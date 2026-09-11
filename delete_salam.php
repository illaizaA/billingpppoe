<?php
session_start();

require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
require_once __DIR__ . '/pelanggan_detail_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
salamRequireLogin();

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
$refererPath = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
$fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
$legacyDashboardRequest = $requestMethod === 'GET'
    && isset($_GET['id'])
    && basename($refererPath) === 'dashboard_salam.php'
    && in_array($fetchSite, ['same-origin', 'same-site'], true);

// POST adalah alur final. GET lama hanya diterima dari fetch dashboard yang
// masih tersimpan di cache browser agar data telanjur salah tetap dapat dihapus.
if ($requestMethod !== 'POST' && !$legacyDashboardRequest) {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Penghapusan hanya dapat dilakukan melalui konfirmasi aplikasi.',
        'request_method' => $requestMethod,
    ]);
    exit;
}

if ($requestMethod === 'POST' && (string) ($_POST['confirmed'] ?? '') !== '1') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Konfirmasi penghapusan tidak valid.'
    ]);
    exit;
}

$id = (int) ($requestMethod === 'POST' ? ($_POST['id'] ?? 0) : ($_GET['id'] ?? 0));

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
$deletedDetail = 0;
$deletedMapping = 0;

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
    $customerRow = $checkStmt->get_result()->fetch_assoc();
    $allowed = (bool) $customerRow;
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

    // Bersihkan mapping secara eksplisit agar tetap aman pada database lama
    // yang belum memiliki ON DELETE CASCADE.
    $mappingTable = $koneksi->query("SHOW TABLES LIKE 'pelanggan_pppoe_mapping'");
    if ($mappingTable instanceof mysqli_result && $mappingTable->num_rows > 0) {
        $mappingStmt = $koneksi->prepare('DELETE FROM pelanggan_pppoe_mapping WHERE pelanggan_id = ?');
        if (!$mappingStmt) {
            throw new RuntimeException($koneksi->error);
        }
        $mappingStmt->bind_param('i', $id);
        if (!$mappingStmt->execute()) {
            throw new RuntimeException($mappingStmt->error);
        }
        $deletedMapping = $mappingStmt->affected_rows;
        $mappingStmt->close();
    }

    if (salamDetailPelangganTableReady($koneksi)) {
        $detailStmt = $koneksi->prepare('DELETE FROM pelanggan_detail_salam WHERE pelanggan_id = ?');
        if (!$detailStmt) {
            throw new RuntimeException($koneksi->error);
        }
        $detailStmt->bind_param('i', $id);
        if (!$detailStmt->execute()) {
            throw new RuntimeException($detailStmt->error);
        }
        $deletedDetail = $detailStmt->affected_rows;
        $detailStmt->close();
    }

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
        ? 'Pelanggan, tagihan, detail, dan koneksi PPPoE Billing berhasil dihapus permanen.'
        : ($errorMessage ?? 'Data pelanggan gagal dihapus.'),
    'deleted_bills' => $ok ? $deletedBills : 0,
    'deleted_detail' => $ok ? $deletedDetail : 0,
    'deleted_mapping' => $ok ? $deletedMapping : 0,
]);
?>
