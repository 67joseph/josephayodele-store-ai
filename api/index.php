<?php
// Store-AI API front controller.

// PHP's built-in dev server: let real files (uploaded images) be served directly.
if (PHP_SAPI === 'cli-server') {
    $f = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($f) && basename($f) !== 'index.php' && substr($f, -4) !== '.php') {
        return false;
    }
}

require __DIR__ . '/src/core.php';

date_default_timezone_set(cfg('timezone', 'Africa/Lagos'));

header('Access-Control-Allow-Origin: ' . cfg('cors_origin', '*'));
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token, X-Store-Id');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

foreach (['auth', 'products', 'sales', 'reports'] as $mod) {
    require __DIR__ . '/src/' . $mod . '.php';
}

try {
    route('GET', '/', function () {
        respond(['name' => 'Store-AI API', 'ok' => true]);
    }, false);
    dispatch();
} catch (HttpError $e) {
    respond(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('[store-ai] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    respond(['error' => cfg('debug') ? $e->getMessage() : 'Something went wrong on the server'], 500);
}
