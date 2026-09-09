<?php
/**
 * Helper peta Dashboard Analitik dari sumber PPPoE.
 *
 * PRINSIP:
 * - Koordinat / status jaringan selalu dibaca dari endpoint PPPoE dengan GET.
 * - Tidak ada POST / PUT / PATCH / DELETE ke PPPoE.
 * - Data pembayaran tetap dibaca dari database Billing.
 * - Matching dilakukan di memory pada setiap request dan tidak disimpan ke PPPoE.
 */

require_once __DIR__ . '/config_monitoring_pppoe.php';

function analitikPppoeFetchReadonly(string $url, int $timeout): array
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
            throw new RuntimeException('Sumber PPPoE tidak dapat dihubungi: ' . ($curlError ?: 'koneksi gagal'));
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
            throw new RuntimeException('Sumber PPPoE tidak dapat dihubungi dari server Billing.');
        }

        if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $httpCode = (int) $m[1];
        }
    }

    if ($httpCode !== 0 && ($httpCode < 200 || $httpCode >= 300)) {
        throw new RuntimeException('Sumber PPPoE merespons HTTP ' . $httpCode . '.');
    }

    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Respons PPPoE bukan JSON yang valid.');
    }

    if (isset($decoded['data']) && is_array($decoded['data'])) {
        return $decoded['data'];
    }

    return $decoded;
}

function analitikPppoeNormalize(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') return '';

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) $value = $converted;
    }

    $value = strtolower($value);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

/**
 * Membaca pasangan wilayah + nama dari username/lokasi PPPoE.
 * Contoh: SLM-Tulus -> salam + tulus.
 */
function analitikPppoeMatchPairs(array $row): array
{
    $pairs = [];
    $aliases = [
        'salam' => 'salam', 'slm' => 'salam',
        'baran' => 'baran', 'brn' => 'baran',
        'gunungmanuk' => 'gunungmanuk', 'gunung' => 'gunungmanuk',
        'gnm' => 'gunungmanuk', 'gm' => 'gunungmanuk',
        'ngasemayu' => 'ngasemayu', 'ngasem' => 'ngasemayu', 'nga' => 'ngasemayu',
        'trosari' => 'trosari', 'trs' => 'trosari',
        'waduk' => 'waduk', 'wdk' => 'waduk',
    ];

    $add = static function (string $raw, string $source) use (&$pairs, $aliases): void {
        $raw = trim($raw);
        if ($raw === '' || !str_contains($raw, '-')) return;

        [$rawRegion, $rawName] = explode('-', $raw, 2);
        $regionKey = analitikPppoeNormalize($rawRegion);
        $nameKey = analitikPppoeNormalize($rawName);
        if ($regionKey === '' || $nameKey === '') return;

        $regionKey = $aliases[$regionKey] ?? $regionKey;
        $key = $nameKey . '|' . $regionKey;
        if (!isset($pairs[$key])) {
            $pairs[$key] = [
                'name' => $nameKey,
                'address' => $regionKey,
                'source' => $source,
            ];
        }
    };

    foreach (['user', 'username'] as $field) {
        $add((string) ($row[$field] ?? ''), 'username');
    }
    foreach (['lokasi', 'name', 'nama'] as $field) {
        $add((string) ($row[$field] ?? ''), 'lokasi');
    }

    return array_values($pairs);
}

