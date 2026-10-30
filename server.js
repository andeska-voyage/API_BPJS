/**
 * server.js — Bridge BPJS Kesehatan (GET only) — v3
 * Kompatibel Node 14+ (modul https bawaan, tanpa fetch)
 * v3: dekripsi 2 skema cipher — 3DES (Antrean) & AES (VClaim)
 *     varian yang berhasil dilog: "✔ Dekripsi sukses"
 */
const express  = require('express');
const https    = require('https');
const net = require('net');
const crypto   = require('crypto');
const LZString = require('lz-string');

const app = express();
app.use(express.json());            // diperlukan utk POST getlisttask
app.use(express.static('public'));

/* ====== KREDENSIAL (jangan dibagikan / di-commit) ====== */
const SERVICES = {
  sep: {
    consId: '***', secretKey: '****',
    userKey: '*****',
    baseUrl: 'https://apijkn.bpjs-kesehatan.go.id/vclaim-rest',
  },
  antrean: {
    consId: '***', secretKey: '****',
    userKey: '******',
    baseUrl: 'https://apijkn.bpjs-kesehatan.go.id/antreanrs',
  },
};
/* ======================================================= */

app.get('/ping', (req, res) => res.json({ ok: true, ts: Date.now() }));

function buatAuth(cfg) {
  const timestamp = Math.floor(Date.now() / 1000);
  const signature = crypto.createHmac('sha256', cfg.secretKey)
    .update(cfg.consId + '&' + timestamp).digest('base64');
  return {
    timestamp,
    headers: {
      'X-Cons-ID': cfg.consId,
      'X-Timestamp': String(timestamp),
      'X-Signature': signature,
      'user_key': cfg.userKey,
      'Accept': 'application/json',
    },
  };
}

function dekripsi(cfg, timestamp, b64) {
  const gabung = String(cfg.consId) + String(cfg.secretKey) + String(timestamp);

  const md5hex    = crypto.createHash('md5').update(gabung).digest('hex');
  const md5raw    = crypto.createHash('md5').update(gabung).digest();
  const sha256hex = crypto.createHash('sha256').update(gabung).digest('hex');
  const sha256raw = crypto.createHash('sha256').update(gabung).digest();

  /* Skema 3DES — dipakai layanan Antrean Online (antreanrs):
     kunci = 24 byte pertama dari consID+secretKey+tStamp (dipotong / di-nol-pad),
     IV    = 8 byte pertama dari string yang sama                       */
  const buf   = Buffer.from(gabung, 'utf8');
  const key24 = Buffer.concat([buf, Buffer.alloc(24)]).slice(0, 24);
  const iv8   = Buffer.concat([buf, Buffer.alloc(8)]).slice(0, 8);

  const kombinasi = [
    // ===== 3DES — Antrean Online =====
    { algo: 'des-ede3-cbc', key: key24, iv: iv8 },
    { algo: 'des-ede3-cbc', key: key24, iv: Buffer.from(md5raw.slice(0, 8)) }, // cadangan

    // ===== AES — VClaim (SEP) =====
    { algo: 'aes-256-cbc', key: Buffer.concat([md5raw, Buffer.alloc(16)]), iv: md5raw },           // varian resmi BPJS (PHP)
    { algo: 'aes-256-cbc', key: Buffer.from(sha256raw), iv: Buffer.from(sha256raw.slice(0, 16)) }, // varian sha256 raw
    { algo: 'aes-256-cbc', key: Buffer.from(sha256hex.slice(0, 32), 'ascii'),
                            iv: Buffer.from(sha256hex.slice(0, 16), 'ascii') },
    { algo: 'aes-256-cbc', key: Buffer.from(md5hex), iv: Buffer.from(md5hex.slice(0, 16), 'ascii') }, // varian Java
    { algo: 'aes-128-cbc', key: Buffer.from(md5hex.slice(0, 16), 'ascii'),
                           iv: Buffer.from(md5hex.slice(16, 32), 'ascii') },
    { algo: 'aes-256-cbc', key: Buffer.concat([Buffer.from(md5hex.slice(0, 16), 'ascii'), Buffer.alloc(16)]),
                           iv: Buffer.from(md5hex.slice(16, 32), 'ascii') },
    { algo: 'aes-128-cbc', key: md5raw, iv: md5raw },
  ];

  const dekompresi = [
    { label: 'EncodedURIComponent', fn: LZString.decompressFromEncodedURIComponent },
    { label: 'UTF16',               fn: LZString.decompressFromUTF16 },
    { label: 'plain',               fn: LZString.decompress },
    { label: 'Base64',              fn: LZString.decompressFromBase64 },
  ];

  const jejak = [];
  for (const k of kombinasi) {
    let plain;
    try {
      const d = crypto.createDecipheriv(k.algo, k.key, k.iv);
      plain = Buffer.concat([d.update(Buffer.from(b64, 'base64')), d.final()]).toString('utf8');
    } catch (e) {
      jejak.push(k.algo + ': ' + String(e.message).split('\n')[0]);
      continue;
    }
    for (const dec of dekompresi) {
      try {
        const hasil = dec.fn(plain);
        if (hasil && typeof hasil === 'string') {
          const obj = JSON.parse(hasil);
          console.log('✔ Dekripsi sukses — ' + k.algo + ' + LZ-' + dec.label);
          return obj;
        }
      } catch { /* coba dekompresi berikutnya */ }
    }
    jejak.push(k.algo + ': cipher OK tapi LZ gagal (awal plain: ' +
               JSON.stringify(plain.slice(0, 40)) + ')');
  }
  throw new Error('Dekripsi gagal semua varian → ' + jejak.join(' | '));
}

