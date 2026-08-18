<?php
/**
 * Helper periode tagihan untuk Dashboard Billing.
 * Tidak mengubah struktur database. Data bulan berjalan tetap berasal dari
 * pelanggan_salam, sedangkan periode lama dibaca dari tagihan_salam.
 */

function salamPeriodeYm(?string $value, ?string $fallback = null): string
{
    $value = trim((string) $value);
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
        return $value;
    }
    if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])-\d{2}$/', $value, $m)) {
        return $m[1] . '-' . $m[2];
    }
    if ($fallback !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $fallback)) {
        return $fallback;
    }
    return date('Y-m');
}

function salamPeriodeTanggalPertama(string $periodeYm): string
{
    return salamPeriodeYm($periodeYm) . '-01';
}

function salamFindTagihanPeriode(mysqli $koneksi, int $pelangganId, string $periodeYm): ?array
{
    if ($pelangganId <= 0) return null;
    $periodeYm = salamPeriodeYm($periodeYm);

    $scopeCurrent = salamScopeCondition($koneksi, 'p.alamat');
    $stmt = $koneksi->prepare("SELECT p.*, p.id AS pelanggan_id, 'berjalan' AS sumber_data, NULL AS tagihan_riwayat_id
        FROM pelanggan_salam p
        WHERE p.id = ? AND DATE_FORMAT(p.waktu, '%Y-%m') = ? AND {$scopeCurrent}
        LIMIT 1");
    $stmt->bind_param('is', $pelangganId, $periodeYm);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($row) return $row;

    $scopeHistory = salamScopeCondition($koneksi, 't.alamat_snapshot');
    $stmt = $koneksi->prepare("SELECT
            p.id,
            t.pelanggan_id,
            COALESCE(NULLIF(t.id_pelanggan_snapshot,''), p.id_pelanggan) AS id_pelanggan,
            COALESCE(p.kode_pelanggan,'') AS kode_pelanggan,
            COALESCE(NULLIF(t.nama_snapshot,''), p.nama) AS nama,
            COALESCE(p.nomor_pelanggan,'') AS nomor_pelanggan,
            COALESCE(NULLIF(t.alamat_snapshot,''), p.alamat) AS alamat,
            COALESCE(NULLIF(t.paket_snapshot,''), p.paket) AS paket,
            COALESCE(NULLIF(t.tarif_snapshot,0), p.tarif_langganan, t.nominal_tagihan, 0) AS tarif_langganan,
            CASE WHEN t.status_bayar='Lunas' THEN 0 ELSE t.nominal_tagihan END AS tagihan,
            t.status_bayar,
            COALESCE(p.status_pelanggan,'Aktif') AS status_pelanggan,
            t.periode AS waktu,
            t.tanggal_jatuh_tempo AS langganan_selesai,
            p.tanggal_daftar,
            t.tanggal_bayar,
            t.nominal_dibayar,
            t.nomor_invoice,
            'riwayat' AS sumber_data,
            t.id AS tagihan_riwayat_id,
            t.nominal_tagihan AS nominal_tagihan_asli
        FROM tagihan_salam t
        LEFT JOIN pelanggan_salam p ON p.id=t.pelanggan_id
        WHERE t.pelanggan_id=? AND DATE_FORMAT(t.periode,'%Y-%m')=? AND {$scopeHistory}
        LIMIT 1");
    $stmt->bind_param('is', $pelangganId, $periodeYm);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function salamListTunggakanPelanggan(mysqli $koneksi, int $pelangganId): array
{
    if ($pelangganId <= 0) return [];
    $master = salamFindPelangganById($koneksi, $pelangganId);
    if (!$master) return [];

    $rows = [];
    if (($master['status_bayar'] ?? '') === 'Belum Lunas' && !empty($master['waktu'])) {
        $nominal = (float) ($master['tagihan'] ?? 0);
        if ($nominal <= 0) $nominal = (float) ($master['tarif_langganan'] ?? 0);
        $rows[] = [
            'periode' => salamPeriodeYm((string) $master['waktu']),
            'nominal' => $nominal,
            'sumber_data' => 'berjalan',
        ];
    }

    $scope = salamScopeCondition($koneksi, 't.alamat_snapshot');
    $stmt = $koneksi->prepare("SELECT DATE_FORMAT(t.periode,'%Y-%m') AS periode, t.nominal_tagihan AS nominal
        FROM tagihan_salam t
        WHERE t.pelanggan_id=? AND t.status_bayar='Belum Lunas' AND {$scope}
          AND NOT EXISTS (
              SELECT 1 FROM pelanggan_salam p
              WHERE p.id=t.pelanggan_id
                AND DATE_FORMAT(p.waktu,'%Y-%m')=DATE_FORMAT(t.periode,'%Y-%m')
          )
        ORDER BY t.periode ASC");
    $stmt->bind_param('i', $pelangganId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $rows[] = [
            'periode' => salamPeriodeYm((string) $r['periode']),
            'nominal' => (float) ($r['nominal'] ?? 0),
            'sumber_data' => 'riwayat',
        ];
    }
    $stmt->close();

    usort($rows, fn($a, $b) => strcmp($a['periode'], $b['periode']));
    return $rows;
}

function salamUpdateStatusTagihanPeriode(mysqli $koneksi, int $pelangganId, string $periodeYm, string $status): bool
{
    if (!in_array($status, ['Lunas', 'Belum Lunas'], true)) return false;
    $record = salamFindTagihanPeriode($koneksi, $pelangganId, $periodeYm);
    if (!$record) return false;

    if (($record['sumber_data'] ?? '') === 'berjalan') {
        if ($status === 'Lunas') {
            if (($record['status_bayar'] ?? '') === 'Lunas') return true;
            $nominal = (float) ($record['tagihan'] ?? 0);
            if ($nominal <= 0) $nominal = (float) ($record['tarif_langganan'] ?? 0);
            $stmt = $koneksi->prepare("UPDATE pelanggan_salam
                SET status_bayar='Lunas', nominal_dibayar=?, tanggal_bayar=CURDATE(), tagihan=0
                WHERE id=?");
            $stmt->bind_param('di', $nominal, $pelangganId);
        } else {
            $stmt = $koneksi->prepare("UPDATE pelanggan_salam
                SET status_bayar='Belum Lunas', tagihan=tarif_langganan, nominal_dibayar=NULL, tanggal_bayar=NULL
                WHERE id=?");
            $stmt->bind_param('i', $pelangganId);
        }
    } else {
        $historyId = (int) ($record['tagihan_riwayat_id'] ?? 0);
        if ($historyId <= 0) return false;
        if ($status === 'Lunas') {
            if (($record['status_bayar'] ?? '') === 'Lunas') return true;
            $nominal = (float) ($record['nominal_tagihan_asli'] ?? $record['tarif_langganan'] ?? 0);
            $stmt = $koneksi->prepare("UPDATE tagihan_salam
                SET status_bayar='Lunas', nominal_dibayar=?, tanggal_bayar=CURDATE()
                WHERE id=?");
            $stmt->bind_param('di', $nominal, $historyId);
        } else {
            $stmt = $koneksi->prepare("UPDATE tagihan_salam
                SET status_bayar='Belum Lunas', nominal_dibayar=NULL, tanggal_bayar=NULL
                WHERE id=?");
            $stmt->bind_param('i', $historyId);
        }
    }

    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}
