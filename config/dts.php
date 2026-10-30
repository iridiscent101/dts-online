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
        'email' => env('DTS_CONTACT_EMAIL', 'sdovizcaya.deped@gmail.com'),
        'hours' => env('DTS_CONTACT_HOURS'),
        'facebook' => env('DTS_CONTACT_FACEBOOK', 'https://www.facebook.com/AHA.Bestkaya/'),
        'website' => env('DTS_CONTACT_WEBSITE', 'https://sdonuevavizcaya.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Client email limits
    |--------------------------------------------------------------------------
    |
    | Clients may give an email for routing updates. To keep the public form from being used to flood someone's inbox
    | (or to use up the Gmail account's daily sending limit, about 500 for a regular account), one address can be given
    | on only so many online submissions a day, and all client emails together stop for the day at the daily limit.
    |
    */

    // After an office releases or completes a document, it may still add files (not replace or delete them) for this
    // many hours, for example the scanned signed receipt. Administrators can always change files.
    'attachment_grace_hours' => (int) env('DTS_ATTACHMENT_GRACE_HOURS', 24),

    // On Vercel every Forgot password answer takes at least this long, longer than sending the email, so the answer time
    // never shows which sign-in emails have a recovery email. Other servers answer at once and send afterwards.
    'forgot_password_answer_seconds' => (float) env('DTS_FORGOT_PASSWORD_ANSWER_SECONDS', 10),

    // How long an emailed temporary password works. The person's real password keeps working alongside it.
    'temporary_password_minutes' => (int) env('DTS_TEMPORARY_PASSWORD_MINUTES', 60),

    // Shown under "Regards," at the end of every email.
    'mail_sign_off' => env('DTS_MAIL_SIGN_OFF', 'Deped SDO Records Unit'),

    'client_email' => [
        'submissions_per_address_per_day' => (int) env('DTS_CLIENT_EMAIL_PER_ADDRESS', 5),
        'daily_limit' => (int) env('DTS_CLIENT_EMAIL_DAILY_LIMIT', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo sign-up
    |--------------------------------------------------------------------------
    |
    | A public page (/demo-register) where demo participants create their own staff account. Off unless
    | DEMO_REGISTRATION_ENABLED=true; only sign-in emails ending in the given domain are accepted. Turn it off after the
    | demo.
    |
    */

    /*
    | Send people to My Account to set a new password at sign-in when an administrator set it or it is shorter than the
    | rule. DTS_REQUIRE_PASSWORD_CHANGE=false turns this off (for example during a demo).
    */
    'require_password_change' => (bool) env('DTS_REQUIRE_PASSWORD_CHANGE', true),

    'demo_registration' => [
        'enabled' => (bool) env('DEMO_REGISTRATION_ENABLED', false),
        'email_domain' => env('DEMO_REGISTRATION_DOMAIN', 'test.com'),
    ],

];
