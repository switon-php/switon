#!/usr/bin/env php
<?php

/**
 * Minimal SMTP listener for integration tests (one client per process).
 *
 * Env:
 * - FAKE_SMTP_MODE: plain | auth_plain | auth_login | starttls_missing | starttls_broken (default plain)
 * - EXPECT_SMTP_USER / EXPECT_SMTP_PASS: credentials for auth modes
 * - FAKE_SMTP_DATA_OUT: optional path; raw DATA payload (dot-stuffed) is written after the terminating dot
 *
 * Stdout line 1: TCP port (decimal). Exit 0 on successful session.
 *
 * @internal
 */

/**
 * @param resource $stream
 */
function smtp_read_line($stream): ?string
{
    $line = fgets($stream);
    if ($line === false) {
        return null;
    }

    return rtrim($line, "\r\n");
}

/**
 * @param resource $stream
 */
function smtp_write($stream, string $data): void
{
    fwrite($stream, $data);
    fflush($stream);
}

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "stream_socket_server failed: {$errstr}\n");
    exit(1);
}

$name = stream_socket_get_name($server, false);
if ($name === false || preg_match('/:(\d+)$/', $name, $m) !== 1) {
    fwrite(STDERR, "could not parse bind address\n");
    exit(1);
}

fwrite(STDOUT, $m[1] . "\n");
fflush(STDOUT);

$conn = stream_socket_accept($server, 120.0);
fclose($server);
if ($conn === false) {
    exit(2);
}

smtp_write($conn, "220 fake-smtp ready\r\n");

$line = smtp_read_line($conn);
if ($line === null) {
    exit(3);
}
$hello = strtoupper($line);
if (!str_starts_with($hello, 'EHLO') && !str_starts_with($hello, 'HELO')) {
    exit(3);
}

$mode = getenv('FAKE_SMTP_MODE') ?: 'plain';
$mode = strtolower($mode);

if ($mode === 'auth_plain') {
    smtp_write($conn, "250-AUTH PLAIN\r\n250 OK\r\n");
} elseif ($mode === 'auth_login') {
    smtp_write($conn, "250-AUTH LOGIN\r\n250 OK\r\n");
} elseif ($mode === 'starttls_broken') {
    smtp_write($conn, "250-PIPELINING\r\n250-STARTTLS\r\n250 OK\r\n");
} elseif ($mode === 'starttls_missing') {
    smtp_write($conn, "250-fake\r\n250 OK\r\n");
} else {
    smtp_write($conn, "250-fake\r\n250 OK\r\n");
}

if ($mode === 'auth_plain') {
    $line = smtp_read_line($conn);
    $user = (string)(getenv('EXPECT_SMTP_USER') ?: '');
    $pass = (string)(getenv('EXPECT_SMTP_PASS') ?: '');
    $expected = base64_encode("\0{$user}\0{$pass}");
    if ($line === 'AUTH PLAIN') {
        smtp_write($conn, "334 \r\n");
        $line = smtp_read_line($conn);
    } elseif (str_starts_with($line ?? '', 'AUTH PLAIN ')) {
        $line = substr((string)$line, 11);
    }
    if ($line !== $expected) {
        exit(4);
    }
    smtp_write($conn, "235 2.7.0 OK\r\n");
} elseif ($mode === 'auth_login') {
    $line = smtp_read_line($conn);
    if ($line === null || strtoupper(substr($line, 0, 10)) !== 'AUTH LOGIN') {
        exit(4);
    }
    smtp_write($conn, "334 VXNlcm5hbWU6\r\n");
    $u = smtp_read_line($conn);
    if ($u !== base64_encode((string)(getenv('EXPECT_SMTP_USER') ?: ''))) {
        exit(5);
    }
    smtp_write($conn, "334 UGFzc3dvcmQ6\r\n");
    $p = smtp_read_line($conn);
    if ($p !== base64_encode((string)(getenv('EXPECT_SMTP_PASS') ?: ''))) {
        exit(6);
    }
    smtp_write($conn, "235 2.7.0 OK\r\n");
} elseif ($mode === 'starttls_broken') {
    $line = smtp_read_line($conn);
    if ($line === null || strtoupper($line) !== 'STARTTLS') {
        exit(11);
    }
    smtp_write($conn, "220 Ready to start TLS\r\n");
    stream_set_timeout($conn, 10);
    while (!feof($conn)) {
        $chunk = @fread($conn, 4096);
        if ($chunk === false || $chunk === '') {
            break;
        }
    }
    fclose($conn);

    exit(0);
} elseif ($mode === 'starttls_missing') {
    fclose($conn);

    exit(0);
}

$line = smtp_read_line($conn);
if ($line === null) {
    // Peer closed (e.g. client aborted after TLS/config error before MAIL FROM).
    exit(0);
}
if (!str_starts_with(strtoupper($line), 'MAIL FROM:')) {
    exit(7);
}
smtp_write($conn, "250 OK\r\n");

while (true) {
    $line = smtp_read_line($conn);
    if ($line === null) {
        exit(8);
    }
    $u = strtoupper($line);
    if (str_starts_with($u, 'RCPT TO:')) {
        smtp_write($conn, "250 OK\r\n");
        continue;
    }
    if ($u === 'DATA') {
        smtp_write($conn, "354 End data with <CR><LF>.<CR><LF>\r\n");
        break;
    }
    exit(9);
}

$body = '';
while (($chunk = fgets($conn)) !== false) {
    $t = rtrim($chunk, "\r\n");
    if ($t === '.') {
        break;
    }
    $body .= $chunk;
}

smtp_write($conn, "250 OK\r\n");

$outPath = getenv('FAKE_SMTP_DATA_OUT');
if ($outPath !== false && $outPath !== '') {
    file_put_contents($outPath, $body);
}

// Tests focus on successful DATA exchange; close proactively instead of waiting for QUIT.
fclose($conn);

exit(0);
