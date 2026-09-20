<?php

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP identity
    |--------------------------------------------------------------------------
    |
    | Every request the platform makes identifies itself honestly. Site
    | operators must be able to recognise and contact us (plan §0).
    |
    */

    'user_agent' => env('ACQUISITION_USER_AGENT', 'TechnologyWatch/0.1 (primary-source monitoring; +http://technologywatch.test)'),

    /*
    |--------------------------------------------------------------------------
    | Fetch defaults
    |--------------------------------------------------------------------------
    |
    | Used when a fetch request does not specify its own limits. Profiles
    | normally override these through crawl_policy (ADR-0005).
    |
    */

    'fetch' => [
        'timeout_seconds' => 20,
        'max_body_bytes' => 50 * 1024 * 1024,
        'max_redirects' => 5,
        'max_attempts' => 3,
        // Retry-After values above this are not honoured; the fetch fails instead (ADR-0004).
        'max_retry_after_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | robots.txt
    |--------------------------------------------------------------------------
    |
    | The product token matched against User-agent groups. Falls back to "*".
    |
    */

    'robots_token' => env('ACQUISITION_ROBOTS_TOKEN', 'TechnologyWatch'),

    /*
    |--------------------------------------------------------------------------
    | Runs
    |--------------------------------------------------------------------------
    |
    | run_timeout_seconds bounds one queued run. The circuit breaker (ADR-0004)
    | delays the next run of a source after a FAILED run, doubling the delay
    | per consecutive failure up to max_minutes, and marks the source DEGRADED
    | after degrade_after consecutive failures.
    |
    */

    'run_timeout_seconds' => (int) env('ACQUISITION_RUN_TIMEOUT', 3600),

    'circuit_breaker' => [
        'base_minutes' => 60,
        'max_minutes' => 24 * 60,
        'degrade_after' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent worker (ADR-0001)
    |--------------------------------------------------------------------------
    |
    | The Claude Agent SDK runs as a Python sidecar in worker/. Laravel starts
    | it with the Process facade; the worker calls back into
    | `php artisan acquisition:tool` for every Tool. Secrets stay in
    | worker/.env; the model can be overridden here for a whole deployment.
    |
    */

    'worker' => [
        'dir' => env('ACQUISITION_WORKER_DIR', base_path('worker')),
        'python' => env('ACQUISITION_WORKER_PYTHON', base_path('worker/.venv/bin/python')),
        'php' => env('ACQUISITION_WORKER_PHP', PHP_BINARY),
        'timeout_seconds' => (int) env('ACQUISITION_WORKER_TIMEOUT', 1800),
        'model' => env('TW_AGENT_MODEL', 'claude-opus-5'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tools exposed to the Agent (AT-14)
    |--------------------------------------------------------------------------
    |
    | The complete allowlist. The bridge refuses anything else, whatever the
    | worker asks for. Storage writes happen inside these tools, never directly.
    |
    */

    'agent_tools' => [
        'discover_web',
        'fetch_url',
        'parse_html',
        'parse_xml',
        'parse_pdf',
        'normalize_document',
        'store_source_profile_candidate',
    ],

    /*
    |--------------------------------------------------------------------------
    | Discovery budget defaults (plan §5.1)
    |--------------------------------------------------------------------------
    */

    'discovery' => [
        'max_depth' => 2,
        'max_urls' => 50,
        'max_seconds' => 120,
        'requests_per_minute' => 30,
        'max_tool_calls' => 40,
        'max_turns' => 30,
        'max_budget_usd' => 2.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring, Health and drift (plan §12, §13)
    |--------------------------------------------------------------------------
    |
    | baseline_runs: how many earlier observations form the median baseline.
    | quality_collapse_ratio: share of failed quality verdicts that counts as
    | evidence, once at least min_documents_for_ratio documents were assessed.
    | max_sitemap_children: cap on child sitemaps read per sitemap index.
    | self_healing.auto_discover: queue one Discovery run when a source enters
    | PARSER_DRIFT (it only creates a candidate; approval stays human).
    |
    */

    'monitoring' => [
        'baseline_runs' => 5,
        'quality_collapse_ratio' => 0.5,
        'min_documents_for_ratio' => 3,
        'max_sitemap_children' => 20,
    ],

    'self_healing' => [
        'auto_discover' => (bool) env('ACQUISITION_AUTO_DISCOVER', true),
    ],

];
