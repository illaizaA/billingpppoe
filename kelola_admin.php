<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
require_once __DIR__ . '/config_salam.php';
salamRequireSuperAdmin();

if (empty($_SESSION['csrf_kelola_admin'])) {
    $_SESSION['csrf_kelola_admin'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_kelola_admin'];

function kelolaEsc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function kelolaRedirect(string $query = ''): void
{
    header('Location: kelola_admin.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function kelolaSetFlash(string $type, string $message): void
{
    $_SESSION['kelola_admin_flash'] = ['type' => $type, 'message' => $message];
}

function kelolaValidUsername(string $username): bool
{
    return preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username) === 1;
}

function kelolaPanjang(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedToken)) {
        kelolaSetFlash('error', 'Sesi formulir tidak valid. Silakan coba lagi.');
        kelolaRedirect();
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_admin') {
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $wilayah = salamNormalisasiAlamatInput($_POST['wilayah'] ?? '');
        if ($password === '') {
            $password = 's0t0kudus';
        }

        if (!kelolaValidUsername($username)) {
            kelolaSetFlash('error', 'Username harus 3–50 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda minus.');
            kelolaRedirect('bagian=tambah');
        }
        if (kelolaPanjang($password) < 6) {
            kelolaSetFlash('error', 'Password minimal 6 karakter.');
            kelolaRedirect('bagian=tambah');
        }
        if ($wilayah === '' || kelolaPanjang($wilayah) > 50) {
            kelolaSetFlash('error', 'Nama wilayah wajib diisi dan maksimal 50 karakter.');
            kelolaRedirect('bagian=tambah');
        }

        $checkUsername = $koneksi->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $checkUsername->bind_param('s', $username);
        $checkUsername->execute();
        $usernameExists = $checkUsername->get_result()->num_rows > 0;
        $checkUsername->close();
        if ($usernameExists) {
            kelolaSetFlash('error', 'Username tersebut sudah digunakan.');
            kelolaRedirect('bagian=tambah');
        }

        $wilayahKey = salamNormalisasiKunci($wilayah);
        $wilayahSql = salamSqlNormalisasiAlamat('wilayah');
        $checkWilayah = $koneksi->prepare("SELECT id FROM users WHERE role = 'admin' AND {$wilayahSql} = ? LIMIT 1");
        $checkWilayah->bind_param('s', $wilayahKey);
        $checkWilayah->execute();
        $wilayahExists = $checkWilayah->get_result()->num_rows > 0;
        $checkWilayah->close();
        if ($wilayahExists) {
            kelolaSetFlash('error', 'Wilayah ' . $wilayah . ' sudah mempunyai akun admin.');
            kelolaRedirect('bagian=tambah');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $role = 'admin';
        $insert = $koneksi->prepare('INSERT INTO users (username, password, role, wilayah) VALUES (?, ?, ?, ?)');
        $insert->bind_param('ssss', $username, $hash, $role, $wilayah);
        $ok = $insert->execute();
        $insert->close();

        if (!$ok) {
            kelolaSetFlash('error', 'Admin gagal ditambahkan. Tidak ada data lama yang diubah.');
            kelolaRedirect('bagian=tambah');
        }

        if (!salamIsWilayahSemua($wilayah)) {
            salamPastikanConfigWilayah($wilayah);
        }
        kelolaSetFlash('success', 'Admin ' . $username . ' untuk wilayah ' . $wilayah . ' berhasil ditambahkan.');
        kelolaRedirect(salamIsWilayahSemua($wilayah) ? '' : 'wilayah=' . rawurlencode($wilayah));
    }

    if ($action === 'edit_admin') {
        $id = (int) ($_POST['id'] ?? 0);
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $wilayah = salamNormalisasiAlamatInput($_POST['wilayah'] ?? '');

        $targetStmt = $koneksi->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
        $targetStmt->bind_param('i', $id);
        $targetStmt->execute();
        $targetExists = $targetStmt->get_result()->num_rows > 0;
        $targetStmt->close();

        if (!$targetExists) {
            kelolaSetFlash('error', 'Data admin tidak ditemukan.');
            kelolaRedirect();
        }
        if (!kelolaValidUsername($username)) {
            kelolaSetFlash('error', 'Username admin tidak valid.');
            kelolaRedirect('edit_admin=' . $id);
        }
        if ($password !== '' && kelolaPanjang($password) < 6) {
            kelolaSetFlash('error', 'Password baru minimal 6 karakter.');
            kelolaRedirect('edit_admin=' . $id);
        }
        if ($wilayah === '' || kelolaPanjang($wilayah) > 50) {
            kelolaSetFlash('error', 'Nama wilayah wajib diisi dan maksimal 50 karakter.');
            kelolaRedirect('edit_admin=' . $id);
        }

        $checkUsername = $koneksi->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
        $checkUsername->bind_param('si', $username, $id);
        $checkUsername->execute();
        $usernameExists = $checkUsername->get_result()->num_rows > 0;
        $checkUsername->close();
        if ($usernameExists) {
            kelolaSetFlash('error', 'Username tersebut sudah digunakan akun lain.');
            kelolaRedirect('edit_admin=' . $id);
        }

        $wilayahKey = salamNormalisasiKunci($wilayah);
        $wilayahSql = salamSqlNormalisasiAlamat('wilayah');
        $checkWilayah = $koneksi->prepare("SELECT id FROM users WHERE role = 'admin' AND {$wilayahSql} = ? AND id <> ? LIMIT 1");
        $checkWilayah->bind_param('si', $wilayahKey, $id);
        $checkWilayah->execute();
        $wilayahExists = $checkWilayah->get_result()->num_rows > 0;
        $checkWilayah->close();
        if ($wilayahExists) {
            kelolaSetFlash('error', 'Wilayah ' . $wilayah . ' sudah digunakan admin lain.');
            kelolaRedirect('edit_admin=' . $id);
        }

        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $update = $koneksi->prepare("UPDATE users SET username = ?, password = ?, wilayah = ? WHERE id = ? AND role = 'admin'");
            $update->bind_param('sssi', $username, $hash, $wilayah, $id);
        } else {
            $update = $koneksi->prepare("UPDATE users SET username = ?, wilayah = ? WHERE id = ? AND role = 'admin'");
            $update->bind_param('ssi', $username, $wilayah, $id);
        }
        $ok = $update->execute();
        $update->close();

        if (!$ok) {
            kelolaSetFlash('error', 'Data admin gagal diperbarui.');
            kelolaRedirect('edit_admin=' . $id);
        }

        if (!salamIsWilayahSemua($wilayah)) {
            salamPastikanConfigWilayah($wilayah);
        }
        kelolaSetFlash('success', 'Data admin berhasil diperbarui.');
        kelolaRedirect(salamIsWilayahSemua($wilayah) ? '' : 'wilayah=' . rawurlencode($wilayah));
    }


    if ($action === 'delete_admin') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            kelolaSetFlash('error', 'ID admin tidak valid.');
            kelolaRedirect();
        }

        $targetStmt = $koneksi->prepare(
            "SELECT username, wilayah FROM users WHERE id = ? AND role = 'admin' LIMIT 1"
        );
        $targetStmt->bind_param('i', $id);
        $targetStmt->execute();
        $targetAdmin = $targetStmt->get_result()->fetch_assoc() ?: null;
        $targetStmt->close();

        if (!$targetAdmin) {
            kelolaSetFlash('error', 'Data admin tidak ditemukan atau akun tersebut bukan admin wilayah.');
            kelolaRedirect();
        }

        $deleteStmt = $koneksi->prepare(
            "DELETE FROM users WHERE id = ? AND role = 'admin'"
        );
        $deleteStmt->bind_param('i', $id);
        $deleteOk = $deleteStmt->execute();
        $deletedRows = $deleteStmt->affected_rows;
        $deleteStmt->close();

        if (!$deleteOk || $deletedRows < 1) {
            kelolaSetFlash('error', 'Akun admin gagal dihapus.');
            kelolaRedirect('edit_admin=' . $id);
        }

        kelolaSetFlash(
            'success',
            'Akun admin ' . (string) $targetAdmin['username']
            . ' berhasil dihapus. Data pelanggan, tagihan, dan config wilayah tetap tersimpan.'
        );
        kelolaRedirect();
    }

    if ($action === 'save_config') {
        $wilayah = salamNormalisasiAlamatInput($_POST['wilayah'] ?? '');
        $namaLayanan = trim((string) ($_POST['nama_layanan'] ?? ''));
        $nomorWhatsAppRaw = trim((string) ($_POST['nomor_whatsapp'] ?? ''));
        $nomorWhatsApp = salamNormalisasiNomorConfig($nomorWhatsAppRaw);
        $catatan = trim((string) ($_POST['catatan_pembayaran'] ?? ''));

        $jenisList = is_array($_POST['metode_jenis'] ?? null)
            ? $_POST['metode_jenis']
            : [];
        $namaList = is_array($_POST['metode_nama'] ?? null)
            ? $_POST['metode_nama']
            : [];
        $nomorList = is_array($_POST['metode_nomor'] ?? null)
            ? $_POST['metode_nomor']
            : [];
        $atasNamaList = is_array($_POST['metode_atas_nama'] ?? null)
            ? $_POST['metode_atas_nama']
            : [];

        if ($wilayah === '') {
            kelolaSetFlash('error', 'Wilayah config tidak valid.');
            kelolaRedirect('bagian=config');
        }
        if ($namaLayanan === '') {
            $namaLayanan = 'Billing ' . $wilayah . ' / UKOOMED';
        }
        if ($nomorWhatsAppRaw !== '' && !preg_match('/^62\d{8,14}$/', $nomorWhatsApp)) {
            kelolaSetFlash('error', 'Nomor WhatsApp tidak valid. Gunakan format 08..., 62..., atau +62....');
            kelolaRedirect('wilayah=' . rawurlencode($wilayah));
        }
        if (kelolaPanjang($namaLayanan) > 150 || kelolaPanjang($catatan) > 1000) {
            kelolaSetFlash('error', 'Nama layanan atau catatan pembayaran terlalu panjang.');
            kelolaRedirect('wilayah=' . rawurlencode($wilayah));
        }

        $jumlahMetode = max(
            count($jenisList),
            count($namaList),
            count($nomorList),
            count($atasNamaList)
        );
        $metodePembayaran = [];

        for ($i = 0; $i < $jumlahMetode; $i++) {
            $jenis = salamNormalisasiJenisMetode($jenisList[$i] ?? 'bank');
            $nama = trim((string) ($namaList[$i] ?? ''));
            $nomor = trim((string) ($nomorList[$i] ?? ''));
            $atasNama = trim((string) ($atasNamaList[$i] ?? ''));

            if ($nama === '' && $nomor === '' && $atasNama === '') {
                continue;
            }

            if ($nama === '' || $nomor === '') {
                kelolaSetFlash(
                    'error',
                    'Setiap metode pembayaran yang diisi wajib memiliki nama bank/e-wallet dan nomor pembayaran.'
                );
                kelolaRedirect('wilayah=' . rawurlencode($wilayah));
            }

            if (
                kelolaPanjang($nama) > 100
                || kelolaPanjang($nomor) > 100
                || kelolaPanjang($atasNama) > 150
            ) {
                kelolaSetFlash('error', 'Salah satu data metode pembayaran terlalu panjang.');
                kelolaRedirect('wilayah=' . rawurlencode($wilayah));
            }

            $metodePembayaran[] = [
                'jenis' => $jenis,
                'nama' => $nama,
                'nomor' => $nomor,
                'atas_nama' => $atasNama,
            ];
        }

        $ok = salamSimpanConfigWilayah($wilayah, [
            'nama_layanan' => $namaLayanan,
            'metode_pembayaran' => $metodePembayaran,
            'nomor_whatsapp' => $nomorWhatsApp,
            'catatan_pembayaran' => $catatan,
        ]);

        if (!$ok) {
            kelolaSetFlash('error', 'Config gagal disimpan. Pastikan file config_wilayah.json dapat ditulis oleh aplikasi.');
            kelolaRedirect('wilayah=' . rawurlencode($wilayah));
        }

        kelolaSetFlash('success', 'Config wilayah ' . $wilayah . ' berhasil diperbarui.');
        kelolaRedirect('wilayah=' . rawurlencode($wilayah));
    }

    kelolaSetFlash('error', 'Perintah tidak dikenali.');
    kelolaRedirect();
}

