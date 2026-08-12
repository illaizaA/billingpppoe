<?php
session_start();

require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
require_once __DIR__ . '/pelanggan_detail_helper.php';
require_once __DIR__ . '/config_monitoring_pppoe.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

salamRequireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'read_only' => true,
        'message' => 'Monitoring hanya mendukung pembacaan data.'
    ]);
    exit;
}

/**
 * Membaca data PPPoE dengan GET saja.
 * Tidak ada POST / PUT / PATCH / DELETE.
 */
function salamFetchJsonReadonly(string $url, int $timeout): array
{
    $body = false;
    $httpCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException(
                'Sumber PPPoE tidak dapat dihubungi: '
                . ($curlError ?: 'koneksi gagal')
            );
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw new RuntimeException(
                'Sumber PPPoE tidak dapat dihubungi dari server Billing.'
            );
        }

        if (
            !empty($http_response_header[0])
            && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match)
        ) {
            $httpCode = (int) $match[1];
        }
    }

    if ($httpCode !== 0 && ($httpCode < 200 || $httpCode >= 300)) {
        throw new RuntimeException('Sumber PPPoE merespons HTTP ' . $httpCode . '.');
    }

    $decoded = json_decode((string) $body, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Respons PPPoE bukan JSON yang valid.');
    }

    // Mendukung respons array langsung atau {"data":[...]}.
    if (isset($decoded['data']) && is_array($decoded['data'])) {
        return $decoded['data'];
    }

    return $decoded;
}

function salamRuntimeNormalize(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }

    $value = strtolower($value);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

function salamRuntimePppoeNameKeys(array $row): array
{
    $keys = [];

    foreach (['lokasi', 'user', 'name', 'nama'] as $field) {
        $raw = trim((string) ($row[$field] ?? ''));

        if ($raw === '') {
            continue;
        }

        $normalized = salamRuntimeNormalize($raw);

        if ($normalized !== '') {
            $keys[$normalized] = true;
        }

        // Ambil bagian setelah tanda "-" untuk nama seperti:
        // salam-tulus / SLM-Tulus / waduk-jumbadi
        if (str_contains($raw, '-')) {
            $parts = explode('-', $raw, 2);
            $suffix = salamRuntimeNormalize($parts[1] ?? '');

            if ($suffix !== '') {
                $keys[$suffix] = true;
            }
        }
    }

    return array_keys($keys);
}

function salamBillingPublicRow(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'id_pelanggan' => (string) ($row['id_pelanggan'] ?? ''),
        'nama' => (string) ($row['nama'] ?? ''),
        'nama_ktp' => (string) ($row['nama_ktp'] ?? ''),
        'nik' => (string) ($row['nik'] ?? ''),
        'alamat' => (string) ($row['alamat'] ?? ''),
        'foto_rumah' => (string) ($row['foto_rumah'] ?? ''),
    ];
}

/**
 * Membuat index Billing di MEMORY untuk request ini saja.
 * Tidak menyimpan mapping ke database.
 */
function salamBuildRuntimeBillingIndex(array $rows): array
{
    $byCustomerId = [];
    $byName = [];

    foreach ($rows as $row) {
        $internalId = (int) ($row['id'] ?? 0);

        if ($internalId <= 0) {
            continue;
        }

        $customerId = salamRuntimeNormalize($row['id_pelanggan'] ?? '');

        if ($customerId !== '') {
            $byCustomerId[$customerId][$internalId] = $row;
        }

        foreach ([$row['nama'] ?? '', $row['nama_ktp'] ?? ''] as $name) {
            $key = salamRuntimeNormalize($name);

            if ($key !== '') {
                $byName[$key][$internalId] = $row;
            }
        }
    }

    return [
        'by_customer_id' => $byCustomerId,
        'by_name' => $byName,
    ];
}

