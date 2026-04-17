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
            $this->saveApiKey();
            return;
        }

        if (!$this->hasApiKey() && $page !== 'api-key') {
            redirect_to('index.php?page=api-key');
        }

        if ($page === 'api-key') {
            $this->showApiKeyForm();
            return;
        }

        $this->showDashboard();
    }

    public function showApiKeyForm(): void
    {
        $currentPage = 'api-key';
        $setting = $this->getSettings();
        $maskedApiKey = mask_api_key($setting['openai_api_key'] ?? null);

        require APP_BASE_PATH . '/includes/header.php';
        require APP_BASE_PATH . '/layouts/sidebar.php';
        require APP_BASE_PATH . '/views/api_key_form.php';
        require APP_BASE_PATH . '/includes/footer.php';
    }

    public function saveApiKey(): void
    {
        $apiKey = post('openai_api_key');

        if ($apiKey === '') {
            set_flash_message('error', 'API key is required.');
            redirect_to('index.php?page=api-key');
        }

        try {
            $existing = $this->getSettings();

            if ($existing) {
                $sql = 'UPDATE settings SET openai_api_key = :openai_api_key, updated_at = NOW() WHERE id = :id';
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':openai_api_key' => $apiKey,
                    ':id' => $existing['id'],
                ]);
            } else {
                $sql = 'INSERT INTO settings (openai_api_key, created_at, updated_at) VALUES (:openai_api_key, NOW(), NOW())';
                $stmt = $this->db->prepare($sql);
                $stmt->execute([':openai_api_key' => $apiKey]);
            }

            write_log('API key saved/updated successfully.');
            set_flash_message('success', 'API key saved successfully.');
            redirect_to('index.php?page=dashboard');
        } catch (Throwable $e) {
            write_log('Failed to save API key: ' . $e->getMessage(), 'ERROR', true);
            set_flash_message('error', 'Failed to save API key. Check logs/error.log.');
            redirect_to('index.php?page=api-key');
        }
    }

    public function showDashboard(): void
    {
        $currentPage = 'dashboard';
        $setting = $this->getSettings();
        $usageData = $this->getOrRefreshUsageData();

        require APP_BASE_PATH . '/includes/header.php';
        require APP_BASE_PATH . '/layouts/sidebar.php';
        require APP_BASE_PATH . '/views/dashboard.php';
        require APP_BASE_PATH . '/includes/footer.php';
    }

    private function hasApiKey(): bool
    {
        $setting = $this->getSettings();
        return !empty($setting['openai_api_key']);
    }

    private function getSettings(): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM settings ORDER BY id DESC LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function getOrRefreshUsageData(): array
    {
        $records = $this->loadCachedUsageRecords();
        $lastRecord = $records[0] ?? null;

        if ($lastRecord && isset($lastRecord['updated_at'])) {
            $age = time() - strtotime($lastRecord['updated_at']);
            if ($age < USAGE_CACHE_TTL_SECONDS) {
                write_log('Loaded usage data from cache.');
                return [
                    'status' => 'cached',
                    'message' => null,
                    'records' => $records,
                ];
            }
        }

        $apiKey = $this->getSettings()['openai_api_key'] ?? '';
        if ($apiKey === '') {
            return [
                'status' => 'error',
                'message' => 'API key is not configured.',
                'records' => $records,
            ];
        }

        $fetched = $this->fetchUsageFromOpenAI($apiKey);

        if ($fetched['success']) {
            $this->replaceUsageCache($fetched['daily']);
            $updatedRecords = $this->loadCachedUsageRecords();
            return [
                'status' => 'fresh',
                'message' => null,
                'records' => $updatedRecords,
            ];
        }

        write_log('Failed to fetch usage data: ' . $fetched['message'], 'ERROR', true);

        return [
            'status' => 'error',
            'message' => $fetched['message'],
            'records' => $records,
        ];
    }

    private function fetchUsageFromOpenAI(string $apiKey): array
    {
        $endTime = time();
        $startTime = strtotime('-' . USAGE_CACHE_DAYS . ' days');
        $query = http_build_query([
            'start_time' => $startTime,
            'end_time' => $endTime,
            'bucket_width' => '1d',
            'limit' => USAGE_CACHE_DAYS,
        ]);

        $url = $this->config['openai']['usage_endpoint'] . '?' . $query;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) $this->config['openai']['timeout_seconds'],
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || $curlError) {
            return [
                'success' => false,
                'message' => 'cURL error while requesting usage API: ' . $curlError,
                'daily' => [],
            ];
        }

        $payload = json_decode($responseBody, true);

        if ($httpCode >= 400) {
            return [
                'success' => false,
                'message' => 'OpenAI API returned HTTP ' . $httpCode,
                'daily' => [],
            ];
        }

        if (!is_array($payload)) {
            return [
                'success' => false,
                'message' => 'Invalid JSON response from usage API.',
                'daily' => [],
            ];
        }

        $daily = $this->parseUsagePayload($payload);

        if (empty($daily)) {
            return [
                'success' => false,
                'message' => 'No usage records returned from OpenAI API.',
                'daily' => [],
            ];
        }

        write_log('Fetched usage data from OpenAI API successfully.');

        return [
            'success' => true,
            'message' => null,
            'daily' => $daily,
        ];
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

    private function replaceUsageCache(array $records): void
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
                ]);
            }

            $this->db->commit();
            write_log('Usage cache refreshed successfully. Total rows: ' . count($records));
        } catch (Throwable $e) {
            $this->db->rollBack();
            write_log('Failed to refresh usage cache: ' . $e->getMessage(), 'ERROR', true);
            throw $e;
        }
    }

    private function loadCachedUsageRecords(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM usage_cache WHERE interval_type = :interval_type ORDER BY usage_date DESC');
        $stmt->execute([':interval_type' => 'daily']);
        return $stmt->fetchAll() ?: [];
    }
}
