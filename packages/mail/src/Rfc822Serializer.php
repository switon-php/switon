<?php

declare(strict_types=1);

namespace Switon\Mail;

use DateTimeImmutable;
use DateTimeZone;

use function array_merge;
use function base64_encode;
use function bin2hex;
use function chunk_split;
use function explode;
use function implode;
use function preg_match;
use function random_bytes;
use function rawurlencode;
use function str_replace;
use function strlen;
use function strrpos;
use function substr;
use function trim;

/**
 * Builds an RFC 5322 message with MIME (multipart/alternative, multipart/mixed).
 *
 * Guidance:
 * - use this after message validation, right before SMTP DATA submission
 * - the serializer owns header generation, MIME boundaries, and attachment encoding
 *
 * @see \Switon\Mail\Rfc822SerializerInterface
 */
class Rfc822Serializer implements Rfc822SerializerInterface
{
    protected const array RESERVED_HEADERS = [
        'from' => true,
        'to' => true,
        'cc' => true,
        'bcc' => true,
        'reply-to' => true,
        'date' => true,
        'message-id' => true,
        'subject' => true,
        'mime-version' => true,
        'content-type' => true,
        'content-transfer-encoding' => true,
    ];

    /**
     * Serializes one message into RFC 5322 wire text, optionally omitting Bcc from visible headers.
     */
    public function serialize(MessageInterface $message, bool $omitBccInHeaders): string
    {
        $date = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('r');
        $domain = $this->domainFromEmail($message->getFrom()->getEmail());
        $messageId = $message->getMessageId();
        if ($messageId === null || $messageId === '') {
            $messageId = bin2hex(random_bytes(16)) . '@' . $domain;
        }
        $messageIdHeader = '<' . trim($messageId, '<>') . '>';

        $lines = [];
        $lines[] = 'Date: ' . $date;
        $lines[] = 'Message-ID: ' . $messageIdHeader;
        $lines[] = 'From: ' . $this->formatAddress($message->getFrom());
        $lines[] = 'MIME-Version: 1.0';

        $toLine = $this->formatAddressList($message->getTo());
        $ccLine = $this->formatAddressList($message->getCc());
        if ($toLine === '' && $ccLine === '' && $omitBccInHeaders && $message->getBcc() !== []) {
            $lines[] = 'To: undisclosed-recipients:;';
        } else {
            if ($toLine !== '') {
                $lines[] = 'To: ' . $toLine;
            }
            if ($ccLine !== '') {
                $lines[] = 'Cc: ' . $ccLine;
            }
        }
        if (!$omitBccInHeaders && $message->getBcc() !== []) {
            $bccLine = $this->formatAddressList($message->getBcc());
            if ($bccLine !== '') {
                $lines[] = 'Bcc: ' . $bccLine;
            }
        }

        $replyTo = $message->getReplyTo();
        if ($replyTo !== null) {
            $lines[] = 'Reply-To: ' . $this->formatAddress($replyTo);
        }

        $lines[] = 'Subject: ' . $this->encodeSubject($message->getSubject());

        foreach ($message->getHeaders() as $name => $value) {
            $key = strtolower($name);
            if (isset(self::RESERVED_HEADERS[$key])) {
                continue;
            }
            $lines[] = $name . ': ' . $this->foldHeaderValue($value);
        }

        $text = $message->getTextBody();
        $html = $message->getHtmlBody();
        $attachments = $message->getAttachments();

        if ($attachments === []) {
            [$ctLines, $body] = $this->buildInnerPart($text, $html);
            foreach ($ctLines as $h) {
                $lines[] = $h;
            }

            return implode("\r\n", $lines) . "\r\n\r\n" . $body;
        }

        $mixed = '----=_Mixed_' . bin2hex(random_bytes(8));
        $lines[] = 'Content-Type: multipart/mixed; boundary="' . $mixed . '"';

        [$innerHead, $innerBody] = $this->buildInnerPart($text, $html);

        $chunks = [];
        $chunks[] = '--' . $mixed;
        foreach ($innerHead as $h) {
            $chunks[] = $h;
        }
        $chunks[] = '';
        $chunks[] = $innerBody;
        $chunks[] = '';

        foreach ($attachments as $att) {
            $chunks[] = '--' . $mixed;
            $chunks[] = 'Content-Type: ' . $att->getMediaType();
            $chunks[] = 'Content-Transfer-Encoding: base64';
            $chunks[] = 'Content-Disposition: attachment; ' . $this->filenameParam($att->getFilename());
            $chunks[] = '';
            $chunks[] = trim(chunk_split(base64_encode($att->getContent())));
            $chunks[] = '';
        }
        $chunks[] = '--' . $mixed . '--';

        return implode("\r\n", $lines) . "\r\n\r\n" . implode("\r\n", $chunks);
    }

