<?php
/**
 * index.php — Dashboard BPJS + API dalam SATU FILE
 * - URL biasa        → halaman dashboard (HTML)
 * - URL + ?jenis=... → API JSON (antrean | sep | task)
 */

if (isset($_GET['jenis'])) {

// ================= KONFIG =================
 $consId    = '29513';
 $secretKey = '7gA350Sji11e';

 $userKeys = [
    'antrean' => '152fcf2687a35774a6e59a27b1efac99',
    'task'    => '152fcf2687a35774a6e59a27b1efac99',
    'sep'     => '88f50cf542182200a5244c14197fb23e', // ganti jika VClaim beda user_key
];

 $baseUrls = [
    'antrean' => 'https://apijkn.bpjs-kesehatan.go.id/antreanrs',
    'task'    => 'https://apijkn.bpjs-kesehatan.go.id/antreanrs',
    'sep'     => 'https://apijkn.bpjs-kesehatan.go.id/vclaim-rest',
];
// ==========================================

class BpjsLz
{
    const URI_SAFE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+-=';
    const BASE64   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';

    public static function decompress(string $bytes): ?string
    {
        $bytes = trim($bytes);
        if ($bytes === '') return null;
        if (self::isJson($bytes)) return $bytes;

        $c = [];
        $c['encoded-uri'] = self::fromEncoded(str_replace(' ', '+', $bytes), self::URI_SAFE, 32);
        $c['base64']      = self::fromEncoded($bytes, self::BASE64, 32);

        if (function_exists('mb_ord')) {
            $units = [];
            $n = mb_strlen($bytes, 'UTF-8');
            for ($i = 0; $i < $n; $i++)
                $units[] = mb_ord(mb_substr($bytes, $i, 1, 'UTF-8'), 'UTF-8') - 32;
            $c['utf16'] = self::fromUnits($units, 16384);
        }

        $pairs = [];
        for ($i = 0, $n = strlen($bytes); $i + 1 < $n; $i += 2)
            $pairs[] = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]);
        $c['uint8'] = self::fromUnits($pairs, 32768);

        foreach ($c as $out)
            if ($out !== null && $out !== '' && self::isJson($out)) return $out;
        return null;
    }

    private static function isJson(string $s): bool
    {
        $f = ($s !== '') ? $s[0] : '';
        if ($f !== '{' && $f !== '[') return false;
        json_decode($s);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private static function fromEncoded(string $s, string $alphabet, int $resetValue): ?string
    {
        $rev = array_flip(str_split($alphabet));
        $units = [];
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            if (!isset($rev[$s[$i]])) return null;
            $units[] = $rev[$s[$i]];
        }
        return self::fromUnits($units, $resetValue);
    }

    private static function fromUnits(array $u, int $resetValue): ?string
    {
        $len = count($u);
        if ($len === 0) return null;
        $val = $u[0]; $position = $resetValue; $index = 1;

        $readBits = function (int $n) use (&$val, &$position, &$index, $u, $resetValue): int {
            $bits = 0; $power = 1; $max = 1 << $n;
            while ($power !== $max) {
                $resb = $val & $position;
                $position >>= 1;
                if ($position === 0) { $position = $resetValue; $val = $u[$index] ?? 0; $index++; }
                if ($resb > 0) $bits |= $power;
                $power <<= 1;
            }
            return $bits;
        };

        $dict = []; $enlargeIn = 4; $dictSize = 4; $numBits = 3;

        $header = $readBits(2);
        if ($header === 2) return '';
        if ($header === 3) return null;

        $first = $header === 0 ? $readBits(8) : $readBits(16);
        $dict[3] = [$first]; $w = [$first]; $chunks = [[$first]];

        while (true) {
            if ($index > $len) return null;
            $cc = $readBits($numBits);

            if ($cc === 0 || $cc === 1) {
                $dict[$dictSize++] = [$readBits($cc === 0 ? 8 : 16)];
                $cc = $dictSize - 1; $enlargeIn--;
            } elseif ($cc === 2) {
                return self::unitsToUtf8($chunks);
            }

            if ($enlargeIn === 0) { $enlargeIn = 1 << $numBits; $numBits++; }

            if (isset($dict[$cc]))         $entry = $dict[$cc];
            elseif ($cc === $dictSize)     $entry = array_merge($w, [$w[0]]);
            else                           return null;

            $chunks[] = $entry;
            $dict[$dictSize++] = array_merge($w, [$entry[0]]);
            $enlargeIn--;
            $w = $entry;

            if ($enlargeIn === 0) { $enlargeIn = 1 << $numBits; $numBits++; }
        }
    }

    private static function unitsToUtf8(array $chunks): string
    {
        $out = ''; $pending = -1;
        foreach ($chunks as $chunk) {
            foreach ($chunk as $unit) {
                if ($unit >= 0xD800 && $unit <= 0xDBFF) { $pending = $unit; continue; }
                if ($unit >= 0xDC00 && $unit <= 0xDFFF && $pending >= 0) {
                    $cp = 0x10000 + (($pending - 0xD800) << 10) + ($unit - 0xDC00);
                    $out .= self::u8($cp); $pending = -1; continue;
                }
                if ($pending >= 0) { $out .= "\xEF\xBF\xBD"; $pending = -1; }
                $out .= self::u8($unit);
            }
        }
        if ($pending >= 0) $out .= "\xEF\xBF\xBD";
        return $out;
    }

    private static function u8(int $cp): string
    {
        if ($cp < 0x80)    return chr($cp);
        if ($cp < 0x800)   return chr(0xC0 | $cp >> 6) . chr(0x80 | $cp & 0x3F);
        if ($cp < 0x10000) return chr(0xE0 | $cp >> 12) . chr(0x80 | $cp >> 6 & 0x3F) . chr(0x80 | $cp & 0x3F);
        return chr(0xF0 | $cp >> 18) . chr(0x80 | $cp >> 12 & 0x3F)
             . chr(0x80 | $cp >> 6 & 0x3F) . chr(0x80 | $cp & 0x3F);
    }
}

