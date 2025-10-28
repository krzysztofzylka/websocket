<?php

declare(strict_types=1);

namespace Websocket;

use Async\LoopInterface;
use SplQueue;
use Websocket\Exception\WebSocketException;

/**
 * WebSocket server powered by the Async event loop.
 */
class Server
{

    private LoopInterface $loop;

    private string $host;

    private int $port;

    /** @var resource|null */
    private $serverStream = null;

    /** @var array<int, Connection> */
    private array $connections = [];

    /** @var array<int, true> */
    private array $connectionsOpened = [];

    /** @var callable|null */
    private $openHandler = null;

    /** @var callable|null */
    private $messageHandler = null;

    /** @var callable|null */
    private $closeHandler = null;

    /** @var callable|null */
    private $errorHandler = null;

    /** @var callable|null */
    private $pingHandler = null;

    /** @var callable|null */
    private $pongHandler = null;

    private array $supportedSubprotocols = [];

    /** @var callable|null */
    private $subprotocolSelector = null;

    private bool $rejectMismatchedSubprotocol = false;

    private bool $listening = false;

    private array $contextOptions;

    private bool $tls = false;

    private int $tlsCryptoMethod;

    private bool $compressionEnabled = true;

    private bool $compressionServerNoContextTakeover = true;

    private bool $compressionClientNoContextTakeover = true;

    private int $compressionMinBytes = 256;

    private int $maxMessageSize = 8 * 1024 * 1024;

    private int $binaryStreamThreshold = 256 * 1024;

    /** @var callable|null */
    private $binaryStreamHandler = null;

    private int $textStreamThreshold = 64 * 1024;

    private bool $adaptiveTextStreaming = false;

    private int $adaptiveMemoryTarget = 64 * 1024 * 1024;

    private int $adaptiveMemoryTolerance = 8 * 1024 * 1024;

    private float $maxDecompressionRatio = 20.0;

    /** @var array<int, string> */
    private array $allowedOrigins = [];

    private bool $requireOriginHeader = false;

    /** @var callable|null */
    private $authValidator = null;

    private bool $loggingEnabled = false;

    private bool $tlsAutoReload = false;

    private int $tlsReloadInterval = 300;

    private int $tlsLastReload = 0;

    private ?string $tlsContextHash = null;

    private ?int $maxConnections = null;

    private int $connectionQueueLimit = 0;

    /** @var SplQueue<resource> */
    private SplQueue $pendingSockets;

    private int $connectionsAcceptedTotal = 0;

    private int $connectionsRejectedTotal = 0;

    private int $connectionsQueuedTotal = 0;

    private int $messagesReceived = 0;

    private int $messagesSent = 0;

    private int $bytesReceived = 0;

    private int $bytesSent = 0;

    private int $binaryStreamsHandled = 0;

