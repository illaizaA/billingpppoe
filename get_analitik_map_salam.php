<?php
session_start();

require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
require_once __DIR__ . '/analitik_helper.php';
require_once __DIR__ . '/analitik_pppoe_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    analitikRequireAccess();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'read_only' => true,
            'message' => 'Peta analitik hanya mendukung pembacaan data.',
            'data' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $range = analitikResolvePeriodRange($_GET);
    $scope = analitikResolveWilayah($_GET['wilayah'] ?? 'all');
    $map = analitikBuildPppoeMap($koneksi, $range['awal'], $range['akhir'], $scope);

    echo json_encode([
        'success' => true,
        'read_only' => true,
        'periode' => [
            'awal' => $range['awal'],
            'akhir' => $range['akhir'],
        ],
        'scope' => [
            'label' => $scope['label'],
            'is_all' => (bool) $scope['is_all'],
        ],
        'counts' => $map['counts'],
        'data' => $map['points'],
        'regions' => $map['regions'],
        'geojson' => $map['geojson'],
        'fetched_at' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'read_only' => true,
        'message' => $e->getMessage(),
        'data' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

if (isset($koneksi) && $koneksi instanceof mysqli) {
    $koneksi->close();
}
