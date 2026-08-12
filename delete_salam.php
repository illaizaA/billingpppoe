<?php
session_start();

require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
require_once __DIR__ . '/pelanggan_detail_helper.php';

header('Content-Type: application/json; charset=utf-8');
salamRequireLogin();

$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'ID pelanggan tidak valid.'
    ]);
    exit;
}

$scope = salamScopeCondition($koneksi, 'p.alamat');
$fotoRumah = '';

// Ambil path foto hanya untuk pelanggan yang memang boleh diakses akun ini.
if (salamDetailPelangganTableReady($koneksi)) {
    $photoStmt = $koneksi->prepare(
        "SELECT d.foto_rumah
         FROM pelanggan_salam p
         LEFT JOIN pelanggan_detail_salam d ON d.pelanggan_id = p.id
         WHERE p.id = ?
           AND {$scope}
         LIMIT 1"
    );

    if ($photoStmt) {
        $photoStmt->bind_param('i', $id);
        $photoStmt->execute();
        $photoRow = $photoStmt->get_result()->fetch_assoc();
        $fotoRumah = (string) ($photoRow['foto_rumah'] ?? '');
        $photoStmt->close();
    }
}

$deleteScope = salamScopeCondition($koneksi, 'alamat');
$stmt = $koneksi->prepare(
    "DELETE FROM pelanggan_salam
     WHERE id = ?
       AND {$deleteScope}
     LIMIT 1"
);

$stmt->bind_param('i', $id);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($ok && $affected > 0 && $fotoRumah !== '') {
    // Record pelanggan_detail_salam ikut terhapus melalui ON DELETE CASCADE.
    // File fisik foto dibersihkan terpisah agar tidak menumpuk di server.
    salamHapusFotoRumah($fotoRumah);
}

$koneksi->close();

echo json_encode([
    'success' => $ok && $affected > 0,
    'message' => $affected > 0
        ? 'Data pelanggan dihapus.'
        : 'Data pelanggan tidak ditemukan atau bukan wilayah akun ini.'
]);
?>
