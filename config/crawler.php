<?php

return [

    /*
    |--------------------------------------------------------------------------
    | robots.txt enforcement
    |--------------------------------------------------------------------------
    |
    | Every outgoing HTTP request passes a global request middleware
    | (AppServiceProvider) that refuses URLs the host's robots.txt does not
    | allow. API endpoints we call as a client, not as a crawler, are exempt.
    |
    */

    'robots_exempt_hosts' => [
        'api.openai.com',
    ],

];
