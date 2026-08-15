<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

final class DiagnosticsDraftValidator
{
    /**
     * Runtime fail-closed validation used before draft writes. CI additionally runs the
     * normative Draft 2020-12 schemas and tools/diagnostics_lint.py.
     * @return array{ok: bool, errors: array<int, array<string, string>>, warnings: array<int, array<string, string>>}
     */
    public function validateInspection(array $document): array
    {
        $errors=[];
        $this->required($document,['schema_version','document_type','id','property','inspection','actors','observations','evidence','observation_evidence_links','import_metadata','created_at','updated_at'],'',$errors);
        if (($document['schema_version']??null)!=='1.0.0') $this->error($errors,'$.schema_version','E_SCHEMA_VERSION','Očakáva sa schema 1.0.0.');
        if (($document['document_type']??null)!=='inspection') $this->error($errors,'$.document_type','E_DOCUMENT_TYPE','Očakáva sa inspection dokument.');
        $id=(string)($document['id']??''); if(preg_match('/^insp_[0-9a-f]{16,32}$/D',$id)!==1)$this->error($errors,'$.id','E_ID','Neplatné inspection ID.');
        foreach(['actors','observations','evidence','observation_evidence_links'] as $field){if(!isset($document[$field])||!is_array($document[$field]))$this->error($errors,'$.'.$field,'E_TYPE','Pole musí byť array.');}
        $observationIds=[];$evidenceIds=[];
        foreach((array)($document['observations']??[]) as $index=>$item){if(!is_array($item)){$this->error($errors,'$.observations['.$index.']','E_TYPE','Observation musí byť object.');continue;}$this->required($item,['id','inspection_id','statement','type','area','status','provenance','observed_at','observed_by','limitations'],'$.observations['.$index.']',$errors);$oid=(string)($item['id']??'');if(preg_match('/^obs_[0-9a-f]{16,32}$/D',$oid)!==1||isset($observationIds[$oid]))$this->error($errors,'$.observations['.$index.'].id','E_ID','Neplatné alebo duplicitné observation ID.');$observationIds[$oid]=true;if(($item['inspection_id']??null)!==$id)$this->error($errors,'$.observations['.$index.'].inspection_id','E_OWNERSHIP','Observation patrí inej inspection.');}
        foreach((array)($document['evidence']??[]) as $index=>$item){if(!is_array($item))continue;$this->required($item,['id','inspection_id','type','title','description','captured_at','captured_by','provenance','media_reference','privacy','status','content_type'],'$.evidence['.$index.']',$errors);$eid=(string)($item['id']??'');if(preg_match('/^ev_[0-9a-f]{16,32}$/D',$eid)!==1||isset($evidenceIds[$eid]))$this->error($errors,'$.evidence['.$index.'].id','E_ID','Neplatné alebo duplicitné evidence ID.');$evidenceIds[$eid]=true;if(($item['inspection_id']??null)!==$id)$this->error($errors,'$.evidence['.$index.'].inspection_id','E_OWNERSHIP','Evidence patrí inej inspection.');$ref=(string)($item['media_reference']??'');if($ref===''||strpos($ref,'..')!==false||preg_match('/^[A-Za-z]:|^\/|^\\\\|^[a-z]+:\/\//i',$ref))$this->error($errors,'$.evidence['.$index.'].media_reference','E_PATH','Nebezpečná media reference.');}
        foreach((array)($document['observation_evidence_links']??[]) as $index=>$link){if(!is_array($link)||!isset($observationIds[(string)($link['observation_id']??'')])||!isset($evidenceIds[(string)($link['evidence_id']??'')]))$this->error($errors,'$.observation_evidence_links['.$index.']','E_DANGLING_REFERENCE','Link odkazuje na neznámy objekt.');}
        foreach(['approved_at','approved_by','published_at','pin','access_id','report_id'] as $forbidden){if($this->containsKey($document,$forbidden))$this->error($errors,'$','E_FORBIDDEN_WORKFLOW_FIELD','Draft obsahuje nepovolené workflow pole: '.$forbidden);}
        return ['ok'=>$errors===[],'errors'=>$errors,'warnings'=>[]];
    }

