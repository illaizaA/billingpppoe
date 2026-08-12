<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
require_once __DIR__ . '/config_salam.php';
header('Content-Type: application/json; charset=utf-8');
salamRequireLogin();

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = min(100, max(1, (int) ($_GET['limit'] ?? 10)));
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');
$filter = strtolower(trim((string) ($_GET['filter'] ?? 'all')));
$wilayahFilter = trim((string) ($_GET['wilayah'] ?? 'all'));

$conditions = [salamScopeCondition($koneksi, 'alamat')];
if (salamCanAccessAllWilayah() && $wilayahFilter !== '' && strtolower($wilayahFilter) !== 'all') {
    $conditions[] = salamWilayahFilterCondition($koneksi, $wilayahFilter, 'alamat');
}
if ($search !== '') {
    $escaped = $koneksi->real_escape_string($search);
    $conditions[] = "(LOWER(nama) LIKE LOWER('%{$escaped}%') OR SOUNDEX(nama) = SOUNDEX('{$escaped}') OR id_pelanggan LIKE '%{$escaped}%' OR nomor_pelanggan LIKE '%{$escaped}%' OR paket LIKE '%{$escaped}%' OR alamat LIKE '%{$escaped}%')";
}
if ($filter === 'lunas') {
    $conditions[] = "status_bayar='Lunas'";
} elseif ($filter === 'belum lunas') {
    $conditions[] = "status_bayar='Belum Lunas'";
} elseif ($filter === 'aktif') {
    $conditions[] = "status_pelanggan='Aktif'";
} elseif ($filter === 'tidak aktif') {
    $conditions[] = "status_pelanggan='Tidak Aktif'";
}
$where = ' WHERE ' . implode(' AND ', $conditions);

$countResult = $koneksi->query('SELECT COUNT(*) total FROM pelanggan_salam' . $where);
$totalData = (int) ($countResult?->fetch_assoc()['total'] ?? 0);
$totalPages = max(1, (int) ceil($totalData / $limit));
$result = $koneksi->query('SELECT * FROM pelanggan_salam' . $where . ' ORDER BY id ASC LIMIT ' . $limit . ' OFFSET ' . $offset);

function esc($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function salamPesanTagihan(array $row): string {
    $periode = salamBulananIndonesia($row['waktu'] ?? null, false);
    $masaAktif = salamBulananIndonesia($row['langganan_selesai'] ?? null, true);
    $nominal = salamRupiah($row['tagihan'] ?? 0);
    $layanan = salamNamaLayananUntukAlamat($row['alamat'] ?? '');
    $pembayaran = salamInfoPembayaranUntukAlamat($row['alamat'] ?? '');
    return "Halo Bapak/Ibu " . ($row['nama'] ?? '-') . ",\n\n"
        . "Berikut informasi tagihan layanan {$layanan}.\n"
        . "ID Pelanggan: " . ($row['id_pelanggan'] ?? '-') . "\n"
        . "Paket: " . ($row['paket'] ?? '-') . "\n"
        . "Periode Tagihan: {$periode}\n"
        . "Masa Aktif Sampai: {$masaAktif}\n"
        . "Nominal Tagihan: {$nominal}\n"
        . "Status: " . ($row['status_bayar'] ?? 'Belum Lunas') . "\n\n"
        . $pembayaran . "\n\n"
        . "Silakan mengirimkan bukti pembayaran untuk konfirmasi.\n"
        . "Terima kasih.";
}

ob_start();
?>
<table class="modern-table">
    <thead><tr><th>No</th><th>ID Pelanggan</th><th>Nama Pelanggan</th><th>Alamat</th><th>Status Pelanggan</th><th>Paket</th><th>Masa Aktif Sampai</th><th>Tagihan Bulan</th><th>Tagihan</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody>
<?php if ($result && $result->num_rows > 0): ?>
<?php $no = $offset + 1; while ($row = $result->fetch_assoc()):
    $wa = salamNormalisasiWhatsApp($row['nomor_pelanggan'] ?? '');
    $tagihanUrl = $wa !== '' ? 'https://wa.me/' . $wa . '?text=' . rawurlencode(salamPesanTagihan($row)) : '';
    $statusPelanggan = ($row['status_pelanggan'] ?? 'Aktif') === 'Tidak Aktif' ? 'Tidak Aktif' : 'Aktif';
    $statusBayar = ($row['status_bayar'] ?? 'Belum Lunas') === 'Lunas' ? 'Lunas' : 'Belum Lunas';
?>
<tr>
<td><?= $no++; ?></td>
<td><span class="id-pill"><?= esc($row['id_pelanggan'] ?: '-'); ?></span></td>
<td><div class="name-cell"><?= esc($row['nama']); ?></div><small class="muted"><?= esc($row['nomor_pelanggan'] ?: '-'); ?></small></td>
<td><?= esc(salamNamaWilayahTampilan($row['alamat'] ?: '-')); ?></td>
<td><button type="button" class="badge <?= $statusPelanggan === 'Aktif' ? 'badge-success' : 'badge-danger'; ?>" onclick="updateStatusPelanggan(<?= (int)$row['id']; ?>, '<?= $statusPelanggan; ?>')" title="Klik untuk mengubah status pelanggan" style="border:0;cursor:pointer;"><?= esc($statusPelanggan); ?></button></td>
<td><?= esc($row['paket']); ?></td>
<td class="muted"><?= esc(salamBulananIndonesia($row['langganan_selesai'] ?? null, true)); ?></td>
<td><?= esc(salamBulananIndonesia($row['waktu'] ?? null, false)); ?></td>
<td><strong><?= esc(salamRupiah($row['tagihan'] ?? 0)); ?></strong></td>
<td><span class="badge <?= $statusBayar === 'Lunas' ? 'badge-success' : 'badge-danger'; ?>" onclick="updateStatus(<?= (int)$row['id']; ?>, '<?= $statusBayar; ?>')" title="Klik untuk ubah status"><?= esc($statusBayar); ?></span></td>
<td class="actions-cell"><div class="action-row">
<?php if ($tagihanUrl !== ''): ?><a class="action-btn btn-tagihan" href="<?= esc($tagihanUrl); ?>" target="_blank" rel="noopener">Kirim Tagihan</a><?php else: ?><button type="button" class="action-btn btn-tagihan" onclick="Swal.fire({icon:'warning',title:'Nomor WhatsApp belum valid',text:'Isi nomor pelanggan dengan format 08..., +62..., atau 62... terlebih dahulu.'})">Kirim Tagihan</button><?php endif; ?>
<button class="action-btn btn-resi" onclick="sendReceipt(<?= (int)$row['id']; ?>, '<?= esc($wa); ?>', '<?= $statusBayar; ?>')">Kirim Resi</button>
<button class="action-btn btn-print" onclick="window.open('nota_salam.php?id=<?= (int)$row['id']; ?>', '_blank')">Print Nota</button>
<button class="action-btn btn-edit icon-only" onclick="openEditModal(<?= (int)$row['id']; ?>)" title="Edit"><i class="fas fa-edit"></i></button>
<button class="action-btn btn-delete icon-only" onclick="confirmDelete(<?= (int)$row['id']; ?>)" title="Hapus"><i class="fas fa-trash-alt"></i></button>
</div></td>
</tr>
<?php endwhile; else: ?><tr><td colspan="11" style="text-align:center; padding:30px; color:#6c757d;">Tidak ada data ditemukan.</td></tr><?php endif; ?>
</tbody></table>
<?php
$table = ob_get_clean();
$koneksi->close();
echo json_encode(['table'=>$table,'totalPages'=>$totalPages,'totalData'=>$totalData]);
?>
