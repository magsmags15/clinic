<?php
/**
 * Copy this file to  config.local.php  ON THE SERVER and fill in the values from your hosting panel
 * (MySQL Databases section). config.local.php is git-ignored, so the password never goes to GitHub.
 */
return [
    'host' => 'localhost',          // usually "localhost" on shared hosting
    'port' => 3306,
    'name' => 'your_database_name',
    'user' => 'your_database_user',
    'pass' => 'your_database_password',
];
