<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
require_once __DIR__ . '/config_salam.php';
require_once __DIR__ . '/tagihan_periode_helper.php';
header('Content-Type: application/json; charset=utf-8');
salamRequireLogin();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int) ($input['id'] ?? 0);
$status = (string) ($input['status'] ?? '');
$periode = salamPeriodeYm($input['periode'] ?? date('Y-m'));

if ($id <= 0 || !in_array($status, ['Lunas','Belum Lunas'], true)) {
    echo json_encode(['success'=>false,'message'=>'Data status tidak valid.']);
    exit;
}

$data = salamFindTagihanPeriode($koneksi, $id, $periode);
if (!$data) {
    echo json_encode(['success'=>false,'message'=>'Tagihan periode tersebut tidak ditemukan atau bukan wilayah akun ini.']);
    exit;
}

$ok = salamUpdateStatusTagihanPeriode($koneksi, $id, $periode, $status);
if (!$ok) {
    echo json_encode(['success'=>false,'message'=>'Status pembayaran gagal diperbarui.']);
    exit;
}

$updated = salamFindTagihanPeriode($koneksi, $id, $periode);
$response = ['success'=>true,'message'=>'Status pembayaran diperbarui.','receipt'=>[
    'nama'=>$updated['nama'] ?? '-',
    'id_pelanggan'=>$updated['id_pelanggan'] ?? '-',
    'paket'=>$updated['paket'] ?? '-',
    'periode'=>salamBulananIndonesia($updated['waktu'] ?? null, false),
    'masa_aktif'=>salamBulananIndonesia($updated['langganan_selesai'] ?? null, true),
    'tanggal_bayar'=>salamBulananIndonesia($updated['tanggal_bayar'] ?? null, true),
    'nominal'=>salamRupiah($updated['nominal_dibayar'] ?? 0),
    'nama_layanan'=>salamNamaLayananUntukAlamat($updated['alamat'] ?? ''),
    'wilayah'=>salamNamaWilayahTampilan($updated['alamat'] ?? ''),
    'periode_ym'=>$periode,
]];
$koneksi->close();
echo json_encode($response);
