<?php
/**
 * Mapping PPPoE hanya disimpan di Billing.
 * PPPoE asli tidak diubah.
 */
function salamPppoeMappingTableReady(mysqli $koneksi): bool
{
    $result = $koneksi->query("SHOW TABLES LIKE 'pelanggan_pppoe_mapping'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function salamPppoeNormalize(?string $value): string
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

function salamPppoeNameKeys(array $row): array
{
    $keys = [];

    foreach (['lokasi', 'user'] as $field) {
        $raw = trim((string) ($row[$field] ?? ''));
        if ($raw === '') continue;

        $key = salamPppoeNormalize($raw);
        if ($key !== '') $keys[$key] = true;

        if (str_contains($raw, '-')) {
            $parts = explode('-', $raw, 2);
            $suffix = salamPppoeNormalize($parts[1] ?? '');
            if ($suffix !== '') $keys[$suffix] = true;
        }

        foreach ([
            'slm','salam','brn','baran','gnm','gunungmanuk',
            'nga','ngasemayu','trs','trosari','wdk','waduk'
        ] as $prefix) {
            if (str_starts_with($key, $prefix) && strlen($key) > strlen($prefix)) {
                $without = substr($key, strlen($prefix));
                if ($without !== '') $keys[$without] = true;
            }
        }
    }

    return array_keys($keys);
}

function salamLoadPppoeMappings(mysqli $koneksi): array
{
    if (!salamPppoeMappingTableReady($koneksi)) return [];

    $result = $koneksi->query(
        'SELECT pelanggan_id, pppoe_id, match_method FROM pelanggan_pppoe_mapping'
    );

    $map = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $pppoeId = trim((string) ($row['pppoe_id'] ?? ''));
            if ($pppoeId === '') continue;

            $map[$pppoeId] = [
                'pelanggan_id' => (int) $row['pelanggan_id'],
                'match_method' => (string) ($row['match_method'] ?? ''),
            ];
        }
        $result->free();
    }

    return $map;
}

function salamSavePppoeMapping(
    mysqli $koneksi,
    int $pelangganId,
    string $pppoeId,
    string $matchMethod
): bool {
    if (
        $pelangganId <= 0
        || trim($pppoeId) === ''
        || !salamPppoeMappingTableReady($koneksi)
    ) return false;

    $stmt = $koneksi->prepare(
        'INSERT IGNORE INTO pelanggan_pppoe_mapping
            (pelanggan_id, pppoe_id, match_method)
         VALUES (?, ?, ?)'
    );

    if (!$stmt) return false;

    $stmt->bind_param('iss', $pelangganId, $pppoeId, $matchMethod);
    $ok = $stmt->execute();
    $inserted = $stmt->affected_rows > 0;
    $stmt->close();

    return $ok && $inserted;
}

function salamBuildBillingMatchIndex(array $billingRows): array
{
    $byInternalId = [];
    $byCustomerId = [];
    $byName = [];

    foreach ($billingRows as $row) {
        $internalId = (int) ($row['id'] ?? 0);
        if ($internalId <= 0) continue;

        $byInternalId[$internalId] = $row;

        $customerIdKey = salamPppoeNormalize($row['id_pelanggan'] ?? '');
        if ($customerIdKey !== '') {
            $byCustomerId[$customerIdKey][$internalId] = true;
        }

        foreach ([$row['nama'] ?? '', $row['nama_ktp'] ?? ''] as $name) {
            $nameKey = salamPppoeNormalize($name);
            if ($nameKey !== '') {
                $byName[$nameKey][$internalId] = true;
            }
        }
    }

    return [
        'by_internal_id' => $byInternalId,
        'by_customer_id' => $byCustomerId,
        'by_name' => $byName,
    ];
}

function salamFindAutomaticBillingMatch(array $pppoeRow, array $index): ?array
{
    $pppoeId = trim((string) ($pppoeRow['id'] ?? ''));
    $idKey = salamPppoeNormalize($pppoeId);

    // Prioritas pertama: ID Pelanggan Billing == ID PPPoE.
    if ($idKey !== '' && isset($index['by_customer_id'][$idKey])) {
        $ids = array_keys($index['by_customer_id'][$idKey]);

        if (count($ids) === 1) {
            $id = (int) $ids[0];
            return [
                'pelanggan_id' => $id,
                'method' => 'auto_id',
                'row' => $index['by_internal_id'][$id] ?? null,
            ];
        }
    }

    // Prioritas kedua: Nama/Nama KTP cocok pasti dan hanya satu pelanggan.
    $matchedIds = [];

    foreach (salamPppoeNameKeys($pppoeRow) as $nameKey) {
        if (!isset($index['by_name'][$nameKey])) continue;

        foreach (array_keys($index['by_name'][$nameKey]) as $id) {
            $matchedIds[(int) $id] = true;
        }
    }

    if (count($matchedIds) === 1) {
        $id = (int) array_key_first($matchedIds);

        return [
            'pelanggan_id' => $id,
            'method' => 'auto_name',
            'row' => $index['by_internal_id'][$id] ?? null,
        ];
    }

    return null;
}
?>