    public function __construct(LoopInterface $loop, string $host, int $port, array $contextOptions = [], array $options = [])
    {
        $this->loop = $loop;
        $this->host = $host;
        $this->port = $port;
        $this->contextOptions = $contextOptions;
        $this->tls = (bool)($options['tls'] ?? false);
        $this->tlsCryptoMethod = (int)($options['tls_crypto_method'] ?? $this->defaultTlsCryptoMethod());
        $this->compressionEnabled = (bool)($options['compression'] ?? true);
        $this->compressionServerNoContextTakeover = (bool)($options['compression_server_no_context_takeover'] ?? true);
        $this->compressionClientNoContextTakeover = (bool)($options['compression_client_no_context_takeover'] ?? true);
        $this->compressionMinBytes = max(0, (int)($options['compression_min_bytes'] ?? 256));
        $this->maxMessageSize = max(1, (int)($options['max_message_size'] ?? (8 * 1024 * 1024)));
        $this->binaryStreamThreshold = max(1, (int)($options['binary_stream_threshold'] ?? (256 * 1024)));

        $this->textStreamThreshold = max(1, (int)($options['text_stream_threshold'] ?? (64 * 1024)));
        $this->loggingEnabled = (bool)($options['logging'] ?? false);
        $this->adaptiveTextStreaming = (bool)($options['adaptive_text_streaming'] ?? false);
        $this->adaptiveMemoryTarget = max(1, (int)($options['adaptive_memory_target'] ?? (64 * 1024 * 1024)));
        $this->adaptiveMemoryTolerance = max(1, (int)($options['adaptive_memory_tolerance'] ?? (8 * 1024 * 1024)));
        $this->maxDecompressionRatio = max(1.0, (float)($options['max_decompression_ratio'] ?? 20.0));
        $this->tlsAutoReload = (bool)($options['tls_auto_reload'] ?? false);
        $this->tlsReloadInterval = max(1, (int)($options['tls_reload_interval'] ?? 300));
        $this->tlsContextHash = $this->computeTlsContextHash();

        $maxConnectionsOption = $options['max_connections'] ?? null;

        if ($maxConnectionsOption === null) {
            $this->maxConnections = null;
        } else {
            $maxConnectionsValue = (int)$maxConnectionsOption;
            $this->maxConnections = $maxConnectionsValue > 0 ? $maxConnectionsValue : null;
        }

        $this->connectionQueueLimit = max(0, (int)($options['connection_queue_limit'] ?? 0));

        $this->pendingSockets = new SplQueue();

        if ($this->tlsAutoReload && $this->tls) {
            $this->scheduleTlsReload();
        }
    }

    public function enableTls(bool $enabled = true): void
    {
        $this->tls = $enabled;
    }

    public function setTlsCryptoMethod(int $method): void
    {
        $this->tlsCryptoMethod = $method;
    }

    public function enableCompression(bool $enabled = true): void
    {
        $this->compressionEnabled = $enabled;
    }

    public function setCompressionContextTakeover(bool $clientNoContext, bool $serverNoContext): void
    {
        $this->compressionClientNoContextTakeover = $clientNoContext;
        $this->compressionServerNoContextTakeover = $serverNoContext;
    }

    public function setCompressionMinBytes(int $bytes): void
    {
        $this->compressionMinBytes = max(0, $bytes);
    }

    public function setMaxMessageSize(int $bytes): void
    {
        $this->maxMessageSize = max(1, $bytes);
    }

    public function setBinaryStreamThreshold(int $bytes): void
    {
        $this->binaryStreamThreshold = max(1, $bytes);
    }

    public function setTextStreamThreshold(int $bytes): void
    {
        $this->textStreamThreshold = max(1, $bytes);
    }

    public function getTextStreamThreshold(): int
    {
        return $this->textStreamThreshold;
    }

    public function enableAdaptiveTextStreaming(bool $enabled = true): void
    {
        $this->adaptiveTextStreaming = $enabled;
    }

    public function setAdaptiveMemoryTarget(int $bytes, int $tolerance): void
    {
        $this->adaptiveMemoryTarget = max(1, $bytes);
        $this->adaptiveMemoryTolerance = max(1, $tolerance);
    }

    public function validateAuth(Connection $connection, array $headers, array $queryParams): bool
    {
        if ($this->authValidator === null) {
            return true;
        }

        $result = (bool)($this->authValidator)($connection, $headers, $queryParams);

        if (!$result) {
            $this->log('WARN', 'Auth validator rejected connection.', [
                'client' => $connection->getHeader('host') ?? 'unknown',
                'path' => $connection->getPath(),
            ]);
        }

        return $result;
    }

    /**
     * Define allowed Origin headers (case-insensitive). Empty list disables restriction.
     */
    public function setAllowedOrigins(array $origins, bool $requireHeader = true): void
    {
        $normalized = [];

        foreach ($origins as $origin) {
            $origin = trim($origin);

            if ($origin === '') {
                continue;
            }

            $normalized[] = strtolower($origin);
        }

        $this->allowedOrigins = array_values(array_unique($normalized));
        $this->requireOriginHeader = $requireHeader && $this->allowedOrigins !== [];
    }

