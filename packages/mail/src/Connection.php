<?php

declare(strict_types=1);

namespace Switon\Mail;

use Switon\Core\Attribute\Autowired;
use Switon\Mail\Exception\AuthException;
use Switon\Mail\Exception\ConnectionException;
use Switon\Mail\Exception\ProtocolException;
use Switon\Mail\Exception\TlsException;
use CurlHandle;

use function array_map;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_reset;
use function curl_setopt_array;
use function defined;
use function in_array;
use function max;
use function round;
use function sprintf;
use function strlen;
use function substr;
use function trim;

/**
 * Default curl-backed connection with lazy handle reuse.
 *
 * Guidance:
 * - use this to keep SMTP protocol and curl error mapping in one transport implementation
 * - reuse the same instance for repeated sends; endpoint details come from each <code>Delivery</code>
 *
 * Road-signs:
 * - Delivery carries endpoint + payload
 * - prepareHandle() reuses one curl easy handle
 * - mapFailure() converts curl/runtime failures to DeliveryException leaves
 *
 * @see \Switon\Mail\ConnectionInterface
 * @see \Switon\Mail\Delivery
 * @see \Switon\Mail\Exception\DeliveryException
 */
class Connection implements ConnectionInterface
{
    #[Autowired] protected MailerInterface $owner;

    protected ?CurlHandle $handle = null;

    public function getOwner(): MailerInterface
    {
        return $this->owner;
    }

    /**
     * Sends one prepared SMTP delivery request through the configured curl-backed transport.
     */
    public function send(Delivery $request): void
    {
        $handle = $this->prepareHandle();
        $payload = $request->payload;
        $offset = 0;

        $options = [
            CURLOPT_URL => $this->urlFor($request),
            CURLOPT_MAIL_FROM => '<' . $request->from . '>',
            CURLOPT_MAIL_RCPT => array_map(static fn (string $email): string => '<' . $email . '>', $request->recipients),
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILESIZE => strlen($payload),
            CURLOPT_READFUNCTION => static function ($curl, $stream, int $length) use (&$payload, &$offset): string {
                $chunk = substr($payload, $offset, $length);
                $offset += strlen($chunk);

                return $chunk;
            },
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => $this->timeoutMs($request->timeout),
            CURLOPT_TIMEOUT_MS => $this->timeoutMs($request->timeout),
        ];

        if ($request->username !== '') {
            $options[CURLOPT_USERNAME] = $request->username;
            $options[CURLOPT_PASSWORD] = $request->password;
        }

        if ($request->encryption === Encryption::StartTls) {
            $options[CURLOPT_USE_SSL] = CURLUSESSL_ALL;
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
        } elseif ($request->encryption === Encryption::Ssl) {
            $options[CURLOPT_USE_SSL] = CURLUSESSL_ALL;
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
        } else {
            $options[CURLOPT_USE_SSL] = CURLUSESSL_NONE;
        }

        curl_setopt_array($handle, $options);

        if (curl_exec($handle) === false) {
            $this->mapFailure($handle, $request);
        }

        $responseCode = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($responseCode >= 400) {
            $this->raiseFromResponseCode($responseCode, $request, curl_error($handle));
        }
    }

    /**
     * Returns the reusable curl handle, creating it on first use and resetting it afterward.
     */
    protected function prepareHandle(): CurlHandle
    {
        if ($this->handle === null) {
            $handle = curl_init();
            if (!$handle instanceof CurlHandle) {
                ConnectionException::raise('SMTP curl handle could not be created.');
            }

            $this->handle = $handle;
        } else {
            curl_reset($this->handle);
        }

        return $this->handle;
    }

    /**
     * Builds the SMTP or SMTPS URL for the delivery endpoint.
     */
    protected function urlFor(Delivery $request): string
    {
        $scheme = $request->encryption === Encryption::Ssl ? 'smtps' : 'smtp';

        return sprintf('%s://%s:%d', $scheme, $request->host, $request->port);
    }

    /**
     * Converts timeout seconds to curl millisecond options while preserving a minimum positive timeout.
     */
    protected function timeoutMs(float $seconds): int
    {
        return max(1, (int)round($seconds * 1000));
    }

    /**
     * Maps curl transport failures to the most specific delivery exception type.
     */
    protected function mapFailure(CurlHandle $handle, Delivery $request): never
    {
        $errno = curl_errno($handle);
        $error = trim(curl_error($handle));
        $responseCode = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $detail = $error !== '' ? $error : ('curl error ' . $errno);

        if ($responseCode >= 400) {
            $this->raiseFromResponseCode($responseCode, $request, $detail);
        }

        if ($this->isTlsError($errno)) {
            TlsException::raise('SMTP TLS failed: {error}', ['error' => $detail]);
        }

        if ($this->isAuthError($errno)) {
            AuthException::raise('SMTP AUTH failed: {error}', ['error' => $detail]);
        }

        if ($this->isConnectionError($errno)) {
            ConnectionException::raise('SMTP connect failed: {error}', ['error' => $detail]);
        }

        ProtocolException::raise('SMTP delivery failed: {error}', ['error' => $detail]);
    }

    /**
     * Raises delivery exceptions from SMTP response codes returned by the server.
     */
    protected function raiseFromResponseCode(int $responseCode, Delivery $request, string $detail = ''): never
    {
        $error = $detail !== '' ? $detail : 'SMTP server returned an error response';
        if ($request->username !== '' && in_array($responseCode, [334, 454, 503, 534, 535], true)) {
            AuthException::raise('SMTP AUTH failed ({code}): {error}', [
                'code' => (string)$responseCode,
                'error' => $error,
            ]);
        }

        ProtocolException::raise('SMTP error ({code}): {error}', [
            'code' => (string)$responseCode,
            'error' => $error,
        ]);
    }

    /** Returns whether the curl error code indicates a TLS negotiation or certificate failure. */
    protected function isTlsError(int $errno): bool
    {
        return in_array($errno, $this->definedCurlCodes([
            'CURLE_USE_SSL_FAILED',
            'CURLE_SSL_CONNECT_ERROR',
            'CURLE_PEER_FAILED_VERIFICATION',
            'CURLE_SSL_CERTPROBLEM',
            'CURLE_SSL_CIPHER',
            'CURLE_SSL_CACERT_BADFILE',
        ]), true);
    }

    /** Returns whether the curl error code indicates an SMTP authentication failure. */
    protected function isAuthError(int $errno): bool
    {
        return in_array($errno, $this->definedCurlCodes([
            'CURLE_LOGIN_DENIED',
            'CURLE_REMOTE_ACCESS_DENIED',
        ]), true);
    }

    /** Returns whether the curl error code indicates a connection or network transport failure. */
    protected function isConnectionError(int $errno): bool
    {
        return in_array($errno, $this->definedCurlCodes([
            'CURLE_COULDNT_RESOLVE_HOST',
            'CURLE_COULDNT_CONNECT',
            'CURLE_OPERATION_TIMEDOUT',
            'CURLE_SEND_ERROR',
            'CURLE_RECV_ERROR',
            'CURLE_GOT_NOTHING',
        ]), true);
    }

    /**
     * @param list<string> $names
     *
     * @return list<int>
     */
    protected function definedCurlCodes(array $names): array
    {
        $codes = [];
        foreach ($names as $name) {
            if (defined($name)) {
                $value = constant($name);
                if (is_int($value)) {
                    $codes[] = $value;
                }
            }
        }

        return $codes;
    }
}
