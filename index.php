<?php

require_once __DIR__ . '/includes/db.php';

$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

if ($uri !== '') {
    $stmt = db()->prepare("
        SELECT destination_url
        FROM short_links
        WHERE slug = :slug
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute(['slug' => $uri]);
    $link = $stmt->fetch();

    if ($link && !empty($link['destination_url'])) {
        header('Location: ' . $link['destination_url']);
        exit;
    }
}

header('Location: /page.php?name=home');
exit;