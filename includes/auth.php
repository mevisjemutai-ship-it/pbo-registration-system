<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /pbo-portal/login.php');
        exit;
    }
}

function requireAdmin(): void
{
    requireLogin();

    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Access denied.');
    }
}

function currentUserId(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

function currentUserName(): string
{
    return (string) ($_SESSION['full_name'] ?? '');
}

function currentUserRole(): string
{
    return (string) ($_SESSION['role'] ?? '');
}function requirePasswordChanged(): void
{
    requireLogin();

    if (!empty($_SESSION['must_change_password'])) {
        header('Location: /pbo-portal/change-password.php');
        exit;
    }
}