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
        // Tagihan tetap dibaca dari bulan yang tersentuh oleh rentang tanggal.
        // Penyaringan tanggal pembayaran yang sebenarnya dilakukan setelah data
        // tagihan dimuat, sehingga seluruh panel memakai dasar data yang sama.
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

function analitikTanggalBayarYmd($value): string
{
    $value = trim((string) $value);
    if ($value === '') return '';

    $timestamp = strtotime($value);
    if ($timestamp === false) return '';

    return date('Y-m-d', $timestamp);
}


/**
 * Tanggal acuan untuk menentukan kondisi tunggakan.
 *
 * Rentang BULAN:
 * - satu bulan dianggap satu periode billing penuh;
 * - acuan memakai hari terakhir dari bulan akhir yang dipilih;
 * - tunggakan lama dari bulan sebelum awal filter tetap dibawa selama belum lunas;
 * - bulan berjalan tetap dinilai sebagai satu periode penuh, sehingga tagihan
 *   Belum Lunas pada bulan tersebut tidak hilang hanya karena hari ini belum
 *   mencapai tanggal jatuh tempo di akhir bulan.
 *
 * Rentang TANGGAL:
 * - memakai tanggal akhir filter secara harian;
 * - jatuh tempo setelah tanggal akhir belum dianggap tunggakan;
 * - bila tanggal akhir berada di masa depan, dibatasi sampai hari ini.
 */
