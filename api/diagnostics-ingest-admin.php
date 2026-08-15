<?php
declare(strict_types=1);

use DoktorHaus\Diagnostics\DiagnosticsIngestConfig;
use DoktorHaus\Diagnostics\DiagnosticsIngestException;
use DoktorHaus\Diagnostics\DiagnosticsIngestService;
use DoktorHaus\Diagnostics\DiagnosticsStorage;
use DoktorHaus\Diagnostics\DiagnosticsStorageException;

require_once __DIR__ . '/lib/diagnostics/DiagnosticsIngestException.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsIngestConfig.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsStorage.php';
require_once __DIR__ . '/lib/diagnostics/DiagnosticsIngestService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function ingest_respond(int $status,array $payload):void{http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);exit;}
function ingest_payload():array{if(stripos((string)($_SERVER['CONTENT_TYPE']??''),'multipart/form-data')===0)return $_POST;$decoded=json_decode((string)(file_get_contents('php://input')?:''),true);return is_array($decoded)?$decoded:[];}
function ingest_clean(string $value,int $max):string{$value=trim($value);return strlen($value)<=$max?$value:substr($value,0,$max);}

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')ingest_respond(405,['ok'=>false,'error'=>'Nepodporovaná metóda.','code'=>'METHOD_NOT_ALLOWED']);
$payload=ingest_payload();$local=__DIR__.'/inspections.config.php';$legacy=is_file($local)?require$local:[];if(!is_array($legacy))$legacy=[];$adminPin=getenv('INSPECTIONS_ADMIN_PIN');if($adminPin===false||$adminPin==='')$adminPin=getenv('PUBLIC_HELP_PIN');if($adminPin===false||$adminPin==='')$adminPin=(string)($legacy['admin_pin']??'');
if($adminPin==='')ingest_respond(503,['ok'=>false,'error'=>'Admin autorizácia nie je nakonfigurovaná.','code'=>'ADMIN_CONFIG']);
$provided=is_string($payload['adminPin']??null)?$payload['adminPin']:'';if(!hash_equals($adminPin,$provided))ingest_respond(403,['ok'=>false,'error'=>'Nesprávny Admin PIN.','code'=>'ADMIN_FORBIDDEN']);

try{
    $config=DiagnosticsIngestConfig::fromEnvironment();$service=new DiagnosticsIngestService(DiagnosticsStorage::fromEnvironment(),$config);$action=ingest_clean((string)($payload['action']??''),50);
    if($action==='connection-status')ingest_respond(200,['ok'=>true,'connection'=>$service->connectionStatus()]);
    if($action==='list-completed')ingest_respond(200,['ok'=>true,'items'=>$service->listCompleted()]);
    if($action==='start-import')ingest_respond(200,array_merge(['ok'=>true],$service->startImport(ingest_clean((string)($payload['sourceInspectionId']??''),160))));
    if($action==='download-media-batch')ingest_respond(200,array_merge(['ok'=>true],$service->downloadMediaBatch(ingest_clean((string)($payload['jobId']??''),40),(int)($payload['batchSize']??7))));
    if($action==='normalize')ingest_respond(200,array_merge(['ok'=>true],$service->normalize(ingest_clean((string)($payload['jobId']??''),40))));
    if($action==='generate-diagnosis')ingest_respond(200,array_merge(['ok'=>true],$service->generateDiagnosis(ingest_clean((string)($payload['jobId']??''),40))));
    if($action==='status')ingest_respond(200,array_merge(['ok'=>true],$service->status(ingest_clean((string)($payload['jobId']??''),40))));
    if($action==='validate')ingest_respond(200,array_merge(['ok'=>true],$service->validate(ingest_clean((string)($payload['jobId']??''),40))));
    if($action==='download-draft'){
        $type=ingest_clean((string)($payload['type']??''),20);$document=$service->draft(ingest_clean((string)($payload['jobId']??''),40),$type);header('Content-Disposition: attachment; filename="'.($type==='diagnosis'?'diagnosis.json':'inspection.json').'"');echo json_encode($document,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";exit;
    }
    if($action==='upload-draft'){
        if(!isset($_FILES['draft'])||!is_array($_FILES['draft'])||($_FILES['draft']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new DiagnosticsIngestException('UPLOAD_FILE','Vyberte JSON súbor.');
        $file=$_FILES['draft'];if((int)($file['size']??0)<2||(int)$file['size']>5242880)throw new DiagnosticsIngestException('UPLOAD_SIZE','JSON súbor má neplatnú veľkosť.');$name=(string)($file['name']??'');if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='json')throw new DiagnosticsIngestException('UPLOAD_TYPE','Povolený je iba JSON súbor.');$mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);if(!in_array($mime,['application/json','text/plain'],true))throw new DiagnosticsIngestException('UPLOAD_MIME','Nahraný súbor nie je JSON.');$decoded=json_decode((string)file_get_contents((string)$file['tmp_name']),true);if(!is_array($decoded))throw new DiagnosticsIngestException('UPLOAD_JSON','Nahraný JSON sa nepodarilo parse-núť.');$result=$service->replaceDraft(ingest_clean((string)($payload['jobId']??''),40),ingest_clean((string)($payload['type']??''),20),$decoded,(int)($payload['storageRevision']??0));ingest_respond(200,array_merge(['ok'=>true],$result));
    }
    ingest_respond(422,['ok'=>false,'error'=>'Neznáma ingest akcia.','code'=>'UNKNOWN_ACTION']);
}catch(DiagnosticsIngestException$error){$status=422;if(in_array($error->getIngestCode(),['INGEST_DISABLED','IMPORT_HUMAN_CONFLICT','IMPORT_JOB_CONFLICT'],true))$status=409;elseif(in_array($error->getIngestCode(),['MITTI_NOT_CONFIGURED','LLM_NOT_CONFIGURED','INGEST_CONFIG'],true))$status=503;elseif(in_array($error->getIngestCode(),['MITTI_RATE_LIMIT'],true))$status=429;ingest_respond($status,['ok'=>false,'error'=>$error->getMessage(),'code'=>$error->getIngestCode(),'details'=>$error->getDetails()]);}
catch(DiagnosticsStorageException$error){ingest_respond(503,['ok'=>false,'error'=>'Diagnostické storage nie je dostupné.','code'=>'STORAGE']);}
catch(Throwable$error){ingest_respond(500,['ok'=>false,'error'=>'Mitti ingest zlyhal bezpečným spôsobom.','code'=>'INGEST_FAILED']);}
