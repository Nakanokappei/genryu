<?php

namespace App\OpenAi;

/** Token usage of model calls and what it costs. */
class Usage
{
    /**
     * A call's cost in USD from services.openai.prices (per million tokens); null when unpriced.
     *
     * @param  array{input_tokens: ?int, cached_tokens: ?int, output_tokens: ?int}  $usage
     * @return array{estimated_input_cost: ?float, estimated_output_cost: ?float, estimated_total_cost: ?float}
     */
    public static function estimatedCost(string $model, array $usage): array
    {
        // By key, not dot path: model ids contain dots.
        $prices = ((array) config('services.openai.prices'))[$model] ?? null;

        // Unknown prices or usage: no estimate.
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
     * Several calls' usage added up per key; null where no call reported it.
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
     * Several calls' total cost in USD, priced call by call; null if any is unpriced.
     *
     * @param  list<array<string, ?int>>  $usages
     */
    public static function costOfCalls(string $model, array $usages): ?float
    {
        $total = null;

        // Price each call and add it up.
        foreach ($usages as $usage) {
            $cost = self::estimatedCost($model, ['input_tokens' => $usage['input_tokens'] ?? null, 'cached_tokens' => $usage['cached_tokens'] ?? null, 'output_tokens' => $usage['output_tokens'] ?? null])['estimated_total_cost'];

            // One unknown makes the total unknown.
            if ($cost === null) {
                return null;
            }

            $total = ($total ?? 0.0) + $cost;
        }

        return $total;
    }
}
