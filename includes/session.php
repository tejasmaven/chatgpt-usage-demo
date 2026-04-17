<?php
/**
 * Session setup.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function set_flash_message(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flash_message(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}
