<?php

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) return require $localConfig;

$config = [
    'host' => getenv('LEL_DB_HOST') ?: '',
    'db' => getenv('LEL_DB_NAME') ?: '',
    'user' => getenv('LEL_DB_USER') ?: '',
    'pass' => getenv('LEL_DB_PASSWORD') ?: '',
    'charset' => getenv('LEL_DB_CHARSET') ?: 'utf8mb4',
];
return $config;
