<?php

class AdminController
{
    private PDO $db;
    private array $config;

    public function __construct(PDO $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function handleRequest(string $page): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'save-api-key') {
            $this->saveApiKeys();
            return;
        }

        if ($page === 'api-key' || $page === 'settings') {
            $this->showApiKeyForm();
            return;
        }

        $this->showDashboard();
    }

    public function showApiKeyForm(): void
    {
        $currentPage = 'api-key';
        $setting = $this->getSettings();
        $maskedStandardApiKey = maskApiKey($setting['standard_api_key'] ?? null);
        $maskedAdminApiKey = maskApiKey($setting['admin_api_key'] ?? null);

        require APP_BASE_PATH . '/includes/header.php';
        require APP_BASE_PATH . '/layouts/sidebar.php';
        require APP_BASE_PATH . '/views/api_key_form.php';
        require APP_BASE_PATH . '/includes/footer.php';
    }

    public function saveApiKeys(): void
    {
        $standardApiKey = post('standard_api_key');
        $adminApiKey = post('admin_api_key');

        try {
            $saved = saveApiKeys($standardApiKey, $adminApiKey);
            if (!$saved) {
                throw new RuntimeException('saveApiKeys returned false');
            }

            logAppMessage('API keys saved/updated successfully.');
            set_flash_message('success', 'API keys saved successfully.');
            redirect_to('index.php?page=dashboard');
        } catch (Throwable $e) {
            logErrorMessage('Failed to save API keys: ' . $e->getMessage());
            set_flash_message('error', 'Failed to save API keys. Check logs/error.log.');
            redirect_to('index.php?page=api-key');
        }
    }

    public function showDashboard(): void
    {
        $currentPage = 'dashboard';
        $setting = $this->getSettings();
        $usageData = $this->syncUsageData();

        require APP_BASE_PATH . '/includes/header.php';
        require APP_BASE_PATH . '/layouts/sidebar.php';
        require APP_BASE_PATH . '/views/dashboard.php';
        require APP_BASE_PATH . '/includes/footer.php';
    }

    public function syncUsageData(): array
    {
        $records = $this->loadCachedUsageRecords();
        $latestRecord = $records[0] ?? null;

        $adminApiKey = trim((string) getSetting('admin_api_key'));
        $standardApiKey = trim((string) getSetting('standard_api_key'));

        if ($adminApiKey === '') {
            logErrorMessage('Dashboard sync blocked: missing admin API key.');

            return [
                'status' => 'missing_admin_key',
                'message' => 'Admin API key is required to fetch organization usage data. Normal/project API keys may not work for organization endpoints.',
                'records' => $records,
                'diagnostics' => [
                    'standard_api_key_configured' => $standardApiKey !== '',
                    'admin_api_key_configured' => false,
                    'last_http_code' => $latestRecord['last_http_code'] ?? null,
                    'last_sync_status' => $latestRecord['last_sync_status'] ?? 'missing_admin_key',
                    'last_successful_sync_at' => $latestRecord['last_successful_sync_at'] ?? null,
                    'last_sync_error' => $latestRecord['last_sync_error'] ?? null,
                ],
            ];
        }

        if ($latestRecord && isset($latestRecord['updated_at'])) {
            $age = time() - strtotime((string) $latestRecord['updated_at']);
            if ($age < USAGE_CACHE_TTL_SECONDS) {
                logAppMessage('Loaded usage data from cache.');

                return [
                    'status' => 'cached',
                    'message' => null,
                    'records' => $records,
                    'diagnostics' => [
                        'standard_api_key_configured' => $standardApiKey !== '',
                        'admin_api_key_configured' => true,
                        'last_http_code' => $latestRecord['last_http_code'] ?? null,
                        'last_sync_status' => $latestRecord['last_sync_status'] ?? 'cached',
                        'last_successful_sync_at' => $latestRecord['last_successful_sync_at'] ?? null,
                        'last_sync_error' => $latestRecord['last_sync_error'] ?? null,
                    ],
                ];
            }
        }

        $usageResponse = fetchOpenAIOrgUsage($adminApiKey, USAGE_CACHE_DAYS);
        $httpCode = (int) ($usageResponse['http_code'] ?? 0);

        if (!$usageResponse['success']) {
            $message = $usageResponse['message'] ?? 'Unknown OpenAI usage API error.';
            $this->updateCacheSyncMetadata('failed', $message, $httpCode, null);

            return [
                'status' => 'error',
                'message' => $message,
                'records' => $records,
                'diagnostics' => [
                    'standard_api_key_configured' => $standardApiKey !== '',
                    'admin_api_key_configured' => true,
                    'last_http_code' => $httpCode,
                    'last_sync_status' => 'failed',
                    'last_successful_sync_at' => $latestRecord['last_successful_sync_at'] ?? null,
                    'last_sync_error' => $message,
                ],
            ];
        }

        $usagePayload = $usageResponse['payload'] ?? [];
        $daily = $this->parseUsagePayload($usagePayload);

        if (empty($daily)) {
            $message = 'No usage records returned from OpenAI API.';
            logErrorMessage($message);
            $this->updateCacheSyncMetadata('failed', $message, $httpCode, null);

            return [
                'status' => 'error',
                'message' => $message,
                'records' => $records,
                'diagnostics' => [
                    'standard_api_key_configured' => $standardApiKey !== '',
                    'admin_api_key_configured' => true,
                    'last_http_code' => $httpCode,
                    'last_sync_status' => 'failed',
                    'last_successful_sync_at' => $latestRecord['last_successful_sync_at'] ?? null,
                    'last_sync_error' => $message,
                ],
            ];
        }

        $costResponse = fetchOpenAICosts($adminApiKey, USAGE_CACHE_DAYS);
        if ($costResponse['success']) {
            $costsByDate = $this->parseCostsPayloadByDate($costResponse['payload'] ?? []);
            foreach ($daily as &$row) {
                if (isset($costsByDate[$row['usage_date']])) {
                    $row['total_cost_usd'] = $costsByDate[$row['usage_date']];
                }
            }
            unset($row);
        } else {
            logErrorMessage('Costs endpoint fetch failed; continuing with usage payload cost fields if available. Message: ' . ($costResponse['message'] ?? 'unknown'));
        }

        $successTimestamp = date('Y-m-d H:i:s');
        $this->replaceUsageCache($daily, 'success', null, $httpCode, $successTimestamp);
        $updatedRecords = $this->loadCachedUsageRecords();
        $latestUpdatedRecord = $updatedRecords[0] ?? null;

        return [
            'status' => 'fresh',
            'message' => null,
            'records' => $updatedRecords,
            'diagnostics' => [
                'standard_api_key_configured' => $standardApiKey !== '',
                'admin_api_key_configured' => true,
                'last_http_code' => $latestUpdatedRecord['last_http_code'] ?? $httpCode,
                'last_sync_status' => $latestUpdatedRecord['last_sync_status'] ?? 'success',
                'last_successful_sync_at' => $latestUpdatedRecord['last_successful_sync_at'] ?? $successTimestamp,
                'last_sync_error' => $latestUpdatedRecord['last_sync_error'] ?? null,
            ],
        ];
    }

    private function getSettings(): ?array
    {
        return getLatestSettingsRow($this->db);
    }

    private function parseUsagePayload(array $payload): array
    {
        $rows = [];

        $dataBuckets = $payload['data'] ?? [];
        foreach ($dataBuckets as $bucket) {
            $bucketDate = isset($bucket['start_time']) ? date('Y-m-d', (int) $bucket['start_time']) : date('Y-m-d');
            $results = $bucket['results'] ?? [];

            $totalRequests = 0;
            $textInput = 0;
            $textOutput = 0;
            $cached = 0;
            $audioInput = 0;
            $audioOutput = 0;
            $images = 0;
            $costUsd = 0.0;

            foreach ($results as $result) {
                $totalRequests += (int) ($result['num_model_requests'] ?? 0);
                $textInput += (int) ($result['input_tokens'] ?? 0);
                $textOutput += (int) ($result['output_tokens'] ?? 0);
                $cached += (int) ($result['input_cached_tokens'] ?? 0);
                $audioInput += (int) ($result['input_audio_tokens'] ?? 0);
                $audioOutput += (int) ($result['output_audio_tokens'] ?? 0);
                $images += (int) ($result['num_images'] ?? 0);

                if (isset($result['cost_usd'])) {
                    $costUsd += (float) $result['cost_usd'];
                }
            }

            $rows[] = [
                'usage_date' => $bucketDate,
                'interval_type' => 'daily',
                'total_requests' => $totalRequests,
                'total_text_input_tokens' => $textInput,
                'total_text_output_tokens' => $textOutput,
                'total_cached_tokens' => $cached,
                'total_audio_input_tokens' => $audioInput,
                'total_audio_output_tokens' => $audioOutput,
                'total_images' => $images,
                'total_cost_usd' => $costUsd,
                'raw_json' => json_encode($bucket),
            ];
        }

        usort($rows, static fn($a, $b) => strcmp($b['usage_date'], $a['usage_date']));

        return $rows;
    }

    private function parseCostsPayloadByDate(array $payload): array
    {
        $costByDate = [];

        foreach (($payload['data'] ?? []) as $bucket) {
            $bucketDate = isset($bucket['start_time']) ? date('Y-m-d', (int) $bucket['start_time']) : null;
            if ($bucketDate === null) {
                continue;
            }

            $bucketCost = 0.0;
            foreach (($bucket['results'] ?? []) as $result) {
                if (isset($result['amount']['value'])) {
                    $bucketCost += (float) $result['amount']['value'];
                } elseif (isset($result['cost_usd'])) {
                    $bucketCost += (float) $result['cost_usd'];
                }
            }

            $costByDate[$bucketDate] = $bucketCost;
        }

        return $costByDate;
    }

    private function replaceUsageCache(array $records, string $syncStatus, ?string $syncError, int $httpCode, ?string $successTimestamp): void
    {
        $this->db->beginTransaction();

        try {
            $deleteStmt = $this->db->prepare('DELETE FROM usage_cache WHERE interval_type = :interval_type');
            $deleteStmt->execute([':interval_type' => 'daily']);

            $insertSql = 'INSERT INTO usage_cache (
                usage_date,
                interval_type,
                total_requests,
                total_text_input_tokens,
                total_text_output_tokens,
                total_cached_tokens,
                total_audio_input_tokens,
                total_audio_output_tokens,
                total_images,
                total_cost_usd,
                raw_json,
                last_sync_status,
                last_sync_error,
                last_http_code,
                last_successful_sync_at,
                created_at,
                updated_at
            ) VALUES (
                :usage_date,
                :interval_type,
                :total_requests,
                :total_text_input_tokens,
                :total_text_output_tokens,
                :total_cached_tokens,
                :total_audio_input_tokens,
                :total_audio_output_tokens,
                :total_images,
                :total_cost_usd,
                :raw_json,
                :last_sync_status,
                :last_sync_error,
                :last_http_code,
                :last_successful_sync_at,
                NOW(),
                NOW()
            )';

            $insertStmt = $this->db->prepare($insertSql);

            foreach ($records as $record) {
                $insertStmt->execute([
                    ':usage_date' => $record['usage_date'],
                    ':interval_type' => $record['interval_type'],
                    ':total_requests' => $record['total_requests'],
                    ':total_text_input_tokens' => $record['total_text_input_tokens'],
                    ':total_text_output_tokens' => $record['total_text_output_tokens'],
                    ':total_cached_tokens' => $record['total_cached_tokens'],
                    ':total_audio_input_tokens' => $record['total_audio_input_tokens'],
                    ':total_audio_output_tokens' => $record['total_audio_output_tokens'],
                    ':total_images' => $record['total_images'],
                    ':total_cost_usd' => $record['total_cost_usd'],
                    ':raw_json' => $record['raw_json'],
                    ':last_sync_status' => $syncStatus,
                    ':last_sync_error' => $syncError,
                    ':last_http_code' => $httpCode > 0 ? $httpCode : null,
                    ':last_successful_sync_at' => $successTimestamp,
                ]);
            }

            $this->db->commit();
            logAppMessage('Usage cache refreshed successfully. Total rows: ' . count($records));
        } catch (Throwable $e) {
            $this->db->rollBack();
            logErrorMessage('Failed to refresh usage cache: ' . $e->getMessage());
            throw $e;
        }
    }

    private function updateCacheSyncMetadata(string $syncStatus, ?string $syncError, int $httpCode, ?string $successTimestamp): void
    {
        $sql = 'UPDATE usage_cache
                SET last_sync_status = :last_sync_status,
                    last_sync_error = :last_sync_error,
                    last_http_code = :last_http_code,
                    last_successful_sync_at = COALESCE(:last_successful_sync_at, last_successful_sync_at),
                    updated_at = NOW()
                WHERE interval_type = :interval_type';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':last_sync_status' => $syncStatus,
            ':last_sync_error' => $syncError,
            ':last_http_code' => $httpCode > 0 ? $httpCode : null,
            ':last_successful_sync_at' => $successTimestamp,
            ':interval_type' => 'daily',
        ]);
    }

    private function loadCachedUsageRecords(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM usage_cache WHERE interval_type = :interval_type ORDER BY usage_date DESC');
        $stmt->execute([':interval_type' => 'daily']);
        return $stmt->fetchAll() ?: [];
    }
}
