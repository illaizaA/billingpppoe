<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
header('Content-Type: application/json; charset=utf-8');
salamRequireLogin();

if (!salamCanAccessAllWilayah()) {
    echo json_encode(['alamat' => [salamWilayahLogin()]], JSON_UNESCAPED_UNICODE);
    exit;
}

$alamat = [];
$seen = [];
$addAlamat = function ($value) use (&$alamat, &$seen) {
    $value = salamNormalisasiAlamatInput($value);
    if ($value === '') return;
    $key = salamNormalisasiKunci($value);
    if (isset($seen[$key])) return;
    $seen[$key] = true;
    $alamat[] = $value;
};

foreach (salamDaftarWilayahResmi() as $item) {
    $addAlamat($item);
}

$result = $koneksi->query("SELECT alamat FROM pelanggan_salam WHERE alamat IS NOT NULL AND TRIM(alamat) <> '' ORDER BY alamat ASC LIMIT 500");
if ($result instanceof mysqli_result) {
    while ($row = $result->fetch_assoc()) {
        $addAlamat($row['alamat']);
    }
}

$resultRiwayat = $koneksi->query("SELECT alamat_snapshot AS alamat FROM tagihan_salam WHERE alamat_snapshot IS NOT NULL AND TRIM(alamat_snapshot) <> '' ORDER BY alamat_snapshot ASC LIMIT 500");
if ($resultRiwayat instanceof mysqli_result) {
    while ($row = $resultRiwayat->fetch_assoc()) {
        $addAlamat($row['alamat']);
    }
}

sort($alamat, SORT_NATURAL | SORT_FLAG_CASE);
echo json_encode(['alamat' => $alamat], JSON_UNESCAPED_UNICODE);
?>