$flash = $_SESSION['kelola_admin_flash'] ?? null;
unset($_SESSION['kelola_admin_flash']);

$admins = [];
$adminResult = $koneksi->query("SELECT id, username, wilayah FROM users WHERE role = 'admin' ORDER BY wilayah ASC, username ASC");
if ($adminResult) {
    while ($row = $adminResult->fetch_assoc()) {
        $admins[] = $row;
    }
}

$allConfigs = salamSemuaConfigWilayah();
$wilayahOptions = [];
foreach ($allConfigs as $key => $config) {
    $nama = salamNormalisasiAlamatInput($config['wilayah'] ?? $key);
    if ($nama !== '') {
        $wilayahOptions[salamNormalisasiKunci($nama)] = $nama;
    }
}
foreach ($admins as $admin) {
    $nama = salamNormalisasiAlamatInput($admin['wilayah'] ?? '');
    if ($nama !== '' && !salamIsWilayahSemua($nama)) {
        $wilayahOptions[salamNormalisasiKunci($nama)] = $nama;
    }
}
asort($wilayahOptions, SORT_NATURAL | SORT_FLAG_CASE);

$selectedWilayah = salamNormalisasiAlamatInput($_GET['wilayah'] ?? '');
if ($selectedWilayah === '' || !isset($wilayahOptions[salamNormalisasiKunci($selectedWilayah)])) {
    $selectedWilayah = $wilayahOptions[salamNormalisasiKunci('SALAM')] ?? (reset($wilayahOptions) ?: 'SALAM');
}
$selectedKey = salamNormalisasiKunci($selectedWilayah);
$selectedConfig = $allConfigs[$selectedKey] ?? [
    'wilayah' => $selectedWilayah,
    'nama_layanan' => 'Billing ' . $selectedWilayah . ' / UKOOMED',
    'metode_pembayaran' => [],
    'nomor_whatsapp' => '',
    'catatan_pembayaran' => '',
    'info_pembayaran' => 'Konfirmasikan pembayaran kepada admin wilayah ' . $selectedWilayah . '.',
];