    public function getAllowedOrigins(): array
    {
        return $this->allowedOrigins;
    }

    public function requireOriginHeader(bool $required = true): void
    {
        $this->requireOriginHeader = $required;
    }

    public function shouldRequireOrigin(): bool
    {
        return $this->requireOriginHeader;
    }

    public function isOriginAllowed(?string $origin): bool
    {
        if ($this->allowedOrigins === []) {
            return true;
        }

        if ($origin === null || $origin === '') {
            return !$this->requireOriginHeader;
        }

        return in_array(strtolower($origin), $this->allowedOrigins, true);
    }

    /**
     * Set a custom authentication validator executed during the handshake.
     *
     * Signature: function (Connection $connection, array $headers, array $query): bool
     */
    public function setAuthValidator(?callable $validator): void
    {
        $this->authValidator = $validator;
    }

    public function hasAuthValidator(): bool
    {
        return $this->authValidator !== null;
    }

    public function setMaxConnections(?int $max): void
    {
        $this->maxConnections = $max !== null && $max <= 0 ? null : $max;
        $this->processPendingQueue();
    }

    public function getMaxConnections(): ?int
    {
        return $this->maxConnections;
    }

    public function setConnectionQueueLimit(int $limit): void
    {
        $this->connectionQueueLimit = max(0, $limit);
        $this->trimPendingQueue();
    }

    public function getConnectionQueueLimit(): int
    {
        return $this->connectionQueueLimit;
    }

    public function getPendingConnectionCount(): int
    {
        return $this->pendingSockets->count();
    }

    public function onBinaryStream(callable $handler): void
    {
        $this->binaryStreamHandler = $handler;
    }

    public function enableLogging(bool $enabled = true): void
    {
        $this->loggingEnabled = $enabled;
    }

    public function isLoggingEnabled(): bool
    {
        return $this->loggingEnabled;
    }

    public function enableTlsAutoReload(bool $enabled = true, ?int $interval = null): void
    {
        $this->tlsAutoReload = $enabled;

        if ($interval !== null) {
            $this->tlsReloadInterval = max(1, $interval);
        }

        if ($this->tlsAutoReload && $this->tls) {
            $this->scheduleTlsReload();
        }
    }

    public function setMaxDecompressionRatio(float $ratio): void
    {
        $this->maxDecompressionRatio = max(1.0, $ratio);
    }

    public function getMaxDecompressionRatio(): float
    {
        return $this->maxDecompressionRatio;
    }

    public function isTlsEnabled(): bool
    {
        return $this->tls;
    }

    public function isCompressionEnabled(): bool
    {
        return $this->compressionEnabled;
    }

    public function isClientNoContextTakeover(): bool
    {
        return $this->compressionClientNoContextTakeover;
    }

    public function isServerNoContextTakeover(): bool
    {
        return $this->compressionServerNoContextTakeover;
    }

    public function getCompressionMinBytes(): int
    {
        return $this->compressionMinBytes;
    }

    public function getMaxMessageSize(): int
    {
        return $this->maxMessageSize;
    }

