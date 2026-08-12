<?php
/**
 * Helper profil pelanggan Billing.
 * Nama KTP, NIK, dan Foto Rumah dikelola Billing.
 * Data teknis PPPoE tidak ditulis dari Billing.
 */
function salamDetailPelangganTableReady(mysqli $koneksi): bool
{
    $result = $koneksi->query("SHOW TABLES LIKE 'pelanggan_detail_salam'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function salamGetPelangganDetail(mysqli $koneksi, int $pelangganId): array
{
    $default = [
        'nama_ktp' => '',
        'nik' => '',
        'foto_rumah' => '',
        'koordinat_x' => '',
        'koordinat_y' => '',
        'pppoe_user' => '',
    ];

    if ($pelangganId <= 0 || !salamDetailPelangganTableReady($koneksi)) {
        return $default;
    }

    $stmt = $koneksi->prepare(
        'SELECT nama_ktp, nik, foto_rumah, koordinat_x, koordinat_y, pppoe_user
         FROM pelanggan_detail_salam
         WHERE pelanggan_id = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param('i', $pelangganId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return array_merge($default, $row);
}

function salamFotoRumahUpload(array $file, string $idPelanggan = 'pelanggan'): ?string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload foto rumah gagal. Kode upload: ' . $error);
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        throw new RuntimeException('Ukuran foto rumah maksimal 5 MB.');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('File foto rumah tidak valid.');
    }

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
    } elseif (function_exists('mime_content_type')) {
        $mime = (string) mime_content_type($tmp);
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Foto rumah harus berformat JPG, PNG, atau WEBP.');
    }

    $uploadDir = __DIR__ . '/uploads/foto_rumah';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Folder penyimpanan foto rumah tidak dapat dibuat.');
    }

    $safeId = preg_replace('/[^A-Za-z0-9_-]+/', '-', $idPelanggan) ?: 'pelanggan';
    $filename = $safeId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.' . $extensions[$mime];
    $destination = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException('Foto rumah gagal disimpan ke server.');
    }

    return 'uploads/foto_rumah/' . $filename;
}

function salamHapusFotoRumah(?string $relativePath): void
{
    $relativePath = trim((string) $relativePath);

    if ($relativePath === '' || !str_starts_with($relativePath, 'uploads/foto_rumah/')) {
        return;
    }

    $fullPath = __DIR__ . '/uploads/foto_rumah/' . basename($relativePath);
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function salamUpsertPelangganDetailBilling(
    mysqli $koneksi,
    int $pelangganId,
    ?string $namaKtp,
    ?string $nik,
    ?string $fotoRumah
): void {
    if (!salamDetailPelangganTableReady($koneksi)) {
        throw new RuntimeException(
            'Tabel pelanggan_detail_salam belum tersedia. Import database_update_billing_pppoe_FINAL.sql terlebih dahulu.'
        );
    }

    $namaKtp = trim((string) $namaKtp);
    $nik = preg_replace('/\s+/', '', trim((string) $nik)) ?? '';
    $fotoRumah = trim((string) $fotoRumah);

    if ($nik !== '' && !preg_match('/^[0-9]{8,32}$/', $nik)) {
        throw new RuntimeException('NIK hanya boleh berisi 8-32 angka.');
    }

    $exists = $koneksi->prepare(
        'SELECT id FROM pelanggan_detail_salam WHERE pelanggan_id = ? LIMIT 1'
    );
    if (!$exists) {
        throw new RuntimeException('Gagal mengecek profil pelanggan: ' . $koneksi->error);
    }

    $exists->bind_param('i', $pelangganId);
    $exists->execute();
    $hasRow = (bool) $exists->get_result()->fetch_assoc();
    $exists->close();

    if ($hasRow) {
        $stmt = $koneksi->prepare(
            "UPDATE pelanggan_detail_salam
             SET nama_ktp = ?,
                 nik = ?,
                 foto_rumah = NULLIF(?, '')
             WHERE pelanggan_id = ?"
        );
        if (!$stmt) {
            throw new RuntimeException('Gagal menyiapkan profil pelanggan: ' . $koneksi->error);
        }
        $stmt->bind_param('sssi', $namaKtp, $nik, $fotoRumah, $pelangganId);
    } else {
        $stmt = $koneksi->prepare(
            "INSERT INTO pelanggan_detail_salam
                (pelanggan_id, nama_ktp, nik, foto_rumah)
             VALUES (?, ?, ?, NULLIF(?, ''))"
        );
        if (!$stmt) {
            throw new RuntimeException('Gagal menyiapkan profil pelanggan: ' . $koneksi->error);
        }
        $stmt->bind_param('isss', $pelangganId, $namaKtp, $nik, $fotoRumah);
    }

    if (!$stmt->execute()) {
        $message = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Gagal menyimpan profil pelanggan: ' . $message);
    }

    $stmt->close();
}

// Kompatibilitas pemanggil lama: koordinat dan username PPPoE sengaja diabaikan.
function salamUpsertPelangganDetail(
    mysqli $koneksi,
    int $pelangganId,
    ?string $namaKtp,
    ?string $nik,
    ?float $koordinatX = null,
    ?float $koordinatY = null,
    ?string $fotoRumah = null,
    ?string $pppoeUser = null
): void {
    salamUpsertPelangganDetailBilling(
        $koneksi,
        $pelangganId,
        $namaKtp,
        $nik,
        $fotoRumah
    );
}
?>
