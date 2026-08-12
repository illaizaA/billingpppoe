<?php
/** Helper aplikasi Billing multiwilayah. Nama file lama dipertahankan agar fitur yang sudah berjalan tidak rusak. */

function salamDaftarWilayahResmi(): array
{
    return [
        'baran' => 'BARAN',
        'gunungmanuk' => 'GUNUNGMANUK',
        'ngasemayu' => 'NGASEMAYU',
        'salam' => 'SALAM',
        'trosari' => 'TROSARI',
        'waduk' => 'WADUK',
    ];
}

function salamNormalisasiKunci(?string $value): string
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

function salamWilayahResmiDariInput(?string $value, bool $gunakanKoreksiTypo = true): ?string
{
    $key = salamNormalisasiKunci($value);
    if ($key === '') {
        return null;
    }

    $wilayah = salamDaftarWilayahResmi();
    if (isset($wilayah[$key])) {
        return $wilayah[$key];
    }

    if (!$gunakanKoreksiTypo) {
        return null;
    }

    // Koreksi hanya untuk salah ketik ringan pada enam wilayah resmi.
    $bestKey = null;
    $bestDistance = PHP_INT_MAX;
    foreach (array_keys($wilayah) as $knownKey) {
        $distance = levenshtein($key, $knownKey);
        if ($distance < $bestDistance) {
            $bestDistance = $distance;
            $bestKey = $knownKey;
        }
    }

    $maxDistance = strlen($key) >= 9 ? 2 : 1;
    return ($bestKey !== null && $bestDistance <= $maxDistance) ? $wilayah[$bestKey] : null;
}

function salamNormalisasiAlamatInput(?string $value): string
{
    $value = preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';
    if ($value === '') {
        return '';
    }

    $resmi = salamWilayahResmiDariInput($value, true);
    if ($resmi !== null) {
        return $resmi;
    }

    return function_exists('mb_strtoupper')
        ? mb_strtoupper($value, 'UTF-8')
        : strtoupper($value);
}

function salamKodeWilayah(?string $value): string
{
    $key = salamNormalisasiKunci($value);
    $codes = [
        'baran' => 'BRN',
        'gunungmanuk' => 'GNM',
        'ngasemayu' => 'NGA',
        'salam' => 'SLM',
        'trosari' => 'TRS',
        'waduk' => 'WDK',
    ];
    if (isset($codes[$key])) {
        return $codes[$key];
    }

    $raw = strtoupper(preg_replace('/[^a-z0-9]/i', '', (string) $value) ?? '');
    return str_pad(substr($raw, 0, 3), 3, 'X');
}

function salamNamaWilayahTampilan(?string $value): string
{
    $resmi = salamWilayahResmiDariInput($value, false);
    if ($resmi !== null) {
        return $resmi;
    }

    $normal = salamNormalisasiAlamatInput($value);
    return $normal !== '' ? $normal : '-';
}

function salamRequireLogin(): void
{
    $role = strtolower((string) ($_SESSION['role'] ?? ''));
    if (empty($_SESSION['login']) || !in_array($role, ['admin', 'superadmin'], true)) {
        header('Location: login.php');
        exit;
    }

    if ($role === 'admin' && salamNormalisasiKunci($_SESSION['wilayah'] ?? '') === '') {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }
}

function salamIsSuperAdmin(): bool
{
    return !empty($_SESSION['login'])
        && strtolower((string) ($_SESSION['role'] ?? '')) === 'superadmin';
}

function salamIsWilayahSemua(?string $value): bool
{
    return in_array(salamNormalisasiKunci($value), ['semua', 'semuawilayah', 'all'], true);
}

function salamIsAdminSemuaWilayah(): bool
{
    return !empty($_SESSION['login'])
        && strtolower((string) ($_SESSION['role'] ?? '')) === 'admin'
        && salamIsWilayahSemua($_SESSION['wilayah'] ?? '');
}

function salamCanAccessAllWilayah(): bool
{
    return salamIsSuperAdmin() || salamIsAdminSemuaWilayah();
}

function salamRequireSuperAdmin(): void
{
    salamRequireLogin();
    if (!salamIsSuperAdmin()) {
        http_response_code(403);
        exit('Akses hanya untuk Super Admin.');
    }
}

function salamWilayahLogin(): string
{
    if (salamIsSuperAdmin()) {
        return 'SEMUA WILAYAH';
    }

    return salamNormalisasiAlamatInput($_SESSION['wilayah'] ?? '');
}

function salamKunciWilayahLogin(): string
{
    return salamNormalisasiKunci($_SESSION['wilayah'] ?? '');
}

function salamSqlNormalisasiAlamat(string $column = 'alamat'): string
{
    if (!preg_match('/^[a-zA-Z0-9_.]+$/', $column)) {
        throw new InvalidArgumentException('Nama kolom alamat tidak valid.');
    }

    return "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(COALESCE({$column}, '')), ' ', ''), '-', ''), '_', ''), '.', ''), '/', ''))";
}

function salamScopeCondition(mysqli $koneksi, string $column = 'alamat'): string
{
    if (salamCanAccessAllWilayah()) {
        return '1=1';
    }

    $key = $koneksi->real_escape_string(salamKunciWilayahLogin());
    return salamSqlNormalisasiAlamat($column) . " = '{$key}'";
}

function salamWilayahFilterCondition(mysqli $koneksi, ?string $filter, string $column = 'alamat'): string
{
    $filter = trim((string) $filter);
    if ($filter === '' || strtolower($filter) === 'all' || strtolower($filter) === 'semua') {
        return '1=1';
    }

    $key = $koneksi->real_escape_string(salamNormalisasiKunci($filter));
    if ($key === '') {
        return '1=0';
    }

    return salamSqlNormalisasiAlamat($column) . " = '{$key}'";
}

function salamCanAccessAlamat(?string $alamat): bool
{
    return salamCanAccessAllWilayah()
        || salamNormalisasiKunci($alamat) === salamKunciWilayahLogin();
}

function salamFindPelangganById(mysqli $koneksi, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $scope = salamScopeCondition($koneksi, 'alamat');
    $stmt = $koneksi->prepare("SELECT * FROM pelanggan_salam WHERE id = ? AND {$scope} LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function salamBulananIndonesia(?string $date, bool $withDay = true): string
{
    if (!$date) return '-';
    $time = strtotime($date);
    if ($time === false) return '-';
    $months = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
    $month = $months[(int) date('n', $time)] ?? date('F', $time);
    return $withDay ? date('j', $time) . ' ' . $month . ' ' . date('Y', $time) : $month . ' ' . date('Y', $time);
}

function salamTanggalWaktuIndonesia(?string $dateTime = null): string
{
    $time = $dateTime ? strtotime($dateTime) : time();
    if ($time === false) $time = time();
    return salamBulananIndonesia(date('Y-m-d', $time), true) . ', ' . date('H:i', $time);
}

function salamNormalisasiWhatsApp(?string $number): string
{
    $number = preg_replace('/\D+/', '', (string) $number);
    if (str_starts_with($number, '00')) $number = substr($number, 2);
    if (str_starts_with($number, '0')) $number = '62' . substr($number, 1);
    elseif (str_starts_with($number, '8')) $number = '62' . $number;
    return preg_match('/^62\d{8,14}$/', $number) ? $number : '';
}

function salamRupiah($value): string
{
    return 'Rp' . number_format((float) $value, 0, ',', '.');
}
?>