function fail(array $payload, int $code = 500): void {
    http_response_code($code);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit($json === false ? '{"error":"Terjadi kesalahan internal"}' : $json);
}

header('Content-Type: application/json; charset=utf-8');

 // ★ BARU: baca parameter dari POST JSON (dashboard) ATAU GET (tes langsung di browser)
 $input = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $input = json_decode((string)file_get_contents('php://input'), true) ?: [];
}
 $param = function (string $k, $d = null) use ($input) {
    return $input[$k] ?? $_GET[$k] ?? $d;
};

 $jenis   = $param('jenis', '');
 $tanggal = $param('tanggal', date('Y-m-d'));
 $jns     = ((string)$param('jns', '2')) === '1' ? '1' : '2';

if (!isset($baseUrls[$jenis])) fail(['error' => 'Parameter jenis tidak valid (antrean | sep | task)'], 400);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) fail(['error' => 'Format tanggal harus YYYY-MM-DD'], 400);

 $endpoints = [
    'antrean' => ['method'=>'GET',  'url'=>"{$baseUrls['antrean']}/antrean/pendaftaran/tanggal/{$tanggal}", 'body'=>null],
    'sep'     => ['method'=>'GET',  'url'=>"{$baseUrls['sep']}/Monitoring/Kunjungan/Tanggal/{$tanggal}/JnsPelayanan/{$jns}", 'body'=>null],
    'task'    => ['method'=>'POST', 'url'=>"{$baseUrls['task']}/antrean/getlisttask",
                  'body'=>['kodebooking'=>trim((string)$param('kodebooking','')), 'taskid'=>(int)$param('taskid',0), 'waktu'=>'']],
];
 $ep = $endpoints[$jenis];

 $timestamp = time();
 $signature = base64_encode(hash_hmac('sha256', $consId . '&' . $timestamp, $secretKey, true));

 $headers = [
    "X-Cons-ID: {$consId}", "X-Timestamp: {$timestamp}",
    "X-Signature: {$signature}", "user_key: {$userKeys[$jenis]}", "Accept: application/json",
];

 $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30];
if ($ep['method'] === 'POST') {
    $opts[CURLOPT_POST]       = true;
    $opts[CURLOPT_POSTFIELDS] = json_encode($ep['body']);
    $headers[] = 'Content-Type: application/json';
}
 $opts[CURLOPT_HTTPHEADER] = $headers;

 $ch = curl_init($ep['url']);
curl_setopt_array($ch, $opts);
 $raw      = curl_exec($ch);
 $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
 $curlErr  = curl_error($ch);
curl_close($ch);

if ($raw === false) fail(['error' => 'cURL gagal', 'detail' => $curlErr], 502);

 $result = json_decode($raw, true);
if (!is_array($result))
    fail(['error' => "BPJS merespons HTTP {$httpCode} (bukan JSON)", 'http_code' => $httpCode, 'raw' => substr((string)$raw, 0, 200)], 502);
if ((int)($result['metadata']['code'] ?? 0) !== 200)
    fail(['error' => $result['metadata']['message'] ?? 'BPJS menolak request', 'http_code' => $httpCode, 'jenis' => $jenis], 502);

 $resp = $result['response'] ?? '';
if (is_array($resp)) exit(json_encode(['jenis' => $jenis, 'jumlah' => count($resp), 'data' => $resp]));

 $encrypted = trim((string)$resp);
if ($encrypted === '') exit(json_encode(['jenis' => $jenis, 'jumlah' => 0, 'data' => []]));

 $key       = $consId . $secretKey . $timestamp;
 $keyHash   = hex2bin(hash('sha256', $key));
 $iv        = substr($keyHash, 0, 16);
 $decrypted = openssl_decrypt(base64_decode($encrypted), 'AES-256-CBC', $keyHash, OPENSSL_RAW_DATA, $iv);

if ($decrypted === false || $decrypted === '') fail(['error' => 'Dekripsi gagal', 'jenis' => $jenis]);

 $plain = BpjsLz::decompress($decrypted);
