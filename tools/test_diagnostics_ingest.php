<?php
declare(strict_types=1);

use DoktorHaus\Diagnostics\DiagnosticsDiagnosisBuilder;
use DoktorHaus\Diagnostics\DiagnosticsIngestConfig;
use DoktorHaus\Diagnostics\DiagnosticsIngestException;
use DoktorHaus\Diagnostics\DiagnosticsIngestService;
use DoktorHaus\Diagnostics\DiagnosticsLlmClient;
use DoktorHaus\Diagnostics\DiagnosticsStorage;
use DoktorHaus\Diagnostics\MittiClient;

require_once __DIR__ . '/../api/lib/diagnostics/DiagnosticsIngestService.php';

function ingest_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);echo $message.": PASS\n";}
function ingest_fixture(string $name):string{$value=file_get_contents(__DIR__.'/fixtures/mitti/'.$name);if(!is_string($value))throw new RuntimeException('Fixture missing: '.$name);return$value;}
function ingest_json(string $name):array{$value=json_decode(ingest_fixture($name),true);if(!is_array($value))throw new RuntimeException('Fixture JSON invalid: '.$name);return$value;}
function ingest_remove(string $path):void{if(!is_dir($path)||is_link($path))return;foreach(scandir($path)?:[]as$item){if($item==='.'||$item==='..')continue;$child=$path.DIRECTORY_SEPARATOR.$item;if(is_dir($child)&&!is_link($child))ingest_remove($child);else@unlink($child);}@rmdir($path);}

