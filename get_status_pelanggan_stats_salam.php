<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
header('Content-Type: application/json; charset=utf-8');
salamRequireLogin();
$wilayahFilter = trim((string) ($_GET['wilayah'] ?? 'all'));
$conditions = [salamScopeCondition($koneksi, 'alamat')];
if (salamCanAccessAllWilayah() && $wilayahFilter !== '' && strtolower($wilayahFilter) !== 'all') {
    $conditions[] = salamWilayahFilterCondition($koneksi, $wilayahFilter, 'alamat');
}
$scope = implode(' AND ', $conditions);
$sql = "SELECT SUM(CASE WHEN LOWER(TRIM(COALESCE(status_pelanggan,'')))='aktif' THEN 1 ELSE 0 END) pelanggan_aktif, SUM(CASE WHEN LOWER(TRIM(COALESCE(status_pelanggan,'')))='tidak aktif' THEN 1 ELSE 0 END) pelanggan_tidak_aktif FROM pelanggan_salam WHERE {$scope}";
$result = $koneksi->query($sql);
if (!$result) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Gagal mengambil statistik status pelanggan.']); exit; }
$row = $result->fetch_assoc();
echo json_encode(['success'=>true,'pelanggan_aktif'=>(int)($row['pelanggan_aktif']??0),'pelanggan_tidak_aktif'=>(int)($row['pelanggan_tidak_aktif']??0)]);
$koneksi->close();
?>
