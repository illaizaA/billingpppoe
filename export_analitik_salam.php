<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
require_once __DIR__ . '/analitik_helper.php';
require_once __DIR__ . '/analitik_export_xlsx_helper.php';

date_default_timezone_set('Asia/Jakarta');

try {
    analitikRequireAccess();

    $range = analitikResolvePeriodRange($_GET);
    $scope = analitikResolveWilayah($_GET['wilayah'] ?? 'all');
    $data = analitikBuild($koneksi, $range['awal'], $range['akhir'], $scope);
    $records = $data['records'] ?? [];
    $mode = strtolower(trim((string) ($_GET['mode'] ?? 'all')));
    $analytic = strtolower(trim((string) ($_GET['analytic'] ?? '')));
    $detailType = strtolower(trim((string) ($_GET['type'] ?? '')));
    $detailKey = trim((string) ($_GET['key'] ?? ''));

    $periodLabel = analitikPeriodLabel($range['awal']) . ' - ' . analitikPeriodLabel($range['akhir']);
    $scopeLabel = (string) ($scope['label'] ?? 'SEMUA WILAYAH');
    $meta = [
        ['label' => 'Periode', 'value' => $periodLabel],
        ['label' => 'Wilayah', 'value' => $scopeLabel],
        ['label' => 'Diekspor', 'value' => salamTanggalWaktuIndonesia()],
    ];

    $conditionLabel = static function (float $percent, float $obligation): string {
        if ($obligation <= 0) return 'Belum ada data';
        if ($percent >= 75) return 'Baik';
        if ($percent >= 50) return 'Cukup';
        if ($percent >= 25) return 'Perlu perhatian';
        return 'Perlu ditagih';
    };

    $summarySheet = static function () use ($data, $meta): array {
        $summary = $data['summary'] ?? [];
        $rows = [
            ['Pelanggan Terdata', (string) number_format((int)($summary['pelanggan'] ?? 0)) . ' orang'],
            ['Jumlah Tagihan', (string) number_format((int)($summary['tagihan'] ?? 0)) . ' tagihan'],
            ['Tagihan Lunas', (string) number_format((int)($summary['lunas'] ?? 0)) . ' tagihan'],
            ['Tagihan Belum Lunas', (string) number_format((int)($summary['belum'] ?? 0)) . ' tagihan'],
            ['Tingkat Tagihan Lunas', number_format((float)($summary['tingkat_bayar'] ?? 0), 1, ',', '.') . '%'],
            ['Pembayaran Masuk', salamRupiah((float)($summary['total_dibayar'] ?? 0))],
            ['Total Tunggakan', salamRupiah((float)($summary['total_tunggakan'] ?? 0))],
        ];
        return [
            'name' => 'Ringkasan',
            'title' => 'Ringkasan Analitik Billing',
            'subtitle' => 'Ringkasan sederhana dari seluruh analitik pada periode yang dipilih',
            'meta' => $meta,
            'columns' => [
                ['label' => 'Informasi', 'type' => 'text', 'width' => 30],
                ['label' => 'Hasil', 'type' => 'text', 'width' => 24],
            ],
            'rows' => $rows,
        ];
    };

    $trendSheet = static function () use ($data, $meta): array {
        $trend = array_values($data['trend'] ?? []);
        $rows = [];
        foreach ($trend as $item) {
            $rows[] = [
                analitikPeriodLabel((string)($item['periode'] ?? '')),
                (int)($item['total'] ?? 0),
                (int)($item['lunas'] ?? 0),
                (int)($item['belum'] ?? 0),
                (float)($item['persen'] ?? 0),
                (float)($item['dibayar'] ?? 0),
                (float)($item['tunggakan'] ?? 0),
            ];
        }
        $totalBills = array_sum(array_map(static fn($r) => (int)($r['total'] ?? 0), $trend));
        $paidBills = array_sum(array_map(static fn($r) => (int)($r['lunas'] ?? 0), $trend));
        return [
            'name' => 'Perkembangan Pembayaran',
            'title' => 'Perkembangan Pembayaran',
            'subtitle' => 'Perubahan pembayaran dari bulan ke bulan',
            'meta' => $meta,
            'summary' => [
                ['label' => 'Jumlah Tagihan', 'value' => $totalBills, 'type' => 'integer'],
                ['label' => 'Tagihan Lunas', 'value' => $paidBills, 'type' => 'integer'],
            ],
            'columns' => [
                ['label' => 'Periode', 'type' => 'text', 'width' => 18],
                ['label' => 'Jumlah Tagihan', 'type' => 'integer', 'width' => 16],
                ['label' => 'Lunas', 'type' => 'integer', 'width' => 12],
                ['label' => 'Belum Lunas', 'type' => 'integer', 'width' => 14],
                ['label' => 'Persentase Lunas', 'type' => 'percent', 'width' => 18],
                ['label' => 'Pembayaran Masuk', 'type' => 'money', 'width' => 20],
                ['label' => 'Tunggakan', 'type' => 'money', 'width' => 18],
            ],
            'rows' => $rows,
        ];
    };

    $unpaidSheet = static function () use ($data, $scope, $meta): array {
        if (!empty($scope['is_all'])) {
            $rows = [];
            foreach (array_values($data['region_unpaid'] ?? []) as $item) {
                $total = (int)($item['total_pelanggan'] ?? 0);
                $unpaid = (int)($item['belum_pelanggan'] ?? 0);
                $rows[] = [
                    (string)($item['wilayah'] ?? '-'),
                    $total,
                    $unpaid,
                    max(0, $total - $unpaid),
                    (float)($item['persen'] ?? 0),
                ];
            }
            return [
                'name' => 'Belum Bayar per Wilayah',
                'title' => 'Belum Bayar per Wilayah',
                'subtitle' => 'Perbandingan pelanggan yang masih memiliki tunggakan di setiap wilayah',
                'meta' => $meta,
                'columns' => [
                    ['label' => 'Wilayah', 'type' => 'text', 'width' => 20],
                    ['label' => 'Total Pelanggan', 'type' => 'integer', 'width' => 17],
                    ['label' => 'Pelanggan Belum Bayar', 'type' => 'integer', 'width' => 22],
                    ['label' => 'Tanpa Tunggakan', 'type' => 'integer', 'width' => 18],
                    ['label' => 'Persentase Belum Bayar', 'type' => 'percent', 'width' => 23],
                ],
                'rows' => $rows,
            ];
        }

        $customers = array_values($data['unpaid_customers'] ?? []);
        $rows = [];
        foreach ($customers as $item) {
            $rows[] = [
                (string)($item['id_pelanggan'] ?? '-'),
                (string)($item['nama'] ?? '-'),
                (string)($item['paket'] ?? '-'),
                analitikPeriodLabel((string)($item['periode_tertua'] ?? '')),
                (int)($item['jumlah_periode'] ?? 0),
                (int)($item['lama_bulan'] ?? 0),
                (float)($item['total_tunggakan'] ?? 0),
            ];
        }
        $total = array_sum(array_map(static fn($r) => (float)($r['total_tunggakan'] ?? 0), $customers));
        return [
            'name' => 'Prioritas Belum Bayar',
            'title' => 'Prioritas Pelanggan Belum Bayar',
            'subtitle' => 'Daftar pelanggan yang perlu diprioritaskan untuk penagihan',
            'meta' => $meta,
            'summary' => [
                ['label' => 'Pelanggan Belum Bayar', 'value' => count($customers), 'type' => 'integer'],
                ['label' => 'Total Tunggakan', 'value' => $total, 'type' => 'money'],
            ],
            'columns' => [
                ['label' => 'ID Pelanggan', 'type' => 'text', 'width' => 18],
                ['label' => 'Nama Pelanggan', 'type' => 'text', 'width' => 25],
                ['label' => 'Paket', 'type' => 'text', 'width' => 20],
                ['label' => 'Tagihan Tertua', 'type' => 'text', 'width' => 18],
                ['label' => 'Bulan Belum Lunas', 'type' => 'integer', 'width' => 18],
                ['label' => 'Lama Tunggakan (Bulan)', 'type' => 'integer', 'width' => 22],
                ['label' => 'Total Tunggakan', 'type' => 'money', 'width' => 20],
            ],
            'rows' => $rows,
        ];
    };

    $outstandingSheet = static function () use ($data, $records, $scope, $meta): array {
        $stats = [];
        foreach ($records as $record) {
            if (($record['status_bayar'] ?? '') === 'Lunas') continue;
            $key = !empty($scope['is_all']) ? (string)($record['wilayah'] ?? '-') : (string)($record['periode'] ?? '');
            if (!isset($stats[$key])) $stats[$key] = ['tagihan' => 0, 'pelanggan' => [], 'tunggakan' => 0.0];
            $stats[$key]['tagihan']++;
            $id = (int)($record['pelanggan_id'] ?? 0);
            if ($id > 0) $stats[$key]['pelanggan'][$id] = true;
            $stats[$key]['tunggakan'] += (float)($record['nominal_tagihan'] ?? 0);
        }

        $rows = [];
        foreach (array_values($data['outstanding'] ?? []) as $item) {
            $key = (string)($item['key'] ?? '');
            $stat = $stats[$key] ?? ['tagihan' => 0, 'pelanggan' => [], 'tunggakan' => 0.0];
            $rows[] = [
                !empty($scope['is_all']) ? (string)($item['label'] ?? '-') : analitikPeriodLabel($key),
                (float)($item['value'] ?? 0),
                count($stat['pelanggan']),
                (int)$stat['tagihan'],
            ];
        }
        $title = !empty($scope['is_all']) ? 'Total Tunggakan per Wilayah' : 'Tunggakan per Bulan';
        return [
            'name' => !empty($scope['is_all']) ? 'Tunggakan per Wilayah' : 'Tunggakan per Bulan',
            'title' => $title,
            'subtitle' => 'Rincian nominal yang masih belum dibayar',
            'meta' => $meta,
            'summary' => [
                ['label' => 'Total Tunggakan', 'value' => (float)($data['summary']['total_tunggakan'] ?? 0), 'type' => 'money'],
            ],
            'columns' => [
                ['label' => !empty($scope['is_all']) ? 'Wilayah' : 'Periode', 'type' => 'text', 'width' => 20],
                ['label' => 'Total Tunggakan', 'type' => 'money', 'width' => 20],
                ['label' => 'Pelanggan Menunggak', 'type' => 'integer', 'width' => 21],
                ['label' => 'Tagihan Belum Lunas', 'type' => 'integer', 'width' => 20],
            ],
            'rows' => $rows,
        ];
    };

    $topSheet = static function () use ($data, $meta): array {
        $rows = [];
        foreach (array_values($data['top5'] ?? []) as $index => $item) {
            $rows[] = [
                $index + 1,
                (string)($item['id_pelanggan'] ?? '-'),
                (string)($item['nama'] ?? '-'),
                (string)($item['wilayah'] ?? '-'),
                (int)($item['lunas'] ?? 0),
                (int)($item['total_periode'] ?? 0),
                (float)($item['persen_lunas'] ?? 0),
                (int)($item['streak'] ?? 0),
            ];
        }
        return [
            'name' => 'Pelanggan Rajin Bayar',
            'title' => 'Pelanggan Paling Rajin Bayar',
            'subtitle' => 'Pelanggan dengan tingkat pembayaran terbaik pada periode yang dipilih',
            'meta' => $meta,
            'columns' => [
                ['label' => 'Peringkat', 'type' => 'integer', 'width' => 12],
                ['label' => 'ID Pelanggan', 'type' => 'text', 'width' => 18],
                ['label' => 'Nama Pelanggan', 'type' => 'text', 'width' => 25],
                ['label' => 'Wilayah', 'type' => 'text', 'width' => 18],
                ['label' => 'Bulan Lunas', 'type' => 'integer', 'width' => 14],
                ['label' => 'Bulan Tercatat', 'type' => 'integer', 'width' => 16],
                ['label' => 'Tingkat Pembayaran', 'type' => 'percent', 'width' => 20],
                ['label' => 'Lunas Berturut-turut Terpanjang', 'type' => 'integer', 'width' => 30],
            ],
            'rows' => $rows,
        ];
    };

    $financialSheet = static function () use ($data, $meta, $conditionLabel): array {
        $rows = [];
        foreach (array_values($data['region_comparison'] ?? []) as $item) {
            $obligation = (float)($item['total_kewajiban'] ?? 0);
            $percent = (float)($item['persen_pembayaran_terkumpul'] ?? 0);
            $rows[] = [
                (string)($item['wilayah'] ?? '-'),
                (float)($item['pendapatan'] ?? 0),
                (float)($item['tunggakan'] ?? 0),
                $obligation,
                $percent,
                $conditionLabel($percent, $obligation),
            ];
        }
        return [
            'name' => 'Kondisi Pembayaran',
            'title' => 'Kondisi Pembayaran per Wilayah',
            'subtitle' => 'Menunjukkan seberapa besar pembayaran yang sudah terkumpul',
            'meta' => $meta,
            'columns' => [
                ['label' => 'Wilayah', 'type' => 'text', 'width' => 20],
                ['label' => 'Pembayaran Masuk', 'type' => 'money', 'width' => 20],
                ['label' => 'Sisa Belum Dibayar', 'type' => 'money', 'width' => 20],
                ['label' => 'Total Kewajiban', 'type' => 'money', 'width' => 20],
                ['label' => 'Pembayaran Terkumpul', 'type' => 'percent', 'width' => 22],
                ['label' => 'Kondisi', 'type' => 'status', 'width' => 20],
            ],
            'rows' => $rows,
        ];
    };

    $statusSheet = static function () use ($data, $meta): array {
        $rows = [];
        foreach (array_values($data['region_comparison'] ?? []) as $item) {
            $rows[] = [
                (string)($item['wilayah'] ?? '-'),
                (int)($item['tagihan_lunas'] ?? 0),
                (int)($item['tagihan_belum'] ?? 0),
                (int)($item['total_tagihan'] ?? 0),
                (float)($item['persen_lunas'] ?? 0),
            ];
        }
        return [
            'name' => 'Status Pembayaran',
            'title' => 'Status Tagihan per Wilayah',
            'subtitle' => 'Perbandingan jumlah tagihan yang sudah lunas dan belum lunas',
            'meta' => $meta,
            'columns' => [
                ['label' => 'Wilayah', 'type' => 'text', 'width' => 20],
                ['label' => 'Tagihan Lunas', 'type' => 'integer', 'width' => 16],
                ['label' => 'Tagihan Belum Lunas', 'type' => 'integer', 'width' => 20],
                ['label' => 'Total Tagihan', 'type' => 'integer', 'width' => 16],
                ['label' => 'Persentase Lunas', 'type' => 'percent', 'width' => 18],
            ],
            'rows' => $rows,
        ];
    };

    $detailSheet = static function (string $type, string $key) use ($data, $records, $scope, $meta): array {
        $columns = [];
        $rows = [];
        $summary = [];
        $title = 'Detail Data';
        $sheetName = 'Detail Data';

        $billRow = static function (array $r): array {
            return [
                (string)($r['id_pelanggan'] ?? '-'),
                (string)($r['nama'] ?? '-'),
                (string)($r['wilayah'] ?? '-'),
                analitikPeriodLabel((string)($r['periode'] ?? '')),
                (string)($r['status_bayar'] ?? '-'),
                (float)($r['nominal_tagihan'] ?? 0),
                (float)($r['nominal_dibayar'] ?? 0),
                salamBulananIndonesia($r['tanggal_bayar'] ?? null, true),
            ];
        };

        if ($type === 'trend') {
            if (!preg_match('/^\d{4}-\d{2}$/', $key)) throw new RuntimeException('Periode detail tidak valid.');
            $filtered = array_values(array_filter($records, static fn($r) => ($r['periode'] ?? '') === $key));
            $paid = count(array_filter($filtered, static fn($r) => ($r['status_bayar'] ?? '') === 'Lunas'));
            $paidAmount = array_sum(array_map(static fn($r) => (float)($r['nominal_dibayar'] ?? 0), $filtered));
            $outstanding = array_sum(array_map(static fn($r) => ($r['status_bayar'] ?? '') === 'Belum Lunas' ? (float)($r['nominal_tagihan'] ?? 0) : 0, $filtered));
            $title = 'Pembayaran ' . analitikPeriodLabel($key);
            $sheetName = 'Detail Perkembangan Bayar';
            $summary = [
                ['label' => 'Jumlah Tagihan', 'value' => count($filtered), 'type' => 'integer'],
                ['label' => 'Sudah Lunas', 'value' => $paid, 'type' => 'integer'],
                ['label' => 'Belum Lunas', 'value' => count($filtered) - $paid, 'type' => 'integer'],
                ['label' => 'Pembayaran Masuk', 'value' => $paidAmount, 'type' => 'money'],
                ['label' => 'Tunggakan', 'value' => $outstanding, 'type' => 'money'],
            ];
            $columns = [
                ['label'=>'ID Pelanggan','type'=>'text','width'=>18], ['label'=>'Nama Pelanggan','type'=>'text','width'=>25],
                ['label'=>'Wilayah','type'=>'text','width'=>18], ['label'=>'Periode','type'=>'text','width'=>16],
                ['label'=>'Status','type'=>'status','width'=>15], ['label'=>'Tagihan','type'=>'money','width'=>18],
                ['label'=>'Dibayar','type'=>'money','width'=>18], ['label'=>'Tanggal Bayar','type'=>'text','width'=>18],
            ];
            $rows = array_map($billRow, $filtered);
        } elseif ($type === 'unpaid') {
            $customers = $data['unpaid_customers'] ?? [];
            if (!empty($scope['is_all'])) {
                $region = salamWilayahResmiDariInput($key, false);
                if ($region === null) throw new RuntimeException('Wilayah detail tidak valid.');
                $customers = array_filter($customers, static fn($r) => ($r['wilayah'] ?? '') === $region);
                $title = 'Pelanggan Belum Membayar - ' . $region;
                $sheetName = 'Detail Belum Bayar';
            } else {
                if ($key === 'clear') {
                    $allIds = [];
                    foreach ($records as $r) {
                        $id = (int)($r['pelanggan_id'] ?? 0);
                        if ($id > 0) $allIds[$id] = $r;
                    }
                    $unpaidIds = array_fill_keys(array_map('intval', array_keys($customers)), true);
                    $clear = [];
                    foreach ($allIds as $id => $r) if (!isset($unpaidIds[$id])) $clear[] = $r;
                    $title = 'Pelanggan Tanpa Tunggakan - ' . $scope['label'];
                    $sheetName = 'Detail Tanpa Tunggakan';
                    $summary = [['label' => 'Pelanggan', 'value' => count($clear), 'type' => 'integer']];
                    $columns = [
                        ['label'=>'ID Pelanggan','type'=>'text','width'=>18], ['label'=>'Nama Pelanggan','type'=>'text','width'=>25],
                        ['label'=>'Wilayah','type'=>'text','width'=>18], ['label'=>'Paket','type'=>'text','width'=>20],
                    ];
                    $rows = array_map(static fn($r) => [(string)($r['id_pelanggan'] ?? '-'), (string)($r['nama'] ?? '-'), (string)($r['wilayah'] ?? '-'), (string)($r['paket'] ?? '-')], $clear);
                    return ['name'=>$sheetName,'title'=>$title,'subtitle'=>'Detail sesuai pilihan pada dashboard analitik','meta'=>$meta,'summary'=>$summary,'columns'=>$columns,'rows'=>$rows];
                }
                $title = 'Pelanggan Belum Membayar - ' . $scope['label'];
                $sheetName = 'Detail Belum Bayar';
            }
            $customers = array_values($customers);
            $total = array_sum(array_map(static fn($r) => (float)($r['total_tunggakan'] ?? 0), $customers));
            $summary = [
                ['label' => 'Pelanggan', 'value' => count($customers), 'type' => 'integer'],
                ['label' => 'Total Belum Dibayar', 'value' => $total, 'type' => 'money'],
            ];
            $columns = [
                ['label'=>'ID Pelanggan','type'=>'text','width'=>18], ['label'=>'Nama Pelanggan','type'=>'text','width'=>25],
                ['label'=>'Wilayah','type'=>'text','width'=>18], ['label'=>'Tagihan Tertua','type'=>'text','width'=>18],
                ['label'=>'Periode Belum Lunas','type'=>'integer','width'=>20], ['label'=>'Total Tunggakan','type'=>'money','width'=>20],
            ];
            $rows = array_map(static fn($r) => [
                (string)($r['id_pelanggan'] ?? '-'), (string)($r['nama'] ?? '-'), (string)($r['wilayah'] ?? '-'),
                analitikPeriodLabel((string)($r['periode_tertua'] ?? '')), (int)($r['jumlah_periode'] ?? 0), (float)($r['total_tunggakan'] ?? 0),
            ], $customers);
        } elseif ($type === 'outstanding') {
            $filtered = array_values(array_filter($records, static function ($r) use ($scope, $key) {
                if (($r['status_bayar'] ?? '') !== 'Belum Lunas') return false;
                return !empty($scope['is_all']) ? (($r['wilayah'] ?? '') === $key) : (($r['periode'] ?? '') === $key);
            }));
            $title = !empty($scope['is_all']) ? 'Rincian Tunggakan - ' . $key : 'Rincian Tunggakan - ' . analitikPeriodLabel($key);
            $sheetName = 'Detail Tunggakan';
            $amount = array_sum(array_map(static fn($r) => (float)($r['nominal_tagihan'] ?? 0), $filtered));
            $ids = [];
            foreach ($filtered as $r) {
                $id = (int)($r['pelanggan_id'] ?? 0);
                if ($id > 0) $ids[$id] = true;
            }
            $summary = [
                ['label'=>'Total Tunggakan','value'=>$amount,'type'=>'money'],
                ['label'=>'Pelanggan','value'=>count($ids),'type'=>'integer'],
            ];
            $columns = [
                ['label'=>'ID Pelanggan','type'=>'text','width'=>18], ['label'=>'Nama Pelanggan','type'=>'text','width'=>25],
                ['label'=>'Wilayah','type'=>'text','width'=>18], ['label'=>'Periode','type'=>'text','width'=>16],
                ['label'=>'Status','type'=>'status','width'=>15], ['label'=>'Tagihan','type'=>'money','width'=>18],
                ['label'=>'Dibayar','type'=>'money','width'=>18], ['label'=>'Tanggal Bayar','type'=>'text','width'=>18],
            ];
            $rows = array_map($billRow, $filtered);
        } elseif ($type === 'customer' || $type === 'top') {
            $customerId = (int)$key;
            if ($customerId <= 0) throw new RuntimeException('Pelanggan detail tidak valid.');
            $filtered = array_values(array_filter($records, static fn($r) => (int)($r['pelanggan_id'] ?? 0) === $customerId));
            if (!$filtered) throw new RuntimeException('Data pelanggan tidak ditemukan pada periode dan wilayah ini.');
            usort($filtered, static fn($a, $b) => strcmp((string)($a['periode'] ?? ''), (string)($b['periode'] ?? '')));
            $first = $filtered[0];
            $title = ($type === 'top' ? 'Riwayat Pembayaran - ' : 'Detail Pembayaran - ') . ($first['nama'] ?? '-');
            $sheetName = $type === 'top' ? 'Detail Pelanggan Rajin Bayar' : 'Detail Pembayaran Pelanggan';
            $paid = count(array_filter($filtered, static fn($r) => ($r['status_bayar'] ?? '') === 'Lunas'));
            $total = count($filtered);
            $unpaid = $total - $paid;
            $outstanding = array_sum(array_map(static fn($r) => ($r['status_bayar'] ?? '') === 'Lunas' ? 0 : (float)($r['nominal_tagihan'] ?? 0), $filtered));
            $summary = [
                ['label'=>'ID Pelanggan','value'=>(string)($first['id_pelanggan'] ?? '-'),'type'=>'text'],
                ['label'=>'Wilayah','value'=>(string)($first['wilayah'] ?? '-'),'type'=>'text'],
                ['label'=>'Bulan Lunas','value'=>$paid,'type'=>'integer'],
                ['label'=>'Bulan Belum Lunas','value'=>$unpaid,'type'=>'integer'],
                ['label'=>'Tingkat Pembayaran','value'=>$total > 0 ? round(($paid / $total) * 100, 1) : 0,'type'=>'percent'],
                ['label'=>'Total Tunggakan','value'=>$outstanding,'type'=>'money'],
            ];
            $columns = [
                ['label'=>'Periode','type'=>'text','width'=>18], ['label'=>'Status','type'=>'status','width'=>16],
                ['label'=>'Tagihan','type'=>'money','width'=>18], ['label'=>'Dibayar','type'=>'money','width'=>18],
                ['label'=>'Tanggal Bayar','type'=>'text','width'=>18],
            ];
            $rows = array_map(static fn($r) => [
                analitikPeriodLabel((string)($r['periode'] ?? '')), (string)($r['status_bayar'] ?? '-'),
                (float)($r['nominal_tagihan'] ?? 0), (float)($r['nominal_dibayar'] ?? 0), salamBulananIndonesia($r['tanggal_bayar'] ?? null, true),
            ], $filtered);
        } elseif ($type === 'aging') {
            $customers = array_values($data['unpaid_customers'] ?? []);
            $filtered = array_values(array_filter($customers, static function ($r) use ($key) {
                $age = (int)($r['lama_bulan'] ?? 1);
                if ($key === '1') return $age <= 1;
                if ($key === '2') return $age === 2;
                if ($key === '3plus') return $age >= 3;
                return false;
            }));
            $label = $key === '1' ? '1 Bulan' : ($key === '2' ? '2 Bulan' : '3 Bulan atau Lebih');
            $title = 'Lama Tunggakan - ' . $label;
            $sheetName = 'Detail Lama Tunggakan';
            $summary = [
                ['label'=>'Pelanggan','value'=>count($filtered),'type'=>'integer'],
                ['label'=>'Total Tunggakan','value'=>array_sum(array_map(static fn($r)=>(float)($r['total_tunggakan'] ?? 0),$filtered)),'type'=>'money'],
            ];
            $columns = [
                ['label'=>'ID Pelanggan','type'=>'text','width'=>18], ['label'=>'Nama Pelanggan','type'=>'text','width'=>25],
                ['label'=>'Wilayah','type'=>'text','width'=>18], ['label'=>'Tunggakan Tertua','type'=>'text','width'=>18],
                ['label'=>'Lama (Bulan)','type'=>'integer','width'=>15], ['label'=>'Total Tunggakan','type'=>'money','width'=>20],
            ];
            $rows = array_map(static fn($r)=>[(string)($r['id_pelanggan']??'-'),(string)($r['nama']??'-'),(string)($r['wilayah']??'-'),analitikPeriodLabel((string)($r['periode_tertua']??'')),(int)($r['lama_bulan']??0),(float)($r['total_tunggakan']??0)],$filtered);
        } elseif ($type === 'timeliness') {
            $allowed = ['before','on','late','unpaid','unknown'];
            if (!in_array($key,$allowed,true)) throw new RuntimeException('Kategori detail tidak valid.');
            $filtered = array_values(array_filter($records, static fn($r) => analitikClassifyTimeliness($r) === $key));
            $labels = ['before'=>'Bayar Sebelum Jatuh Tempo','on'=>'Bayar Pada Jatuh Tempo','late'=>'Bayar Terlambat','unpaid'=>'Belum Membayar','unknown'=>'Tanggal Tidak Lengkap'];
            $title = $labels[$key];
            $sheetName = 'Detail Ketepatan Bayar';
            $summary = [['label'=>'Jumlah Data','value'=>count($filtered),'type'=>'integer']];
            $columns = [
                ['label'=>'ID Pelanggan','type'=>'text','width'=>18], ['label'=>'Nama Pelanggan','type'=>'text','width'=>25],
                ['label'=>'Wilayah','type'=>'text','width'=>18], ['label'=>'Periode','type'=>'text','width'=>16],
                ['label'=>'Jatuh Tempo','type'=>'text','width'=>18], ['label'=>'Tanggal Bayar','type'=>'text','width'=>18],
                ['label'=>'Status','type'=>'status','width'=>16],
            ];
            $rows = array_map(static fn($r)=>[(string)($r['id_pelanggan']??'-'),(string)($r['nama']??'-'),(string)($r['wilayah']??'-'),analitikPeriodLabel((string)($r['periode']??'')),salamBulananIndonesia($r['tanggal_jatuh_tempo']??null,true),salamBulananIndonesia($r['tanggal_bayar']??null,true),(string)($r['status_bayar']??'-')],$filtered);
        } else {
            throw new RuntimeException('Jenis detail analitik tidak dikenali.');
        }

        return [
            'name' => $sheetName,
            'title' => $title,
            'subtitle' => 'Detail sesuai pilihan pada dashboard analitik',
            'meta' => $meta,
            'summary' => $summary,
            'columns' => $columns,
            'rows' => $rows,
        ];
    };

    $sheetBuilders = [
        'trend' => $trendSheet,
        'unpaid' => $unpaidSheet,
        'outstanding' => $outstandingSheet,
        'top' => $topSheet,
        'financial' => $financialSheet,
        'status' => $statusSheet,
    ];


    /*
     * Rincian export per analitik.
     * Tombol Excel di dashboard selalu mengunduh SATU file yang berisi:
     * 1) hasil/ringkasan analitik, dan
     * 2) rincian data yang menjadi dasar analitik tersebut.
     *
     * Detail modal di dashboard tetap hanya untuk melihat data di layar;
     * tidak ada tombol export terpisah di modal agar alur pengguna lebih sederhana.
     */
    $sortBillingRecords = static function (array $items): array {
        usort($items, static function ($a, $b): int {
            $cmp = strcmp((string)($a['periode'] ?? ''), (string)($b['periode'] ?? ''));
            if ($cmp !== 0) return $cmp;
            $cmp = strcmp((string)($a['wilayah'] ?? ''), (string)($b['wilayah'] ?? ''));
            if ($cmp !== 0) return $cmp;
            return strcasecmp((string)($a['nama'] ?? ''), (string)($b['nama'] ?? ''));
        });
        return $items;
    };

    $billingDetailSheet = static function (
        string $name,
        string $title,
        string $subtitle,
        array $items,
        array $meta
    ) use ($sortBillingRecords): array {
        $items = $sortBillingRecords(array_values($items));
        $rows = [];
        foreach ($items as $r) {
            $status = (string)($r['status_bayar'] ?? '-');
            $tagihan = (float)($r['nominal_tagihan'] ?? 0);
            $dibayar = (float)($r['nominal_dibayar'] ?? 0);
            $sisa = $status === 'Lunas' ? 0.0 : $tagihan;
            $rows[] = [
                analitikPeriodLabel((string)($r['periode'] ?? '')),
                (string)($r['id_pelanggan'] ?? '-'),
                (string)($r['nama'] ?? '-'),
                (string)($r['wilayah'] ?? '-'),
                (string)($r['paket'] ?? '-'),
                $status,
                $tagihan,
                $dibayar,
                $sisa,
                salamBulananIndonesia($r['tanggal_bayar'] ?? null, true),
            ];
        }

        return [
            'name' => $name,
            'title' => $title,
            'subtitle' => $subtitle,
            'meta' => $meta,
            'summary' => [
                ['label' => 'Jumlah Data', 'value' => count($rows), 'type' => 'integer'],
            ],
            'columns' => [
                ['label'=>'Periode','type'=>'text','width'=>18],
                ['label'=>'ID Pelanggan','type'=>'text','width'=>18],
                ['label'=>'Nama Pelanggan','type'=>'text','width'=>25],
                ['label'=>'Wilayah','type'=>'text','width'=>18],
                ['label'=>'Paket','type'=>'text','width'=>20],
                ['label'=>'Status','type'=>'status','width'=>16],
                ['label'=>'Tagihan','type'=>'money','width'=>18],
                ['label'=>'Dibayar','type'=>'money','width'=>18],
                ['label'=>'Sisa Belum Dibayar','type'=>'money','width'=>21],
                ['label'=>'Tanggal Bayar','type'=>'text','width'=>18],
            ],
            'rows' => $rows,
        ];
    };

    $trendDetailSheet = static function () use ($records, $meta, $billingDetailSheet): array {
        return $billingDetailSheet(
            'Detail Perkembangan Bayar',
            'Rincian Perkembangan Pembayaran',
            'Daftar tagihan pelanggan yang membentuk analitik perkembangan pembayaran',
            $records,
            $meta
        );
    };

    $unpaidDetailSheet = static function () use ($records, $meta, $billingDetailSheet): array {
        $items = array_values(array_filter($records, static fn($r) => (string)($r['status_bayar'] ?? '') !== 'Lunas'));
        return $billingDetailSheet(
            'Detail Belum Bayar',
            'Rincian Pelanggan Belum Bayar',
            'Daftar tagihan yang masih belum lunas pada periode yang dipilih',
            $items,
            $meta
        );
    };

    $outstandingDetailSheet = static function () use ($records, $meta, $billingDetailSheet): array {
        $items = array_values(array_filter($records, static fn($r) => (string)($r['status_bayar'] ?? '') !== 'Lunas'));
        return $billingDetailSheet(
            'Detail Tunggakan',
            'Detail Tunggakan',
            'Daftar tagihan yang menjadi dasar perhitungan total tunggakan',
            $items,
            $meta
        );
    };

    $topDetailSheet = static function () use ($data, $records, $meta, $sortBillingRecords): array {
        $top = array_values($data['top5'] ?? []);
        $rankByCustomer = [];
        $nameByCustomer = [];
        foreach ($top as $index => $item) {
            $id = (int)($item['pelanggan_id'] ?? 0);
            if ($id <= 0) continue;
            $rankByCustomer[$id] = $index + 1;
            $nameByCustomer[$id] = (string)($item['nama'] ?? '-');
        }

        $items = array_values(array_filter($records, static function ($r) use ($rankByCustomer): bool {
            return isset($rankByCustomer[(int)($r['pelanggan_id'] ?? 0)]);
        }));
        $items = $sortBillingRecords($items);
        usort($items, static function ($a, $b) use ($rankByCustomer): int {
            $ra = $rankByCustomer[(int)($a['pelanggan_id'] ?? 0)] ?? 9999;
            $rb = $rankByCustomer[(int)($b['pelanggan_id'] ?? 0)] ?? 9999;
            if ($ra !== $rb) return $ra <=> $rb;
            $cmp = strcasecmp((string)($a['nama'] ?? ''), (string)($b['nama'] ?? ''));
            if ($cmp !== 0) return $cmp;
            return strcmp((string)($a['periode'] ?? ''), (string)($b['periode'] ?? ''));
        });

        $rows = [];
        foreach ($items as $r) {
            $id = (int)($r['pelanggan_id'] ?? 0);
            $rows[] = [
                (int)($rankByCustomer[$id] ?? 0),
                (string)($r['id_pelanggan'] ?? '-'),
                (string)($r['nama'] ?? ($nameByCustomer[$id] ?? '-')),
                (string)($r['wilayah'] ?? '-'),
                analitikPeriodLabel((string)($r['periode'] ?? '')),
                (string)($r['status_bayar'] ?? '-'),
                (float)($r['nominal_tagihan'] ?? 0),
                (float)($r['nominal_dibayar'] ?? 0),
                salamBulananIndonesia($r['tanggal_bayar'] ?? null, true),
            ];
        }

        return [
            'name' => 'Detail Pelanggan Rajin Bayar',
            'title' => 'Rincian Pelanggan Paling Rajin Bayar',
            'subtitle' => 'Riwayat pembayaran pelanggan yang tampil pada peringkat dashboard',
            'meta' => $meta,
            'summary' => [
                ['label'=>'Pelanggan pada Peringkat','value'=>count($rankByCustomer),'type'=>'integer'],
            ],
            'columns' => [
                ['label'=>'Peringkat','type'=>'integer','width'=>12],
                ['label'=>'ID Pelanggan','type'=>'text','width'=>18],
                ['label'=>'Nama Pelanggan','type'=>'text','width'=>25],
                ['label'=>'Wilayah','type'=>'text','width'=>18],
                ['label'=>'Periode','type'=>'text','width'=>18],
                ['label'=>'Status','type'=>'status','width'=>16],
                ['label'=>'Tagihan','type'=>'money','width'=>18],
                ['label'=>'Dibayar','type'=>'money','width'=>18],
                ['label'=>'Tanggal Bayar','type'=>'text','width'=>18],
            ],
            'rows' => $rows,
        ];
    };

    $financialDetailSheet = static function () use ($records, $meta, $billingDetailSheet): array {
        return $billingDetailSheet(
            'Detail Kondisi Pembayaran',
            'Rincian Kondisi Pembayaran per Wilayah',
            'Daftar tagihan yang menjadi dasar kondisi pembayaran tiap wilayah',
            $records,
            $meta
        );
    };

    $statusDetailSheet = static function () use ($records, $meta, $billingDetailSheet): array {
        return $billingDetailSheet(
            'Detail Status Pembayaran',
            'Rincian Status Tagihan per Wilayah',
            'Daftar tagihan yang menjadi dasar perbandingan Lunas dan Belum Lunas',
            $records,
            $meta
        );
    };

    $detailBuilders = [
        'trend' => $trendDetailSheet,
        'unpaid' => $unpaidDetailSheet,
        'outstanding' => $outstandingDetailSheet,
        'top' => $topDetailSheet,
        'financial' => $financialDetailSheet,
        'status' => $statusDetailSheet,
    ];

    // Nama file export dibuat singkat, formal, dan mudah dikenali pengguna non-IT.
    // Contoh: "Pelanggan Rajin Bayar - Apr-Sep 2026.xlsx"
    $bulanSingkat = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];
    $awalParts = array_map('intval', explode('-', $range['awal']));
    $akhirParts = array_map('intval', explode('-', $range['akhir']));
    $awalTahun = $awalParts[0] ?? (int)date('Y');
    $awalBulan = $awalParts[1] ?? (int)date('n');
    $akhirTahun = $akhirParts[0] ?? $awalTahun;
    $akhirBulan = $akhirParts[1] ?? $awalBulan;

    if ($awalTahun === $akhirTahun && $awalBulan === $akhirBulan) {
        $periodeFile = ($bulanSingkat[$awalBulan] ?? '') . ' ' . $awalTahun;
    } elseif ($awalTahun === $akhirTahun) {
        $periodeFile = ($bulanSingkat[$awalBulan] ?? '') . '-' . ($bulanSingkat[$akhirBulan] ?? '') . ' ' . $awalTahun;
    } else {
        $periodeFile = ($bulanSingkat[$awalBulan] ?? '') . ' ' . $awalTahun
            . '-' . ($bulanSingkat[$akhirBulan] ?? '') . ' ' . $akhirTahun;
    }

    $scopeFile = '';
    if (empty($scope['is_all'])) {
        $scopeFile = ' - ' . ucwords(strtolower($scopeLabel));
    }

    if ($mode === 'detail') {
        $sheet = $detailSheet($detailType, $detailKey);
        analitikXlsxDownload([$sheet], 'Detail Analitik - ' . $periodeFile . $scopeFile);
    }

    if ($mode === 'panel') {
        if (!isset($sheetBuilders[$analytic], $detailBuilders[$analytic])) {
            throw new RuntimeException('Analitik yang dipilih tidak tersedia untuk export.');
        }
        if (in_array($analytic, ['financial','status'], true) && empty($data['region_comparison'])) {
            throw new RuntimeException('Perbandingan antarwilayah tidak tersedia untuk cakupan akun ini.');
        }

        // Satu kali klik = satu file Excel berisi hasil analitik + rincian datanya.
        $resultSheet = $sheetBuilders[$analytic]();
        $detailSheetForPanel = $detailBuilders[$analytic]();
        $nameMap = [
            'trend' => 'Perkembangan Pembayaran',
            'unpaid' => !empty($scope['is_all']) ? 'Belum Bayar per Wilayah' : 'Prioritas Belum Bayar',
            'outstanding' => !empty($scope['is_all']) ? 'Tunggakan per Wilayah' : 'Tunggakan per Bulan',
            'top' => 'Pelanggan Rajin Bayar',
            'financial' => 'Kondisi Pembayaran',
            'status' => 'Status Pembayaran',
        ];
        analitikXlsxDownload(
            [$resultSheet, $detailSheetForPanel],
            ($nameMap[$analytic] ?? 'Analitik Billing') . ' - ' . $periodeFile . $scopeFile
        );
    }

    if ($mode !== 'all') throw new RuntimeException('Mode export tidak dikenali.');

    // Satu file lengkap. Setiap hasil analitik langsung diikuti sheet rincian yang mendasarinya.
    // Nama tab dibuat eksplisit supaya pengguna tidak melihat "Analitik 1", "Analitik 2", dst.
    $withSheetName = static function (array $sheet, string $name): array {
        $sheet['name'] = $name;
        return $sheet;
    };

    $sheets = [
        $withSheetName($summarySheet(), 'Ringkasan'),
        $withSheetName($trendSheet(), 'Perkembangan Pembayaran'),
        $withSheetName($trendDetailSheet(), 'Detail Perkembangan Bayar'),
        $withSheetName(
            $unpaidSheet(),
            !empty($scope['is_all']) ? 'Belum Bayar per Wilayah' : 'Prioritas Belum Bayar'
        ),
        $withSheetName($unpaidDetailSheet(), 'Detail Belum Bayar'),
        $withSheetName(
            $outstandingSheet(),
            !empty($scope['is_all']) ? 'Tunggakan per Wilayah' : 'Tunggakan per Bulan'
        ),
        $withSheetName($outstandingDetailSheet(), 'Detail Tunggakan'),
        $withSheetName($topSheet(), 'Pelanggan Rajin Bayar'),
        $withSheetName($topDetailSheet(), 'Detail Pelanggan Rajin Bayar'),
    ];
    if (!empty($data['region_comparison'])) {
        $sheets[] = $withSheetName($financialSheet(), 'Kondisi Pembayaran');
        $sheets[] = $withSheetName($financialDetailSheet(), 'Detail Kondisi Pembayaran');
        $sheets[] = $withSheetName($statusSheet(), 'Status Pembayaran');
        $sheets[] = $withSheetName($statusDetailSheet(), 'Detail Status Pembayaran');
    }

    analitikXlsxDownload($sheets, 'Analitik Billing - ' . $periodeFile . $scopeFile);

} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Export Analitik</title>';
    echo '<style>body{font-family:Segoe UI,Tahoma,sans-serif;background:#f4f7fb;color:#243244;padding:30px}.box{max-width:680px;margin:auto;background:#fff;border:1px solid #e6ebf1;border-radius:14px;padding:22px}.box h2{margin-top:0;color:#c0392b}.box a{display:inline-block;margin-top:14px;background:#3498db;color:#fff;text-decoration:none;padding:9px 13px;border-radius:8px;font-weight:700}</style></head><body>';
    echo '<div class="box"><h2>Export Excel belum dapat dibuat</h2><p>' . $message . '</p><a href="analitik_salam.php">Kembali ke Ringkasan Analitik</a></div></body></html>';
}
