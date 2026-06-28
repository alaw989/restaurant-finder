<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Exposed routes
    |--------------------------------------------------------------------------
    |
    | Only the named routes the frontend actually calls via Ziggy's `route()`
    | helper are exposed (audited via `grep -rn "route(" resources/js`).
    | Everything else navigates via literal `href="/..."` strings and doesn't
    | need Ziggy. This trims the inline `@routes` payload injected into every
    | page's `<head>` from all ~47 routes (~40 KB) down to these 13 (~12 KB)
    | — spec-061 (frontend bundle diet).
    |
    | If a new `route('name')` call is added, add its name here or it throws
    | at call-time.
    |
    */

    'only' => [
        'dashboard',
        'login',
        'logout',
        'password.confirm',
        'password.email',
        'password.request',
        'password.store',
        'password.update',
        'profile.destroy',
        'profile.edit',
        'profile.update',
        'register',
        'verification.send',
    ],

];
