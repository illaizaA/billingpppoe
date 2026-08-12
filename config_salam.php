<?php
/*
 | Config WhatsApp/pembayaran per wilayah.
 | Nilai config disimpan di config_wilayah.json agar dapat diedit
 | oleh Super Admin tanpa menambah tabel atau kolom database.
 |
 | Setiap wilayah dapat memiliki metode pembayaran tanpa batas, misalnya:
 | - 2 rekening bank
 | - 1 bank + beberapa e-wallet
 | - bank saja
 | - e-wallet saja
 |
 | Nama bank dan nama e-wallet bebas diisi.
 */

function salamPathConfigWilayah(): string
{
    return __DIR__ . '/config_wilayah.json';
}

function salamConfigDefaultWilayah(): array
{
    return [
        'baran' => salamBuatConfigDefault('BARAN'),
        'gunungmanuk' => salamBuatConfigDefault('GUNUNGMANUK'),
        'ngasemayu' => salamBuatConfigDefault('NGASEMAYU'),
        'salam' => salamBuatConfigDefault('SALAM', [
            [
                'jenis' => 'bank',
                'nama' => 'BCA',
                'nomor' => '8950258552',
                'atas_nama' => 'Witono',
            ],
        ], '6287839817566', ''),
        'trosari' => salamBuatConfigDefault('TROSARI'),
        'waduk' => salamBuatConfigDefault('WADUK'),
    ];
}

function salamBuatConfigDefault(
    string $wilayah,
    array $metodePembayaran = [],
    string $nomorWhatsApp = '',
    ?string $catatanPembayaran = null
): array {
    return [
        'wilayah' => $wilayah,
        'nama_layanan' => 'Billing ' . $wilayah . ' / UKOOMED',
        'metode_pembayaran' => $metodePembayaran,
        'nomor_whatsapp' => $nomorWhatsApp,
        'catatan_pembayaran' => $catatanPembayaran
            ?? ('Konfirmasikan pembayaran kepada admin wilayah ' . $wilayah . '.'),
    ];
}

