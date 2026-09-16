<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| The admin app (Educa_Admin) and the staff portal (Educa_Portal; dev port 5174)
| call this API from three kinds of origin: their
| hosted PWA, and the Capacitor shells, which serve the bundled app from
| capacitor://localhost (iOS) and https://localhost (Android). Auth is a bearer
| token, never a cookie, so credentials stay off.
|
| Origins are an explicit list rather than the framework's '*': a token
| stolen by a script on some other site is still a stolen token, but there is
| no reason to let arbitrary sites read responses in the first place.
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:5173,http://127.0.0.1:5173,http://localhost:4173,http://localhost:5174,http://127.0.0.1:5174,http://localhost:4174,capacitor://localhost,https://localhost'
    ))))),

    'allowed_origins_patterns' => [],

    // X-School is how a superadmin names the school they are acting on.
    'allowed_headers' => ['*'],

    // Content-Disposition: report card PDFs are fetched as blobs, and the client
    // can only read the server's filename if it is exposed. Retry-After: login
    // and password changes are throttled.
    'exposed_headers' => ['Content-Disposition', 'Retry-After'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
