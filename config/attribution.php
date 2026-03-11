<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Attribution Table
    |--------------------------------------------------------------------------
    |
    | The database table where attribution records are stored.
    | One row per user — existing records are never overwritten.
    |
    */
    'table' => env('ATTRIBUTION_TABLE', 'attribution_records'),

    /*
    |--------------------------------------------------------------------------
    | localStorage Key
    |--------------------------------------------------------------------------
    |
    | The key used by the JavaScript snippet to store attribution data in
    | the visitor's localStorage. Must match the key read in your frontend
    | signup flow.
    |
    */
    'storage_key' => env('ATTRIBUTION_STORAGE_KEY', 'wc_attribution'),

    /*
    |--------------------------------------------------------------------------
    | Route Path
    |--------------------------------------------------------------------------
    |
    | The path segment used for the package's two endpoints:
    |   POST {prefix}/{path}             → save initial/last touch
    |   POST {prefix}/{path}/converting  → save converting touch
    |
    | Override if the default conflicts with an existing route in your app.
    |
    */
    'route_path' => env('ATTRIBUTION_ROUTE_PATH', 'touchpoint'),

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    |
    | Controls where async import jobs are dispatched. Set these in your
    | .env rather than editing this file.
    |
    | ATTRIBUTION_QUEUE_CONNECTION  queue connection (null = app default)
    | ATTRIBUTION_QUEUE             queue name (default = 'default')
    |
    | Works with Horizon out of the box: Horizon watches queue names, so
    | pointing ATTRIBUTION_QUEUE at a Horizon-supervised queue is enough.
    |
    */
    'queue' => [
        'connection' => env('ATTRIBUTION_QUEUE_CONNECTION', null),
        'name' => env('ATTRIBUTION_QUEUE', 'default'),
    ],
];
