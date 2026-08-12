<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
header('Content-Type: application/json; charset=utf-8');
salamRequireLogin();
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int) ($input['id'] ?? 0);
$status = (string) ($input['status_pelanggan'] ?? '');
if ($id <= 0 || !in_array($status, ['Aktif','Tidak Aktif'], true)) { echo json_encode(['success'=>false,'message'=>'Data status pelanggan tidak valid.']); exit; }
if (!salamFindPelangganById($koneksi, $id)) { echo json_encode(['success'=>false,'message'=>'Pelanggan tidak ditemukan atau bukan wilayah akun ini.']); exit; }
if ($status === 'Aktif') {
    $stmt = $koneksi->prepare("UPDATE pelanggan_salam SET status_pelanggan='Aktif', waktu=DATE_FORMAT(CURDATE(), '%Y-%m-01'), langganan_selesai=LAST_DAY(CURDATE()), status_bayar='Belum Lunas', tagihan=tarif_langganan, tanggal_bayar=NULL, nominal_dibayar=NULL WHERE id=?");
    $message = 'Pelanggan diaktifkan dan tagihan bulan berjalan dibuat.';
} else {
    $stmt = $koneksi->prepare("UPDATE pelanggan_salam SET status_pelanggan='Tidak Aktif' WHERE id=?");
    $message = 'Pelanggan dinonaktifkan. Tagihan bulan berikutnya tidak akan dibuat otomatis.';
}
$stmt->bind_param('i', $id); $ok = $stmt->execute(); $stmt->close(); $koneksi->close();
echo json_encode(['success'=>$ok,'message'=>$ok ? $message : 'Gagal memperbarui status pelanggan.']);
?>
