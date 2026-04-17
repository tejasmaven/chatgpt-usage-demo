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

function logAppMessage(string $message): void
{
    write_log($message, 'INFO', false);
}

function logErrorMessage(string $message): void
{
    write_log($message, 'ERROR', true);
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

function getLatestSettingsRow(PDO $db): ?array
{
    $stmt = $db->prepare('SELECT * FROM settings ORDER BY id DESC LIMIT 1');
    $stmt->execute();
    $row = $stmt->fetch();

    return $row ?: null;
}

function getSetting(string $keyName): ?string
{
    global $config;

    $db = get_db_connection($config);
    $settings = getLatestSettingsRow($db);

    if (!$settings || !array_key_exists($keyName, $settings)) {
        return null;
    }

    $value = $settings[$keyName];
    return is_string($value) ? $value : null;
}

function saveApiKeys(?string $standardApiKey, ?string $adminApiKey): bool
{
    global $config;

    $standardApiKey = trim((string) $standardApiKey);
    $adminApiKey = trim((string) $adminApiKey);

    $db = get_db_connection($config);
    $existing = getLatestSettingsRow($db);

    // TODO: Encrypt API keys at rest before storing in production.
    if ($existing) {
        $sql = 'UPDATE settings
                SET standard_api_key = :standard_api_key,
                    admin_api_key = :admin_api_key,
                    updated_at = NOW()
                WHERE id = :id';
        $stmt = $db->prepare($sql);
        return $stmt->execute([
            ':standard_api_key' => $standardApiKey !== '' ? $standardApiKey : null,
            ':admin_api_key' => $adminApiKey !== '' ? $adminApiKey : null,
            ':id' => (int) $existing['id'],
        ]);
    }

    $sql = 'INSERT INTO settings (standard_api_key, admin_api_key, created_at, updated_at)
            VALUES (:standard_api_key, :admin_api_key, NOW(), NOW())';
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':standard_api_key' => $standardApiKey !== '' ? $standardApiKey : null,
        ':admin_api_key' => $adminApiKey !== '' ? $adminApiKey : null,
    ]);
}

function maskApiKey(?string $key): string
{
    if (!$key) {
        return 'Not configured';
    }

    $length = strlen($key);
    if ($length <= 8) {
        return str_repeat('*', $length);
    }

    return substr($key, 0, 4) . str_repeat('*', max(0, $length - 8)) . substr($key, -4);
}

function mask_api_key(?string $apiKey): string
{
    return maskApiKey($apiKey);
}

function resolveCaBundlePath(array $config): ?string
{
    $candidatePaths = [
        $config['openai']['ca_bundle_path'] ?? null,
        ini_get('curl.cainfo') ?: null,
        ini_get('openssl.cafile') ?: null,
    ];

    foreach ($candidatePaths as $path) {
        if (!is_string($path)) {
            continue;
        }

        $trimmedPath = trim($path);
        if ($trimmedPath !== '' && is_readable($trimmedPath)) {
            return $trimmedPath;
        }
    }

    return null;
}

