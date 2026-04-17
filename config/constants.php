<?php
/**
 * Application-wide constants.
 */

define('APP_NAME', 'OpenAI Usage Tracker');
define('APP_VERSION', '1.0.0-pilot');
define('APP_TIMEZONE', 'UTC');
define('APP_BASE_PATH', dirname(__DIR__));
define('LOGS_PATH', APP_BASE_PATH . '/logs');
define('APP_LOG_FILE', LOGS_PATH . '/app.log');
define('ERROR_LOG_FILE', LOGS_PATH . '/error.log');
define('USAGE_CACHE_DAYS', 30);
define('USAGE_CACHE_TTL_SECONDS', 3600); // Refresh cached row every hour
