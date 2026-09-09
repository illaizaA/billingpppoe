<?php

function salamManualNormalize(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') return '';
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) $value = $converted;
    }
    return preg_replace('/[^a-z0-9]+/', '', strtolower($value)) ?? '';
}

function salamManualPppoeId(array $row): string
{
    return trim((string) ($row['id'] ?? ''));
}

function salamManualPairs(array $row): array
{
    $aliases = [
        'salam'=>'salam','slm'=>'salam','baran'=>'baran','brn'=>'baran',
        'gunungmanuk'=>'gunungmanuk','gunung'=>'gunungmanuk','gnm'=>'gunungmanuk','gm'=>'gunungmanuk',
        'ngasemayu'=>'ngasemayu','ngasem'=>'ngasemayu','nga'=>'ngasemayu',
        'trosari'=>'trosari','trs'=>'trosari','waduk'=>'waduk','wdk'=>'waduk',
    ];
    $pairs = [];
    foreach (['user','username','lokasi','name','nama'] as $field) {
        $raw = trim((string) ($row[$field] ?? ''));
        if ($raw === '' || !str_contains($raw, '-')) continue;
        [$region, $name] = explode('-', $raw, 2);
        $region = salamManualNormalize($region);
        $name = salamManualNormalize($name);
        if ($region === '' || $name === '') continue;
        $region = $aliases[$region] ?? $region;
        $pairs[$name . '|' . $region] = ['name'=>$name, 'address'=>$region];
    }
    return array_values($pairs);
}

function salamManualFetchPppoe(): array
{
    $url = PPPOE_MONITOR_API_URL;
    $timeout = PPPOE_MONITOR_TIMEOUT_SECONDS;
    $body = false;
    $httpCode = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>$timeout, CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_HTTPGET=>true, CURLOPT_HTTPHEADER=>['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new RuntimeException('Sumber PPPoE tidak dapat dihubungi: ' . ($error ?: 'koneksi gagal'));
    } else {
        $context = stream_context_create(['http'=>[
            'method'=>'GET','timeout'=>$timeout,'ignore_errors'=>true,'header'=>"Accept: application/json\r\n"
        ]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) throw new RuntimeException('Sumber PPPoE tidak dapat dibaca dari server Billing.');
    }
    if ($httpCode !== 0 && ($httpCode < 200 || $httpCode >= 300)) {
        throw new RuntimeException('Sumber PPPoE merespons HTTP ' . $httpCode . '.');
    }
    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) throw new RuntimeException('Respons PPPoE bukan JSON yang valid.');
    $rows = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
    return array_values(array_filter($rows, static function ($row): bool {
        return is_array($row)
            && (!array_key_exists('icon', $row) || (int) $row['icon'] === 119)
            && salamManualPppoeId($row) !== '';
    }));
}

function salamManualBillingRows(mysqli $koneksi): array
{
    $scope = salamScopeCondition($koneksi, 'p.alamat');
    $sql = "SELECT p.id,p.id_pelanggan,p.kode_pelanggan,p.nama,p.alamat,p.status_pelanggan,
                   COALESCE(d.nama_ktp,'') nama_ktp,COALESCE(d.pppoe_user,'') pppoe_user,
                   d.koordinat_x,d.koordinat_y
            FROM pelanggan_salam p
            LEFT JOIN pelanggan_detail_salam d ON d.pelanggan_id=p.id
            WHERE {$scope} ORDER BY p.nama,p.id";
    $result = $koneksi->query($sql);
    if (!$result) throw new RuntimeException('Data pelanggan Billing tidak dapat dibaca.');
    $rows = [];
    while ($row = $result->fetch_assoc()) { $row['id']=(int)$row['id']; $rows[]=$row; }
    $result->free();
    return $rows;
}

function salamManualSimilarity(string $left, string $right): float
{
    if ($left === '' || $right === '') return 0.0;
    if ($left === $right) return 100.0;
    similar_text($left, $right, $percent);
    return (float) $percent;
}

function salamManualDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r=6371000.0; $dLat=deg2rad($lat2-$lat1); $dLon=deg2rad($lon2-$lon1);
    $a=sin($dLat/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;
    return $r*2*atan2(sqrt($a),sqrt(1-$a));
}

function salamManualCandidate(array $billing, array $pppoe): ?array
{
    $address = salamManualNormalize($billing['alamat'] ?? '');
    if ($address === '') return null;
    $best = 0.0;
    $sameAddress = false;
    foreach (salamManualPairs($pppoe) as $pair) {
        if ($pair['address'] !== $address) continue;
        $sameAddress = true;
        $best = max($best,
            salamManualSimilarity(salamManualNormalize($billing['nama'] ?? ''), $pair['name']),
            salamManualSimilarity(salamManualNormalize($billing['nama_ktp'] ?? ''), $pair['name'])
        );
    }
    if (!$sameAddress) return null;
    $distance = null;
    $x=$billing['koordinat_x']??null; $y=$billing['koordinat_y']??null;
    $lat=$pppoe['latitude']??$pppoe['lat']??null; $lon=$pppoe['longitude']??$pppoe['lng']??$pppoe['lon']??null;
    if (is_numeric($x)&&is_numeric($y)&&is_numeric($lat)&&is_numeric($lon)) {
        $distance=salamManualDistance((float)$y,(float)$x,(float)$lat,(float)$lon);
    }
    return ['score'=>$best,'distance'=>$distance];
}

function salamManualPublicPppoe(array $row, array $rank=[]): array
{
    return [
        'id'=>salamManualPppoeId($row),
        'user'=>(string)($row['user']??$row['username']??''),
        'lokasi'=>(string)($row['lokasi']??$row['name']??''),
        'status'=>strtoupper((string)($row['status']??'UNKNOWN')),
        'latitude'=>$row['latitude']??$row['lat']??null,
        'longitude'=>$row['longitude']??$row['lng']??$row['lon']??null,
        'score'=>round((float)($rank['score']??0),1),
        'distance'=>isset($rank['distance'])&&$rank['distance']!==null?round((float)$rank['distance'],1):null,
    ];
}

