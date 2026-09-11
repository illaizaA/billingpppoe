<?php
/**
 * Pengaman periode tagihan bulanan dari sisi aplikasi.
 *
 * Mekanisme:
 * - Dipanggil setiap koneksi melalui db_salam.php.
 * - Tagihan periode lama selalu diarsipkan ke tagihan_salam sebelum periode aktif maju.
 * - Pelanggan tidak aktif tetap dapat menyimpan tagihan terakhirnya ke histori,
 *   tetapi tidak dibuatkan tagihan bulan baru.
 * - Pelanggan aktif yang tertinggal beberapa bulan diproses bulan demi bulan.
 * - Tanggal jatuh tempo kosong memakai akhir bulan periode sebagai fallback.
 * - Aman dipanggil berulang karena histori diharapkan UNIQUE pelanggan+periode.
 * - Tidak mengubah struktur database dan tidak menyentuh PPPoE.
 */
function salamPastikanTagihanBulanBerjalan(mysqli $koneksi): void
{
    $currentMonth = date('Y-m-01');

    /*
     * Proses hanya diperlukan bila:
     * 1) masih ada pelanggan aktif yang periodenya tertinggal; atau
     * 2) ada pelanggan tidak aktif/aktif dengan periode lama yang belum mempunyai
     *    snapshot histori untuk periode tersebut.
     *
     * Dengan kondisi ini, pelanggan tidak aktif yang sudah berhasil diarsipkan
     * tidak memicu proses berulang pada setiap request.
     */
    $check = $koneksi->prepare(
        "SELECT 1
         FROM pelanggan_salam p
         WHERE p.waktu < ?
           AND (
                p.status_pelanggan = 'Aktif'
                OR NOT EXISTS (
                    SELECT 1
                    FROM tagihan_salam t
                    WHERE t.pelanggan_id = p.id
                      AND DATE_FORMAT(t.periode, '%Y-%m') = DATE_FORMAT(p.waktu, '%Y-%m')
                )
           )
         LIMIT 1"
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

        /*
         * Arsipkan kondisi periode lama terlebih dahulu, termasuk pelanggan yang
         * sudah Tidak Aktif. Pelanggan tidak aktif hanya diarsipkan; periodenya
         * tidak dimajukan dan tidak dibuatkan tagihan baru.
         */
        $archiveCurrentOldPeriod = "INSERT INTO tagihan_salam
            (pelanggan_id, periode, id_pelanggan_snapshot, nama_snapshot,
             alamat_snapshot, paket_snapshot, tarif_snapshot, nominal_tagihan,
             status_bayar, tanggal_jatuh_tempo, tanggal_bayar,
             nominal_dibayar, nomor_invoice)
            SELECT p.id,
                   DATE_FORMAT(p.waktu, '%Y-%m-01'),
                   COALESCE(p.id_pelanggan, ''),
                   p.nama,
                   p.alamat,
                   p.paket,
                   COALESCE(p.tarif_langganan, 0),
                   COALESCE(NULLIF(p.tarif_langganan, 0), p.tagihan, 0),
                   p.status_bayar,
                   COALESCE(p.langganan_selesai, LAST_DAY(p.waktu)),
                   p.tanggal_bayar,
                   p.nominal_dibayar,
                   p.nomor_invoice
            FROM pelanggan_salam p
            WHERE p.waktu < '{$currentMonth}'
            ON DUPLICATE KEY UPDATE
                id_pelanggan_snapshot = VALUES(id_pelanggan_snapshot),
                nama_snapshot = VALUES(nama_snapshot),
                alamat_snapshot = VALUES(alamat_snapshot),
                paket_snapshot = VALUES(paket_snapshot),
                tarif_snapshot = VALUES(tarif_snapshot),
                nominal_tagihan = VALUES(nominal_tagihan),
                status_bayar = VALUES(status_bayar),
                tanggal_jatuh_tempo = VALUES(tanggal_jatuh_tempo),
                tanggal_bayar = VALUES(tanggal_bayar),
                nominal_dibayar = VALUES(nominal_dibayar),
                nomor_invoice = VALUES(nomor_invoice)";

        if (!$koneksi->query($archiveCurrentOldPeriod)) {
            throw new RuntimeException($koneksi->error);
        }

        /*
         * Pelanggan aktif yang tertinggal dipindahkan satu bulan per iterasi.
         * Bila masih tertinggal setelah maju satu bulan, periode hasil kemajuan
         * tersebut langsung diarsipkan sebelum iterasi berikutnya. Dengan cara ini
         * Juni -> Juli -> Agustus -> September tidak kehilangan bulan di tengah.
         */
        for ($loop = 0; $loop < $maximumLoops; $loop++) {
            $pending = $koneksi->prepare(
                "SELECT 1
                 FROM pelanggan_salam
                 WHERE status_pelanggan = 'Aktif'
                   AND waktu < ?
                 LIMIT 1
                 FOR UPDATE"
            );
            if (!$pending) throw new RuntimeException($koneksi->error);

            $pending->bind_param('s', $currentMonth);
            $pending->execute();
            $hasPending = (bool) $pending->get_result()->fetch_assoc();
            $pending->close();

            if (!$hasPending) break;

            $advance = "UPDATE pelanggan_salam SET
                    langganan_selesai = LAST_DAY(DATE_ADD(waktu, INTERVAL 1 MONTH)),
                    status_bayar = 'Belum Lunas',
                    tagihan = tarif_langganan,
                    tanggal_bayar = NULL,
                    nominal_dibayar = NULL,
                    nomor_invoice = CONCAT(
                        CASE UPPER(REPLACE(TRIM(COALESCE(alamat, '')), ' ', ''))
                            WHEN 'BARAN' THEN 'BRN'
                            WHEN 'GUNUNGMANUK' THEN 'GNM'
                            WHEN 'NGASEMAYU' THEN 'NGA'
                            WHEN 'SALAM' THEN 'SLM'
                            WHEN 'TROSARI' THEN 'TRS'
                            WHEN 'WADUK' THEN 'WDK'
                            WHEN 'PENGKOK' THEN 'PEN'
                            ELSE UPPER(LEFT(REPLACE(TRIM(COALESCE(alamat, 'XXX')), ' ', ''), 3))
                        END,
                        '/INV/', LPAD(id, 5, '0'), '/',
                        DATE_FORMAT(DATE_ADD(waktu, INTERVAL 1 MONTH), '%m/%Y')
                    ),
                    waktu = DATE_ADD(DATE_FORMAT(waktu, '%Y-%m-01'), INTERVAL 1 MONTH)
                WHERE status_pelanggan = 'Aktif'
                  AND waktu < '{$currentMonth}'";

            if (!$koneksi->query($advance)) {
                throw new RuntimeException($koneksi->error);
            }

            /*
             * Jika setelah maju pelanggan masih tertinggal, arsipkan periode baru
             * tersebut sebagai tagihan Belum Lunas sebelum maju lagi pada iterasi
             * berikutnya. Periode bulan berjalan tidak diarsipkan karena tetap hidup
             * di pelanggan_salam.
             */
            $archiveIntermediate = "INSERT INTO tagihan_salam
                (pelanggan_id, periode, id_pelanggan_snapshot, nama_snapshot,
                 alamat_snapshot, paket_snapshot, tarif_snapshot, nominal_tagihan,
                 status_bayar, tanggal_jatuh_tempo, tanggal_bayar,
                 nominal_dibayar, nomor_invoice)
                SELECT p.id,
                       DATE_FORMAT(p.waktu, '%Y-%m-01'),
                       COALESCE(p.id_pelanggan, ''),
                       p.nama,
                       p.alamat,
                       p.paket,
                       COALESCE(p.tarif_langganan, 0),
                       COALESCE(NULLIF(p.tarif_langganan, 0), p.tagihan, 0),
                       p.status_bayar,
                       COALESCE(p.langganan_selesai, LAST_DAY(p.waktu)),
                       p.tanggal_bayar,
                       p.nominal_dibayar,
                       p.nomor_invoice
                FROM pelanggan_salam p
                WHERE p.status_pelanggan = 'Aktif'
                  AND p.waktu < '{$currentMonth}'
                ON DUPLICATE KEY UPDATE
                    id_pelanggan_snapshot = VALUES(id_pelanggan_snapshot),
                    nama_snapshot = VALUES(nama_snapshot),
                    alamat_snapshot = VALUES(alamat_snapshot),
                    paket_snapshot = VALUES(paket_snapshot),
                    tarif_snapshot = VALUES(tarif_snapshot),
                    nominal_tagihan = VALUES(nominal_tagihan),
                    status_bayar = VALUES(status_bayar),
                    tanggal_jatuh_tempo = VALUES(tanggal_jatuh_tempo),
                    tanggal_bayar = VALUES(tanggal_bayar),
                    nominal_dibayar = VALUES(nominal_dibayar),
                    nomor_invoice = VALUES(nomor_invoice)";

            if (!$koneksi->query($archiveIntermediate)) {
                throw new RuntimeException($koneksi->error);
            }

            if ($loop === $maximumLoops - 1) {
                throw new RuntimeException('Periode pelanggan tertinggal lebih dari 120 bulan.');
            }
        }

        $koneksi->commit();
    } catch (Throwable $e) {
        try {
            $koneksi->rollback();
        } catch (Throwable $ignored) {
        }
        error_log('Pembuatan tagihan bulanan gagal: ' . $e->getMessage());
    } finally {
        $koneksi->query("SELECT RELEASE_LOCK('salam_tagihan_bulanan_v2')");
    }
}
?>
