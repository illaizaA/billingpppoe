<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
header('Content-Type: application/json; charset=utf-8');
salamRequireLogin();
$periode = date('Y-m-01');
$wilayahFilter = trim((string) ($_GET['wilayah'] ?? 'all'));
$conditions = [salamScopeCondition($koneksi, 'alamat')];
if (salamCanAccessAllWilayah() && $wilayahFilter !== '' && strtolower($wilayahFilter) !== 'all') {
    $conditions[] = salamWilayahFilterCondition($koneksi, $wilayahFilter, 'alamat');
}
$scope = implode(' AND ', $conditions);
$total = (int) ($koneksi->query("SELECT COUNT(*) total FROM pelanggan_salam WHERE {$scope}")?->fetch_assoc()['total'] ?? 0);
$lunas = (int) ($koneksi->query("SELECT COUNT(*) total FROM pelanggan_salam WHERE {$scope} AND status_bayar='Lunas' AND waktu='{$periode}'")?->fetch_assoc()['total'] ?? 0);
$belum = (int) ($koneksi->query("SELECT COUNT(*) total FROM pelanggan_salam WHERE {$scope} AND status_bayar='Belum Lunas' AND waktu='{$periode}'")?->fetch_assoc()['total'] ?? 0);
$koneksi->close();
echo json_encode(['total_pelanggan'=>$total,'lunas_bulan_ini'=>$lunas,'belum_lunas_bulan_ini'=>$belum]);
?>
