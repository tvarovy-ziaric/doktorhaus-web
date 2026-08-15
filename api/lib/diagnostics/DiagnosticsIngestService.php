<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

require_once __DIR__ . '/DiagnosticsIngestException.php';
require_once __DIR__ . '/DiagnosticsIngestConfig.php';
require_once __DIR__ . '/DiagnosticsStorage.php';
require_once __DIR__ . '/MittiClient.php';
require_once __DIR__ . '/MittiImportStore.php';
require_once __DIR__ . '/MittiInspectionMapper.php';
require_once __DIR__ . '/DiagnosticsLlmClient.php';
require_once __DIR__ . '/DiagnosticsDiagnosisBuilder.php';
require_once __DIR__ . '/DiagnosticsDraftValidator.php';

final class DiagnosticsIngestService
{
    /** @var DiagnosticsStorage */ private $storage;
    /** @var DiagnosticsIngestConfig */ private $config;
    /** @var MittiClient */ private $mitti;
    /** @var MittiImportStore */ private $imports;
    /** @var MittiInspectionMapper */ private $mapper;
    /** @var DiagnosticsLlmClient */ private $llm;
    /** @var DiagnosticsDiagnosisBuilder */ private $builder;
    /** @var DiagnosticsDraftValidator */ private $validator;

    public function __construct(
        DiagnosticsStorage $storage,
        DiagnosticsIngestConfig $config,
        ?MittiClient $mitti = null,
        ?DiagnosticsLlmClient $llm = null
    ) {
        $this->storage=$storage;$this->config=$config;$this->mitti=$mitti??new MittiClient($config);$this->imports=new MittiImportStore($storage);$this->mapper=new MittiInspectionMapper();$this->llm=$llm??new DiagnosticsLlmClient($config);$this->builder=new DiagnosticsDiagnosisBuilder();$this->validator=new DiagnosticsDraftValidator();
    }

    public function connectionStatus():array{return $this->mitti->connectionStatus();}
    public function listCompleted():array{$this->config->assertEnabled();return $this->mitti->listCompleted(30);}

    /** @return array<string,mixed> */
    public function startImport(string $sourceInspectionId):array
    {
        $this->config->assertEnabled();
        $warnings=[];
        try{
            $this->imports->appendAudit('mitti_import_started','success');
            $raw=$this->mitti->getInspection($sourceInspectionId);
            try{$template=$this->mitti->getTemplate($sourceInspectionId);}catch(\Throwable $e){$template=[];$warnings[]=['code'=>'W_MITTI_TEMPLATE_UNAVAILABLE','message'=>'Template labels sa nepodarilo načítať.'];}
            try{$answers=$this->mitti->getAnswers($sourceInspectionId);}catch(\Throwable $e){$answers=[];$warnings[]=['code'=>'W_MITTI_ANSWERS_UNAVAILABLE','message'=>'Answers stream sa nepodarilo načítať.'];}
            $modified=$this->nestedString($raw,[['modified_at'],['last_modified_at'],['audit_data','date_modified']],gmdate('Y-m-d\TH:i:s\Z'));
            $media=$this->mapper->extractMedia($raw);
            $snapshot=$this->imports->createSnapshot($sourceInspectionId,$modified,$raw,$template,$answers,$media);
            $job=$this->imports->createJob((string)$snapshot['sourceKey'],(string)$snapshot['sourceRevision'],(bool)$snapshot['noOp']);
            if($warnings!==[])$job=$this->imports->updateJob($job['jobId'],$job['jobRevision'],['warnings'=>$warnings]);
            return ['job'=>$job,'noOp'=>(bool)$snapshot['noOp']];
        }catch(\Throwable $error){try{$this->imports->appendAudit('mitti_import_failed','failure',['reason_code'=>$error instanceof DiagnosticsIngestException?$error->getIngestCode():'UNEXPECTED']);}catch(\Throwable $ignored){}throw $error;}
    }

