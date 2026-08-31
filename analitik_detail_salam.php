<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
require_once __DIR__ . '/analitik_helper.php';

header('Content-Type: application/json; charset=utf-8');

try {
    analitikRequireAccess();
    $range = analitikResolvePeriodRange($_GET);
    $scope = analitikResolveWilayah($_GET['wilayah'] ?? 'all');
    $data = analitikBuild($koneksi, $range['awal'], $range['akhir'], $scope);
    $records = $data['records'];
    $type = trim((string) ($_GET['type'] ?? ''));
    $key = trim((string) ($_GET['key'] ?? ''));

    $title = 'Detail Analitik';
    $summary = [];
    $columns = [];
    $rows = [];

    $formatBillRow = static function (array $row): array {
        return [
            $row['id_pelanggan'] ?? '-',
            $row['nama'] ?? '-',
            $row['wilayah'] ?? '-',
            analitikPeriodLabel((string) ($row['periode'] ?? '')),
            $row['status_bayar'] ?? '-',
            salamRupiah((float) ($row['nominal_tagihan'] ?? 0)),
            salamRupiah((float) ($row['nominal_dibayar'] ?? 0)),
            salamBulananIndonesia($row['tanggal_bayar'] ?? null, true),
        ];
    };

    if ($type === 'trend') {
        if (!preg_match('/^\d{4}-\d{2}$/', $key)) throw new RuntimeException('Periode tidak valid.');
        $filtered = array_values(array_filter($records, fn($r) => ($r['periode'] ?? '') === $key));
        $paid = count(array_filter($filtered, fn($r) => ($r['status_bayar'] ?? '') === 'Lunas'));
        $unpaid = count($filtered) - $paid;
        $paidAmount = array_sum(array_map(fn($r) => (float) ($r['nominal_dibayar'] ?? 0), $filtered));
        $outstanding = array_sum(array_map(fn($r) => ($r['status_bayar'] ?? '') === 'Belum Lunas' ? (float) ($r['nominal_tagihan'] ?? 0) : 0, $filtered));
        $title = 'Pembayaran ' . analitikPeriodLabel($key);
        $summary = [
            ['label' => 'Jumlah Tagihan', 'value' => count($filtered) . ' data'],
            ['label' => 'Sudah Dibayar', 'value' => $paid . ' data'],
            ['label' => 'Belum Dibayar', 'value' => $unpaid . ' data'],
            ['label' => 'Nominal Dibayar', 'value' => salamRupiah($paidAmount)],
            ['label' => 'Belum Dibayar', 'value' => salamRupiah($outstanding)],
        ];
        $columns = ['ID', 'Nama', 'Wilayah', 'Periode', 'Status', 'Tagihan', 'Dibayar', 'Tanggal Bayar'];
        $rows = array_map($formatBillRow, $filtered);
    } elseif ($type === 'unpaid') {
        $customers = $data['unpaid_customers'];
        if ($scope['is_all']) {
            $region = salamWilayahResmiDariInput($key, false);
            if ($region === null) throw new RuntimeException('Wilayah tidak valid.');
            $customers = array_filter($customers, fn($r) => ($r['wilayah'] ?? '') === $region);
            $title = 'Pelanggan Belum Membayar - ' . $region;
        } else {
            if ($key === 'clear') {
                $allIds = [];
                foreach ($records as $r) if ((int) ($r['pelanggan_id'] ?? 0) > 0) $allIds[(int)$r['pelanggan_id']] = $r;
                $unpaidIds = array_fill_keys(array_map('intval', array_keys($customers)), true);
                $clear = [];
                foreach ($allIds as $id => $r) if (!isset($unpaidIds[$id])) $clear[] = $r;
                $title = 'Pelanggan Tanpa Tunggakan - ' . $scope['label'];
                $summary = [['label' => 'Pelanggan', 'value' => count($clear) . ' orang']];
                $columns = ['ID', 'Nama', 'Wilayah', 'Paket'];
                $rows = array_map(fn($r) => [$r['id_pelanggan'] ?? '-', $r['nama'] ?? '-', $r['wilayah'] ?? '-', $r['paket'] ?? '-'], $clear);
                echo json_encode(compact('title', 'summary', 'columns', 'rows'), JSON_UNESCAPED_UNICODE);
                exit;
            }
            $title = 'Pelanggan Belum Membayar - ' . $scope['label'];
        }
        $customers = array_values($customers);
        $total = array_sum(array_map(fn($r) => (float) ($r['total_tunggakan'] ?? 0), $customers));
        $summary = [
            ['label' => 'Pelanggan', 'value' => count($customers) . ' orang'],
            ['label' => 'Total Belum Dibayar', 'value' => salamRupiah($total)],
        ];
        $columns = ['ID', 'Nama', 'Wilayah', 'Tagihan Tertua', 'Periode Belum Lunas', 'Total Tunggakan'];
        $rows = array_map(fn($r) => [
            $r['id_pelanggan'] ?? '-', $r['nama'] ?? '-', $r['wilayah'] ?? '-',
            analitikPeriodLabel((string) ($r['periode_tertua'] ?? '')), ($r['jumlah_periode'] ?? 0) . ' periode',
            salamRupiah((float) ($r['total_tunggakan'] ?? 0)),
        ], $customers);
    } elseif ($type === 'outstanding') {
        $filtered = array_values(array_filter($records, function ($r) use ($scope, $key) {
            if (($r['status_bayar'] ?? '') !== 'Belum Lunas') return false;
            return $scope['is_all'] ? (($r['wilayah'] ?? '') === $key) : (($r['periode'] ?? '') === $key);
        }));
        $title = $scope['is_all'] ? 'Rincian Tunggakan - ' . $key : 'Rincian Tunggakan - ' . analitikPeriodLabel($key);
        $amount = array_sum(array_map(fn($r) => (float) ($r['nominal_tagihan'] ?? 0), $filtered));
        $ids = [];
        foreach ($filtered as $r) $ids[(int) ($r['pelanggan_id'] ?? 0)] = true;
        $summary = [
            ['label' => 'Total Tunggakan', 'value' => salamRupiah($amount)],
            ['label' => 'Pelanggan', 'value' => count(array_filter(array_keys($ids))) . ' orang'],
        ];
        $columns = ['ID', 'Nama', 'Wilayah', 'Periode', 'Status', 'Tagihan', 'Dibayar', 'Tanggal Bayar'];
        $rows = array_map($formatBillRow, $filtered);
    } elseif ($type === 'aging') {
        $customers = array_values($data['unpaid_customers']);
        $filtered = array_values(array_filter($customers, function ($r) use ($key) {
            $age = (int) ($r['lama_bulan'] ?? 1);
            if ($key === '1') return $age <= 1;
            if ($key === '2') return $age === 2;
            if ($key === '3plus') return $age >= 3;
            return false;
        }));
        $label = $key === '1' ? '1 Bulan' : ($key === '2' ? '2 Bulan' : '3 Bulan atau Lebih');
        $title = 'Lama Tunggakan - ' . $label;
        $summary = [
            ['label' => 'Pelanggan', 'value' => count($filtered) . ' orang'],
            ['label' => 'Total Tunggakan', 'value' => salamRupiah(array_sum(array_map(fn($r) => (float) ($r['total_tunggakan'] ?? 0), $filtered)))],
        ];
        $columns = ['ID', 'Nama', 'Wilayah', 'Tunggakan Tertua', 'Lama', 'Total Tunggakan'];
        $rows = array_map(fn($r) => [
            $r['id_pelanggan'] ?? '-', $r['nama'] ?? '-', $r['wilayah'] ?? '-',
            analitikPeriodLabel((string) ($r['periode_tertua'] ?? '')), ($r['lama_bulan'] ?? 0) . ' bulan',
            salamRupiah((float) ($r['total_tunggakan'] ?? 0)),
        ], $filtered);
    } elseif ($type === 'timeliness') {
        $allowed = ['before', 'on', 'late', 'unpaid', 'unknown'];
        if (!in_array($key, $allowed, true)) throw new RuntimeException('Kategori tidak valid.');
        $filtered = array_values(array_filter($records, fn($r) => analitikClassifyTimeliness($r) === $key));
        $labels = [
            'before' => 'Bayar Sebelum Jatuh Tempo', 'on' => 'Bayar Pada Jatuh Tempo',
            'late' => 'Bayar Terlambat', 'unpaid' => 'Belum Membayar', 'unknown' => 'Tanggal Tidak Lengkap',
        ];
        $title = $labels[$key];
        $summary = [['label' => 'Jumlah Data', 'value' => count($filtered) . ' tagihan']];
        $columns = ['ID', 'Nama', 'Wilayah', 'Periode', 'Jatuh Tempo', 'Tanggal Bayar', 'Status'];
        $rows = array_map(fn($r) => [
            $r['id_pelanggan'] ?? '-', $r['nama'] ?? '-', $r['wilayah'] ?? '-', analitikPeriodLabel((string) ($r['periode'] ?? '')),
            salamBulananIndonesia($r['tanggal_jatuh_tempo'] ?? null, true), salamBulananIndonesia($r['tanggal_bayar'] ?? null, true),
            $r['status_bayar'] ?? '-',
        ], $filtered);
    } elseif ($type === 'customer') {
        $customerId = (int) $key;
        if ($customerId <= 0) throw new RuntimeException('Pelanggan tidak valid.');
        $filtered = array_values(array_filter($records, fn($r) => (int) ($r['pelanggan_id'] ?? 0) === $customerId));
        if (!$filtered) throw new RuntimeException('Data pelanggan tidak ditemukan pada rentang dan cakupan akun ini.');

        $first = $filtered[0];
        $unpaidRows = array_values(array_filter($filtered, fn($r) => ($r['status_bayar'] ?? '') !== 'Lunas'));
        $outstanding = array_sum(array_map(fn($r) => (float) ($r['nominal_tagihan'] ?? 0), $unpaidRows));
        $title = 'Detail Pembayaran - ' . ($first['nama'] ?? '-');
        $summary = [
            ['label' => 'ID Pelanggan', 'value' => $first['id_pelanggan'] ?? '-'],
            ['label' => 'Wilayah', 'value' => $first['wilayah'] ?? '-'],
            ['label' => 'Jumlah Tagihan', 'value' => count($filtered) . ' data'],
            ['label' => 'Belum Lunas', 'value' => count($unpaidRows) . ' data'],
            ['label' => 'Total Tunggakan', 'value' => salamRupiah($outstanding)],
        ];
        $columns = ['ID', 'Nama', 'Wilayah', 'Periode', 'Status', 'Tagihan', 'Dibayar', 'Tanggal Bayar'];
        $rows = array_map($formatBillRow, $filtered);
    } elseif ($type === 'top') {
        $customerId = (int) $key;
        if ($customerId <= 0) throw new RuntimeException('Pelanggan tidak valid.');
        $filtered = array_values(array_filter($records, fn($r) => (int) ($r['pelanggan_id'] ?? 0) === $customerId));
        if (!$filtered) throw new RuntimeException('Data pelanggan tidak ditemukan pada cakupan akun ini.');

        usort($filtered, fn($a, $b) => strcmp((string)($a['periode'] ?? ''), (string)($b['periode'] ?? '')));
        $first = $filtered[0];
        $total = count($filtered);
        $paid = count(array_filter($filtered, fn($r) => ($r['status_bayar'] ?? '') === 'Lunas'));
        $percent = $total > 0 ? round(($paid / $total) * 100, 1) : 0.0;
        $percentLabel = abs($percent - round($percent)) < 0.05
            ? number_format($percent, 0, ',', '.') . '%'
            : number_format($percent, 1, ',', '.') . '%';

        $title = 'Riwayat Pembayaran - ' . ($first['nama'] ?? '-');
        $summary = [
            ['label' => 'ID Pelanggan', 'value' => $first['id_pelanggan'] ?? '-'],
            ['label' => 'Wilayah', 'value' => $first['wilayah'] ?? '-'],
            ['label' => 'Pembayaran', 'value' => $paid . ' dari ' . $total . ' bulan'],
            ['label' => 'Tingkat Pembayaran', 'value' => $percentLabel],
        ];
        $columns = ['Periode', 'Status', 'Tagihan', 'Dibayar', 'Tanggal Bayar'];
        $rows = array_map(fn($r) => [
            analitikPeriodLabel((string) ($r['periode'] ?? '')),
            (($r['status_bayar'] ?? '') === 'Lunas') ? 'Lunas ✓' : 'Belum Lunas',
            salamRupiah((float) ($r['nominal_tagihan'] ?? 0)),
            salamRupiah((float) ($r['nominal_dibayar'] ?? 0)),
            salamBulananIndonesia($r['tanggal_bayar'] ?? null, true),
        ], $filtered);
    } else {
        throw new RuntimeException('Jenis detail analitik tidak dikenali.');
    }

    if (count($rows) > 250) $rows = array_slice($rows, 0, 250);
    echo json_encode(compact('title', 'summary', 'columns', 'rows'), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
