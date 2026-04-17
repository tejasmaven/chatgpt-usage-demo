<?php
/**
 * Shared helper functions.
 */

function write_log(string $message, string $level = 'INFO', bool $isError = false): void
{
    $logFile = $isError ? ERROR_LOG_FILE : APP_LOG_FILE;
    $line = sprintf("[%s] [%s] %s%s", date('Y-m-d H:i:s'), strtoupper($level), $message, PHP_EOL);
    @file_put_contents($logFile, $line, FILE_APPEND);
}

function redirect_to(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function post(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

function mask_api_key(?string $apiKey): string
{
    if (!$apiKey) {
        return 'Not configured';
    }

    $length = strlen($apiKey);
    if ($length <= 8) {
        return str_repeat('*', $length);
    }

    return substr($apiKey, 0, 4) . str_repeat('*', max(0, $length - 8)) . substr($apiKey, -4);
}

function format_number($value): string
{
    return number_format((float) $value, 0, '.', ',');
}

function format_currency($value): string
{
    if ($value === null || $value === '') {
        return 'N/A';
    }

    return '$' . number_format((float) $value, 4, '.', ',');
}
