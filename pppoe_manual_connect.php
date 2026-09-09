<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
require_once __DIR__ . '/pelanggan_detail_helper.php';
require_once __DIR__ . '/config_monitoring_pppoe.php';
require_once __DIR__ . '/pppoe_manual_helper.php';

header('Content-Type: application/json; charset=utf-8');
salamRequireLogin();

function manualReply(bool $success, string $message, array $extra=[]): void
{
    echo json_encode(['success'=>$success,'message'=>$message]+$extra, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (!salamDetailPelangganTableReady($koneksi)) throw new RuntimeException('Tabel detail pelanggan belum tersedia.');
    $billingRows=salamManualBillingRows($koneksi);
    $billingById=[]; foreach($billingRows as $row) $billingById[(int)$row['id']]=$row;
    $pppoes=salamManualFetchPppoe();
    $pppoeById=[]; foreach($pppoes as $row) $pppoeById[salamManualPppoeId($row)]=$row;

    if ($_SERVER['REQUEST_METHOD']==='GET') {
        $billingId=(int)($_GET['billing_id']??0);
        $pppoeId=trim((string)($_GET['pppoe_id']??''));
        $used=[]; foreach($billingRows as $row) if(trim((string)$row['pppoe_user'])!=='') $used[(string)$row['pppoe_user']]=(int)$row['id'];
        $items=[];
        if ($billingId>0 && isset($billingById[$billingId])) {
            $billing=$billingById[$billingId];
            foreach($pppoes as $pppoe){
                $id=salamManualPppoeId($pppoe);
                if(isset($used[$id])&&$used[$id]!==$billingId) continue;
                $rank=salamManualCandidate($billing,$pppoe); if($rank===null) continue;
                $items[]=salamManualPublicPppoe($pppoe,$rank);
            }
            usort($items,static fn($a,$b)=>($b['score']<=>$a['score']) ?: (($a['distance']??PHP_FLOAT_MAX)<=>($b['distance']??PHP_FLOAT_MAX)));
            manualReply(true,'Kandidat PPPoE ditemukan.',['candidates'=>array_slice($items,0,5),'current'=>(string)$billing['pppoe_user']]);
        }
        if ($pppoeId!=='' && isset($pppoeById[$pppoeId])) {
            foreach($billingRows as $billing){
                if(trim((string)$billing['pppoe_user'])!=='' && (string)$billing['pppoe_user']!==$pppoeId) continue;
                $rank=salamManualCandidate($billing,$pppoeById[$pppoeId]); if($rank===null) continue;
                $items[]=['id'=>(int)$billing['id'],'id_pelanggan'=>(string)$billing['id_pelanggan'],'nama'=>(string)$billing['nama'],'nama_ktp'=>(string)$billing['nama_ktp'],'alamat'=>(string)$billing['alamat'],'score'=>round($rank['score'],1),'distance'=>$rank['distance']===null?null:round($rank['distance'],1)];
            }
            usort($items,static fn($a,$b)=>($b['score']<=>$a['score']) ?: (($a['distance']??PHP_FLOAT_MAX)<=>($b['distance']??PHP_FLOAT_MAX)));
            manualReply(true,'Kandidat pelanggan ditemukan.',['candidates'=>array_slice($items,0,5)]);
        }
        manualReply(false,'Pelanggan atau PPPoE tidak ditemukan.');
    }

    if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); manualReply(false,'Metode tidak diizinkan.'); }
    $input=json_decode(file_get_contents('php://input'),true)?:[];
    $token=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
    if ($token===''||!hash_equals((string)($_SESSION['pppoe_manual_csrf']??''),$token)) { http_response_code(403); manualReply(false,'Token keamanan tidak valid. Muat ulang halaman.'); }
    $action=(string)($input['action']??'connect');
    $billingId=(int)($input['billing_id']??0);
    if(!isset($billingById[$billingId])) { http_response_code(404); manualReply(false,'Pelanggan tidak ditemukan atau di luar wilayah akun.'); }
    if($action==='disconnect'){
        $stmt=$koneksi->prepare('UPDATE pelanggan_detail_salam SET pppoe_user=NULL WHERE pelanggan_id=?');
        $stmt->bind_param('i',$billingId); $stmt->execute(); $stmt->close();
        manualReply(true,'Koneksi manual berhasil diputuskan. Matching otomatis akan digunakan kembali.');
    }
    $pppoeId=trim((string)($input['pppoe_id']??''));
    if($pppoeId===''||!isset($pppoeById[$pppoeId])) { http_response_code(422); manualReply(false,'PPPoE yang dipilih tidak ditemukan pada data terbaru.'); }
    if(salamManualCandidate($billingById[$billingId],$pppoeById[$pppoeId])===null){ http_response_code(422); manualReply(false,'PPPoE harus berasal dari wilayah yang sama.'); }
    $check=$koneksi->prepare('SELECT pelanggan_id FROM pelanggan_detail_salam WHERE pppoe_user=? AND pelanggan_id<>? LIMIT 1');
    $check->bind_param('si',$pppoeId,$billingId); $check->execute(); $conflict=$check->get_result()->fetch_assoc(); $check->close();
    if($conflict){ http_response_code(409); manualReply(false,'PPPoE tersebut sudah terhubung dengan pelanggan lain.'); }
    $stmt=$koneksi->prepare('INSERT INTO pelanggan_detail_salam (pelanggan_id,pppoe_user) VALUES (?,?) ON DUPLICATE KEY UPDATE pppoe_user=VALUES(pppoe_user)');
    $stmt->bind_param('is',$billingId,$pppoeId); $ok=$stmt->execute(); $stmt->close();
    if(!$ok) throw new RuntimeException('Koneksi manual gagal disimpan.');
    manualReply(true,'Pelanggan berhasil dihubungkan secara manual ke PPPoE.');
} catch(Throwable $e){ http_response_code(500); manualReply(false,$e->getMessage()); }