function salamBacaConfigWilayahFile(): array
{
    $path = salamPathConfigWilayah();
    if (!is_file($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return [];
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function salamNormalisasiNomorConfig(?string $value): string
{
    $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
    if ($digits === '') {
        return '';
    }

    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }

    if (str_starts_with($digits, '0')) {
        $digits = '62' . substr($digits, 1);
    } elseif (str_starts_with($digits, '8')) {
        $digits = '62' . $digits;
    }

    return $digits;
}

function salamNormalisasiJenisMetode(?string $value): string
{
    $jenis = strtolower(trim((string) $value));
    $jenis = str_replace(['-', '_', ' '], '', $jenis);

    return in_array($jenis, ['ewallet', 'dompetdigital'], true)
        ? 'ewallet'
        : 'bank';
}

/**
 * Mengubah format metode pembayaran baru maupun format config lama
 * menjadi array seragam:
 * [
 *   ['jenis' => 'bank|ewallet', 'nama' => '', 'nomor' => '', 'atas_nama' => '']
 * ]
 */
function salamNormalisasiMetodePembayaran(array $config): array
{
    $hasil = [];

    if (isset($config['metode_pembayaran']) && is_array($config['metode_pembayaran'])) {
        foreach ($config['metode_pembayaran'] as $metode) {
            if (!is_array($metode)) {
                continue;
            }

            $jenis = salamNormalisasiJenisMetode(
                $metode['jenis'] ?? $metode['tipe'] ?? $metode['type'] ?? 'bank'
            );
            $nama = trim((string) (
                $metode['nama']
                ?? $metode['nama_bank']
                ?? $metode['nama_ewallet']
                ?? ''
            ));
            $nomor = trim((string) (
                $metode['nomor']
                ?? $metode['nomor_rekening']
                ?? $metode['nomor_ewallet']
                ?? ''
            ));
            $atasNama = trim((string) ($metode['atas_nama'] ?? ''));

            if ($nama === '' && $nomor === '' && $atasNama === '') {
                continue;
            }

            $hasil[] = [
                'jenis' => $jenis,
                'nama' => $nama,
                'nomor' => $nomor,
                'atas_nama' => $atasNama,
            ];
        }

        return array_values($hasil);
    }

    // Kompatibilitas config lama: satu bank dan/atau satu e-wallet.
    $namaBank = trim((string) ($config['nama_bank'] ?? ''));
    $nomorRekening = trim((string) ($config['nomor_rekening'] ?? ''));
    $namaEwallet = trim((string) ($config['nama_ewallet'] ?? ''));
    $nomorEwallet = trim((string) ($config['nomor_ewallet'] ?? ''));
    $atasNama = trim((string) ($config['atas_nama'] ?? ''));

    if ($namaBank !== '' || $nomorRekening !== '') {
        $hasil[] = [
            'jenis' => 'bank',
            'nama' => $namaBank,
            'nomor' => $nomorRekening,
            'atas_nama' => $atasNama,
        ];
    }

    if ($namaEwallet !== '' || $nomorEwallet !== '') {
        $hasil[] = [
            'jenis' => 'ewallet',
            'nama' => $namaEwallet,
            'nomor' => $nomorEwallet,
            'atas_nama' => $atasNama,
        ];
    }

    return array_values($hasil);
}

function salamConfigMemilikiDataPembayaranLama(array $config): bool
{
    foreach ([
        'jenis_pembayaran',
        'nama_bank',
        'nomor_rekening',
        'nama_ewallet',
        'nomor_ewallet',
        'atas_nama',
    ] as $key) {
        if (array_key_exists($key, $config)) {
            return true;
        }
    }

    return false;
}

/**
 * Membaca field form dengan format:
 * metode_jenis[]
 * metode_nama[]
 * metode_nomor[]
 * metode_atas_nama[]
 */
function salamAmbilMetodePembayaranDariForm(array $values): array
{
    if (array_key_exists('metode_pembayaran', $values)) {
        return salamNormalisasiMetodePembayaran([
            'metode_pembayaran' => is_array($values['metode_pembayaran'])
                ? $values['metode_pembayaran']
                : [],
        ]);
    }

    $jenisList = is_array($values['metode_jenis'] ?? null)
        ? $values['metode_jenis']
        : [];
    $namaList = is_array($values['metode_nama'] ?? null)
        ? $values['metode_nama']
        : [];
    $nomorList = is_array($values['metode_nomor'] ?? null)
        ? $values['metode_nomor']
        : [];
    $atasNamaList = is_array($values['metode_atas_nama'] ?? null)
        ? $values['metode_atas_nama']
        : [];

    $jumlah = max(
        count($jenisList),
        count($namaList),
        count($nomorList),
        count($atasNamaList)
    );

    $metode = [];
    for ($i = 0; $i < $jumlah; $i++) {
        $metode[] = [
            'jenis' => $jenisList[$i] ?? 'bank',
            'nama' => $namaList[$i] ?? '',
            'nomor' => $nomorList[$i] ?? '',
            'atas_nama' => $atasNamaList[$i] ?? '',
        ];
    }

    return salamNormalisasiMetodePembayaran([
        'metode_pembayaran' => $metode,
    ]);
}

function salamBangunInfoPembayaran(array $config, string $namaWilayah): string
{
    $metodePembayaran = salamNormalisasiMetodePembayaran($config);
    $nomorWhatsApp = salamNormalisasiNomorConfig($config['nomor_whatsapp'] ?? '');
    $catatan = trim((string) ($config['catatan_pembayaran'] ?? ''));

    $bagian = [];

    if ($metodePembayaran !== []) {
        $daftar = ['Pembayaran dapat dilakukan melalui:'];

        foreach ($metodePembayaran as $index => $metode) {
            $nomorUrut = $index + 1;
            $jenis = $metode['jenis'];
            $nama = trim((string) $metode['nama']);
            $nomor = trim((string) $metode['nomor']);
            $atasNama = trim((string) $metode['atas_nama']);

            $teks = $nomorUrut . ') ';
            if ($jenis === 'ewallet') {
                $teks .= 'E-Wallet';
                if ($nama !== '') {
                    $teks .= ' ' . $nama;
                }
                if ($nomor !== '') {
                    $teks .= ' - Nomor ' . $nomor;
                }
            } else {
                $teks .= 'Bank';
                if ($nama !== '') {
                    $teks .= ' ' . $nama;
                }
                if ($nomor !== '') {
                    $teks .= ' - No. rek ' . $nomor;
                }
            }

            if ($atasNama !== '') {
                $teks .= ' atas nama ' . $atasNama;
            }

            $daftar[] = $teks;
        }

        $bagian[] = implode("\n", $daftar);
    }

    if ($nomorWhatsApp !== '') {
        $bagian[] = 'Konfirmasikan pembayaran ke WhatsApp admin: wa.me/' . $nomorWhatsApp;
    }

    if ($catatan !== '') {
        $bagian[] = $catatan;
    }

    if ($bagian === []) {
        $bagian[] = 'Konfirmasikan pembayaran kepada admin wilayah ' . $namaWilayah . '.';
    }

    return implode("\n\n", $bagian);
}

/**
 * Menambahkan field lama pada hasil baca supaya halaman lama yang masih
 * memanggil nama_bank/nama_ewallet tidak langsung error.
 */
function salamTambahkanFieldKompatibilitas(array $config): array
{
    $config['nama_bank'] = '';
    $config['nomor_rekening'] = '';
    $config['nama_ewallet'] = '';
    $config['nomor_ewallet'] = '';
    $config['atas_nama'] = '';

    foreach ($config['metode_pembayaran'] ?? [] as $metode) {
        if (($metode['jenis'] ?? '') === 'bank' && $config['nama_bank'] === '') {
            $config['nama_bank'] = (string) ($metode['nama'] ?? '');
            $config['nomor_rekening'] = (string) ($metode['nomor'] ?? '');
            $config['atas_nama'] = (string) ($metode['atas_nama'] ?? '');
        }

        if (($metode['jenis'] ?? '') === 'ewallet' && $config['nama_ewallet'] === '') {
            $config['nama_ewallet'] = (string) ($metode['nama'] ?? '');
            $config['nomor_ewallet'] = (string) ($metode['nomor'] ?? '');
            if ($config['atas_nama'] === '') {
                $config['atas_nama'] = (string) ($metode['atas_nama'] ?? '');
            }
        }
    }

    return $config;
}

function salamSemuaConfigWilayah(): array
{
    $defaults = salamConfigDefaultWilayah();
    $saved = salamBacaConfigWilayahFile();
    $merged = $defaults;

    foreach ($saved as $rawKey => $value) {
        if (!is_array($value)) {
            continue;
        }

        $key = function_exists('salamNormalisasiKunci')
            ? salamNormalisasiKunci((string) $rawKey)
            : strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $rawKey) ?? '');

        if ($key === '') {
            continue;
        }

        $wilayah = trim((string) ($value['wilayah'] ?? $rawKey));
        if (function_exists('salamNormalisasiAlamatInput')) {
            $wilayah = salamNormalisasiAlamatInput($wilayah);
        } else {
            $wilayah = strtoupper($wilayah);
        }

        if ($wilayah === '') {
            continue;
        }

        $base = $merged[$key] ?? salamBuatConfigDefault($wilayah);

        // Migrasi config lama hanya bila field pembayaran memang ada di file JSON.
        if (array_key_exists('metode_pembayaran', $value)) {
            $value['metode_pembayaran'] = salamNormalisasiMetodePembayaran($value);
        } elseif (salamConfigMemilikiDataPembayaranLama($value)) {
            $value['metode_pembayaran'] = salamNormalisasiMetodePembayaran($value);
        }

        $merged[$key] = array_merge($base, $value, ['wilayah' => $wilayah]);
    }

    foreach ($merged as $key => &$config) {
        $namaWilayah = trim((string) ($config['wilayah'] ?? strtoupper($key)));
        if ($namaWilayah === '') {
            $namaWilayah = strtoupper($key);
        }

        if (trim((string) ($config['nama_layanan'] ?? '')) === '') {
            $config['nama_layanan'] = 'Billing ' . $namaWilayah . ' / UKOOMED';
        }

        $config['metode_pembayaran'] = salamNormalisasiMetodePembayaran($config);
        $config['nomor_whatsapp'] = salamNormalisasiNomorConfig($config['nomor_whatsapp'] ?? '');
        $config['catatan_pembayaran'] = trim((string) ($config['catatan_pembayaran'] ?? ''));
        $config['info_pembayaran'] = salamBangunInfoPembayaran($config, $namaWilayah);
        $config = salamTambahkanFieldKompatibilitas($config);
    }
    unset($config);

    return $merged;
}

