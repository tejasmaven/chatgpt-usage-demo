<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/controllers/AdminController.php';

$page = isset($_GET['page']) ? trim((string) $_GET['page']) : 'dashboard';
$allowedPages = ['dashboard', 'api-key', 'settings', 'save-api-key', 'chat', 'chat-submit'];

if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}

try {
    $pdo = get_db_connection($config);
    $controller = new AdminController($pdo, $config);
    $controller->handleRequest($page);
} catch (Throwable $e) {
    write_log('Fatal app error: ' . $e->getMessage(), 'ERROR', true);
    http_response_code(500);
    echo '<h1>Application Error</h1>';
    echo '<p>Please check logs/error.log for details.</p>';
}
