<?php
declare(strict_types=1);

function fbgIsLocalRequest(): bool
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;

    return in_array($host, [
        'localhost',
        '127.0.0.1',
        '192.168.1.149',
        '10.8.0.1',
        '10.8.0.2',
        '10.8.0.3',
        'dev.frostbyt3gaming.com',
    ], true);
}

function fbgConfigureErrorHandling(): void
{
    $isLocal = fbgIsLocalRequest();

    error_reporting(E_ALL);

    ini_set('log_errors', '1');
    ini_set('display_errors', $isLocal ? '1' : '0');
    ini_set('display_startup_errors', $isLocal ? '1' : '0');
    ini_set('html_errors', $isLocal ? '1' : '0');
}

fbgConfigureErrorHandling();