function salamSimpanConfigWilayah(string $wilayah, array $values): bool
{
    if (function_exists('salamNormalisasiAlamatInput')) {
        $wilayah = salamNormalisasiAlamatInput($wilayah);
        $key = salamNormalisasiKunci($wilayah);
    } else {
        $wilayah = strtoupper(trim($wilayah));
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', $wilayah) ?? '');
    }

    if ($wilayah === '' || $key === '') {
        return false;
    }

    $saved = salamBacaConfigWilayahFile();
    $current = is_array($saved[$key] ?? null) ? $saved[$key] : [];

    $formMengirimMetode = array_key_exists('metode_pembayaran', $values)
        || array_key_exists('metode_jenis', $values)
        || array_key_exists('metode_nama', $values)
        || array_key_exists('metode_nomor', $values)
        || array_key_exists('metode_atas_nama', $values);

    if ($formMengirimMetode) {
        $metodePembayaran = salamAmbilMetodePembayaranDariForm($values);
    } elseif (salamConfigMemilikiDataPembayaranLama($values)) {
        $metodePembayaran = salamNormalisasiMetodePembayaran($values);
    } else {
        $metodePembayaran = salamNormalisasiMetodePembayaran($current);
    }

    // Bersihkan field pembayaran versi lama dari JSON.
    foreach ([
        'jenis_pembayaran',
        'nama_bank',
        'nomor_rekening',
        'nama_ewallet',
        'nomor_ewallet',
        'atas_nama',
    ] as $legacyKey) {
        unset($current[$legacyKey]);
    }

    $saved[$key] = array_merge($current, [
        'wilayah' => $wilayah,
        'nama_layanan' => trim((string) (
            $values['nama_layanan']
            ?? $current['nama_layanan']
            ?? ('Billing ' . $wilayah . ' / UKOOMED')
        )),
        'metode_pembayaran' => $metodePembayaran,
        'nomor_whatsapp' => salamNormalisasiNomorConfig(
            $values['nomor_whatsapp'] ?? $current['nomor_whatsapp'] ?? ''
        ),
        'catatan_pembayaran' => trim((string) (
            $values['catatan_pembayaran']
            ?? $current['catatan_pembayaran']
            ?? ''
        )),
    ]);

    $json = json_encode(
        $saved,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        return false;
    }

    return file_put_contents(salamPathConfigWilayah(), $json . PHP_EOL, LOCK_EX) !== false;
}

