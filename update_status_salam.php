<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
require_once __DIR__ . '/config_salam.php';
header('Content-Type: application/json; charset=utf-8');
salamRequireLogin();
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int) ($input['id'] ?? 0);
$status = (string) ($input['status'] ?? '');
if ($id <= 0 || !in_array($status, ['Lunas','Belum Lunas'], true)) { echo json_encode(['success'=>false,'message'=>'Data status tidak valid.']); exit; }
$data = salamFindPelangganById($koneksi, $id);
if (!$data) { echo json_encode(['success'=>false,'message'=>'Pelanggan tidak ditemukan atau bukan wilayah akun ini.']); exit; }
if ($status === 'Lunas') {
    if (($data['status_bayar'] ?? '') !== 'Lunas') {
        $nominal = (float) ($data['tagihan'] ?? 0);
        $update = $koneksi->prepare("UPDATE pelanggan_salam SET status_bayar='Lunas', nominal_dibayar=?, tanggal_bayar=CURDATE(), tagihan=0 WHERE id=?");
        $update->bind_param('di', $nominal, $id); $ok = $update->execute(); $update->close();
    } else $ok = true;
} else {
    $update = $koneksi->prepare("UPDATE pelanggan_salam SET status_bayar='Belum Lunas', tagihan=tarif_langganan, nominal_dibayar=NULL, tanggal_bayar=NULL WHERE id=?");
    $update->bind_param('i', $id); $ok = $update->execute(); $update->close();
}
if (!$ok) { echo json_encode(['success'=>false,'message'=>'Status pembayaran gagal diperbarui.']); exit; }
$updated = salamFindPelangganById($koneksi, $id);
$response = ['success'=>true,'message'=>'Status pembayaran diperbarui.','receipt'=>[
    'nama'=>$updated['nama'],'id_pelanggan'=>$updated['id_pelanggan'],'paket'=>$updated['paket'],
    'periode'=>salamBulananIndonesia($updated['waktu'], false),'masa_aktif'=>salamBulananIndonesia($updated['langganan_selesai'], true),
    'tanggal_bayar'=>salamBulananIndonesia($updated['tanggal_bayar'], true),'nominal'=>salamRupiah($updated['nominal_dibayar'] ?? 0),
    'nama_layanan'=>salamNamaLayananUntukAlamat($updated['alamat'] ?? ''),'wilayah'=>salamNamaWilayahTampilan($updated['alamat'] ?? '')
]];
$koneksi->close(); echo json_encode($response);
?>
