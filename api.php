<?php declare(strict_types=1);

use Indexer\Database;
use Indexer\RustRocksDBProxyException;

require __DIR__ . '/config.php';
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/include/functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $rawUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $uri = explode('/', trim($rawUri, '/'));

    // calling API method
    if (($reqCnt = count($uri)) >= 2 &&
        preg_match('/^v\d+(?:\.\d+)?$/', $uri[0]) &&
        preg_match('/^[a-zA-Z0-9_-]+$/', $uri[1]) &&
        is_file($scriptPath = __DIR__ . '/api/' . $uri[0] . '/' . $uri[1] . '.php')
    ) {
        $db = new Database();
        require $scriptPath;
    }
} catch (RuntimeException | RustRocksDBProxyException $e) {
    logEvent($e->getMessage(), $_SERVER['REQUEST_URI'], 'indexer-api.debug');
    if (in_array($e->getCode(), [INT_EXC_DB, INT_EXC_API_ERROR], true)) {
        apiAnswer('error', $e->getMessage());
    } else {
        apiAnswer('error', API_ERROR_INTERNAL);
    }
} catch (JsonException $e) {
    apiAnswer('error', API_ERROR_INVALID_INCOMING_JSON);
}

apiAnswer('error', API_ERROR_WRONG_API_METHOD);