function bpjsGet(url, headers) {
  return new Promise((resolve, reject) => {
    const req = https.get(url, { headers }, res => {
      let body = '';
      res.setEncoding('utf8');
      res.on('data', c => (body += c));
      res.on('end', () => resolve({ status: res.statusCode, body }));
    });
    req.on('error', reject);
    req.setTimeout(20000, () => req.destroy(new Error('timeout 20 detik')));
  });
}

// POST ke server BPJS dengan body JSON — khusus getlisttask
function bpjsPost(url, headers, bodyObj){
  return new Promise((resolve, reject) => {
    const payload = Buffer.from(JSON.stringify(bodyObj), 'utf8');
    const u = new URL(url);
    const req = https.request({
      hostname: u.hostname,
      path: u.pathname + (u.search || ''),
      method: 'POST',
      headers: { ...headers,
                 'Content-Type': 'application/json',
                 'Content-Length': payload.length }
    }, res => {
      let body = '';
      res.setEncoding('utf8');
      res.on('data', c => (body += c));
      res.on('end', () => resolve({ status: res.statusCode, body }));
    });
    req.on('error', reject);
    req.setTimeout(20000, () => req.destroy(new Error('timeout 20 detik')));
    req.write(payload);
    req.end();
  });
}

app.use('/api', async (req, res) => {
  const m = req.url.match(/^\/(sep|antrean)\/(.*)$/s);
  if (!m) return res.status(404).json({ error: 'Gunakan /api/sep/... atau /api/antrean/...' });

  const cfg  = SERVICES[m[1]];
  const path = m[2];
  const isTask = (m[1] === 'antrean' && /^antrean\/getlisttask/.test(path));

  let hasil;
  const { timestamp, headers } = buatAuth(cfg);

  try {
    if (req.method === 'GET') {
      hasil = await bpjsGet(cfg.baseUrl + '/' + path, headers);
    } else if (req.method === 'POST' && isTask) {
      hasil = await bpjsPost(cfg.baseUrl + '/antrean/getlisttask', headers, req.body || {});
    } else {
      return res.status(405).json({ error: 'Hanya GET — atau POST khusus getlisttask' });
    }

    const { status, body } = hasil;
    let data, parseOK = true;
    try { data = JSON.parse(body); } catch { data = {}; parseOK = false; }

    const out = { _info: { httpStatus: status, url: cfg.baseUrl + '/' + path, encrypted: false } };

    // v9.2: antreanrs memakai "metadata" lowercase — tangkap semuanya
    out.meta = data.metaData || data.meta || data.metadata || null;

    if (typeof data.response === 'string' && data.response.length) {
      out._info.encrypted = true;
      try { out.response = dekripsi(cfg, timestamp, data.response); }
      catch (e) { out.response = null; out._info.catatan = String(e.message || e); }
    } else {
      out.response = (data.response != null) ? data.response : null;
    }

    // v9.2: struktur tak terduga → tampilkan isi mentah BPJS (kunci diagnosis)
    if (!parseOK || (out.meta === null && out.response === null)) {
      out._info.rawPreview = String(body).slice(0, 300);
      console.log('[bpjs-raw]', out._info.url, '→', out._info.rawPreview);
    }

    res.json(out);

  } catch (err) {
    res.status(502).json({ error: 'Gagal memanggil server BPJS: ' + err.message });
  }
});

