<?php

$frontendUrl = trim((string) env('FRONTEND_URL', ''));

if ($frontendUrl === '*') {
    $frontendUrl = '';
}

$frontendUrl = rtrim($frontendUrl, '/');
$originLokal = in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)
    ? [
        'http://localhost:3000',
        'http://localhost:5173',
        'http://127.0.0.1:5500',
    ]
    : [];

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

    'paths' => ['backend-api', 'backend-api/*', 'api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Wildcard tidak boleh dipakai karena supports_credentials bernilai true.
    'allowed_origins' => array_values(array_unique(array_filter([
        $frontendUrl,
        'https://siamagdishub.vercel.app',
        ...$originLokal,
    ]))),

    // Hanya origin produksi yang diizinkan; preview *.vercel.app tidak diaktifkan.
    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => ['Authorization', 'Content-Disposition'],

    'max_age' => 86400,

    'supports_credentials' => true,

];