    /** @return array<string,mixed> */
    public function downloadMediaBatch(string $jobId,int $batchSize=7):array
    {
        $job=$this->imports->loadJob($jobId);$batchSize=max(1,min(10,$batchSize));$processed=0;
        while($processed<$batchSize){$media=$this->imports->nextPendingMedia($job['sourceKey'],$job['sourceRevision']);if($media===null)break;$temporary=$this->imports->mediaTemporaryPath($job['sourceKey'],$job['sourceRevision'],(string)$media['storage_filename']);try{$result=$this->mitti->downloadMedia((string)$media['source_media_id'],(string)$media['media_type'],$temporary);$this->imports->completeMedia($job['sourceKey'],$job['sourceRevision'],(string)$media['source_media_id'],$temporary,$result);}catch(\Throwable $error){@unlink($temporary);$this->imports->markMediaPendingReason($job['sourceKey'],$job['sourceRevision'],(string)$media['source_media_id'],$error instanceof DiagnosticsIngestException?$error->getIngestCode():'DOWNLOAD_FAILED');}$processed++;}
        $manifest=$this->imports->loadManifest($job['sourceKey'],$job['sourceRevision']);$downloaded=0;$unresolved=0;foreach((array)($manifest['media']??[])as$media){if(($media['status']??null)==='downloaded')$downloaded++;elseif(!empty($media['pending_reason']))$unresolved++;}
        $progress=$job['progress'];$progress['step']=2;$progress['mediaDownloaded']=$downloaded;$progress['mediaTotal']=count((array)($manifest['media']??[]));$progress['mediaPending']=$unresolved;
        $job=$this->imports->updateJob($jobId,$job['jobRevision'],['state'=>'media_ready','progress'=>$progress]);return ['job'=>$job,'processed'=>$processed,'done'=>$this->imports->nextPendingMedia($job['sourceKey'],$job['sourceRevision'])===null];
    }

    /** @return array<string,mixed> */
    public function normalize(string $jobId):array
    {
        $job=$this->imports->loadJob($jobId);$raw=$this->imports->loadRawInspection($job['sourceKey'],$job['sourceRevision']);$template=$this->imports->loadRawTemplate($job['sourceKey'],$job['sourceRevision']);$answers=$this->imports->loadRawAnswers($job['sourceKey'],$job['sourceRevision']);$manifest=$this->imports->loadManifest($job['sourceKey'],$job['sourceRevision']);$mapped=$this->mapper->map($raw,$template,$answers,$manifest);$document=$mapped['document'];$validation=$this->validator->validateInspection($document);if(!$validation['ok'])throw new DiagnosticsIngestException('MAPPER_SCHEMA','Normalizovaná inspection neprešla runtime schema kontrolou.',['errors'=>$validation['errors']]);
        $inspectionId=$document['id'];$newHash=$this->hash($document);$expected=null;
        if($this->storage->draftExists($inspectionId)){$current=$this->storage->loadDraftInspection($inspectionId);$currentMeta=$this->storage->loadDraftMeta($inspectionId);$currentHash=$this->hash($current);$safe=false;foreach($this->imports->canonicalBaselines($job['sourceKey'])as$baseline){if(($baseline['inspection_id']??null)===$inspectionId&&($baseline['inspection_sha256']??null)===$currentHash){$safe=true;break;}}if(!$safe){$job=$this->imports->updateJob($jobId,$job['jobRevision'],['state'=>'import_conflict','errorCode'=>'HUMAN_REVIEW_REQUIRED']);throw new DiagnosticsIngestException('IMPORT_HUMAN_CONFLICT','Draft bol po importe upravený. Re-import ho nesmie prepísať.');}$expected=(int)$currentMeta['storage_revision'];if($currentHash===$newHash){$progress=$job['progress'];$progress['step']=3;$job=$this->imports->updateJob($jobId,$job['jobRevision'],['state'=>'normalized','progress'=>$progress,'draftInspectionId'=>$inspectionId,'storageRevision'=>$expected,'normalizedInspectionSha256'=>$newHash,'warnings'=>array_merge((array)$job['warnings'],$mapped['warnings'])]);return ['job'=>$job,'inspection'=>$document,'noOp'=>true];}}
        $saved=$this->storage->saveDraftInspection($document,$expected);$revision=(int)$saved['storage_revision'];$this->imports->saveCanonicalMeta($job['sourceKey'],$job['sourceRevision'],['inspection_id'=>$inspectionId,'inspection_sha256'=>$newHash,'storage_revision'=>$revision,'source_revision'=>$job['sourceRevision'],'created_at'=>$job['createdAt']]);$progress=$job['progress'];$progress['step']=3;$job=$this->imports->updateJob($jobId,$job['jobRevision'],['state'=>'normalized','progress'=>$progress,'draftInspectionId'=>$inspectionId,'storageRevision'=>$revision,'normalizedInspectionSha256'=>$newHash,'warnings'=>array_merge((array)$job['warnings'],$mapped['warnings'])]);return ['job'=>$job,'inspection'=>$document,'noOp'=>false];
    }

