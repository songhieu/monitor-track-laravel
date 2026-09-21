<?php

// Router for `php -S`: records each ingest request into MT_TEST_DIR and
// answers with MT_TEST_STATUS (default 202).
$dir = getenv('MT_TEST_DIR');
$body = file_get_contents('php://input');
if (($_SERVER['HTTP_CONTENT_ENCODING'] ?? '') === 'gzip') {
    $body = gzdecode($body);
}
file_put_contents($dir.'/'.microtime(true).'-'.bin2hex(random_bytes(3)).'.json', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
    'content_encoding' => $_SERVER['HTTP_CONTENT_ENCODING'] ?? null,
    'idempotency_key' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null,
    'body' => $body,
]));
http_response_code((int) (getenv('MT_TEST_STATUS') ?: 202));