    /**
     * Applies SMTP dot-stuffing to the payload before DATA transmission.
     */
    public function dotStuff(string $payload): string
    {
        $lines = explode("\r\n", $payload);
        $out = [];
        foreach ($lines as $line) {
            $out[] = (isset($line[0]) && $line[0] === '.') ? '.' . $line : $line;
        }

        return implode("\r\n", $out);
    }

    /**
     * Builds the innermost body part, choosing text, HTML, multipart/alternative, or an empty text body.
     *
     * @return array{0: list<string>, 1: string}
     */
    protected function buildInnerPart(?string $text, ?string $html): array
    {
        if ($text !== null && $html !== null) {
            $b = '----=_Alt_' . bin2hex(random_bytes(8));
            $head = ['Content-Type: multipart/alternative; boundary="' . $b . '"'];
            $bodyLines = [];
            $bodyLines[] = '--' . $b;
            $bodyLines = array_merge($bodyLines, $this->textPartLines('text/plain', $text));
            $bodyLines[] = '--' . $b;
            $bodyLines = array_merge($bodyLines, $this->textPartLines('text/html', $html));
            $bodyLines[] = '--' . $b . '--';

            return [$head, implode("\r\n", $bodyLines)];
        }
        if ($html !== null) {
            return [$this->textPartHead('text/html'), $this->textPartBody($html)];
        }
        if ($text !== null) {
            return [$this->textPartHead('text/plain'), $this->textPartBody($text)];
        }

        return [$this->textPartHead('text/plain'), $this->textPartBody('')];
    }

    /**
     * Returns MIME headers for one UTF-8 base64-encoded text or HTML part.
     *
     * @return list<string>
     */
    protected function textPartHead(string $mediaType): array
    {
        return [
            'Content-Type: ' . $mediaType . '; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
    }

    /** Encodes one text body as base64 for MIME transport. */
    protected function textPartBody(string $body): string
    {
        return trim(chunk_split(base64_encode($body)));
    }

    /**
     * Returns one complete MIME text part including headers, separator line, and encoded body.
     *
     * @return list<string>
     */
    protected function textPartLines(string $mediaType, string $body): array
    {
        $lines = [];
        $lines[] = 'Content-Type: ' . $mediaType . '; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: base64';
        $lines[] = '';
        $lines[] = trim(chunk_split(base64_encode($body)));

        return $lines;
    }

    /** Builds the attachment filename parameter, using RFC 5987 encoding when needed. */
    protected function filenameParam(string $filename): string
    {
        if ($filename === '' || !preg_match('/[^\x20-\x7E]/', $filename)) {
            return 'filename="' . str_replace(['\\', '"'], ['\\\\', '\\"'], $filename) . '"';
        }

        return "filename*=UTF-8''" . rawurlencode($filename);
    }

    /** Encodes the subject line, preserving ASCII when possible and using UTF-8 encoded-word otherwise. */
    protected function encodeSubject(string $subject): string
    {
        if ($subject === '') {
            return '';
        }
        if (!preg_match('/[^\x20-\x7E]/', $subject)) {
            return $this->foldHeaderValue($subject);
        }

        return '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }

    /** Folds a long header value onto continuation lines. */
    protected function foldHeaderValue(string $value): string
    {
        if (strlen($value) <= 78) {
            return $value;
        }

        return rtrim(chunk_split($value, 78, "\r\n "));
    }

    /** Formats one mailbox for RFC 5322 headers, encoding the display name when required. */
    protected function formatAddress(AddressInterface $address): string
    {
        $email = $address->getEmail();
        $name = $address->getName();
        if ($name === null || $name === '') {
            return $email;
        }
        if (!preg_match('/[^\x20-\x7E]/', $name)) {
            $q = str_replace(['\\', '"'], ['\\\\', '\\"'], $name);

            return '"' . $q . '" <' . $email . '>';
        }

        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
    }

    /**
     * Formats one mailbox list for RFC 5322 headers.
     *
     * @param list<AddressInterface> $list
     */
    protected function formatAddressList(array $list): string
    {
        if ($list === []) {
            return '';
        }
        $parts = [];
        foreach ($list as $a) {
            $parts[] = $this->formatAddress($a);
        }

        return implode(', ', $parts);
    }

    /** Derives the Message-ID domain from the sender address, falling back to localhost when unavailable. */
    protected function domainFromEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return 'localhost';
        }
        $d = substr($email, $at + 1);
        if ($d === '') {
            return 'localhost';
        }

        return $d;
    }
}
