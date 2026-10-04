<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function requireCsrfToken(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$provided || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $provided)) {
        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Token de seguridad inválido o ausente.']);
        exit();
    }
}

function loginRateLimitKey(string $scope): string
{
    return hash('sha256', $scope . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function loginAttempt(string $scope, bool $success = false): bool
{
    $file = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'lel-login-' . loginRateLimitKey($scope) . '.json';
    $handle = fopen($file, 'c+');
    if (!$handle) return true;
    flock($handle, LOCK_EX);
    $state = json_decode(stream_get_contents($handle) ?: '{}', true) ?: [];
    $now = time();
    if (($state['started'] ?? 0) < $now - 900) $state = ['started' => $now, 'attempts' => 0];
    if (!$success && (int) $state['attempts'] >= 10) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return false;
    }
    $state = $success ? [] : ['started' => $state['started'], 'attempts' => (int) $state['attempts'] + 1];
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return true;
}