if ($plain === null)
    fail(['error' => 'Dekompresi gagal', 'jenis' => $jenis,
          'diagnostik' => ['panjang' => strlen($decrypted), 'awal_hex' => bin2hex(substr($decrypted, 0, 48))]]);

echo $plain;
exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:,">
<title>Dashboard BPJS — Antrean · SEP · Task</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
  .fade-in{animation:fadeIn .25s ease-in}
  @keyframes fadeIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
  .spin{animation:spin 1s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen flex flex-col">

<header class="bg-gradient-to-r from-blue-700 via-indigo-700 to-indigo-800 text-white shadow-lg sticky top-0 z-40">
  <div class="max-w-7xl mx-auto px-4 pt-4 flex flex-wrap items-center gap-3">
    <div class="flex items-center gap-3">
      <div class="w-11 h-11 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
      </div>
      <div>
        <h1 class="text-lg font-bold leading-tight">Dashboard Bridging BPJS</h1>
        <p class="text-blue-200 text-xs">Antrean (Antrol) · SEP (VClaim) · Monitoring Task</p>
      </div>
    </div>
    <div class="ml-auto flex flex-wrap items-center gap-2">
      <input type="date" id="tanggal" [color-scheme:dark]
             class="bg-white/10 border border-white/25 rounded-lg px-3 py-1.5 text-sm text-white focus:outline-none focus:ring-2 focus:ring-white/40">
      <button id="btnRefresh" class="bg-white text-indigo-700 font-semibold text-sm px-4 py-1.5 rounded-lg hover:bg-blue-50 active:scale-95 transition flex items-center gap-2">
        <svg id="icoRefresh" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        Muat
      </button>
      <label class="flex items-center gap-1.5 text-xs text-blue-100 cursor-pointer select-none">
        <input type="checkbox" id="autoRefresh" class="rounded accent-white"> Auto 30s
      </label>
    </div>
  </div>
  <nav class="max-w-7xl mx-auto px-4 flex gap-1 mt-2">
    <button data-tab="antrean" class="tab-btn px-4 py-2 rounded-t-lg text-sm font-semibold transition">📋 Antrean</button>
    <button data-tab="sep"     class="tab-btn px-4 py-2 rounded-t-lg text-sm font-semibold transition">🏥 SEP</button>
    <button data-tab="task"    class="tab-btn px-4 py-2 rounded-t-lg text-sm font-semibold transition">⚡ Task List</button>
  </nav>
</header>

<main class="max-w-7xl w-full mx-auto px-4 py-6 flex-1 space-y-5">

  <div id="alertError" class="hidden bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm flex items-start gap-2">
    <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
    <div><span id="txtError"></span><button onclick="load(activeTab)" class="underline font-medium mt-1">Coba lagi</button></div>
  </div>

  <section class="tab-panel space-y-5" data-panel="antrean">
    <div id="statsAntrean" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4"></div>
    <div class="bg-white rounded-2xl shadow-sm ring-1 ring-slate-200 overflow-hidden">
      <div class="p-4 border-b border-slate-100 flex flex-wrap gap-2 items-center">
        <div class="relative flex-1 min-w-[220px]">
          <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          <input id="qAntrean" placeholder="Cari no. antrean / booking / RM / NIK / HP…"
                 class="w-full pl-9 pr-3 py-2 text-sm rounded-lg border border-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-400">
        </div>
        <select id="fPoli"   class="text-sm py-2 px-3 rounded-lg border border-slate-200 bg-white"><option value="">Semua Poli</option></select>
        <select id="fStatus" class="text-sm py-2 px-3 rounded-lg border border-slate-200 bg-white"><option value="">Semua Status</option></select>
        <select id="fSumber" class="text-sm py-2 px-3 rounded-lg border border-slate-200 bg-white"><option value="">Semua Sumber</option></select>
        <button id="btnCsvAntrean" class="text-sm py-2 px-3 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-slate-600">CSV</button>
      </div>
      <div class="px-4 py-2 text-xs text-slate-500 border-b border-slate-100" id="infoAntrean">&nbsp;</div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-xs uppercase tracking-wide text-slate-500 bg-slate-50">
            <th class="px-4 py-3 font-semibold">No. Antrean</th><th class="px-4 py-3 font-semibold">Kode Booking</th>
            <th class="px-4 py-3 font-semibold">No. RM</th><th class="px-4 py-3 font-semibold">Poli</th>
            <th class="px-4 py-3 font-semibold">Jam Praktek</th><th class="px-4 py-3 font-semibold">Sumber</th>
            <th class="px-4 py-3 font-semibold">Status</th><th class="px-4 py-3 font-semibold">Estimasi</th><th></th>
          </tr></thead>
          <tbody id="tbodyAntrean" class="divide-y divide-slate-100"></tbody>
        </table>
      </div>
      <div id="emptyAntrean" class="hidden py-16 text-center text-slate-400">
        <p class="font-medium">Tidak ada data antrean</p>
        <p class="text-sm">Coba tanggal lain, mis. <button onclick="setTanggal('2026-09-28')" class="underline text-indigo-500">2026-09-28</button></p>
      </div>
    </div>
  </section>

  <section class="tab-panel space-y-5 hidden" data-panel="sep">
    <div id="statsSep" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4"></div>
    <div class="bg-white rounded-2xl shadow-sm ring-1 ring-slate-200 overflow-hidden">
      <div class="p-4 border-b border-slate-100 flex flex-wrap gap-2 items-center">
        <div class="relative flex-1 min-w-[220px]">
          <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          <input id="qSep" placeholder="Cari no. SEP / nama / kartu / RM / poli…"
                 class="w-full pl-9 pr-3 py-2 text-sm rounded-lg border border-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-400">
        </div>
        <select id="jnsPelayanan" class="text-sm py-2 px-3 rounded-lg border border-indigo-200 bg-indigo-50 text-indigo-700 font-medium">
          <option value="2">Rawat Jalan</option><option value="1">Rawat Inap</option>
        </select>
        <select id="fKelas" class="text-sm py-2 px-3 rounded-lg border border-slate-200 bg-white"><option value="">Semua Kelas</option></select>
        <button id="btnCsvSep" class="text-sm py-2 px-3 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-slate-600">CSV</button>
      </div>
      <div class="px-4 py-2 text-xs text-slate-500 border-b border-slate-100" id="infoSep">&nbsp;</div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-xs uppercase tracking-wide text-slate-500 bg-slate-50">
            <th class="px-4 py-3 font-semibold">No. SEP</th><th class="px-4 py-3 font-semibold">Tgl SEP</th>
            <th class="px-4 py-3 font-semibold">No. Kartu</th><th class="px-4 py-3 font-semibold">Nama Peserta</th>
            <th class="px-4 py-3 font-semibold">Poli</th><th class="px-4 py-3 font-semibold">Diag. Awal</th>
            <th class="px-4 py-3 font-semibold">Kelas</th><th></th>
          </tr></thead>
          <tbody id="tbodySep" class="divide-y divide-slate-100"></tbody>
        </table>
      </div>
      <div id="emptySep" class="hidden py-16 text-center text-slate-400">
        <p class="font-medium">Tidak ada data SEP</p><p class="text-sm">Coba ganti tanggal atau jenis pelayanan.</p>
      </div>
    </div>
  </section>

  <section class="tab-panel space-y-5 hidden" data-panel="task">
    <div id="statsTask" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4"></div>
    <div class="bg-white rounded-2xl shadow-sm ring-1 ring-slate-200 overflow-hidden">
      <div class="p-4 border-b border-slate-100 flex flex-wrap gap-2 items-center">
        <div class="relative flex-1 min-w-[220px]">
          <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          <input id="qTask" placeholder="Cari kode booking…" class="w-full pl-9 pr-3 py-2 text-sm rounded-lg border border-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-400">
        </div>
        <select id="fTask" class="text-sm py-2 px-3 rounded-lg border border-slate-200 bg-white">
          <option value="">Semua Task</option><option value="1">Task 1 — Check-in</option>
          <option value="2">Task 2 — Mulai Dokter</option><option value="3">Task 3 — Selesai Dokter</option>
          <option value="4">Task 4 — Batal</option><option value="5">Task 5 — Farmasi</option>
        </select>
        <button id="btnCsvTask" class="text-sm py-2 px-3 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-slate-600">CSV</button>
      </div>
      <div class="px-4 py-2 text-xs text-slate-500 border-b border-slate-100" id="infoTask">&nbsp;</div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr class="text-left text-xs uppercase tracking-wide text-slate-500 bg-slate-50">
            <th class="px-4 py-3 font-semibold">Kode Booking</th><th class="px-4 py-3 font-semibold">Task</th>
            <th class="px-4 py-3 font-semibold">Nama Task</th><th class="px-4 py-3 font-semibold">Waktu</th>
          </tr></thead>
          <tbody id="tbodyTask" class="divide-y divide-slate-100"></tbody>
        </table>
      </div>
      <div id="emptyTask" class="hidden py-16 text-center text-slate-400">
        <p class="font-medium">Tidak ada data task</p><p class="text-sm">getlisttask hanya menampilkan task hari-H (hari ini).</p>
      </div>
    </div>
  </section>

  <p class="text-center text-xs text-slate-400">Terakhir diperbarui: <span id="lastUpdate" class="font-medium">-</span></p>
</main>

<div id="modal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[88vh] overflow-y-auto fade-in">
    <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 sticky top-0 bg-white z-10">
      <h3 class="font-bold text-slate-800">Detail</h3>
      <div class="flex gap-2">
        <button id="btnSalin" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800 px-2 py-1 rounded-md hover:bg-indigo-50">Salin JSON</button>
        <button id="btnClose" class="w-8 h-8 rounded-lg hover:bg-slate-100 text-slate-500 flex items-center justify-center">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
    </div>
    <div id="modalBody" class="p-5 space-y-5"></div>
  </div>
</div>

<script>
// ★ SATU FILE: API = URL halaman ini sendiri
const API = '';

const $ = id => document.getElementById(id);
const state = { antrean: [], sep: [], task: [] };
const view  = { antrean: [], sep: [], task: [] };
let activeTab = 'antrean', autoTimer = null, currentDetail = null;

const MONO = 'font-mono text-xs';
const JNS_PEL = {1:'Rawat Inap',2:'Rawat Jalan','1':'Rawat Inap','2':'Rawat Jalan'};
const JENIS_KUNJ = {1:'Rujukan FKTP',2:'Rujukan RS',3:'Kontrol',4:'Prosedur'};
const SEX = {L:'Laki-laki',P:'Perempuan'};
const TASKS = {1:'Check-in (Loket)',2:'Mulai Dilayani Dokter',3:'Selesai Dilayani Dokter',4:'Batal',5:'Farmasi'};
const TASK_COLOR = {1:'bg-sky-100 text-sky-700 ring-1 ring-sky-600/20',2:'bg-indigo-100 text-indigo-700 ring-1 ring-indigo-600/20',
                    3:'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-600/20',4:'bg-red-100 text-red-700 ring-1 ring-red-600/20',
                    5:'bg-violet-100 text-violet-700 ring-1 ring-violet-600/20'};
const SEARCH_KEYS = {
  antrean:['noantrean','kodebooking','norekammedis','nik','nokapst','nohp','nomorreferensi'],
  sep:['noSep','namaPeserta','noKartu','noMr','noRujukan','poli','diagAwal','nmProvider'],
  task:['kodebooking','taskname'],
};
const CSV_COLS = {
  antrean:['noantrean','kodebooking','norekammedis','kodepoli','kodedokter','jampraktek','sumberdata','status','estimasidilayani','tanggal','jeniskunjungan','nomorreferensi','nik','nokapst','nohp','ispeserta','createdtime'],
  sep:['noSep','tglSep','tglPlgSep','jnsPelayanan','kelasRawat','noKartu','namaPeserta','noMr','tglLahir','sex','jnsPeserta','hakKelas','noHP','kdProvider','nmProvider','poli','poliEksekutif','diagAwal','noRujukan','byTagihan','byTopUp'],
  task:['kodebooking','taskid','taskname','waktu'],
};

const esc = s => String(s ?? '-').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmtTs = ms => { const n = Number(ms); if (!n) return '-';
  const d = new Date(n);
  return d.toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}) + ' ' +
         d.toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'}); };
