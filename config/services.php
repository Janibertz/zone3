<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'strava' => [
        'client_id'              => env('STRAVA_CLIENT_ID'),
        'client_secret'          => env('STRAVA_CLIENT_SECRET'),
        'redirect'               => env('STRAVA_REDIRECT_URI'),
        'webhook_callback_url'   => env('STRAVA_WEBHOOK_CALLBACK_URL', env('APP_URL') . '/strava/webhook'),
        'webhook_verify_token'   => env('STRAVA_WEBHOOK_VERIFY_TOKEN', 'zone3_webhook'),
    ],

    // Garmin Connect Developer Program (OAuth2 mit PKCE). Die Token-URL steht
    // hier und nicht im Code: Garmins PKCE-Spezifikation nennt diauth, ein
    // anderer Entwurf connectapi — umstellbar ohne Deploy von Code.
    'garmin' => [
        'client_id'     => env('GARMIN_CLIENT_ID'),
        'client_secret' => env('GARMIN_CLIENT_SECRET'),
        'redirect'      => env('GARMIN_REDIRECT_URI', env('APP_URL') . '/garmin/callback'),
        'authorize_url' => env('GARMIN_AUTHORIZE_URL', 'https://connect.garmin.com/oauth2Confirm'),
        'token_url'     => env('GARMIN_TOKEN_URL', 'https://diauth.garmin.com/di-oauth2-service/oauth/token'),
        'api_url'       => env('GARMIN_API_URL', 'https://apis.garmin.com'),
    ],

    // COROS ueber MCP (seit 05.11.2026). Keine Freigabe noetig: Zone3
    // registriert sich selbst als OAuth-Client (dynamische Registrierung).
    // EU-Endpunkt, weil die Athleten in Deutschland sind (Auskunft COROS).
    // `enabled` blendet die Verbindung im Profil ein — aus, bis sie mit
    // echten Konten geprueft ist.
    'coros' => [
        'enabled'   => (bool) env('COROS_ENABLED', false),
        'base_url'  => env('COROS_MCP_BASE_URL', 'https://mcpeu.coros.com'),
        'client_id' => env('COROS_CLIENT_ID'),
        'redirect'  => env('COROS_REDIRECT_URI', env('APP_URL') . '/coros/callback'),
    ],

    'openai' => [
        'api_key'    => env('OPENAI_API_KEY'),
        'model'      => env('OPENAI_MODEL',      'gpt-5.6-sol'),     // Plan
        'model_mid'  => env('OPENAI_MODEL_MID',  'gpt-5.6-terra'),   // Coach-Chat, Schwellenpace
        'model_mini' => env('OPENAI_MODEL_MINI', 'gpt-5.6-luna'),    // alles Kurze

        // Hintergrund-Aufrufe ueber Flex: halber Preis, langsamer.
        'flex'       => env('OPENAI_FLEX', true),

        // Tagesbudgets in Euro (0 = aus). Das Monatslimit steht zusaetzlich
        // hart auf der OpenAI-Plattform; diese hier fangen eine Schleife ab,
        // bevor sie den Monat aufbraucht.
        'budget' => [
            'daily_eur'      => (float) env('OPENAI_BUDGET_DAILY_EUR', 1.00),
            'user_daily_eur' => (float) env('OPENAI_BUDGET_USER_DAILY_EUR', 0.30),
        ],
    ],

    // Telegram-Bot fuer den Coach. Das Webhook-Geheimnis wird aus APP_KEY
    // abgeleitet, wenn es nicht gesetzt ist — eine Variable weniger, die in
    // Coolify fehlen kann.
    'telegram' => [
        'bot_token'      => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    // Push an die iOS-App (Apple Push Notification service). Schlüssel unter
    // developer.apple.com → Keys, Inhalt der .p8-Datei in APNS_KEY.
    'apns' => [
        'key_id'     => env('APNS_KEY_ID'),
        'team_id'    => env('APNS_TEAM_ID'),
        'key'        => env('APNS_KEY'),
        'bundle_id'  => env('APNS_BUNDLE_ID', 'run.zone3.app'),
        'production' => env('APNS_PRODUCTION', true),
    ],

    // „Mit Apple fortfahren“. In der iOS-App gilt die Bundle-ID, im Browser
    // die Services-ID (Developer-Konto → Identifiers → Services IDs). Ohne
    // Services-ID gibt es den Knopf nur in der App. Ein Schlüssel ist nicht
    // nötig: Zone3 prüft nur das id_token, es tauscht keinen Code.
    'apple_signin' => [
        'client_id' => env('APPLE_SIGNIN_CLIENT_ID'),
        'bundle_id' => env('APNS_BUNDLE_ID', 'run.zone3.app'),
    ],

    'webpush' => [
        'public_key'  => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject'     => env('VAPID_SUBJECT', 'mailto:admin@zone3.app'),
    ],

    'github' => [
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
    ],

];
