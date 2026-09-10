<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
require_once __DIR__ . '/analitik_helper.php';

analitikRequireAccess();

$range = analitikResolvePeriodRange($_GET);
$scope = analitikResolveWilayah($_GET['wilayah'] ?? 'all');
$isSuperAdminAnalitik = salamIsSuperAdmin();
$isAdminSemuaWilayahAnalitik = salamIsAdminSemuaWilayah();
$canPilihWilayahAnalitik = $isSuperAdminAnalitik || $isAdminSemuaWilayahAnalitik;
$isAdminWilayahAnalitik = !$canPilihWilayahAnalitik;
$roleLabelAnalitik = $isSuperAdminAnalitik
    ? 'Super Admin'
    : ($isAdminSemuaWilayahAnalitik ? 'Admin Semua Wilayah' : 'Admin Wilayah');
$bulanOptions = [
    '01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni',
    '07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember',
];
$yearOptions = analitikYearOptions($koneksi, $range['tahun_awal'], $range['tahun_akhir']);
$wilayahOptions = array_values(salamDaftarWilayahResmi());

$errorAnalitik = null;
try {
    $analitik = analitikBuild($koneksi, $range['awal'], $range['akhir'], $scope);
} catch (Throwable $e) {
    $errorAnalitik = $e->getMessage();
    $analitik = [
        'summary'=>['pelanggan'=>0,'tagihan'=>0,'lunas'=>0,'belum'=>0,'tingkat_bayar'=>0,'total_dibayar'=>0,'total_tunggakan'=>0],
        'trend'=>[], 'region_unpaid'=>[], 'outstanding'=>[],
        'aging'=>['1'=>0,'2'=>0,'3plus'=>0],
        'timeliness'=>['before'=>0,'on'=>0,'late'=>0,'unpaid'=>0,'unknown'=>0], 'top5'=>[], 'unpaid_customers'=>[], 'region_comparison'=>[],
    ];
}

$trend = $analitik['trend'];
$regionUnpaid = $analitik['region_unpaid'];
$outstanding = $analitik['outstanding'];
$top5 = $analitik['top5'];
$unpaidCustomers = $analitik['unpaid_customers'] ?? [];
$regionComparison = $analitik['region_comparison'] ?? [];
$priorityCustomers = array_slice(array_values($unpaidCustomers), 0, 4);
$priorityOutstanding = array_sum(array_map(static fn($r) => (float)($r['total_tunggakan'] ?? 0), $unpaidCustomers));

$rangeLabel = $range['filter_tipe'] === 'tanggal'
    ? analitikDateLabel($range['tanggal_awal']) . ' - ' . analitikDateLabel($range['tanggal_akhir'])
    : analitikPeriodLabel($range['awal']) . ' - ' . analitikPeriodLabel($range['akhir']);

$trendRates = array_column($trend, 'persen');
$trendMax = max(100, (int) ceil((max($trendRates ?: [0]) + 5) / 10) * 10);
$trendWidth = max(460, count($trend) * 72);
$trendRenderMinWidth = count($trend) > 6 ? max(520, count($trend) * 68) : 0;
$trendHeight = 230;
$plotLeft = 42;
$plotRight = 20;
$plotTop = 25;
$plotBottom = 46;
$plotW = $trendWidth - $plotLeft - $plotRight;
$plotH = $trendHeight - $plotTop - $plotBottom;
$points = [];
foreach ($trend as $i => $item) {
    $x = count($trend) <= 1 ? $plotLeft + $plotW / 2 : $plotLeft + ($plotW * $i / (count($trend)-1));
    $y = $plotTop + $plotH - (($item['persen'] / $trendMax) * $plotH);
    $points[] = ['x'=>$x,'y'=>$y,'item'=>$item];
}
$path = '';
foreach ($points as $i => $p) $path .= ($i === 0 ? 'M' : ' L') . round($p['x'], 1) . ' ' . round($p['y'], 1);
$areaPath = '';
if ($points) {
    $firstPoint = $points[0];
    $lastPoint = $points[count($points) - 1];
    $baselineY = $plotTop + $plotH;
    $areaPath = 'M' . round($firstPoint['x'], 1) . ' ' . round($baselineY, 1)
        . ' L' . round($firstPoint['x'], 1) . ' ' . round($firstPoint['y'], 1);
    foreach (array_slice($points, 1) as $point) {
        $areaPath .= ' L' . round($point['x'], 1) . ' ' . round($point['y'], 1);
    }
    $areaPath .= ' L' . round($lastPoint['x'], 1) . ' ' . round($baselineY, 1) . ' Z';
}