    public function getBinaryStreamThreshold(): int
    {
        return $this->binaryStreamThreshold;
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Starts listening for incoming WebSocket connections.
     */
    public function start(): void
    {
        if ($this->listening) {
            return;
        }

        if ($this->tls && $this->tlsCryptoMethod === 0) {
            throw new WebSocketException('TLS is enabled, but no crypto method is configured.');
        }

        $scheme = $this->tls ? 'tls' : 'tcp';
        $uri = sprintf('%s://%s:%d', $scheme, $this->host, $this->port);
        $context = stream_context_create($this->prepareContextOptions());

        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        $server = @stream_socket_server($uri, $errno, $errstr, $flags, $context);

        if (!is_resource($server)) {
            throw new WebSocketException(sprintf('Failed to create server socket (%d): %s', $errno, $errstr));
        }

        if ($this->tls) {
            $this->applyTlsContext($server);
        }

        stream_set_blocking($server, false);
        $this->serverStream = $server;
        $this->loop->addReadStream($server, function () {
            $this->handleAccept();
        });

        $this->listening = true;
    }

    public function isListening(): bool
    {
        return $this->listening;
    }

    /**
     * Stops the server and closes all active connections.
     */
    public function stop(): void
    {
        if (!$this->listening) {
            return;
        }

        $this->listening = false;

        if (is_resource($this->serverStream)) {
            $this->loop->removeReadStream($this->serverStream);
            fclose($this->serverStream);
        }

        $this->serverStream = null;

        foreach ($this->connections as $connection) {
            $connection->close(Frame::CLOSE_GOING_AWAY, 'Server shutting down.');
        }

        while (!$this->pendingSockets->isEmpty()) {
            $queued = $this->pendingSockets->dequeue();

            if (is_resource($queued)) {
                fclose($queued);
            }
        }

        $this->pendingSockets = new SplQueue();
    }

    /**
     * Sets the handler invoked when a connection is successfully upgraded.
     */
    public function onOpen(callable $handler): void
    {
        $this->openHandler = $handler;
    }

    /**
     * Sets the handler invoked when a message is received.
     *
     * Signature: function (Connection $connection, string $payload, bool $isText): void
     */
    public function onMessage(callable $handler): void
    {
        $this->messageHandler = $handler;
    }

    /**
     * Sets the handler invoked when a connection is closed.
     *
     * Signature: function (Connection $connection, int $closeCode, string $reason): void
     */
    public function onClose(callable $handler): void
    {
        $this->closeHandler = $handler;
    }

    /**
     * Sets the handler invoked when an error occurs within a connection context.
     *
     * Signature: function (Connection $connection, \Throwable $error): void
     */
    public function onError(callable $handler): void
    {
        $this->errorHandler = $handler;
    }

    /**
     * Sets the handler invoked when a ping frame is received.
     */
    public function onPing(callable $handler): void
    {
        $this->pingHandler = $handler;
    }

    /**
     * Sets the handler invoked when a pong frame is received.
     */
    public function onPong(callable $handler): void
    {
        $this->pongHandler = $handler;
    }

    /**
     * Defines a static list of supported subprotocols. The first matching protocol offered
     * by the client will be selected.
     */
    public function setSupportedSubprotocols(array $protocols): void
    {
        $this->supportedSubprotocols = array_values(array_filter(array_map('trim', $protocols)));
    }

    /**
     * Allows custom subprotocol negotiation logic.
     *
     * Signature: function (Connection $connection, array $requested): ?string
     */
    public function setSubprotocolSelector(?callable $selector): void
    {
        $this->subprotocolSelector = $selector;
    }

    /**
     * If enabled, the handshake will be rejected when no subprotocol match is found.
     */
    public function requireSupportedSubprotocol(bool $required): void
    {
        $this->rejectMismatchedSubprotocol = $required;
    }

    /**
     * Returns the number of connections currently tracked by the server.
     */
    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * Returns a snapshot of active connections.
     *
     * @return Connection[]
     */
    public function getConnections(): array
    {
        return array_values($this->connections);
    }

    public function findConnectionsByLabel(string $label): array
    {
        $matches = [];

        foreach ($this->connections as $connection) {
            if ($connection->getLabel() === $label) {
                $matches[] = $connection;
            }
        }

        return $matches;
    }

    public function findConnectionsByProperty(string $name, mixed $value): array
    {
        $matches = [];

        foreach ($this->connections as $connection) {
            if ($connection->hasProperty($name) && $connection->getProperty($name) === $value) {
                $matches[] = $connection;
            }
        }

        return $matches;
    }

    /**
     * Broadcasts a message to all active connections.
     */
    public function broadcast(string $payload, bool $binary = false): void
    {
        $opcode = $binary ? Frame::OPCODE_BINARY : Frame::OPCODE_TEXT;

        foreach ($this->connections as $connection) {
            if ($connection->isOpen()) {
                try {
                    if ($opcode === Frame::OPCODE_BINARY) {
                        $connection->sendBinary($payload);
                    } else {
                        $connection->sendText($payload);
                    }
                } catch (\Throwable $exception) {
                    $this->notifyError($connection, $exception);
                }
            }
        }
    }

    /**
     * Internal: Handles incoming client sockets.
     */
    private function handleAccept(): void
    {
        if (!is_resource($this->serverStream)) {
            return;
        }

        while (($client = @stream_socket_accept($this->serverStream, 0)) !== false) {
            if ($this->tls) {
                $this->applyTlsContext($client);
                stream_set_blocking($client, true);
                $enabled = @stream_socket_enable_crypto($client, true, $this->tlsCryptoMethod);

                if ($enabled !== true) {
                    fclose($client);
                    $this->log('WARN', 'Failed to enable TLS for client.', []);
                    continue;
                }
            }

            stream_set_blocking($client, false);
            if ($this->maxConnections !== null && count($this->connections) >= $this->maxConnections) {
                if ($this->connectionQueueLimit !== 0 && $this->pendingSockets->count() >= $this->connectionQueueLimit) {
                    $this->rejectOverloadedClient($client);

                    continue;
                }

                $this->pendingSockets->enqueue($client);
                $this->connectionsQueuedTotal++;

                continue;
            }

            $this->promoteSocketToConnection($client);
        }
    }

    /**
     * Internal: Negotiates the subprotocol according to configuration.
     *
     * @param array<int, string> $requested
     */
    public function selectSubprotocol(Connection $connection, array $requested): ?string
    {
        if ($this->subprotocolSelector !== null) {
            $selected = ($this->subprotocolSelector)($connection, $requested);

            if ($selected !== null && !is_string($selected)) {
                throw new WebSocketException('Custom subprotocol selector must return string or null.');
            }

            if ($selected !== null && $selected !== '') {
                return $selected;
            }

            if ($this->rejectMismatchedSubprotocol) {
                throw new WebSocketException('No subprotocol selected.');
            }

            return null;
        }

        if ($this->supportedSubprotocols === []) {
            return null;
        }

        foreach ($requested as $protocol) {
            if (in_array($protocol, $this->supportedSubprotocols, true)) {
                return $protocol;
            }
        }

        if ($this->rejectMismatchedSubprotocol) {
            throw new WebSocketException('No supported subprotocol found.');
        }

        return null;
    }

    /**
     * Internal notification invoked once the connection is ready.
     */
    public function notifyOpen(Connection $connection): void
    {
        $id = $connection->getId();
        $this->connectionsOpened[$id] = true;

        $this->log('INFO', 'Connection opened.', [
            'connection_id' => $id,
            'path' => $connection->getPath(),
            'subprotocol' => $connection->getSubprotocol(),
        ]);

        if ($this->openHandler !== null) {
            ($this->openHandler)($connection);
        }
    }

    /**
     * Internal notification invoked on message reception.
     */
    public function notifyMessage(Connection $connection, string $payload, bool $isText): void
    {
        $this->log('DEBUG', 'Received message.', [
            'connection_id' => $connection->getId(),
            'type' => $isText ? 'text' : 'binary',
            'length' => strlen($payload),
        ]);

        $this->messagesReceived++;
        $this->bytesReceived += strlen($payload);

        if ($this->messageHandler !== null) {
            ($this->messageHandler)($connection, $payload, $isText);
        }
    }

    /**
     * Internal notification invoked when a binary message is streamed.
     *
     * @param resource $stream
     */
    public function notifyBinaryStream(Connection $connection, $stream, int $length): void
    {
        if (!is_resource($stream)) {
            return;
        }

        $this->messagesReceived++;
        $this->bytesReceived += $length;
        $this->binaryStreamsHandled++;

        if ($this->binaryStreamHandler !== null) {
            rewind($stream);
            ($this->binaryStreamHandler)($connection, $stream, $length);
        } else {
            fclose($stream);
        }
    }

    /**
     * Internal notification invoked on ping reception.
     */
    public function notifyPing(Connection $connection, string $payload): void
    {
        if ($this->pingHandler !== null) {
            ($this->pingHandler)($connection, $payload);
        }
    }

    /**
     * Internal notification invoked on pong reception.
     */
    public function notifyPong(Connection $connection, string $payload): void
    {
        if ($this->pongHandler !== null) {
            ($this->pongHandler)($connection, $payload);
        }
    }

    /**
     * Internal notification invoked when an error occurs.
     */
    public function notifyError(Connection $connection, \Throwable $error): void
    {
        $this->log('ERROR', $error->getMessage(), [
            'connection_id' => $connection->getId(),
            'exception' => get_class($error),
        ]);

        if ($this->errorHandler !== null) {
            ($this->errorHandler)($connection, $error);
        }
    }

    /**
     * Internal notification invoked when the connection closes.
     */
    public function notifyClose(Connection $connection, int $code, string $reason): void
    {
        $id = $connection->getId();

        if (isset($this->connections[$id])) {
            unset($this->connections[$id]);
        }

        $wasOpened = isset($this->connectionsOpened[$id]);
        unset($this->connectionsOpened[$id]);

        $this->log('INFO', 'Connection closed.', [
            'connection_id' => $id,
            'code' => $code,
            'reason' => $reason,
        ]);

        if ($wasOpened && $this->closeHandler !== null) {
            ($this->closeHandler)($connection, $code, $reason);
        }

        $this->processPendingQueue();
        $this->adjustTextStreamingThreshold();
    }

    public function recordSentMessage(int $bytes): void
    {
        $this->messagesSent++;
        $this->bytesSent += $bytes;
    }

    public function getStats(): array
    {
        $averageIn = $this->messagesReceived > 0 ? $this->bytesReceived / $this->messagesReceived : 0.0;
        $averageOut = $this->messagesSent > 0 ? $this->bytesSent / $this->messagesSent : 0.0;

        return [
            'active_connections' => count($this->connections),
            'pending_connections' => $this->pendingSockets->count(),
            'max_connections' => $this->maxConnections,
            'connection_queue_limit' => $this->connectionQueueLimit,
            'connections_accepted_total' => $this->connectionsAcceptedTotal,
            'connections_rejected_total' => $this->connectionsRejectedTotal,
            'connections_queued_total' => $this->connectionsQueuedTotal,
            'messages_received' => $this->messagesReceived,
            'messages_sent' => $this->messagesSent,
            'bytes_received' => $this->bytesReceived,
            'bytes_sent' => $this->bytesSent,
            'average_message_size_in' => $averageIn,
            'average_message_size_out' => $averageOut,
            'binary_streams' => $this->binaryStreamsHandled,
            'memory_usage' => memory_get_usage(false),
            'memory_usage_real' => memory_get_usage(true),
        ];
    }

    public function resetStats(): void
    {
        $this->messagesReceived = 0;
        $this->messagesSent = 0;
        $this->bytesReceived = 0;
        $this->bytesSent = 0;
        $this->binaryStreamsHandled = 0;
        $this->connectionsAcceptedTotal = 0;
        $this->connectionsRejectedTotal = 0;
        $this->connectionsQueuedTotal = 0;
    }

    private function processPendingQueue(): void
    {
        while (!$this->pendingSockets->isEmpty()
            && ($this->maxConnections === null || count($this->connections) < $this->maxConnections)
        ) {
            $client = $this->pendingSockets->dequeue();

            if (!is_resource($client)) {
                continue;
            }

            if (feof($client)) {
                fclose($client);
                $this->log('DEBUG', 'Dropped queued client (EOF).', []);
                continue;
            }

            $this->promoteSocketToConnection($client);
        }

        $this->adjustTextStreamingThreshold();
    }

    private function trimPendingQueue(): void
    {
        if ($this->connectionQueueLimit === 0) {
            return;
        }

        while ($this->pendingSockets->count() > $this->connectionQueueLimit) {
            $socket = $this->pendingSockets->dequeue();
            $this->rejectOverloadedClient($socket);
        }
    }

    private function promoteSocketToConnection($client): void
    {
        if (!is_resource($client)) {
            return;
        }

        stream_set_blocking($client, false);

        $connection = new Connection($this, $this->loop, $client, $this->getConnectionOptions());
        $id = $connection->getId();
        $this->connections[$id] = $connection;
        $this->connectionsAcceptedTotal++;

        $this->log('DEBUG', 'Promoted client to connection.', [
            'connection_id' => $id,
            'queue_size' => $this->pendingSockets->count(),
        ]);

        $this->loop->addReadStream($client, function ($stream) use ($connection) {
            $connection->handleRead();
        });

        $this->adjustTextStreamingThreshold();
    }

    private function getConnectionOptions(): array
    {
        return [
            'max_message_size' => $this->maxMessageSize,
            'binary_stream_threshold' => $this->binaryStreamThreshold,
            'text_stream_threshold' => $this->textStreamThreshold,
            'compression_enabled' => $this->compressionEnabled,
            'compression_min_bytes' => $this->compressionMinBytes,
            'compression_server_no_context_takeover' => $this->compressionServerNoContextTakeover,
            'compression_client_no_context_takeover' => $this->compressionClientNoContextTakeover,
            'adaptive_text_streaming' => $this->adaptiveTextStreaming,
            'adaptive_memory_target' => $this->adaptiveMemoryTarget,
            'adaptive_memory_tolerance' => $this->adaptiveMemoryTolerance,
            'logging' => $this->loggingEnabled,
            'max_decompression_ratio' => $this->maxDecompressionRatio,
        ];
    }

    private function rejectOverloadedClient($client): void
    {
        if (!is_resource($client)) {
            return;
        }

        if (!$this->tls) {
            @stream_set_blocking($client, true);
            @fwrite(
                $client,
                "HTTP/1.1 503 Service Unavailable\r\nConnection: close\r\nRetry-After: 5\r\nContent-Length: 0\r\n\r\n"
            );
        }

        fclose($client);
        $this->connectionsRejectedTotal++;
        $this->log('WARN', 'Rejected client due to overload.', []);
    }

    /**
     * Merges custom stream context options with defaults.
     */
    private function prepareContextOptions(): array
    {
        $defaults = [
            'socket' => [
                'so_reuseaddr' => true,
            ],
        ];

        return array_replace_recursive($defaults, $this->contextOptions);
    }

    private function defaultTlsCryptoMethod(): int
    {
        if (defined('STREAM_CRYPTO_METHOD_TLS_SERVER')) {
            return STREAM_CRYPTO_METHOD_TLS_SERVER;
        }

        $candidates = [
            'STREAM_CRYPTO_METHOD_TLSv1_3_SERVER',
            'STREAM_CRYPTO_METHOD_TLSv1_2_SERVER',
            'STREAM_CRYPTO_METHOD_TLSv1_1_SERVER',
            'STREAM_CRYPTO_METHOD_TLSv1_0_SERVER',
            'STREAM_CRYPTO_METHOD_TLSv1_3',
            'STREAM_CRYPTO_METHOD_TLSv1_2',
            'STREAM_CRYPTO_METHOD_TLSv1_1',
            'STREAM_CRYPTO_METHOD_TLSv1_0',
        ];

        $method = 0;

        foreach ($candidates as $name) {
            if (defined($name)) {
                $method |= constant($name);
            }
        }

        if ($method > 0) {
            return $method;
        }

        return defined('STREAM_CRYPTO_METHOD_ANY_SERVER') ? STREAM_CRYPTO_METHOD_ANY_SERVER : 0;
    }

    public function logEvent(string $level, string $message, array $context = []): void
    {
        if (!$this->loggingEnabled && $level === 'DEBUG') {
            return;
        }

        if (!$this->loggingEnabled && !in_array($level, ['WARN', 'ERROR'], true)) {
            return;
        }

        $payload = [
            'time' => date('c'),
            'level' => $level,
            'message' => $message,
        ];

        if ($context !== []) {
            $payload['context'] = $context;
        }

        fwrite(STDOUT, '[log] ' . json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    private function log(string $level, string $message, array $context): void
    {
        $this->logEvent($level, $message, $context);
    }

    private function adjustTextStreamingThreshold(): void
    {
        if (!$this->adaptiveTextStreaming) {
            return;
        }

        $active = count($this->connections);

        if ($active === 0) {
            $this->textStreamThreshold = max(1, (int)($this->textStreamThreshold * 0.9));
            return;
        }

        $usage = memory_get_usage(true);

        $targetUpper = $this->adaptiveMemoryTarget + $this->adaptiveMemoryTolerance;
        $targetLower = max(1, $this->adaptiveMemoryTarget - $this->adaptiveMemoryTolerance);

        if ($usage > $targetUpper) {
            $this->textStreamThreshold = max(1, (int)($this->textStreamThreshold * 0.8));
            $this->log('DEBUG', 'Adaptive streaming: decreased text threshold.', [
                'threshold' => $this->textStreamThreshold,
                'memory_usage' => $usage,
            ]);

            return;
        }

        if ($usage < $targetLower) {
            $this->textStreamThreshold = max(1, (int)($this->textStreamThreshold * 1.1));
            $this->log('DEBUG', 'Adaptive streaming: increased text threshold.', [
                'threshold' => $this->textStreamThreshold,
                'memory_usage' => $usage,
            ]);
        }
    }

    private function computeTlsContextHash(): ?string
    {
        if (!$this->tls || !isset($this->contextOptions['ssl'])) {
            return null;
        }

        $parts = [];

        foreach (['local_cert', 'local_pk', 'cafile', 'capath'] as $key) {
            $value = $this->contextOptions['ssl'][$key] ?? null;

            if ($value && is_string($value) && is_file($value)) {
                clearstatcache(true, $value);
                $parts[] = $value . '|' . @filemtime($value) . '|' . @filesize($value);
            }
        }

        if ($parts === []) {
            return null;
        }

        return hash('sha256', implode(';', $parts));
    }

    private function applyTlsContext($socket): void
    {
        if (!isset($this->contextOptions['ssl'])) {
            return;
        }

        foreach ($this->contextOptions['ssl'] as $key => $value) {
            if ($value === null) {
                continue;
            }
            @stream_context_set_option($socket, 'ssl', $key, $value);
        }
    }

    private function scheduleTlsReload(): void
    {
        $this->loop->addTimer($this->tlsReloadInterval, function (): void {
            $this->checkTlsReload();
        }, true);
    }

    private function checkTlsReload(): void
    {
        if (!$this->tls || !$this->tlsAutoReload) {
            return;
        }

        $hash = $this->computeTlsContextHash();

        if ($hash === null || $hash === $this->tlsContextHash) {
            return;
        }

        $this->tlsContextHash = $hash;
        $this->tlsLastReload = time();
        $this->log('INFO', 'Reloaded TLS context after certificate change.', []);

        if (is_resource($this->serverStream)) {
            $this->applyTlsContext($this->serverStream);
        }
    }

}
