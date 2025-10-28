<?php

declare(strict_types=1);

namespace Websocket;

use Async\LoopInterface;
use Websocket\Exception\WebSocketException;

/**
 * Represents a single WebSocket connection managed by the server.
 */
class Connection
{

    private const STATE_CONNECTING = 'connecting';

    private const STATE_OPEN = 'open';

    private const STATE_CLOSING = 'closing';

    private const STATE_CLOSED = 'closed';

    private const WEBSOCKET_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var resource */
    private $stream;

    private string $id;

    private LoopInterface $loop;

    private Server $server;

    private string $state = self::STATE_CONNECTING;

    private string $readBuffer = '';

    private string $writeBuffer = '';

    private bool $writeWatcherActive = false;

    private bool $closeFrameSent = false;

    private bool $closeFrameReceived = false;

    private ?int $closeCode = null;

    private string $closeReason = '';

    private ?string $path = null;

    private array $headers = [];

    private array $queryParameters = [];

    private ?string $subprotocol = null;

    private ?string $label = null;

    private array $properties = [];

    private ?int $fragmentOpcode = null;

    private bool $fragmentCompressed = false;

    private int $fragmentSize = 0;

    private string $fragmentBuffer = '';

    /** @var resource|null */
    private $fragmentStream = null;

    private int $maxMessageSize;

    private int $binaryStreamThreshold;

    private int $textStreamThreshold;

    private bool $compressionAllowed;

    private bool $compressionNegotiated = false;

    private int $compressionMinBytes;

    private bool $compressionClientNoContextTakeover;

    private bool $compressionServerNoContextTakeover;

    private bool $compressionClientNoContextActive = false;

    private bool $compressionServerNoContextActive = false;

    private int $compressionClientWindowBits = 15;

    private int $compressionServerWindowBits = 15;

    /** @var resource|null */
    private $deflateContext = null;

    /** @var resource|null */
    private $inflateContext = null;

    private bool $adaptiveTextStreaming;

    private int $adaptiveMemoryTarget;

    private int $adaptiveMemoryTolerance;

    private bool $loggingEnabled;

    private float $maxDecompressionRatio;

    public function __construct(Server $server, LoopInterface $loop, $stream, array $options = [])
    {
        $this->server = $server;
        $this->loop = $loop;
        $this->stream = $stream;
        $this->id = (string)spl_object_id($this);
        $this->maxMessageSize = max(1, (int)($options['max_message_size'] ?? $server->getMaxMessageSize()));
        $this->binaryStreamThreshold = max(1, (int)($options['binary_stream_threshold'] ?? $server->getBinaryStreamThreshold()));
        $this->compressionAllowed = (bool)($options['compression_enabled'] ?? $server->isCompressionEnabled());
        $this->compressionMinBytes = max(0, (int)($options['compression_min_bytes'] ?? $server->getCompressionMinBytes()));
        $this->compressionServerNoContextTakeover = (bool)($options['compression_server_no_context_takeover'] ?? $server->isServerNoContextTakeover());
        $this->compressionClientNoContextTakeover = (bool)($options['compression_client_no_context_takeover'] ?? $server->isClientNoContextTakeover());
        $this->textStreamThreshold = max(1, (int)($options['text_stream_threshold'] ?? $server->getTextStreamThreshold()));
        $this->adaptiveTextStreaming = (bool)($options['adaptive_text_streaming'] ?? false);
        $this->adaptiveMemoryTarget = max(1, (int)($options['adaptive_memory_target'] ?? (64 * 1024 * 1024)));
        $this->adaptiveMemoryTolerance = max(1, (int)($options['adaptive_memory_tolerance'] ?? (8 * 1024 * 1024)));
        $this->loggingEnabled = (bool)($options['logging'] ?? false);
        $this->maxDecompressionRatio = max(1.0, (float)($options['max_decompression_ratio'] ?? 20.0));
        if (isset($options['connection_label'])) {
            $this->label = (string)$options['connection_label'];
        }
        if (isset($options['connection_properties']) && is_array($options['connection_properties'])) {
            $this->properties = $options['connection_properties'];
        }
        stream_set_blocking($this->stream, false);

        if ($this->adaptiveTextStreaming) {
            $this->scheduleAdaptiveCheck();
        }
    }

