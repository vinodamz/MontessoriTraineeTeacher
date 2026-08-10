<?php
// Copy this file to includes/config.php and fill in the real values.
// includes/config.php is gitignored — never commit DB credentials.

return [
    'db' => [
        'host'     => 'localhost',
        'name'     => 'cpaneluser_lg',      // cPanel prefixes DB names with your account
        'user'     => 'cpaneluser_lg',
        'password' => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],
    'app' => [
        'name'           => 'Little Graduates',
        'short_name'     => 'LG',
        'session_name'   => 'LG_SESSION',
        'max_pin_tries'  => 5,
        'lock_seconds'   => 30,
        'timezone'       => 'Asia/Kolkata',
        // Optional. Used to sign per-student survey prefills (?pref=).
        // If empty, a stable hash of the DB name+password is used instead.
        // 'survey_prefill_secret' => 'change-me-to-a-long-random-string',
    ],
];
