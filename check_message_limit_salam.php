<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
header('Content-Type: application/json; charset=utf-8');
salamRequireLogin();
$method = $_GET['method'] ?? '';
$idPelanggan = trim((string) ($_POST['id_pelanggan'] ?? $_GET['id_pelanggan'] ?? ''));
$wilayah = salamCanAccessAllWilayah()
    ? salamNormalisasiAlamatInput($_POST['wilayah'] ?? $_GET['wilayah'] ?? '')
    : salamWilayahLogin();
if ($idPelanggan !== '') {
    $scope = salamScopeCondition($koneksi, 'alamat');
    $stmtCari = $koneksi->prepare("SELECT alamat FROM pelanggan_salam WHERE id_pelanggan = ? AND {$scope} LIMIT 1");
    $stmtCari->bind_param('s', $idPelanggan);
    $stmtCari->execute();
    if ($row = $stmtCari->get_result()->fetch_assoc()) {
        $wilayah = salamNormalisasiAlamatInput($row['alamat'] ?? $wilayah);
    }
    $stmtCari->close();
}
if ($wilayah === '' || salamIsWilayahSemua($wilayah)) {
    if (salamCanAccessAllWilayah()) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Wilayah pelanggan tidak dapat ditentukan.','wilayah'=>'']);
        exit;
    }
    $wilayah = salamWilayahLogin();
}
if ($method === 'check') {
    $stmt = $koneksi->prepare("SELECT COUNT(*) AS count FROM message_send_log WHERE wilayah = ? AND DATE(created_at) = CURDATE()");
    $stmt->bind_param('s', $wilayah); $stmt->execute(); $result = $stmt->get_result()->fetch_assoc(); $stmt->close();
    $count = (int) ($result['count'] ?? 0); echo json_encode(['count'=>$count,'limit'=>40,'reached_limit'=>$count>=40,'wilayah'=>$wilayah]);
} elseif ($method === 'increment') {
    $type = 'tagihan';
    $stmt = $koneksi->prepare("INSERT INTO message_send_log (wilayah, id_pelanggan, message_type, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
    $stmt->bind_param('sss', $wilayah, $idPelanggan, $type); $ok = $stmt->execute(); $stmt->close(); echo json_encode(['success'=>$ok,'wilayah'=>$wilayah]);
} else echo json_encode(['success'=>false,'message'=>'Method tidak valid.']);
?>
