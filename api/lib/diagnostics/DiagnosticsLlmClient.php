<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

require_once __DIR__ . '/DiagnosticsIngestException.php';
require_once __DIR__ . '/DiagnosticsIngestConfig.php';

final class DiagnosticsLlmClient
{
    /** @var DiagnosticsIngestConfig */ private $config;
    /** @var callable|null */ private $transport;

    public function __construct(DiagnosticsIngestConfig $config, ?callable $transport = null)
    {
        $this->config = $config;
        $this->transport = $transport;
    }

    /**
     * @param array<string, mixed> $inspection
     * @return array<string, mixed>
     */
    public function generateCandidate(array $inspection, string $ruleContext): array
    {
        $this->config->assertOpenAiConfigured();
        $payload = [
            'model' => $this->config->getLlmModel(),
            'store' => false,
            'input' => [
                ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $this->systemPrompt($ruleContext)]]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => json_encode($inspection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]],
            ],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'doktorhaus_diagnosis_candidate', 'strict' => true, 'schema' => $this->candidateSchema()]],
        ];
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $this->config->getOpenAiKey(), 'User-Agent: DoktorHaus-diagnostics/1.0'];
        $response = $this->transport
            ? call_user_func($this->transport, 'POST', 'https://api.openai.com/v1/responses', $headers, $payload)
            : $this->request($payload, $headers);
        $status = (int)($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new DiagnosticsIngestException('LLM_HTTP', 'Diagnostická LLM požiadavka zlyhala.', ['status' => $status]);
        }
        $body = $response['body'] ?? null;
        if (is_string($body)) { $body = json_decode($body, true); }
        if (!is_array($body)) { throw new DiagnosticsIngestException('LLM_RESPONSE', 'LLM odpoveď nemá platný tvar.'); }
        $text = $body['output_text'] ?? null;
        if (!is_string($text)) {
            foreach ((array)($body['output'] ?? []) as $output) {
                foreach ((array)($output['content'] ?? []) as $content) {
                    if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) { $text = $content['text']; break 2; }
                    if (($content['type'] ?? null) === 'refusal') { throw new DiagnosticsIngestException('LLM_REFUSAL', 'LLM odmietlo vytvoriť diagnostický draft.'); }
                }
            }
        }
        if (!is_string($text)) { throw new DiagnosticsIngestException('LLM_RESPONSE', 'LLM odpoveď neobsahuje structured output.'); }
        $candidate = json_decode($text, true);
        if (!is_array($candidate) || $this->isList($candidate)) { throw new DiagnosticsIngestException('LLM_MALFORMED', 'LLM vrátilo neplatný structured output.'); }
        $this->validateCandidate($candidate, $inspection);
        return $candidate;
    }

    /** @return array<string, mixed> */
    public function candidateSchema(): array
    {
        $string = ['type' => 'string'];
        $score = static function (array $values): array { return ['type' => 'string', 'enum' => $values]; };
        $risk = ['type' => 'object', 'additionalProperties' => false, 'required' => ['level', 'description', 'horizon'], 'properties' => ['level' => $score(['none','low','moderate','high','critical','unknown']), 'description' => $string, 'horizon' => $string]];
        $impact = ['type' => 'object', 'additionalProperties' => false, 'required' => ['level','description','confidence'], 'properties' => ['level' => $score(['none','low','moderate','high','critical','unknown']), 'description' => $string, 'confidence' => $score(['unknown','low','medium','high'])]];
        $hypothesis = ['type' => 'object', 'additionalProperties' => false, 'required' => ['statement','mechanism','confidence','rationale','missing_information'], 'properties' => ['statement'=>$string,'mechanism'=>$string,'confidence'=>$score(['unknown','low','medium','high']),'rationale'=>$string,'missing_information'=>['type'=>'array','items'=>$string]]];
        $issue = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['source_observation_ids','title','category','affected_areas','summary','interpretation','severity','severity_rationale','likelihood','likelihood_subject','likelihood_subject_kind','likelihood_rationale','urgency','urgency_rationale','priority','priority_rationale','confidence','deterioration_rate','deterioration_rationale','short_term_risk','long_term_risk','hypotheses','missing_information','limitations','impacts','cost_estimate_reason'],
            'properties' => [
                'source_observation_ids'=>['type'=>'array','minItems'=>1,'items'=>$string], 'title'=>$string,
                'category'=>$score(['drainage','moisture','structural','masonry','foundation','facade','roof','chimney_flue','electrical','heating','plumbing','sewerage','ventilation','indoor_climate','biological','windows_doors','site_exterior','documentation','fire_safety','other']),
                'affected_areas'=>['type'=>'array','minItems'=>1,'items'=>$string], 'summary'=>$string, 'interpretation'=>$string,
                'severity'=>$score(['S1','S2','S3','S4','S5']), 'severity_rationale'=>$string,
                'likelihood'=>$score(['L1','L2','L3','L4','L5']), 'likelihood_subject'=>$string, 'likelihood_subject_kind'=>$score(['observed_condition','future_event','hypothesized_mechanism']), 'likelihood_rationale'=>$string,
                'urgency'=>$score(['U1','U2','U3','U4','U5']), 'urgency_rationale'=>$string, 'priority'=>$score(['P1','P2','P3','P4','P5']), 'priority_rationale'=>$string,
                'confidence'=>$score(['unknown','low','medium','high']), 'deterioration_rate'=>$score(['stable','slow','progressive','rapid','unknown']), 'deterioration_rationale'=>$string,
                'short_term_risk'=>$risk, 'long_term_risk'=>$risk, 'hypotheses'=>['type'=>'array','items'=>$hypothesis],
                'missing_information'=>['type'=>'array','items'=>$string], 'limitations'=>['type'=>'array','items'=>$string],
                'impacts'=>['type'=>'object','additionalProperties'=>false,'required'=>['safety','structural','moisture','health','durability','usability','financial'],'properties'=>['safety'=>$impact,'structural'=>$impact,'moisture'=>$impact,'health'=>$impact,'durability'=>$impact,'usability'=>$impact,'financial'=>$impact]],
                'cost_estimate_reason'=>$string,
            ],
        ];
        $verification = ['type'=>'object','additionalProperties'=>false,'required'=>['source_observation_ids','method','purpose','verification_type','specialty'],'properties'=>['source_observation_ids'=>['type'=>'array','minItems'=>1,'items'=>$string],'method'=>$string,'purpose'=>$string,'verification_type'=>$score(['inspection','measurement','monitoring','destructive_probe','laboratory_test','specialist_assessment','document_review','other']),'specialty'=>$string]];
        $recommendation = ['type'=>'object','additionalProperties'=>false,'required'=>['source_observation_ids','type','title','description','rationale','target_timeframe','specialty','acceptance_or_follow_up','conditional'],'properties'=>['source_observation_ids'=>['type'=>'array','minItems'=>1,'items'=>$string],'type'=>$score(['IMMEDIATE','VERIFY','REPAIR','MONITOR','MAINTENANCE','DOCUMENT']),'title'=>$string,'description'=>$string,'rationale'=>$string,'target_timeframe'=>$string,'specialty'=>$string,'acceptance_or_follow_up'=>$string,'conditional'=>['type'=>'boolean']]];
        return ['type'=>'object','additionalProperties'=>false,'required'=>['issues','verifications','recommendations'],'properties'=>['issues'=>['type'=>'array','items'=>$issue],'verifications'=>['type'=>'array','items'=>$verification],'recommendations'=>['type'=>'array','items'=>$recommendation]]];
    }

    private function systemPrompt(string $ruleContext): string
    {
        return "Si analytická draft vrstva DoktorHaus. Vytvor iba diagnosis-candidate podľa strict schémy.\n"
            . "Zdrojové pozorovanie nie je diagnóza. Nepotvrdzuj príčinu bez dôkazu; oddeľ pozorovanie, interpretáciu a hypotézu. "
            . "Používaj unknown a missing information, zachovaj alternatívne hypotézy, nevytváraj falošnú presnosť ani alarmistický text. "
            . "S5, P1 a U1 použi iba s obhájiteľným rationale. Každý issue musí mať presne sedem impact dimensions. "
            . "Recommendations musia vychádzať z issues a verifications musia pomenovať čo treba potvrdiť. "
            . "Nevytváraj opaque ID, APPROVE, publish, PIN, report version, cenu ani interné tarifné dáta.\n\n"
            . "Normatívny kontext projektu:\n" . $ruleContext;
    }

    /** @return array<string, mixed> */
    private function request(array $payload, array $headers): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) { throw new DiagnosticsIngestException('LLM_REQUEST', 'LLM request sa nepodarilo serializovať.'); }
        $context = stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$json,'timeout'=>120,'ignore_errors'=>true,'follow_location'=>0],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $body = @file_get_contents('https://api.openai.com/v1/responses', false, $context);
        $headersOut = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
        $status = 0;
        foreach ($headersOut as $header) { if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) { $status=(int)$match[1]; } }
        if ($body === false) { throw new DiagnosticsIngestException('LLM_NETWORK', 'Diagnostická LLM služba nie je dostupná.'); }
        return ['status'=>$status,'headers'=>$headersOut,'body'=>$body];
    }

    private function validateCandidate(array $candidate, array $inspection): void
    {
        if (!isset($candidate['issues'],$candidate['verifications'],$candidate['recommendations']) || !is_array($candidate['issues']) || !is_array($candidate['verifications']) || !is_array($candidate['recommendations'])) {
            throw new DiagnosticsIngestException('LLM_SCHEMA', 'LLM candidate nemá povinné kolekcie.');
        }
        $known = [];
        foreach ((array)($inspection['observations'] ?? []) as $observation) { if (is_string($observation['id'] ?? null)) { $known[$observation['id']] = true; } }
        foreach (['issues','verifications','recommendations'] as $collection) {
            foreach ($candidate[$collection] as $item) {
                if (!is_array($item) || !is_array($item['source_observation_ids'] ?? null) || $item['source_observation_ids'] === []) { throw new DiagnosticsIngestException('LLM_SCHEMA', 'LLM candidate obsahuje neplatnú položku.'); }
                foreach ($item['source_observation_ids'] as $id) { if (!is_string($id) || !isset($known[$id])) { throw new DiagnosticsIngestException('LLM_DANGLING_REF', 'LLM candidate odkazuje na neznáme pozorovanie.'); } }
                foreach (['status','approved_at','approved_by','published_at','pin','access_id','report_id'] as $forbidden) { if (array_key_exists($forbidden,$item)) { throw new DiagnosticsIngestException('LLM_FORBIDDEN', 'LLM candidate obsahuje nepovolené workflow pole.'); } }
            }
        }
    }

    private function isList(array $value): bool { $index=0; foreach($value as $key=>$_){if($key!==$index++)return false;} return true; }
}
