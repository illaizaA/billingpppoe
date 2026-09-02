<?php
session_start();

require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
require_once __DIR__ . '/pelanggan_detail_helper.php';

header('Content-Type: application/json; charset=utf-8');
salamRequireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Penghapusan hanya dapat dilakukan melalui konfirmasi aplikasi.'
    ]);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);

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
$ok = false;
$affected = 0;
$deletedBills = 0;

try {
    $koneksi->begin_transaction();

    // Pastikan pelanggan memang ada dan berada dalam wilayah yang boleh diakses.
    $checkStmt = $koneksi->prepare(
        "SELECT id FROM pelanggan_salam
         WHERE id = ? AND {$deleteScope}
         LIMIT 1
         FOR UPDATE"
    );
    if (!$checkStmt) {
        throw new RuntimeException($koneksi->error);
    }
    $checkStmt->bind_param('i', $id);
    $checkStmt->execute();
    $allowed = (bool) $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$allowed) {
        throw new RuntimeException('Data pelanggan tidak ditemukan atau bukan wilayah akun ini.');
    }

    // Riwayat tagihan memakai relasi logis, sehingga harus dibersihkan eksplisit.
    $billStmt = $koneksi->prepare('DELETE FROM tagihan_salam WHERE pelanggan_id = ?');
    if (!$billStmt) {
        throw new RuntimeException($koneksi->error);
    }
    $billStmt->bind_param('i', $id);
    if (!$billStmt->execute()) {
        throw new RuntimeException($billStmt->error);
    }
    $deletedBills = $billStmt->affected_rows;
    $billStmt->close();

    // Hapus master. Detail dan mapping mengikuti ON DELETE CASCADE jika terpasang.
    $stmt = $koneksi->prepare(
        "DELETE FROM pelanggan_salam
         WHERE id = ? AND {$deleteScope}
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException($koneksi->error);
    }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected !== 1) {
        throw new RuntimeException('Pelanggan gagal dihapus.');
    }

    $koneksi->commit();
    $ok = true;
} catch (Throwable $e) {
    $koneksi->rollback();
    $errorMessage = $e->getMessage();
}

if ($ok && $fotoRumah !== '') {
    // File fisik baru dibersihkan setelah transaksi database berhasil.
    salamHapusFotoRumah($fotoRumah);
}

$koneksi->close();

echo json_encode([
    'success' => $ok,
    'message' => $ok
        ? 'Pelanggan dan seluruh riwayat tagihannya berhasil dihapus permanen.'
        : ($errorMessage ?? 'Data pelanggan gagal dihapus.'),
    'deleted_bills' => $ok ? $deletedBills : 0
]);
?>
