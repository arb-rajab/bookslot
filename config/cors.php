<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // D-0029: Sanctum SPA cookie auth requires the browser to receive and
    // send the session/XSRF cookies cross-origin (the decoupled Nuxt
    // frontend, 03-architecture.md, is a different origin from this API in
    // both dev and production) — that only works with an explicit origin
    // allowlist and supports_credentials true. A wildcard '*' origin is
    // both rejected by browsers when credentials are involved and would
    // defeat the whole point of an origin allowlist.
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', env('FRONTEND_URLS', 'http://localhost:3000,http://127.0.0.1:3000'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