function analitikTanggalAcuanTunggakan(?array $range = null): string
{
    $today = date('Y-m-d');
    $filterTipe = strtolower(trim((string) ($range['filter_tipe'] ?? 'bulan')));

    if ($filterTipe === 'bulan') {
        $akhir = trim((string) ($range['akhir'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}$/', $akhir)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $akhir . '-01');
            if ($date !== false) {
                // Untuk pilihan bulan, nilai sampai akhir periode bulan tersebut.
                // Jika user memilih bulan masa depan, jangan melampaui hari ini.
                $currentMonth = date('Y-m');
                if (strcmp($akhir, $currentMonth) > 0) {
                    return $today;
                }
                return $date->modify('last day of this month')->format('Y-m-d');
            }
        }
    }

    $candidate = trim((string) ($range['tanggal_akhir'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate)) {
        return $today;
    }

    return strcmp($candidate, $today) > 0 ? $today : $candidate;
}

/**
 * Sebuah tagihan disebut tunggakan hanya jika:
 * 1) status pada snapshot analitik masih Belum Lunas; dan
 * 2) tanggal jatuh tempo valid serta sudah tercapai/terlewati pada tanggal
 *    acuan analitik.
 *
 * Data tanpa tanggal jatuh tempo tidak dipaksakan menjadi tunggakan karena
 * tidak dapat diverifikasi waktunya.
 */
function analitikIsOverdue(array $row, ?array $range = null): bool
{
    if (($row['status_bayar'] ?? '') === 'Lunas') return false;

    $dueRaw = trim((string) ($row['tanggal_jatuh_tempo'] ?? ''));

    // Fallback aman untuk data lama yang belum memiliki tanggal jatuh tempo:
    // gunakan hari terakhir dari periode tagihannya. Ini hanya dipakai untuk
    // membaca analitik; database tidak diubah dari fungsi ini.
    if ($dueRaw === '' || $dueRaw === '0000-00-00' || $dueRaw === '0000-00-00 00:00:00') {
        $periodeRaw = trim((string) ($row['periode_tanggal'] ?? $row['periode'] ?? ''));
        if (preg_match('/^(\d{4})-(\d{2})/', $periodeRaw, $m)) {
            $periodeDate = DateTimeImmutable::createFromFormat('!Y-m-d', $m[1] . '-' . $m[2] . '-01');
            if ($periodeDate !== false) {
                $dueRaw = $periodeDate->modify('last day of this month')->format('Y-m-d');
            }
        }
    }

    if ($dueRaw === '') return false;

    $dueTs = strtotime($dueRaw);
    if ($dueTs === false) return false;

    $due = date('Y-m-d', $dueTs);
    if ($due === '0000-00-00') return false;

    return strcmp($due, analitikTanggalAcuanTunggakan($range)) <= 0;
}

function analitikFilterOverdueRecords(array $records, ?array $range = null): array
{
    return array_values(array_filter(
        $records,
        static fn(array $row): bool => analitikIsOverdue($row, $range)
    ));
}


/**
 * Cari periode tagihan paling awal yang tersedia untuk cakupan wilayah.
 * Dipakai khusus untuk membawa tunggakan lama ke periode analitik berikutnya.
 */
function analitikEarliestBillingPeriod(mysqli $koneksi, array $scope, string $fallback): string
{
    $whereP = '';
    $whereT = '';
    $types = '';
    $params = [];

    if (empty($scope['is_all'])) {
        $whereP = ' WHERE ' . salamSqlNormalisasiAlamat('p.alamat') . ' = ?';
        $whereT = ' WHERE ('
            . salamSqlNormalisasiAlamat('t.alamat_snapshot') . ' = ?'
            . " OR (TRIM(COALESCE(t.alamat_snapshot, '')) = '' AND "
            . salamSqlNormalisasiAlamat('p.alamat') . ' = ?))';
        $types = 'sss';
        $regionKey = salamNormalisasiKunci($scope['value']);
        $params[] = $regionKey;
        $params[] = $regionKey;
        $params[] = $regionKey;
    }

    $sql = "
        SELECT MIN(periode_min) AS periode_min
        FROM (
            SELECT DATE_FORMAT(MIN(p.waktu), '%Y-%m') AS periode_min
            FROM pelanggan_salam p
            {$whereP}

            UNION ALL

            SELECT DATE_FORMAT(MIN(t.periode), '%Y-%m') AS periode_min
            FROM tagihan_salam t
            LEFT JOIN pelanggan_salam p ON p.id = t.pelanggan_id
            {$whereT}
        ) z
        WHERE periode_min IS NOT NULL
    ";

    $stmt = $koneksi->prepare($sql);
    if (!$stmt) return $fallback;

    if ($types !== '') {
        analitikBindParams($stmt, $types, $params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $periode = trim((string) ($row['periode_min'] ?? ''));
    return preg_match('/^\\d{4}-\\d{2}$/', $periode) ? $periode : $fallback;
}

/**
 * Bentuk kondisi tagihan "per tanggal acuan".
 *
 * Khusus perhitungan tunggakan, tanggal awal filter tidak menghapus tunggakan
 * lama. Yang dilihat adalah kondisi sampai tanggal akhir/acuan:
 * - jika tagihan belum lunas, tetap dianggap belum lunas;
 * - jika sekarang sudah Lunas tetapi tanggal bayarnya setelah tanggal acuan,
 *   maka pada tanggal acuan tagihan tersebut masih dianggap belum lunas;
 * - jika sudah dibayar pada/sebelum tanggal acuan, tidak termasuk tunggakan.
 *
 * Database tidak diubah; penyesuaian hanya pada salinan array analitik.
 */
function analitikApplyArrearsCutoffState(array $records, ?array $range = null): array
{
    $cutoff = analitikTanggalAcuanTunggakan($range);
    $result = [];

    foreach ($records as $row) {
        $statusAsli = (string) ($row['status_bayar'] ?? '');
        $tanggalBayarAsli = $row['tanggal_bayar'] ?? null;
        $nominalDibayarAsli = (float) ($row['nominal_dibayar'] ?? 0);

        $row['status_bayar_asli'] = $statusAsli;
        $row['tanggal_bayar_asli'] = $tanggalBayarAsli;
        $row['nominal_dibayar_asli'] = $nominalDibayarAsli;

        if ($statusAsli !== 'Lunas') {
            $result[] = $row;
            continue;
        }

        $tanggalBayar = analitikTanggalBayarYmd($tanggalBayarAsli);

        // Sudah lunas pada/sebelum tanggal acuan -> tidak lagi menjadi tunggakan.
        if ($tanggalBayar !== '' && strcmp($tanggalBayar, $cutoff) <= 0) {
            continue;
        }

        // Jika data lama sudah berstatus Lunas tetapi tanggal bayarnya kosong,
        // jangan membuat tunggakan palsu karena waktunya tidak dapat diverifikasi.
        if ($tanggalBayar === '') {
            continue;
        }

        // Dibayar setelah tanggal acuan -> pada tanggal acuan masih belum lunas.
        $row['status_bayar'] = 'Belum Lunas';
        $row['nominal_dibayar'] = 0.0;
        $row['tanggal_bayar'] = null;
        $result[] = $row;
    }

    return array_values($result);
}

/**
 * Ambil seluruh tunggakan yang masih aktif sampai tanggal acuan.
 *
 * Dataset analitik utama tetap mengikuti rentang filter, tetapi tunggakan dibaca
 * sejak riwayat tagihan paling awal agar tunggakan bulan sebelumnya tidak hilang
 * hanya karena bulan tersebut berada sebelum tanggal awal filter.
 */
function analitikLoadOverdueRecordsAsOf(
    mysqli $koneksi,
    string $periodeAkhir,
    array $scope,
    ?array $range = null
): array {
    $cutoff = analitikTanggalAcuanTunggakan($range);
    $cutoffPeriod = substr($cutoff, 0, 7);
    if (!preg_match('/^\\d{4}-\\d{2}$/', $cutoffPeriod)) {
        $cutoffPeriod = $periodeAkhir;
    }

    $earliest = analitikEarliestBillingPeriod($koneksi, $scope, $cutoffPeriod);
    if (strcmp($earliest, $cutoffPeriod) > 0) {
        $earliest = $cutoffPeriod;
    }

    $records = analitikLoadRecords($koneksi, $earliest, $cutoffPeriod, $scope);
    $records = analitikApplyArrearsCutoffState($records, $range);

    return analitikFilterOverdueRecords($records, $range);
}

/**
 * Menyamakan seluruh analitik dengan filter yang dipilih.
 *
 * Mode bulan:
 * - perilaku lama dipertahankan; seluruh tagihan pada bulan terpilih dihitung.
 *
 * Mode tanggal:
 * - tagihan dibaca dari bulan yang tersentuh rentang tanggal;
 * - pembayaran sebelum tanggal awal tidak dimasukkan karena sudah selesai
 *   sebelum rentang analisis dimulai;
 * - pembayaran di dalam rentang dihitung sebagai Lunas;
 * - pembayaran setelah tanggal akhir diperlakukan sebagai Belum Lunas pada
 *   rentang tersebut (tanpa mengubah data asli di database);
 * - pembayaran berstatus Lunas tanpa tanggal bayar yang valid tidak dianggap
 *   Lunas dalam rentang tanggal karena waktunya tidak dapat diverifikasi.
 *
 * Semua perubahan hanya terjadi pada array di memori untuk kebutuhan analitik.
 */
function analitikApplySelectedRange(array $records, ?array $range = null): array
{
    if (!$range || (($range['filter_tipe'] ?? 'bulan') !== 'tanggal')) {
        return $records;
    }

    $tanggalAwal = trim((string) ($range['tanggal_awal'] ?? ''));
    $tanggalAkhir = trim((string) ($range['tanggal_akhir'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalAwal)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalAkhir)) {
        return $records;
    }

    $filtered = [];
    foreach ($records as $row) {
        $statusAsli = (string) ($row['status_bayar'] ?? '');
        $tanggalBayarAsli = $row['tanggal_bayar'] ?? null;
        $nominalDibayarAsli = (float) ($row['nominal_dibayar'] ?? 0);

        // Simpan nilai asli hanya sebagai metadata internal. Tidak menulis DB.
        $row['status_bayar_asli'] = $statusAsli;
        $row['tanggal_bayar_asli'] = $tanggalBayarAsli;
        $row['nominal_dibayar_asli'] = $nominalDibayarAsli;
        $row['pembayaran_dalam_rentang'] = false;

        if ($statusAsli !== 'Lunas') {
            // Tagihan yang memang belum lunas tetap relevan sampai akhir rentang.
            $filtered[] = $row;
            continue;
        }

        $tanggalBayar = analitikTanggalBayarYmd($tanggalBayarAsli);

        if ($tanggalBayar !== '' && strcmp($tanggalBayar, $tanggalAwal) < 0) {
            // Sudah lunas sebelum rentang dimulai: tidak menjadi bagian analisis
            // pada rentang tanggal yang sedang dipilih.
            continue;
        }

        if ($tanggalBayar !== ''
            && strcmp($tanggalBayar, $tanggalAwal) >= 0
            && strcmp($tanggalBayar, $tanggalAkhir) <= 0) {
            $row['pembayaran_dalam_rentang'] = true;
            $filtered[] = $row;
            continue;
        }

        // Dibayar setelah batas akhir (atau tanggal bayar tidak dapat diverifikasi):
        // pada rentang ini tagihan belum dianggap lunas. Nilai asli tetap ada pada
        // metadata internal di atas dan database sama sekali tidak diubah.
        $row['status_bayar'] = 'Belum Lunas';
        $row['nominal_dibayar'] = 0.0;
        $row['tanggal_bayar'] = null;
        $filtered[] = $row;
    }

    return array_values($filtered);
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
            COALESCE(p.langganan_selesai, LAST_DAY(p.waktu)) AS tanggal_jatuh_tempo,
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
            COALESCE(t.tanggal_jatuh_tempo, LAST_DAY(t.periode)) AS tanggal_jatuh_tempo,
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
    // Ranking pelanggan rajin bayar:
    // 1) persentase lunas tertinggi,
    // 2) jumlah periode lunas terbanyak,
    // 3) streak lunas terpanjang,
    // 4) jika masih sama, waktu pembayaran paling awal.
    // tanggal_bayar disimpan sebagai DATETIME agar tanggal + jam dapat dibandingkan.
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
        $waktuBayarPertama = '';

        foreach ($rows as $periode => $row) {
            if (($row['status_bayar'] ?? '') === 'Lunas') {
                $lunas++;
                if ($periodeLunasTerakhir === '' || strcmp($periode, $periodeLunasTerakhir) > 0) {
                    $periodeLunasTerakhir = $periode;
                }

                // Simpan waktu pembayaran paling awal pada rentang analitik.
                // Untuk data lama yang hanya memiliki tanggal, MySQL akan menyimpannya
                // sebagai 00:00:00 setelah migrasi DATE -> DATETIME.
                $tanggalBayar = trim((string) ($row['tanggal_bayar'] ?? ''));
                if ($tanggalBayar !== '' && $tanggalBayar !== '0000-00-00' && $tanggalBayar !== '0000-00-00 00:00:00') {
                    $timestamp = strtotime($tanggalBayar);
                    if ($timestamp !== false) {
                        $waktuValid = date('Y-m-d H:i:s', $timestamp);
                        if ($waktuBayarPertama === '' || strcmp($waktuValid, $waktuBayarPertama) < 0) {
                            $waktuBayarPertama = $waktuValid;
                        }
                    }
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
            'waktu_bayar_pertama' => $waktuBayarPertama,
        ]);
    }

    usort($ranking, static function (array $a, array $b): int {
        $cmpPersen = ((float) ($b['persen_lunas'] ?? 0)) <=> ((float) ($a['persen_lunas'] ?? 0));
        if ($cmpPersen !== 0) return $cmpPersen;

        $cmpLunas = ((int) ($b['lunas'] ?? 0)) <=> ((int) ($a['lunas'] ?? 0));
        if ($cmpLunas !== 0) return $cmpLunas;

        $cmpStreak = ((int) ($b['streak'] ?? 0)) <=> ((int) ($a['streak'] ?? 0));
        if ($cmpStreak !== 0) return $cmpStreak;

        // Pemecah seri: tanggal + jam pembayaran paling awal.
        $waktuA = trim((string) ($a['waktu_bayar_pertama'] ?? ''));
        $waktuB = trim((string) ($b['waktu_bayar_pertama'] ?? ''));
        if ($waktuA !== $waktuB) {
            if ($waktuA === '') return 1;
            if ($waktuB === '') return -1;
            return strcmp($waktuA, $waktuB);
        }

        // Fallback deterministik jika data lama/dua transaksi benar-benar memiliki
        // timestamp yang sama. Tidak menggunakan nama A-Z untuk menentukan ranking.
        return ((int) ($a['pelanggan_id'] ?? 0)) <=> ((int) ($b['pelanggan_id'] ?? 0));
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

function analitikBuild(mysqli $koneksi, string $awal, string $akhir, array $scope, ?array $range = null): array
{
    $records = analitikLoadRecords($koneksi, $awal, $akhir, $scope);
    $records = analitikApplySelectedRange($records, $range);
    $periods = analitikPeriodeSequence($awal, $akhir);

    $customerSet = [];
    $paidCount = 0;
    $unpaidCount = 0;
    $totalPaid = 0.0;
    $totalOutstanding = 0.0; // hanya tagihan yang sudah jatuh tempo

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
            // Belum Bayar tetap menghitung semua tagihan yang belum lunas.
            $unpaidCount++;
            $amount = (float) ($row['nominal_tagihan'] ?? 0);
            if ($id > 0) $regionUnpaidCustomers[$region][$id] = true;
            if (isset($trend[$periode])) {
                $trend[$periode]['belum']++;
            }

            // Nilai tunggakan tidak dihitung dari dataset rentang ini saja.
            // Setelah loop, tunggakan dimuat secara akumulatif dari seluruh
            // riwayat sampai tanggal acuan supaya tunggakan bulan sebelumnya
            // tetap terbawa sampai benar-benar lunas.
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

    // Tunggakan bersifat akumulatif: ambil seluruh tagihan lama yang pada
    // tanggal acuan sudah jatuh tempo dan belum lunas. Tanggal awal filter tidak
    // menghapus tunggakan lama; tunggakan hilang hanya setelah lunas.
    $overdueRecords = analitikLoadOverdueRecordsAsOf($koneksi, $akhir, $scope, $range);
    $cutoffPeriod = substr(analitikTanggalAcuanTunggakan($range), 0, 7);
    if (!preg_match('/^\d{4}-\d{2}$/', $cutoffPeriod)) $cutoffPeriod = $akhir;

    foreach ($overdueRecords as $row) {
        $amount = (float) ($row['nominal_tagihan'] ?? 0);
        $region = (string) ($row['wilayah'] ?? '-');
        $periode = (string) ($row['periode'] ?? '');

        $totalOutstanding += $amount;
        $regionOutstanding[$region] = ($regionOutstanding[$region] ?? 0) + $amount;
        if ($periode !== '') {
            $periodOutstanding[$periode] = ($periodOutstanding[$periode] ?? 0) + $amount;
            // Grafik perkembangan tetap hanya menampilkan bulan dalam filter,
            // tetapi bila bulan tersebut memiliki tunggakan, nilainya sinkron.
            if (isset($trend[$periode])) {
                $trend[$periode]['tunggakan'] += $amount;
            }
        }
    }

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
        // Untuk Admin Wilayah, tampilkan juga periode tunggakan lama yang masih
        // aktif walaupun periodenya berada sebelum bulan awal filter.
        $outstandingPeriods = array_keys($periodOutstanding);
        sort($outstandingPeriods, SORT_STRING);
        foreach ($outstandingPeriods as $p) {
            $outstandingChart[] = [
                'key' => $p,
                'label' => analitikPeriodLabel($p, true),
                'value' => (float) ($periodOutstanding[$p] ?? 0),
            ];
        }
    }

    // Semua pelanggan belum bayar pada rentang terpilih tetap tersedia untuk
    // panel Belum Bayar. Ini berbeda dari tunggakan akumulatif di atas.
    $unpaidCustomers = analitikBuildCustomerUnpaid($records, $akhir);

    $overdueCustomers = analitikBuildCustomerUnpaid($overdueRecords, $cutoffPeriod);

    $aging = ['1' => 0, '2' => 0, '3plus' => 0];
    foreach ($overdueCustomers as $item) {
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
        if (!$scope['is_all']) {
            $comparisonRecords = analitikApplySelectedRange($comparisonRecords, $range);
        }
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
        'overdue_records' => $overdueRecords,
        'overdue_customers' => $overdueCustomers,
        'tanggal_acuan_tunggakan' => analitikTanggalAcuanTunggakan($range),
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
