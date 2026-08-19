<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    */

    'ssr' => [

        'enabled' => (bool) env('INERTIA_SSR_ENABLED', true),

        'runtime' => env('INERTIA_SSR_RUNTIME', 'node'),

        'ensure_runtime_exists' => (bool) env('INERTIA_SSR_ENSURE_RUNTIME_EXISTS', false),

        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),

        'hot_url' => env('INERTIA_SSR_HOT_URL'),

        'ensure_bundle_exists' => (bool) env('INERTIA_SSR_ENSURE_BUNDLE_EXISTS', true),

        'throw_on_error' => (bool) env('INERTIA_SSR_THROW_ON_ERROR', false),

    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    */

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [

            resource_path('js/pages'),

        ],

        'extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    */

    'testing' => [

        'ensure_pages_exist' => true,

    ],

    'expose_shared_prop_keys' => true,

    /*
    |--------------------------------------------------------------------------
    | History
    |--------------------------------------------------------------------------
    |
    | Encrypt page data before it is stored in the browser's history state, so
    | nothing this app renders survives in history after logout. This is a
    | secrets manager: default on, everywhere.
    |
    */

    'history' => [

        'encrypt' => (bool) env('INERTIA_ENCRYPT_HISTORY', true),

    ],

    /*
    |--------------------------------------------------------------------------
    | DevTools - DISABLED, permanently, on purpose
    |--------------------------------------------------------------------------
    |
    | The Inertia DevTools recorder writes every request and response body to
    | storage/inertia-devtools/*.json and serves them back over an endpoint that
    | is UNAUTHENTICATED in the local environment. For an ordinary app that is a
    | convenience; for a secrets manager it is a plaintext leak: set-value
    | requests ({"value": ...}), PIN issuance ({"pin": ...}) and every reveal
    | response would land on disk in the clear and be readable by anyone who can
    | reach /_inertia/devtools/entries.
    |
    | It is hard-disabled here - not env-driven - so no .env slip can turn it
    | back on. Do not "temporarily" enable it. If you ever need request tracing,
    | use a tool that does not persist bodies (or one you can point away from the
    | reveal/value/pin routes).
    |
    */

    'devtools' => [

        'enabled' => false,

    ],

];
