<?php
session_start();
require_once __DIR__ . '/helpers_salam.php';
salamRequireLogin();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring PPPoE - Billing Salam</title>
    <link rel="icon" href="logo_cleon.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <style>
        :root{--primary:#3498db;--secondary:#2c3e50;--success:#27ae60;--danger:#e74c3c;--muted:#6c757d;--bg:#f5f7f9;--card:#fff}
        *{box-sizing:border-box}html,body{margin:0;height:100%;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:var(--bg);color:#263238}
        .header{height:72px;background:linear-gradient(135deg,var(--secondary),#1a2530);color:#fff;padding:0 26px;display:flex;align-items:center;justify-content:space-between;gap:16px;box-shadow:0 4px 12px rgba(0,0,0,.12)}
        .header h1{font-size:22px;margin:0;display:flex;align-items:center;gap:10px}.header-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.chip,.btn{border:0;border-radius:7px;padding:9px 14px;display:inline-flex;align-items:center;gap:7px;font-weight:600;text-decoration:none}.chip{background:rgba(255,255,255,.12);color:#fff}.readonly{background:#eafaf1;color:#16804b}.btn{background:#3498db;color:#fff;cursor:pointer}.btn:hover{filter:brightness(.95)}
        .page{height:calc(100% - 72px);display:grid;grid-template-columns:285px 1fr;min-height:0}.sidebar{background:#fff;border-right:1px solid #e7edf2;padding:18px;overflow:auto}.brand{font-size:18px;font-weight:800;color:#ff6336;margin-bottom:16px;text-align:center}.stats{display:grid;grid-template-columns:1fr 1fr;gap:10px}.stat{background:#f8fafc;border:1px solid #edf2f7;border-radius:10px;padding:13px;text-align:center}.stat.total{grid-column:1/-1}.stat strong{font-size:25px;display:block}.stat.online strong{color:var(--success)}.stat.offline strong{color:var(--danger)}.label{font-size:12px;color:var(--muted)}
        .search{margin-top:16px;position:relative}.search i{position:absolute;left:11px;top:12px;color:#8795a1}.search input{width:100%;padding:10px 10px 10px 34px;border:1px solid #dce5ec;border-radius:8px;outline:0}.notice{font-size:12px;line-height:1.5;color:#64748b;background:#f8fafc;border-radius:8px;padding:10px;margin-top:14px}.statusline{font-size:12px;margin-top:12px;color:#64748b}.error{display:none;background:#fff1f0;color:#b42318;border:1px solid #ffd5d2;border-radius:8px;padding:10px;margin-top:12px;line-height:1.4}.map-wrap{position:relative;min-width:0;min-height:0}#map{height:100%;width:100%;background:#e9eef2}.loading{position:absolute;z-index:1000;left:50%;top:20px;transform:translateX(-50%);background:#fff;padding:10px 14px;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.15);font-size:13px}
        .pppoe-marker{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;color:#fff;border:2px solid #fff;box-shadow:0 2px 7px rgba(0,0,0,.35);font-size:13px}.pppoe-marker.online{background:#27ae60}.pppoe-marker.offline{background:#e74c3c}.pppoe-marker.unknown{background:#7f8c8d}
        .leaflet-popup-content-wrapper{border-radius:12px}.leaflet-popup-content{margin:12px;width:285px!important}.customer-card{font-size:12px}.house-photo{width:100%;height:145px;object-fit:cover;border-radius:9px;background:#eef2f5;margin-bottom:9px}.photo-empty{height:90px;border-radius:9px;background:#f1f5f9;display:grid;place-items:center;color:#94a3b8;margin-bottom:9px}.customer-title{font-size:15px;font-weight:800;color:#1f2937;margin-bottom:6px}.network-row{padding:7px 8px;background:#f8fafc;border-radius:7px;margin-bottom:7px}.detail-grid{display:grid;grid-template-columns:96px 1fr;gap:4px 8px}.detail-grid b{color:#475569}.online-text{color:#16804b;font-weight:800}.offline-text{color:#c0392b;font-weight:800}.unlinked{background:#fff8e6;border:1px solid #ffe4a8;color:#8a5a00;border-radius:8px;padding:9px;line-height:1.45;margin-top:8px}
        @media(max-width:800px){.header{height:auto;min-height:72px;padding:12px 15px;align-items:flex-start}.header h1{font-size:17px}.page{height:calc(100% - 96px);grid-template-columns:1fr;grid-template-rows:auto 1fr}.sidebar{padding:10px;border-right:0;border-bottom:1px solid #e7edf2}.brand,.notice{display:none}.stats{grid-template-columns:repeat(3,1fr)}.stat.total{grid-column:auto}.stat{padding:8px}.stat strong{font-size:18px}.search{margin-top:8px}.map-wrap{min-height:500px}}
    </style>
</head>
<body>
<div class="header">
    <h1><i class="fas fa-map-location-dot"></i> Monitoring PPPoE <span class="readonly"><i class="fas fa-eye"></i> READ ONLY</span></h1>
    <div class="header-actions">
        <span class="chip"><i class="fas fa-user-circle"></i> <?= htmlspecialchars((string) ($_SESSION['username'] ?? '')); ?></span>
        <a class="btn" href="dashboard_salam.php"><i class="fas fa-arrow-left"></i> Dashboard Billing</a>
    </div>
</div>
<div class="page">
    <aside class="sidebar">
        <div class="brand">PPPoE Command Center</div>
        <div class="stats">
            <div class="stat total"><strong id="totalCount">-</strong><span class="label">Total PPPoE</span></div>
            <div class="stat online"><strong id="onlineCount">-</strong><span class="label">Online</span></div>
            <div class="stat offline"><strong id="offlineCount">-</strong><span class="label">Offline</span></div>
        </div>
        <div class="search"><i class="fas fa-search"></i><input id="searchInput" type="text" placeholder="Cari PPPoE / pelanggan..."></div>
        <div class="notice"><b>Mode monitoring saja.</b><br>Status, IP, koordinat, ID teknis, dan username jaringan dibaca dari PPPoE asli. Billing mencocokkan data pelanggan saat halaman dibuka dan hanya menambahkan ID pelanggan, Nama KTP, NIK, serta Foto Rumah pada tampilan. Tidak ada data PPPoE yang disimpan ke database Billing. Tidak ada tombol tambah, edit, hapus, atau perubahan Mikrotik.</div>
        <div id="errorBox" class="error"></div>
        <div id="statusLine" class="statusline">Menunggu data...</div>
    </aside>
    <main class="map-wrap">
        <div id="loading" class="loading"><i class="fas fa-spinner fa-spin"></i> Memuat data PPPoE...</div>
        <div id="map"></div>
    </main>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
const map = L.map('map', {zoomControl:true}).setView([-7.85,110.48], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom:19,
    attribution:'&copy; OpenStreetMap contributors'
}).addTo(map);

const layer = L.layerGroup().addTo(map);
let allData = [];
let firstLoad = true;
let currentSearch = '';

function esc(value){return String(value ?? '').replace(/[&<>'"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));}
function validPhoto(path){return /^uploads\/foto_rumah\/[A-Za-z0-9._-]+$/.test(String(path||''));}
function markerIcon(status){const s=String(status||'').toUpperCase();const cls=s==='ONLINE'?'online':(s==='OFFLINE'?'offline':'unknown');return L.divIcon({className:'',html:`<div class="pppoe-marker ${cls}"><i class="fas fa-wifi"></i></div>`,iconSize:[28,28],iconAnchor:[14,14],popupAnchor:[0,-14]});}
function popupHtml(item){
    const b=item.billing;
    const status=String(item.status||'UNKNOWN').toUpperCase();
    const statusClass=status==='ONLINE'?'online-text':(status==='OFFLINE'?'offline-text':'');
    // Koordinat selalu mengikuti sumber PPPoE asli.
    const x=item.longitude;
    const y=item.latitude;
    let photo='';
    if(b && validPhoto(b.foto_rumah)) photo=`<img class="house-photo" src="${esc(b.foto_rumah)}" alt="Foto rumah pelanggan">`;
    else photo=`<div class="photo-empty"><span><i class="fas fa-house"></i> Foto rumah belum tersedia</span></div>`;
    let detail='';
    if(b){
        detail=`${photo}<div class="customer-title">${esc(b.nama_ktp || b.nama || item.user || '-')}</div>
        <div class="detail-grid">
            <b>ID Pelanggan</b><span>${esc(b.id_pelanggan || '-')}</span>
            <b>Nama KTP</b><span>${esc(b.nama_ktp || '-')}</span>
            <b>NIK</b><span>${esc(b.nik || '-')}</span>
            <b>Koordinat X</b><span>${esc(x)}</span>
            <b>Koordinat Y</b><span>${esc(y)}</span>
            <b>Alamat</b><span>${esc(b.alamat || '-')}</span>
        </div>`;
    } else {
        detail=`${photo}<div class="unlinked"><b>Belum terhubung otomatis ke data Billing.</b><br>Billing tetap menampilkan posisi dan status asli dari PPPoE. Profil Billing akan muncul jika ID atau nama dapat dicocokkan saat halaman dibuka.</div>`;
    }
    return `<div class="customer-card">
        <div class="network-row"><b>${esc(item.user || item.lokasi || '-')}</b><br>${esc(item.lokasi || '')}<br>IP: ${esc(item.ip || '-')}<br>Status: <span class="${statusClass}">${esc(status)}</span></div>
        ${detail}
    </div>`;
}
function matches(item, term){
    if(!term) return true;
    const b=item.billing||{};
    const hay=[item.user,item.lokasi,item.ip,item.status,b.id_pelanggan,b.nama,b.nama_ktp,b.nik,b.alamat].join(' ').toLowerCase();
    return hay.includes(term);
}
function render(){
    layer.clearLayers();
    const filtered=allData.filter(x=>matches(x,currentSearch));
    const bounds=[];
    filtered.forEach(item=>{
        const lat=Number(item.latitude), lng=Number(item.longitude);
        if(!Number.isFinite(lat)||!Number.isFinite(lng)) return;
        const marker=L.marker([lat,lng],{icon:markerIcon(item.status)}).bindPopup(popupHtml(item),{maxWidth:320});
        marker.addTo(layer); bounds.push([lat,lng]);
    });
    if(firstLoad && bounds.length){map.fitBounds(bounds,{padding:[35,35]});firstLoad=false;}
}
function updateStats(){
    document.getElementById('totalCount').textContent=allData.length;
    document.getElementById('onlineCount').textContent=allData.filter(x=>String(x.status).toUpperCase()==='ONLINE').length;
    document.getElementById('offlineCount').textContent=allData.filter(x=>String(x.status).toUpperCase()==='OFFLINE').length;
}
async function loadData(){
    const loading=document.getElementById('loading'); const error=document.getElementById('errorBox');
    try{
        if(firstLoad) loading.style.display='block';
        const res=await fetch('get_pppoe_readonly.php',{cache:'no-store'});
        const data=await res.json();
        if(!res.ok || !data.success) throw new Error(data.message||'Data PPPoE gagal dimuat.');
        allData=Array.isArray(data.data)?data.data:[];
        updateStats(); render(); error.style.display='none';
        const when=new Date(data.fetched_at||Date.now());
        document.getElementById('statusLine').textContent=`Data aktif: ${allData.length} titik • diperbarui ${when.toLocaleTimeString('id-ID')}`;
    }catch(e){
        error.textContent=e.message; error.style.display='block';
        document.getElementById('statusLine').textContent='Sumber PPPoE belum dapat dibaca.';
    }finally{loading.style.display='none';}
}
document.getElementById('searchInput').addEventListener('input',e=>{currentSearch=e.target.value.trim().toLowerCase();render();});
loadData();
setInterval(loadData,5000);
</script>
</body>
</html>