$selectedMetodePembayaran = salamNormalisasiMetodePembayaran($selectedConfig);
if ($selectedMetodePembayaran === []) {
    $selectedMetodePembayaran[] = [
        'jenis' => 'bank',
        'nama' => '',
        'nomor' => '',
        'atas_nama' => '',
    ];
}

$editAdmin = null;
$editAdminId = (int) ($_GET['edit_admin'] ?? 0);
if ($editAdminId > 0) {
    $editStmt = $koneksi->prepare("SELECT id, username, wilayah FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
    $editStmt->bind_param('i', $editAdminId);
    $editStmt->execute();
    $editAdmin = $editStmt->get_result()->fetch_assoc() ?: null;
    $editStmt->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Admin & Config - Billing UKOOMED</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="logo_cleon.png" type="image/png">
    <style>
        :root {
            --primary:#2f8fd3;
            --primary-dark:#2475ad;
            --secondary:#203246;
            --success:#22a861;
            --danger:#dc4f45;
            --warning:#e89b26;
            --page:#f3f6f9;
            --surface:#ffffff;
            --soft:#f7f9fb;
            --soft-blue:#eef6fc;
            --muted:#6c7a89;
            --text:#243445;
            --border:#dfe7ee;
            --shadow:0 8px 26px rgba(28,48,70,.08);
        }

        * { box-sizing:border-box; }
        html { scroll-behavior:smooth; }
        body {
            margin:0;
            font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;
            background:var(--page);
            color:var(--text);
            line-height:1.45;
        }

        .header {
            position:sticky;
            top:0;
            z-index:30;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:18px;
            padding:15px 28px;
            color:#fff;
            background:linear-gradient(135deg,var(--secondary),#152333);
            box-shadow:0 4px 16px rgba(0,0,0,.14);
        }
        .header h1 {
            margin:0;
            font-size:22px;
            display:flex;
            align-items:center;
            gap:10px;
        }
        .header-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .top-btn {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:7px;
            border:0;
            border-radius:8px;
            padding:10px 14px;
            color:#fff;
            text-decoration:none;
            font-weight:700;
            transition:.2s ease;
        }
        .top-btn:hover { transform:translateY(-1px); filter:brightness(.97); }
        .btn-dashboard { background:var(--primary); }
        .btn-logout { background:var(--danger); }

        .container { max-width:1180px; margin:0 auto; padding:30px 24px 44px; }
        .page-hero {
            display:flex;
            justify-content:space-between;
            align-items:flex-end;
            gap:20px;
            margin-bottom:24px;
        }
        .page-hero h2 { margin:0 0 7px; color:var(--secondary); font-size:28px; }
        .page-hero p { margin:0; color:var(--muted); max-width:720px; }
        .hero-badge {
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:9px 13px;
            border:1px solid #cfe3f3;
            border-radius:999px;
            background:var(--soft-blue);
            color:var(--primary-dark);
            font-size:13px;
            font-weight:800;
            white-space:nowrap;
        }

        .flash {
            margin:0 0 22px;
            padding:14px 17px;
            border-radius:10px;
            font-weight:700;
        }
        .flash-success { background:#eaf8ef; color:#196c37; border:1px solid #bfe8cc; }
        .flash-error { background:#fff0ee; color:#a92e22; border:1px solid #f3c3bd; }

        .quick-nav {
            position:sticky;
            top:74px;
            z-index:20;
            display:grid;
            grid-template-columns:repeat(3,minmax(0,1fr));
            gap:10px;
            margin:0 0 24px;
            padding:10px;
            border:1px solid rgba(210,221,230,.95);
            border-radius:12px;
            background:rgba(255,255,255,.96);
            box-shadow:0 7px 22px rgba(28,48,70,.09);
            backdrop-filter:blur(8px);
        }
        .quick-nav a {
            display:flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            min-height:43px;
            padding:10px 13px;
            border:1px solid #d8e7f2;
            border-radius:9px;
            color:var(--primary-dark);
            background:var(--soft-blue);
            text-decoration:none;
            font-size:13px;
            font-weight:900;
            text-align:center;
            transition:.18s ease;
        }
        .quick-nav a:hover,
        .quick-nav a:focus-visible {
            color:#fff;
            border-color:var(--primary);
            background:var(--primary);
            transform:translateY(-1px);
            outline:none;
        }

        .page-stack { display:flex; flex-direction:column; gap:24px; }
        .section-card {
            background:var(--surface);
            border:1px solid rgba(210,221,230,.85);
            border-radius:14px;
            box-shadow:var(--shadow);
            overflow:hidden;
        }
        .section-card:target { scroll-margin-top:160px; }
        .section-head {
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:18px;
            padding:21px 25px;
            border-bottom:1px solid var(--border);
            background:linear-gradient(180deg,#fff,#fbfcfd);
        }
        .section-title-wrap { display:flex; align-items:center; gap:14px; min-width:0; }
        .section-icon {
            width:42px;
            height:42px;
            border-radius:11px;
            display:flex;
            align-items:center;
            justify-content:center;
            flex:0 0 auto;
            background:var(--soft-blue);
            color:var(--primary-dark);
            font-size:17px;
        }
        .section-kicker {
            display:block;
            margin-bottom:2px;
            color:var(--primary-dark);
            font-size:11px;
            font-weight:900;
            letter-spacing:.8px;
            text-transform:uppercase;
        }
        .section-head h3 { margin:0; color:var(--secondary); font-size:20px; }
        .section-head p { margin:4px 0 0; color:var(--muted); font-size:13px; }
        .section-body { padding:25px; }

        .field { margin:0; }
        label {
            display:block;
            margin-bottom:7px;
            color:#3a4b5c;
            font-size:13px;
            font-weight:800;
        }
        input, select, textarea {
            width:100%;
            padding:12px 13px;
            border:1px solid var(--border);
            border-radius:9px;
            font:inherit;
            color:var(--text);
            background:#fff;
            outline:none;
            transition:border-color .18s ease, box-shadow .18s ease;
        }
        input:focus, select:focus, textarea:focus {
            border-color:var(--primary);
            box-shadow:0 0 0 3px rgba(47,143,211,.12);
        }
        textarea { min-height:112px; resize:vertical; }
        .hint { display:block; margin-top:6px; color:var(--muted); font-size:12px; }

        .admin-form-grid {
            display:grid;
            grid-template-columns:repeat(3,minmax(0,1fr));
            gap:18px;
            align-items:start;
        }
        .form-actions {
            display:flex;
            justify-content:flex-end;
            gap:10px;
            margin-top:22px;
            padding-top:20px;
            border-top:1px solid var(--border);
            flex-wrap:wrap;
        }

        .btn {
            border:0;
            border-radius:9px;
            padding:11px 16px;
            cursor:pointer;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            font-weight:800;
            font-size:14px;
            transition:.18s ease;
        }
        .btn:hover { transform:translateY(-1px); filter:brightness(.98); }
        .btn-primary { background:var(--primary); color:#fff; }
        .btn-success { background:var(--success); color:#fff; }
        .btn-muted { background:#edf2f6; color:#34485b; }
        .btn-danger-soft { background:#fff0ee; color:#b63b2f; border:1px solid #f1c5bf; }


        .delete-admin-box {
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:18px;
            margin-top:20px;
            padding:17px 18px;
            border:1px solid #f1c5bf;
            border-radius:11px;
            background:#fff7f5;
        }
        .delete-admin-box strong {
            display:block;
            color:#a92e22;
            margin-bottom:3px;
        }
        .delete-admin-box span {
            display:block;
            color:var(--muted);
            font-size:12px;
        }
        .delete-admin-box form {
            flex:0 0 auto;
            margin:0;
        }

        .table-wrap { overflow:auto; }
        table { width:100%; border-collapse:collapse; min-width:720px; }
        th, td { padding:14px 18px; border-bottom:1px solid var(--border); text-align:left; }
        th {
            background:#f7f9fb;
            color:#566574;
            font-size:11px;
            text-transform:uppercase;
            letter-spacing:.55px;
        }
        tbody tr:hover { background:#fbfdff; }
        tbody tr:last-child td { border-bottom:0; }
        .pill {
            display:inline-block;
            padding:5px 10px;
            border-radius:999px;
            font-size:11px;
            font-weight:900;
            background:#e9f4fb;
            color:#2477aa;
        }
        .empty { color:var(--muted); text-align:center; padding:28px; }
        .table-number { color:var(--muted); font-weight:700; width:72px; }

        .config-selector {
            display:block;
            padding:18px;
            margin-bottom:22px;
            border:1px solid #d8e7f2;
            border-radius:12px;
            background:var(--soft-blue);
        }
        .config-selector-row {
            display:grid;
            grid-template-columns:minmax(0,1fr) auto;
            gap:16px;
            align-items:stretch;
        }
        .config-selector-row select {
            min-height:44px;
        }
        .selected-area {
            display:flex;
            align-items:center;
            justify-content:center;
            gap:9px;
            min-height:44px;
            padding:10px 18px;
            border-radius:9px;
            color:#fff;
            background:var(--primary-dark);
            font-weight:900;
            white-space:nowrap;
        }

        .config-block {
            padding:22px;
            border:1px solid var(--border);
            border-radius:12px;
            background:#fff;
        }
        .config-block + .config-block { margin-top:18px; }
        .config-block-head {
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:16px;
            margin-bottom:18px;
        }
        .config-block-title { display:flex; gap:11px; align-items:flex-start; }
        .config-step {
            width:29px;
            height:29px;
            border-radius:8px;
            display:flex;
            align-items:center;
            justify-content:center;
            flex:0 0 auto;
            background:var(--soft-blue);
            color:var(--primary-dark);
            font-size:12px;
            font-weight:900;
        }
        .config-block h4 { margin:0; color:var(--secondary); font-size:16px; }
        .config-block p { margin:4px 0 0; color:var(--muted); font-size:12px; }
        .config-two-column {
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:18px;
        }

        .payment-list { display:flex; flex-direction:column; gap:14px; }
        .payment-row {
            position:relative;
            padding:18px;
            border:1px solid #dbe5ed;
            border-radius:12px;
            background:var(--soft);
        }
        .payment-row-head {
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            margin-bottom:14px;
        }
        .payment-row-title {
            display:flex;
            align-items:center;
            gap:9px;
            color:var(--secondary);
            font-size:13px;
            font-weight:900;
        }
        .payment-index {
            width:26px;
            height:26px;
            border-radius:8px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            background:#dceefb;
            color:var(--primary-dark);
            font-size:12px;
        }
        .payment-grid {
            display:grid;
            grid-template-columns:.8fr 1.15fr 1.25fr 1.15fr;
            gap:14px;
            align-items:end;
        }
        .payment-remove { padding:8px 11px; }
        .payment-empty-note {
            color:var(--muted);
            font-size:12px;
            margin-top:10px;
        }

        .preview {
            white-space:pre-wrap;
            background:#f7fafc;
            border:1px dashed #b9cddd;
            border-radius:10px;
            padding:17px;
            color:#405365;
            font-size:13px;
            line-height:1.65;
            min-height:100px;
        }
        .save-bar {
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:16px;
            margin-top:20px;
            padding:17px 19px;
            border-radius:12px;
            background:#f0f7fc;
            border:1px solid #d5e8f6;
        }
        .save-bar strong { color:var(--secondary); }
        .save-bar span { display:block; margin-top:2px; color:var(--muted); font-size:12px; }

        @media (max-width:900px) {
            .container { padding:22px 15px 34px; }
            .page-hero { align-items:flex-start; flex-direction:column; }
            .admin-form-grid, .config-two-column, .payment-grid { grid-template-columns:1fr 1fr; }
            .config-selector-row { grid-template-columns:1fr; }
            .selected-area { width:100%; }
        }
        @media (max-width:620px) {
            .header { align-items:flex-start; flex-direction:column; padding:13px 15px; }
            .quick-nav {
                top:121px;
                display:grid;
                grid-template-columns:1fr;
                gap:10px;
                padding:10px;
                overflow:visible;
            }
            .quick-nav a {
                min-width:0;
                width:100%;
                justify-content:flex-start;
                text-align:left;
                padding:14px 16px;
            }
            .section-card:target { scroll-margin-top:198px; }
            .header h1 { font-size:18px; }
            .header-actions { width:100%; }
            .top-btn { flex:1; }
            .page-hero h2 { font-size:24px; }
            .section-head { align-items:flex-start; padding:18px; }
            .section-body { padding:18px; }
            .admin-form-grid, .config-two-column, .payment-grid { grid-template-columns:1fr; }
            .config-block { padding:17px; }
            .config-block-head { flex-direction:column; }
            .form-actions, .save-bar { align-items:stretch; flex-direction:column; }
            .form-actions .btn, .save-bar .btn { width:100%; }
            .delete-admin-box {
                align-items:stretch;
                flex-direction:column;
            }
            .delete-admin-box form,
            .delete-admin-box .btn {
                width:100%;
            }

            .table-wrap { overflow:visible; }
            table { min-width:0; width:100%; border-collapse:separate; border-spacing:0; }
            thead { display:none; }
            tbody { display:flex; flex-direction:column; gap:14px; padding:16px; }
            tbody tr {
                display:block;
                border:1px solid var(--border);
                border-radius:14px;
                background:#fff;
                box-shadow:0 6px 18px rgba(28,48,70,.07);
                overflow:hidden;
            }
            tbody td {
                display:grid;
                grid-template-columns:110px 1fr;
                gap:12px;
                align-items:center;
                width:100%;
                padding:13px 16px;
                border-bottom:1px solid var(--border);
                text-align:left;
            }
            tbody td:last-child { border-bottom:0; }
            tbody td::before {
                content:attr(data-label);
                color:var(--muted);
                font-size:11px;
                font-weight:900;
                text-transform:uppercase;
                letter-spacing:.45px;
            }
            .table-number { width:auto; }
            .table-actions { display:flex; justify-content:flex-start; }
            .table-actions .btn { width:100%; }
        }

        /* =========================================================
           MOBILE: daftar admin menjadi kartu, tanpa scroll horizontal
           ========================================================= */
        @media (max-width:800px) {
            body {
                overflow-x:hidden;
            }

            #daftar-admin,
            #daftar-admin .table-wrap {
                width:100%;
                max-width:100%;
                overflow-x:hidden !important;
            }

            #daftar-admin .table-wrap {
                padding:14px;
                background:var(--page);
            }

            #daftar-admin .admin-table,
            #daftar-admin .admin-table tbody,
            #daftar-admin .admin-table tr,
            #daftar-admin .admin-table td {
                display:block;
                width:100%;
                min-width:0 !important;
                max-width:100%;
            }

            #daftar-admin .admin-table {
                border-collapse:separate;
                border-spacing:0;
            }

            #daftar-admin .admin-table thead {
                display:none;
            }

            #daftar-admin .admin-table tbody {
                display:flex;
                flex-direction:column;
                gap:14px;
                padding:0;
            }

            #daftar-admin .admin-table tbody tr {
                overflow:hidden;
                border:1px solid var(--border);
                border-radius:13px;
                background:#fff;
                box-shadow:0 5px 16px rgba(28,48,70,.07);
            }

            #daftar-admin .admin-table tbody td {
                display:grid;
                grid-template-columns:100px minmax(0,1fr);
                align-items:center;
                gap:12px;
                padding:13px 14px;
                border-bottom:1px solid var(--border);
                text-align:right;
                overflow-wrap:anywhere;
            }

            #daftar-admin .admin-table tbody td:last-child {
                border-bottom:0;
            }

            #daftar-admin .admin-table tbody td::before {
                content:attr(data-label);
                color:var(--secondary);
                font-size:12px;
                font-weight:800;
                text-align:left;
                text-transform:none;
                letter-spacing:0;
            }

            #daftar-admin .admin-table .table-number {
                width:100%;
                color:var(--text);
            }

            #daftar-admin .admin-table td strong {
                justify-self:end;
                max-width:100%;
                text-align:right;
                word-break:break-word;
            }

            #daftar-admin .admin-table .pill {
                justify-self:end;
            }

            #daftar-admin .admin-table .table-actions {
                display:grid;
                grid-template-columns:100px minmax(0,1fr);
                justify-content:initial;
            }

            #daftar-admin .admin-table .table-actions::before {
                align-self:center;
            }

            #daftar-admin .admin-table .table-actions .btn {
                justify-self:end;
                width:auto;
                max-width:100%;
                min-height:40px;
                padding:9px 13px;
                white-space:normal;
            }

            #daftar-admin .empty {
                display:block !important;
                padding:22px 16px !important;
                text-align:center !important;
            }

            #daftar-admin .empty::before {
                display:none;
            }
        }

        @media (max-width:420px) {
            #daftar-admin .table-wrap {
                padding:10px;
            }

            #daftar-admin .admin-table tbody td,
            #daftar-admin .admin-table .table-actions {
                grid-template-columns:88px minmax(0,1fr);
                gap:10px;
                padding:12px;
            }

            #daftar-admin .admin-table .table-actions .btn {
                padding:8px 11px;
                font-size:13px;
            }
        }

    </style>
