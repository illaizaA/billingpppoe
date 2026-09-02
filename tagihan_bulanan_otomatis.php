<?php
/**
 * Pengaman periode tagihan bulanan dari sisi aplikasi.
 *
 * - Dipanggil setiap koneksi, tetapi hanya bekerja bila ada pelanggan aktif
 *   yang periodenya tertinggal.
 * - Memproses bulan yang terlewat satu per satu agar tidak ada periode hilang.
 * - Aman dipanggil berulang karena histori memiliki UNIQUE pelanggan+periode.
 * - Tidak mengubah struktur database dan tidak menyentuh PPPoE.
 */
function salamPastikanTagihanBulanBerjalan(mysqli $koneksi): void
{
    $currentMonth = date('Y-m-01');
    $check = $koneksi->prepare(
        "SELECT 1 FROM pelanggan_salam
         WHERE status_pelanggan='Aktif' AND waktu < ? LIMIT 1"
    );
    if (!$check) {
        error_log('Pemeriksaan periode tagihan gagal: ' . $koneksi->error);
        return;
    }
    $check->bind_param('s', $currentMonth);
    $check->execute();
    $outdated = (bool) $check->get_result()->fetch_assoc();
    $check->close();
    if (!$outdated) return;

    $lockResult = $koneksi->query("SELECT GET_LOCK('salam_tagihan_bulanan_v2', 10) AS locked");
    $lockRow = $lockResult instanceof mysqli_result ? $lockResult->fetch_assoc() : null;
    if ((int) ($lockRow['locked'] ?? 0) !== 1) {
        error_log('Periode tagihan belum diproses karena sedang dikerjakan proses lain.');
        return;
    }

    try {
        $koneksi->begin_transaction();
        $maximumLoops = 120;

        for ($loop = 0; $loop < $maximumLoops; $loop++) {
            $pending = $koneksi->prepare(
                "SELECT 1 FROM pelanggan_salam
                 WHERE status_pelanggan='Aktif' AND waktu < ? LIMIT 1 FOR UPDATE"
            );
            if (!$pending) throw new RuntimeException($koneksi->error);
            $pending->bind_param('s', $currentMonth);
            $pending->execute();
            $hasPending = (bool) $pending->get_result()->fetch_assoc();
            $pending->close();
            if (!$hasPending) break;

            $archive = "INSERT INTO tagihan_salam
                (pelanggan_id, periode, id_pelanggan_snapshot, nama_snapshot,
                 alamat_snapshot, paket_snapshot, tarif_snapshot, nominal_tagihan,
                 status_bayar, tanggal_jatuh_tempo, tanggal_bayar,
                 nominal_dibayar, nomor_invoice)
                SELECT p.id, DATE_FORMAT(p.waktu,'%Y-%m-01'),
                       COALESCE(p.id_pelanggan,''), p.nama, p.alamat, p.paket,
                       COALESCE(p.tarif_langganan,0),
                       COALESCE(NULLIF(p.tarif_langganan,0),p.tagihan,0),
                       p.status_bayar, p.langganan_selesai, p.tanggal_bayar,
                       p.nominal_dibayar, p.nomor_invoice
                FROM pelanggan_salam p
                WHERE p.status_pelanggan='Aktif' AND p.waktu < '{$currentMonth}'
                ON DUPLICATE KEY UPDATE
                    id_pelanggan_snapshot=VALUES(id_pelanggan_snapshot),
                    nama_snapshot=VALUES(nama_snapshot),
                    alamat_snapshot=VALUES(alamat_snapshot),
                    paket_snapshot=VALUES(paket_snapshot),
                    tarif_snapshot=VALUES(tarif_snapshot),
                    nominal_tagihan=VALUES(nominal_tagihan),
                    status_bayar=VALUES(status_bayar),
                    tanggal_jatuh_tempo=VALUES(tanggal_jatuh_tempo),
                    tanggal_bayar=VALUES(tanggal_bayar),
                    nominal_dibayar=VALUES(nominal_dibayar),
                    nomor_invoice=VALUES(nomor_invoice)";
            if (!$koneksi->query($archive)) throw new RuntimeException($koneksi->error);

            $advance = "UPDATE pelanggan_salam SET
                    langganan_selesai=LAST_DAY(DATE_ADD(waktu,INTERVAL 1 MONTH)),
                    status_bayar='Belum Lunas', tagihan=tarif_langganan,
                    tanggal_bayar=NULL, nominal_dibayar=NULL,
                    nomor_invoice=CONCAT(
                        CASE UPPER(REPLACE(TRIM(COALESCE(alamat,'')),' ',''))
                            WHEN 'BARAN' THEN 'BRN'
                            WHEN 'GUNUNGMANUK' THEN 'GNM'
                            WHEN 'NGASEMAYU' THEN 'NGA'
                            WHEN 'SALAM' THEN 'SLM'
                            WHEN 'TROSARI' THEN 'TRS'
                            WHEN 'WADUK' THEN 'WDK'
                            WHEN 'PENGKOK' THEN 'PEN'
                            ELSE UPPER(LEFT(REPLACE(TRIM(COALESCE(alamat,'XXX')),' ',''),3))
                        END,
                        '/INV/',LPAD(id,5,'0'),'/',
                        DATE_FORMAT(DATE_ADD(waktu,INTERVAL 1 MONTH),'%m/%Y')),
                    waktu=DATE_ADD(DATE_FORMAT(waktu,'%Y-%m-01'),INTERVAL 1 MONTH)
                WHERE status_pelanggan='Aktif' AND waktu < '{$currentMonth}'";
            if (!$koneksi->query($advance)) throw new RuntimeException($koneksi->error);

            if ($loop === $maximumLoops - 1) {
                throw new RuntimeException('Periode pelanggan tertinggal lebih dari 120 bulan.');
            }
        }

        $koneksi->commit();
    } catch (Throwable $e) {
        try { $koneksi->rollback(); } catch (Throwable $ignored) {}
        error_log('Pembuatan tagihan bulanan gagal: ' . $e->getMessage());
    } finally {
        $koneksi->query("SELECT RELEASE_LOCK('salam_tagihan_bulanan_v2')");
    }
}
?>
