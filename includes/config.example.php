<?php
// Copy this file to includes/config.php and fill in the real values.
// includes/config.php is gitignored — never commit DB credentials.

return [
    'db' => [
        'host'     => 'localhost',
        'name'     => 'cpaneluser_mtt',     // cPanel prefixes DB names with your account
        'user'     => 'cpaneluser_mtt',
        'password' => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],
    'app' => [
        'name'           => 'Trainee Teacher Assessment',
        'short_name'     => 'TTA',
        'session_name'   => 'MTT_SESSION',
        'max_pin_tries'  => 5,
        'lock_seconds'   => 30,
        'timezone'       => 'Asia/Kolkata',
    ],
];