function analitikPppoeLoadBillingRows(mysqli $koneksi, array $scope): array
{
    $sql = "SELECT p.id,
                   p.id_pelanggan,
                   p.nama,
                   p.alamat,
                   COALESCE(p.paket, '') AS paket,
                   COALESCE(p.status_pelanggan, '') AS status_pelanggan,
                   COALESCE(d.nama_ktp, '') AS nama_ktp,
                   COALESCE(d.nik, '') AS nik,
                   COALESCE(d.foto_rumah, '') AS foto_rumah,
                   COALESCE(d.pppoe_user, '') AS pppoe_user
            FROM pelanggan_salam p
            LEFT JOIN pelanggan_detail_salam d ON d.pelanggan_id = p.id";

    $params = [];
    $types = '';
    if (empty($scope['is_all'])) {
        $sql .= ' WHERE ' . salamSqlNormalisasiAlamat('p.alamat') . ' = ?';
        $params[] = salamNormalisasiKunci($scope['value']);
        $types = 's';
    }

    $sql .= ' ORDER BY p.id ASC';
    $stmt = $koneksi->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Query pelanggan untuk peta gagal disiapkan: ' . $koneksi->error);
    }

    if ($types !== '') {
        $scopeKey = (string) $params[0];
        $stmt->bind_param($types, $scopeKey);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) ($row['id'] ?? 0);
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function analitikPppoeBuildBillingIndex(array $rows, array $validPppoeIds = []): array
{
    $byCustomerNameAddress = [];
    $byKtpNameAddress = [];
    $byManualPppoe = [];
    $manualBillingIds = [];

    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) continue;

        $manualId = trim((string) ($row['pppoe_user'] ?? ''));
        if ($manualId !== '' && isset($validPppoeIds[$manualId])) {
            $byManualPppoe[$manualId] = $row;
            $manualBillingIds[$id] = true;
        }

        $address = analitikPppoeNormalize($row['alamat'] ?? '');
        if ($address === '') continue;

        $customerName = analitikPppoeNormalize($row['nama'] ?? '');
        if ($customerName !== '' && !isset($manualBillingIds[$id])) {
            $byCustomerNameAddress[$customerName . '|' . $address][$id] = $row;
        }

        $ktpName = analitikPppoeNormalize($row['nama_ktp'] ?? '');
        if ($ktpName !== '' && !isset($manualBillingIds[$id])) {
            $byKtpNameAddress[$ktpName . '|' . $address][$id] = $row;
        }
    }

    return [
        'by_customer_name_address' => $byCustomerNameAddress,
        'by_ktp_name_address' => $byKtpNameAddress,
        'by_manual_pppoe' => $byManualPppoe,
    ];
}

function analitikPppoeFindBillingMatch(array $pppoeRow, array $index): ?array
{
    $pppoeId = trim((string) ($pppoeRow['id'] ?? ''));
    if ($pppoeId !== '' && isset($index['by_manual_pppoe'][$pppoeId])) {
        return $index['by_manual_pppoe'][$pppoeId];
    }
    $pairs = analitikPppoeMatchPairs($pppoeRow);
    if (!$pairs) return null;

    $candidates = [];
    foreach ($pairs as $pair) {
        $key = $pair['name'] . '|' . $pair['address'];
        if (!isset($index['by_customer_name_address'][$key])) continue;
        foreach ($index['by_customer_name_address'][$key] as $id => $row) {
            $candidates[(int) $id] = $row;
        }
    }
    if (count($candidates) === 1) return array_values($candidates)[0];
    if (count($candidates) > 1) return null;

    $candidates = [];
    foreach ($pairs as $pair) {
        $key = $pair['name'] . '|' . $pair['address'];
        if (!isset($index['by_ktp_name_address'][$key])) continue;
        foreach ($index['by_ktp_name_address'][$key] as $id => $row) {
            $candidates[(int) $id] = $row;
        }
    }

    return count($candidates) === 1 ? array_values($candidates)[0] : null;
}

/**
 * Ringkas status tagihan per pelanggan untuk rentang analitik terpilih.
 */
function analitikPppoeBillingSummaryByCustomer(array $records, string $periodeAkhir): array
{
    $summary = [];

    foreach ($records as $row) {
        $id = (int) ($row['pelanggan_id'] ?? 0);
        if ($id <= 0) continue;

        if (!isset($summary[$id])) {
            $summary[$id] = [
                'tagihan' => 0,
                'lunas' => 0,
                'belum' => 0,
                'total_tunggakan' => 0.0,
                'periode_tertua' => null,
                'lama_bulan' => 0,
            ];
        }

        $summary[$id]['tagihan']++;
        if (($row['status_bayar'] ?? '') === 'Lunas') {
            $summary[$id]['lunas']++;
            continue;
        }

        $summary[$id]['belum']++;
        $summary[$id]['total_tunggakan'] += (float) ($row['nominal_tagihan'] ?? 0);
        $periode = (string) ($row['periode'] ?? '');
        if ($periode !== '' && ($summary[$id]['periode_tertua'] === null || strcmp($periode, $summary[$id]['periode_tertua']) < 0)) {
            $summary[$id]['periode_tertua'] = $periode;
        }
    }

    foreach ($summary as &$item) {
        if (!empty($item['periode_tertua'])) {
            $item['lama_bulan'] = analitikMonthsInclusive((string) $item['periode_tertua'], $periodeAkhir);
        }
    }
    unset($item);

    return $summary;
}