/**
 * Cocokkan hanya ketika hasilnya pasti/tunggal.
 * Urutan:
 * 1. ID Pelanggan Billing == ID PPPoE.
 * 2. Nama/Nama KTP Billing == nama/lokasi/user PPPoE.
 *
 * Tidak ada tebakan dan tidak ada penyimpanan mapping.
 */
function salamFindRuntimeBillingMatch(array $pppoeRow, array $index): ?array
{
    $pppoeId = salamRuntimeNormalize($pppoeRow['id'] ?? '');

    if ($pppoeId !== '' && isset($index['by_customer_id'][$pppoeId])) {
        $rows = array_values($index['by_customer_id'][$pppoeId]);

        if (count($rows) === 1) {
            return $rows[0];
        }
    }

    $candidateRows = [];

    foreach (salamRuntimePppoeNameKeys($pppoeRow) as $nameKey) {
        if (!isset($index['by_name'][$nameKey])) {
            continue;
        }

        foreach ($index['by_name'][$nameKey] as $internalId => $row) {
            $candidateRows[(int) $internalId] = $row;
        }
    }

    if (count($candidateRows) === 1) {
        return array_values($candidateRows)[0];
    }

    return null;
}

try {
    // 1. Baca PPPoE asli.
    $pppoes = salamFetchJsonReadonly(
        PPPOE_MONITOR_API_URL,
        PPPOE_MONITOR_TIMEOUT_SECONDS
    );

    // 2. Baca data Billing sesuai hak akses/wilayah login.
    $scope = salamScopeCondition($koneksi, 'p.alamat');

    $billingRows = [];

    $sql = "SELECT p.id,
                   p.id_pelanggan,
                   p.nama,
                   p.alamat,
                   d.nama_ktp,
                   d.nik,
                   d.foto_rumah
            FROM pelanggan_salam p
            LEFT JOIN pelanggan_detail_salam d
              ON d.pelanggan_id = p.id
            WHERE {$scope}";

    $result = $koneksi->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $billingRows[] = $row;
        }

        $result->free();
    }

    // 3. Index hanya di memory. Tidak ada tabel mapping.
    $runtimeIndex = salamBuildRuntimeBillingIndex($billingRows);
    $clean = [];

    foreach ($pppoes as $row) {
        if (!is_array($row)) {
            continue;
        }

        // Jika source menyediakan field icon, pertahankan hanya marker pelanggan.
        // Jika field icon tidak ada, jangan dibuang.
        if (array_key_exists('icon', $row) && (int) $row['icon'] !== 119) {
            continue;
        }

        // Posisi SELALU dari PPPoE.
        $latitude = $row['latitude'] ?? $row['lat'] ?? null;
        $longitude = $row['longitude'] ?? $row['lng'] ?? $row['lon'] ?? null;

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            continue;
        }

        // 4. Cocokkan di memory tanpa menyimpan apa pun.
        $billingRow = salamFindRuntimeBillingMatch($row, $runtimeIndex);

        $clean[] = [
            'id' => trim((string) ($row['id'] ?? '')),
            'user' => trim((string) ($row['user'] ?? $row['username'] ?? '')),
            'lokasi' => (string) ($row['lokasi'] ?? $row['name'] ?? ''),
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'ip' => (string) ($row['ip'] ?? '-'),
            'status' => strtoupper((string) ($row['status'] ?? 'UNKNOWN')),
            'router' => (string) ($row['router'] ?? ''),
            'billing' => $billingRow ? salamBillingPublicRow($billingRow) : null,
        ];
    }

    echo json_encode([
        'success' => true,
        'read_only' => true,
        'source' => PPPOE_MONITOR_BASE_URL,
        'fetched_at' => date('c'),
        'data' => $clean,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $error) {
    http_response_code(502);

    echo json_encode([
        'success' => false,
        'read_only' => true,
        'source' => PPPOE_MONITOR_BASE_URL,
        'message' => $error->getMessage(),
        'data' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$koneksi->close();
?>