const fmtRp = v => { const n = Number(v); return n ? 'Rp ' + n.toLocaleString('id-ID') : '-'; };
const matches = (r,q,keys) => { const s=q.trim().toLowerCase();
  return !s || keys.some(k => String(r[k] ?? '').toLowerCase().includes(s)); };
const statusBadge = s => ({'Selesai dilayani':'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-600/20',
  'Belum dilayani':'bg-amber-100 text-amber-700 ring-1 ring-amber-600/20',
  'Batal':'bg-red-100 text-red-700 ring-1 ring-red-600/20'}[s] || 'bg-slate-100 text-slate-600 ring-1 ring-slate-500/20');
const sumberBadge = s => ({'Mobile JKN':'bg-blue-100 text-blue-700 ring-1 ring-blue-600/20',
  'Bridging Antrean':'bg-violet-100 text-violet-700 ring-1 ring-violet-600/20'}[s] || 'bg-slate-100 text-slate-600 ring-1 ring-slate-500/20');
const setTanggal = v => { $('tanggal').value = v; onDateChange(); };

async function load(jenis) {
  const payload = { jenis: jenis };
  if (jenis !== 'task') payload.tanggal = $('tanggal').value;
  if (jenis === 'sep')  payload.jns = $('jnsPelayanan').value;
  $('alertError').classList.add('hidden');
  try {
    // ★ BARU: POST + body JSON — tanpa query string sama sekali
    const res = await fetch(location.pathname, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const text = await res.text();
    let json; try { json = JSON.parse(text); }
    catch { throw new Error('Response bukan JSON: ' + text.slice(0, 200)); }
    if (json && json.error) throw new Error(json.error);
    state[jenis] = Array.isArray(json) ? json : (json.data || json.list || json.kunjungan || []);
    render(jenis);
    $('lastUpdate').textContent = new Date().toLocaleTimeString('id-ID');
  } catch (e) {
    $('txtError').textContent = `[${jenis.toUpperCase()}] ${e.message}`;
    $('alertError').classList.remove('hidden');
  }
}

function onDateChange() {
  localStorage.setItem('bpjs-last-date', $('tanggal').value);
  load('antrean'); load('sep');
}

function render(jenis) {
  if (jenis === 'antrean') renderAntrean();
  else if (jenis === 'sep') renderSep();
  else renderTask();
}

function statCard(c) {
  return `<div class="bg-white rounded-2xl shadow-sm ring-1 ring-slate-200 p-4 flex items-center gap-4">
    <div class="w-11 h-11 rounded-xl ${c.cls} flex items-center justify-center shrink-0">
      <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="${c.ico}"/></svg>
    </div>
    <div><div class="text-2xl font-bold">${c.value}</div><div class="text-xs text-slate-500">${c.label}</div></div>
  </div>`;
}

function renderAntrean() {
  const q=$('qAntrean').value, p=$('fPoli').value, s=$('fStatus').value, b=$('fSumber').value;
  view.antrean = state.antrean.filter(r =>
    (!p||r.kodepoli===p)&&(!s||r.status===s)&&(!b||r.sumberdata===b)&&matches(r,q,SEARCH_KEYS.antrean));

  const cnt = k => state.antrean.filter(r => r.status===k).length;
  $('statsAntrean').innerHTML = [
    {label:'Total Antrean',value:state.antrean.length,cls:'text-indigo-600 bg-indigo-50',ico:'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'},
    {label:'Belum Dilayani',value:cnt('Belum dilayani'),cls:'text-amber-600 bg-amber-50',ico:'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'},
    {label:'Selesai Dilayani',value:cnt('Selesai dilayani'),cls:'text-emerald-600 bg-emerald-50',ico:'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'},
    {label:'Batal',value:cnt('Batal'),cls:'text-red-600 bg-red-50',ico:'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z'},
    {label:'Peserta JKN',value:state.antrean.filter(r=>r.ispeserta).length,cls:'text-blue-600 bg-blue-50',ico:'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'},
  ].map(statCard).join('');

  $('emptyAntrean').classList.toggle('hidden', view.antrean.length > 0);
  $('tbodyAntrean').innerHTML = view.antrean.map((r,i) => `
    <tr class="hover:bg-indigo-50/40 transition cursor-pointer fade-in" data-i="${i}">
      <td class="px-4 py-3 font-bold whitespace-nowrap">${esc(r.noantrean)}</td>
      <td class="px-4 py-3 ${MONO} text-slate-600 whitespace-nowrap">${esc(r.kodebooking)}</td>
      <td class="px-4 py-3 ${MONO} text-slate-600">${esc(r.norekammedis)}</td>
      <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-md bg-slate-100 text-slate-700 font-semibold text-xs">${esc(r.kodepoli)}</span></td>
      <td class="px-4 py-3 text-slate-600 whitespace-nowrap">${esc(r.jampraktek)}</td>
      <td class="px-4 py-3"><span class="px-2 py-1 rounded-md text-xs font-medium whitespace-nowrap ${sumberBadge(r.sumberdata)}">${esc(r.sumberdata)}</span></td>
      <td class="px-4 py-3"><span class="px-2 py-1 rounded-md text-xs font-medium whitespace-nowrap ${statusBadge(r.status)}">${esc(r.status)}</span></td>
      <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap">${fmtTs(r.estimasidilayani)}</td>
      <td class="px-4 py-3 text-right"><span class="text-indigo-600 text-xs font-semibold">Detail →</span></td>
    </tr>`).join('');
  $('infoAntrean').innerHTML = `Tanggal <b>${esc($('tanggal').value)}</b> — menampilkan <b>${view.antrean.length}</b> dari <b>${state.antrean.length}</b> antrean`;
}

function renderSep() {
  const q=$('qSep').value, k=$('fKelas').value;
  view.sep = state.sep.filter(r => (!k||String(r.kelasRawat)===k)&&matches(r,q,SEARCH_KEYS.sep));

  const c = f => state.sep.filter(f).length;
  $('statsSep').innerHTML = [
    {label:'Total SEP',value:state.sep.length,cls:'text-indigo-600 bg-indigo-50',ico:'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'},
    {label:'Poli Eksekutif',value:c(r=>String(r.poliEksekutif)==='1'),cls:'text-amber-600 bg-amber-50',ico:'M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z'},
    {label:'Kelas 1',value:c(r=>String(r.kelasRawat)==='1'),cls:'text-blue-600 bg-blue-50',ico:'M3 12l9-9 9 9M5 10v10a1 1 0 001 1h3m10-11v10a1 1 0 01-1 1h-3m-6 0h6'},
    {label:'Kelas 2',value:c(r=>String(r.kelasRawat)==='2'),cls:'text-teal-600 bg-teal-50',ico:'M3 12l9-9 9 9M5 10v10a1 1 0 001 1h3m10-11v10a1 1 0 01-1 1h-3m-6 0h6'},
    {label:'Kelas 3',value:c(r=>String(r.kelasRawat)==='3'),cls:'text-emerald-600 bg-emerald-50',ico:'M3 12l9-9 9 9M5 10v10a1 1 0 001 1h3m10-11v10a1 1 0 01-1 1h-3m-6 0h6'},
  ].map(statCard).join('');

  $('emptySep').classList.toggle('hidden', view.sep.length > 0);
  $('tbodySep').innerHTML = view.sep.map((r,i) => `
    <tr class="hover:bg-indigo-50/40 transition cursor-pointer fade-in" data-i="${i}">
      <td class="px-4 py-3 ${MONO} text-slate-700 whitespace-nowrap">${esc(r.noSep)}</td>
      <td class="px-4 py-3 text-slate-600 whitespace-nowrap">${esc(r.tglSep)}</td>
      <td class="px-4 py-3 ${MONO} text-slate-600">${esc(r.noKartu)}</td>
      <td class="px-4 py-3 font-medium">${esc(r.namaPeserta)}</td>
      <td class="px-4 py-3 text-slate-600">${esc(r.poli)}</td>
      <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-md bg-slate-100 text-slate-700 font-semibold text-xs">${esc(r.diagAwal)}</span></td>
      <td class="px-4 py-3 text-slate-600 whitespace-nowrap">Kelas ${esc(r.kelasRawat)}</td>
      <td class="px-4 py-3 text-right"><span class="text-indigo-600 text-xs font-semibold">Detail →</span></td>
    </tr>`).join('');
  $('infoSep').innerHTML = `${JNS_PEL[$('jnsPelayanan').value]} · Tanggal <b>${esc($('tanggal').value)}</b> — menampilkan <b>${view.sep.length}</b> dari <b>${state.sep.length}</b> SEP`;
}

function renderTask() {
  const q=$('qTask').value, t=$('fTask').value;
  view.task = state.task.filter(r => (!t||String(r.taskid)===t)&&matches(r,q,SEARCH_KEYS.task));

  $('statsTask').innerHTML = [1,2,3,4,5].map(id => {
    const n = state.task.filter(r => Number(r.taskid)===id).length;
    return `<div class="bg-white rounded-2xl shadow-sm ring-1 ring-slate-200 p-4 flex items-center gap-4">
      <div class="w-10 h-10 rounded-xl ${TASK_COLOR[id]} flex items-center justify-center font-bold shrink-0">${id}</div>
      <div><div class="text-2xl font-bold">${n}</div><div class="text-xs text-slate-500">${TASKS[id]}</div></div></div>`;
  }).join('');

  $('emptyTask').classList.toggle('hidden', view.task.length > 0);
  $('tbodyTask').innerHTML = view.task.map((r,i) => `
    <tr class="hover:bg-indigo-50/40 transition cursor-pointer fade-in" data-i="${i}">
      <td class="px-4 py-3 ${MONO} text-slate-700">${esc(r.kodebooking)}</td>
      <td class="px-4 py-3"><span class="px-2 py-1 rounded-md text-xs font-medium ${TASK_COLOR[r.taskid]||'bg-slate-100 text-slate-600'}">Task ${esc(r.taskid)}</span></td>
      <td class="px-4 py-3 text-slate-600">${esc(r.taskname || TASKS[r.taskid] || '-')}</td>
      <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap">${fmtTs(r.waktu)}</td>
    </tr>`).join('');
  $('infoTask').innerHTML = `Hari-H — total <b>${state.task.length}</b> task · menampilkan <b>${view.task.length}</b>`;
}

function section(title, rows) {
  return `<div>
    <div class="text-[11px] font-bold uppercase tracking-widest text-indigo-500 mb-2">${title}</div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
      ${rows.map(([l,v,mono]) => `
        <div class="bg-slate-50 rounded-lg px-3 py-2 ring-1 ring-slate-100">
          <div class="text-[11px] text-slate-400">${l}</div>
          <div class="text-slate-800 break-all ${mono?MONO:'font-medium'}">${esc(v)}</div>
        </div>`).join('')}
    </div></div>`;
}

function openDetail(jenis, r) {
  currentDetail = r;
  let html = '';
  if (jenis === 'antrean') {
    html = section('📋 Booking', [
        ['Nomor Antrean',r.noantrean],['Kode Booking',r.kodebooking,true],
        ['Nomor Referensi',r.nomorreferensi,true],['Sumber Data',r.sumberdata],
        ['Status',r.status],['Waktu Dibuat',fmtTs(r.createdtime)]]) +
      section('👤 Pasien', [
        ['No. Rekam Medis',r.norekammedis,true],['NIK',r.nik,true],
        ['No. Kartu BPJS',r.nokapst,true],['No. HP',r.nohp,true],
        ['Peserta JKN',r.ispeserta?'Ya':'Tidak'],['Tanggal Kunjungan',r.tanggal]]) +
      section('🏥 Kunjungan', [
        ['Poli',r.kodepoli],['Kode Dokter',r.kodedokter],
        ['Jam Praktek',r.jampraktek],['Jenis Kunjungan',JENIS_KUNJ[r.jeniskunjungan]??r.jeniskunjungan],
        ['Estimasi Dilayani',fmtTs(r.estimasidilayani)],['Sumber',r.sumberdata]]);
  } else if (jenis === 'sep') {
    html = section('📄 Data SEP', [
        ['No. SEP',r.noSep,true],['Tgl SEP',r.tglSep],
        ['Tgl Pulang',r.tglPlgSep||'-'],['Jenis Pelayanan',JNS_PEL[r.jnsPelayanan]??r.jnsPelayanan],
        ['Kelas Rawat',r.kelasRawat],['Poli Eksekutif',String(r.poliEksekutif)==='1'?'Ya':'Tidak']]) +
      section('👤 Peserta', [
        ['Nama',r.namaPeserta],['No. Kartu',r.noKartu,true],
        ['No. RM',r.noMr||'-',true],['Tgl Lahir',r.tglLahir||'-'],
        ['Jenis Kelamin',SEX[r.sex]??(r.sex||'-')],['Jenis Peserta',r.jnsPeserta||'-'],
        ['Hak Kelas',r.hakKelas||'-'],['No. HP',r.noHP||'-',true]]) +
      section('🏥 Layanan', [
        ['Kode Provider',r.kdProvider,true],['Nama Provider',r.nmProvider||'-'],
        ['Poli',r.poli||'-'],['Diagnosa Awal',r.diagAwal||'-',true],
        ['No. Rujukan',r.noRujukan||'-',true],['Biaya Tagihan',fmtRp(r.byTagihan)],
        ['Biaya Top Up',fmtRp(r.byTopUp)]]);
  } else {
    html = section('⚡ Task', [
      ['Kode Booking',r.kodebooking,true],['Task ID',r.taskid],
      ['Nama Task',r.taskname||TASKS[r.taskid]||'-'],['Waktu',fmtTs(r.waktu)]]);
  }
  $('modalBody').innerHTML = html;
  $('modal').classList.remove('hidden');
}

function exportCsv(jenis) {
  const rows = view[jenis], cols = CSV_COLS[jenis];
  if (!rows.length) return alert('Tidak ada data untuk diekspor.');
  const q = v => `"${String(v ?? '').replace(/"/g,'""')}"`;
  const csv = '\uFEFF' + [cols.join(','), ...rows.map(r => cols.map(c => q(r[c])).join(','))].join('\r\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));
  a.download = `${jenis}_${$('tanggal').value}.csv`;
  a.click(); URL.revokeObjectURL(a.href);
}

const TAB_ON = 'bg-white text-indigo-700', TAB_OFF = 'bg-white/10 text-blue-100 hover:bg-white/20';
function setTab(t) {
  activeTab = t;
  document.querySelectorAll('.tab-btn').forEach(b =>
    b.className = 'tab-btn px-4 py-2 rounded-t-lg text-sm font-semibold transition ' + (b.dataset.tab===t?TAB_ON:TAB_OFF));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('hidden', p.dataset.panel!==t));
}

document.querySelectorAll('.tab-btn').forEach(b => b.onclick = () => setTab(b.dataset.tab));
[['tbodyAntrean','antrean'],['tbodySep','sep'],['tbodyTask','task']].forEach(([id,jenis]) =>
  $(id).addEventListener('click', e => {
    const tr = e.target.closest('tr[data-i]');
    if (tr) openDetail(jenis, view[jenis][+tr.dataset.i]);
  }));
 $('btnClose').onclick = () => $('modal').classList.add('hidden');
 $('modal').onclick = e => { if (e.target === $('modal')) $('modal').classList.add('hidden'); };
document.onkeydown = e => { if (e.key === 'Escape') $('modal').classList.add('hidden'); };
 $('btnSalin').onclick = async () => {
  if (!currentDetail) return;
  try { await navigator.clipboard.writeText(JSON.stringify(currentDetail, null, 2));
    $('btnSalin').textContent='Tersalin ✓'; setTimeout(()=>$('btnSalin').textContent='Salin JSON',1500);
  } catch { alert('Gagal menyalin'); }
};
 $('btnRefresh').onclick = () => load(activeTab);
 $('tanggal').addEventListener('change', onDateChange);
 $('jnsPelayanan').addEventListener('change', () => load('sep'));
['qAntrean','fPoli','fStatus','fSumber'].forEach(id => $(id).addEventListener('input', renderAntrean));
 $('qSep').addEventListener('input', renderSep);
 $('fKelas').addEventListener('change', renderSep);
 $('qTask').addEventListener('input', renderTask);
 $('fTask').addEventListener('change', renderTask);
 $('btnCsvAntrean').onclick = () => exportCsv('antrean');
 $('btnCsvSep').onclick = () => exportCsv('sep');
 $('btnCsvTask').onclick = () => exportCsv('task');
 $('autoRefresh').onchange = e => {
  if (autoTimer) { clearInterval(autoTimer); autoTimer = null; }
  if (e.target.checked) autoTimer = setInterval(() => load(activeTab), 30000);
};

const params = new URLSearchParams(location.search);
 $('tanggal').value = params.get('tanggal') || localStorage.getItem('bpjs-last-date') ||
                     new Date().toISOString().slice(0,10);
if (params.get('jns')) $('jnsPelayanan').value = params.get('jns');
setTab('antrean');
['antrean','sep','task'].forEach(load);
</script>
</body>
</html>