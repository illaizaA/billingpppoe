<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
require_once __DIR__ . '/config_salam.php';
require_once __DIR__ . '/tagihan_periode_helper.php';
header('Content-Type: application/json; charset=utf-8');
salamRequireLogin();

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = min(100, max(1, (int) ($_GET['limit'] ?? 10)));
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');
$filter = strtolower(trim((string) ($_GET['filter'] ?? 'all')));
$wilayahFilter = trim((string) ($_GET['wilayah'] ?? 'all'));
$periode = salamPeriodeYm($_GET['periode'] ?? date('Y-m'));
$periodeEsc = $koneksi->real_escape_string($periode);

$sourceSql = "
    SELECT
        p.id,
        p.id AS pelanggan_id,
        p.id_pelanggan,
        COALESCE(p.kode_pelanggan,'') AS kode_pelanggan,
        p.nama,
        COALESCE(p.nomor_pelanggan,'') AS nomor_pelanggan,
        p.alamat,
        p.paket,
        p.tarif_langganan,
        p.tagihan,
        p.status_bayar,
        p.status_pelanggan,
        p.waktu,
        p.langganan_selesai,
        p.tanggal_bayar,
        p.nominal_dibayar,
        p.nomor_invoice,
        'berjalan' AS sumber_data
    FROM pelanggan_salam p
    WHERE DATE_FORMAT(p.waktu,'%Y-%m')='{$periodeEsc}'

    UNION ALL

    SELECT
        p.id,
        t.pelanggan_id,
        COALESCE(NULLIF(t.id_pelanggan_snapshot,''),p.id_pelanggan) AS id_pelanggan,
        COALESCE(p.kode_pelanggan,'') AS kode_pelanggan,
        COALESCE(NULLIF(t.nama_snapshot,''),p.nama) AS nama,
        COALESCE(p.nomor_pelanggan,'') AS nomor_pelanggan,
        COALESCE(NULLIF(t.alamat_snapshot,''),p.alamat) AS alamat,
        COALESCE(NULLIF(t.paket_snapshot,''),p.paket) AS paket,
        COALESCE(NULLIF(t.tarif_snapshot,0),p.tarif_langganan,t.nominal_tagihan,0) AS tarif_langganan,
        CASE WHEN t.status_bayar='Lunas' THEN 0 ELSE t.nominal_tagihan END AS tagihan,
        t.status_bayar,
        COALESCE(p.status_pelanggan,'Aktif') AS status_pelanggan,
        t.periode AS waktu,
        t.tanggal_jatuh_tempo AS langganan_selesai,
        t.tanggal_bayar,
        t.nominal_dibayar,
        t.nomor_invoice,
        'riwayat' AS sumber_data
    FROM tagihan_salam t
    LEFT JOIN pelanggan_salam p ON p.id=t.pelanggan_id
    WHERE DATE_FORMAT(t.periode,'%Y-%m')='{$periodeEsc}'
      AND NOT EXISTS (
          SELECT 1 FROM pelanggan_salam p2
          WHERE p2.id=t.pelanggan_id
            AND DATE_FORMAT(p2.waktu,'%Y-%m')=DATE_FORMAT(t.periode,'%Y-%m')
      )
";

$conditions = [salamScopeCondition($koneksi, 'q.alamat')];
if (salamCanAccessAllWilayah() && $wilayahFilter !== '' && strtolower($wilayahFilter) !== 'all') {
    $conditions[] = salamWilayahFilterCondition($koneksi, $wilayahFilter, 'q.alamat');
}
if ($search !== '') {
    $escaped = $koneksi->real_escape_string($search);
    $conditions[] = "(LOWER(q.nama) LIKE LOWER('%{$escaped}%') OR SOUNDEX(q.nama)=SOUNDEX('{$escaped}') OR q.id_pelanggan LIKE '%{$escaped}%' OR q.nomor_pelanggan LIKE '%{$escaped}%' OR q.paket LIKE '%{$escaped}%' OR q.alamat LIKE '%{$escaped}%')";
}
if ($filter === 'lunas') {
    $conditions[] = "q.status_bayar='Lunas'";
} elseif ($filter === 'belum lunas') {
    $conditions[] = "q.status_bayar='Belum Lunas'";
} elseif ($filter === 'aktif') {
    $conditions[] = "q.status_pelanggan='Aktif'";
} elseif ($filter === 'tidak aktif') {
    $conditions[] = "q.status_pelanggan='Tidak Aktif'";
}
$where = ' WHERE ' . implode(' AND ', $conditions);

