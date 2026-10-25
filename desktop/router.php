<?php

declare(strict_types=1);

$expectedToken = getenv('APP_DESKTOP_TOKEN');
$receivedToken = $_SERVER['HTTP_X_OFFICECONVERTERS_DESKTOP'] ?? '';
if (! is_string($expectedToken) || strlen($expectedToken) < 32 || ! hash_equals($expectedToken, $receivedToken)) {
    http_response_code(403);
    exit('Forbidden');
}

$public = realpath(__DIR__.'/app/public');
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$file = realpath($public.DIRECTORY_SEPARATOR.ltrim($uri, '/'));
if ($file !== false && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file)
    && strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
    return false;
}

require $public.'/index.php';
