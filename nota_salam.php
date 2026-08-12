<?php
date_default_timezone_set('Asia/Jakarta');


session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
salamRequireLogin();

/* Ukuran thermal yang sama seperti file sumber: 57 mm, 58 mm, dan 80 mm. */
$thermalDipilih = isset($_GET['thermal']) ? (int) $_GET['thermal'] : 80;
$daftarUkuranThermal = [57, 58, 80];
$ukuranThermal = in_array($thermalDipilih, $daftarUkuranThermal, true) ? $thermalDipilih : 80;
$lebarKertas = $ukuranThermal . 'mm';
$lebarIsi = match ($ukuranThermal) {57 => '51mm', 58 => '52mm', default => '74mm'};
$modeCetak = (isset($_GET['mode']) && $_GET['mode'] === 'a4') ? 'a4' : 'thermal';
$cetakOtomatis = isset($_GET['autoprint']) && $_GET['autoprint'] === '1';
$ukuranHalaman = $modeCetak === 'a4' ? 'A4 portrait' : $lebarKertas . ' auto';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) exit('ID nota tidak valid.');
$data = salamFindPelangganById($koneksi, $id);
if (!$data) exit('Data pelanggan tidak ditemukan atau bukan wilayah akun ini.');

$isLunas = ($data['status_bayar'] ?? '') === 'Lunas';
$judulNota = $isLunas ? 'BUKTI PEMBAYARAN' : 'NOTA TAGIHAN';
$subjudul = $isLunas ? 'Bukti pembayaran pelanggan' : 'Informasi tagihan pelanggan';
$nominal = $isLunas ? (float) ($data['nominal_dibayar'] ?? $data['tarif_langganan'] ?? 0) : (float) ($data['tagihan'] ?? 0);
$nomorNota = !empty($data['nomor_invoice']) ? (string) $data['nomor_invoice'] : salamKodeWilayah($data['alamat'] ?? '') . '-' . date('Ym') . '-' . str_pad((string)$data['id'],5,'0',STR_PAD_LEFT);
$tanggalCetak = salamTanggalWaktuIndonesia();
$periodeTagihan = salamBulananIndonesia($data['waktu'] ?? null, false);
$masaAktif = salamBulananIndonesia($data['langganan_selesai'] ?? null, true);
$tanggalBayar = $isLunas ? salamBulananIndonesia($data['tanggal_bayar'] ?? null, true) : '-';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $modeCetak === 'a4' ? 'Nota A4' : 'Nota Tagihan - ' . htmlspecialchars($nomorNota); ?></title>
    <style>
        :root {
            --paper-width: <?= htmlspecialchars($lebarKertas); ?>;
            --content-width: <?= htmlspecialchars($lebarIsi); ?>;
        }

        @page { size: <?= htmlspecialchars($ukuranHalaman); ?>; margin: <?= $modeCetak === 'a4' ? '10mm' : '0'; ?>; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #eef2f7;
            font-family: Arial, Helvetica, sans-serif;
            color: #111;
        }

        .toolbar {
            width: <?= $modeCetak === 'a4' ? 'min(100% - 28px, 780px)' : 'min(100% - 24px, var(--content-width))'; ?>;
            margin: 14px auto 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            justify-content: center;
        }
        .toolbar a,
        .toolbar button,
        .toolbar select {
            min-height: 38px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
        }
        .toolbar a,
        .toolbar button {
            border: 0;
            padding: 0 12px;
            color: #fff;
            text-align: center;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
        }
        .toolbar select {
            min-width: 128px;
            padding: 0 9px;
            border: 1px solid #cfd8e3;
            color: #1f2937;
            background: #fff;
            cursor: pointer;
        }

        /* Perbaikan: isi tombol Tutup dibuat benar-benar tepat di tengah. */
        .toolbar .btn-close {
            background: #4b5563;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 44px;
            min-height: 44px;
            line-height: 1;
            vertical-align: middle;
        }

        .toolbar .btn-thermal { background: #1d7fb8; }
        .toolbar .btn-a4 { background: #27ae60; }

        .nota {
            width: var(--content-width);
            margin: 0 auto 22px;
            background: #fff;
            padding: 3.5mm 3mm;
            box-shadow: 0 6px 22px rgba(15, 23, 42, .16);
        }

        .thermal-header {
            display: flex;
            align-items: center;
            gap: 7px;
            padding-bottom: 7px;
            border-bottom: 1px dashed #111;
        }
        .logo-sims {
            display: block;
            width: 23mm;
            max-width: 36%;
            height: auto;
            object-fit: contain;
            object-position: left center;
            flex: 0 0 auto;
        }
        .header-info {
            min-width: 0;
            text-align: left;
            flex: 1;
        }
        .brand-name {
            font-size: 14px;
            line-height: 1.1;
            font-weight: 800;
            letter-spacing: .25px;
        }
        .subtitle {
            margin-top: 2px;
            font-size: 9px;
            line-height: 1.3;
        }

        .line { border-top: 1px dashed #111; margin: 7px 0; }
        table { border-collapse: collapse; width: 100%; font-size: 9px; }
        td { padding: 2.2px 0; vertical-align: top; overflow-wrap: anywhere; }
        td:first-child { width: 39%; color: #374151; }
        .total { font-size: 12px; font-weight: 800; }
        .status { display: inline-block; border: 1px solid #111; padding: 2px 5px; font-weight: 700; font-size: 8.5px; }
        .foot {
            margin: 8px 0 0;
            font-size: 8.5px;
            line-height: 1.35;
            text-align: center;
        }
        .foot-line { margin: 0; }
        .support-sims {
            margin-top: 3px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            font-size: 7.8px;
            line-height: 1;
            color: #374151;
            text-align: center;
            vertical-align: middle;
        }
        .support-sims span { white-space: nowrap; }
        .support-logo-sims {
            display: inline-block;
            width: 11mm;
            max-width: 34%;
            height: auto;
            object-fit: contain;
            vertical-align: middle;
        }

        /*
         | MODE PDF / A4
         | Hanya mengatur tampilan nota A4. CSS thermal di atas dan di bawah
         | tidak diubah, sehingga ukuran 57 / 58 / 80 mm tetap sama.
         */
        body.mode-a4 .nota {
            width: 102mm;
            margin: 0 auto 20px;
            padding: 7mm;
            border: .35mm solid #111;
            box-shadow: 0 6px 18px rgba(15, 23, 42, .12);
        }
        body.mode-a4 .thermal-header { gap: 9px; padding-bottom: 8px; }
        body.mode-a4 .logo-sims { width: 29mm; }
        body.mode-a4 .brand-name { font-size: 17px; }
        body.mode-a4 .subtitle { font-size: 11px; }
        body.mode-a4 .line { margin: 8px 0; }
        body.mode-a4 table { font-size: 11px; }
        body.mode-a4 td { padding: 3px 0; }
        body.mode-a4 .total { font-size: 14px; }
        body.mode-a4 .status { font-size: 10px; }
        body.mode-a4 .foot { font-size: 10px; }
        body.mode-a4 .support-sims { font-size: 8.8px; gap: 4px; margin-top: 4px; }
        body.mode-a4 .support-logo-sims { width: 14mm; }

        @media print {
            .toolbar { display: none !important; }
            body.mode-thermal {
                width: var(--paper-width);
                min-width: var(--paper-width);
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
            }
            body.mode-thermal .nota {
                width: var(--paper-width);
                margin: 0;
                padding: 3mm;
                box-shadow: none;
            }
            /* Printer thermal umumnya monokrom. Logo disederhanakan saat cetak. */
            body.mode-thermal .logo-sims,
            body.mode-thermal .support-logo-sims {
                filter: grayscale(100%) contrast(190%);
            }
            /* A4: nota kecil, rapi, berada di pojok kiri atas area cetak. */
            body.mode-a4 {
                width: auto;
                min-width: 0;
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
            }
            body.mode-a4 .nota {
                width: 102mm;
                margin: 0;
                padding: 7mm;
                border: .35mm solid #111;
                box-shadow: none;
            }
        }
    </style>
</head>
<body class="mode-<?= htmlspecialchars($modeCetak); ?>">
    <div class="toolbar">
        <a class="btn-close" href="dashboard_salam.php">← Tutup</a>
        <select id="thermal-size" aria-label="Ukuran kertas thermal">
            <option value="57" <?= $ukuranThermal === 57 ? 'selected' : ''; ?>>Thermal 57 mm</option>
            <option value="58" <?= $ukuranThermal === 58 ? 'selected' : ''; ?>>Thermal 58 mm</option>
            <option value="80" <?= $ukuranThermal === 80 ? 'selected' : ''; ?>>Thermal 80 mm</option>
        </select>
        <button class="btn-thermal" type="button" onclick="cetakThermal()">🖨 Cetak Thermal</button>
        <button class="btn-a4" type="button" onclick="cetakA4()">PDF / A4</button>
    </div>

    <main class="nota">
        <header class="thermal-header">
            <img class="logo-sims" src="logo_JDN.jpeg" alt="Logo SIMS">
            <div class="header-info">
                <div class="brand-name"><?= htmlspecialchars($judulNota); ?></div>
                <div class="subtitle"><?= htmlspecialchars($subjudul); ?></div>
            </div>
        </header>

        <div class="line"></div>
        <table>
            <tr><td>No. Nota</td><td>: <?= htmlspecialchars($nomorNota); ?></td></tr>
            <tr><td>Tanggal Cetak Nota</td><td>: <?= htmlspecialchars($tanggalCetak); ?></td></tr>
            <tr><td>Periode Tagihan</td><td>: <?= htmlspecialchars($periodeTagihan); ?></td></tr>
            <?php if ($isLunas): ?><tr><td>Tanggal Bayar</td><td>: <?= htmlspecialchars($tanggalBayar); ?></td></tr><?php endif; ?>
            <tr><td>ID Pelanggan</td><td>: <?= htmlspecialchars((string)($data['id_pelanggan'] ?? '-')); ?></td></tr>
            <?php if (!empty($data['kode_pelanggan'])): ?><tr><td>Kode Pelanggan</td><td>: <?= htmlspecialchars((string)$data['kode_pelanggan']); ?></td></tr><?php endif; ?>
            <tr><td>Nama</td><td>: <?= htmlspecialchars((string)($data['nama'] ?? '-')); ?></td></tr>
            <tr><td>Alamat</td><td>: <?= htmlspecialchars((string)(!empty($data['alamat']) ? $data['alamat'] : '-')); ?></td></tr>
            <?php if (!empty($data['nomor_pelanggan'])): ?><tr><td>No. WhatsApp</td><td>: <?= htmlspecialchars((string)$data['nomor_pelanggan']); ?></td></tr><?php endif; ?>
            <tr><td>Paket</td><td>: <?= htmlspecialchars((string)($data['paket'] ?? '-')); ?></td></tr>
            <tr><td>Masa Aktif Sampai</td><td>: <?= htmlspecialchars($masaAktif); ?></td></tr>
            <tr><td>Status</td><td>: <span class="status"><?= htmlspecialchars((string)($data['status_bayar'] ?? '-')); ?></span></td></tr>
        </table>

        <div class="line"></div>
        <table>
            <tr><td class="total"><?= $isLunas ? 'Nominal Dibayar' : 'Total Tagihan'; ?></td><td class="total">Rp<?= number_format($nominal, 0, ',', '.'); ?></td></tr>
        </table>
        <div class="line"></div>
        <div class="foot">
            <div class="foot-line">Terima kasih.</div>
            <div class="foot-line">Nota ini dicetak dari aplikasi Billing.</div>
            <div class="support-sims" aria-label="Supported by SIMS">
                <span>Supported by</span>
                <img class="support-logo-sims" src="logo_sims.png" alt="SIMS">
            </div>
        </div>
    </main>

    <script>
        function bukaMode(mode, ukuran) {
            const url = new URL(window.location.href);
            url.searchParams.set('mode', mode);
            if (ukuran) {
                url.searchParams.set('thermal', ukuran);
            }
            url.searchParams.set('autoprint', '1');
            window.location.href = url.toString();
        }

        function cetakThermal() {
            const ukuran = document.getElementById('thermal-size').value;
            bukaMode('thermal', ukuran);
        }

        function cetakA4() {
            bukaMode('a4', null);
        }

        <?php if ($cetakOtomatis): ?>
        window.addEventListener('load', function () {
            setTimeout(function () {
                window.print();
            }, 250);
        });

        window.addEventListener('afterprint', function () {
            const url = new URL(window.location.href);
            if (url.searchParams.get('autoprint') === '1') {
                url.searchParams.delete('autoprint');
                window.history.replaceState({}, document.title, url.toString());
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
