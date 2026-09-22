<?php

namespace App\Actions;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The agent behind 素材情報 (UI: "Materials", stage 2.3 of docs/HANDOVER.md):
 * given the structuring layer of the editorial policy (the developer
 * prompt of the Editorial Research Analyst) and a document's Markdown,
 * a model builds the material in two passes on the Responses API, the
 * prompt cached as one block with an explicit breakpoint and the phase
 * named after it. extract reads the whole document and fixes the
 * evidence: quotes with their line ranges, the claims that rest on them,
 * the facets of what happened. finalize gets that evidence back and
 * builds the analysis on it alone: the technology transition, the
 * engineering, the tensions and the possible angles. Each pass is
 * constrained to its JSON schema; App\Actions\ValidateMaterial then
 * checks what a schema cannot (quotes in the text, references, cycles),
 * and a pass that fails it is repaired once with the errors in hand.
 * The usage of every call comes back with the JSON. It only proposes;
 * App\Jobs\ExtractMaterial keeps the material.
 */
class ProposeMaterial
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    private const MAX_MARKDOWN_CHARS = 120000;

    public const SCHEMA_VERSION = 'A.1';

    public const STATES = ['impossible', 'extremely_hard', 'technically_feasible', 'economically_plausible', 'industrializable', 'competitive', 'diffusion', 'unknown'];

    public const ENTRY_POINTS = ['frontier', 'money', 'factory', 'loser', 'bottleneck', 'race', 'everyday', 'contrarian', 'paradox', 'number'];

    public const LENSES = ['frontier', 'capability', 'mechanism', 'bottleneck', 'money', 'factory', 'race', 'displacement', 'everyday', 'paradox'];

    public const AXES = ['before_after', 'lab_world', 'performance_manufacturability', 'possible_affordable', 'expert_software', 'incumbent_challenger', 'benefit_cost', 'expectation_reality', 'other'];

    /** What each phase is told, after the cached prompt. */
    private const PHASES = [
        'extract' => 'Phase: extract. Read the whole document below (its lines are numbered "N| " for your line references; the numbers are not part of the text) and return the primary evidence: spans quoted exactly as written with their line range, the primary_evidence claims resting on them, and the facets. No analysis yet.',
        'finalize' => 'Phase: finalize. Below are the evidence claims and spans fixed in the extract phase, then the document. Build the analysis on those claims alone: inference claims (new ids, never reusing an evidence id, basis = existing claim ids), the technology transition, the engineering facets, the tensions and the possible angles. Do not restate or add primary evidence. An angle\'s why_now_primary_claim_ids and supporting_primary_claim_ids take evidence claim ids only, the ones given below; its angle_claim_id and counterpoint_claim_ids take inference ids, and an angle needs at least one tension and one lens. Every facet marked supported names at least one claim.',
        'repair' => 'The previous answer for this phase failed these checks; return the corrected answer for the same phase, fixing every item listed and changing nothing else.',
    ];

    /**
     * One pass: the JSON the phase asked for, with the usage of the call.
     *
     * @param  array<string, mixed>|null  $evidence  the extract phase's answer, for finalize
     * @param  list<string>  $errors  what the previous answer for this phase got wrong, for a repair
     * @return array{json: array<string, mixed>, usage: array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $prompt, string $model, string $phase, string $markdown, ?array $evidence = null, array $errors = []): array
    {
        $key = (string) config('services.openai.key');

        if ($key === '') {
            throw new RuntimeException(__('OPENAI_API_KEY is not set.'));
        }

        $started = hrtime(true);

        $response = Http::withToken($key)
            ->timeout(300)
            ->post(self::ENDPOINT, self::request($prompt, $model, $phase, $markdown, $evidence, $errors))
            ->throw();

        $latency = (int) round((hrtime(true) - $started) / 1_000_000);
        $json = json_decode(self::outputText($response->json()), true);

        if (! is_array($json)) {
            throw new RuntimeException(__('The agent did not return valid JSON.'));
        }

        return [
            'json' => $json,
            'usage' => [
                'input_tokens' => self::count($response->json('usage.input_tokens')),
                'cached_tokens' => self::count($response->json('usage.input_tokens_details.cached_tokens')),
                'cache_write_tokens' => self::count($response->json('usage.input_tokens_details.cache_write_tokens')),
                'output_tokens' => self::count($response->json('usage.output_tokens')),
                'latency_ms' => $latency,
            ],
        ];
    }

    /**
     * The request of a phase: the fixed prompt first, with the cache
     * breakpoint; the phase, the errors to repair and the evidence after
     * it; the document, its lines numbered, last; the answer constrained
     * to the phase's schema.
     *
     * @param  array<string, mixed>|null  $evidence
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    public static function request(string $prompt, string $model, string $phase, string $markdown, ?array $evidence = null, array $errors = []): array
    {
        $instructions = [self::PHASES[$phase]];

        if ($errors !== []) {
            $instructions[] = self::PHASES['repair']."\n- ".implode("\n- ", $errors);
        }

        $user = ($evidence !== null ? "Evidence (extract phase):\n".json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n" : '')
            ."Document:\n".self::numbered(mb_substr($markdown, 0, self::MAX_MARKDOWN_CHARS));

        return [
            'model' => $model,
            'prompt_cache_options' => ['mode' => 'explicit'],
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        ['type' => 'input_text', 'text' => $prompt, 'prompt_cache_breakpoint' => ['mode' => 'explicit']],
                    ],
                ],
                ['role' => 'developer', 'content' => implode("\n\n", $instructions)],
                ['role' => 'user', 'content' => $user],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => "material_{$phase}",
                    'strict' => true,
                    'schema' => $phase === 'extract' ? self::extractSchema() : self::finalizeSchema(),
                ],
            ],
        ];
    }

    /**
     * The document with its lines numbered, so the model can point at them.
     */
    public static function numbered(string $markdown): string
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];

        return implode("\n", array_map(fn (int $index, string $line): string => ($index + 1).'| '.$line, array_keys($lines), $lines));
    }

    /**
     * The schema of the extract phase: spans, primary claims, the facets of what happened.
     *
     * @return array<string, mixed>
     */
    public static function extractSchema(): array
    {
        return self::object([
            'source_language' => self::string(),
            'primary_spans' => self::list(self::object([
                'id' => self::string(),
                'line_start' => ['type' => 'integer'],
                'line_end' => ['type' => 'integer'],
                'quote' => self::string(),
            ])),
            'claims' => self::list(self::claim(['primary_evidence'], ['reported', 'observed_in_source', 'proposed'])),
            'primary_evidence' => self::object([
                'what_happened' => self::facet(),
                'key_facts' => self::facet(),
                'reported_claims' => self::facet(),
                'numbers' => self::facet(),
                'actors' => self::facet(),
            ]),
        ]);
    }

    /**
     * The schema of the finalize phase: inference claims and the analysis resting on the evidence.
     *
     * @return array<string, mixed>
     */
    public static function finalizeSchema(): array
    {
        return self::object([
            'claims' => self::list(self::claim(['inference'], ['inferred', 'proposed', 'unknown', 'insufficient_evidence'])),
            'technology_transition' => self::object([
                'scope' => self::string(),
                'previous_state' => self::enum(self::STATES),
                'current_state' => self::enum(self::STATES),
                'assessment' => self::enum(['observed_transition', 'no_observed_transition', 'insufficient_evidence']),
                'assessment_claim_ids' => self::list(self::string()),
                'frontier_transition' => self::facet(),
            ]),
            'engineering' => self::object([
                'capability' => self::facet(),
                'mechanism' => self::facet(),
                'engineering_attack' => self::facet(),
                'capital_commitment' => self::facet(),
                'bottleneck' => self::facet(),
                'industrialization' => self::facet(),
            ]),
            'editorial' => self::object([
                'why_it_matters' => self::facet(),
                'tensions' => self::list(self::object([
                    'id' => self::string(),
                    'axis' => self::enum(self::AXES),
                    'left_claim_ids' => self::list(self::string()),
                    'right_claim_ids' => self::list(self::string()),
                    'relationship_claim_ids' => self::list(self::string()),
                    'status' => self::enum(['observed', 'proposed', 'hypothesis', 'insufficient_evidence']),
                ])),
                'possible_angles' => self::list(self::object([
                    'id' => self::string(),
                    'angle' => self::string(),
                    'entry_point' => self::enum(self::ENTRY_POINTS),
                    'lenses' => self::list(self::enum(self::LENSES)),
                    'tension_ids' => self::list(self::string()),
                    'angle_claim_id' => self::string(),
                    'why_now_primary_claim_ids' => self::list(self::string()),
                    'supporting_primary_claim_ids' => self::list(self::string()),
                    'counterpoint_claim_ids' => self::list(self::string()),
                    'missing_information_ids' => self::list(self::string()),
                    'reader_question' => self::string(),
                    'strength' => self::enum(['high', 'medium', 'low']),
                    'decision' => self::enum(['candidate', 'hold', 'reject']),
                    'decision_reason' => self::string(),
                ])),
                'recommended_angle_id' => ['type' => ['string', 'null']],
                'recommendation_reason' => self::string(),
                'missing_information' => self::list(self::object([
                    'id' => self::string(),
                    'question' => self::string(),
                    'why_it_matters' => self::string(),
                    'related_claim_ids' => self::list(self::string()),
                    'reason' => self::enum(['not_in_primary', 'ambiguous', 'conflicting_evidence', 'other']),
                ])),
                'next_signals' => self::list(self::object([
                    'id' => self::string(),
                    'signal' => self::string(),
                    'observable_criterion' => self::string(),
                    'required_primary_evidence' => self::string(),
                    'related_claim_ids' => self::list(self::string()),
                    'target_state' => self::enum(self::STATES),
                ])),
                'reader_questions' => self::list(self::object([
                    'id' => self::string(),
                    'question' => self::string(),
                    'answer_claim_ids' => self::list(self::string()),
                    'status' => self::enum(['answered', 'partial', 'unanswered']),
                ])),
            ]),
            'quality' => self::object([
                'warnings' => self::list(self::object([
                    'code' => self::string(),
                    'message' => self::string(),
                    'related_ids' => self::list(self::string()),
                ])),
            ]),
        ]);
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>  $statuses
     * @return array<string, mixed>
     */
    private static function claim(array $types, array $statuses): array
    {
        return self::object([
            'id' => self::string(),
            'type' => self::enum($types),
            'statement' => self::string(),
            'epistemic_status' => self::enum($statuses),
            'basis' => self::list(self::string()),
            'confidence' => self::enum(['high', 'medium', 'low', 'unknown']),
            'confidence_reason' => self::string(),
            'limitations' => self::list(self::string()),
        ]);
    }

    /** @return array<string, mixed> */
    private static function facet(): array
    {
        return self::object(['status' => self::enum(['supported', 'unknown', 'insufficient_evidence', 'not_applicable']), 'claim_ids' => self::list(self::string())]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    private static function list(array $items): array
    {
        return ['type' => 'array', 'items' => $items];
    }

    /** @return array<string, mixed> */
    private static function string(): array
    {
        return ['type' => 'string'];
    }

    /**
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    private static function enum(array $values): array
    {
        return ['type' => 'string', 'enum' => $values];
    }

    /**
     * The text of the answer: the output_text of the first message in
     * the output (reasoning items and the like are passed over).
     *
     * @param  array<string, mixed>|null  $body
     */
    private static function outputText(?array $body): string
    {
        foreach ($body['output'] ?? [] as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? '') === 'output_text') {
                    return (string) $content['text'];
                }
            }
        }

        return (string) ($body['output_text'] ?? '');
    }

    private static function count(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
