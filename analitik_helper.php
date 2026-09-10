<?php
/**
 * Helper Dashboard Analitik Billing Multiwilayah.
 * Sumber data hanya membaca pelanggan_salam + tagihan_salam.
 * Tidak mengubah database.
 */

require_once __DIR__ . '/helpers_salam.php';

date_default_timezone_set('Asia/Jakarta');

function analitikBindParams(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') return;
    $refs = [];
    foreach ($params as $key => &$value) {
        $refs[$key] = &$value;
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function analitikIsAdminWilayah(): bool
{
    return !salamIsSuperAdmin()
        && !salamIsAdminSemuaWilayah()
        && strtolower((string) ($_SESSION['role'] ?? '')) === 'admin';
}

function analitikRequireAccess(): void
{
    salamRequireLogin();
    if (!salamIsSuperAdmin() && !salamIsAdminSemuaWilayah() && !analitikIsAdminWilayah()) {
        http_response_code(403);
        exit('Dashboard Analitik hanya dapat diakses oleh Super Admin, Admin Semua Wilayah, dan Admin Wilayah.');
    }
}

function analitikResolveWilayah(?string $requested): array
{
    analitikRequireAccess();

    if (!salamCanAccessAllWilayah()) {
        $wilayah = salamNormalisasiAlamatInput($_SESSION['wilayah'] ?? '');
        return [
            'value' => $wilayah,
            'label' => salamNamaWilayahTampilan($wilayah),
            'is_all' => false,
            'locked' => true,
        ];
    }

    $requested = trim((string) $requested);
    if ($requested === '' || salamIsWilayahSemua($requested) || strtolower($requested) === 'all') {
        return [
            'value' => 'all',
            'label' => 'SEMUA WILAYAH',
            'is_all' => true,
            'locked' => false,
        ];
    }

    $wilayah = salamWilayahResmiDariInput($requested, false);
    if ($wilayah === null) {
        return [
            'value' => 'all',
            'label' => 'SEMUA WILAYAH',
            'is_all' => true,
            'locked' => false,
        ];
    }

    return [
        'value' => $wilayah,
        'label' => $wilayah,
        'is_all' => false,
        'locked' => false,
    ];
}

function analitikResolvePeriodRange(array $source): array
{
    $now = new DateTimeImmutable('first day of this month');
    $defaultEnd = $now->format('Y-m');
    $defaultStart = $now->modify('-5 months')->format('Y-m');

    $filterTipe = strtolower(trim((string) ($source['filter_tipe'] ?? 'bulan')));
    if (!in_array($filterTipe, ['bulan', 'tanggal'], true)) {
        $filterTipe = 'bulan';
    }

    $validDate = static function (string $value): bool {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    };

    $defaultDateStart = $defaultStart . '-01';
    $defaultDateEnd = $now->modify('last day of this month')->format('Y-m-d');
    $tanggalAwal = trim((string) ($source['tanggal_awal'] ?? $defaultDateStart));
    $tanggalAkhir = trim((string) ($source['tanggal_akhir'] ?? $defaultDateEnd));
    if (!$validDate($tanggalAwal)) $tanggalAwal = $defaultDateStart;
    if (!$validDate($tanggalAkhir)) $tanggalAkhir = $defaultDateEnd;

    if (strcmp($tanggalAwal, $tanggalAkhir) > 0) {
        [$tanggalAwal, $tanggalAkhir] = [$tanggalAkhir, $tanggalAwal];
    }

    $bulanAwal = trim((string) ($source['bulan_awal'] ?? substr($defaultStart, 5, 2)));
    $tahunAwal = trim((string) ($source['tahun_awal'] ?? substr($defaultStart, 0, 4)));
    $bulanAkhir = trim((string) ($source['bulan_akhir'] ?? substr($defaultEnd, 5, 2)));
    $tahunAkhir = trim((string) ($source['tahun_akhir'] ?? substr($defaultEnd, 0, 4)));

    $validMonth = static fn(string $m): bool => (bool) preg_match('/^(0?[1-9]|1[0-2])$/', $m);
    $validYear = static fn(string $y): bool => (bool) preg_match('/^\d{4}$/', $y);

    if ($filterTipe === 'tanggal') {
        // Data tagihan disimpan per periode bulanan. Rentang tanggal diterjemahkan
        // menjadi seluruh bulan yang tersentuh agar tagihan lunas dan belum lunas
        // tetap dihitung dengan dasar yang sama pada semua panel.
        $awal = substr($tanggalAwal, 0, 7);
        $akhir = substr($tanggalAkhir, 0, 7);
    } else {
        $awal = ($validMonth($bulanAwal) && $validYear($tahunAwal))
            ? sprintf('%04d-%02d', (int) $tahunAwal, (int) $bulanAwal)
            : $defaultStart;
        $akhir = ($validMonth($bulanAkhir) && $validYear($tahunAkhir))
            ? sprintf('%04d-%02d', (int) $tahunAkhir, (int) $bulanAkhir)
            : $defaultEnd;
    }

    if (strcmp($awal, $akhir) > 0) {
        [$awal, $akhir] = [$akhir, $awal];
    }

    if ($filterTipe === 'bulan') {
        $tanggalAwal = $awal . '-01';
        $akhirDate = DateTimeImmutable::createFromFormat('!Y-m-d', $akhir . '-01');
        $tanggalAkhir = $akhirDate
            ? $akhirDate->modify('last day of this month')->format('Y-m-d')
            : $defaultDateEnd;
    }

    return [
        'filter_tipe' => $filterTipe,
        'awal' => $awal,
        'akhir' => $akhir,
        'tanggal_awal' => $tanggalAwal,
        'tanggal_akhir' => $tanggalAkhir,
        'bulan_awal' => substr($awal, 5, 2),
        'tahun_awal' => (int) substr($awal, 0, 4),
        'bulan_akhir' => substr($akhir, 5, 2),
        'tahun_akhir' => (int) substr($akhir, 0, 4),
    ];
}

function analitikDateLabel(string $ymd): string
{
    static $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    if (!$date || $date->format('Y-m-d') !== $ymd) return $ymd;
    return $date->format('j') . ' ' . ($bulan[(int) $date->format('n')] ?? $date->format('m')) . ' ' . $date->format('Y');
}

function analitikPeriodeSequence(string $awal, string $akhir): array
{
    $start = DateTimeImmutable::createFromFormat('!Y-m', $awal);
    $end = DateTimeImmutable::createFromFormat('!Y-m', $akhir);
    if (!$start || !$end) return [];

    $result = [];
    for ($d = $start; $d <= $end; $d = $d->modify('+1 month')) {
        $result[] = $d->format('Y-m');
        if (count($result) > 120) break;
    }
    return $result;
}

function analitikPeriodLabel(string $ym, bool $short = false): string
{
    static $bulan = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
    ];
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) return $ym;
    $name = $bulan[$m[2]] ?? $m[2];
    if ($short) $name = substr($name, 0, 3);
    return $name . ' ' . $m[1];
}

