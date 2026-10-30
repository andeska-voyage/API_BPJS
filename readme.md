# 0. node --check server.js && pm2 restart bpjs-dashboard
# 1. pastikan dashboard hidup
curl http://127.0.0.1:3001/ping

# 2. aktifkan kirim-laporan + paksa sekali
curl -s -X POST http://127.0.0.1:3001/monitor/options \
     -H 'Content-Type: application/json' -d '{"kirimSaatUp":true}' > /dev/null
curl -s http://127.0.0.1:3001/monitor/run

# 3. lihat isi pesan yang barusan terkirim (dan cocokkan dengan HP anda)
# 4. matikan lagi kirimSaatUp kalau tidak mau spam tiap jam:
curl -s -X POST http://127.0.0.1:3001/monitor/options \
     -H 'Content-Type: application/json' -d '{"kirimSaatUp":false}' > /dev/null