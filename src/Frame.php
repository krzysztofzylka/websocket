<?php

declare(strict_types=1);

namespace Websocket;

use Websocket\Exception\WebSocketException;

/**
 * Represents a single WebSocket frame and provides helpers
 * for encoding and decoding according to RFC 6455.
 */
final class Frame
{

    public const OPCODE_CONTINUATION = 0x0;

    public const OPCODE_TEXT = 0x1;

    public const OPCODE_BINARY = 0x2;

    public const OPCODE_CLOSE = 0x8;

    public const OPCODE_PING = 0x9;

    public const OPCODE_PONG = 0xA;

    public const CLOSE_NORMAL = 1000;

    public const CLOSE_GOING_AWAY = 1001;

    public const CLOSE_PROTOCOL_ERROR = 1002;

    public const CLOSE_UNSUPPORTED_DATA = 1003;

    public const CLOSE_NO_STATUS = 1005;

    public const CLOSE_ABNORMAL = 1006;

    public const CLOSE_INVALID_PAYLOAD = 1007;

    public const CLOSE_POLICY_VIOLATION = 1008;

    public const CLOSE_MESSAGE_TOO_BIG = 1009;

    public const CLOSE_MANDATORY_EXTENSION = 1010;

    public const CLOSE_INTERNAL_ERROR = 1011;

    public int $opcode;

    public bool $fin;

    public bool $rsv1;

    public bool $rsv2;

    public bool $rsv3;

    public string $payload;

    public bool $masked;

    public ?int $closeCode;

    public function __construct(
        int     $opcode,
        bool    $fin,
        string  $payload,
        bool    $masked = false,
        ?int    $closeCode = null,
        bool    $rsv1 = false,
        bool    $rsv2 = false,
        bool    $rsv3 = false
    )
    {
        $this->opcode = $opcode;
        $this->fin = $fin;
        $this->payload = $payload;
        $this->masked = $masked;
        $this->closeCode = $closeCode;
        $this->rsv1 = $rsv1;
        $this->rsv2 = $rsv2;
        $this->rsv3 = $rsv3;
    }

    /**
     * Encodes payload into a WebSocket frame for server-to-client transmission.
     */
    public static function encode(
        string $payload,
        int    $opcode,
        bool   $fin = true,
        bool   $rsv1 = false,
        bool   $rsv2 = false,
        bool   $rsv3 = false
    ): string
    {
        $firstByte = ($fin ? 0x80 : 0x00)
            | ($rsv1 ? 0x40 : 0x00)
            | ($rsv2 ? 0x20 : 0x00)
            | ($rsv3 ? 0x10 : 0x00)
            | ($opcode & 0x0F);
        $payloadLen = strlen($payload);
        $header = chr($firstByte);

        if ($payloadLen < 126) {
            $header .= chr($payloadLen);
        } elseif ($payloadLen <= 0xFFFF) {
            $header .= chr(126) . pack('n', $payloadLen);
        } else {
            $high = intdiv($payloadLen, 0x100000000);
            $low = $payloadLen & 0xFFFFFFFF;
            $header .= chr(127) . pack('N', $high) . pack('N', $low);
        }

        return $header . $payload;
    }

    /**
     * Try to parse a frame from the buffer.
     *
     * @param string $buffer Reference to the connection receive buffer.
     * @return Frame|null Parsed frame or null if the buffer does not yet contain a complete frame.
     */
    public static function tryParse(string &$buffer): ?self
    {
        $bufferLength = strlen($buffer);

        if ($bufferLength < 2) {
            return null;
        }

        $offset = 0;
        $byte1 = ord($buffer[$offset++]);
        $byte2 = ord($buffer[$offset++]);

        $fin = (bool)($byte1 & 0x80);
        $rsv1 = (bool)($byte1 & 0x40);
        $rsv2 = (bool)($byte1 & 0x20);
        $rsv3 = (bool)($byte1 & 0x10);
        $opcode = $byte1 & 0x0F;
        $masked = (bool)($byte2 & 0x80);
        $payloadLen = $byte2 & 0x7F;

        if ($payloadLen === 126) {
            if ($bufferLength < $offset + 2) {
                return null;
            }

            $extended = substr($buffer, $offset, 2);
            $payloadLen = current(unpack('n', $extended));
            $offset += 2;
        } elseif ($payloadLen === 127) {
            if ($bufferLength < $offset + 8) {
                return null;
            }

            $extended = substr($buffer, $offset, 8);
            $payloadLen = self::decodeBigEndianUint64($extended);
            $offset += 8;
        }

        if ($payloadLen < 0) {
            throw new WebSocketException('Invalid payload length.');
        }

        $maskKey = '';
        if ($masked) {
            if ($bufferLength < $offset + 4) {
                return null;
            }

            $maskKey = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if ($bufferLength < $offset + $payloadLen) {
            return null;
        }

        $payload = substr($buffer, $offset, $payloadLen);
        $offset += $payloadLen;

        if ($masked) {
            $payload = self::applyMask($payload, $maskKey);
        }

        $closeCode = null;

        if ($opcode === self::OPCODE_CLOSE && $payloadLen >= 2) {
            $closeCode = current(unpack('n', substr($payload, 0, 2)));
            $payload = substr($payload, 2);
        }

        $buffer = (string)substr($buffer, $offset);

        return new self($opcode, $fin, $payload, $masked, $closeCode, $rsv1, $rsv2, $rsv3);
    }

    private static function applyMask(string $payload, string $maskKey): string
    {
        $maskBytes = array_map('ord', str_split($maskKey));
        $unmasked = '';
        $length = strlen($payload);

        for ($i = 0; $i < $length; $i++) {
            $unmasked .= chr(ord($payload[$i]) ^ $maskBytes[$i % 4]);
        }

        return $unmasked;
    }

    private static function decodeBigEndianUint64(string $binary): int
    {
        $parts = unpack('N2', $binary);

        if ($parts === false) {
            throw new WebSocketException('Unable to decode 64-bit payload length.');
        }

        [$hi, $lo] = array_values($parts);

        if ($hi > (PHP_INT_MAX >> 32)) {
            throw new WebSocketException('Payload length exceeds platform integer size.');
        }

        return ($hi << 32) | $lo;
    }

}