function requestOpenAIEndpoint(string $endpoint, string $adminApiKey, int $days = 30): array
{
    global $config;

    $endTime = time();
    $startTime = strtotime('-' . max(1, $days) . ' days');

    $url = $endpoint . '?' . http_build_query([
        'start_time' => $startTime,
        'end_time' => $endTime,
        'bucket_width' => '1d',
        'limit' => max(1, $days),
    ]);

    $ch = curl_init($url);
    $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int) ($config['openai']['timeout_seconds'] ?? 30),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $adminApiKey,
            'Content-Type: application/json',
        ],
    ];

    $caBundlePath = resolveCaBundlePath($config);
    if ($caBundlePath !== null) {
        $curlOptions[CURLOPT_CAINFO] = $caBundlePath;
    }

    curl_setopt_array($ch, $curlOptions);

    $responseBody = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false || $curlError !== '') {
        $errorMessage = 'cURL error while requesting OpenAI endpoint ' . $endpoint . ': ' . $curlError;
        logErrorMessage($errorMessage);

        return [
            'success' => false,
            'message' => 'Unable to connect to OpenAI API. Check logs/error.log for technical details.',
            'http_code' => 0,
            'response_body' => null,
            'payload' => null,
        ];
    }

    if ($httpCode !== 200) {
        logErrorMessage('Non-200 OpenAI response | endpoint=' . $endpoint . ' | http_code=' . $httpCode . ' | response_body=' . $responseBody);

        $friendly = 'OpenAI API request failed with HTTP ' . $httpCode . '.';
        if ($httpCode === 401 || $httpCode === 403) {
            $friendly = 'Authentication/authorization failed (HTTP ' . $httpCode . '). Organization usage endpoints require an Admin API key. Normal project keys may not work.';
        }

        return [
            'success' => false,
            'message' => $friendly,
            'http_code' => $httpCode,
            'response_body' => $responseBody,
            'payload' => null,
        ];
    }

    $payload = json_decode($responseBody, true);
    if (!is_array($payload)) {
        logErrorMessage('Malformed OpenAI response JSON | endpoint=' . $endpoint . ' | http_code=' . $httpCode . ' | response_body=' . $responseBody);

        return [
            'success' => false,
            'message' => 'Malformed response from OpenAI API.',
            'http_code' => $httpCode,
            'response_body' => $responseBody,
            'payload' => null,
        ];
    }

    logAppMessage('OpenAI request successful | endpoint=' . $endpoint . ' | http_code=' . $httpCode);

    return [
        'success' => true,
        'message' => null,
        'http_code' => $httpCode,
        'response_body' => $responseBody,
        'payload' => $payload,
    ];
}

function fetchOpenAIOrgUsage(string $adminApiKey, int $days = 30): array
{
    global $config;
    return requestOpenAIEndpoint($config['openai']['usage_endpoint'], $adminApiKey, $days);
}

function fetchOpenAICosts(string $adminApiKey, int $days = 30): array
{
    global $config;
    return requestOpenAIEndpoint($config['openai']['costs_endpoint'], $adminApiKey, $days);
}

function callOpenAIChat(string $apiKey, string $prompt): array
{
    global $config;

    $url = 'https://api.openai.com/v1/chat/completions';
    $payload = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ],
        'max_tokens' => 200,
    ];

    $ch = curl_init($url);
    $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => (int) ($config['openai']['timeout_seconds'] ?? 30),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ];

    $caBundlePath = resolveCaBundlePath($config);
    if ($caBundlePath !== null) {
        $curlOptions[CURLOPT_CAINFO] = $caBundlePath;
    }

    curl_setopt_array($ch, $curlOptions);

    $responseBody = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false || $curlError !== '') {
        $errorMessage = 'cURL error while requesting chat completions: ' . $curlError;
        logErrorMessage($errorMessage);

        return [
            'success' => false,
            'message' => 'Unable to connect to OpenAI API. Please try again later.',
            'payload' => null,
            'http_code' => 0,
        ];
    }

    $decoded = json_decode($responseBody, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        $apiError = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;
        $friendly = 'OpenAI API request failed with HTTP ' . $httpCode . '.';
        if (is_string($apiError) && $apiError !== '') {
            $friendly .= ' ' . $apiError;
        }

        logErrorMessage('Chat API request failed | http_code=' . $httpCode . ' | response_body=' . $responseBody);

        return [
            'success' => false,
            'message' => $friendly,
            'payload' => is_array($decoded) ? $decoded : null,
            'http_code' => $httpCode,
        ];
    }

    if (!is_array($decoded)) {
        logErrorMessage('Malformed chat completion response JSON | http_code=' . $httpCode . ' | response_body=' . $responseBody);

        return [
            'success' => false,
            'message' => 'Malformed response from OpenAI API.',
            'payload' => null,
            'http_code' => $httpCode,
        ];
    }

    return [
        'success' => true,
        'message' => null,
        'payload' => $decoded,
        'http_code' => $httpCode,
    ];
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