function analitikMonthsInclusive(string $from, string $to): int
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $from, $a) || !preg_match('/^(\d{4})-(\d{2})$/', $to, $b)) {
        return 0;
    }
    $diff = (((int) $b[1] - (int) $a[1]) * 12) + ((int) $b[2] - (int) $a[2]);
    return max(0, $diff + 1);
}

function analitikPreviousPeriod(string $ym): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m', $ym);
    return $date ? $date->modify('-1 month')->format('Y-m') : '';
}

function analitikLoadRecords(mysqli $koneksi, string $awal, string $akhir, array $scope): array
{
    $union = "
        SELECT
            p.id AS pelanggan_id,
            p.id_pelanggan,
            COALESCE(p.kode_pelanggan, '') AS kode_pelanggan,
            p.nama,
            COALESCE(p.nomor_pelanggan, '') AS nomor_pelanggan,
            p.alamat AS wilayah,
            p.paket,
            p.status_pelanggan,
            DATE_FORMAT(p.waktu, '%Y-%m') AS periode,
            p.waktu AS periode_tanggal,
            p.status_bayar,
            CASE
                WHEN p.status_bayar = 'Lunas' THEN COALESCE(NULLIF(p.nominal_dibayar, 0), NULLIF(p.tarif_langganan, 0), 0)
                ELSE COALESCE(NULLIF(p.tagihan, 0), NULLIF(p.tarif_langganan, 0), 0)
            END AS nominal_tagihan,
            COALESCE(p.nominal_dibayar, 0) AS nominal_dibayar,
            p.tanggal_bayar,
            p.langganan_selesai AS tanggal_jatuh_tempo,
            'berjalan' AS sumber_data
        FROM pelanggan_salam p
        WHERE DATE_FORMAT(p.waktu, '%Y-%m') BETWEEN ? AND ?

        UNION ALL

        SELECT
            t.pelanggan_id,
            COALESCE(NULLIF(t.id_pelanggan_snapshot, ''), p.id_pelanggan) AS id_pelanggan,
            COALESCE(p.kode_pelanggan, '') AS kode_pelanggan,
            COALESCE(NULLIF(t.nama_snapshot, ''), p.nama, '-') AS nama,
            COALESCE(p.nomor_pelanggan, '') AS nomor_pelanggan,
            COALESCE(NULLIF(t.alamat_snapshot, ''), p.alamat, '-') AS wilayah,
            COALESCE(NULLIF(t.paket_snapshot, ''), p.paket, '-') AS paket,
            COALESCE(p.status_pelanggan, 'Aktif') AS status_pelanggan,
            DATE_FORMAT(t.periode, '%Y-%m') AS periode,
            t.periode AS periode_tanggal,
            t.status_bayar,
            COALESCE(t.nominal_tagihan, 0) AS nominal_tagihan,
            COALESCE(t.nominal_dibayar, 0) AS nominal_dibayar,
            t.tanggal_bayar,
            t.tanggal_jatuh_tempo,
            'riwayat' AS sumber_data
        FROM tagihan_salam t
        LEFT JOIN pelanggan_salam p ON p.id = t.pelanggan_id
        WHERE DATE_FORMAT(t.periode, '%Y-%m') BETWEEN ? AND ?
          AND NOT EXISTS (
              SELECT 1
              FROM pelanggan_salam p_berjalan
              WHERE p_berjalan.id = t.pelanggan_id
                AND DATE_FORMAT(p_berjalan.waktu, '%Y-%m') = DATE_FORMAT(t.periode, '%Y-%m')
          )
    ";

    $types = 'ssss';
    $params = [$awal, $akhir, $awal, $akhir];
    $conditions = ['1=1'];

    if (empty($scope['is_all'])) {
        $conditions[] = salamSqlNormalisasiAlamat('wilayah') . ' = ?';
        $types .= 's';
        $params[] = salamNormalisasiKunci($scope['value']);
    }

    $sql = "SELECT * FROM ({$union}) x WHERE " . implode(' AND ', $conditions)
        . " ORDER BY periode ASC, wilayah ASC, nama ASC, pelanggan_id ASC";

    $stmt = $koneksi->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Query analitik gagal disiapkan: ' . $koneksi->error);
    }
    analitikBindParams($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['pelanggan_id'] = (int) ($row['pelanggan_id'] ?? 0);
        $row['nominal_tagihan'] = (float) ($row['nominal_tagihan'] ?? 0);
        $row['nominal_dibayar'] = (float) ($row['nominal_dibayar'] ?? 0);
        $row['wilayah'] = salamNamaWilayahTampilan($row['wilayah'] ?? '');
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function analitikClassifyTimeliness(array $row): string
{
    if (($row['status_bayar'] ?? '') !== 'Lunas') return 'unpaid';

    $paid = trim((string) ($row['tanggal_bayar'] ?? ''));
    $due = trim((string) ($row['tanggal_jatuh_tempo'] ?? ''));
    if ($paid === '' || $due === '') return 'unknown';

    $paidTs = strtotime($paid);
    $dueTs = strtotime($due);
    if ($paidTs === false || $dueTs === false) return 'unknown';
    if ($paidTs < $dueTs) return 'before';
    if (date('Y-m-d', $paidTs) === date('Y-m-d', $dueTs)) return 'on';
    return 'late';
}

function analitikBuildCustomerUnpaid(array $records, string $periodeAkhir): array
{
    $customers = [];
    foreach ($records as $row) {
        if (($row['status_bayar'] ?? '') !== 'Belum Lunas') continue;
        $id = (int) ($row['pelanggan_id'] ?? 0);
        if ($id <= 0) continue;
        if (!isset($customers[$id])) {
            $customers[$id] = [
                'pelanggan_id' => $id,
                'id_pelanggan' => $row['id_pelanggan'] ?? '-',
                'nama' => $row['nama'] ?? '-',
                'wilayah' => $row['wilayah'] ?? '-',
                'nomor_pelanggan' => $row['nomor_pelanggan'] ?? '',
                'paket' => $row['paket'] ?? '-',
                'periode_tertua' => $row['periode'] ?? '',
                'periode_terbaru' => $row['periode'] ?? '',
                'jumlah_periode' => 0,
                'total_tunggakan' => 0.0,
            ];
        }
        $customers[$id]['jumlah_periode']++;
        $customers[$id]['total_tunggakan'] += (float) ($row['nominal_tagihan'] ?? 0);
        $p = (string) ($row['periode'] ?? '');
        if ($p !== '' && ($customers[$id]['periode_tertua'] === '' || strcmp($p, $customers[$id]['periode_tertua']) < 0)) {
            $customers[$id]['periode_tertua'] = $p;
        }
        if ($p !== '' && strcmp($p, $customers[$id]['periode_terbaru']) > 0) {
            $customers[$id]['periode_terbaru'] = $p;
        }
    }

    foreach ($customers as &$item) {
        $item['lama_bulan'] = max(1, analitikMonthsInclusive($item['periode_tertua'], $periodeAkhir));
    }
    unset($item);

    uasort($customers, static function (array $a, array $b): int {
        if ($a['lama_bulan'] !== $b['lama_bulan']) return $b['lama_bulan'] <=> $a['lama_bulan'];
        if ($a['total_tunggakan'] !== $b['total_tunggakan']) return $b['total_tunggakan'] <=> $a['total_tunggakan'];
        return strcasecmp((string) $a['nama'], (string) $b['nama']);
    });
    return $customers;
}

function analitikBuildTop5(array $records, string $awal, string $akhir): array
{
    // Ranking ini sengaja dibuat sederhana untuk pengguna non-teknis:
    // siapa yang paling rajin membayar pada periode filter.
    // Penilaian tidak lagi bergantung pada tanggal bayar vs jatuh tempo.
    $byCustomer = [];
    foreach ($records as $row) {
        $id = (int) ($row['pelanggan_id'] ?? 0);
        $periode = (string) ($row['periode'] ?? '');
        if ($id <= 0 || $periode === '') continue;

        $byCustomer[$id]['meta'] = [
            'pelanggan_id' => $id,
            'id_pelanggan' => $row['id_pelanggan'] ?? '-',
            'nama' => $row['nama'] ?? '-',
            'wilayah' => $row['wilayah'] ?? '-',
        ];
        // Satu pelanggan dihitung satu kali untuk setiap periode/bulan.
        $byCustomer[$id]['rows'][$periode] = $row;
    }

    $periodeSequence = analitikPeriodeSequence($awal, $akhir);
    $ranking = [];

    foreach ($byCustomer as $customer) {
        $rows = $customer['rows'] ?? [];
        if (!$rows) continue;
        ksort($rows);

        $totalPeriode = count($rows);
        $lunas = 0;
        $periodeLunasTerakhir = '';

        foreach ($rows as $periode => $row) {
            if (($row['status_bayar'] ?? '') === 'Lunas') {
                $lunas++;
                if ($periodeLunasTerakhir === '' || strcmp($periode, $periodeLunasTerakhir) > 0) {
                    $periodeLunasTerakhir = $periode;
                }
            }
        }

        // Jika belum pernah Lunas pada rentang ini, pelanggan tidak masuk ranking.
        if ($lunas <= 0 || $totalPeriode <= 0) continue;

        // Streak hanya menjadi pemecah seri, bukan syarat agar pelanggan bisa tampil.
        $streakBerjalan = 0;
        $streakTerpanjang = 0;
        foreach ($periodeSequence as $periode) {
            if (isset($rows[$periode]) && (($rows[$periode]['status_bayar'] ?? '') === 'Lunas')) {
                $streakBerjalan++;
                $streakTerpanjang = max($streakTerpanjang, $streakBerjalan);
            } else {
                $streakBerjalan = 0;
            }
        }

        $persen = round(($lunas / $totalPeriode) * 100, 1);
        $ranking[] = array_merge($customer['meta'], [
            'lunas' => $lunas,
            'total_periode' => $totalPeriode,
            'persen_lunas' => $persen,
            'streak' => $streakTerpanjang,
            'periode_lunas_terakhir' => $periodeLunasTerakhir,
        ]);
    }

    usort($ranking, static function (array $a, array $b): int {
        $cmpPersen = ((float) ($b['persen_lunas'] ?? 0)) <=> ((float) ($a['persen_lunas'] ?? 0));
        if ($cmpPersen !== 0) return $cmpPersen;

        $cmpLunas = ((int) ($b['lunas'] ?? 0)) <=> ((int) ($a['lunas'] ?? 0));
        if ($cmpLunas !== 0) return $cmpLunas;

        $cmpStreak = ((int) ($b['streak'] ?? 0)) <=> ((int) ($a['streak'] ?? 0));
        if ($cmpStreak !== 0) return $cmpStreak;

        return strcasecmp((string) ($a['nama'] ?? ''), (string) ($b['nama'] ?? ''));
    });

    return array_slice($ranking, 0, 5);
}

function analitikBuildRegionFinancialComparison(array $records): array
{
    $regions = [];
    foreach (array_values(salamDaftarWilayahResmi()) as $region) {
        $key = salamNormalisasiKunci($region);
        $regions[$key] = [
            'wilayah' => $region,
            'pendapatan' => 0.0,
            'tunggakan' => 0.0,
            'tagihan_lunas' => 0,
            'tagihan_belum' => 0,
            'total_tagihan' => 0,
            'persen_lunas' => 0.0,
            'total_kewajiban' => 0.0,
            'persen_pembayaran_terkumpul' => 0.0,
        ];
    }

    foreach ($records as $row) {
        $key = salamNormalisasiKunci((string) ($row['wilayah'] ?? ''));
        if (!isset($regions[$key])) continue;

        $regions[$key]['total_tagihan']++;
        if (($row['status_bayar'] ?? '') === 'Lunas') {
            $regions[$key]['tagihan_lunas']++;
            $regions[$key]['pendapatan'] += (float) ($row['nominal_dibayar'] ?? 0);
        } else {
            $regions[$key]['tagihan_belum']++;
            $regions[$key]['tunggakan'] += (float) ($row['nominal_tagihan'] ?? 0);
        }
    }

    foreach ($regions as &$region) {
        $total = (int) ($region['total_tagihan'] ?? 0);
        $region['persen_lunas'] = $total > 0
            ? round(((int) $region['tagihan_lunas'] / $total) * 100, 1)
            : 0.0;

        // Kondisi pembayaran: berapa persen nominal kewajiban pada periode terpilih
        // yang sudah benar-benar masuk sebagai pembayaran. Nilai ini berbeda dari
        // grafik Total Tunggakan karena yang ditampilkan adalah tingkat keberhasilan
        // pembayaran (0-100%), bukan nominal tunggakan per wilayah.
        $pendapatan = (float) ($region['pendapatan'] ?? 0);
        $tunggakan = (float) ($region['tunggakan'] ?? 0);
        $totalKewajiban = $pendapatan + $tunggakan;
        $region['total_kewajiban'] = $totalKewajiban;
        $region['persen_pembayaran_terkumpul'] = $totalKewajiban > 0
            ? round(($pendapatan / $totalKewajiban) * 100, 1)
            : 0.0;
    }
    unset($region);

    return array_values($regions);
}

function analitikBuild(mysqli $koneksi, string $awal, string $akhir, array $scope): array
{
    $records = analitikLoadRecords($koneksi, $awal, $akhir, $scope);
    $periods = analitikPeriodeSequence($awal, $akhir);

    $customerSet = [];
    $paidCount = 0;
    $unpaidCount = 0;
    $totalPaid = 0.0;
    $totalOutstanding = 0.0;

    $trend = [];
    foreach ($periods as $p) {
        $trend[$p] = [
            'periode' => $p,
            'label' => analitikPeriodLabel($p, true),
            'total' => 0,
            'lunas' => 0,
            'belum' => 0,
            'persen' => 0.0,
            'dibayar' => 0.0,
            'tunggakan' => 0.0,
        ];
    }

    $regionCustomers = [];
    $regionUnpaidCustomers = [];
    $regionOutstanding = [];
    $periodOutstanding = [];
    $timeliness = ['before' => 0, 'on' => 0, 'late' => 0, 'unpaid' => 0, 'unknown' => 0];

    foreach ($records as $row) {
        $id = (int) ($row['pelanggan_id'] ?? 0);
        $periode = (string) ($row['periode'] ?? '');
        $region = (string) ($row['wilayah'] ?? '-');
        if ($id > 0) {
            $customerSet[$id] = true;
            $regionCustomers[$region][$id] = true;
        }

        if (isset($trend[$periode])) {
            $trend[$periode]['total']++;
        }

        if (($row['status_bayar'] ?? '') === 'Lunas') {
            $paidCount++;
            $amount = (float) ($row['nominal_dibayar'] ?? 0);
            $totalPaid += $amount;
            if (isset($trend[$periode])) {
                $trend[$periode]['lunas']++;
                $trend[$periode]['dibayar'] += $amount;
            }
        } else {
            $unpaidCount++;
            $amount = (float) ($row['nominal_tagihan'] ?? 0);
            $totalOutstanding += $amount;
            if ($id > 0) $regionUnpaidCustomers[$region][$id] = true;
            $regionOutstanding[$region] = ($regionOutstanding[$region] ?? 0) + $amount;
            $periodOutstanding[$periode] = ($periodOutstanding[$periode] ?? 0) + $amount;
            if (isset($trend[$periode])) {
                $trend[$periode]['belum']++;
                $trend[$periode]['tunggakan'] += $amount;
            }
        }

        $class = analitikClassifyTimeliness($row);
        $timeliness[$class] = ($timeliness[$class] ?? 0) + 1;
    }

    foreach ($trend as &$item) {
        $item['persen'] = $item['total'] > 0 ? round(($item['lunas'] / $item['total']) * 100, 1) : 0.0;
    }
    unset($item);

    $regionUnpaid = [];
    foreach (array_values(salamDaftarWilayahResmi()) as $region) {
        $total = isset($regionCustomers[$region]) ? count($regionCustomers[$region]) : 0;
        $unpaid = isset($regionUnpaidCustomers[$region]) ? count($regionUnpaidCustomers[$region]) : 0;
        if ($scope['is_all'] || $region === $scope['label']) {
            $regionUnpaid[] = [
                'wilayah' => $region,
                'total_pelanggan' => $total,
                'belum_pelanggan' => $unpaid,
                'persen' => $total > 0 ? round(($unpaid / $total) * 100, 1) : 0.0,
            ];
        }
    }
    usort($regionUnpaid, static fn(array $a, array $b): int => $b['persen'] <=> $a['persen']);

    $outstandingChart = [];
    if ($scope['is_all']) {
        foreach (array_values(salamDaftarWilayahResmi()) as $region) {
            $outstandingChart[] = [
                'key' => $region,
                'label' => $region,
                'value' => (float) ($regionOutstanding[$region] ?? 0),
            ];
        }
        usort($outstandingChart, static fn(array $a, array $b): int => $b['value'] <=> $a['value']);
    } else {
        foreach ($periods as $p) {
            $outstandingChart[] = [
                'key' => $p,
                'label' => analitikPeriodLabel($p, true),
                'value' => (float) ($periodOutstanding[$p] ?? 0),
            ];
        }
    }

    $unpaidCustomers = analitikBuildCustomerUnpaid($records, $akhir);
    $aging = ['1' => 0, '2' => 0, '3plus' => 0];
    foreach ($unpaidCustomers as $item) {
        $age = (int) ($item['lama_bulan'] ?? 1);
        if ($age <= 1) $aging['1']++;
        elseif ($age === 2) $aging['2']++;
        else $aging['3plus']++;
    }

    $billCount = count($records);
    $paymentRate = $billCount > 0 ? round(($paidCount / $billCount) * 100, 1) : 0.0;

    $regionComparison = [];
    if (salamCanAccessAllWilayah()) {
        $comparisonRecords = $scope['is_all']
            ? $records
            : analitikLoadRecords($koneksi, $awal, $akhir, [
                'value' => 'all',
                'label' => 'SEMUA WILAYAH',
                'is_all' => true,
                'locked' => false,
            ]);
        $regionComparison = analitikBuildRegionFinancialComparison($comparisonRecords);
    }

    return [
        'records' => $records,
        'summary' => [
            'pelanggan' => count($customerSet),
            'tagihan' => $billCount,
            'lunas' => $paidCount,
            'belum' => $unpaidCount,
            'tingkat_bayar' => $paymentRate,
            'total_dibayar' => $totalPaid,
            'total_tunggakan' => $totalOutstanding,
        ],
        'trend' => array_values($trend),
        'region_unpaid' => $regionUnpaid,
        'outstanding' => $outstandingChart,
        'aging' => $aging,
        'timeliness' => $timeliness,
        'unpaid_customers' => $unpaidCustomers,
        'top5' => analitikBuildTop5($records, $awal, $akhir),
        'region_comparison' => $regionComparison,
    ];
}

function analitikYearOptions(mysqli $koneksi, int $selectedStart, int $selectedEnd): array
{
    $current = (int) date('Y');
    $min = min($current - 3, $selectedStart, $selectedEnd);
    $max = max($current + 1, $selectedStart, $selectedEnd);
    $result = $koneksi->query("SELECT MIN(y) min_year, MAX(y) max_year FROM (
        SELECT YEAR(waktu) y FROM pelanggan_salam WHERE waktu IS NOT NULL
        UNION ALL
        SELECT YEAR(periode) y FROM tagihan_salam WHERE periode IS NOT NULL
    ) z");
    if ($result instanceof mysqli_result) {
        $r = $result->fetch_assoc();
        if (!empty($r['min_year'])) $min = min($min, (int) $r['min_year']);
        if (!empty($r['max_year'])) $max = max($max, (int) $r['max_year']);
    }
    return range($max, $min);
}

function analitikRupiahCompact(float $value): string
{
    if (abs($value) >= 1000000000) return 'Rp' . rtrim(rtrim(number_format($value / 1000000000, 1, ',', '.'), '0'), ',') . ' M';
    if (abs($value) >= 1000000) return 'Rp' . rtrim(rtrim(number_format($value / 1000000, 1, ',', '.'), '0'), ',') . ' jt';
    if (abs($value) >= 1000) return 'Rp' . rtrim(rtrim(number_format($value / 1000, 1, ',', '.'), '0'), ',') . ' rb';
    return salamRupiah($value);
}
