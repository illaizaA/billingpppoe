<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
require_once __DIR__ . '/pelanggan_detail_helper.php';
header('Content-Type: application/json; charset=utf-8');
salamRequireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Metode tidak valid.']);
    exit;
}

$idPelanggan = trim($_POST['id_pelanggan'] ?? '');
$kodePelanggan = trim($_POST['kode_pelanggan'] ?? '');
$nama = trim($_POST['nama'] ?? '');
$paket = trim($_POST['paket'] ?? '');
$nomor = trim($_POST['nomor_pelanggan'] ?? '');
$alamat = salamCanAccessAllWilayah()
    ? salamNormalisasiAlamatInput($_POST['alamat'] ?? '')
    : salamWilayahLogin();
$tarif = (float) str_replace(',', '.', (string) ($_POST['tagihan'] ?? 0));
$namaKtp = trim((string) ($_POST['nama_ktp'] ?? ''));
$nik = preg_replace('/\s+/', '', trim((string) ($_POST['nik'] ?? ''))) ?? '';
// Koordinat TIDAK diterima dari Billing.
// Posisi marker selalu mengikuti latitude/longitude dari sumber PPPoE asli.
if ($nik !== '' && !preg_match('/^[0-9]{8,32}$/', $nik)) {
    echo json_encode(['success'=>false,'message'=>'NIK hanya boleh berisi angka.']);
    exit;
}
if (!salamDetailPelangganTableReady($koneksi)) {
    echo json_encode(['success'=>false,'message'=>'Database fitur profil pelanggan belum dipasang. Import database_update_billing_pppoe_FINAL.sql terlebih dahulu.']);
    exit;
}

if ($nama === '' || $paket === '') {
    echo json_encode(['success'=>false,'message'=>'Nama dan paket wajib diisi.']);
    exit;
}
if ($alamat === '') {
    echo json_encode(['success'=>false,'message'=>'Alamat/wilayah wajib diisi.']);
    exit;
}
if ($tarif < 0) {
    echo json_encode(['success'=>false,'message'=>'Tarif langganan tidak boleh negatif.']);
    exit;
}

$fotoBaru = null;
$koneksi->begin_transaction();
try {
    $stmt = $koneksi->prepare('INSERT INTO pelanggan_salam (id_pelanggan, kode_pelanggan, nama, nomor_pelanggan, alamat, paket, tarif_langganan, tagihan) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('ssssssdd', $idPelanggan, $kodePelanggan, $nama, $nomor, $alamat, $paket, $tarif, $tarif);
    if (!$stmt->execute()) {
        throw new RuntimeException('Gagal menyimpan pelanggan: ' . $stmt->error);
    }

    $id = (int) $stmt->insert_id;
    $stmt->close();
    $kodeWilayah = salamKodeWilayah($alamat);
    $generatedId = $idPelanggan !== '' ? $idPelanggan : $kodeWilayah . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    $invoice = $kodeWilayah . '/INV/' . str_pad((string) $id, 5, '0', STR_PAD_LEFT) . '/' . date('m/Y');
    $fix = $koneksi->prepare('UPDATE pelanggan_salam SET id_pelanggan = ?, nomor_invoice = ? WHERE id = ?');
    $fix->bind_param('ssi', $generatedId, $invoice, $id);
    if (!$fix->execute()) {
        throw new RuntimeException('Gagal melengkapi ID pelanggan: ' . $fix->error);
    }
    $fix->close();

    $fotoBaru = salamFotoRumahUpload($_FILES['foto_rumah'] ?? [], $generatedId);
    salamUpsertPelangganDetailBilling(
        $koneksi,
        $id,
        $namaKtp,
        $nik,
        $fotoBaru ?? ''
    );

    $koneksi->commit();
} catch (Throwable $e) {
    $koneksi->rollback();
    if ($fotoBaru !== null) {
        salamHapusFotoRumah($fotoBaru);
    }
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    $koneksi->close();
    exit;
}

$koneksi->close();
echo json_encode(['success'=>true,'message'=>'Pelanggan wilayah '.$alamat.' berhasil ditambahkan.']);
?>