$countResult = $koneksi->query("SELECT COUNT(*) total FROM ({$sourceSql}) q {$where}");
$totalData = (int) ($countResult?->fetch_assoc()['total'] ?? 0);
$totalPages = max(1, (int) ceil($totalData / $limit));
$result = $koneksi->query("SELECT * FROM ({$sourceSql}) q {$where} ORDER BY q.id ASC LIMIT {$limit} OFFSET {$offset}");

function esc($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function salamPesanTagihanPeriode(array $row): string {
    $periodeYm = salamPeriodeYm((string) ($row['waktu'] ?? date('Y-m')), date('Y-m'));
    $periodeLabel = salamBulananIndonesia(($periodeYm . '-01'), false);
    $masaAktif = salamBulananIndonesia($row['langganan_selesai'] ?? null, true);
    $nominalValue = (float) ($row['tagihan'] ?? 0);
    if ($nominalValue <= 0) $nominalValue = (float) ($row['tarif_langganan'] ?? 0);
    $nominal = salamRupiah($nominalValue);
    $layanan = salamNamaLayananUntukAlamat($row['alamat'] ?? '');
    $pembayaran = salamInfoPembayaranUntukAlamat($row['alamat'] ?? '');
    $isTunggakanLama = $periodeYm < date('Y-m') && (($row['status_bayar'] ?? 'Belum Lunas') !== 'Lunas');

    $pembuka = $isTunggakanLama
        ? "Kami informasikan bahwa hingga saat ini masih terdapat tunggakan tagihan layanan {$layanan} untuk periode {$periodeLabel} yang belum dilunasi."
        : "Berikut informasi tagihan layanan {$layanan}.";

    return "Halo Bapak/Ibu " . ($row['nama'] ?? '-') . ",\n\n"
        . $pembuka . "\n\n"
        . "ID Pelanggan: " . ($row['id_pelanggan'] ?? '-') . "\n"
        . "Paket: " . ($row['paket'] ?? '-') . "\n"
        . "Periode Tagihan: {$periodeLabel}\n"
        . "Masa Aktif Sampai: {$masaAktif}\n"
        . "Nominal Tagihan: {$nominal}\n"
        . "Status: " . ($row['status_bayar'] ?? 'Belum Lunas') . "\n\n"
        . $pembayaran . "\n\n"
        . "Mohon mengirimkan bukti pembayaran untuk proses konfirmasi.\n"
        . "Terima kasih.";
}
function salamPesanSemuaTunggakan(array $row, array $tunggakan): string {
    $layanan = salamNamaLayananUntukAlamat($row['alamat'] ?? '');
    $pembayaran = salamInfoPembayaranUntukAlamat($row['alamat'] ?? '');
    $lines = [];
    $total = 0.0;
    foreach ($tunggakan as $item) {
        $total += (float) ($item['nominal'] ?? 0);
        $lines[] = '- ' . salamBulananIndonesia(($item['periode'] ?? '') . '-01', false) . ': ' . salamRupiah($item['nominal'] ?? 0);
    }
    return "Halo Bapak/Ibu " . ($row['nama'] ?? '-') . ",\n\n"
        . "Kami informasikan bahwa hingga saat ini masih terdapat beberapa tagihan layanan {$layanan} yang belum dilunasi.\n\n"
        . "ID Pelanggan: " . ($row['id_pelanggan'] ?? '-') . "\n"
        . "Paket: " . ($row['paket'] ?? '-') . "\n\n"
        . "Rincian tunggakan:\n"
        . implode("\n", $lines) . "\n\n"
        . "Total Tunggakan: " . salamRupiah($total) . "\n\n"
        . $pembayaran . "\n\n"
        . "Mohon mengirimkan bukti pembayaran untuk proses konfirmasi.\n"
        . "Terima kasih.";
}

ob_start();
?>
<table class="modern-table">
    <thead><tr><th>No</th><th>ID Pelanggan</th><th>Nama Pelanggan</th><th>Alamat</th><th>Status Pelanggan</th><th>Paket</th><th>Masa Aktif Sampai</th><th>Tagihan Bulan</th><th>Tagihan</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody>
<?php if ($result && $result->num_rows > 0): ?>
<?php $no = $offset + 1; while ($row = $result->fetch_assoc()):
    $pelangganId = (int) ($row['pelanggan_id'] ?? $row['id']);
    $wa = salamNormalisasiWhatsApp($row['nomor_pelanggan'] ?? '');
    $statusPelanggan = ($row['status_pelanggan'] ?? 'Aktif') === 'Tidak Aktif' ? 'Tidak Aktif' : 'Aktif';
    $statusBayar = ($row['status_bayar'] ?? 'Belum Lunas') === 'Lunas' ? 'Lunas' : 'Belum Lunas';
    $rowPeriode = salamPeriodeYm((string) ($row['waktu'] ?? $periode), $periode);
    $currentMessage = salamPesanTagihanPeriode($row);
    $tunggakan = salamListTunggakanPelanggan($koneksi, $pelangganId);
    $allMessage = count($tunggakan) > 0 ? salamPesanSemuaTunggakan($row, $tunggakan) : $currentMessage;
    $isCurrentUnpaid = $statusBayar === 'Belum Lunas';
?>
<tr>
<td><?= $no++; ?></td>
<td><span class="id-pill"><?= esc($row['id_pelanggan'] ?: '-'); ?></span></td>
<td><div class="name-cell"><?= esc($row['nama']); ?></div><small class="muted"><?= esc($row['nomor_pelanggan'] ?: '-'); ?></small></td>
<td><?= esc(salamNamaWilayahTampilan($row['alamat'] ?: '-')); ?></td>
<td><button type="button" class="badge <?= $statusPelanggan === 'Aktif' ? 'badge-success' : 'badge-danger'; ?>" onclick="updateStatusPelanggan(<?= $pelangganId; ?>, '<?= $statusPelanggan; ?>')" title="Klik untuk mengubah status pelanggan" style="border:0;cursor:pointer;"><?= esc($statusPelanggan); ?></button></td>
<td><?= esc($row['paket']); ?></td>
<td class="muted"><?= esc(salamBulananIndonesia($row['langganan_selesai'] ?? null, true)); ?></td>
<td><?= esc(salamBulananIndonesia($row['waktu'] ?? null, false)); ?></td>
<td><strong><?= esc(salamRupiah($row['tagihan'] ?? 0)); ?></strong></td>
<td><span class="badge <?= $statusBayar === 'Lunas' ? 'badge-success' : 'badge-danger'; ?>" onclick="updateStatus(<?= $pelangganId; ?>, '<?= $statusBayar; ?>', '<?= esc($rowPeriode); ?>')" title="Klik untuk ubah status periode <?= esc($rowPeriode); ?>"><?= esc($statusBayar); ?></span></td>
<td class="actions-cell"><div class="action-row">
<?php if ($wa !== ''): ?>
<button type="button" class="action-btn btn-tagihan" onclick="openTagihanChoice(this)"
    data-wa="<?= esc($wa); ?>"
    data-current-message="<?= esc(rawurlencode($currentMessage)); ?>"
    data-all-message="<?= esc(rawurlencode($allMessage)); ?>"
    data-arrears-count="<?= count($tunggakan); ?>"
    data-current-unpaid="<?= $isCurrentUnpaid ? '1' : '0'; ?>"
    data-period-label="<?= esc(salamBulananIndonesia($row['waktu'] ?? null, false)); ?>">Kirim Tagihan</button>
<?php else: ?>
<button type="button" class="action-btn btn-tagihan" onclick="Swal.fire({icon:'warning',title:'Nomor WhatsApp belum valid',text:'Isi nomor pelanggan dengan format 08..., +62..., atau 62... terlebih dahulu.'})">Kirim Tagihan</button>
<?php endif; ?>
<button class="action-btn btn-resi" onclick="sendReceipt(<?= $pelangganId; ?>, '<?= esc($wa); ?>', '<?= $statusBayar; ?>', '<?= esc($rowPeriode); ?>')">Kirim Resi</button>
<button class="action-btn btn-print" onclick="window.open('nota_salam.php?id=<?= $pelangganId; ?>&periode=<?= esc($rowPeriode); ?>', '_blank')">Print Nota</button>
<button class="action-btn btn-edit icon-only" onclick="openEditModal(<?= $pelangganId; ?>, '<?= esc($rowPeriode); ?>')" title="Edit"><i class="fas fa-edit"></i></button>
<?php if (($row['sumber_data'] ?? '') === 'berjalan'): ?>
<button class="action-btn btn-delete icon-only" onclick="confirmDelete(<?= $pelangganId; ?>)" title="Hapus"><i class="fas fa-trash-alt"></i></button>
<?php endif; ?>
</div></td>
</tr>
<?php endwhile; else: ?><tr><td colspan="11" style="text-align:center; padding:30px; color:#6c757d;">Tidak ada data ditemukan untuk periode ini.</td></tr><?php endif; ?>
</tbody></table>
<?php
$table = ob_get_clean();
$koneksi->close();
echo json_encode(['table'=>$table,'totalPages'=>$totalPages,'totalData'=>$totalData,'periode'=>$periode]);
?>