function salamPastikanConfigWilayah(string $wilayah): bool
{
    $key = function_exists('salamNormalisasiKunci')
        ? salamNormalisasiKunci($wilayah)
        : strtolower(preg_replace('/[^a-z0-9]+/i', '', $wilayah) ?? '');

    if ($key === '') {
        return false;
    }

    $saved = salamBacaConfigWilayahFile();
    if (isset($saved[$key]) && is_array($saved[$key])) {
        return true;
    }

    return salamSimpanConfigWilayah($wilayah, [
        'nama_layanan' => 'Billing ' . strtoupper(trim($wilayah)) . ' / UKOOMED',
        'metode_pembayaran' => [],
        'catatan_pembayaran' => 'Konfirmasikan pembayaran kepada admin wilayah '
            . strtoupper(trim($wilayah)) . '.',
    ]);
}

function salamConfigUntukAlamat(?string $alamat): array
{
    $key = salamNormalisasiKunci($alamat);
    $configs = salamSemuaConfigWilayah();

    if (isset($configs[$key])) {
        return $configs[$key];
    }

    $nama = salamNamaWilayahTampilan($alamat);
    $config = salamBuatConfigDefault($nama);
    $config['info_pembayaran'] = salamBangunInfoPembayaran($config, $nama);

    return salamTambahkanFieldKompatibilitas($config);
}

function salamNamaLayananUntukAlamat(?string $alamat): string
{
    return salamConfigUntukAlamat($alamat)['nama_layanan'];
}

function salamInfoPembayaranUntukAlamat(?string $alamat): string
{
    return salamConfigUntukAlamat($alamat)['info_pembayaran'];
}

// Tetap tersedia agar kode lama yang memanggil konstanta ini tidak error.
if (!defined('SALAM_INFO_PEMBAYARAN')) {
    define(
        'SALAM_INFO_PEMBAYARAN',
        salamSemuaConfigWilayah()['salam']['info_pembayaran']
    );
}
?>