function analitikPppoeMapStatus(?array $billing, ?array $billSummary): array
{
    if (!$billing) {
        return ['key' => 'unlinked', 'label' => 'Belum terhubung ke Billing'];
    }
    if (!$billSummary || (int) ($billSummary['tagihan'] ?? 0) <= 0) {
        return ['key' => 'nodata', 'label' => 'Tidak ada tagihan pada periode'];
    }
    if ((int) ($billSummary['belum'] ?? 0) <= 0) {
        return ['key' => 'paid', 'label' => 'Tidak ada tunggakan'];
    }
    if ((int) ($billSummary['lama_bulan'] ?? 0) >= 3) {
        return ['key' => 'critical', 'label' => 'Tunggakan 3+ bulan'];
    }
    return ['key' => 'warning', 'label' => 'Memiliki tunggakan'];
}


function analitikPppoeRegionKey(?array $billing, array $pppoeRow): string
{
    if ($billing && !empty($billing['alamat'])) {
        return salamNormalisasiKunci((string) $billing['alamat']);
    }

    $pairs = analitikPppoeMatchPairs($pppoeRow);
    foreach ($pairs as $pair) {
        if (!empty($pair['address'])) {
            return salamNormalisasiKunci((string) $pair['address']);
        }
    }

    return '';
}

function analitikPppoeRegionLabel(string $regionKey): string
{
    foreach (array_values(salamDaftarWilayahResmi()) as $region) {
        if (salamNormalisasiKunci($region) === $regionKey) {
            return $region;
        }
    }

    return strtoupper($regionKey);
}


function analitikGeoCross(array $o, array $a, array $b): float
{
    return (($a[0] - $o[0]) * ($b[1] - $o[1])) - (($a[1] - $o[1]) * ($b[0] - $o[0]));
}

/**
 * Convex hull sederhana untuk membentuk polygon dari sebaran titik PPPoE.
 * Input/output titik memakai format GeoJSON: [longitude, latitude].
 */
function analitikGeoConvexHull(array $points): array
{
    $unique = [];
    foreach ($points as $point) {
        if (!isset($point[0], $point[1])) continue;
        $lng = round((float) $point[0], 7);
        $lat = round((float) $point[1], 7);
        $unique[$lng . '|' . $lat] = [$lng, $lat];
    }
    $points = array_values($unique);
    if (count($points) <= 2) return $points;

    usort($points, static function (array $a, array $b): int {
        if ($a[0] === $b[0]) return $a[1] <=> $b[1];
        return $a[0] <=> $b[0];
    });

    $lower = [];
    foreach ($points as $point) {
        while (count($lower) >= 2 && analitikGeoCross($lower[count($lower)-2], $lower[count($lower)-1], $point) <= 0) {
            array_pop($lower);
        }
        $lower[] = $point;
    }

    $upper = [];
    for ($i = count($points) - 1; $i >= 0; $i--) {
        $point = $points[$i];
        while (count($upper) >= 2 && analitikGeoCross($upper[count($upper)-2], $upper[count($upper)-1], $point) <= 0) {
            array_pop($upper);
        }
        $upper[] = $point;
    }

    array_pop($lower);
    array_pop($upper);
    return array_merge($lower, $upper);
}

function analitikGeoMetersToLng(float $meters, float $lat): float
{
    $cos = cos(deg2rad($lat));
    if (abs($cos) < 0.1) $cos = 0.1;
    return $meters / (111320.0 * $cos);
}

function analitikGeoMetersToLat(float $meters): float
{
    return $meters / 110540.0;
}

function analitikGeoCircle(float $lng, float $lat, float $radiusMeters = 170.0, int $segments = 18): array
{
    $ring = [];
    for ($i = 0; $i < $segments; $i++) {
        $angle = (2 * M_PI * $i) / $segments;
        $dx = cos($angle) * $radiusMeters;
        $dy = sin($angle) * $radiusMeters;
        $ring[] = [
            round($lng + analitikGeoMetersToLng($dx, $lat), 7),
            round($lat + analitikGeoMetersToLat($dy), 7),
        ];
    }
    if ($ring) $ring[] = $ring[0];
    return $ring;
}

/**
 * Untuk dua titik, bentuk area kapsul memanjang agar tidak kembali menjadi kotak.
 */
