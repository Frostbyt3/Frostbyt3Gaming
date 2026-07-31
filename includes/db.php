<?php
declare(strict_types=1);

// db.php

require_once __DIR__ . '/../config/secrets.php';

function isLocal(): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? '';

    return in_array($host, [
        'localhost',
        '127.0.0.1',
        '192.168.1.149',
        '10.8.0.2'
    ], true);
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (isLocal()) {
        $host = MAIN_DB_HOST_L;
        $name = MAIN_DB_NAME_L;
        $user = MAIN_DB_USER_L;
        $pass = MAIN_DB_PASS_L;
    } else {
        $host = MAIN_DB_HOST;
        $name = MAIN_DB_NAME;
        $user = MAIN_DB_USER;
        $pass = MAIN_DB_PASS;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $host,
        $name
    );

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}