<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public contact details
    |--------------------------------------------------------------------------
    |
    | Shown in the public pages' header links and footer "Contact us" block. The Division's published details are the
    | defaults; set a value to empty in .env to hide it.
    |
    */

    'contact' => [
        'office' => env('DTS_CONTACT_OFFICE', 'DepEd Schools Division of Nueva Vizcaya'),
        'address' => env('DTS_CONTACT_ADDRESS', 'Quezon St., Don Domingo Maddela, Bayombong, Nueva Vizcaya'),
        // Several numbers are separated by commas.
        'phone' => env('DTS_CONTACT_PHONE', '+63 962 681 4945, +63 992 035 2123'),
        'email' => env('DTS_CONTACT_EMAIL', 'nuevavizcaya@deped.gov.ph'),
        'hours' => env('DTS_CONTACT_HOURS'),
        'facebook' => env('DTS_CONTACT_FACEBOOK', 'https://www.facebook.com/AHA.Bestkaya/'),
        'website' => env('DTS_CONTACT_WEBSITE', 'https://sdonuevavizcaya.com'),
    ],

];