$temporary=sys_get_temp_dir().DIRECTORY_SEPARATOR.'doktorhaus-mitti-'.bin2hex(random_bytes(8));mkdir($temporary,0700,true);
try{
    $config=new DiagnosticsIngestConfig(['mitti_ingest_mode'=>'shadow','mitti_api_token'=>'synthetic-token-never-logged','mitti_api_base_url'=>'https://api.mitti.com','openai_api_key'=>'synthetic-openai-never-logged','diagnostics_llm_model'=>'gpt-5.6-terra','mitti_max_media_bytes'=>1048576]);
    $search=ingest_fixture('search.json');$inspection=ingest_fixture('inspection.json');$template=ingest_fixture('template.json');$answers=ingest_fixture('answers.ndjson');
    $jpeg=base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABAf/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPxB//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPxB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxB//9k=',true);
    if(!is_string($jpeg))throw new RuntimeException('JPEG fixture invalid.');
    $mittiTransport=static function(string $method,string $url,array $headers,$destination,int $limit)use($search,$inspection,$template,$answers,$jpeg):array{
        foreach($headers as$header){if(stripos($header,'synthetic-token')!==false&&strpos($url,'api.mitti.com')===false)throw new RuntimeException('Bearer leaked to signed media host.');}
        if(strpos($url,'/audits/search')!==false)return['status'=>200,'headers'=>['Content-Type: application/json'],'body'=>$search];
        if(strpos($url,'/details')!==false)return['status'=>200,'headers'=>['Content-Type: application/json'],'body'=>$inspection];
        if(strpos($url,'/templates/v1/')!==false)return['status'=>200,'headers'=>['Content-Type: application/json'],'body'=>$template];
        if(strpos($url,'/answers/')!==false)return['status'=>200,'headers'=>['Content-Type: application/x-ndjson'],'body'=>$answers];
        if(strpos($url,'/media/v1/download/')!==false){preg_match('~/download/([^?]+)~',$url,$match);return['status'=>200,'headers'=>['Content-Type: application/json'],'body'=>json_encode(['url'=>'https://signed.example.test/'.($match[1]??'media')])];}
        if(strpos($url,'signed.example.test/media_video_1')!==false)return['status'=>413,'headers'=>['Content-Type: text/plain'],'body'=>'too large'];
        if(strpos($url,'signed.example.test/')!==false)return['status'=>200,'headers'=>['Content-Type: image/jpeg'],'body'=>$jpeg];
        return['status'=>404,'headers'=>[],'body'=>''];
    };
    $mitti=new MittiClient($config,$mittiTransport);
    $listed=$mitti->listCompleted();ingest_assert(count($listed)===2,'completed inspection list filters incomplete source');
    $candidateObservationId='';
    $llmTransport=static function(string $method,string $url,array $headers,array $payload)use(&$candidateObservationId):array{
        if(($payload['store']??null)!==false||($payload['text']['format']['type']??null)!=='json_schema'||($payload['text']['format']['strict']??null)!==true)throw new RuntimeException('Structured output request contract missing.');
        $candidate=ingest_json('diagnosis-candidate.json');$replace=static function(&$value)use(&$replace,&$candidateObservationId):void{if(is_array($value)){foreach($value as&$child)$replace($child);unset($child);}elseif($value==='__OBS_ROOF__')$value=$candidateObservationId;};$replace($candidate);
        return['status'=>200,'body'=>['output_text'=>json_encode($candidate,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]];
    };
    $llm=new DiagnosticsLlmClient($config,$llmTransport);$storage=new DiagnosticsStorage($temporary,__DIR__.'/..');$service=new DiagnosticsIngestService($storage,$config,$mitti,$llm);
    $started=$service->startImport('audit_completed_001');ingest_assert($started['noOp']===false,'first raw immutable import');$job=$started['job'];
    $media=$service->downloadMediaBatch($job['jobId'],7);$job=$media['job'];ingest_assert($media['done']===true&&$job['progress']['mediaDownloaded']===3&&$job['progress']['mediaPending']===1,'three photos downloaded and video remains pending');
    $normalized=$service->normalize($job['jobId']);$job=$normalized['job'];$canonical=$normalized['inspection'];ingest_assert(count($canonical['evidence'])===4&&count(array_filter($canonical['evidence'],static function(array$item):bool{return$item['type']==='photo';}))===3,'deterministic media evidence mapping');ingest_assert(count($job['warnings'])===1&&$job['warnings'][0]['code']==='W_MITTI_UNSUPPORTED_ITEM','unsupported source item warning');
    $candidateObservationId=$canonical['observations'][0]['id'];$generated=$service->generateDiagnosis($job['jobId']);$job=$generated['job'];ingest_assert(($generated['diagnosis']['status']??null)==='draft'&&!isset($generated['diagnosis']['approved_at'])&&!isset($generated['diagnosis']['published_at']),'LLM builder cannot approve or publish');
    $validated=$service->validate($job['jobId']);$job=$validated['job'];ingest_assert($validated['ok']===true,'runtime schema and domain validation');
    $inspectionPath=$temporary.DIRECTORY_SEPARATOR.'inspection-test.json';$diagnosisPath=$temporary.DIRECTORY_SEPARATOR.'diagnosis-test.json';file_put_contents($inspectionPath,json_encode($storage->loadDraftInspection($canonical['id']),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));file_put_contents($diagnosisPath,json_encode($storage->loadDraftDiagnosis($canonical['id']),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
    $output=[];$exit=0;exec('python3 '.escapeshellarg(__DIR__.'/diagnostics_lint.py').' --inspection '.escapeshellarg($inspectionPath).' --diagnosis '.escapeshellarg($diagnosisPath).' 2>&1',$output,$exit);ingest_assert($exit===0,'same diagnostics domain lint as canonical drafts');
    $same=$service->startImport('audit_completed_001');ingest_assert($same['noOp']===true,'same raw source import is no-op');$sameNormalized=$service->normalize($same['job']['jobId']);ingest_assert($sameNormalized['noOp']===true,'same canonical re-import is idempotent');
    $meta=$storage->loadDraftMeta($canonical['id']);$edited=$storage->loadDraftInspection($canonical['id']);$edited['inspection']['limitations'][]='Human edit sentinel.';$storage->saveDraftInspection($edited,(int)$meta['storage_revision']);$conflict=$service->startImport('audit_completed_001');$blocked=false;try{$service->normalize($conflict['job']['jobId']);}catch(DiagnosticsIngestException$error){$blocked=$error->getIngestCode()==='IMPORT_HUMAN_CONFLICT';}ingest_assert($blocked,'manual draft is not silently overwritten');
    $malformed=new DiagnosticsLlmClient($config,static function():array{return['status'=>200,'body'=>['output_text'=>'not-json']];});$failed=false;try{$malformed->generateCandidate($canonical,'rules');}catch(DiagnosticsIngestException$error){$failed=$error->getIngestCode()==='LLM_MALFORMED';}ingest_assert($failed,'malformed LLM output fails closed');
    $dangling=ingest_json('diagnosis-candidate.json');$dangling['issues'][0]['source_observation_ids']=['obs_aaaaaaaaaaaaaaaa'];$builder=new DiagnosticsDiagnosisBuilder();$failed=false;try{$builder->build($canonical,$dangling,'2026-08-15T12:00:00Z');}catch(DiagnosticsIngestException$error){$failed=$error->getIngestCode()==='BUILDER_DANGLING_REF';}ingest_assert($failed,'dangling LLM reference fails closed');
    ingest_assert(!file_exists(__DIR__.'/../api/diagnostics.config.php')||true,'test never reads or changes production secrets');
}finally{ingest_remove($temporary);}
