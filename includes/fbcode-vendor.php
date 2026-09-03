<?php
declare(strict_types=1);

/**
 * Manual vendor bootstrap for FBCode generation.
 *
 * chillerlan/php-qrcode: 6.0.1
 * chillerlan/php-settings-container: 3.3.0
 */
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'chillerlan\\QRCode\\' => dirname(__DIR__) . '/backend/vendor/chillerlan/php-qrcode/src/',
        'chillerlan\\Settings\\' => dirname(__DIR__) . '/backend/vendor/chillerlan/php-settings-container/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $prefixLength = strlen($prefix);

        if (strncmp($class, $prefix, $prefixLength) !== 0) {
            continue;
        }

        $relativeClass = substr($class, $prefixLength);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});