$maxRegionPercent = max(1, ...array_map(fn($r)=>(float)$r['persen'], $regionUnpaid ?: [['persen'=>0]]));
$maxOutstanding = max(1, ...array_map(fn($r)=>(float)$r['value'], $outstanding ?: [['value'=>0]]));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ringkasan Analitik Billing / UKOOMED</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <link rel="icon" href="logo_cleon.png" type="image/png">
    <style>
        :root{--primary:#3498db;--secondary:#2c3e50;--success:#27ae60;--warning:#f39c12;--danger:#e74c3c;--purple:#8e44ad;--muted:#718096;--border:#e6ebf1;--bg:#f4f7fb;--card:#fff;--ink:#243244}
        *{box-sizing:border-box} body{margin:0;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:var(--bg);color:var(--ink)}
        .header{background:linear-gradient(135deg,var(--secondary),#1a2530);color:#fff;padding:14px clamp(16px,3vw,30px);display:flex;justify-content:space-between;align-items:center;gap:12px;position:sticky;top:0;z-index:50;box-shadow:0 4px 12px rgba(0,0,0,.1)}
        .header h1{font-size:clamp(18px,2vw,23px);margin:0;display:flex;align-items:center;gap:10px}.header-actions{display:flex;gap:9px;flex-wrap:wrap;justify-content:flex-end}.nav-btn{color:#fff;text-decoration:none;padding:9px 13px;border-radius:7px;font-weight:650;font-size:13px;display:inline-flex;align-items:center;gap:7px}.back{background:var(--primary)}.report{background:var(--purple)}.logout{background:var(--danger)}
        .container{max-width:1560px;margin:auto;padding:25px clamp(14px,3vw,34px) 40px}.page-head{display:flex;justify-content:space-between;align-items:flex-start;gap:15px;flex-wrap:wrap;margin-bottom:15px}.page-head h2{margin:0;color:var(--secondary);font-size:25px}.page-head p{margin:5px 0 0;color:var(--muted);font-size:13px}.scope-badge{background:#eef6ff;color:#21618c;border:1px solid #cfe4f6;border-radius:999px;padding:8px 12px;font-size:12px;font-weight:700}
        .filter-panel{background:#fff;border:1px solid var(--border);border-radius:14px;padding:16px 18px;box-shadow:0 6px 18px rgba(31,41,55,.05);margin-bottom:18px}.filter-grid{display:grid;grid-template-columns:minmax(165px,.65fr) 1fr 1fr minmax(190px,.8fr) auto;gap:12px;align-items:end}.field label{display:block;font-size:12px;font-weight:700;color:#4a5568;margin-bottom:6px}.period-pair{display:grid;grid-template-columns:1.2fr .8fr;gap:7px}select,input[type="date"],.locked-field{width:100%;min-height:41px;border:1px solid #dce4ec;border-radius:8px;background:#fff;padding:8px 10px;font-size:14px;color:#263241}.locked-field{display:flex;align-items:center;background:#f7fafc;font-weight:700}.filter-actions{display:flex;gap:8px}.btn{border:0;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer;min-height:41px}.btn-primary{background:var(--primary);color:#fff}.btn-reset{background:#edf2f7;color:#425466;text-decoration:none;display:inline-flex;align-items:center}.filter-field-hidden{display:none!important}.date-filter-note{grid-column:1/-1;font-size:10.5px;color:#718096;margin-top:-3px}
        .analytics-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.panel{background:#fff;border:1px solid var(--border);border-radius:14px;padding:15px;min-width:0;min-height:320px;display:flex;flex-direction:column;box-shadow:0 5px 15px rgba(31,41,55,.045)}.panel-head{display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:12px}.panel h3{margin:0;font-size:16px;color:var(--secondary);font-weight:700}.panel-sub{font-size:11px;color:var(--muted);margin-top:3px;line-height:1.35}.chart-area{flex:1;min-height:0;display:flex;flex-direction:column;justify-content:center;padding-top:2px}.hint{font-size:10.5px;color:#7b8794;margin-top:8px}.empty{color:#8a97a6;font-size:12px;text-align:center;padding:30px 8px}
        .trend-scroll{overflow-x:auto;overflow-y:hidden}.trend-svg{display:block;height:220px}.trend-point{cursor:pointer;outline:none}.trend-point:hover,.trend-point:focus{stroke-width:5}.bar-list{display:flex;flex-direction:column;gap:10px;overflow:visible;padding-right:2px}.bar-button{background:transparent;border:0;padding:0;text-align:left;cursor:pointer;color:inherit}.bar-top{display:flex;justify-content:space-between;gap:8px;font-size:11px;margin-bottom:4px}.bar-name{font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.bar-value{color:#475569;font-weight:700;white-space:nowrap}.bar-track{height:9px;background:#edf2f7;border-radius:999px;overflow:hidden;display:flex}.bar-fill{height:100%;background:var(--danger);transition:filter .2s}.bar-fill-clear{height:100%;background:var(--success);transition:filter .2s}.bar-button:hover .bar-fill,.bar-button:focus .bar-fill,.bar-button:hover .bar-fill-clear,.bar-button:focus .bar-fill-clear{filter:brightness(.9)}.bar-button.map-linked .bar-track{box-shadow:0 0 0 2px rgba(142,68,173,.18)}.bar-button.map-linked .bar-name,.bar-button.map-linked .bar-value{color:#71368d}.bar-note{font-size:10px;color:#8491a0;margin-top:4px;display:flex;gap:11px;flex-wrap:wrap}.bar-note-item{display:inline-flex;align-items:center;gap:4px}.bar-note-dot{width:7px;height:7px;border-radius:50%;display:inline-block}.mini-legend{display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:9.5px;color:#718096}.mini-legend span{display:inline-flex;align-items:center;gap:4px}.priority-summary{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-bottom:10px}.priority-stat{border:1px solid #edf1f5;background:#f8fafc;border-radius:10px;padding:9px 11px}.priority-stat b{display:block;font-size:17px;color:#273548;line-height:1.1}.priority-stat span{display:block;font-size:9.5px;color:#7b8794;margin-top:4px}.priority-list{display:flex;flex-direction:column;gap:6px;min-height:0;overflow:auto}.priority-row{width:100%;border:1px solid #edf1f5;background:#fff;border-radius:9px;padding:7px 9px;display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:10px;align-items:center;text-align:left;cursor:pointer;color:inherit}.priority-row:hover{background:#fff7f6;border-color:#f2d2ce}.priority-main{min-width:0}.priority-name{display:block;font-size:11.5px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#263241}.priority-meta{display:block;font-size:9.5px;color:#8491a0;margin-top:2px}.priority-age{font-size:10px;font-weight:800;color:#b45b00;background:#fff4df;border-radius:999px;padding:4px 7px;white-space:nowrap}.priority-amount{font-size:10.5px;font-weight:800;color:#b9382b;white-space:nowrap}.priority-footer{display:flex;justify-content:flex-end;margin-top:9px}.priority-all{border:0;background:#eef6ff;color:#21618c;border-radius:8px;padding:7px 10px;font-size:10.5px;font-weight:800;cursor:pointer}.priority-all:hover{background:#dfeffc}
        .ranking{display:flex;flex-direction:column;gap:9px;overflow:auto}.rank-btn{border:1px solid #edf1f5;background:#fbfdff;border-radius:11px;padding:10px 12px;display:grid;grid-template-columns:28px minmax(0,1fr) auto;gap:12px;align-items:center;cursor:pointer;text-align:left}.rank-btn:hover{background:#f2f8fd;border-color:#d8e8f6}.rank-no{width:25px;height:25px;border-radius:50%;display:grid;place-items:center;background:#edf2f7;font-size:11px;font-weight:800}.rank-main{display:flex;flex-direction:column;gap:4px;min-width:0}.rank-name{font-size:12.5px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;line-height:1.15}.rank-meta{font-size:10px;color:#7d8b99;display:block;line-height:1.1;letter-spacing:.2px}.rank-streak{font-size:11px;color:#1f7a4d;font-weight:800;white-space:nowrap;padding-left:10px}
        .column-scroll{width:100%;overflow-x:auto;overflow-y:hidden;padding:4px 2px 2px;scrollbar-width:thin}.column-chart{height:235px;display:flex;align-items:stretch;gap:12px;border-bottom:1px solid #dfe6ee;padding:8px 8px 0;position:relative}.column-chart:before,.column-chart:after{content:'';position:absolute;left:8px;right:8px;border-top:1px dashed #edf1f5;pointer-events:none}.column-chart:before{top:31%}.column-chart:after{top:62%}.column-button{flex:1 0 66px;min-width:66px;border:0;background:transparent;padding:0;display:grid;grid-template-rows:30px 1fr 38px;align-items:end;cursor:pointer;color:inherit;position:relative;z-index:1}.column-value{font-size:10.5px;font-weight:800;color:#536274;text-align:center;white-space:nowrap;align-self:center}.column-bar-shell{height:100%;display:flex;align-items:flex-end;justify-content:center}.column-bar{width:min(48px,72%);min-height:4px;border-radius:9px 9px 3px 3px;background:linear-gradient(180deg,#a85ac2,#8e44ad);box-shadow:0 5px 12px rgba(142,68,173,.16);transition:height .3s ease,filter .18s ease,transform .18s ease}.column-button:hover .column-bar,.column-button:focus .column-bar{filter:brightness(.94);transform:translateY(-2px)}.column-button.map-linked .column-bar{box-shadow:0 0 0 3px rgba(142,68,173,.16),0 7px 16px rgba(142,68,173,.2)}.column-label{font-size:9.5px;font-weight:800;color:#425466;text-align:center;align-self:center;line-height:1.1;white-space:normal;overflow:hidden}.column-label-short{display:none}
        .comparison-panel{grid-column:1/-1;min-height:360px}.comparison-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}.comparison-tabs{display:flex;gap:6px;flex-wrap:wrap}.comparison-tab{border:1px solid #dfe7ef;background:#f8fafc;color:#536274;border-radius:999px;padding:6px 10px;font-size:10.5px;font-weight:700;cursor:pointer}.comparison-tab.active{background:#3498db;border-color:#3498db;color:#fff}.comparison-scroll{width:100%;overflow-x:auto;overflow-y:hidden;scrollbar-width:thin;padding:3px 0}.comparison-columns{min-width:580px;height:240px;display:flex;align-items:stretch;gap:16px;border-bottom:1px solid #dfe6ee;padding:10px 12px 0;position:relative}.comparison-columns:before,.comparison-columns:after{content:'';position:absolute;left:12px;right:12px;border-top:1px dashed #edf1f5;pointer-events:none}.comparison-columns:before{top:34%}.comparison-columns:after{top:66%}.comparison-column{flex:1;min-width:72px;display:grid;grid-template-rows:30px 1fr 42px;align-items:end;position:relative;z-index:1}.comparison-value{font-size:11px;font-weight:800;color:#334155;text-align:center;align-self:center}.comparison-bar-shell{height:100%;display:flex;align-items:flex-end;justify-content:center}.comparison-bar{width:min(58px,68%);min-height:4px;border-radius:10px 10px 3px 3px;background:linear-gradient(180deg,#55b4ed,#3498db);box-shadow:0 5px 12px rgba(52,152,219,.16);transition:height .3s ease,background .2s ease,transform .18s ease}.comparison-column:hover .comparison-bar{transform:translateY(-2px)}.comparison-name{font-size:10px;font-weight:800;color:#334155;text-align:center;align-self:center;line-height:1.15}.comparison-name-short{display:none}.comparison-panel[data-metric="aktif"] .comparison-bar{background:linear-gradient(180deg,#52c98a,#27ae60)}.comparison-panel[data-metric="tidak_aktif"] .comparison-bar{background:linear-gradient(180deg,#a9b3ba,#8795a1)}.comparison-panel[data-metric="belum_bayar"] .comparison-bar{background:linear-gradient(180deg,#f06d61,#e74c3c)}
        .map-panel{grid-column:1/-1;height:auto;min-height:470px;padding:15px}.map-panel .panel-head{margin-bottom:10px;align-items:flex-start}.map-head-main{display:flex;align-items:flex-start;gap:9px}.map-head-icon{width:34px;height:34px;border-radius:10px;background:#eef6ff;color:#3498db;display:grid;place-items:center;flex:0 0 34px}.map-meta{font-size:11px;color:#7b8794;margin-top:3px}.map-legend{display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end}.map-legend-item{display:inline-flex;align-items:center;gap:5px;font-size:10.5px;color:#566577;background:#f8fafc;border:1px solid #e7edf3;padding:5px 8px;border-radius:999px;white-space:nowrap}.map-legend-dot{width:8px;height:8px;border-radius:50%;flex:0 0 8px}.map-shell{position:relative;min-height:390px;border:1px solid #e6ebf1;border-radius:12px;overflow:hidden;background:#eaf0f5}.analytics-map{height:390px;width:100%;background:#eaf0f5}.map-overlay{position:absolute;z-index:600;left:12px;top:12px;background:rgba(255,255,255,.96);border:1px solid #e2e8f0;border-radius:9px;padding:8px 10px;box-shadow:0 4px 14px rgba(31,41,55,.1);font-size:11px;color:#475569;display:flex;align-items:center;gap:7px}.map-overlay.error{color:#9d2d25;background:#fff7f6;border-color:#f1c7c2}.map-overlay.hidden{display:none}.analytics-map .leaflet-popup-content-wrapper{border-radius:12px;box-shadow:0 12px 35px rgba(15,23,42,.2)}.analytics-map .leaflet-popup-content{margin:13px;width:300px!important}.map-region-chip{min-width:74px;max-width:118px;padding:5px 10px;border-radius:999px;text-align:center;color:#fff;font-size:10px;font-weight:800;box-shadow:0 6px 16px rgba(15,23,42,.18);border:1px solid rgba(255,255,255,.92);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.map-region-chip.paid{background:#22a861}.map-region-chip.warning{background:#f39c12}.map-region-chip.critical{background:#e74c3c}.map-region-chip.nodata{background:#8795a1}.analytics-map .leaflet-interactive:focus{outline:none}.map-popup{font-size:12px;color:#334155}.map-popup-title{font-size:15px;font-weight:800;color:#1f2937;margin-bottom:2px}.map-popup-meta{font-size:10.5px;color:#718096;margin-bottom:10px}.map-popup-badges{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}.map-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 7px;border-radius:999px;font-size:10px;font-weight:800}.map-badge.paid{background:#eaf8f0;color:#18794e}.map-badge.warning{background:#fff6e5;color:#9a6500}.map-badge.critical{background:#fff0ee;color:#b9382b}.map-badge.nodata,.map-badge.unlinked{background:#f1f5f9;color:#64748b}.map-badge.online{background:#eaf8f0;color:#18794e}.map-badge.offline{background:#fff0ee;color:#b9382b}.map-badge.unknown{background:#f1f5f9;color:#64748b}.map-popup-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-bottom:9px}.map-popup-stat{background:#f8fafc;border:1px solid #edf2f7;border-radius:8px;padding:7px}.map-popup-stat span{display:block;font-size:9px;color:#7b8794;margin-bottom:2px}.map-popup-stat b{font-size:11px;color:#263241}.map-popup-note{font-size:10.5px;color:#64748b;line-height:1.45;background:#f8fafc;border-radius:8px;padding:8px}.map-detail-btn{width:100%;margin-top:9px;border:0;border-radius:8px;background:#3498db;color:#fff;font-weight:700;font-size:11px;padding:8px 10px;cursor:pointer}.map-detail-btn:hover{filter:brightness(.94)}
        .modal-backdrop{position:fixed;inset:0;background:rgba(17,24,39,.52);display:none;align-items:center;justify-content:center;padding:18px;z-index:500}.modal-backdrop.open{display:flex}.modal{width:min(1100px,100%);max-height:88vh;background:#fff;border-radius:15px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 24px 70px rgba(0,0,0,.28)}.modal-head{padding:15px 18px;border-bottom:1px solid #e7edf3;display:flex;justify-content:space-between;gap:10px;align-items:center}.modal-head h3{margin:0;font-size:18px}.modal-close{border:0;background:#edf2f7;width:34px;height:34px;border-radius:8px;cursor:pointer;font-size:18px}.modal-body{padding:15px 18px;overflow:auto}.detail-summary{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}.summary-pill{background:#f7fafc;border:1px solid #e4ebf2;border-radius:9px;padding:8px 10px}.summary-pill b{display:block;font-size:13px}.summary-pill span{font-size:10px;color:#7a8795}.detail-table-wrap{overflow:auto;border:1px solid #e6ebf1;border-radius:10px}.detail-table{border-collapse:collapse;width:100%;min-width:760px;font-size:11px}.detail-table th{background:#3498db;color:#fff;text-align:left;padding:9px;white-space:nowrap}.detail-table td{padding:9px;border-bottom:1px solid #edf1f5}.loading{text-align:center;padding:35px;color:#708090}
        .error-box{background:#fff2f1;color:#9d2d25;border:1px solid #efc3bf;border-radius:10px;padding:12px;margin-bottom:15px}
        @media(max-width:1180px){.filter-grid{grid-template-columns:1fr 1fr}}
        @media(max-width:900px){.analytics-grid{grid-template-columns:1fr}}
        @media(max-width:720px){.header{align-items:flex-start;flex-direction:column}.header-actions{width:100%}.container{padding:17px 12px 28px}.filter-grid{grid-template-columns:1fr}.analytics-grid{grid-template-columns:1fr}.panel{height:auto;min-height:285px;padding:13px}.trend-svg{height:205px}.bar-list{gap:9px}.priority-summary{grid-template-columns:1fr 1fr}.priority-row{grid-template-columns:minmax(0,1fr) auto}.priority-amount{grid-column:2}.priority-age{grid-column:2}.column-chart{height:220px;gap:7px;padding-left:3px;padding-right:3px}.column-button{min-width:52px;flex-basis:52px;grid-template-rows:28px 1fr 34px}.column-bar{width:min(38px,72%)}.column-label-full{display:none}.column-label-short{display:inline}.comparison-panel{min-height:0}.comparison-head{flex-direction:column}.comparison-tabs{width:100%;display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.comparison-tab{width:100%;padding:8px 6px}.comparison-columns{min-width:0;width:100%;height:220px;gap:7px;padding-left:3px;padding-right:3px}.comparison-column{min-width:46px;grid-template-rows:28px 1fr 34px}.comparison-bar{width:min(38px,72%)}.comparison-name-full{display:none}.comparison-name-short{display:inline}.modal-backdrop{padding:8px}.modal{max-height:94vh}.map-panel{min-height:410px}.analytics-map{height:335px}.map-shell{min-height:335px}.map-panel .panel-head{flex-direction:column}.map-legend{justify-content:flex-start}.analytics-map .leaflet-popup-content{width:245px!important}.map-popup-grid{grid-template-columns:1fr 1fr}}
        @media(max-width:430px){.period-pair{grid-template-columns:1fr 1fr}.filter-actions{flex-direction:column}.btn,.btn-reset{width:100%;justify-content:center}.nav-btn{font-size:11px;padding:8px 10px}.priority-summary{grid-template-columns:1fr}.column-chart{gap:5px}.column-button{min-width:47px;flex-basis:47px}.column-value,.comparison-value{font-size:9.5px}.column-label,.comparison-name{font-size:8.8px}.comparison-columns{gap:5px}.comparison-column{min-width:43px}}

        /* ===== Penyempurnaan visual dashboard analitik ===== */
        body{background:linear-gradient(180deg,#f6f8fb 0,#f3f6fa 100%)}
        .container{max-width:1480px;padding-top:22px}
        .analytics-grid{gap:18px}
        .panel{border:1px solid #e2e8f0;border-radius:18px;padding:18px;min-height:330px;box-shadow:0 8px 24px rgba(37,51,66,.055);transition:box-shadow .2s ease,transform .2s ease;overflow:hidden}
        .panel:hover{box-shadow:0 12px 30px rgba(37,51,66,.075)}
        .panel-head{margin-bottom:15px;min-height:38px}
        .panel h3{font-size:15px;letter-spacing:-.12px}
        .panel-head>i{width:36px;height:36px;border-radius:11px;display:grid;place-items:center;background:#f5f8fb;border:1px solid #e8edf3;font-size:15px;flex:0 0 36px}
        .chart-area{padding-top:0}
        .empty{display:flex;align-items:center;justify-content:center;min-height:190px;background:#fafcff;border:1px dashed #e2e8f0;border-radius:13px;color:#8795a1}

        /* Tren pembayaran */
        .trend-scroll{border-radius:13px;background:linear-gradient(180deg,#fbfdff 0%,#fff 100%);padding:7px 4px 0;overflow-x:auto;overflow-y:hidden}
        .trend-svg{display:block;width:100%;height:238px;min-width:0}
        .trend-point circle{transition:r .15s ease,filter .15s ease}
        .trend-point:hover circle,.trend-point:focus circle{r:7;filter:drop-shadow(0 3px 5px rgba(52,152,219,.28))}

        /* Belum bayar */
        .mini-legend{gap:14px;font-size:10px}
        .bar-list{gap:9px}
        .bar-button{padding:9px 10px 10px;border:1px solid #edf1f5;border-radius:12px;background:#fbfdff;transition:background .16s ease,border-color .16s ease,transform .16s ease}
        .bar-button:hover,.bar-button:focus{background:#fff;border-color:#dfe7ef;transform:translateY(-1px)}
        .bar-top{align-items:center;margin-bottom:7px;font-size:11px}
        .bar-name{font-size:11px;color:#263241;letter-spacing:.05px}
        .bar-metrics{display:flex;align-items:center;gap:7px;white-space:nowrap}
        .bar-count{font-size:9.5px;font-weight:700;color:#6f7f8f;background:#eef3f7;border-radius:999px;padding:3px 7px}
        .bar-value{font-size:11px;color:#334155}
        .bar-track{height:11px;background:#eaf0f5;border-radius:999px;box-shadow:inset 0 1px 2px rgba(15,23,42,.06)}
        .bar-fill,.bar-fill-clear{transition:width .25s ease,filter .18s ease}
        .bar-fill{background:linear-gradient(90deg,#ef5b4d,#e74c3c)}
        .bar-fill-clear{background:linear-gradient(90deg,#35bd73,#27ae60)}

        /* Prioritas penagihan admin wilayah */
        .priority-stat{border-radius:12px;padding:11px 12px;background:linear-gradient(180deg,#fbfdff,#f7fafc)}
        .priority-stat b{font-size:19px}
        .priority-row{border-radius:11px;padding:9px 10px}
        .priority-all{padding:8px 11px}

        /* Total tunggakan - column chart */
        .vertical-chart-frame{display:grid;grid-template-columns:52px minmax(0,1fr);gap:8px;width:100%;align-items:stretch}
        .vertical-axis{height:244px;display:flex;flex-direction:column;justify-content:space-between;align-items:flex-end;padding:8px 2px 38px 0;font-size:9.5px;font-weight:700;color:#8a97a6;white-space:nowrap}
        .column-scroll{padding:4px 2px 2px;overflow-x:auto;overflow-y:hidden}
        .column-chart{height:244px;gap:16px;border-bottom:1px solid #dfe6ee;padding:8px 10px 0;background:linear-gradient(180deg,rgba(248,250,252,.68),rgba(255,255,255,0));border-radius:10px 10px 0 0}
        .column-chart:before,.column-chart:after{border-top:1px solid #e9eef4;left:10px;right:10px}
        .column-button{grid-template-rows:32px 1fr 42px;min-width:68px;flex-basis:68px}
        .column-value{font-size:10.5px;color:#475569}
        .column-bar{width:min(54px,70%);border-radius:10px 10px 4px 4px;background:linear-gradient(180deg,#b565cc 0%,#8e44ad 100%);box-shadow:0 7px 16px rgba(142,68,173,.18)}
        .column-label{font-size:9.7px;color:#3e4c5d}

        /* Ranking Top 5 */
        .ranking{gap:8px;overflow:visible}
        .rank-btn{border-color:#e8edf3;background:#fbfdff;border-radius:12px;padding:10px 12px;min-height:47px}
        .rank-btn:hover{transform:translateY(-1px);box-shadow:0 5px 12px rgba(31,41,55,.06)}
        .rank-no{background:#eef3f7;color:#526273}
        .rank-btn:nth-child(1) .rank-no{background:#fff3cc;color:#a86e00}
        .rank-btn:nth-child(2) .rank-no{background:#eef1f4;color:#66717c}
        .rank-btn:nth-child(3) .rank-no{background:#f7e6dc;color:#a05b35}
        .rank-name{font-size:12.5px}.rank-meta{font-size:9.8px}.rank-payment{font-size:10.2px;color:#526273;font-weight:700;line-height:1.2}.rank-streak{background:#eaf8f0;color:#18794e;border-radius:999px;padding:5px 8px;font-size:11px;font-weight:800}

        /* Perbandingan pelanggan */
        .comparison-panel{min-height:390px}
        .comparison-head{align-items:center}
        .comparison-tabs{background:#f5f8fb;border:1px solid #e7edf3;padding:4px;border-radius:12px;gap:3px}
        .comparison-tab{border:0;background:transparent;border-radius:8px;padding:7px 11px;font-size:10.5px}
        .comparison-tab.active{box-shadow:0 3px 8px rgba(52,152,219,.18)}
        .comparison-chart-frame{display:grid;grid-template-columns:38px minmax(0,1fr);gap:8px;width:100%;align-items:stretch}
        .comparison-axis{height:265px;display:flex;flex-direction:column;justify-content:space-between;align-items:flex-end;padding:10px 1px 43px 0;font-size:10px;font-weight:700;color:#8a97a6}
        .comparison-scroll{padding:3px 0;overflow-x:auto;overflow-y:hidden}
        .comparison-columns{height:265px;min-width:580px;gap:22px;padding:10px 16px 0;background:linear-gradient(180deg,rgba(248,250,252,.7),rgba(255,255,255,0));border-radius:10px 10px 0 0}
        .comparison-columns:before,.comparison-columns:after{border-top:1px solid #e9eef4;left:16px;right:16px}
        .comparison-column{grid-template-rows:32px 1fr 44px;min-width:72px}
        .comparison-value{font-size:11px;color:#334155}
        .comparison-bar{width:min(62px,68%);border-radius:11px 11px 4px 4px;box-shadow:0 7px 16px rgba(52,152,219,.16)}
        .comparison-name{font-size:10px;color:#3e4c5d}

        /* Peta */
        .map-panel{border-radius:18px;min-height:465px}
        .map-shell{border-radius:14px;box-shadow:inset 0 0 0 1px rgba(226,232,240,.45)}
        .map-meta{font-size:10.5px}

        @media(max-width:1100px){
            .panel{min-height:310px}
            .vertical-chart-frame{grid-template-columns:46px minmax(0,1fr)}
        }
        @media(max-width:900px){
            .analytics-grid{grid-template-columns:1fr}
            .panel{min-height:0}
            .trend-svg{height:230px}
            .comparison-panel,.map-panel{grid-column:1}
        }
        @media(max-width:720px){
            .container{padding:14px 10px 24px}
            .page-head h2{font-size:21px}
            .filter-panel{padding:13px;border-radius:13px}
            .panel{padding:14px;border-radius:15px}
            .panel-head{margin-bottom:12px}
            .panel h3{font-size:14px}
            .panel-head>i{width:32px;height:32px;border-radius:10px;flex-basis:32px}
            .trend-scroll{padding-left:0;padding-right:0}
            .trend-svg{height:215px}
            .bar-button{padding:8px 9px}
            .bar-count{padding:2px 6px}
            .priority-summary{grid-template-columns:1fr 1fr}
            .priority-list{overflow:visible}
            .vertical-chart-frame{grid-template-columns:40px minmax(0,1fr);gap:5px}
            .vertical-axis{height:225px;font-size:8.5px;padding-bottom:34px}
            .column-chart{height:225px;gap:8px;padding-left:4px;padding-right:4px}
            .column-button{min-width:48px;flex-basis:48px;grid-template-rows:28px 1fr 34px}
            .column-bar{width:min(38px,74%)}
            .column-label-full{display:none}.column-label-short{display:inline}
            .comparison-head{align-items:stretch}
            .comparison-tabs{width:100%;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));padding:3px}
            .comparison-tab{width:100%;padding:7px 3px;font-size:9px}
            .comparison-chart-frame{grid-template-columns:30px minmax(0,1fr);gap:4px}
            .comparison-axis{height:225px;font-size:8.5px;padding-bottom:34px}
            .comparison-columns{min-width:0;width:100%;height:225px;gap:7px;padding-left:4px;padding-right:4px}
            .comparison-column{min-width:42px;grid-template-rows:28px 1fr 34px}
            .comparison-bar{width:min(38px,74%)}
            .comparison-name-full{display:none}.comparison-name-short{display:inline}
            .rank-btn{padding:9px 10px;grid-template-columns:27px minmax(0,1fr) auto;gap:9px}
            .map-panel{min-height:390px}.analytics-map{height:310px}.map-shell{min-height:310px}
        }
        @media(max-width:430px){
            .header-actions{gap:6px}.nav-btn{padding:8px 9px}
            .priority-summary{grid-template-columns:1fr}
            .comparison-tabs{grid-template-columns:repeat(2,minmax(0,1fr))}
            .vertical-chart-frame{grid-template-columns:36px minmax(0,1fr)}
            .column-button{min-width:44px;flex-basis:44px}
            .column-value,.comparison-value{font-size:9px}
            .column-label,.comparison-name{font-size:8.5px}
            .rank-streak{font-size:9.5px;padding:4px 6px}
            .map-legend{gap:5px}.map-legend-item{font-size:9px;padding:4px 6px}
        }


        /* ===== Final responsive hardening: seluruh fitur aman di mobile ===== */
        html,body{width:100%;max-width:100%;overflow-x:hidden}
        body.modal-open{overflow:hidden;touch-action:none}
        .container,.analytics-grid,.panel,.chart-area,.filter-panel,.modal,.modal-body,.detail-table-wrap{min-width:0;max-width:100%}
        .container{width:100%}
        .column-chart-fit{width:100%;min-width:0!important}
        .detail-table-wrap{width:100%;max-width:100%;overscroll-behavior:contain}
        .modal{min-width:0;max-width:calc(100vw - 36px)}
        .modal-body{min-width:0;overscroll-behavior:contain}
        .detail-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:8px;width:100%}
        .summary-pill{min-width:0;margin:0}
        .summary-pill b{overflow-wrap:anywhere}
        .map-overlay{max-width:calc(100% - 24px);white-space:normal;line-height:1.3}

        @media(max-width:720px){
            .header{position:static;padding:12px 10px;gap:10px}
            .header h1{font-size:17px;line-height:1.25}
            .header-actions{width:100%;display:grid;grid-template-columns:repeat(auto-fit,minmax(96px,1fr));gap:7px}
            .nav-btn{width:100%;justify-content:center;text-align:center;white-space:nowrap;padding:9px 7px;font-size:10.5px}
            .container{padding:12px 8px 22px}
            .page-head{display:grid;grid-template-columns:1fr;gap:8px;margin-bottom:12px}
            .page-head h2{font-size:20px}.page-head p{font-size:11.5px;line-height:1.4}
            .scope-badge{justify-self:start;max-width:100%;white-space:normal}
            .filter-panel{padding:12px;margin-bottom:13px}
            .filter-grid{grid-template-columns:1fr;gap:10px}
            .filter-actions{width:100%;display:grid;grid-template-columns:1fr 1fr;gap:8px}
            .filter-actions .btn,.filter-actions .btn-reset{width:100%;display:flex;align-items:center;justify-content:center;text-align:center}
            .analytics-grid{grid-template-columns:1fr;gap:12px}
            .panel{min-height:0!important;height:auto!important;padding:13px;border-radius:14px;overflow:visible}
            .panel-head{min-height:0;margin-bottom:11px}.panel h3{font-size:14px;line-height:1.3}
            .panel-sub{font-size:10px}.panel-head>i{width:31px;height:31px;flex-basis:31px}
            .chart-area{width:100%;overflow:visible}

            /* Tren: tetap terbaca; bila periode panjang scroll hanya di dalam chart. */
            .trend-scroll{width:100%;max-width:100%;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch}
            .trend-svg{height:215px;width:100%;max-width:none}

            /* Belum bayar: tidak ada isi yang terpotong. */
            .bar-list{gap:8px}.bar-button{padding:8px 9px}
            .bar-top{gap:6px}.bar-metrics{gap:5px}.bar-count{font-size:9px}.bar-value{font-size:10px}
            .bar-name{white-space:normal;overflow:visible;text-overflow:clip}

            /* Prioritas admin wilayah: nama dan nominal tetap utuh. */
            .priority-summary{grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}
            .priority-stat{min-width:0;padding:10px}.priority-stat b{font-size:17px;overflow-wrap:anywhere}
            .priority-list{overflow:visible}
            .priority-row{grid-template-columns:minmax(0,1fr) auto;grid-template-areas:"main age" "main amount";gap:5px 8px;align-items:start;padding:9px}
            .priority-main{grid-area:main}.priority-age{grid-area:age;justify-self:end}.priority-amount{grid-area:amount;justify-self:end}
            .priority-name{white-space:normal;overflow:visible;text-overflow:clip;line-height:1.25}
            .priority-meta{line-height:1.35;overflow-wrap:anywhere}
            .priority-footer{justify-content:stretch}.priority-all{width:100%;min-height:38px}

            /* Column chart: 6 wilayah masuk satu layar; rentang >6 bulan tetap bisa digeser di dalam chart. */
            .vertical-chart-frame{grid-template-columns:34px minmax(0,1fr);gap:4px}
            .vertical-axis{height:220px;font-size:8px;padding:7px 0 32px}
            .column-scroll{width:100%;max-width:100%;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch}
            .column-chart{height:220px;padding:7px 2px 0;gap:4px}
            .column-chart-fit{width:100%;min-width:0!important}
            .column-chart-fit .column-button{flex:1 1 0;min-width:0!important}
            .column-chart-scrollable .column-button{min-width:48px;flex-basis:48px}
            .column-button{grid-template-rows:26px 1fr 32px}
            .column-bar{width:min(34px,76%)}
            .column-value{font-size:8.8px}.column-label{font-size:8.3px;line-height:1.05}
            .column-label-full{display:none}.column-label-short{display:inline}

            /* Top 5: streak pindah ke bawah agar nama tidak terjepit. */
            .ranking{overflow:visible}
            .rank-btn{grid-template-columns:27px minmax(0,1fr);grid-template-areas:"rankno rankmain" "rankno rankstreak";gap:4px 9px;align-items:start;padding:9px 10px}
            .rank-no{grid-area:rankno}.rank-main{grid-area:rankmain}.rank-streak{grid-area:rankstreak;justify-self:start;padding:4px 7px;margin:0;font-size:9.8px}
            .rank-name{white-space:normal;overflow:visible;text-overflow:clip;line-height:1.25}
            .rank-meta{line-height:1.25}

            /* Perbandingan wilayah: 6 kolom muat, tab nyaman disentuh. */
            .comparison-panel{min-height:0}.comparison-head{align-items:stretch}
            .comparison-tabs{width:100%;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:5px;padding:4px}
            .comparison-tab{width:100%;min-height:36px;padding:7px 5px;font-size:9.8px}
            .comparison-chart-frame{grid-template-columns:28px minmax(0,1fr);gap:3px}
            .comparison-axis{height:220px;font-size:8px;padding:7px 0 32px}
            .comparison-scroll{width:100%;max-width:100%;overflow-x:hidden}
            .comparison-columns{min-width:0!important;width:100%;height:220px;gap:4px;padding:7px 2px 0}
            .comparison-column{flex:1 1 0;min-width:0!important;grid-template-rows:26px 1fr 32px}
            .comparison-bar{width:min(34px,76%)}
            .comparison-value{font-size:9px}.comparison-name{font-size:8.3px;line-height:1.05}
            .comparison-name-full{display:none}.comparison-name-short{display:inline}

            /* Peta: legend dan popup tidak keluar layar. */
            .map-panel{min-height:0;padding:13px}.map-panel .panel-head{flex-direction:column;gap:9px}
            .map-legend{width:100%;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:5px;justify-content:stretch}
            .map-legend-item{justify-content:center;white-space:normal;text-align:center;font-size:9px;padding:5px 6px;line-height:1.2}
            .map-shell{min-height:300px}.analytics-map{height:300px}
            .map-overlay{left:8px;right:8px;top:8px;max-width:none;font-size:9.5px;padding:7px 8px}
            .analytics-map .leaflet-popup-content{width:auto!important;max-width:calc(100vw - 82px)!important;margin:11px!important}
            .map-popup{max-width:100%;overflow-wrap:anywhere}.map-popup-grid{grid-template-columns:1fr 1fr;gap:6px}
            .map-region-chip{max-width:92px;min-width:62px;font-size:8.5px;padding:4px 7px}

            /* Modal mobile = bottom sheet penuh, bukan dialog desktop yang melebar. */
            .modal-backdrop{padding:0;align-items:flex-end;justify-content:center;overflow:hidden}
            .modal{width:100%;max-width:100%;min-width:0;max-height:92vh;max-height:92dvh;height:auto;border-radius:18px 18px 0 0;box-shadow:0 -16px 45px rgba(15,23,42,.24)}
            .modal-head{position:sticky;top:0;z-index:3;background:#fff;padding:13px 14px;min-width:0}
            .modal-head h3{font-size:16px;line-height:1.3;min-width:0;overflow-wrap:anywhere}
            .modal-close{flex:0 0 34px}
            .modal-body{width:100%;min-width:0;max-width:100%;padding:12px;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch}
            .detail-summary{grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;margin-bottom:10px}
            .summary-pill{padding:9px;min-width:0}.summary-pill span{font-size:9px}.summary-pill b{font-size:12px;line-height:1.25}

            /* Tabel detail berubah menjadi kartu data di HP, jadi tidak ada horizontal cut. */
            .detail-table-wrap{border:0;border-radius:0;overflow:visible;width:100%;max-width:100%}
            .detail-table{display:block;width:100%;min-width:0!important;font-size:10.5px}
            .detail-table thead{display:none}
            .detail-table tbody{display:grid;grid-template-columns:1fr;gap:9px;width:100%}
            .detail-table tr{display:block;width:100%;border:1px solid #e4ebf2;border-radius:12px;background:#fff;overflow:hidden;box-shadow:0 3px 10px rgba(31,41,55,.035)}
            .detail-table td{display:grid;grid-template-columns:minmax(92px,34%) minmax(0,1fr);gap:9px;width:100%;padding:8px 10px;border-bottom:1px solid #edf1f5;white-space:normal;overflow-wrap:anywhere;line-height:1.35}
            .detail-table td:last-child{border-bottom:0}
            .detail-table td::before{content:attr(data-label);font-size:9px;font-weight:800;color:#718096;text-transform:none;line-height:1.3}
            .loading{padding:28px 10px}
        }

        @media(max-width:520px){
            .period-pair{grid-template-columns:1fr 1fr}
            .priority-summary{grid-template-columns:repeat(2,minmax(0,1fr))}
            .map-popup-grid{grid-template-columns:1fr 1fr}
        }

        @media(max-width:360px){
            .header-actions{grid-template-columns:1fr 1fr}
            .period-pair{grid-template-columns:1fr}
            .filter-actions{grid-template-columns:1fr}
            .priority-summary{grid-template-columns:1fr}
            .detail-summary{grid-template-columns:1fr}
            .map-legend{grid-template-columns:1fr}
            .map-popup-grid{grid-template-columns:1fr}
            .detail-table td{grid-template-columns:1fr;gap:3px}
            .vertical-chart-frame{grid-template-columns:30px minmax(0,1fr)}
            .comparison-chart-frame{grid-template-columns:24px minmax(0,1fr)}
        }


        /* ===== Analitik Perbandingan Wilayah: kondisi pembayaran + status tagihan ===== */
        .comparison-analytics-panel{grid-column:1/-1;min-height:0;padding:18px}
        .comparison-analytics-head{align-items:flex-start}
        .comparison-analytics-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,.85fr);gap:14px;align-items:stretch}
        .comparison-chart-card{border:1px solid #e7edf3;background:linear-gradient(180deg,#fbfdff 0%,#fff 100%);border-radius:14px;padding:14px;min-width:0}
        .comparison-chart-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:13px;flex-wrap:wrap}
        .comparison-chart-head h4{margin:0;color:#2c3e50;font-size:13px;font-weight:800;letter-spacing:-.05px}
        .comparison-chart-head>div>span{display:block;margin-top:3px;color:#8190a0;font-size:9.5px}
        .comparison-legend{display:flex;align-items:center;gap:10px;flex-wrap:wrap;color:#667587;font-size:9px;font-weight:700}
        .comparison-legend.compact span{display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
        .legend-swatch{display:inline-block;width:8px;height:8px;border-radius:3px;flex:0 0 8px}
        .legend-swatch.paid{background:#27ae60}.legend-swatch.unpaid{background:#e74c3c}

        /* Gauge dibuat sederhana supaya mudah dibaca user non-IT. */
        .payment-gauge-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}
        .payment-gauge-card{border:1px solid #e8edf3;border-radius:12px;background:#fff;padding:10px 10px 9px;min-width:0;box-shadow:0 3px 10px rgba(15,23,42,.035);transition:transform .18s ease,box-shadow .18s ease}
        .payment-gauge-card:hover{transform:translateY(-1px);box-shadow:0 6px 15px rgba(15,23,42,.07)}
        .payment-gauge-top{display:flex;align-items:flex-start;justify-content:space-between;gap:6px;min-width:0}
        .payment-gauge-region{font-size:9.7px;line-height:1.15;font-weight:850;color:#344456;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .payment-condition{font-size:7.7px;line-height:1;padding:4px 6px;border-radius:999px;font-weight:850;white-space:nowrap;flex:0 0 auto}
        .condition-good{background:#eaf8f0;color:#218c55}.condition-enough{background:#fff6dd;color:#a56a00}.condition-attention{background:#fff0e4;color:#c56a12}.condition-collect{background:#fdebea;color:#c0392b}.condition-empty{background:#f0f3f6;color:#718096}
        .payment-gauge-visual{position:relative;width:118px;height:69px;margin:7px auto 0;max-width:100%}
        .payment-gauge-svg{display:block;width:118px;height:69px;max-width:100%;overflow:visible;margin:auto}
        .gauge-track{fill:none;stroke:#edf1f5;stroke-width:10;stroke-linecap:round}
        .gauge-value{fill:none;stroke:var(--gauge-color,#3498db);stroke-width:10;stroke-linecap:round;transition:stroke-dasharray .3s ease}
        .payment-gauge-center{position:absolute;left:0;right:0;bottom:1px;text-align:center;pointer-events:none}
        .payment-gauge-center strong{display:block;font-size:18px;line-height:1;font-weight:900;color:#263747;letter-spacing:-.5px}
        .payment-gauge-center span{display:block;margin-top:3px;font-size:7.3px;font-weight:700;color:#8794a3;white-space:nowrap}
        .payment-gauge-money{margin-top:6px;padding-top:7px;border-top:1px solid #f0f3f6;display:flex;align-items:center;justify-content:space-between;gap:6px}
        .payment-gauge-money span{font-size:7.8px;color:#8190a0;white-space:nowrap}
        .payment-gauge-money strong{font-size:9px;color:#3c4d5e;font-weight:850;white-space:nowrap}
        .payment-gauge-note{margin-top:10px;color:#8996a4;font-size:8.4px;line-height:1.4}

        .status-comparison-list{display:flex;flex-direction:column;gap:11px}
        .status-region-row{display:grid;grid-template-columns:104px minmax(0,1fr) 42px;gap:9px;align-items:center;min-width:0}
        .comparison-region-name{font-size:10px;font-weight:800;color:#3d4c5d;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .status-stack{height:11px;border-radius:999px;overflow:hidden;background:#edf2f7;display:flex;box-shadow:inset 0 1px 2px rgba(15,23,42,.05)}
        .status-paid,.status-unpaid{height:100%;display:block;transition:width .25s ease}
        .status-paid{background:linear-gradient(90deg,#35bd73,#27ae60)}
        .status-unpaid{background:linear-gradient(90deg,#ef6659,#e74c3c)}
        .status-region-row strong{font-size:9.5px;color:#435365;text-align:right;white-space:nowrap}
        .comparison-footnote{margin-top:10px;padding-top:8px;border-top:1px solid #eef2f6;color:#8996a4;font-size:8.8px;text-align:right}

        @media(max-width:1100px){
            .payment-gauge-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
        }
        @media(max-width:980px){
            .comparison-analytics-grid{grid-template-columns:1fr}
            .payment-gauge-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
        }
        @media(max-width:720px){
            .comparison-analytics-panel{padding:13px}
            .comparison-chart-card{padding:12px;border-radius:13px}
            .comparison-chart-head{margin-bottom:11px}
            .comparison-chart-head h4{font-size:12px}
            .payment-gauge-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
            .payment-gauge-card{padding:9px}
            .payment-gauge-region{font-size:9.2px}
            .payment-gauge-visual{width:108px;height:64px}
            .payment-gauge-svg{width:108px;height:64px}
            .payment-gauge-center strong{font-size:17px}
            .status-region-row{grid-template-columns:88px minmax(0,1fr) 38px;gap:7px}
            .comparison-region-name{font-size:9.2px}
            .status-region-row strong{font-size:8.8px}
            .status-stack{height:10px}
            .comparison-footnote{font-size:8.2px}
        }
        @media(max-width:430px){
            .comparison-chart-head{display:grid;grid-template-columns:1fr;gap:7px}
            .comparison-legend{gap:8px}
            .payment-gauge-grid{grid-template-columns:1fr}
            .payment-gauge-card{display:grid;grid-template-columns:minmax(0,1fr) 112px;grid-template-rows:auto auto;gap:0 8px;align-items:center;padding:10px 11px}
            .payment-gauge-top{grid-column:1;grid-row:1;display:block}
            .payment-gauge-region{font-size:10.5px;white-space:normal;overflow:visible;text-overflow:clip}
            .payment-condition{display:inline-block;margin-top:6px;font-size:8px}
            .payment-gauge-visual{grid-column:2;grid-row:1 / span 2;width:108px;height:64px;margin:0 auto}
            .payment-gauge-svg{width:108px;height:64px}
            .payment-gauge-money{grid-column:1;grid-row:2;margin-top:9px;padding-top:7px;display:block}
            .payment-gauge-money span,.payment-gauge-money strong{display:block}
            .payment-gauge-money strong{margin-top:3px;font-size:10px}
            .payment-gauge-note{font-size:8.2px}
            .status-region-row{grid-template-columns:78px minmax(0,1fr) 34px;gap:5px}
            .status-region-row .comparison-region-name{font-size:8.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            .status-region-row strong{font-size:8.4px}
        }
        @media(max-width:350px){
            .payment-gauge-card{grid-template-columns:minmax(0,1fr) 96px}
            .payment-gauge-visual,.payment-gauge-svg{width:96px}
            .payment-gauge-center strong{font-size:15px}
        }

        /* Tambahan export Excel analitik - tidak mengubah komponen analitik yang sudah ada. */
        .filter-actions{flex-wrap:wrap}
        .panel-head-actions{display:flex;align-items:center;gap:7px;flex:0 0 auto}
        .excel-export-btn{border:1px solid #cfe7da;background:#eefaf3;color:#18794e;border-radius:8px;padding:6px 9px;font-size:10.5px;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;min-height:30px}
        .excel-export-btn:hover,.excel-export-btn:focus{background:#dff5e8;border-color:#b8ddc8;outline:none}
        .btn-export-all{background:#1f9d60;color:#fff;display:inline-flex;align-items:center;gap:7px;white-space:nowrap}
        .btn-export-all:hover,.btn-export-all:focus{background:#188650}
        .modal-head-actions{display:flex;align-items:center;gap:7px;flex:0 0 auto}
        .modal-export-btn{border:1px solid #cfe7da;background:#eefaf3;color:#18794e;border-radius:8px;padding:8px 10px;font-size:11px;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
        .modal-export-btn:hover:not(:disabled),.modal-export-btn:focus:not(:disabled){background:#dff5e8}
        .modal-export-btn:disabled{opacity:.5;cursor:not-allowed}
        @media(max-width:720px){.filter-actions{display:grid;grid-template-columns:1fr 1fr;width:100%}.filter-actions .btn-export-all{grid-column:1/-1;justify-content:center}.excel-export-btn{padding:6px 8px}.modal-export-btn span{display:none}.modal-export-btn{width:34px;height:34px;padding:0;justify-content:center}}
    </style>
</head>
<body>
<div class="header">
    <h1><i class="fas fa-chart-column"></i> Ringkasan Analitik Billing</h1>
    <div class="header-actions">
        <a class="nav-btn back" href="dashboard_salam.php"><i class="fas fa-arrow-left"></i> Dashboard</a>
        <?php if ($isSuperAdminAnalitik): ?>
            <a class="nav-btn report" href="laporan_salam.php"><i class="fas fa-file-lines"></i> Laporan</a>
        <?php endif; ?>
        <a class="nav-btn logout" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<div class="container">
    <div class="page-head">
        <div>
            <h2>Ringkasan Analitik</h2>
            <p><?= htmlspecialchars($rangeLabel); ?> · <?= htmlspecialchars($scope['label']); ?></p>
        </div>
        <div class="scope-badge">
            <?= htmlspecialchars($roleLabelAnalitik); ?> · <?= htmlspecialchars($scope['label']); ?>
        </div>
    </div>

    <?php if ($errorAnalitik): ?><div class="error-box">Analitik belum dapat dimuat: <?= htmlspecialchars($errorAnalitik); ?></div><?php endif; ?>

    <div class="filter-panel">
        <form method="get" id="analyticsFilter">
            <div class="filter-grid">
                <div class="field">
                    <label>Jenis Filter</label>
                    <select name="filter_tipe" id="filterTipe">
                        <option value="bulan" <?= $range['filter_tipe']==='bulan'?'selected':'' ?>>Rentang Bulan</option>
                        <option value="tanggal" <?= $range['filter_tipe']==='tanggal'?'selected':'' ?>>Rentang Tanggal</option>
                    </select>
                </div>
                <div class="field">
                    <div data-filter-bulan class="<?= $range['filter_tipe']==='bulan'?'':'filter-field-hidden' ?>">
                        <label>Dari Periode</label>
                        <div class="period-pair">
                        <select name="bulan_awal">
                            <?php foreach($bulanOptions as $v=>$n): ?><option value="<?= $v ?>" <?= $range['bulan_awal']===$v?'selected':'' ?>><?= htmlspecialchars($n) ?></option><?php endforeach; ?>
                        </select>
                        <select name="tahun_awal">
                            <?php foreach($yearOptions as $y): ?><option value="<?= $y ?>" <?= $range['tahun_awal']===$y?'selected':'' ?>><?= $y ?></option><?php endforeach; ?>
                        </select>
                        </div>
                    </div>
                    <div data-filter-tanggal class="<?= $range['filter_tipe']==='tanggal'?'':'filter-field-hidden' ?>">
                        <label>Dari Tanggal</label>
                        <input type="date" name="tanggal_awal" value="<?= htmlspecialchars($range['tanggal_awal']) ?>">
                    </div>
                </div>
                <div class="field">
                    <div data-filter-bulan class="<?= $range['filter_tipe']==='bulan'?'':'filter-field-hidden' ?>">
                        <label>Sampai Periode</label>
                        <div class="period-pair">
                        <select name="bulan_akhir">
                            <?php foreach($bulanOptions as $v=>$n): ?><option value="<?= $v ?>" <?= $range['bulan_akhir']===$v?'selected':'' ?>><?= htmlspecialchars($n) ?></option><?php endforeach; ?>
                        </select>
                        <select name="tahun_akhir">
                            <?php foreach($yearOptions as $y): ?><option value="<?= $y ?>" <?= $range['tahun_akhir']===$y?'selected':'' ?>><?= $y ?></option><?php endforeach; ?>
                        </select>
                        </div>
                    </div>
                    <div data-filter-tanggal class="<?= $range['filter_tipe']==='tanggal'?'':'filter-field-hidden' ?>">
                        <label>Sampai Tanggal</label>
                        <input type="date" name="tanggal_akhir" value="<?= htmlspecialchars($range['tanggal_akhir']) ?>">
                    </div>
                </div>
                <div class="field">
                    <label>Wilayah</label>
                    <?php if ($canPilihWilayahAnalitik): ?>
                        <select name="wilayah">
                            <option value="all" <?= $scope['is_all']?'selected':'' ?>>Semua Wilayah</option>
                            <?php foreach($wilayahOptions as $w): ?><option value="<?= htmlspecialchars($w) ?>" <?= !$scope['is_all'] && $scope['value']===$w?'selected':'' ?>><?= htmlspecialchars($w) ?></option><?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <div class="locked-field"><i class="fas fa-lock" style="margin-right:7px"></i><?= htmlspecialchars($scope['label']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="filter-actions">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-filter"></i> Tampilkan</button>
                    <a class="btn btn-reset" href="analitik_salam.php"><i class="fas fa-rotate-left" style="margin-right:6px"></i> Reset</a>
                    <button class="btn btn-export-all" type="button" data-export-all title="Unduh semua hasil analitik beserta rinciannya dalam satu file Excel"><i class="fas fa-file-excel"></i> Export Semua Analitik</button>
                </div>
                <div class="date-filter-note <?= $range['filter_tipe']==='tanggal'?'':'filter-field-hidden' ?>" data-filter-tanggal-note>Rentang tanggal mencakup seluruh tagihan pada bulan yang tersentuh agar data lunas dan belum lunas tetap lengkap.</div>
            </div>
        </form>
    </div>

    <div class="analytics-grid">
        <section class="panel">
            <div class="panel-head"><div><h3>Perkembangan Pembayaran</h3></div><div class="panel-head-actions"><button type="button" class="excel-export-btn" data-export-analytic="trend" title="Unduh hasil dan rincian Perkembangan Pembayaran"><i class="fas fa-file-excel"></i> Unduh Excel</button><i class="fas fa-chart-line" style="color:#3498db"></i></div></div>
            <div class="chart-area trend-scroll">
                <?php if (!$points): ?><div class="empty">Belum ada data pada rentang ini.</div><?php else: ?>
                <svg class="trend-svg" viewBox="0 0 <?= $trendWidth ?> <?= $trendHeight ?>" <?= $trendRenderMinWidth > 0 ? 'style="min-width:' . (int)$trendRenderMinWidth . 'px"' : '' ?> aria-label="Perkembangan pembayaran">
                    <defs>
                        <linearGradient id="trendAreaFill" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#3498db" stop-opacity="0.20"/>
                            <stop offset="100%" stop-color="#3498db" stop-opacity="0.02"/>
                        </linearGradient>
                    </defs>
                    <?php for($i=0;$i<=4;$i++): $v=$trendMax-(($trendMax/4)*$i); $y=$plotTop+($plotH/4*$i); ?>
                        <line x1="<?= $plotLeft ?>" y1="<?= $y ?>" x2="<?= $trendWidth-$plotRight ?>" y2="<?= $y ?>" stroke="#e8edf3" stroke-width="1"/>
                        <text x="<?= $plotLeft-7 ?>" y="<?= $y+4 ?>" text-anchor="end" font-size="10" fill="#8090a0"><?= round($v) ?>%</text>
                    <?php endfor; ?>
                    <?php if ($areaPath !== ''): ?><path d="<?= htmlspecialchars($areaPath) ?>" fill="url(#trendAreaFill)" stroke="none"/><?php endif; ?>
                    <path d="<?= htmlspecialchars($path) ?>" fill="none" stroke="#3498db" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                    <?php foreach($points as $p): $item=$p['item']; ?>
                        <g class="trend-point" tabindex="0" role="button" data-detail="trend" data-key="<?= htmlspecialchars($item['periode']) ?>">
                            <title><?= htmlspecialchars(analitikPeriodLabel($item['periode'])) ?>: <?= $item['persen'] ?>% lunas</title>
                            <circle cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>" r="5" fill="#fff" stroke="#3498db" stroke-width="3"/>
                            <text x="<?= $p['x'] ?>" y="<?= $p['y']-10 ?>" text-anchor="middle" font-size="10" font-weight="700" fill="#334155"><?= $item['persen'] ?>%</text>
                            <text x="<?= $p['x'] ?>" y="<?= $trendHeight-13 ?>" text-anchor="middle" font-size="9.5" fill="#6f7f8f"><?= htmlspecialchars($item['label']) ?></text>
                        </g>
                    <?php endforeach; ?>
                </svg>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <?php if ($isAdminWilayahAnalitik): ?>
                <div class="panel-head">
                    <div>
                        <h3>Prioritas Pelanggan Belum Bayar</h3>
                        <div class="panel-sub">Urut dari tunggakan terlama</div>
                    </div>
                    <div class="panel-head-actions">
                        <button type="button" class="excel-export-btn" data-export-analytic="unpaid" title="Unduh hasil dan rincian Prioritas Pelanggan Belum Bayar"><i class="fas fa-file-excel"></i> Unduh Excel</button>
                        <i class="fas fa-user-clock" style="color:#e74c3c"></i>
                    </div>
                </div>
                <div class="chart-area" style="justify-content:flex-start">
                    <div class="priority-summary">
                        <div class="priority-stat">
                            <b><?= number_format(count($unpaidCustomers)) ?></b>
                            <span>Pelanggan belum bayar</span>
                        </div>
                        <div class="priority-stat">
                            <b><?= htmlspecialchars(analitikRupiahCompact((float)$priorityOutstanding)) ?></b>
                            <span>Total tunggakan</span>
                        </div>
                    </div>

                    <?php if (!$priorityCustomers): ?>
                        <div class="empty" style="padding:20px 8px">Tidak ada pelanggan yang memiliki tunggakan pada periode ini.</div>
                    <?php else: ?>
                        <div class="priority-list">
                            <?php foreach ($priorityCustomers as $customer): ?>
                                <button type="button" class="priority-row" data-detail="customer" data-key="<?= (int)($customer['pelanggan_id'] ?? 0) ?>">
                                    <span class="priority-main">
                                        <span class="priority-name"><?= htmlspecialchars((string)($customer['nama'] ?? '-')) ?></span>
                                        <span class="priority-meta"><?= htmlspecialchars((string)($customer['id_pelanggan'] ?? '-')) ?> · tertua <?= htmlspecialchars(analitikPeriodLabel((string)($customer['periode_tertua'] ?? ''))) ?></span>
                                    </span>
                                    <span class="priority-age"><?= (int)($customer['lama_bulan'] ?? 0) ?> bulan</span>
                                    <span class="priority-amount"><?= htmlspecialchars(analitikRupiahCompact((float)($customer['total_tunggakan'] ?? 0))) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="priority-footer">
                            <button type="button" class="priority-all" data-detail="unpaid" data-key="unpaid"><i class="fas fa-list" style="margin-right:5px"></i>Lihat semua pelanggan belum bayar</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="panel-head">
                    <div>
                        <h3>Belum Bayar per Wilayah</h3>
                        <div class="mini-legend">
                            <span><i class="bar-note-dot" style="background:#e74c3c"></i>Belum bayar</span>
                            <span><i class="bar-note-dot" style="background:#27ae60"></i>Tanpa tunggakan</span>
                        </div>
                    </div>
                    <div class="panel-head-actions">
                        <button type="button" class="excel-export-btn" data-export-analytic="unpaid" title="Unduh hasil dan rincian Belum Bayar per Wilayah"><i class="fas fa-file-excel"></i> Unduh Excel</button>
                        <i class="fas fa-user-clock" style="color:#e74c3c"></i>
                    </div>
                </div>
                <div class="chart-area">
                    <?php if (!$regionUnpaid): ?>
                        <div class="empty">Belum ada data pelanggan pada periode ini.</div>
                    <?php else: ?>
                        <div class="bar-list">
                            <?php foreach($regionUnpaid as $r):
                                $totalPelanggan = (int)($r['total_pelanggan'] ?? 0);
                                $belumPelanggan = (int)($r['belum_pelanggan'] ?? 0);
                                $amanPelanggan = max(0, $totalPelanggan - $belumPelanggan);
                                $belumPersen = $totalPelanggan > 0 ? min(100, max(0, (float)$r['persen'])) : 0;
                                $amanPersen = $totalPelanggan > 0 ? max(0, 100 - $belumPersen) : 0;
                            ?>
                                <button type="button" class="bar-button" data-detail="unpaid" data-key="<?= htmlspecialchars($r['wilayah']) ?>">
                                    <div class="bar-top">
                                        <span class="bar-name"><?= htmlspecialchars($r['wilayah']) ?></span>
                                        <span class="bar-metrics">
                                            <span class="bar-count"><?= $belumPelanggan ?>/<?= $totalPelanggan ?></span>
                                            <span class="bar-value"><?= number_format($belumPersen, 1, ',', '.') ?>%</span>
                                        </span>
                                    </div>
                                    <div class="bar-track" aria-label="<?= htmlspecialchars($r['wilayah']) ?>: <?= $belumPersen ?> persen belum bayar">
                                        <div class="bar-fill" style="width:<?= $belumPersen ?>%"></div>
                                        <div class="bar-fill-clear" style="width:<?= $amanPersen ?>%"></div>
                                    </div>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-head"><div><h3><?= $scope['is_all'] ? 'Total Tunggakan per Wilayah' : 'Tunggakan per Bulan' ?></h3></div><div class="panel-head-actions"><button type="button" class="excel-export-btn" data-export-analytic="outstanding" title="Unduh hasil dan rincian Tunggakan"><i class="fas fa-file-excel"></i> Unduh Excel</button><i class="fas fa-chart-column" style="color:#8e44ad"></i></div></div>
            <div class="chart-area">
                <?php if (!$outstanding): ?>
                    <div class="empty">Belum ada data tunggakan pada periode ini.</div>
                <?php else: ?>
                    <div class="vertical-chart-frame">
                        <div class="vertical-axis" aria-hidden="true">
                            <span><?= htmlspecialchars(analitikRupiahCompact((float)$maxOutstanding)) ?></span>
                            <span><?= htmlspecialchars(analitikRupiahCompact((float)($maxOutstanding / 2))) ?></span>
                            <span>Rp0</span>
                        </div>
                        <div class="column-scroll">
                            <div class="column-chart <?= count($outstanding) > 6 ? 'column-chart-scrollable' : 'column-chart-fit' ?>" style="min-width:<?= count($outstanding) > 6 ? max(420, count($outstanding) * 64) : 0 ?>px">
                            <?php foreach($outstanding as $o):
                                $columnPercent = $maxOutstanding > 0 ? min(100, max(0, ((float)$o['value'] / $maxOutstanding) * 100)) : 0;
                                $shortLabel = (string)$o['label'];
                                if ($scope['is_all']) {
                                    $shortMap = ['BARAN'=>'BRN','GUNUNGMANUK'=>'GNM','NGASEMAYU'=>'NGA','SALAM'=>'SLM','TROSARI'=>'TRS','WADUK'=>'WDK'];
                                    $shortLabel = $shortMap[strtoupper((string)$o['label'])] ?? (string)$o['label'];
                                }
                            ?>
                                <button type="button" class="column-button" data-detail="outstanding" data-key="<?= htmlspecialchars($o['key']) ?>" title="<?= htmlspecialchars($o['label']) ?>: <?= htmlspecialchars(analitikRupiahCompact((float)$o['value'])) ?>">
                                    <span class="column-value"><?= htmlspecialchars(analitikRupiahCompact((float)$o['value'])) ?></span>
                                    <span class="column-bar-shell"><span class="column-bar" style="height:<?= max($columnPercent > 0 ? 8 : 0, $columnPercent) ?>%"></span></span>
                                    <span class="column-label"><span class="column-label-full"><?= htmlspecialchars($o['label']) ?></span><span class="column-label-short"><?= htmlspecialchars($shortLabel) ?></span></span>
                                </button>
                            <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>


        <section class="panel">
            <div class="panel-head">
                <div>
                    <h3>Pelanggan Paling Rajin Bayar</h3>
                    <div class="panel-sub">Berdasarkan periode yang dipilih</div>
                </div>
                <div class="panel-head-actions">
                    <button type="button" class="excel-export-btn" data-export-analytic="top" title="Unduh hasil dan rincian Pelanggan Paling Rajin Bayar"><i class="fas fa-file-excel"></i> Unduh Excel</button>
                    <i class="fas fa-trophy" style="color:#d89b16"></i>
                </div>
            </div>
            <div class="chart-area">
                <?php if(!$top5): ?>
                    <div class="empty">Belum ada riwayat pembayaran pada periode ini.</div>
                <?php else: ?>
                <div class="ranking">
                    <?php foreach($top5 as $i=>$r):
                        $persenRajin = (float)($r['persen_lunas'] ?? 0);
                        $persenRajinLabel = abs($persenRajin - round($persenRajin)) < 0.05
                            ? number_format($persenRajin, 0, ',', '.') . '%'
                            : number_format($persenRajin, 1, ',', '.') . '%';
                        $metaPelanggan = (string)($r['id_pelanggan'] ?? '-');
                        if ($scope['is_all']) $metaPelanggan .= ' · ' . (string)($r['wilayah'] ?? '-');
                    ?>
                        <button type="button" class="rank-btn" data-detail="top" data-key="<?= (int)$r['pelanggan_id'] ?>" title="Lihat riwayat pembayaran <?= htmlspecialchars((string)$r['nama']) ?>">
                            <span class="rank-no"><?= $i+1 ?></span>
                            <span class="rank-main">
                                <span class="rank-name"><?= htmlspecialchars((string)$r['nama']) ?></span>
                                <span class="rank-meta"><?= htmlspecialchars($metaPelanggan) ?></span>
                                <span class="rank-payment">Lunas <?= (int)($r['lunas'] ?? 0) ?> dari <?= (int)($r['total_periode'] ?? 0) ?> bulan</span>
                            </span>
                            <span class="rank-streak"><?= htmlspecialchars($persenRajinLabel) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </section>


        <?php if ($canPilihWilayahAnalitik && $regionComparison): ?>
        <section class="panel comparison-analytics-panel">
            <div class="panel-head comparison-analytics-head">
                <div>
                    <h3>Analitik Perbandingan Wilayah</h3>
                    <div class="panel-sub">Kondisi pembayaran dan status tagihan pada periode terpilih</div>
                </div>
                <i class="fas fa-scale-balanced" style="color:#475569"></i>
            </div>

            <div class="comparison-analytics-grid">
                <div class="comparison-chart-card">
                    <div class="comparison-chart-head">
                        <div>
                            <h4>Kondisi Pembayaran per Wilayah</h4>
                            <span>Menunjukkan berapa persen pembayaran yang sudah terkumpul</span>
                        </div>
                        <button type="button" class="excel-export-btn" data-export-analytic="financial" title="Unduh hasil dan rincian Kondisi Pembayaran per Wilayah"><i class="fas fa-file-excel"></i> Unduh Excel</button>
                    </div>

                    <div class="payment-gauge-grid">
                        <?php foreach ($regionComparison as $row):
                            $income = (float)($row['pendapatan'] ?? 0);
                            $totalObligation = (float)($row['total_kewajiban'] ?? 0);
                            $collectionPercent = max(0, min(100, (float)($row['persen_pembayaran_terkumpul'] ?? 0)));
                            $hasPaymentData = $totalObligation > 0;

                            if (!$hasPaymentData) {
                                $conditionLabel = 'Belum ada data';
                                $conditionClass = 'condition-empty';
                                $gaugeColor = '#94a3b8';
                            } elseif ($collectionPercent >= 75) {
                                $conditionLabel = 'Baik';
                                $conditionClass = 'condition-good';
                                $gaugeColor = '#27ae60';
                            } elseif ($collectionPercent >= 50) {
                                $conditionLabel = 'Cukup';
                                $conditionClass = 'condition-enough';
                                $gaugeColor = '#d4a017';
                            } elseif ($collectionPercent >= 25) {
                                $conditionLabel = 'Perlu perhatian';
                                $conditionClass = 'condition-attention';
                                $gaugeColor = '#e67e22';
                            } else {
                                $conditionLabel = 'Perlu ditagih';
                                $conditionClass = 'condition-collect';
                                $gaugeColor = '#e74c3c';
                            }
                            $gaugeValue = $hasPaymentData ? $collectionPercent : 0;
                            $gaugeGap = max(0, 100 - $gaugeValue);
                        ?>
                        <div class="payment-gauge-card" title="<?= htmlspecialchars((string)($row['wilayah'] ?? '-')) ?>: pembayaran terkumpul <?= htmlspecialchars(number_format($collectionPercent, 1, ',', '.')) ?>%">
                            <div class="payment-gauge-top">
                                <div class="payment-gauge-region"><?= htmlspecialchars((string)($row['wilayah'] ?? '-')) ?></div>
                                <span class="payment-condition <?= htmlspecialchars($conditionClass) ?>"><?= htmlspecialchars($conditionLabel) ?></span>
                            </div>

                            <div class="payment-gauge-visual" style="--gauge-color:<?= htmlspecialchars($gaugeColor) ?>">
                                <svg class="payment-gauge-svg" viewBox="0 0 120 70" role="img" aria-label="Pembayaran terkumpul <?= htmlspecialchars(number_format($collectionPercent, 1, ',', '.')) ?> persen">
                                    <path class="gauge-track" pathLength="100" d="M 15 60 A 45 45 0 0 1 105 60"></path>
                                    <path class="gauge-value" pathLength="100" d="M 15 60 A 45 45 0 0 1 105 60" stroke-dasharray="<?= htmlspecialchars(number_format($gaugeValue, 2, '.', '')) ?> <?= htmlspecialchars(number_format($gaugeGap, 2, '.', '')) ?>"></path>
                                </svg>
                                <div class="payment-gauge-center">
                                    <strong><?= $hasPaymentData ? htmlspecialchars(number_format($collectionPercent, 0, ',', '.')) . '%' : '—' ?></strong>
                                    <span>Pembayaran terkumpul</span>
                                </div>
                            </div>

                            <div class="payment-gauge-money">
                                <span>Pembayaran masuk</span>
                                <strong><?= htmlspecialchars(analitikRupiahCompact($income)) ?></strong>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="payment-gauge-note">Persentase dihitung dari pembayaran masuk dibanding total kewajiban pada periode yang dipilih. Grafik Total Tunggakan tetap menunjukkan nominal yang belum dibayar.</div>
                </div>

                <div class="comparison-chart-card">
                    <div class="comparison-chart-head">
                        <div>
                            <h4>Status Tagihan per Wilayah</h4>
                            <span>Komposisi jumlah tagihan lunas dan belum lunas</span>
                        </div>
                        <div class="panel-head-actions">
                            <button type="button" class="excel-export-btn" data-export-analytic="status" title="Unduh hasil dan rincian Status Tagihan per Wilayah"><i class="fas fa-file-excel"></i> Unduh Excel</button>
                            <div class="comparison-legend compact">
                                <span><i class="legend-swatch paid"></i>Lunas</span>
                                <span><i class="legend-swatch unpaid"></i>Belum</span>
                            </div>
                        </div>
                    </div>

                    <div class="status-comparison-list">
                        <?php foreach ($regionComparison as $row):
                            $paidBills = (int)($row['tagihan_lunas'] ?? 0);
                            $unpaidBills = (int)($row['tagihan_belum'] ?? 0);
                            $totalBills = max(0, (int)($row['total_tagihan'] ?? 0));
                            $paidPercent = $totalBills > 0 ? ($paidBills / $totalBills) * 100 : 0;
                            $unpaidPercent = $totalBills > 0 ? 100 - $paidPercent : 0;
                        ?>
                        <div class="status-region-row" title="<?= htmlspecialchars((string)($row['wilayah'] ?? '-')) ?>: <?= $paidBills ?> lunas, <?= $unpaidBills ?> belum lunas">
                            <div class="comparison-region-name"><?= htmlspecialchars((string)($row['wilayah'] ?? '-')) ?></div>
                            <div class="status-stack">
                                <span class="status-paid" style="width:<?= $paidPercent ?>%"></span>
                                <span class="status-unpaid" style="width:<?= $unpaidPercent ?>%"></span>
                            </div>
                            <strong><?= htmlspecialchars(number_format((float)($row['persen_lunas'] ?? 0), 0, ',', '.')) ?>%</strong>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="comparison-footnote">Angka di kanan menunjukkan persentase jumlah tagihan yang sudah lunas.</div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="panel map-panel">
            <div class="panel-head">
                <div class="map-head-main">
                    <div class="map-head-icon"><i class="fas fa-map-location-dot"></i></div>
                    <div>
                        <h3>Peta Ringkasan Wilayah</h3>
                        <div class="map-meta">Warna area mengikuti kondisi tunggakan</div>
                    </div>
                </div>
                <div class="map-legend" aria-label="Legenda status peta">
                    <span class="map-legend-item"><span class="map-legend-dot" style="background:#22a861"></span>Tidak ada tunggakan</span>
                    <span class="map-legend-item"><span class="map-legend-dot" style="background:#f39c12"></span>Ada tunggakan</span>
                    <span class="map-legend-item"><span class="map-legend-dot" style="background:#e74c3c"></span>Tunggakan tinggi</span>
                    <span class="map-legend-item"><span class="map-legend-dot" style="background:#8795a1"></span>Belum ada data</span>
                </div>
            </div>
            <div class="map-shell">
                <div id="analyticsMap" class="analytics-map" aria-label="Peta ringkasan wilayah dari PPPoE"></div>
                <div id="mapStatus" class="map-overlay"><i class="fas fa-spinner fa-spin"></i> Memuat ringkasan wilayah...</div>
            </div>
        </section>
    </div>
</div>

<div class="modal-backdrop" id="detailModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="detailTitle">
        <div class="modal-head">
            <h3 id="detailTitle">Detail Analitik</h3>
            <div class="modal-head-actions">
                <button type="button" class="modal-close" id="closeDetail" aria-label="Tutup">&times;</button>
            </div>
        </div>
        <div class="modal-body" id="detailBody"><div class="loading">Memuat data...</div></div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
(() => {
    const selector = document.getElementById('filterTipe');
    if (!selector) return;
    const syncFilterFields = () => {
        const isTanggal = selector.value === 'tanggal';
        document.querySelectorAll('[data-filter-bulan]').forEach(el => el.classList.toggle('filter-field-hidden', isTanggal));
        document.querySelectorAll('[data-filter-tanggal],[data-filter-tanggal-note]').forEach(el => el.classList.toggle('filter-field-hidden', !isTanggal));
    };
    selector.addEventListener('change', syncFilterFields);
    syncFilterFields();
})();
</script>
<script>
(() => {
    const rootParams = new URLSearchParams({
        filter_tipe: <?= json_encode($range['filter_tipe']) ?>,
        tanggal_awal: <?= json_encode($range['tanggal_awal']) ?>,
        tanggal_akhir: <?= json_encode($range['tanggal_akhir']) ?>,
        bulan_awal: <?= json_encode($range['bulan_awal']) ?>,
        tahun_awal: <?= json_encode((string)$range['tahun_awal']) ?>,
        bulan_akhir: <?= json_encode($range['bulan_akhir']) ?>,
        tahun_akhir: <?= json_encode((string)$range['tahun_akhir']) ?>,
        wilayah: <?= json_encode($scope['value']) ?>
    });
    const modal = document.getElementById('detailModal');
    const title = document.getElementById('detailTitle');
    const body = document.getElementById('detailBody');
    const close = document.getElementById('closeDetail');

    function exportUrl(mode, extra = {}) {
        const p = new URLSearchParams(rootParams);
        p.set('mode', mode);
        Object.entries(extra).forEach(([key, value]) => p.set(key, String(value ?? '')));
        return 'export_analitik_salam.php?' + p.toString();
    }

    function esc(value) {
        return String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
    }
    function closeModal(){
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden','true');
        document.body.classList.remove('modal-open');
    }
    close.addEventListener('click', closeModal);
    modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && modal.classList.contains('open')) closeModal(); });

    async function openDetail(type, key) {
        modal.classList.add('open');
        modal.setAttribute('aria-hidden','false');
        document.body.classList.add('modal-open');
        title.textContent = 'Detail Analitik';
        body.innerHTML = '<div class="loading">Memuat data...</div>';
        const p = new URLSearchParams(rootParams);
        p.set('type', type); p.set('key', key);
        try {
            const response = await fetch('analitik_detail_salam.php?' + p.toString(), {headers:{'Accept':'application/json'}});
            const data = await response.json();
            if (!response.ok || data.error) throw new Error(data.message || 'Data detail tidak dapat dimuat.');
            title.textContent = data.title || 'Detail Analitik';
            const summary = (data.summary || []).map(s => `<div class="summary-pill"><span>${esc(s.label)}</span><b>${esc(s.value)}</b></div>`).join('');
            let table = '';
            if ((data.rows || []).length) {
                const th = (data.columns || []).map(c => `<th>${esc(c)}</th>`).join('');
                const columns = data.columns || [];
                const tr = data.rows.map(row => `<tr>${row.map((v, i) => `<td data-label="${esc(columns[i] || 'Data')}">${esc(v)}</td>`).join('')}</tr>`).join('');
                table = `<div class="detail-table-wrap"><table class="detail-table"><thead><tr>${th}</tr></thead><tbody>${tr}</tbody></table></div>`;
            } else {
                table = '<div class="empty">Tidak ada data detail pada pilihan ini.</div>';
            }
            body.innerHTML = `<div class="detail-summary">${summary}</div>${table}`;
        } catch (err) {
            body.innerHTML = `<div class="error-box">${esc(err.message)}</div>`;
        }
    }


    document.querySelectorAll('[data-export-analytic]').forEach(button => {
        button.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();
            const analytic = button.getAttribute('data-export-analytic') || '';
            if (!analytic) return;
            window.location.href = exportUrl('panel', {analytic});
        });
    });

    document.querySelectorAll('[data-export-all]').forEach(button => {
        button.addEventListener('click', () => {
            window.location.href = exportUrl('all');
        });
    });

    window.openAnalitikDetail = openDetail;

    document.querySelectorAll('[data-detail]').forEach(el => {
        const handler = () => openDetail(el.dataset.detail, el.dataset.key || '');
        el.addEventListener('click', handler);
        if (el.getAttribute('role') === 'button') {
            el.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); handler(); } });
        }
    });

})();
</script>

<script>
(() => {
    const mapNode = document.getElementById('analyticsMap');
    const statusNode = document.getElementById('mapStatus');
    if (!mapNode || !window.L) {
        if (statusNode) {
            statusNode.classList.add('error');
            statusNode.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Library peta tidak dapat dimuat.';
        }
        return;
    }

    const params = new URLSearchParams({
        filter_tipe: <?= json_encode($range['filter_tipe']) ?>,
        tanggal_awal: <?= json_encode($range['tanggal_awal']) ?>,
        tanggal_akhir: <?= json_encode($range['tanggal_akhir']) ?>,
        bulan_awal: <?= json_encode($range['bulan_awal']) ?>,
        tahun_awal: <?= json_encode((string)$range['tahun_awal']) ?>,
        bulan_akhir: <?= json_encode($range['bulan_akhir']) ?>,
        tahun_akhir: <?= json_encode((string)$range['tahun_akhir']) ?>,
        wilayah: <?= json_encode($scope['value']) ?>
    });

    const map = L.map(mapNode, {
        zoomControl: true,
        scrollWheelZoom: false
    }).setView([-7.85, 110.48], 12);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    let geoLayer = null;
    const labelLayer = L.layerGroup().addTo(map);
    const regionRegistry = new Map();
    let activeRegionKey = '';

    function esc(value) {
        return String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
    }

    function rupiah(value) {
        const n = Number(value || 0);
        return 'Rp' + new Intl.NumberFormat('id-ID', {maximumFractionDigits: 0}).format(Number.isFinite(n) ? n : 0);
    }

    function regionStyle(status, active = false) {
        const palette = {
            paid: {fill: '#22a861', stroke: '#18794e'},
            warning: {fill: '#f39c12', stroke: '#b9770e'},
            critical: {fill: '#e74c3c', stroke: '#b9382b'},
            nodata: {fill: '#8795a1', stroke: '#64748b'}
        };
        const color = palette[status] || palette.nodata;
        return {
            color: color.stroke,
            weight: active ? 4 : 2,
            opacity: 0.95,
            fillColor: color.fill,
            fillOpacity: active ? 0.48 : 0.33,
            dashArray: active ? '' : '4 3'
        };
    }

    function chipIcon(label, status) {
        return L.divIcon({
            className: '',
            html: `<div class="map-region-chip ${esc(status)}">${esc(label)}</div>`,
            iconSize: null,
            iconAnchor: [42, 16]
        });
    }

    function popupHtml(item) {
        const status = item.status_key || 'nodata';
        const label = item.label || item.key || 'Wilayah';
        const detailType = item.detail_type || 'outstanding';
        const detailKey = item.detail_key || item.key || '';
        return `<div class="map-popup">
            <div class="map-popup-title">${esc(label)}</div>
            <div class="map-popup-meta">Ringkasan wilayah pada periode terpilih</div>
            <div class="map-popup-badges">
                <span class="map-badge ${esc(status)}">${esc(item.status_label || 'Status wilayah')}</span>
                <span class="map-badge unknown"><i class="fas fa-location-dot"></i>${Number(item.pppoe_titik || 0)} titik PPPoE</span>
            </div>
            <div class="map-popup-grid">
                <div class="map-popup-stat"><span>Total pelanggan</span><b>${Number(item.total_pelanggan || 0)} pelanggan</b></div>
                <div class="map-popup-stat"><span>Pelanggan menunggak</span><b>${Number(item.pelanggan_menunggak || 0)} pelanggan</b></div>
                <div class="map-popup-stat"><span>Tagihan belum lunas</span><b>${Number(item.tagihan_belum || 0)} tagihan</b></div>
                <div class="map-popup-stat"><span>Total tunggakan</span><b>${esc(rupiah(item.total_tunggakan || 0))}</b></div>
            </div>
            <div class="map-popup-note">Online: <b>${Number(item.online || 0)}</b> · Offline: <b>${Number(item.offline || 0)}</b> · Lama tunggakan tertinggi: <b>${Number(item.max_lama_bulan || 0)} bulan</b></div>
            <button type="button" class="map-detail-btn" data-map-detail-type="${esc(detailType)}" data-map-detail-key="${esc(detailKey)}" data-map-region="${esc(item.key || '')}"><i class="fas fa-table-list"></i> Lihat detail wilayah</button>
        </div>`;
    }

    function setStatus(html, isError = false) {
        if (!statusNode) return;
        statusNode.classList.remove('hidden', 'error');
        if (isError) statusNode.classList.add('error');
        statusNode.innerHTML = html;
    }

    function hideStatusSoon() {
        if (!statusNode) return;
        window.setTimeout(() => statusNode.classList.add('hidden'), 2400);
    }

    function highlightRegion(regionKey, openPopup = false) {
        if (!regionKey || !regionRegistry.has(regionKey)) return;
        activeRegionKey = regionKey;

        regionRegistry.forEach((entry, key) => {
            entry.layer.setStyle(regionStyle(entry.feature.properties.status_key, key === regionKey));
            if (key === regionKey && entry.layer.bringToFront) entry.layer.bringToFront();
        });

        document.querySelectorAll('.column-button[data-detail="outstanding"][data-key]').forEach(btn => {
            btn.classList.toggle('map-linked', btn.getAttribute('data-key') === regionKey);
        });

        const target = regionRegistry.get(regionKey);
        map.fitBounds(target.layer.getBounds(), {padding: [26, 26], maxZoom: 15});
        if (openPopup) target.layer.openPopup();
    }

    function addRegionLabel(feature) {
        const p = feature?.properties || {};
        const center = Array.isArray(p.center) ? p.center : null;
        if (!center || center.length !== 2) return;
        const lat = Number(center[0]);
        const lng = Number(center[1]);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

        L.marker([lat, lng], {
            interactive: false,
            keyboard: false,
            icon: chipIcon(p.label || p.key || 'Wilayah', p.status_key || 'nodata')
        }).addTo(labelLayer);
    }

    async function loadMap() {
        setStatus('<i class="fas fa-spinner fa-spin"></i> Memuat area GeoJSON wilayah...');
        try {
            const response = await fetch('get_analitik_map_salam.php?' + params.toString(), {
                headers: {'Accept': 'application/json'},
                cache: 'no-store'
            });
            const payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Peta analitik tidak dapat dimuat.');
            }

            if (geoLayer) {
                map.removeLayer(geoLayer);
                geoLayer = null;
            }
            labelLayer.clearLayers();
            regionRegistry.clear();

            const geojson = payload.geojson || {type:'FeatureCollection', features:[]};
            geoLayer = L.geoJSON(geojson, {
                style: feature => regionStyle(feature?.properties?.status_key || 'nodata'),
                onEachFeature: (feature, layer) => {
                    const p = feature?.properties || {};
                    const regionKey = p.key || p.region_key || '';
                    if (!regionKey) return;

                    regionRegistry.set(regionKey, {feature, layer});
                    layer.bindTooltip(`${esc(p.label || regionKey)} · ${esc(rupiah(p.total_tunggakan || 0))}`, {
                        sticky: true,
                        opacity: 0.96
                    });
                    layer.bindPopup(popupHtml(p), {maxWidth: 330});
                    layer.on({
                        mouseover: () => layer.setStyle(regionStyle(p.status_key || 'nodata', true)),
                        mouseout: () => {
                            layer.setStyle(regionStyle(p.status_key || 'nodata', activeRegionKey === regionKey));
                        },
                        click: () => highlightRegion(regionKey, false)
                    });
                    addRegionLabel(feature);
                }
            }).addTo(map);

            if (geoLayer.getLayers().length > 0) {
                map.fitBounds(geoLayer.getBounds(), {padding: [28, 28], maxZoom: 15});
            }

            const c = payload.counts || {};
            setStatus(`<i class="fas fa-map-location-dot"></i> ${Number(c.total || 0)} wilayah · ${Number(c.paid || 0)} aman · ${Number(c.warning || 0)} perhatian · ${Number(c.critical || 0)} prioritas`);
            hideStatusSoon();
        } catch (err) {
            setStatus(`<i class="fas fa-triangle-exclamation"></i> ${esc(err.message)}`, true);
        }
    }

    document.addEventListener('click', event => {
        const detailBtn = event.target.closest('[data-map-detail-type]');
        if (detailBtn) {
            const type = detailBtn.getAttribute('data-map-detail-type') || 'outstanding';
            const key = detailBtn.getAttribute('data-map-detail-key') || '';
            const regionKey = detailBtn.getAttribute('data-map-region') || '';
            if (regionKey) highlightRegion(regionKey, false);
            map.closePopup();
            if (typeof window.openAnalitikDetail === 'function') {
                window.openAnalitikDetail(type, key);
            }
            return;
        }

        const barBtn = event.target.closest('.column-button[data-detail="outstanding"][data-key]');
        if (barBtn) {
            const regionKey = barBtn.getAttribute('data-key');
            if (regionKey) {
                window.setTimeout(() => highlightRegion(regionKey, true), 80);
            }
        }
    });

    document.querySelectorAll('.column-button[data-detail="outstanding"][data-key]').forEach(btn => {
        const regionKey = btn.getAttribute('data-key') || '';
        btn.addEventListener('mouseenter', () => { if (regionRegistry.has(regionKey)) highlightRegion(regionKey, false); });
        btn.addEventListener('focus', () => { if (regionRegistry.has(regionKey)) highlightRegion(regionKey, false); });
    });

    loadMap();
    window.setTimeout(() => map.invalidateSize(), 250);
    let mapResizeTimer = null;
    const refreshMapSize = () => {
        window.clearTimeout(mapResizeTimer);
        mapResizeTimer = window.setTimeout(() => map.invalidateSize(), 180);
    };
    window.addEventListener('resize', refreshMapSize, {passive:true});
    window.addEventListener('orientationchange', refreshMapSize, {passive:true});
})();
</script>

</body>
</html>
