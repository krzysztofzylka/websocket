<?php

declare(strict_types=1);

use Async\EventLoop;
use Websocket\Connection;
use Websocket\Server;

require __DIR__ . '/../vendor/autoload.php';

$loop = new EventLoop();
$server = new Server($loop, '0.0.0.0', 8088);
$server->enableLogging(true);
$server->enableAdaptiveTextStreaming(true);
$server->setAdaptiveMemoryTarget(64 * 1024 * 1024, 8 * 1024 * 1024);
$server->setMaxDecompressionRatio(25.0);
$server->enableAdaptiveTextStreaming(true);
$server->setMaxDecompressionRatio(25.0);
$server->setMaxConnections(100);
$server->setConnectionQueueLimit(50);
// Demo security: require a simple token, allow local file origins as well.
$server->setAllowedOrigins([
    'http://127.0.0.1:8088',
    'http://localhost',
    'http://localhost:63342',
    'null',
    'file://',
], false);
$server->setAuthValidator(function (Connection $connection, array $headers, array $query): bool {
    return ($query['token'] ?? null) === 'secret-token';
});

$server->onOpen(function (Connection $connection): void {
    $path = $connection->getPath() ?? '/';
    echo sprintf("[open] client=%s path=%s\n", $connection->getHeader('host') ?? 'unknown', $path);
});

$server->onMessage(function (Connection $connection, string $payload, bool $isText) use ($server): void {
    $type = $isText ? 'text' : 'binary';
    echo sprintf("[message] type=%s payload=%s\n", $type, $payload);

    if ($isText) {
        $server->broadcast('[broadcast] ' . $payload);
    }
});

$server->onBinaryStream(function (Connection $connection, $stream, int $length): void {
    echo sprintf("[binary-stream] %d bytes\n", $length);
    fclose($stream);
});

$server->onClose(function (Connection $connection, int $code, string $reason): void {
    echo sprintf("[close] code=%d reason=%s\n", $code, $reason !== '' ? $reason : '(none)');
});

$server->onError(function (Connection $connection, \Throwable $error): void {
    fwrite(STDERR, "[error] " . $error->getMessage() . PHP_EOL);
});

$loop->addTimer(10.0, function () use ($server): void {
    $stats = $server->getStats();
    try {
        $json = json_encode($stats, JSON_THROW_ON_ERROR);
    } catch (\JsonException $exception) {
        $json = json_encode($stats);
        if ($json === false) {
            $json = '{}';
        }
    }

    echo '[stats] ' . $json . PHP_EOL;
}, true);

$server->start();

echo "Server running at ws://127.0.0.1:8088 (Ctrl+C to stop)\n";

$loop->run();