    /** @return array<string,mixed> */
    public function generateDiagnosis(string $jobId):array
    {
        $job=$this->imports->loadJob($jobId);$inspectionId=(string)($job['draftInspectionId']??'');if($inspectionId==='')throw new DiagnosticsIngestException('INGEST_STATE','Najprv treba normalizovať inspection.');$inspection=$this->storage->loadDraftInspection($inspectionId);$candidate=$this->llm->generateCandidate($inspection,$this->ruleContext());$diagnosis=$this->builder->build($inspection,$candidate,(string)$job['createdAt']);$validation=$this->validator->validateDiagnosis($diagnosis,$inspection);if(!$validation['ok'])throw new DiagnosticsIngestException('DIAGNOSIS_SCHEMA','Vytvorená diagnosis neprešla runtime schema kontrolou.',['errors'=>$validation['errors']]);$meta=$this->storage->loadDraftMeta($inspectionId);$saved=$this->storage->saveDraftDiagnosis($diagnosis,(int)$meta['storage_revision']);$hash=$this->hash($diagnosis);$progress=$job['progress'];$progress['step']=4;$job=$this->imports->updateJob($jobId,$job['jobRevision'],['state'=>'diagnosis_generated','progress'=>$progress,'storageRevision'=>(int)$saved['storage_revision'],'normalizedDiagnosisSha256'=>$hash]);$this->imports->appendAudit('diagnostic_draft_generated','success',['job_id'=>$jobId,'inspection_id'=>$inspectionId]);return ['job'=>$job,'diagnosis'=>$diagnosis,'candidate'=>$candidate];
    }

    /** @return array<string,mixed> */
    public function validate(string $jobId):array
    {
        $job=$this->imports->loadJob($jobId);$inspectionId=(string)($job['draftInspectionId']??'');if($inspectionId==='')throw new DiagnosticsIngestException('INGEST_STATE','Draft ešte neexistuje.');$inspection=$this->storage->loadDraftInspection($inspectionId);$inspectionResult=$this->validator->validateInspection($inspection);$diagnosisResult=['ok'=>false,'errors'=>[['path'=>'$','code'=>'E_MISSING_DIAGNOSIS','message'=>'Diagnosis draft ešte neexistuje.']],'warnings'=>[]];try{$diagnosis=$this->storage->loadDraftDiagnosis($inspectionId);$diagnosisResult=$this->validator->validateDiagnosis($diagnosis,$inspection);}catch(\Throwable $ignored){}$ok=$inspectionResult['ok']&&$diagnosisResult['ok'];$errors=array_merge($inspectionResult['errors'],$diagnosisResult['errors']);$warnings=array_merge($inspectionResult['warnings'],$diagnosisResult['warnings']);$progress=$job['progress'];$progress['step']=$ok?6:5;$job=$this->imports->updateJob($jobId,$job['jobRevision'],['state'=>$ok?'ready_for_human_review':'validation_failed','progress'=>$progress,'validation'=>['ok'=>$ok,'errors'=>$errors,'warnings'=>$warnings]]);$this->imports->appendAudit('diagnostic_draft_validated',$ok?'success':'failure',['job_id'=>$jobId,'inspection_id'=>$inspectionId]);return ['job'=>$job,'ok'=>$ok,'errors'=>$errors,'warnings'=>$warnings];
    }

    /** @return array<string,mixed> */
    public function status(string $jobId):array
    {
        $job=$this->imports->loadJob($jobId);$summary=['observations'=>0,'evidence'=>0,'photos'=>0,'videosPending'=>0,'issues'=>0,'warnings'=>count((array)$job['warnings']),'validationErrors'=>count((array)($job['validation']['errors']??[])),'pricingAvailable'=>false];$inspectionId=(string)($job['draftInspectionId']??'');if($inspectionId!==''){try{$inspection=$this->storage->loadDraftInspection($inspectionId);$summary['observations']=count((array)$inspection['observations']);$summary['evidence']=count((array)$inspection['evidence']);foreach((array)$inspection['evidence']as$item){if(($item['type']??null)==='photo')$summary['photos']++;if(($item['type']??null)==='video'&&strpos((string)($item['description']??''),'pending')!==false)$summary['videosPending']++;}try{$diagnosis=$this->storage->loadDraftDiagnosis($inspectionId);$summary['issues']=count((array)$diagnosis['issues']);}catch(\Throwable $ignored){}}catch(\Throwable $ignored){}}return ['job'=>$job,'summary'=>$summary];
    }

