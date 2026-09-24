<?php

namespace App\OpenAi;

/**
 * What a model call used and what it cost. Every agent reports its usage
 * in the same shape (input tokens, cached and cache-written ones apart,
 * output tokens, latency), and every job that keeps a run prices it the
 * same way, so both live here rather than in whichever job came first.
 */
class Usage
{
    /**
     * What the call cost, from the prices per million tokens configured
     * for the model: cached input tokens at the cached price, the rest of
     * the input at the input price, the output at the output price. Null
     * when the model's prices are not known.
     *
     * @param  array{input_tokens: ?int, cached_tokens: ?int, output_tokens: ?int}  $usage
     * @return array{estimated_input_cost: ?float, estimated_output_cost: ?float, estimated_total_cost: ?float}
     */
    public static function estimatedCost(string $model, array $usage): array
    {
        // Looked up by key, not by dot path: the model ids have dots in them (gpt-5.6-luna).
        $prices = ((array) config('services.openai.prices'))[$model] ?? null;

        if (! is_array($prices) || ! is_numeric($prices['input'] ?? null) || ! is_numeric($prices['output'] ?? null) || $usage['input_tokens'] === null || $usage['output_tokens'] === null) {
            return ['estimated_input_cost' => null, 'estimated_output_cost' => null, 'estimated_total_cost' => null];
        }

        $cached = $usage['cached_tokens'] ?? 0;
        $cachedPrice = is_numeric($prices['cached'] ?? null) ? (float) $prices['cached'] : (float) $prices['input'];
        $input = (($usage['input_tokens'] - $cached) * (float) $prices['input'] + $cached * $cachedPrice) / 1_000_000;
        $output = $usage['output_tokens'] * (float) $prices['output'] / 1_000_000;

        return ['estimated_input_cost' => $input, 'estimated_output_cost' => $output, 'estimated_total_cost' => $input + $output];
    }

    /**
     * The usage of several calls added up, key by key; a figure no call
     * reported stays unknown.
     *
     * @param  list<array<string, ?int>>  $usages
     * @return array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: ?int}
     */
    public static function sum(array $usages): array
    {
        $sum = function (string $key) use ($usages): ?int {
            $values = array_filter(array_column($usages, $key), fn ($value) => $value !== null);

            return $values === [] ? null : (int) array_sum($values);
        };

        return ['input_tokens' => $sum('input_tokens'), 'cached_tokens' => $sum('cached_tokens'), 'cache_write_tokens' => $sum('cache_write_tokens'), 'output_tokens' => $sum('output_tokens'), 'latency_ms' => $sum('latency_ms')];
    }

    /**
     * What several calls on one model cost in USD, priced call by call;
     * unknown when any call's is.
     *
     * @param  list<array<string, ?int>>  $usages
     */
    public static function costOfCalls(string $model, array $usages): ?float
    {
        $total = null;

        foreach ($usages as $usage) {
            $cost = self::estimatedCost($model, ['input_tokens' => $usage['input_tokens'] ?? null, 'cached_tokens' => $usage['cached_tokens'] ?? null, 'output_tokens' => $usage['output_tokens'] ?? null])['estimated_total_cost'];

            if ($cost === null) {
                return null;
            }

            $total = ($total ?? 0.0) + $cost;
        }

        return $total;
    }
}