function analitikGeoCapsule(array $a, array $b, float $radiusMeters = 150.0): array
{
    $midLat = ((float)$a[1] + (float)$b[1]) / 2;
    $midLng = ((float)$a[0] + (float)$b[0]) / 2;
    $cos = max(0.1, abs(cos(deg2rad($midLat))));

    $ax = ((float)$a[0] - $midLng) * 111320.0 * $cos;
    $ay = ((float)$a[1] - $midLat) * 110540.0;
    $bx = ((float)$b[0] - $midLng) * 111320.0 * $cos;
    $by = ((float)$b[1] - $midLat) * 110540.0;

    $angle = atan2($by - $ay, $bx - $ax);
    $ringMeters = [];
    $steps = 8;

    // Semicircle around B.
    for ($i = 0; $i <= $steps; $i++) {
        $theta = $angle - (M_PI / 2) + (M_PI * $i / $steps);
        $ringMeters[] = [$bx + cos($theta) * $radiusMeters, $by + sin($theta) * $radiusMeters];
    }
    // Semicircle around A.
    for ($i = 0; $i <= $steps; $i++) {
        $theta = $angle + (M_PI / 2) + (M_PI * $i / $steps);
        $ringMeters[] = [$ax + cos($theta) * $radiusMeters, $ay + sin($theta) * $radiusMeters];
    }

    $ring = [];
    foreach ($ringMeters as [$x, $y]) {
        $ring[] = [
            round($midLng + ($x / (111320.0 * $cos)), 7),
            round($midLat + ($y / 110540.0), 7),
        ];
    }
    if ($ring) $ring[] = $ring[0];
    return $ring;
}

/**
 * Membentuk polygon GeoJSON dari titik PPPoE suatu wilayah.
 * Ini adalah area perkiraan berbasis sebaran titik PPPoE, bukan batas administratif resmi.
 */
function analitikGeoRegionPolygon(array $coords): ?array
{
    $points = [];
    foreach ($coords as $coord) {
        if (!isset($coord[0], $coord[1])) continue;
        $lat = (float) $coord[0];
        $lng = (float) $coord[1];
        if (!is_finite($lat) || !is_finite($lng)) continue;
        $points[] = [$lng, $lat];
    }

    if (!$points) return null;
    if (count($points) === 1) {
        return analitikGeoCircle($points[0][0], $points[0][1]);
    }

    $hull = analitikGeoConvexHull($points);
    if (count($hull) === 1) {
        return analitikGeoCircle($hull[0][0], $hull[0][1]);
    }
    if (count($hull) === 2) {
        return analitikGeoCapsule($hull[0], $hull[1]);
    }

    $centerLng = array_sum(array_column($hull, 0)) / count($hull);
    $centerLat = array_sum(array_column($hull, 1)) / count($hull);
    $cos = max(0.1, abs(cos(deg2rad($centerLat))));
    $expanded = [];

    foreach ($hull as $point) {
        $x = ((float)$point[0] - $centerLng) * 111320.0 * $cos;
        $y = ((float)$point[1] - $centerLat) * 110540.0;
        $distance = sqrt(($x * $x) + ($y * $y));

        if ($distance < 0.001) {
            $expanded[] = $point;
            continue;
        }

        // Sedikit diperlebar supaya polygon tidak menempel tepat di titik pelanggan.
        $newDistance = ($distance * 1.08) + 90.0;
        $scale = $newDistance / $distance;
        $x *= $scale;
        $y *= $scale;

        $expanded[] = [
            round($centerLng + ($x / (111320.0 * $cos)), 7),
            round($centerLat + ($y / 110540.0), 7),
        ];
    }

    if ($expanded) $expanded[] = $expanded[0];
    return $expanded;
}

