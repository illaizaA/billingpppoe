<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/salam_bootstrap.php';
require_once __DIR__ . '/pelanggan_detail_helper.php';
require_once __DIR__ . '/tagihan_periode_helper.php';

salamRequireLogin();
$canAccessAllWilayah = salamCanAccessAllWilayah();

$id = (int) ($_GET['id'] ?? 0);
$isModal = isset($_GET['modal']) && $_GET['modal'] === '1';
if ($id <= 0) {
    exit('ID pelanggan tidak valid.');
}

foreach (['tarif_langganan', 'tanggal_bayar', 'nominal_dibayar', 'alamat'] as $column) {
    if (!salamTableHasColumn($koneksi, $column)) {
        exit('Struktur database Salam belum diperbarui. Import database_update_salam_only.sql terlebih dahulu.');
    }
}

$masterData = salamFindPelangganById($koneksi, $id);
if (!$masterData) {
    exit('Data pelanggan tidak ditemukan atau bukan wilayah akun ini.');
}
$requestedPeriode = salamPeriodeYm($_GET['periode'] ?? ($masterData['waktu'] ?? date('Y-m')));
$data = salamFindTagihanPeriode($koneksi, $id, $requestedPeriode);
if (!$data) {
    exit('Tagihan pada periode yang dipilih tidak ditemukan atau bukan wilayah akun ini.');
}
$isHistorical = (($data['sumber_data'] ?? '') === 'riwayat');
if (!salamDetailPelangganTableReady($koneksi)) {
    exit('Database fitur profil pelanggan belum dipasang. Import database_update_billing_pppoe_FINAL.sql terlebih dahulu.');
}
$detail = salamGetPelangganDetail($koneksi, $id);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    $nama = trim((string) ($_POST['nama'] ?? ''));
    $idPelanggan = trim((string) ($_POST['id_pelanggan'] ?? ''));
    $kode = trim((string) ($_POST['kode_pelanggan'] ?? ''));
    $nomor = trim((string) ($_POST['nomor_pelanggan'] ?? ''));
    $alamat = $canAccessAllWilayah
        ? salamNormalisasiAlamatInput($_POST['alamat'] ?? '')
        : salamWilayahLogin();
    $paket = trim((string) ($_POST['paket'] ?? ''));
    // Admin hanya mengisi satu nominal: Tagihan.
    // tarif_langganan tetap disimpan internal agar nominal dapat muncul lagi saat bulan baru.
    $tagihanPeriode = (float) str_replace(',', '.', (string) ($_POST['tagihan'] ?? 0));
    $tarifTersimpan = (float) ($data['tarif_langganan'] ?? 0);
    if ($tarifTersimpan <= 0) {
        $tarifTersimpan = (float) ($data['tagihan'] ?? 0);
    }
    $periodeTagihan = trim((string) ($_POST['waktu'] ?? ''));
    $masaAktifSampai = trim((string) ($_POST['langganan_selesai'] ?? ''));
    $statusBayar = ($_POST['status_bayar'] ?? 'Belum Lunas') === 'Lunas'
        ? 'Lunas'
        : 'Belum Lunas';
    $namaKtp = trim((string) ($_POST['nama_ktp'] ?? ''));
    $nik = preg_replace('/\s+/', '', trim((string) ($_POST['nik'] ?? ''))) ?? '';
    // Koordinat tidak pernah disimpan dari form Billing.
    // Latitude/longitude hanya dibaca dari sumber PPPoE asli.
    if ($nik !== '' && !preg_match('/^[0-9]{8,32}$/', $nik)) {
        $errorMessage = 'NIK hanya boleh berisi angka.';
    }

    if ($isHistorical) {
        $historyId = (int) ($data['tagihan_riwayat_id'] ?? 0);
        if ($historyId <= 0) {
            $errorMessage = 'Riwayat tagihan tidak ditemukan.';
        } elseif ($tagihanPeriode < 0) {
            $errorMessage = 'Tagihan tidak boleh negatif.';
        } elseif (!salamDateIsValid($masaAktifSampai)) {
            $errorMessage = 'Masa aktif sampai harus berupa tanggal yang valid.';
        } elseif (salamPeriodeYm($periodeTagihan, $requestedPeriode) !== $requestedPeriode) {
            $errorMessage = 'Periode riwayat tidak dapat dipindahkan. Pilih periode dari Dashboard.';
        } else {
            $nominalRiwayat = $tagihanPeriode > 0
                ? $tagihanPeriode
                : (float) ($data['nominal_tagihan_asli'] ?? $data['tarif_langganan'] ?? 0);
            if ($statusBayar === 'Lunas') {
                $stmtHistory = $koneksi->prepare("UPDATE tagihan_salam
                    SET nominal_tagihan=?, tanggal_jatuh_tempo=?, status_bayar='Lunas',
                        tanggal_bayar=CURDATE(), nominal_dibayar=?
                    WHERE id=?");
                $stmtHistory->bind_param('dsdi', $nominalRiwayat, $masaAktifSampai, $nominalRiwayat, $historyId);
            } else {
                $stmtHistory = $koneksi->prepare("UPDATE tagihan_salam
                    SET nominal_tagihan=?, tanggal_jatuh_tempo=?, status_bayar='Belum Lunas',
                        tanggal_bayar=NULL, nominal_dibayar=NULL
                    WHERE id=?");
                $stmtHistory->bind_param('dsi', $nominalRiwayat, $masaAktifSampai, $historyId);
            }
            $okHistory = $stmtHistory->execute();
            $historyError = $stmtHistory->error;
            $stmtHistory->close();
            if ($okHistory) {
                if ($isModal) {
                    ?>
                    <!doctype html><html lang="id"><head><meta charset="utf-8"><style>
                    html,body{height:100%;margin:0;font-family:Segoe UI,Arial;display:grid;place-items:center;background:#f5f7f9}
                    .msg{background:#fff;padding:22px 28px;border-radius:12px;box-shadow:0 16px 35px rgba(0,0,0,.1);text-align:center}
                    .msg h3{margin:0 0 6px;color:#2c3e50}.msg p{margin:0;color:#6c757d}
                    </style></head><body><div class="msg"><h3>Tagihan periode lama berhasil diperbarui</h3><p>Menutup jendela...</p></div>
                    <script>setTimeout(()=>{window.parent.postMessage(JSON.stringify({action:'close',success:true}),'*')},650)</script>
                    </body></html>
                    <?php
                    exit;
                }
                header('Location: dashboard_salam.php');
                exit;
            }
            $errorMessage = 'Riwayat tagihan gagal diperbarui' . ($historyError !== '' ? ': ' . $historyError : '.');
        }
    }

    if (!$isHistorical) {
    if (!empty($errorMessage)) {
        // Pesan validasi profil pelanggan sudah ditentukan di atas.
    } elseif ($nama === '' || $paket === '') {
        $errorMessage = 'Nama dan paket wajib diisi.';
    } elseif ($tagihanPeriode < 0) {
        $errorMessage = 'Tagihan tidak boleh negatif.';
    } elseif (!salamDateIsValid($periodeTagihan) || !salamDateIsValid($masaAktifSampai)) {
        $errorMessage = 'Periode tagihan dan masa aktif sampai harus berupa tanggal yang valid.';
    } elseif ($masaAktifSampai < $periodeTagihan) {
        $errorMessage = 'Masa aktif sampai tidak boleh lebih awal dari periode tagihan.';
    } else {
        // Satu input nominal untuk admin:
        // - Belum Lunas dan nominal > 0: nominal itu juga menjadi tarif bulan berikutnya.
        // - Lunas: tagihan saat ini menjadi Rp0, tetapi tarif yang tersimpan tetap dipertahankan.
        // - Dari Lunas ke Belum Lunas saat nominal masih 0: sistem mengembalikan tarif tersimpan.
        $tarif = $tarifTersimpan;
        if ($statusBayar === 'Belum Lunas' && $tagihanPeriode > 0) {
            $tarif = $tagihanPeriode;
        } elseif ($statusBayar === 'Belum Lunas' && $tagihanPeriode <= 0) {
            $tagihanPeriode = $tarifTersimpan;
        }

        if ($statusBayar === 'Lunas') {
            $nominalDibayar = $tagihanPeriode > 0
                ? $tagihanPeriode
                : ((float) ($data['tagihan'] ?? 0) > 0
                    ? (float) $data['tagihan']
                    : $tarifTersimpan);

            $sql = "UPDATE pelanggan_salam
                    SET nama = ?,
                        id_pelanggan = ?,
                        kode_pelanggan = ?,
                        nomor_pelanggan = ?,
                        alamat = ?,
                        paket = ?,
                        tarif_langganan = ?,
                        waktu = ?,
                        langganan_selesai = ?,
                        status_bayar = 'Lunas',
                        tagihan = 0,
                        tanggal_bayar = CURDATE(),
                        nominal_dibayar = ?
                    WHERE id = ?";
            $stmt = $koneksi->prepare($sql);
            $stmt->bind_param(
                'ssssssdssdi',
                $nama,
                $idPelanggan,
                $kode,
                $nomor,
                $alamat,
                $paket,
                $tarif,
                $periodeTagihan,
                $masaAktifSampai,
                $nominalDibayar,
                $id
            );
        } else {
            $sql = "UPDATE pelanggan_salam
                    SET nama = ?,
                        id_pelanggan = ?,
                        kode_pelanggan = ?,
                        nomor_pelanggan = ?,
                        alamat = ?,
                        paket = ?,
                        tarif_langganan = ?,
                        waktu = ?,
                        langganan_selesai = ?,
                        status_bayar = 'Belum Lunas',
                        tagihan = ?,
                        tanggal_bayar = NULL,
                        nominal_dibayar = NULL
                    WHERE id = ?";
            $stmt = $koneksi->prepare($sql);
            $stmt->bind_param(
                'ssssssdssdi',
                $nama,
                $idPelanggan,
                $kode,
                $nomor,
                $alamat,
                $paket,
                $tarif,
                $periodeTagihan,
                $masaAktifSampai,
                $tagihanPeriode,
                $id
            );
        }

        $fotoLama = (string) ($detail['foto_rumah'] ?? '');
        $fotoBaru = null;
        $ok = false;
        $dbError = '';
        try {
            $koneksi->begin_transaction();
            if (!$stmt || !$stmt->execute()) {
                throw new RuntimeException($stmt ? $stmt->error : $koneksi->error);
            }
            if ($stmt) {
                $stmt->close();
                $stmt = null;
            }

            $fotoFinal = $fotoLama;
            $fotoBaru = salamFotoRumahUpload($_FILES['foto_rumah'] ?? [], $idPelanggan !== '' ? $idPelanggan : ('pelanggan-' . $id));
            if ($fotoBaru !== null) {
                $fotoFinal = $fotoBaru;
            }

            salamUpsertPelangganDetailBilling(
                $koneksi,
                $id,
                $namaKtp,
                $nik,
                $fotoFinal
            );
            $koneksi->commit();
            $ok = true;
            $detail = salamGetPelangganDetail($koneksi, $id);
            if ($fotoBaru !== null && $fotoLama !== '' && $fotoLama !== $fotoBaru) {
                salamHapusFotoRumah($fotoLama);
            }
        } catch (Throwable $e) {
            $koneksi->rollback();
            if ($stmt) {
                $stmt->close();
            }
            if ($fotoBaru !== null) {
                salamHapusFotoRumah($fotoBaru);
            }
            $dbError = $e->getMessage();
        }

        if ($ok) {
            if ($isModal) {
                ?>
                <!doctype html>
                <html lang="id"><head><meta charset="utf-8"><style>
                html,body{height:100%;margin:0;font-family:Segoe UI,Arial;display:grid;place-items:center;background:#f5f7f9}
                .msg{background:#fff;padding:22px 28px;border-radius:12px;box-shadow:0 16px 35px rgba(0,0,0,.1);text-align:center}
                .msg h3{margin:0 0 6px;color:#2c3e50}.msg p{margin:0;color:#6c757d}
                </style></head><body><div class="msg"><h3>Data berhasil diperbarui</h3><p>Menutup jendela...</p></div>
                <script>setTimeout(()=>{window.parent.postMessage(JSON.stringify({action:'close',success:true}),'*')},650)</script>
                </body></html>
                <?php
                exit;
            }

            header('Location: dashboard_salam.php');
            exit;
        }

        $errorMessage = 'Data gagal diperbarui' . ($dbError !== '' ? ': ' . $dbError : '.');
    }

    }

    $data['nama'] = $isHistorical ? ($data['nama'] ?? $nama) : $nama;
    $data['id_pelanggan'] = $isHistorical ? ($data['id_pelanggan'] ?? $idPelanggan) : $idPelanggan;
    $data['kode_pelanggan'] = $isHistorical ? ($data['kode_pelanggan'] ?? $kode) : $kode;
    $data['nomor_pelanggan'] = $isHistorical ? ($data['nomor_pelanggan'] ?? $nomor) : $nomor;
    $data['alamat'] = $isHistorical ? ($data['alamat'] ?? $alamat) : $alamat;
    $data['paket'] = $isHistorical ? ($data['paket'] ?? $paket) : $paket;
    $data['tarif_langganan'] = $isHistorical ? ($data['tarif_langganan'] ?? $tagihanPeriode) : $tarif;
    $data['tagihan'] = $statusBayar === 'Lunas' ? 0 : $tagihanPeriode;
    $data['waktu'] = $isHistorical ? ($requestedPeriode . '-01') : $periodeTagihan;
    $data['langganan_selesai'] = $masaAktifSampai;
    $data['status_bayar'] = $statusBayar;
    if (!$isHistorical) $detail['nama_ktp'] = $namaKtp ?? ($detail['nama_ktp'] ?? '');
    if (!$isHistorical) $detail['nik'] = $nik ?? ($detail['nik'] ?? '');
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Edit Tagihan Pelanggan</title>
    <style>
        :root{--bg:#f5f7fb;--card:#fff;--accent:#3498db;--muted:#6c757d;--danger:#e74c3c}
        *{box-sizing:border-box}html,body{height:100%;margin:0;font-family:Segoe UI,Arial;background:var(--bg);color:#222}
        .wrap{min-height:100%;display:flex;align-items:center;justify-content:center;padding:0}
        .card{width:100%;height:100%;background:var(--card);overflow:auto}.card__body{padding:22px;min-height:100%;display:flex;flex-direction:column}
        .grid{display:flex;gap:12px;flex-wrap:wrap}.col{flex:1;min-width:160px}
        label.small{display:block;color:var(--muted);font-size:13px;margin-bottom:6px}
        input,select{width:100%;padding:10px 12px;border-radius:8px;border:1px solid #e6eef6;background:#fff;font-size:14px;box-sizing:border-box}
        .note{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.4}
        .actions{display:flex;gap:10px;justify-content:flex-end;padding:12px 0 0;margin-top:auto}
        .btn{padding:10px 14px;border-radius:8px;border:0;cursor:pointer;font-weight:600;text-decoration:none;display:inline-block;text-align:center}
        .btn-primary{background:linear-gradient(90deg,var(--accent),#2980b9);color:#fff}.btn-muted{background:#f1f5f9;color:#213}
        .error-message{background:#ffeeee;color:var(--danger);padding:12px;border-radius:6px;margin-bottom:15px;border-left:4px solid var(--danger)}
        @media(max-width:520px){.grid{flex-direction:column}.actions{flex-direction:column}.btn{width:100%}}
    
        /* MOBILE RESPONSIVE FIX FINAL — UI ONLY */
        @media(max-width:520px){
            html,body{height:100%;min-height:100%;overflow:hidden}
            .wrap{height:100%;min-height:0;display:block}
            .card{height:100%;min-height:0;overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;touch-action:pan-y}
            .card__body{padding:16px 14px 22px;min-height:100%;display:flex;flex-direction:column}
            .grid{flex-direction:column;gap:12px}.col{width:100%;min-width:0}
            input,select,textarea{max-width:100%;min-height:44px;font-size:16px}
            input[type="file"]{width:100%;min-width:0;max-width:100%}
            .actions{flex-direction:column-reverse;gap:8px;padding-bottom:max(6px,env(safe-area-inset-bottom))}
            .btn{width:100%;min-height:44px;display:flex;align-items:center;justify-content:center}
            img{max-width:100%;height:auto}
        }
</style>
</head>
<body>
<div class="wrap">
    <div class="card" role="dialog" aria-label="Edit Tagihan Pelanggan">
        <div class="card__body">
            <?php if (!empty($errorMessage)): ?>
                <div class="error-message"><strong>Error:</strong> <?= htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <?php if ($isHistorical): ?>
                <div style="margin-bottom:14px;padding:10px 12px;border-radius:9px;background:#eef6ff;border:1px solid #cfe4fb;color:#315b7d;font-size:13px;line-height:1.45;">
                    <b>Periode lama: <?= htmlspecialchars(salamBulananIndonesia($requestedPeriode . '-01', false)); ?></b><br>
                    Data pelanggan ditampilkan sebagai referensi. Yang dapat diperbarui di halaman ini hanya nominal tagihan, masa aktif, dan status pembayaran periode tersebut.
                </div>
                <?php endif; ?>
                <div style="font-weight:700;color:#2c3e50;margin-bottom:10px;">Data Pelanggan</div>
                <div class="grid">
                    <div class="col">
                        <label class="small">Nama</label>
                        <input type="text" name="nama" <?= $isHistorical ? 'readonly' : ''; ?> value="<?= htmlspecialchars((string) $data['nama']); ?>" required>
                    </div>
                    <div class="col">
                        <label class="small">ID Pelanggan</label>
                        <input type="text" name="id_pelanggan" <?= $isHistorical ? 'readonly' : ''; ?> value="<?= htmlspecialchars((string) ($data['id_pelanggan'] ?? '')); ?>">
                    </div>
                </div>

                <div class="grid" style="margin-top:12px">
                    <div class="col">
                        <label class="small">Nomor WhatsApp</label>
                        <input type="text" name="nomor_pelanggan" <?= $isHistorical ? 'readonly' : ''; ?> value="<?= htmlspecialchars((string) ($data['nomor_pelanggan'] ?? '')); ?>" placeholder="08... / 62...">
                    </div>
                    <div class="col">
                        <label class="small">Kode Pelanggan</label>
                        <input type="text" name="kode_pelanggan" <?= $isHistorical ? 'readonly' : ''; ?> value="<?= htmlspecialchars((string) ($data['kode_pelanggan'] ?? '')); ?>">
                    </div>
                </div>

                <div class="grid" style="margin-top:12px">
                    <div class="col">
                        <label class="small">Alamat</label>
                        <input type="text" name="alamat" list="alamat-salam-options" autocomplete="off" value="<?= htmlspecialchars($canAccessAllWilayah ? salamNamaWilayahTampilan((string) ($data['alamat'] ?? '')) : salamWilayahLogin()); ?>" placeholder="Pilih alamat atau ketik manual" <?= ($canAccessAllWilayah && !$isHistorical) ? '' : 'readonly'; ?>>
                        <datalist id="alamat-salam-options">
                            <?php foreach (array_values(salamDaftarWilayahResmi()) as $wilayahOption): ?>
                                <option value="<?= htmlspecialchars($wilayahOption); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="col">
                        <label class="small">Paket</label>
                        <input type="text" name="paket" <?= $isHistorical ? 'readonly' : ''; ?> value="<?= htmlspecialchars((string) ($data['paket'] ?? '')); ?>" required>
                    </div>
                </div>

                <div style="margin-top:18px;padding-top:14px;border-top:1px solid #e6eef6;">
                    <div style="font-weight:700;color:#2c3e50;margin-bottom:10px;">Data Identitas</div>
                    <div class="grid">
                        <div class="col">
                            <label class="small">Nama sesuai KTP</label>
                            <input type="text" name="nama_ktp" <?= $isHistorical ? 'readonly' : ''; ?> value="<?= htmlspecialchars((string) ($detail['nama_ktp'] ?? '')); ?>">
                        </div>
                        <div class="col">
                            <label class="small">NIK</label>
                            <input type="text" name="nik" <?= $isHistorical ? 'readonly' : ''; ?> inputmode="numeric" maxlength="32" value="<?= htmlspecialchars((string) ($detail['nik'] ?? '')); ?>">
                        </div>
                    </div>
                    <div class="grid" style="margin-top:12px">
                        <div class="col">
                            <label class="small">Foto Rumah <span style="font-weight:400;color:#7f8c8d;">(opsional)</span></label>
                            <div style="margin-bottom:8px;">
                                <?php if (!empty($detail['foto_rumah'])): ?>
                                    <img src="<?= htmlspecialchars((string) $detail['foto_rumah']); ?>"
                                         alt="Foto rumah"
                                         style="width:100%;max-width:240px;height:130px;object-fit:cover;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc;">
                                <?php else: ?>
                                    <div style="width:100%;max-width:240px;height:130px;border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc;color:#94a3b8;display:flex;align-items:center;justify-content:center;text-align:center;padding:12px;">
                                        <span>🏠<br>Foto rumah belum tersedia</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="file" name="foto_rumah" accept="image/jpeg,image/png,image/webp" <?= $isHistorical ? 'disabled' : ''; ?>>
                            <div class="note">
                                Jika tidak memilih foto baru, foto lama tetap digunakan. Maksimal 5 MB (JPG/PNG/WEBP).
                            </div>
                        </div>
                    </div>


                </div>

                <div style="margin-top:18px;padding-top:14px;border-top:1px solid #e6eef6;">
                    <div style="font-weight:700;color:#2c3e50;margin-bottom:10px;">Tagihan</div>
                <div class="grid" style="margin-top:12px">
                    <div class="col">
                        <label class="small">Periode Tagihan</label>
                        <input type="date" name="waktu" value="<?= htmlspecialchars((string) ($data['waktu'] ?? '')); ?>" required <?= $isHistorical ? 'readonly' : ''; ?>>
                        
                    </div>
                    <div class="col">
                        <label class="small">Masa Aktif Sampai</label>
                        <input type="date" name="langganan_selesai" value="<?= htmlspecialchars((string) ($data['langganan_selesai'] ?? '')); ?>" required>
                        
                    </div>
                </div>

                <div class="grid" style="margin-top:12px">
                    <div class="col">
                        <label class="small">Tagihan</label>
                        <input type="number" id="tagihan" name="tagihan" value="<?= htmlspecialchars((string) ($data['tagihan'] ?? 0)); ?>" min="0" step="1" required>
                        <input type="hidden" id="tarif-tersimpan" value="<?= htmlspecialchars((string) ($data['tarif_langganan'] ?? $data['tagihan'] ?? 0)); ?>">
                        
                    </div>
                    <div class="col">
                        <label class="small">Status Bayar</label>
                        <select name="status_bayar">
                            <option value="Lunas" <?= ($data['status_bayar'] ?? '') === 'Lunas' ? 'selected' : ''; ?>>Lunas</option>
                            <option value="Belum Lunas" <?= ($data['status_bayar'] ?? '') !== 'Lunas' ? 'selected' : ''; ?>>Belum Lunas</option>
                        </select>
                        
                    </div>
                </div>

                </div>

                <div style="margin-top:18px;padding-top:14px;border-top:1px solid #e6eef6;">
                    <div style="margin-top:0;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                        <div style="font-weight:700;color:#2c3e50;margin-bottom:8px;">Informasi Jaringan <span style="font-weight:400;color:#64748b;font-size:12px;">(Hanya lihat)</span></div>
                        <div class="grid">
                            <div class="col">
                                <label class="small">Koordinat X / Longitude</label>
                                <div id="pppoe-coordinate-x" style="padding:10px 12px;border-radius:8px;background:#eef2f7;color:#475569;">Mencocokkan otomatis...</div>
                            </div>
                            <div class="col">
                                <label class="small">Koordinat Y / Latitude</label>
                                <div id="pppoe-coordinate-y" style="padding:10px 12px;border-radius:8px;background:#eef2f7;color:#475569;">Mencocokkan otomatis...</div>
                            </div>
                        </div>
                        <div class="grid" style="margin-top:10px">
                            <div class="col">
                                <label class="small">IP / User / Status</label>
                                <div id="pppoe-network-info" style="padding:10px 12px;border-radius:8px;background:#eef2f7;color:#475569;">Data berasal dari PPPoE asli.</div>
                            </div>
                        </div>
                        <div id="pppoe-coordinate-note" class="note">
                            Billing yang menyesuaikan ke data PPPoE. Admin tidak mengisi ID teknis, username PPPoE, koordinat, IP, atau status jaringan.
                        </div>
                    </div>
                </div>

                <div class="actions">
                    <?php if ($isModal): ?>
                        <button type="button" class="btn btn-muted" onclick="window.parent.postMessage(JSON.stringify({action:'close'}),'*')">Batal</button>
                    <?php else: ?>
                        <a class="btn btn-muted" href="dashboard_salam.php">Batal</a>
                    <?php endif; ?>
                    <button type="submit" name="update" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    const defaults = <?= json_encode(array_values(salamDaftarWilayahResmi()), JSON_UNESCAPED_UNICODE); ?>;
    const datalist = document.getElementById('alamat-salam-options');
    if (!datalist) return;

    function escapeOptionValue(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function render(items) {
        const seen = new Set();
        const merged = [];
        [...defaults, ...(Array.isArray(items) ? items : [])].forEach(item => {
            const value = String(item || '').trim();
            if (!value || value.toLowerCase() === 'semua' || value.toLowerCase() === 'semua alamat') return;
            const key = value.toLowerCase().replace(/\s+/g, ' ');
            if (seen.has(key)) return;
            seen.add(key);
            merged.push(value);
        });
        datalist.innerHTML = merged.map(item => `<option value="${escapeOptionValue(item)}"></option>`).join('');
    }

    render(defaults);
    fetch('get_alamat_salam.php')
        .then(response => response.json())
        .then(data => render(Array.isArray(data.alamat) ? data.alamat : []))
        .catch(() => render(defaults));
})();
</script>
<script>
(function () {
    const status = document.querySelector('select[name="status_bayar"]');
    const tagihan = document.getElementById('tagihan');
    const tarifTersimpan = document.getElementById('tarif-tersimpan');
    if (!status || !tagihan || !tarifTersimpan) return;

    status.addEventListener('change', function () {
        if (this.value === 'Lunas') {
            tagihan.value = '0';
            return;
        }
        if (Number(tagihan.value || 0) <= 0) {
            tagihan.value = tarifTersimpan.value || '0';
        }
    });
})();
</script>
<script>
(function () {
    const billingCustomerId = <?= (int) $id ?>;
    const xBox = document.getElementById('pppoe-coordinate-x');
    const yBox = document.getElementById('pppoe-coordinate-y');
    const networkBox = document.getElementById('pppoe-network-info');
    const note = document.getElementById('pppoe-coordinate-note');

    if (!xBox || !yBox || !networkBox) return;

    async function loadReadonlyPppoeInfo() {
        try {
            const response = await fetch('get_pppoe_readonly.php', { cache: 'no-store' });
            const payload = await response.json();

            if (!response.ok || !payload.success || !Array.isArray(payload.data)) {
                throw new Error(payload.message || 'Data PPPoE tidak tersedia.');
            }

            const item = payload.data.find(row =>
                row.billing && Number(row.billing.id) === Number(billingCustomerId)
            );

            if (!item) {
                xBox.textContent = '-';
                yBox.textContent = '-';
                networkBox.textContent = 'Belum ditemukan pasangan otomatis pada data PPPoE.';
                if (note) {
                    note.textContent = 'Data Billing tetap dapat diedit. Sistem tidak membuat koordinat atau status dummy.';
                }
                return;
            }

            xBox.textContent = item.longitude ?? '-';
            yBox.textContent = item.latitude ?? '-';
            networkBox.textContent =
                `IP: ${item.ip || '-'} • User: ${item.user || '-'} • Status: ${item.status || 'UNKNOWN'}`;

            if (note) {
                note.textContent =
                    `Terhubung otomatis ke ID PPPoE ${item.id || '-'}. Semua informasi jaringan hanya dibaca dari PPPoE.`;
            }
        } catch (error) {
            xBox.textContent = 'Tidak tersedia';
            yBox.textContent = 'Tidak tersedia';
            networkBox.textContent = 'Sumber PPPoE sedang tidak dapat dibaca.';
            if (note) {
                note.textContent = 'Data Billing tetap dapat diedit tanpa mengubah PPPoE.';
            }
        }
    }

    loadReadonlyPppoeInfo();
})();
</script>
</body>
</html>