/* =========================================================
   MONITOR v2 — cek IP publik + laporan capaian v8 → Telegram
   - hitungan SEP: hanya poli terisi & non-IGD (sinkron dashboard)
   - task: hanya bila ON (bisa diubah dari dashboard, tanpa restart)
   ========================================================= */

const MONITOR = {
  host: 'IP_publik',
  port: 80,
  tcpTimeout: 5000,
  telegramToken: '*****', // TODO: rotasi!
  chatId: '******',
  menit: 60,
};

/* pengaturan yang bisa diubah dari dashboard (persist selama proses hidup) */
const MONITOR_OPTIONS = {
  taskEnabled: false,      // aktif/nonaktif API taskid di laporan
  kirimSaatUp: false,    // true = laporan tiap jam; false = hanya saat DOWN
};
app.get('/monitor/options', (req,res)=>res.json(MONITOR_OPTIONS));
app.post('/monitor/options', (req,res)=>{
  if (typeof req.body.taskEnabled === 'boolean')
    MONITOR_OPTIONS.taskEnabled = req.body.taskEnabled;
  if (typeof req.body.kirimSaatUp === 'boolean')
    MONITOR_OPTIONS.kirimSaatUp = req.body.kirimSaatUp;
  res.json(MONITOR_OPTIONS);
});

function cekTcp(host, port, timeout){
  return new Promise(resolve=>{
    let done=false;
    const s=net.connect({host,port});
    const fin=ok=>{ if(done)return; done=true; s.destroy(); resolve(ok); };
    s.setTimeout(timeout, ()=>fin(false));
    s.on('connect', ()=>fin(true));
    s.on('error',   ()=>fin(false));
  });
}

function kirimTelegram(teks){
  const q=new URLSearchParams({chat_id:MONITOR.chatId, text:teks});
  return new Promise((resolve,reject)=>{
    https.get('https://api.telegram.org/bot'+MONITOR.telegramToken+'/sendMessage?'+q,
      res=>{ let b=''; res.on('data',c=>b+=c);
             res.on('end',()=>{ console.log('[telegram] HTTP',res.statusCode); resolve(b); }); })
      .on('error', reject);
  });
}

async function panggil(nama, method, path, body){
  const cfg=SERVICES[nama];
  const {timestamp, headers}=buatAuth(cfg);
  let hasil;
  if (method==='POST'){
    hasil = await bpjsPost(cfg.baseUrl+'/'+path, headers, body);
  } else {
    hasil = await bpjsGet(cfg.baseUrl+'/'+path, headers);
  }
  let data; try{ data=JSON.parse(hasil.body); }catch{ data={}; }
  let response=data.response;
  if (typeof response==='string' && response.length){
    try{ response=dekripsi(cfg, timestamp, response); }catch{ response=null; }
  }
  return { status: hasil.status, response: response||[] };
}

function srvAmbilArray(r){
  if (Array.isArray(r)) return r;
  if (r && typeof r==='object'){
    for (const k of ['sep','antrean','task','list'])
      if (Array.isArray(r[k])) return r[k];
    for (const k of Object.keys(r)){
      const a=srvAmbilArray(r[k]); if(a&&a.length) return a;
    }
  }
  return [];
}

/* —— rumus v8 (sinkron dengan dashboard) —— */
function hitungV8(antArr, totalSepValid){
  const pct = n => totalSepValid>0 ? Math.round(n*100/totalSepValid) : 0;
  const isRef   = p=>{const r=String(p.nomorreferensi||'').trim();
                      return !!r && r!=='-' && r.toLowerCase()!=='null';};
  const isGagal = p=>/batal|gagal|cancel/i.test(String(p.status||''));
  const isSelesai=p=>/selesai/i.test(String(p.status||''));
  const srcOf   = p=>{const s=String(p.sumberdata||'').toLowerCase();
    if(/mobile\s*jkn/.test(s))return 'mjkn';
    if(/bridging/.test(s))return 'bridging'; return 'lain';};
  const setM=new Set(), setB=new Set();
  let nGagal=0, nUmum=0;
  for (const p of antArr){
    if (isGagal(p)){ nGagal++; continue; }
    if (!isRef(p)) { nUmum++;  continue; }
    if (!isSelesai(p)) continue;                 // v8
    const src=srcOf(p);
    if (src==='mjkn')          setM.add(String(p.nomorreferensi).trim());
    else if (src==='bridging') setB.add(String(p.nomorreferensi).trim());
  }
  return {
    sepValid:totalSepValid,
    pctMjkn:pct(setM.size), pctBridg:pct(setB.size),
    pctAll:pct(setM.size)+pct(setB.size),
    mjknN:setM.size, bridgN:setB.size,
    antrean:antArr.length, umum:nUmum, gagal:nGagal
  };
}

