<?php

// Laisse le serveur PHP local servir les fichiers publics avant de passer à Symfony.
$publicDirectory = realpath(__DIR__ . '/public');
$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$file = realpath($publicDirectory . $requestPath);

if (
    $file !== false &&
    str_starts_with($file, $publicDirectory . DIRECTORY_SEPARATOR) &&
    is_file($file)
) {
    return false;
}

require __DIR__ . '/public/index.php';
