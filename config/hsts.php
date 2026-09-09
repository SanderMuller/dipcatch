<?php declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Strict-Transport-Security
    |--------------------------------------------------------------------------
    |
    | Read by zae/strict-transport-security. The package registers no service
    | provider and publishes no config, so this file is hand-written and its
    | own fallbacks are weaker than these values: without the file the header
    | ships as a bare max-age, with no subdomain coverage and no preload.
    |
    | `preload` only enrols the domain once it is submitted at
    | hstspreload.org, but `includeSubdomains` binds every subdomain to HTTPS
    | for a year as soon as a browser sees the header.
    |
    */

    'max-age' => 31536000,
    'includeSubdomains' => true,
    'preload' => true,

];