    /**
     * Returns the underlying stream resource.
     *
     * @return resource
     */
    public function getStream()
    {
        return $this->stream;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setProperty(string $name, mixed $value): void
    {
        $this->properties[$name] = $value;
    }

    public function getProperty(string $name, mixed $default = null): mixed
    {
        return $this->properties[$name] ?? $default;
    }

    public function hasProperty(string $name): bool
    {
        return array_key_exists($name, $this->properties);
    }

    public function removeProperty(string $name): void
    {
        unset($this->properties[$name]);
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        $lower = strtolower($name);

        return $this->headers[$lower] ?? null;
    }

    public function getQueryParameters(): array
    {
        return $this->queryParameters;
    }

    public function getQueryParameter(string $name, mixed $default = null): mixed
    {
        return $this->queryParameters[$name] ?? $default;
    }

    public function getSubprotocol(): ?string
    {
        return $this->subprotocol;
    }

    public function isOpen(): bool
    {
        return $this->state === self::STATE_OPEN;
    }

    public function isClosing(): bool
    {
        return $this->state === self::STATE_CLOSING;
    }

    public function sendText(string $message): void
    {
        $this->send($message, Frame::OPCODE_TEXT);
    }

    public function sendBinary(string $payload): void
    {
        $this->send($payload, Frame::OPCODE_BINARY);
    }

    public function ping(string $payload = ''): void
    {
        if ($this->state !== self::STATE_OPEN) {
            return;
        }

        if (strlen($payload) > 125) {
            throw new WebSocketException('Ping payload must not exceed 125 bytes.');
        }

        $frame = Frame::encode($payload, Frame::OPCODE_PING);
        $this->queueWrite($frame);
    }

    public function close(int $code = Frame::CLOSE_NORMAL, string $reason = ''): void
    {
        if ($this->state === self::STATE_CLOSED) {
            return;
        }

        if ($this->state === self::STATE_CONNECTING) {
            $this->finalizeClose($code, $reason);
            return;
        }

        if (!$this->closeFrameSent) {
            $reason = substr($reason, 0, 123);
            $payload = pack('n', $code) . $reason;
            $frame = Frame::encode($payload, Frame::OPCODE_CLOSE);
            $this->queueWrite($frame);
            $this->closeFrameSent = true;
            $this->closeCode = $code;
            $this->closeReason = $reason;
        }

        $this->state = self::STATE_CLOSING;

        if ($this->writeBuffer === '' && $this->closeFrameReceived) {
            $this->finalizeClose($this->closeCode ?? $code, $this->closeReason);
        }
    }

    /**
     * Internal handler invoked when the stream becomes readable.
     */
    public function handleRead(): void
    {
        if ($this->state === self::STATE_CLOSED) {
            return;
        }

        $data = @stream_get_contents($this->stream);

        if ($data === false) {
            $this->finalizeClose(Frame::CLOSE_ABNORMAL, 'Client read failure');

            return;
        }

        if ($data === '') {
            if (!is_resource($this->stream) || feof($this->stream)) {
                $this->finalizeClose(Frame::CLOSE_ABNORMAL, 'Client disconnected');
            }

            return;
        }

        $this->readBuffer .= $data;

        if ($this->state === self::STATE_CONNECTING) {
            if (strlen($this->readBuffer) >= 3 && strncmp($this->readBuffer, 'GET', 3) !== 0) {
                $this->finalizeClose(Frame::CLOSE_PROTOCOL_ERROR, 'Invalid handshake data.');

                return;
            }
            if (!$this->processHandshakeBuffer()) {
                return;
            }
        }

        if ($this->state === self::STATE_OPEN) {
            $this->processFrameBuffer();
        }
    }

    /**
     * Internal handler invoked when the stream becomes writable.
     */
    public function handleWrite(): void
    {
        $this->flushWriteBuffer();
    }

    private function send(string $payload, int $opcode): void
    {
        if ($this->state !== self::STATE_OPEN) {
            throw new WebSocketException('Cannot send data on a closed connection.');
        }

        if ($opcode === Frame::OPCODE_TEXT && !$this->isValidUtf8($payload)) {
            throw new WebSocketException('Text frame payload must be valid UTF-8.');
        }

        $payloadLengthOriginal = strlen($payload);
        $compressed = false;

        if ($this->compressionNegotiated && $this->shouldCompressPayload($opcode, $payload)) {
            $payload = $this->compressPayload($payload);
            $compressed = true;
        }

        $this->server->recordSentMessage($payloadLengthOriginal);
        $frame = Frame::encode($payload, $opcode, true, $compressed);
        $this->queueWrite($frame);
    }

    private function queueWrite(string $data): void
    {
        $this->writeBuffer .= $data;
        $this->flushWriteBuffer();

        if ($this->writeBuffer !== '' && !$this->writeWatcherActive) {
            $this->loop->addWriteStream($this->stream, function ($stream): void {
                $this->handleWrite();
            });
            $this->writeWatcherActive = true;
        }
    }

    private function flushWriteBuffer(): void
    {
        if ($this->writeBuffer === '') {
            $this->deactivateWriteWatcher();

            if ($this->state === self::STATE_CLOSING && $this->closeFrameReceived) {
                $this->finalizeClose($this->closeCode ?? Frame::CLOSE_NORMAL, $this->closeReason);

            }

            return;
        }

        $written = @fwrite($this->stream, $this->writeBuffer);

        if ($written === false) {
            $this->server->notifyError($this, new WebSocketException('Failed to write to stream.'));
            $this->finalizeClose(Frame::CLOSE_ABNORMAL, 'Write failure');

            return;
        }

        if ($written === 0) {
            return;
        }

        if ($written >= strlen($this->writeBuffer)) {
            $this->writeBuffer = '';
        } else {
            $this->writeBuffer = (string)substr($this->writeBuffer, $written);
        }

        if ($this->writeBuffer === '') {
            $this->deactivateWriteWatcher();

            if ($this->state === self::STATE_CLOSING && $this->closeFrameReceived) {
                $this->finalizeClose($this->closeCode ?? Frame::CLOSE_NORMAL, $this->closeReason);
            }
        } elseif (!$this->writeWatcherActive) {
            $this->loop->addWriteStream($this->stream, function ($stream): void {
                $this->handleWrite();
            });
            $this->writeWatcherActive = true;
        }
    }

    private function deactivateWriteWatcher(): void
    {
        if ($this->writeWatcherActive) {
            $this->loop->removeWriteStream($this->stream);
            $this->writeWatcherActive = false;
        }
    }

    private function processHandshakeBuffer(): bool
    {
        $separatorPos = strpos($this->readBuffer, "\r\n\r\n");

        if ($separatorPos === false) {
            return false;
        }

        $headerBlob = substr($this->readBuffer, 0, $separatorPos);
        $this->readBuffer = (string)substr($this->readBuffer, $separatorPos + 4);

        $headerLines = explode("\r\n", $headerBlob);
        $requestLine = array_shift($headerLines);

        if ($requestLine === null || !preg_match('#^GET\s+(\S+)\s+HTTP/1\.[01]$#i', $requestLine, $matches)) {
            $this->sendHttpError(400, 'Bad Request');
            $this->finalizeClose(Frame::CLOSE_PROTOCOL_ERROR, 'Invalid HTTP request line');

            return false;
        }

        $target = $matches[1];
        $parts = @parse_url($target);
        $this->path = '/';
        $this->queryParameters = [];

        if (is_array($parts)) {
            $this->path = $parts['path'] ?? '/';

            if (isset($parts['query'])) {
                parse_str($parts['query'], $this->queryParameters);
            }
        } elseif (is_string($target) && str_starts_with($target, '/')) {
            $this->path = strtok($target, '?') ?: '/';
            $query = strstr($target, '?');

            if ($query !== false) {
                parse_str(substr($query, 1), $this->queryParameters);
            }
        }

        $headers = [];

        foreach ($headerLines as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        $this->headers = $headers;

        if (!$this->validateHandshakeHeaders($headers)) {
            $this->sendHttpError(400, 'Bad Request');
            $this->finalizeClose(Frame::CLOSE_PROTOCOL_ERROR, 'Invalid handshake headers');

            return false;
        }

        if ($this->server->shouldRequireOrigin() && !isset($headers['origin'])) {
            $this->sendHttpError(403, 'Forbidden');
            $this->finalizeClose(Frame::CLOSE_POLICY_VIOLATION, 'Missing Origin header');

            return false;
        }

        $origin = $headers['origin'] ?? null;

        if (!$this->server->isOriginAllowed($origin)) {
            $this->sendHttpError(403, 'Forbidden');
            $this->finalizeClose(Frame::CLOSE_POLICY_VIOLATION, 'Origin not "' . $origin . '" allowed');

            return false;
        }

        if (!$this->server->validateAuth($this, $headers, $this->queryParameters)) {
            $this->sendHttpError(401, 'Unauthorized');
            $this->finalizeClose(Frame::CLOSE_POLICY_VIOLATION, 'Unauthorized');

            return false;
        }

        $requestedProtocols = [];
        if (isset($headers['sec-websocket-protocol'])) {
            $requestedProtocols = array_values(array_filter(array_map('trim', explode(',', $headers['sec-websocket-protocol']))));
        }

        $extensions = $this->negotiateExtensions($headers);

        try {
            $this->subprotocol = $this->server->selectSubprotocol($this, $requestedProtocols);
        } catch (\Throwable $exception) {
            $this->sendHttpError(400, 'Bad Request');
            $this->server->notifyError($this, $exception);
            $this->finalizeClose(Frame::CLOSE_PROTOCOL_ERROR, 'Subprotocol negotiation failed.');

            return false;
        }

        $this->sendHandshakeResponse($headers['sec-websocket-key'], $this->subprotocol, $extensions);
        $this->state = self::STATE_OPEN;
        $this->server->notifyOpen($this);

        if ($this->readBuffer !== '') {
            $this->processFrameBuffer();
        }

        return true;
    }

    private function validateHandshakeHeaders(array $headers): bool
    {
        if (!isset($headers['host'], $headers['upgrade'], $headers['connection'], $headers['sec-websocket-key'], $headers['sec-websocket-version'])) {
            return false;
        }

        if (strcasecmp($headers['upgrade'], 'websocket') !== 0) {
            return false;
        }

        if (stripos($headers['connection'], 'upgrade') === false) {
            return false;
        }

        if (trim($headers['sec-websocket-version']) !== '13') {
            return false;
        }

        $decodedKey = base64_decode($headers['sec-websocket-key'], true);

        if ($decodedKey === false || strlen($decodedKey) !== 16) {
            return false;
        }

        return true;
    }

    private function negotiateExtensions(array $headers): array
    {
        $this->compressionNegotiated = false;
        $this->compressionClientNoContextActive = false;
        $this->compressionServerNoContextActive = false;
        $this->compressionClientWindowBits = 15;
        $this->compressionServerWindowBits = 15;
        $this->deflateContext = null;
        $this->inflateContext = null;

        if (!$this->compressionAllowed || !isset($headers['sec-websocket-extensions'])) {
            return [];
        }

        if (!function_exists('deflate_init') || !function_exists('inflate_init')) {
            return [];
        }

        $offers = array_map('trim', explode(',', $headers['sec-websocket-extensions']));

        foreach ($offers as $offer) {
            if ($offer === '') {
                continue;
            }

            $parts = array_map('trim', explode(';', $offer));
            $name = strtolower(array_shift($parts));

            if ($name !== 'permessage-deflate') {
                continue;
            }

            $this->compressionNegotiated = true;
            $responseParts = ['permessage-deflate'];

            $clientWindowBits = 15;
            $serverWindowBits = 15;

            $acceptClientNoContext = $this->compressionClientNoContextTakeover
                || $this->hasExtensionParameter($parts, 'client_no_context_takeover');

            if ($acceptClientNoContext) {
                $responseParts[] = 'client_no_context_takeover';
            }

            $acceptServerNoContext = $this->compressionServerNoContextTakeover
                || $this->hasExtensionParameter($parts, 'server_no_context_takeover');

            if ($acceptServerNoContext) {
                $responseParts[] = 'server_no_context_takeover';
            }

            foreach ($parts as $param) {
                $paramLower = strtolower($param);

                if (str_starts_with($paramLower, 'client_max_window_bits=')) {
                    $responseParts[] = $param;
                    $clientWindowBits = $this->sanitizeWindowBits((int)substr($paramLower, strlen('client_max_window_bits=')));

                    continue;
                }

                if ($paramLower === 'client_max_window_bits') {
                    $clientWindowBits = 15;
                    $responseParts[] = 'client_max_window_bits=15';

                    continue;
                }

                if (str_starts_with($paramLower, 'server_max_window_bits=')) {
                    $responseParts[] = $param;
                    $serverWindowBits = $this->sanitizeWindowBits((int)substr($paramLower, strlen('server_max_window_bits=')));

                    continue;
                }

                if ($paramLower === 'server_max_window_bits') {
                    $serverWindowBits = $this->sanitizeWindowBits($this->compressionServerWindowBits);
                    $responseParts[] = 'server_max_window_bits=' . $serverWindowBits;
                }
            }

            $this->compressionClientNoContextActive = $acceptClientNoContext;
            $this->compressionServerNoContextActive = $acceptServerNoContext;
            $this->compressionClientWindowBits = $clientWindowBits;
            $this->compressionServerWindowBits = $serverWindowBits;

            return [implode('; ', array_unique($responseParts))];
        }

        return [];
    }

    private function hasExtensionParameter(array $parameters, string $expected): bool
    {
        $expectedLower = strtolower($expected);

        foreach ($parameters as $parameter) {
            if (strtolower($parameter) === $expectedLower) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeWindowBits(int $bits): int
    {
        if ($bits < 8 || $bits > 15) {
            return 15;
        }

        return $bits;
    }

    private function sendHandshakeResponse(string $clientKey, ?string $subprotocol, array $extensions = []): void
    {
        $accept = base64_encode(sha1($clientKey . self::WEBSOCKET_GUID, true));

        $headers = [
            'HTTP/1.1 101 Switching Protocols',
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Accept: ' . $accept,
        ];

        if ($subprotocol !== null) {
            $headers[] = 'Sec-WebSocket-Protocol: ' . $subprotocol;
        }

        if ($extensions !== []) {
            $headers[] = 'Sec-WebSocket-Extensions: ' . implode(', ', $extensions);
        }

        $response = implode("\r\n", $headers) . "\r\n\r\n";
        $this->queueWrite($response);
    }

    private function sendHttpError(int $code, string $reason): void
    {
        $body = $code . ' ' . $reason;
        $response = sprintf(
            "HTTP/1.1 %d %s\r\nConnection: close\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: %d\r\n\r\n%s",
            $code,
            $reason,
            strlen($body),
            $body
        );

        $this->queueWrite($response);
    }

    private function processFrameBuffer(): void
    {
        while ($this->state === self::STATE_OPEN) {
            $frame = Frame::tryParse($this->readBuffer);

            if ($frame === null) {
                break;
            }

            try {
                $this->handleFrame($frame);
            } catch (\Throwable $exception) {
                $this->server->notifyError($this, $exception);
                $this->close(Frame::CLOSE_INTERNAL_ERROR, 'Unhandled exception');
                break;
            }
        }
    }

    private function handleFrame(Frame $frame): void
    {
        if (!$frame->masked) {
            throw new WebSocketException('Client frames must be masked.');
        }

        if ($frame->rsv2 || $frame->rsv3) {
            throw new WebSocketException('Unsupported reserved bits set.');
        }

        $isControl = in_array($frame->opcode, [Frame::OPCODE_CLOSE, Frame::OPCODE_PING, Frame::OPCODE_PONG], true);

        if ($isControl) {
            if (!$frame->fin) {
                throw new WebSocketException('Control frames must not be fragmented.');
            }

            if ($frame->rsv1) {
                throw new WebSocketException('Control frames must not be compressed.');
            }

            if (strlen($frame->payload) > 125) {
                throw new WebSocketException('Control frame payload too large.');
            }
        }

        if ($frame->rsv1 && !$this->compressionNegotiated) {
            throw new WebSocketException('Unexpected RSV1 bit without negotiated compression.');
        }

        $compressed = $frame->rsv1 && $this->compressionNegotiated;

        if (!$isControl && !$frame->fin && $frame->opcode !== Frame::OPCODE_CONTINUATION) {
            $this->startFragmentedMessage($frame->opcode, $compressed);
            $this->appendFragmentPayload($frame->opcode, $frame->payload);

            return;
        }

        if ($frame->opcode === Frame::OPCODE_CONTINUATION) {
            if ($this->fragmentOpcode === null) {
                return; // Ignore stray continuation frames (likely after an error).
            }

            if ($frame->rsv1) {
                throw new WebSocketException('Continuation frames must not set RSV1.');
            }

            $this->appendFragmentPayload($this->fragmentOpcode, $frame->payload);

            if ($frame->fin) {
                $this->finalizeFragmentedMessage();
            }

            return;
        }

        if (!$frame->fin && !$isControl) {
            throw new WebSocketException('Fragmented messages must use continuation opcode.');
        }

        switch ($frame->opcode) {
            case Frame::OPCODE_TEXT:
            case Frame::OPCODE_BINARY:
                $this->deliverMessage($frame->opcode, $frame->payload, $compressed);
                break;

            case Frame::OPCODE_PING:
                $this->queueWrite(Frame::encode($frame->payload, Frame::OPCODE_PONG));
                $this->server->notifyPing($this, $frame->payload);
                break;

            case Frame::OPCODE_PONG:
                $this->server->notifyPong($this, $frame->payload);
                break;

            case Frame::OPCODE_CLOSE:
                $this->closeFrameReceived = true;
                $code = $frame->closeCode ?? Frame::CLOSE_NORMAL;
                $reason = $frame->payload;

                if (!$this->isValidUtf8($reason)) {
                    $this->close(Frame::CLOSE_INVALID_PAYLOAD, 'Invalid close reason payload.');
                    return;
                }

                if (!$this->closeFrameSent) {
                    $this->close($code, $reason);
                } else {
                    $this->closeCode ??= $code;
                    $this->closeReason = $reason;
                }

                if ($this->writeBuffer === '') {
                    $this->finalizeClose($code, $reason);
                }
                break;

            default:
                $this->close(Frame::CLOSE_PROTOCOL_ERROR, 'Unsupported opcode');
        }
    }

    private function deliverMessage(int $opcode, string $payload, bool $compressed): void
    {
        if (!$compressed && strlen($payload) > $this->maxMessageSize) {
            $this->close(Frame::CLOSE_MESSAGE_TOO_BIG, 'Message exceeds maximum size.');

            return;
        }

        if ($compressed) {
            $payload = $this->decompressPayload($payload);
        }

        if (strlen($payload) > $this->maxMessageSize) {
            $this->close(Frame::CLOSE_MESSAGE_TOO_BIG, 'Message exceeds maximum size.');

            return;
        }

        $isText = $opcode === Frame::OPCODE_TEXT;

        if ($isText) {
            if (!$this->isValidUtf8($payload)) {
                $this->close(Frame::CLOSE_INVALID_PAYLOAD, 'Invalid UTF-8 payload.');

                return;
            }

            $this->server->notifyMessage($this, $payload, true);

            return;
        }

        if ($opcode === Frame::OPCODE_BINARY) {
            $payloadLength = strlen($payload);

            if ($payloadLength > $this->binaryStreamThreshold) {
                $stream = fopen('php://temp', 'w+b');

                if (!is_resource($stream)) {
                    throw new WebSocketException('Failed to create temporary stream for binary payload.');
                }

                $written = fwrite($stream, $payload);

                if ($written === false || $written < $payloadLength) {
                    fclose($stream);
                    throw new WebSocketException('Failed to buffer binary payload.');
                }

                rewind($stream);
                $this->server->notifyBinaryStream($this, $stream, $payloadLength);

                return;
            }

            $this->server->notifyMessage($this, $payload, false);

            return;
        }
    }

    private function shouldCompressPayload(int $opcode, string $payload): bool
    {
        if (!$this->compressionNegotiated) {
            return false;
        }

        if ($opcode !== Frame::OPCODE_TEXT && $opcode !== Frame::OPCODE_BINARY) {
            return false;
        }

        if (strlen($payload) < $this->compressionMinBytes) {
            return false;
        }

        return function_exists('deflate_init') && function_exists('deflate_add');
    }

    /**
     * @return resource
     */
    private function createDeflateContext()
    {
        $context = @deflate_init(ZLIB_ENCODING_RAW, [
            'window' => $this->compressionServerWindowBits,
        ]);

        if ($context === false) {
            throw new WebSocketException('Unable to initialize compression context.');
        }

        return $context;
    }

    private function compressPayload(string $payload): string
    {
        $context = $this->compressionServerNoContextActive
            ? $this->createDeflateContext()
            : ($this->deflateContext ??= $this->createDeflateContext());

        $compressed = @deflate_add($context, $payload, ZLIB_SYNC_FLUSH);

        if ($compressed === false) {
            throw new WebSocketException('Failed to compress payload.');
        }

        if (strlen($compressed) >= 4) {
            $compressed = substr($compressed, 0, -4);
        }

        if ($this->compressionServerNoContextActive) {
            $this->deflateContext = null;
        }

        return $compressed;
    }

    /**
     * @return resource
     */
    private function createInflateContext()
    {
        $context = @inflate_init(ZLIB_ENCODING_RAW, [
            'window' => $this->compressionClientWindowBits,
        ]);

        if ($context === false) {
            throw new WebSocketException('Unable to initialize decompression context.');
        }

        return $context;
    }

    private function decompressPayload(string $payload): string
    {
        $context = $this->compressionClientNoContextActive
            ? $this->createInflateContext()
            : ($this->inflateContext ??= $this->createInflateContext());

        $data = @inflate_add($context, $payload . "\x00\x00\xFF\xFF", ZLIB_SYNC_FLUSH);

        if ($data === false) {
            throw new WebSocketException('Failed to decompress payload.');
        }

        $compressedLength = max(1, strlen($payload));
        if (strlen($data) > $this->maxDecompressionRatio * $compressedLength) {
            $this->log('WARN', 'Compressed payload ratio exceeded.', [
                'compressed' => $compressedLength,
                'decompressed' => strlen($data),
            ]);
            $this->close(Frame::CLOSE_POLICY_VIOLATION, 'Compressed payload ratio exceeded.');
            throw new WebSocketException('Compressed payload ratio exceeded.');
        }

        if ($this->compressionClientNoContextActive) {
            $this->inflateContext = null;
        }

        return $data;
    }

    private function startFragmentedMessage(int $opcode, bool $compressed): void
    {
        if (is_resource($this->fragmentStream)) {
            fclose($this->fragmentStream);
        }

        $this->fragmentOpcode = $opcode;
        $this->fragmentCompressed = $compressed;
        $this->fragmentSize = 0;
        $this->fragmentBuffer = '';
        $this->fragmentStream = null;
    }

    private function appendFragmentPayload(int $opcode, string $payload): void
    {
        $chunkLength = strlen($payload);
        $this->fragmentSize += $chunkLength;

        if ($this->fragmentSize > $this->maxMessageSize) {
            $this->close(Frame::CLOSE_MESSAGE_TOO_BIG, 'Message exceeds maximum size.');
            $this->fragmentOpcode = null;
            $this->fragmentBuffer = '';

            if (is_resource($this->fragmentStream)) {
                fclose($this->fragmentStream);
            }

            $this->fragmentStream = null;

            return;
        }

        $isBinary = $opcode === Frame::OPCODE_BINARY;
        $isText = $opcode === Frame::OPCODE_TEXT;

        if ($this->fragmentCompressed) {
            $this->fragmentBuffer .= $payload;

            return;
        }

        $threshold = $isBinary ? $this->binaryStreamThreshold : $this->textStreamThreshold;

        if ($this->fragmentStream === null && $this->fragmentSize > $threshold && ($isBinary || $isText)) {
            $stream = fopen('php://temp', 'w+b');

            if (!is_resource($stream)) {
                throw new WebSocketException('Failed to create temporary stream for fragmented payload.');
            }

            if ($this->fragmentBuffer !== '') {
                $written = fwrite($stream, $this->fragmentBuffer);

                if ($written === false || $written < strlen($this->fragmentBuffer)) {
                    fclose($stream);

                    throw new WebSocketException('Failed to buffer existing fragments.');
                }
            }

            $this->fragmentBuffer = '';
            $this->fragmentStream = $stream;
        }

        if ($this->fragmentStream !== null) {
            $written = fwrite($this->fragmentStream, $payload);

            if ($written === false || $written < $chunkLength) {
                throw new WebSocketException('Failed to buffer fragmented payload.');
            }

            return;
        }

        $this->fragmentBuffer .= $payload;
    }

    private function finalizeFragmentedMessage(): void
    {
        $opcode = $this->fragmentOpcode;
        $compressed = $this->fragmentCompressed;
        $size = $this->fragmentSize;
        $buffer = $this->fragmentBuffer;
        $stream = $this->fragmentStream;

        $this->fragmentOpcode = null;
        $this->fragmentCompressed = false;
        $this->fragmentSize = 0;
        $this->fragmentBuffer = '';
        $this->fragmentStream = null;

        if ($opcode === null) {
            return;
        }

        if ($compressed) {
            $this->deliverMessage($opcode, $buffer, true);

            return;
        }

        if (is_resource($stream)) {
            rewind($stream);
            $this->deliverBinaryStream($opcode, $stream, $size);

            return;
        }

        $this->deliverMessage($opcode, $buffer, false);
    }

    private function deliverBinaryStream(int $opcode, $stream, int $length): void
    {
        if ($opcode !== Frame::OPCODE_BINARY) {
            $data = stream_get_contents($stream);
            fclose($stream);

            if ($data === false) {
                throw new WebSocketException('Failed to read buffered payload.');
            }

            $this->deliverMessage($opcode, $data, false);

            return;
        }

        if ($length > $this->maxMessageSize) {
            fclose($stream);
            $this->close(Frame::CLOSE_MESSAGE_TOO_BIG, 'Message exceeds maximum size.');

            return;
        }

        $this->server->notifyBinaryStream($this, $stream, $length);
    }

    private function finalizeClose(int $code, string $reason): void
    {
        if ($this->state === self::STATE_CLOSED) {
            return;
        }

        $this->state = self::STATE_CLOSED;
        $this->closeCode = $code;
        $this->closeReason = $reason;

        if (is_resource($this->fragmentStream)) {
            fclose($this->fragmentStream);
        }

        $this->fragmentStream = null;
        $this->fragmentBuffer = '';
        $this->fragmentSize = 0;
        $this->fragmentOpcode = null;
        $this->fragmentCompressed = false;

        $this->deflateContext = null;
        $this->inflateContext = null;

        $this->deactivateWriteWatcher();
        $this->loop->removeReadStream($this->stream);

        if (is_resource($this->stream)) {
            @stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
            fclose($this->stream);
        }

        $this->server->notifyClose($this, $code, $reason);
    }

    private function isValidUtf8(string $payload): bool
    {
        if ($payload === '') {
            return true;
        }

        if (function_exists('mb_check_encoding')) {
            if (mb_check_encoding($payload, 'UTF-8')) {
                return true;
            }
        }

        return (bool)preg_match('//u', $payload);
    }

    private function scheduleAdaptiveCheck(): void
    {
        $this->loop->addTimer(15.0, function (): void {
            if ($this->state !== self::STATE_OPEN || !$this->adaptiveTextStreaming) {
                return;
            }

            $usage = memory_get_usage(true);
            $upper = $this->adaptiveMemoryTarget + $this->adaptiveMemoryTolerance;
            $lower = max(1, $this->adaptiveMemoryTarget - $this->adaptiveMemoryTolerance);

            if ($usage > $upper) {
                $old = $this->textStreamThreshold;
                $this->textStreamThreshold = max(1, (int)($this->textStreamThreshold * 0.85));
                $this->log('DEBUG', 'Adaptive text threshold decreased.', [
                    'old' => $old,
                    'new' => $this->textStreamThreshold,
                    'memory_usage' => $usage,
                ]);
            } elseif ($usage < $lower) {
                $old = $this->textStreamThreshold;
                $this->textStreamThreshold = max(1, (int)($this->textStreamThreshold * 1.05));
                $this->log('DEBUG', 'Adaptive text threshold increased.', [
                    'old' => $old,
                    'new' => $this->textStreamThreshold,
                    'memory_usage' => $usage,
                ]);
            }

            $this->scheduleAdaptiveCheck();
        });
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if (!$this->loggingEnabled && !in_array($level, ['WARN', 'ERROR'], true)) {
            return;
        }

        $context['connection_id'] = spl_object_id($this);
        $this->server->logEvent($level, $message, $context);
    }

}
