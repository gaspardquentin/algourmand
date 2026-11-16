<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Algourmand settings
    |--------------------------------------------------------------------------
    |
    | token_expiration_days   Lifetime of a Sanctum access token, in days.
    | password_reset_url      App deep link opened from the password reset email.
    | metrics.token           Bearer token protecting GET /metrics (null disables access).
    | recipes.themealdb_base_url  Base URL of the TheMealDB JSON API used by the recipe import.
    | supported_locales       Locales the API can answer in; the first one is the fallback.
    | docs.enabled            Whether the Scramble API documentation is served outside local.
    |
    */

    'token_expiration_days' => (int) (env('TOKEN_EXPIRATION_DAYS') ?: 30),

    'password_reset_url' => env('PASSWORD_RESET_URL', 'algourmand://reset-password'),

    'metrics' => [
        'token' => env('METRICS_TOKEN') ?: null,
    ],

    'recipes' => [
        'themealdb_base_url' => env('THEMEALDB_BASE_URL', 'https://www.themealdb.com/api/json/v1/1'),
    ],

    'supported_locales' => ['en', 'fr'],

    'docs' => [
        'enabled' => (bool) env('API_DOCS_ENABLED', env('APP_ENV', 'production') !== 'production'),
    ],

];