    /** @return array{ok: bool, errors: array<int, array<string, string>>, warnings: array<int, array<string, string>>} */
    public function validateDiagnosis(array $document, array $inspection): array
    {
        $errors=[];$required=['schema_version','document_type','id','inspection_id','status','actors','issues','hypotheses','impacts','verifications','recommendations','issue_observation_links','issue_evidence_links','hypothesis_evidence_links','verification_issue_links','verification_hypothesis_links','verification_evidence_links','recommendation_issue_links','recommendation_hypothesis_links','recommendation_dependencies','issue_relations','qa','created_at','updated_at'];$this->required($document,$required,'',$errors);
        $inspectionId=(string)($inspection['id']??'');if(($document['document_type']??null)!=='diagnosis'||($document['schema_version']??null)!=='1.0.0')$this->error($errors,'$','E_DOCUMENT_TYPE','Očakáva sa diagnosis schema 1.0.0.');if(($document['id']??null)!==$inspectionId||($document['inspection_id']??null)!==$inspectionId)$this->error($errors,'$.inspection_id','E_OWNERSHIP','Diagnosis patrí inej inspection.');if(($document['status']??null)!=='draft')$this->error($errors,'$.status','E_DRAFT_STATUS','Ingest môže uložiť iba draft.');
        $observations=[];$evidence=[];foreach((array)($inspection['observations']??[])as$item){if(is_string($item['id']??null))$observations[$item['id']]=true;}foreach((array)($inspection['evidence']??[])as$item){if(is_string($item['id']??null))$evidence[$item['id']]=true;}
        $issues=[];$hypotheses=[];$impacts=[];$verifications=[];$recommendations=[];
        foreach((array)($document['issues']??[]) as $index=>$item){if(!is_array($item))continue;$id=(string)($item['id']??'');if(preg_match('/^issue_[0-9a-f]{16,32}$/D',$id)!==1||isset($issues[$id]))$this->error($errors,'$.issues['.$index.'].id','E_ID','Neplatné alebo duplicitné issue ID.');$issues[$id]=true;if(count((array)($item['affected_areas']??[]))<1)$this->error($errors,'$.issues['.$index.'].affected_areas','E_REQUIRED','Issue musí mať affected area.');}
        foreach((array)($document['hypotheses']??[]) as $index=>$item){if(!is_array($item))continue;$id=(string)($item['id']??'');$hypotheses[$id]=true;if(!isset($issues[(string)($item['diagnostic_issue_id']??'')]))$this->error($errors,'$.hypotheses['.$index.'].diagnostic_issue_id','E_DANGLING_REFERENCE','Hypothesis odkazuje na neznámy issue.');}
        foreach((array)($document['impacts']??[]) as $index=>$item){if(!is_array($item))continue;$issue=(string)($item['diagnostic_issue_id']??'');$dimension=(string)($item['dimension']??'');if(!isset($issues[$issue]))$this->error($errors,'$.impacts['.$index.'].diagnostic_issue_id','E_DANGLING_REFERENCE','Impact odkazuje na neznámy issue.');$impacts[$issue][$dimension]=true;foreach((array)($item['supporting_observation_ids']??[])as$oid){if(!isset($observations[(string)$oid]))$this->error($errors,'$.impacts['.$index.'].supporting_observation_ids','E_DANGLING_REFERENCE','Impact odkazuje na neznáme observation.');}}
        $dimensions=['safety','structural','moisture','health','durability','usability','financial'];foreach(array_keys($issues)as$issue){$actual=array_keys($impacts[$issue]??[]);sort($actual);$expected=$dimensions;sort($expected);if($actual!==$expected)$this->error($errors,'$.impacts','E_IMPACT_DIMENSIONS','Každý issue musí mať presne sedem impact dimensions.');}
        foreach((array)($document['verifications']??[])as$item){if(is_array($item)&&is_string($item['id']??null))$verifications[$item['id']]=true;}foreach((array)($document['recommendations']??[])as$item){if(is_array($item)&&is_string($item['id']??null))$recommendations[$item['id']]=true;}
        $checks=[['issue_observation_links','issue_id',$issues,'observation_id',$observations],['issue_evidence_links','issue_id',$issues,'evidence_id',$evidence],['verification_issue_links','verification_id',$verifications,'issue_id',$issues],['recommendation_issue_links','recommendation_id',$recommendations,'issue_id',$issues]];foreach($checks as $check){foreach((array)($document[$check[0]]??[])as$index=>$link){if(!is_array($link)||!isset($check[2][(string)($link[$check[1]]??'')])||!isset($check[4][(string)($link[$check[3]]??'')]))$this->error($errors,'$.'.$check[0].'['.$index.']','E_DANGLING_REFERENCE','Link odkazuje na neznámy objekt.');}}
        foreach(['approved_at','approved_by','published_at','pin','access_id','report_id']as$forbidden){if($this->containsKey($document,$forbidden))$this->error($errors,'$','E_FORBIDDEN_WORKFLOW_FIELD','Draft obsahuje nepovolené workflow pole: '.$forbidden);}
        return ['ok'=>$errors===[],'errors'=>$errors,'warnings'=>[]];
    }

    private function required(array $data,array $fields,string $base,array &$errors):void{foreach($fields as$field){if(!array_key_exists($field,$data))$this->error($errors,($base!==''?$base:'$').'.'.$field,'E_REQUIRED','Povinné pole chýba.');}}
    private function error(array &$errors,string $path,string $code,string $message):void{$errors[]=['path'=>$path,'code'=>$code,'message'=>$message];}
    private function containsKey($value,string $needle):bool{if(!is_array($value))return false;foreach($value as$key=>$child){if($key===$needle)return true;if(is_array($child)&&$this->containsKey($child,$needle))return true;}return false;}
}
