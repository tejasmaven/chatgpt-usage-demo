<?php
/**
 * Main configuration.
 * Update DB credentials to match your local setup.
 */

require_once __DIR__ . '/constants.php';

date_default_timezone_set(APP_TIMEZONE);

$config = [
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'dbname' => 'openai_usage_tracker',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'openai' => [
        'usage_endpoint' => 'https://api.openai.com/v1/organization/usage/completions',
        'costs_endpoint' => 'https://api.openai.com/v1/organization/costs',
        'timeout_seconds' => 30,
    ],
];
