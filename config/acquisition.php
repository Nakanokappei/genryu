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

];