function analitikPppoeBuildRegionSummaries(array $points, array $billingRows, array $billingSummary, array $scope): array
{
    $regions = [];

    foreach (array_values(salamDaftarWilayahResmi()) as $region) {
        $key = salamNormalisasiKunci($region);
        $regions[$key] = [
            'key' => $region,
            'region_key' => $key,
            'label' => $region,
            'total_pelanggan' => 0,
            'pelanggan_bertagihan' => 0,
            'pelanggan_menunggak' => 0,
            'total_tagihan' => 0,
            'tagihan_lunas' => 0,
            'tagihan_belum' => 0,
            'total_tunggakan' => 0.0,
            'max_lama_bulan' => 0,
            'pppoe_titik' => 0,
            'online' => 0,
            'offline' => 0,
            'coords' => [],
        ];
    }

    foreach ($billingRows as $row) {
        $regionKey = salamNormalisasiKunci((string) ($row['alamat'] ?? ''));
        if ($regionKey === '' || !isset($regions[$regionKey])) continue;

        $regions[$regionKey]['total_pelanggan']++;

        $customerId = (int) ($row['id'] ?? 0);
        if ($customerId <= 0 || !isset($billingSummary[$customerId])) continue;

        $bill = $billingSummary[$customerId];
        if ((int) ($bill['tagihan'] ?? 0) <= 0) continue;

        $regions[$regionKey]['pelanggan_bertagihan']++;
        $regions[$regionKey]['total_tagihan'] += (int) ($bill['tagihan'] ?? 0);
        $regions[$regionKey]['tagihan_lunas'] += (int) ($bill['lunas'] ?? 0);
        $regions[$regionKey]['tagihan_belum'] += (int) ($bill['belum'] ?? 0);
        $regions[$regionKey]['total_tunggakan'] += (float) ($bill['total_tunggakan'] ?? 0);

        if ((int) ($bill['belum'] ?? 0) > 0) {
            $regions[$regionKey]['pelanggan_menunggak']++;
        }
        $regions[$regionKey]['max_lama_bulan'] = max(
            $regions[$regionKey]['max_lama_bulan'],
            (int) ($bill['lama_bulan'] ?? 0)
        );
    }

    foreach ($points as $point) {
        $regionKey = (string) ($point['region_key'] ?? '');
        if ($regionKey === '' || !isset($regions[$regionKey])) continue;

        $lat = (float) ($point['latitude'] ?? 0);
        $lng = (float) ($point['longitude'] ?? 0);
        if (!is_finite($lat) || !is_finite($lng)) continue;

        $regions[$regionKey]['coords'][] = [$lat, $lng];
        $regions[$regionKey]['pppoe_titik']++;

        $networkStatus = strtoupper((string) ($point['network_status'] ?? ''));
        if ($networkStatus === 'ONLINE') $regions[$regionKey]['online']++;
        elseif ($networkStatus === 'OFFLINE') $regions[$regionKey]['offline']++;
    }

    $results = [];
    $features = [];
    $counts = [
        'total' => 0,
        'paid' => 0,
        'warning' => 0,
        'critical' => 0,
        'nodata' => 0,
        'unlinked' => 0,
        'online' => 0,
        'offline' => 0,
    ];

    foreach ($regions as $regionKey => $region) {
        if (!$scope['is_all'] && $regionKey !== salamNormalisasiKunci($scope['value'])) {
            continue;
        }

        $polygon = analitikGeoRegionPolygon($region['coords']);
        if (!$polygon) continue;

        if ($region['pelanggan_bertagihan'] <= 0) {
            $statusKey = 'nodata';
            $statusLabel = 'Belum ada tagihan pada periode';
        } elseif ($region['tagihan_belum'] <= 0 || $region['total_tunggakan'] <= 0) {
            $statusKey = 'paid';
            $statusLabel = 'Tidak ada tunggakan';
        } elseif ($region['max_lama_bulan'] >= 3) {
            $statusKey = 'critical';
            $statusLabel = 'Tunggakan tinggi';
        } else {
            $statusKey = 'warning';
            $statusLabel = 'Ada tunggakan';
        }

        $centerLat = array_sum(array_column($region['coords'], 0)) / max(1, count($region['coords']));
        $centerLng = array_sum(array_column($region['coords'], 1)) / max(1, count($region['coords']));
        $center = [round($centerLat, 6), round($centerLng, 6)];

        $properties = [
            'key' => $region['key'],
            'region_key' => $regionKey,
            'label' => $region['label'],
            'status_key' => $statusKey,
            'status_label' => $statusLabel,
            'total_pelanggan' => (int) $region['total_pelanggan'],
            'pelanggan_bertagihan' => (int) $region['pelanggan_bertagihan'],
            'pelanggan_menunggak' => (int) $region['pelanggan_menunggak'],
            'total_tagihan' => (int) $region['total_tagihan'],
            'tagihan_lunas' => (int) $region['tagihan_lunas'],
            'tagihan_belum' => (int) $region['tagihan_belum'],
            'total_tunggakan' => (float) $region['total_tunggakan'],
            'max_lama_bulan' => (int) $region['max_lama_bulan'],
            'pppoe_titik' => (int) $region['pppoe_titik'],
            'online' => (int) $region['online'],
            'offline' => (int) $region['offline'],
            'center' => $center,
            'detail_type' => $scope['is_all'] ? 'outstanding' : 'unpaid',
            'detail_key' => $scope['is_all'] ? $region['key'] : 'unpaid',
        ];

        $features[] = [
            'type' => 'Feature',
            'id' => $region['key'],
            'properties' => $properties,
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [$polygon],
            ],
        ];

        $results[] = $properties;
        $counts['total']++;
        if (isset($counts[$statusKey])) $counts[$statusKey]++;
        $counts['online'] += (int) $region['online'];
        $counts['offline'] += (int) $region['offline'];
    }

    return [
        'regions' => $results,
        'geojson' => [
            'type' => 'FeatureCollection',
            'features' => $features,
        ],
        'counts' => $counts,
    ];
}