    /** @return array<string,mixed> */
    public function draft(string $jobId,string $type):array
    {
        $job=$this->imports->loadJob($jobId);$id=(string)($job['draftInspectionId']??'');if($id==='')throw new DiagnosticsIngestException('INGEST_STATE','Draft ešte neexistuje.');if($type==='inspection')return $this->storage->loadDraftInspection($id);if($type==='diagnosis')return $this->storage->loadDraftDiagnosis($id);throw new DiagnosticsIngestException('INGEST_DRAFT_TYPE','Pricing draft v shadow MVP nie je automaticky vytváraný.');
    }

    /** @return array<string,mixed> */
    public function replaceDraft(string $jobId,string $type,array $document,int $expectedRevision):array
    {
        $job=$this->imports->loadJob($jobId);$id=(string)($job['draftInspectionId']??'');if($id==='')throw new DiagnosticsIngestException('INGEST_STATE','Draft ešte neexistuje.');if($expectedRevision<1||$expectedRevision!==(int)($job['storageRevision']??0))throw new DiagnosticsIngestException('IMPORT_JOB_CONFLICT','Upload používa zastaranú draft revision.');$currentInspection=$this->storage->loadDraftInspection($id);
        if($type==='inspection'){$result=$this->validator->validateInspection($document);if(($document['id']??null)!==$id)$result['errors'][]=['path'=>'$.id','code'=>'E_OWNERSHIP','message'=>'Nahraný dokument patrí inej inspection.'];if($result['errors']!==[])throw new DiagnosticsIngestException('UPLOAD_VALIDATION','Nahraný inspection JSON nie je platný.',['errors'=>$result['errors']]);$saved=$this->storage->saveDraftInspection($document,$expectedRevision);$inspectionHash=$this->hash($document);$diagnosisHash=$job['normalizedDiagnosisSha256'];}
        elseif($type==='diagnosis'){$result=$this->validator->validateDiagnosis($document,$currentInspection);if($result['errors']!==[])throw new DiagnosticsIngestException('UPLOAD_VALIDATION','Nahraný diagnosis JSON nie je platný.',['errors'=>$result['errors']]);$saved=$this->storage->saveDraftDiagnosis($document,$expectedRevision);$inspectionHash=$job['normalizedInspectionSha256'];$diagnosisHash=$this->hash($document);}else throw new DiagnosticsIngestException('INGEST_DRAFT_TYPE','Pricing upload nie je v shadow MVP dostupný bez pricing generátora.');
        $job=$this->imports->updateJob($jobId,$job['jobRevision'],['state'=>'human_edited','storageRevision'=>(int)$saved['storage_revision'],'normalizedInspectionSha256'=>$inspectionHash,'normalizedDiagnosisSha256'=>$diagnosisHash]);$this->imports->appendAudit('diagnostic_draft_replaced_by_human','success',['job_id'=>$jobId,'inspection_id'=>$id]);return ['job'=>$job,'validation'=>$result];
    }

    private function ruleContext():string{$root=dirname(__DIR__,3);$parts=[];foreach(['WORKFLOW.md','REPORT_CONTRACT.md','SCORING_RULES.md']as$file){$path=$root.'/docs/diagnostics/'.$file;$contents=@file_get_contents($path);if(!is_string($contents))throw new DiagnosticsIngestException('LLM_RULES','Diagnostický source-of-truth dokument chýba.');$parts[]="===== $file =====\n".$contents;}return implode("\n\n",$parts);}
    private function hash(array $value):string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new DiagnosticsIngestException('INGEST_JSON','Draft sa nepodarilo serializovať.');return hash('sha256',$json);}
    private function nestedString(array $row,array $paths,string $default=''):string{foreach($paths as$path){$value=$row;foreach($path as$segment){if(!is_array($value)||!array_key_exists($segment,$value)){$value=null;break;}$value=$value[$segment];}if(is_string($value)||is_numeric($value)){if(trim((string)$value)!=='')return trim((string)$value);}}return$default;}
}