/* —— hitung total SEP valid —— */
function sepValidCount(sepArr){
  return sepArr.filter(s=>{
    const poli=String(s.poli||'').trim().toLowerCase();
    return poli && poli!=='-' && poli!=='—' && poli!=='igd';
  }).length;
}

/* —— ambil task (POST per booking, dgn toggle) —— */
async function ambilTaskSrv(kbList, wkt){
  const out=[];
  for (const kb of kbList){
    try{
      const d = await panggil('antrean','POST','antrean/getlisttask',
                               {kodebooking:kb, taskid:1, waktu:wkt});
      out.push(...srvAmbilArray(d.response));
    }catch(e){ /* skip booking ini */ }
    await new Promise(r=>setTimeout(r,200));
  }
  return out;
}

let monitorTerakhir=null;
async function jobMonitor(){
  const jam=new Date().toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'});
  const d=new Date(), p=x=>String(x).padStart(2,'0');
  const tgl=d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate());
  const up=await cekTcp(MONITOR.host, MONITOR.port, MONITOR.tcpTimeout);

  let baris=[];
  let cap=null, taskInfo='—';

  try{
    const sepR  = await panggil('sep','GET','Monitoring/Kunjungan/Tanggal/'+tgl+'/JnsPelayanan/2');
    const antR  = await panggil('antrean','GET','antrean/pendaftaran/tanggal/'+tgl);
    const sepArr=srvAmbilArray(sepR.response);
    const antArr=srvAmbilArray(antR.response);
    const sepValid=sepValidCount(sepArr);
    const igd = sepArr.length - sepValid
              - sepArr.filter(s=>{const pl=String(s.poli||'').trim().toLowerCase();
                                  return !pl||pl==='-'||pl==='—';}).length;

    cap = hitungV8(antArr, sepValid);
    baris.push('📄 SEP valid: '+sepValid+' (IGD '+igd+' dikecualikan)');
    baris.push('🎫 Antrean: '+antArr.length+' · UMUM '+cap.umum+' · gagal '+cap.gagal);

    if (MONITOR_OPTIONS.taskEnabled){
      const kbList=[...new Set(antArr.map(a=>String(a.kodebooking||'').trim()).filter(Boolean))];
      const tasks=await ambilTaskSrv(kbList.slice(0,50), 'rs');   // batasi 50 booking/jam
      const selesaiN = tasks.filter(t=>String(t.taskid)==='7').length;
      taskInfo = tasks.length+' task · '+selesaiN+' tuntas T7';
      baris.push('⏱️ Task: '+taskInfo);
    } else {
      taskInfo='nonaktif';
    }

    baris.push('📊 MJKN '+cap.pctMjkn+'% + Bridging '+cap.pctBridg
               +'% → All Sumber: '+cap.pctAll+'%');
  }catch(e){
    baris.push('📊 Capaian gagal dihitung: '+e.message);
  }

  const teks = up
    ? '🟢 Laporan Jam '+jam+' — Server Transnet UP\n'+baris.join('\n')
    : '🔴 Server IP Publik Transnet DOWN !!! (Check: '+jam+')\n'
      +'📡 '+MONITOR.host+':'+MONITOR.port+' tidak terjangkau\n'+baris.join('\n');

  monitorTerakhir={ waktu:new Date().toISOString(), tgl, up, capaian:cap,
                    task:taskInfo, options:{...MONITOR_OPTIONS}, teks };

  if (!up || MONITOR_OPTIONS.kirimSaatUp){
    try{ await kirimTelegram(teks); }
    catch(e){ console.log('[monitor] Telegram gagal:', e.message); }
  }
  console.log('[monitor '+jam+']', up?'UP':'DOWN',
              '| All:', cap?cap.pctAll+'%':'?',
              '| task:', taskInfo);
}

setInterval(jobMonitor, MONITOR.menit*60*1000);
setTimeout(jobMonitor, 20*1000);

app.get('/monitor/status', (req,res)=>res.json(monitorTerakhir||{belum:'belum berjalan'}));
app.get('/monitor/run', (req,res)=>{
  jobMonitor().then(()=>res.json(monitorTerakhir))
              .catch(e=>res.status(500).json({error:e.message}));
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, '0.0.0.0', () =>
  console.log('✅ Dashboard BPJS aktif di port ' + PORT));
