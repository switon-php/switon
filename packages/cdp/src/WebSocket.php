<?php

declare(strict_types=1);

namespace Switon\Cdp;

use Throwable;

use function base64_encode;
use function chr;
use function fclose;
use function feof;
use function floor;
use function fread;
use function fwrite;
use function is_resource;
use function max;
use function microtime;
use function ord;
use function pack;
use function parse_url;
use function random_bytes;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function stream_get_meta_data;
use function stream_set_timeout;
use function stream_socket_client;
use function strlen;
use function strpos;
use function substr;
use function unpack;

use const STREAM_CLIENT_CONNECT;

/**
 * Minimal RFC6455 client for CDP (text frames).
 *
 * Role: transport only — JSON message sequencing lives in {@see Session}.
 */
class WebSocket
{
    /** @var resource */
    protected $socket;

    /** Bytes received but not yet consumed as a complete frame. */
    protected string $buffer = '';

    public function __construct(
        string $url,
        float $timeout = 30.0,
    ) {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['path'])) {
            Exception::raise('Invalid WebSocket URL "{url}".', ['url' => $url]);
        }

        $scheme = $parts['scheme'] ?? 'ws';
        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'wss' ? 443 : 80);
        $path = $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $remote = ($scheme === 'wss' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            max(1.0, $timeout),
            STREAM_CLIENT_CONNECT,
        );
        if ($socket === false) {
            Exception::raise('WebSocket connect failed for "{url}": {error}', [
                'url' => $url,
                'error' => $errstr !== '' ? $errstr : (string) $errno,
            ]);
        }

        stream_set_timeout($socket, (int) max(1, $timeout));

        $key = base64_encode(random_bytes(16));
        $headers = "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}" . ($port !== 80 && $port !== 443 ? ':' . $port : '') . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";

        if (fwrite($socket, $headers) === false) {
            fclose($socket);
            Exception::raise('WebSocket handshake write failed for "{url}".', ['url' => $url]);
        }

        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $chunk = fread($socket, 1024);
            if ($chunk === false || $chunk === '') {
                fclose($socket);
                Exception::raise('WebSocket handshake read failed for "{url}".', ['url' => $url]);
            }
            $response .= $chunk;
            if (strlen($response) > 65536) {
                fclose($socket);
                Exception::raise('WebSocket handshake response too large for "{url}".', ['url' => $url]);
            }
        }

        if (!str_starts_with($response, 'HTTP/1.1 101') && !str_starts_with($response, 'HTTP/1.0 101')) {
            fclose($socket);
            Exception::raise('WebSocket upgrade rejected for "{url}": {status}', [
                'url' => $url,
                'status' => rtrim(substr($response, 0, strpos($response, "\r\n") ?: 80)),
            ]);
        }

        $this->socket = $socket;
    }

    public function sendText(string $payload): void
    {
        $frame = $this->encodeFrame($payload);
        $written = fwrite($this->socket, $frame);
        if ($written === false || $written !== strlen($frame)) {
            Exception::raise('WebSocket send failed.');
        }
    }

    public function receiveText(float $timeout): string
    {
        $payload = $this->tryReceiveText($timeout);
        if ($payload !== null) {
            return $payload;
        }

        Exception::raise('WebSocket receive timed out after {seconds}s.', ['seconds' => $timeout]);
    }

    /** @return string|null Payload, or null on timeout / empty read. */
    public function tryReceiveText(float $timeout): ?string
    {
        $deadline = microtime(true) + max(0.05, $timeout);
        while (microtime(true) < $deadline) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }
            $sec = max(0, (int) floor($remaining));
            $usec = max(0, (int) (($remaining - $sec) * 1_000_000));
            if ($sec === 0 && $usec < 1_000) {
                $usec = 1_000;
            }
            stream_set_timeout($this->socket, $sec, $usec);

            $payload = $this->readFrame();
            if ($payload !== null) {
                return $payload;
            }
        }

        return null;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            // Close opcode 0x8, empty payload, masked.
            try {
                fwrite($this->socket, $this->encodeFrame('', 0x8));
            } catch (Throwable) {
            }
            fclose($this->socket);
        }
    }

    protected function encodeFrame(string $payload, int $opcode = 0x1): string
    {
        $len = strlen($payload);
        $head = chr(0x80 | ($opcode & 0x0f));
        $maskBit = 0x80;
        if ($len < 126) {
            $head .= chr($maskBit | $len);
        } elseif ($len <= 0xffff) {
            $head .= chr($maskBit | 126) . pack('n', $len);
        } else {
            $head .= chr($maskBit | 127) . pack('J', $len);
        }

        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        return $head . $mask . $masked;
    }

    /**
     * One complete text message, with continuation frames joined.
     *
     * A message may arrive as a first frame with `FIN=0` followed by opcode `0x0` continuations.
     * CDP normally sends single-frame JSON, but a large payload may be split.
     */
    protected function readFrame(): ?string
    {
        $payload = '';
        $text = false;

        while (true) {
            $frame = $this->readRawFrame();
            if ($frame === null) {
                return null;
            }
            [$fin, $opcode, $data] = $frame;

            if ($opcode === 0x8) {
                Exception::raise('WebSocket closed by peer.');
            }

            if ($opcode === 0x9) {
                // Ping → pong, then keep reading.
                fwrite($this->socket, $this->encodeFrame($data, 0xA));
                continue;
            }

            if ($opcode === 0xA) {
                continue;
            }

            if ($opcode === 0x0) {
                if (!$text) {
                    // Continuation without a text message in flight.
                    continue;
                }
                $payload .= $data;
            } else {
                // 0x1 text is the only payload CDP reads; 0x2 binary and reserved
                // opcodes are dropped together with their continuations.
                $text = $opcode === 0x1;
                $payload = $text ? $data : '';
            }

            if (!$fin) {
                continue;
            }

            if ($text) {
                return $payload;
            }

            $payload = '';
        }
    }

    /**
     * @return array{0: bool, 1: int, 2: string}|null FIN flag, opcode, payload.
     */
    protected function readRawFrame(): ?array
    {
        // A frame is consumed only when all of its bytes are present: a read that times out
        // mid-payload leaves the partial bytes in the buffer, so the next call resumes at the
        // same frame instead of reading payload bytes as a header.
        while (true) {
            $frame = $this->bufferedFrame();
            if ($frame !== null) {
                return $frame;
            }
            if (!$this->fillBuffer()) {
                return null;
            }
        }
    }

    /**
     * One frame from the buffer, or null when the buffer does not yet hold all of it.
     *
     * @return array{0: bool, 1: int, 2: string}|null
     */
    protected function bufferedFrame(): ?array
    {
        $available = strlen($this->buffer);
        if ($available < 2) {
            return null;
        }

        $b1 = ord($this->buffer[0]);
        $b2 = ord($this->buffer[1]);
        $fin = ($b1 & 0x80) !== 0;
        $opcode = $b1 & 0x0f;
        $masked = ($b2 & 0x80) !== 0;
        $len = $b2 & 0x7f;
        $offset = 2;

        if ($len === 126) {
            if ($available < 4) {
                return null;
            }
            $len = (int) unpack('n', substr($this->buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($len === 127) {
            if ($available < 10) {
                return null;
            }
            $len = (int) unpack('J', substr($this->buffer, 2, 8))[1];
            $offset = 10;
        }

        $maskLength = $masked ? 4 : 0;
        $total = $offset + $maskLength + $len;
        if ($available < $total) {
            return null;
        }

        $mask = $maskLength > 0 ? substr($this->buffer, $offset, 4) : '';
        $payload = $len > 0 ? substr($this->buffer, $offset + $maskLength, $len) : '';
        $this->buffer = substr($this->buffer, $total);

        if ($mask !== '') {
            $unmasked = '';
            for ($i = 0; $i < $len; $i++) {
                $unmasked .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $unmasked;
        }

        return [$fin, $opcode, $payload];
    }

    /** Append whatever the socket has, within the timeout already set on the stream. */
    protected function fillBuffer(): bool
    {
        if (feof($this->socket)) {
            return false;
        }
        if ((stream_get_meta_data($this->socket)['timed_out'] ?? false) === true) {
            return false;
        }

        $chunk = fread($this->socket, 8192);
        if ($chunk === false || $chunk === '') {
            return false;
        }
        $this->buffer .= $chunk;

        return true;
    }
}
