<?php

// Router for PHP's built-in web server in TransportTest.

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Test-Server: yes');
$uri = $_SERVER['REQUEST_URI'] ?? '/';

if ($uri === '/slow') {
    sleep(2);
}

if (preg_match('#^/status/(\d+)$#', $uri, $match)) {
    http_response_code((int) $match[1]);
    echo json_encode(['message' => 'status ' . $match[1]]);

    return;
}

echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $uri,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    'body' => file_get_contents('php://input'),
], JSON_UNESCAPED_UNICODE);