</head>
<body>
<header class="header">
    <h1><i class="fas fa-user-gear"></i> Kelola Admin & Config</h1>
    <div class="header-actions">
        <a class="top-btn btn-dashboard" href="dashboard_salam.php"><i class="fas fa-arrow-left"></i> Dashboard</a>
        <a class="top-btn btn-logout" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</header>

<main class="container">
    <section class="page-hero">
        <div>
            <h2>Pengaturan Super Admin</h2>
        </div>
        <div class="hero-badge"><i class="fas fa-users"></i> <?= count($admins); ?> Admin Wilayah</div>
    </section>

    <?php if (is_array($flash)): ?>
        <div class="flash <?= ($flash['type'] ?? '') === 'success' ? 'flash-success' : 'flash-error'; ?>">
            <?= kelolaEsc($flash['message'] ?? ''); ?>
        </div>
    <?php endif; ?>

    <nav class="quick-nav" aria-label="Navigasi cepat halaman">
        <a href="#tambah-admin"><i class="fas fa-user-plus"></i> Tambah Admin</a>
        <a href="#daftar-admin"><i class="fas fa-users-gear"></i> Daftar Admin</a>
        <a href="#config-wilayah"><i class="fas fa-wallet"></i> Pembayaran &amp; WhatsApp</a>
    </nav>

    <div class="page-stack">
        <section class="section-card" id="tambah-admin">
            <div class="section-head">
                <div class="section-title-wrap">
                    <div class="section-icon"><i class="fas fa-user-plus"></i></div>
                    <div>
                        <span class="section-kicker">Bagian 1</span>
                        <h3>Tambah Admin Wilayah</h3>
                        <p>Buat satu akun admin untuk satu wilayah baru.</p>
                    </div>
                </div>
            </div>
            <div class="section-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= kelolaEsc($csrfToken); ?>">
                    <input type="hidden" name="action" value="add_admin">
                    <div class="admin-form-grid">
                        <div class="field">
                            <label for="username">Username Admin</label>
                            <input id="username" name="username" type="text" required maxlength="50" placeholder="contoh: adminpengkok">
                           
                        </div>
                        <div class="field">
                            <label for="password">Password Awal</label>
                            <input id="password" name="password" type="text" required value="s0t0kudus">
    
                        </div>
                        <div class="field">
                            <label for="wilayah">Wilayah Admin</label>
                            <input id="wilayah" name="wilayah" type="text" required maxlength="50" placeholder="contoh: PENGKOK">
                            
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-success" type="submit"><i class="fas fa-save"></i> Tambah Admin Wilayah</button>
                    </div>
                </form>
            </div>
        </section>

        <?php if ($editAdmin): ?>
        <section class="section-card" id="edit-admin">
            <div class="section-head">
                <div class="section-title-wrap">
                    <div class="section-icon"><i class="fas fa-user-pen"></i></div>
                    <div>
                        <span class="section-kicker">Edit Akun</span>
                        <h3>Edit Admin Wilayah</h3>
                        <p>Ubah username, wilayah, atau password admin yang dipilih.</p>
                    </div>
                </div>
            </div>
            <div class="section-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= kelolaEsc($csrfToken); ?>">
                    <input type="hidden" name="action" value="edit_admin">
                    <input type="hidden" name="id" value="<?= (int) $editAdmin['id']; ?>">
                    <div class="admin-form-grid">
                        <div class="field">
                            <label for="edit_username">Username Admin</label>
                            <input id="edit_username" name="username" type="text" required maxlength="50" value="<?= kelolaEsc($editAdmin['username']); ?>">
                        </div>
                        <div class="field">
                            <label for="edit_password">Password Baru</label>
                            <input id="edit_password" name="password" type="text" placeholder="Kosongkan jika tidak diubah">
                            <span class="hint">Biarkan kosong apabila password lama tetap digunakan.</span>
                        </div>
                        <div class="field">
                            <label for="edit_wilayah">Wilayah Admin</label>
                            <input id="edit_wilayah" name="wilayah" type="text" required maxlength="50" value="<?= kelolaEsc(salamNamaWilayahTampilan($editAdmin['wilayah'])); ?>">
                        </div>
                    </div>
                    <div class="form-actions">
                        <a class="btn btn-muted" href="kelola_admin.php"><i class="fas fa-xmark"></i> Batal</a>
                        <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Simpan Perubahan</button>
                    </div>
                </form>

                <div class="delete-admin-box">
                    <div>
                        <strong>Hapus Admin Wilayah</strong>
                        <span>Akun login akan dihapus, tetapi data pelanggan, tagihan, dan config wilayah tetap tersimpan.</span>
                    </div>

                    <form
                        method="post"
                        onsubmit="return confirm('Yakin ingin menghapus admin <?= kelolaEsc($editAdmin['username']); ?>? Akun ini tidak dapat digunakan untuk login lagi.');"
                    >
                        <input type="hidden" name="csrf_token" value="<?= kelolaEsc($csrfToken); ?>">
                        <input type="hidden" name="action" value="delete_admin">
                        <input type="hidden" name="id" value="<?= (int) $editAdmin['id']; ?>">

                        <button class="btn btn-danger-soft" type="submit">
                            <i class="fas fa-trash"></i> Hapus Admin
                        </button>
                    </form>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="section-card" id="daftar-admin">
            <div class="section-head">
                <div class="section-title-wrap">
                    <div class="section-icon"><i class="fas fa-users-gear"></i></div>
                    <div>
                        <span class="section-kicker">Bagian 2</span>
                        <h3>Daftar Admin Wilayah</h3>
                        
                    </div>
                </div>
                <div class="hero-badge"><?= count($admins); ?> akun</div>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr><th>No</th><th>Username</th><th>Wilayah</th><th>Aksi</th></tr>
                    </thead>
                    <tbody>
                    <?php if ($admins === []): ?>
                        <tr><td colspan="4" class="empty">Belum ada akun admin wilayah.</td></tr>
                    <?php else: ?>
                        <?php foreach ($admins as $index => $admin): ?>
                        <tr>
                            <td class="table-number" data-label="No"><?= $index + 1; ?></td>
                            <td data-label="Username"><strong><?= kelolaEsc($admin['username']); ?></strong></td>
                            <td data-label="Wilayah"><span class="pill"><?= kelolaEsc(salamNamaWilayahTampilan($admin['wilayah'])); ?></span></td>
                            <td class="table-actions" data-label="Aksi"><a class="btn btn-primary" href="kelola_admin.php?edit_admin=<?= (int) $admin['id']; ?>#edit-admin"><i class="fas fa-pen"></i> Edit Admin</a></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="section-card" id="config-wilayah">
            <div class="section-head">
                <div class="section-title-wrap">
                    <div class="section-icon"><i class="fas fa-sliders"></i></div>
                    <div>
                        <span class="section-kicker">Bagian 3</span>
                        <h3>Pengaturan Pembayaran & WhatsApp Wilayah</h3>
                        
                    </div>
                </div>
            </div>
            <div class="section-body">
                <form method="get" action="kelola_admin.php" class="config-selector">
                    <div class="field">
                        <label for="pilih_wilayah">Pilih Wilayah yang Akan Diatur</label>
                        <div class="config-selector-row">
                            <select id="pilih_wilayah" name="wilayah" onchange="this.form.submit()">
                                <?php foreach ($wilayahOptions as $option): ?>
                                    <option value="<?= kelolaEsc($option); ?>" <?= salamNormalisasiKunci($option) === $selectedKey ? 'selected' : ''; ?>><?= kelolaEsc($option); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="selected-area"><i class="fas fa-location-dot"></i> <?= kelolaEsc($selectedWilayah); ?></div>
                        </div>
                        <span class="hint">Setiap wilayah memiliki rekening, e-wallet, dan WhatsApp admin sendiri.</span>
                    </div>
                </form>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= kelolaEsc($csrfToken); ?>">
                    <input type="hidden" name="action" value="save_config">
                    <input type="hidden" name="wilayah" value="<?= kelolaEsc($selectedWilayah); ?>">

                    <div class="config-block">
                        <div class="config-block-head">
                            <div class="config-block-title">
                                <div class="config-step">1</div>
                                <div>
                                    <h4>Identitas Layanan</h4>
                                    <p>Nama layanan yang akan ditampilkan untuk wilayah ini.</p>
                                </div>
                            </div>
                        </div>
                        <div class="field">
                            <label for="nama_layanan">Nama Layanan</label>
                            <input id="nama_layanan" name="nama_layanan" type="text" maxlength="150" value="<?= kelolaEsc($selectedConfig['nama_layanan'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="config-block">
                        <div class="config-block-head">
                            <div class="config-block-title">
                                <div class="config-step">2</div>
                                <div>
                                    <h4>Metode Pembayaran</h4>
                                    
                                </div>
                            </div>
                            <button class="btn btn-muted" id="tambah_metode" type="button">
                                <i class="fas fa-plus"></i> Tambah Metode
                            </button>
                        </div>

                        <div class="payment-list" id="metode_pembayaran_list">
                            <?php foreach ($selectedMetodePembayaran as $index => $metode): ?>
                                <?php
                                $jenisMetode = salamNormalisasiJenisMetode($metode['jenis'] ?? 'bank');
                                $isEwallet = $jenisMetode === 'ewallet';
                                ?>
                                <div class="payment-row" data-payment-row>
                                    <div class="payment-row-head">
                                        <div class="payment-row-title">
                                            <span class="payment-index" data-payment-index><?= $index + 1; ?></span>
                                            <span data-payment-title>Metode Pembayaran <?= $index + 1; ?></span>
                                        </div>
                                        <button class="btn btn-danger-soft payment-remove" type="button" data-remove-method title="Hapus metode pembayaran">
                                            <i class="fas fa-trash"></i> Hapus
                                        </button>
                                    </div>
                                    <div class="payment-grid">
                                        <div class="field">
                                            <label>Jenis Pembayaran</label>
                                            <select name="metode_jenis[]" data-method-type>
                                                <option value="bank" <?= !$isEwallet ? 'selected' : ''; ?>>Bank</option>
                                                <option value="ewallet" <?= $isEwallet ? 'selected' : ''; ?>>E-Wallet</option>
                                            </select>
                                        </div>
                                        <div class="field">
                                            <label data-name-label><?= $isEwallet ? 'Nama E-Wallet' : 'Nama Bank'; ?></label>
                                            <input name="metode_nama[]" type="text" maxlength="100" data-method-name placeholder="<?= $isEwallet ? 'contoh: DANA' : 'contoh: BCA'; ?>" value="<?= kelolaEsc($metode['nama'] ?? ''); ?>">
                                        </div>
                                        <div class="field">
                                            <label data-number-label><?= $isEwallet ? 'Nomor E-Wallet' : 'Nomor Rekening'; ?></label>
                                            <input name="metode_nomor[]" type="text" maxlength="100" data-method-number placeholder="<?= $isEwallet ? 'contoh: 081234567890' : 'Masukkan nomor rekening'; ?>" value="<?= kelolaEsc($metode['nomor'] ?? ''); ?>">
                                        </div>
                                        <div class="field">
                                            <label>Atas Nama</label>
                                            <input name="metode_atas_nama[]" type="text" maxlength="150" placeholder="Nama pemilik" value="<?= kelolaEsc($metode['atas_nama'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                    </div>

                    <div class="config-block">
                        <div class="config-block-head">
                            <div class="config-block-title">
                                <div class="config-step">3</div>
                                <div>
                                    <h4>Kontak Admin & Catatan</h4>
                                    
                                </div>
                            </div>
                        </div>
                        <div class="config-two-column">
                            <div class="field">
                                <label for="nomor_whatsapp">WhatsApp Admin</label>
                                <input id="nomor_whatsapp" name="nomor_whatsapp" type="text" maxlength="20" placeholder="08... atau 62..." value="<?= kelolaEsc($selectedConfig['nomor_whatsapp'] ?? ''); ?>">
                                
                            </div>
                            <div class="field">
                                <label for="catatan_pembayaran">Catatan Pembayaran Tambahan</label>
                                <textarea id="catatan_pembayaran" name="catatan_pembayaran" maxlength="1000" placeholder="Boleh dikosongkan"><?= kelolaEsc($selectedConfig['catatan_pembayaran'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="config-block">
                        <div class="config-block-head">
                            <div class="config-block-title">
                                <div class="config-step">4</div>
                                <div>
                                    <h4>Preview Informasi Pembayaran</h4>
                                    <p>Preview menampilkan konfigurasi yang saat ini tersimpan.</p>
                                </div>
                            </div>
                        </div>
                        <div class="preview"><?= kelolaEsc($selectedConfig['info_pembayaran'] ?? ''); ?></div>
                    </div>

                    <div class="save-bar">
                        <div>
                            <strong>Simpan konfigurasi wilayah <?= kelolaEsc($selectedWilayah); ?></strong>
                            <span>Perubahan hanya berlaku untuk wilayah yang sedang dipilih.</span>
                        </div>
                        <button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk"></i> Simpan Config <?= kelolaEsc($selectedWilayah); ?></button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</main>

<template id="template_metode_pembayaran">
    <div class="payment-row" data-payment-row>
        <div class="payment-row-head">
            <div class="payment-row-title">
                <span class="payment-index" data-payment-index>1</span>
                <span data-payment-title>Metode Pembayaran 1</span>
            </div>
            <button class="btn btn-danger-soft payment-remove" type="button" data-remove-method title="Hapus metode pembayaran">
                <i class="fas fa-trash"></i> Hapus
            </button>
        </div>
        <div class="payment-grid">
            <div class="field">
                <label>Jenis Pembayaran</label>
                <select name="metode_jenis[]" data-method-type>
                    <option value="bank" selected>Bank</option>
                    <option value="ewallet">E-Wallet</option>
                </select>
            </div>
            <div class="field">
                <label data-name-label>Nama Bank</label>
                <input name="metode_nama[]" type="text" maxlength="100" data-method-name placeholder="contoh: BCA">
            </div>
            <div class="field">
                <label data-number-label>Nomor Rekening</label>
                <input name="metode_nomor[]" type="text" maxlength="100" data-method-number placeholder="Masukkan nomor rekening">
            </div>
            <div class="field">
                <label>Atas Nama</label>
                <input name="metode_atas_nama[]" type="text" maxlength="150" placeholder="Nama pemilik">
            </div>
        </div>
    </div>
</template>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const list = document.getElementById('metode_pembayaran_list');
    const addButton = document.getElementById('tambah_metode');
    const template = document.getElementById('template_metode_pembayaran');

    if (!list || !addButton || !template) {
        return;
    }

    function updateRowNumbers() {
        list.querySelectorAll('[data-payment-row]').forEach(function (row, index) {
            const number = index + 1;
            const numberBadge = row.querySelector('[data-payment-index]');
            const title = row.querySelector('[data-payment-title]');
            if (numberBadge) numberBadge.textContent = String(number);
            if (title) title.textContent = 'Metode Pembayaran ' + number;
        });
    }

    function updateMethodLabels(row) {
        const typeSelect = row.querySelector('[data-method-type]');
        const nameLabel = row.querySelector('[data-name-label]');
        const numberLabel = row.querySelector('[data-number-label]');
        const nameInput = row.querySelector('[data-method-name]');
        const numberInput = row.querySelector('[data-method-number]');

        if (!typeSelect || !nameLabel || !numberLabel || !nameInput || !numberInput) {
            return;
        }

        const isEwallet = typeSelect.value === 'ewallet';
        nameLabel.textContent = isEwallet ? 'Nama E-Wallet' : 'Nama Bank';
        numberLabel.textContent = isEwallet ? 'Nomor E-Wallet' : 'Nomor Rekening';
        nameInput.placeholder = isEwallet ? 'contoh: DANA' : 'contoh: BCA';
        numberInput.placeholder = isEwallet ? 'contoh: 081234567890' : 'Masukkan nomor rekening';
    }

    function addMethodRow() {
        const fragment = template.content.cloneNode(true);
        const newRow = fragment.querySelector('[data-payment-row]');
        list.appendChild(fragment);
        updateRowNumbers();

        if (newRow) {
            updateMethodLabels(newRow);
            const firstInput = newRow.querySelector('[data-method-name]');
            if (firstInput) firstInput.focus();
        }
    }

    addButton.addEventListener('click', addMethodRow);

    list.addEventListener('change', function (event) {
        const typeSelect = event.target.closest('[data-method-type]');
        if (!typeSelect) return;
        const row = typeSelect.closest('[data-payment-row]');
        if (row) updateMethodLabels(row);
    });

    list.addEventListener('click', function (event) {
        const removeButton = event.target.closest('[data-remove-method]');
        if (!removeButton) return;

        const row = removeButton.closest('[data-payment-row]');
        if (row) row.remove();

        if (list.querySelectorAll('[data-payment-row]').length === 0) {
            addMethodRow();
        } else {
            updateRowNumbers();
        }
    });

    list.querySelectorAll('[data-payment-row]').forEach(updateMethodLabels);
    updateRowNumbers();
});
</script>
</body>
</html>