function analitikBuildPppoeMap(mysqli $koneksi, string $awal, string $akhir, array $scope): array
{
    $pppoes = analitikPppoeFetchReadonly(PPPOE_MONITOR_API_URL, PPPOE_MONITOR_TIMEOUT_SECONDS);
    $billingRows = analitikPppoeLoadBillingRows($koneksi, $scope);
    $validPppoeIds = [];
    foreach ($pppoes as $pppoeRow) {
        if (!is_array($pppoeRow)) continue;
        $pppoeId = trim((string) ($pppoeRow['id'] ?? ''));
        if ($pppoeId !== '') $validPppoeIds[$pppoeId] = true;
    }
    $billingIndex = analitikPppoeBuildBillingIndex($billingRows, $validPppoeIds);
    $records = analitikLoadRecords($koneksi, $awal, $akhir, $scope);
    $billingSummary = analitikPppoeBillingSummaryByCustomer($records, $akhir);

    $points = [];

    foreach ($pppoes as $row) {
        if (!is_array($row)) continue;
        if (array_key_exists('icon', $row) && (int) $row['icon'] !== 119) continue;

        $lat = $row['latitude'] ?? $row['lat'] ?? null;
        $lng = $row['longitude'] ?? $row['lng'] ?? $row['lon'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng)) continue;

        $billing = analitikPppoeFindBillingMatch($row, $billingIndex);
        $regionKey = analitikPppoeRegionKey($billing, $row);
        if ($regionKey === '') continue;

        if (!$scope['is_all'] && $regionKey !== salamNormalisasiKunci($scope['value'])) {
            continue;
        }

        $customerId = $billing ? (int) ($billing['id'] ?? 0) : 0;
        $bill = $customerId > 0 ? ($billingSummary[$customerId] ?? null) : null;
        $mapStatus = analitikPppoeMapStatus($billing, $bill);
        $networkStatus = strtoupper(trim((string) ($row['status'] ?? 'UNKNOWN')));

        $point = [
            'pppoe_id' => trim((string) ($row['id'] ?? '')),
            'user' => trim((string) ($row['user'] ?? $row['username'] ?? '')),
            'lokasi' => trim((string) ($row['lokasi'] ?? $row['name'] ?? '')),
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
            'ip' => trim((string) ($row['ip'] ?? '-')),
            'network_status' => $networkStatus,
            'map_status' => $mapStatus['key'],
            'map_status_label' => $mapStatus['label'],
            'region_key' => $regionKey,
            'region_label' => analitikPppoeRegionLabel($regionKey),
            'billing' => null,
        ];

        if ($billing) {
            $point['billing'] = [
                'pelanggan_id' => $customerId,
                'id_pelanggan' => (string) ($billing['id_pelanggan'] ?? ''),
                'nama' => (string) ($billing['nama'] ?? ''),
                'nama_ktp' => (string) ($billing['nama_ktp'] ?? ''),
                'wilayah' => salamNamaWilayahTampilan((string) ($billing['alamat'] ?? '')),
                'paket' => (string) ($billing['paket'] ?? ''),
                'status_pelanggan' => (string) ($billing['status_pelanggan'] ?? ''),
                'tagihan' => (int) ($bill['tagihan'] ?? 0),
                'lunas' => (int) ($bill['lunas'] ?? 0),
                'belum' => (int) ($bill['belum'] ?? 0),
                'total_tunggakan' => (float) ($bill['total_tunggakan'] ?? 0),
                'periode_tertua' => (string) ($bill['periode_tertua'] ?? ''),
                'periode_tertua_label' => !empty($bill['periode_tertua']) ? analitikPeriodLabel((string) $bill['periode_tertua']) : '-',
                'lama_bulan' => (int) ($bill['lama_bulan'] ?? 0),
            ];
        }

        $points[] = $point;
    }

    $regionSummary = analitikPppoeBuildRegionSummaries($points, $billingRows, $billingSummary, $scope);

    return [
        'points' => $points,
        'regions' => $regionSummary['regions'],
        'geojson' => $regionSummary['geojson'],
        'counts' => $regionSummary['counts'],
        'read_only' => true,
        'source' => PPPOE_MONITOR_BASE_URL,
    ];
}
