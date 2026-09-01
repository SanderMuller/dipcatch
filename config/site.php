<?php declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Marketing site
    |--------------------------------------------------------------------------
    |
    | Copy and contact details for the public pages (homepage, privacy).
    | The contact address is shown in the footer and the privacy statement;
    | leave it empty to hide the contact link.
    |
    */

    'description' => 'DipCatch watches the price of the groceries and products you buy anyway across Dutch supermarkets and webshops, compares shops on unit price, and alerts you when one drops.',

    'contact_email' => env('SITE_CONTACT_EMAIL'),

    /**
     * Shops with a dedicated adapter or data source, shown as logos on the
     * homepage and the first-run dashboard.
     */
    'supported_hosts' => [
        'ah.nl', 'jumbo.com', 'dirk.nl', 'lidl.nl', 'aldi.nl', 'spar.nl',
        'dekamarkt.nl', 'poiesz.nl', 'vomar.nl', 'bol.com', 'amazon.nl', 'zooplus.nl',
    ],

];
