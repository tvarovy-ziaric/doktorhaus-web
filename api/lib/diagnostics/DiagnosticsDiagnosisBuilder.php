<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

require_once __DIR__ . '/DiagnosticsIngestException.php';

final class DiagnosticsDiagnosisBuilder
{
    /** @var string */ private $namespace = 'doktorhaus:diagnostics:llm-candidate-builder:v1';

    /**
     * @param array<string, mixed> $inspection
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    public function build(array $inspection, array $candidate, string $generatedAt): array
    {
        $inspectionId = (string)($inspection['id'] ?? '');
        if (preg_match('/^insp_[0-9a-f]{16,32}$/D', $inspectionId) !== 1) {
            throw new DiagnosticsIngestException('BUILDER_INSPECTION', 'Canonical inspection ID nie je platné.');
        }
        $generatedAt = $this->timestamp($generatedAt);
        $actorId = $this->id('actor', $inspectionId, 'diagnostic-draft-system');
        $knownObservations = [];
        $evidenceByObservation = [];
        foreach ((array)($inspection['observations'] ?? []) as $observation) {
            if (is_string($observation['id'] ?? null)) { $knownObservations[$observation['id']] = $observation; }
        }
        foreach ((array)($inspection['observation_evidence_links'] ?? []) as $link) {
            if (is_string($link['observation_id'] ?? null) && is_string($link['evidence_id'] ?? null)) { $evidenceByObservation[$link['observation_id']][] = $link['evidence_id']; }
        }

        $issues=[]; $hypotheses=[]; $impacts=[]; $verifications=[]; $recommendations=[];
        $issueObservationLinks=[]; $issueEvidenceLinks=[]; $verificationIssueLinks=[]; $recommendationIssueLinks=[];
        $issueByObservation=[];
        foreach ((array)($candidate['issues'] ?? []) as $index => $source) {
            if (!is_array($source)) { throw new DiagnosticsIngestException('BUILDER_CANDIDATE', 'Diagnosis candidate issue nie je platný.'); }
            $observationIds = $this->knownRefs((array)($source['source_observation_ids'] ?? []), $knownObservations);
            if ($observationIds === []) { throw new DiagnosticsIngestException('BUILDER_DANGLING_REF', 'Diagnosis candidate issue nemá platné source observations.'); }
            $issueId = $this->id('issue', $inspectionId, 'issue|' . $index . '|' . implode('|',$observationIds));
            $missing=[]; $missingIds=[];
            foreach ((array)($source['missing_information'] ?? []) as $missingIndex => $statement) {
                if (!is_string($statement) || trim($statement)==='') { continue; }
                $missingId=$this->id('rel',$inspectionId,'missing|' . $issueId . '|' . $missingIndex);
                $missingIds[]=$missingId;
                $missing[]=['id'=>$missingId,'statement'=>trim($statement),'why_it_matters'=>'Informácia môže zmeniť confidence, rozsah overenia alebo odporúčaný krok.','how_to_obtain'=>'Doplniť cieleným odborným overením alebo dokumentáciou.','blocking'=>false];
            }
            $issue = [
                'id'=>$issueId,'display_code'=>sprintf('DI-%03d',$index+1),'title'=>$this->text($source,'title','Diagnostický problém'),
                'category'=>$this->enum((string)($source['category']??''),['drainage','moisture','structural','masonry','foundation','facade','roof','chimney_flue','electrical','heating','plumbing','sewerage','ventilation','indoor_climate','biological','windows_doors','site_exterior','documentation','fire_safety','other'],'other'),
                'affected_areas'=>array_map(static function($area):array{return ['area_type'=>'whole_building','label'=>trim((string)$area)!==''?trim((string)$area):'Dotknutá oblasť'];},array_values(array_filter((array)($source['affected_areas']??[]),'is_string'))),
                'summary'=>$this->text($source,'summary','Diagnostický draft vyžaduje ľudskú kontrolu.'),'interpretation'=>$this->text($source,'interpretation','Odborná interpretácia zostáva draftom.'),
                'severity'=>$this->enum((string)($source['severity']??''),['S1','S2','S3','S4','S5'],'S2'),'severity_rationale'=>$this->text($source,'severity_rationale','Závažnosť vyžaduje kontrolu inšpektora.'),
                'likelihood'=>$this->enum((string)($source['likelihood']??''),['L1','L2','L3','L4','L5'],'L2'),'likelihood_subject'=>$this->text($source,'likelihood_subject','Hodnotený jav uvedený v diagnostickom drafte.'),'likelihood_subject_kind'=>$this->enum((string)($source['likelihood_subject_kind']??''),['observed_condition','future_event','hypothesized_mechanism'],'observed_condition'),'likelihood_rationale'=>$this->text($source,'likelihood_rationale','Likelihood vyžaduje kontrolu inšpektora.'),
                'urgency'=>$this->enum((string)($source['urgency']??''),['U1','U2','U3','U4','U5'],'U3'),'urgency_rationale'=>$this->text($source,'urgency_rationale','Termín vyžaduje kontrolu inšpektora.'),
                'priority'=>$this->enum((string)($source['priority']??''),['P1','P2','P3','P4','P5'],'P3'),'priority_rationale'=>$this->text($source,'priority_rationale','Priorita vyžaduje kontrolu inšpektora.'),
                'confidence'=>$this->enum((string)($source['confidence']??''),['unknown','low','medium','high'],'low'),
                'deterioration_rate'=>$this->enum((string)($source['deterioration_rate']??''),['stable','slow','progressive','rapid','unknown'],'unknown'),'deterioration_rationale'=>$this->text($source,'deterioration_rationale','Bez časového porovnania zostáva tempo zmeny neznáme.'),
                'short_term_risk'=>$this->risk((array)($source['short_term_risk']??[]),'do najbližšieho overenia, najviac 12 mesiacov'),
                'long_term_risk'=>$this->risk((array)($source['long_term_risk']??[]),'viac než 12 mesiacov'),
                'cost_estimate'=>['status'=>'not_estimated','reason'=>$this->text($source,'cost_estimate_reason','Rozsah a cena neboli v zdrojových podkladoch určené.')],
                'cost_escalation'=>['level'=>'unknown','mechanism'=>'Rozsah prípadnej eskalácie nemožno bez ďalšieho overenia určiť.','trigger'=>'Nové prejavy, zmena stavu alebo odklad potrebného overenia.','preventive_step'=>'Vykonať odporúčané overenie pred definitívnym zásahom.','confidence'=>'low','rationale'=>'Automatický draft nemá podklad pre cenovú kvantifikáciu.'],
                'status'=>'active','missing_information'=>$missing,'limitations'=>array_values(array_filter(array_map('strval',(array)($source['limitations']??[])),static function(string $v):bool{return trim($v)!=='';})),
            ];
            if ($issue['affected_areas']===[]) { $issue['affected_areas']=[['area_type'=>'whole_building','label'=>'Dotknutá oblasť']]; }
            if ($issue['limitations']===[]) { $issue['limitations']=['Automaticky vytvorený diagnostický draft nebol odborne schválený.']; }
            $issues[]=$issue;
            foreach ($observationIds as $observationId) {
                $issueByObservation[$observationId][]=$issueId;
                $issueObservationLinks[]=$this->link('issue-observation|'.$issueId.'|'.$observationId,['issue_id'=>$issueId,'observation_id'=>$observationId,'role'=>'primary','rationale'=>'Zdrojové pozorovanie je explicitným vstupom diagnostického draftu.'],$inspectionId,$actorId,$generatedAt);
                foreach (array_unique((array)($evidenceByObservation[$observationId]??[])) as $evidenceId) {
                    $key=$issueId.'|'.$evidenceId;
                    if (isset($issueEvidenceLinks[$key])) continue;
                    $issueEvidenceLinks[$key]=$this->link('issue-evidence|'.$key,['issue_id'=>$issueId,'evidence_id'=>$evidenceId,'role'=>'supporting','rationale'=>'Evidence je v source vrstve naviazané na použité pozorovanie.'],$inspectionId,$actorId,$generatedAt);
                }
            }
            $hypothesisIds=[];
            foreach ((array)($source['hypotheses']??[]) as $hypothesisIndex=>$hypothesisSource) {
                if(!is_array($hypothesisSource))continue;
                $hypothesisId=$this->id('hyp',$inspectionId,'hypothesis|'.$issueId.'|'.$hypothesisIndex); $hypothesisIds[]=$hypothesisId;
                $hypotheses[]=['id'=>$hypothesisId,'display_code'=>sprintf('H-%03d',count($hypotheses)+1),'diagnostic_issue_id'=>$issueId,'statement'=>$this->text($hypothesisSource,'statement','Možné vysvetlenie vyžaduje overenie.'),'mechanism'=>$this->text($hypothesisSource,'mechanism','Mechanizmus nebol potvrdený.'),'confidence'=>$this->enum((string)($hypothesisSource['confidence']??''),['unknown','low','medium','high'],'low'),'status'=>'proposed','rationale'=>$this->text($hypothesisSource,'rationale','Hypotéza vychádza zo zdrojových pozorovaní a vyžaduje QA.'),'missing_information_ids'=>$missingIds];
            }
            foreach ($missing as &$missingItem) { $missingItem['related_hypothesis_ids']=$hypothesisIds; }
            unset($missingItem);
            $issues[count($issues)-1]['missing_information']=$missing;
            $impactSource=(array)($source['impacts']??[]);
            foreach(['safety','structural','moisture','health','durability','usability','financial'] as $dimensionIndex=>$dimension) {
                $value=(array)($impactSource[$dimension]??[]);
                $impacts[]=['id'=>$this->id('imp',$inspectionId,'impact|'.$issueId.'|'.$dimension),'diagnostic_issue_id'=>$issueId,'dimension'=>$dimension,'level'=>$this->enum((string)($value['level']??''),['none','low','moderate','high','critical','unknown'],'unknown'),'description'=>$this->text($value,'description','Dopad vyžaduje odbornú kontrolu.'),'time_horizon'=>'both','confidence'=>$this->enum((string)($value['confidence']??''),['unknown','low','medium','high'],'low'),'rationale'=>$this->text($value,'description','Dopad vyžaduje odbornú kontrolu.'),'supporting_observation_ids'=>$observationIds];
            }
        }
        if($issues===[]) throw new DiagnosticsIngestException('BUILDER_EMPTY','LLM candidate neobsahuje žiadny diagnostický issue.');

        foreach((array)($candidate['verifications']??[]) as $index=>$source){if(!is_array($source))continue;$obs=$this->knownRefs((array)($source['source_observation_ids']??[]),$knownObservations);$linked=$this->issuesFor($obs,$issueByObservation);if($linked===[])throw new DiagnosticsIngestException('BUILDER_DANGLING_REF','Verification nie je naviazané na issue.');$id=$this->id('ver',$inspectionId,'verification|'.$index.'|'.implode('|',$obs));$verifications[]=['id'=>$id,'display_code'=>sprintf('V-%03d',count($verifications)+1),'verification_type'=>$this->enum((string)($source['verification_type']??''),['inspection','measurement','monitoring','destructive_probe','laboratory_test','specialist_assessment','document_review','other'],'inspection'),'method'=>$this->text($source,'method','Cielené odborné overenie.'),'purpose'=>$this->text($source,'purpose','Spresniť diagnostický záver.'),'status'=>'proposed','limitations'=>['Rozsah overenia určí kvalifikovaný odborník.'],'specialist_required'=>true,'responsible_specialty'=>$this->specialty((string)($source['specialty']??''))];foreach($linked as $issueId){$verificationIssueLinks[]=$this->link('verification-issue|'.$id.'|'.$issueId,['verification_id'=>$id,'issue_id'=>$issueId,'role'=>'verifies','rationale'=>'Overenie spresňuje tento diagnostický issue.'],$inspectionId,$actorId,$generatedAt);}}
        foreach((array)($candidate['recommendations']??[]) as $index=>$source){if(!is_array($source))continue;$obs=$this->knownRefs((array)($source['source_observation_ids']??[]),$knownObservations);$linked=$this->issuesFor($obs,$issueByObservation);if($linked===[])throw new DiagnosticsIngestException('BUILDER_DANGLING_REF','Recommendation nie je naviazané na issue.');$id=$this->id('rec',$inspectionId,'recommendation|'.$index.'|'.implode('|',$obs));$type=$this->enum((string)($source['type']??''),['IMMEDIATE','VERIFY','REPAIR','MONITOR','MAINTENANCE','DOCUMENT'],'VERIFY');$recommendations[]=['id'=>$id,'display_code'=>sprintf('R-%03d',count($recommendations)+1),'type'=>$type,'title'=>$this->text($source,'title','Odporúčaný krok'),'description'=>$this->text($source,'description','Rozsah kroku vyžaduje odbornú kontrolu.'),'rationale'=>$this->text($source,'rationale','Krok vychádza z diagnostického draftu.'),'status'=>'proposed','target_timeframe'=>['urgency'=>'U3','recommended_by'=>(new \DateTimeImmutable($generatedAt))->format('Y-m-d'),'text'=>$this->text($source,'target_timeframe','Termín určí inšpektor pri QA.')],'responsible_specialty'=>$this->specialty((string)($source['specialty']??'')),'acceptance_or_follow_up'=>$this->text($source,'acceptance_or_follow_up','Výsledok zdokumentovať a odborne skontrolovať.'),'conditional'=>(bool)($source['conditional']??true)];foreach($linked as $issueId){$recommendationIssueLinks[]=$this->link('recommendation-issue|'.$id.'|'.$issueId,['recommendation_id'=>$id,'issue_id'=>$issueId,'role'=>$type==='MONITOR'?'monitors':($type==='DOCUMENT'?'documents':($type==='IMMEDIATE'?'mitigates':'addresses')),'rationale'=>'Odporúčanie je explicitne naviazané na tento issue.'],$inspectionId,$actorId,$generatedAt);}}

        return ['schema_version'=>'1.0.0','document_type'=>'diagnosis','id'=>$inspectionId,'inspection_id'=>$inspectionId,'status'=>'draft','actors'=>[['id'=>$actorId,'display_name'=>'DoktorHaus AI diagnostic draft','role'=>'system']], 'issues'=>$issues,'hypotheses'=>$hypotheses,'impacts'=>$impacts,'verifications'=>$verifications,'recommendations'=>$recommendations,'issue_observation_links'=>$issueObservationLinks,'issue_evidence_links'=>array_values($issueEvidenceLinks),'hypothesis_evidence_links'=>[],'verification_issue_links'=>$verificationIssueLinks,'verification_hypothesis_links'=>[],'verification_evidence_links'=>[],'recommendation_issue_links'=>$recommendationIssueLinks,'recommendation_hypothesis_links'=>[],'recommendation_dependencies'=>[],'issue_relations'=>[],'qa'=>['status'=>'not_checked','errors_acknowledged'=>[],'warnings_acknowledged'=>[],'notes'=>'Automaticky vytvorený shadow draft. Vyžaduje ľudskú QA kontrolu; nie je APPROVE ani publish.'],'created_at'=>$generatedAt,'updated_at'=>$generatedAt];
    }

    private function link(string $key,array $fields,string $inspectionId,string $actorId,string $at):array{return array_merge(['id'=>$this->id('rel',$inspectionId,$key)],$fields,['created_at'=>$at,'created_by'=>$actorId,'status'=>'active']);}
    private function risk(array $source,string $fallbackHorizon):array{return ['level'=>$this->enum((string)($source['level']??''),['none','low','moderate','high','critical','unknown'],'unknown'),'description'=>$this->text($source,'description','Riziko vyžaduje odbornú kontrolu.'),'horizon'=>$this->text($source,'horizon',$fallbackHorizon)];}
    private function specialty(string $value):array{$allowed=['building_inspector','structural_engineer','roofer','chimney_specialist','electrician','heating_technician','plumber','waterproofing_specialist','geotechnical_specialist','laboratory','surveyor','designer'];$normalized=strtolower(trim($value));return in_array($normalized,$allowed,true)?['specialty'=>$normalized]:['specialty'=>'other','label'=>$value!==''?substr($value,0,120):'Odbornosť určí inšpektor'];}
    private function knownRefs(array $refs,array $known):array{$result=[];foreach($refs as $ref){if(!is_string($ref)||!isset($known[$ref]))throw new DiagnosticsIngestException('BUILDER_DANGLING_REF','Diagnosis candidate odkazuje na neznáme pozorovanie.');$result[$ref]=true;}return array_keys($result);}
    private function issuesFor(array $observations,array $map):array{$result=[];foreach($observations as $id){foreach((array)($map[$id]??[]) as $issue){$result[$issue]=true;}}return array_keys($result);}
    private function text(array $source,string $key,string $fallback):string{$value=trim((string)($source[$key]??''));return $value!==''?$value:$fallback;}
    private function enum(string $value,array $allowed,string $fallback):string{return in_array($value,$allowed,true)?$value:$fallback;}
    private function id(string $prefix,string $inspectionId,string $key):string{return $prefix.'_'.substr(hash('sha256',$this->namespace.'|'.$inspectionId.'|'.$key),0,24);}
    private function timestamp(string $value):string{try{return(new \DateTimeImmutable($value))->format(DATE_ATOM);}catch(\Throwable $e){throw new DiagnosticsIngestException('BUILDER_TIME','Neplatný deterministic generation timestamp.');}}
}
