<?php

declare(strict_types=1);

namespace Switon\Captcha;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Captcha\Event\CaptchaCreated;
use Switon\Captcha\Event\CaptchaCreating;
use Switon\Captcha\Event\CaptchaFailed;
use Switon\Captcha\Event\CaptchaVerified;
use Switon\Captcha\Event\CaptchaVerifying;
use Switon\Captcha\Exception\CaptchaAttemptsExhaustedException;
use Switon\Captcha\Exception\CaptchaDataInvalidException;
use Switon\Captcha\Exception\CaptchaExpiredException;
use Switon\Captcha\Exception\CaptchaMismatchException;
use Switon\Captcha\Exception\CaptchaNotFoundException;
use Switon\Captcha\Exception\CaptchaTooFrequentException;
use Switon\Captcha\Exception\InvalidCaptchaException;
use Switon\Core\Attribute\Autowired;
use Switon\Core\RandomInterface;

use function base64_encode;
use function microtime;
use function round;
use function strlen;
use function strtolower;

/**
 * Shared CAPTCHA workflow for challenge creation and verification.
 *
 * Guidance: Extend for rendering only; keep create(), verify(), and store payload semantics here.
 *
 * Road-signs:
 * - create() stores code, created_at, ttl, attempts
 * - verify() decrements attempts only after freshness checks
 * - failure reasons are emitted as events before exceptions
 * - store payload must round-trip code, created_at, ttl, attempts
 *
 * @see \Switon\Captcha\CaptchaInterface
 * @see \Switon\Captcha\StoreInterface
 * @see \Switon\Captcha\Exception
 * @see \Switon\Captcha\Exception\InvalidCaptchaException
 * @see \Switon\Captcha\Event\CaptchaFailed
 * @see \Switon\Captcha\Event\CaptchaVerified
 */
abstract class AbstractCaptcha implements CaptchaInterface
{
    #[Autowired] protected StoreInterface $store;

    #[Autowired] protected RandomInterface $random;

    #[Autowired] protected EventDispatcherInterface $eventDispatcher;

    /** Characters used for random code generation. */
    /** @noinspection SpellCheckingInspection */
    #[Autowired] protected string $charset = '23456789abcdefhijkmnpqrstuvwxyzABCDEFGHJKLMNPQRTUVWXY';

    /** @var list<string> TTF font paths for CAPTCHA text rendering. */
    #[Autowired] protected array $fonts
        = [
            '@switon.captcha.resources/Fonts/AirbusSpecial.ttf',
            '@switon.captcha.resources/Fonts/Vera.ttf',
        ];

    /** Max character rotation noise in degrees. */
    #[Autowired] protected int $angle_noise = 10;

    /** Max horizontal offset noise in pixels. */
    #[Autowired] protected int $x_noise = 3;

    /** Max vertical offset noise in pixels. */
    #[Autowired] protected int $y_noise = 3;

    /** Base font size in pixels. */
    #[Autowired] protected int $size = 24;

    /** Max font size noise in pixels. */
    #[Autowired] protected int $size_noise = 3;

    /** Number of extra noise characters to render. */
    #[Autowired] protected int $char_noise = 1;

    /** Imagick wave distortion amplitude in pixels; 0 disables it. */
    #[Autowired] protected float $distort = 2.0;

    /** @var array{0: int, 1: int, 2: int} Background RGB color as [R, G, B]. */
    #[Autowired] protected array $background = [255, 255, 255];

    /** Generated CAPTCHA code length. */
    #[Autowired] protected int $length = 5;

    /** Minimum seconds between create and verify. */
    #[Autowired] protected float $min_interval = 1.0;

    /** Allowed verification attempts per challenge (default: one-time). */
    #[Autowired] protected int $attempts = 1;

    /**
     * Returns a random multiplier in the <code>[1-a, 1+a]</code> range.
     */
    protected function rand_amplitude(float $a): float
    {
        return $this->random->int((int)((1 - $a) * 10000), (int)((1 + $a) * 10000)) / 10000;
    }

    /**
     * Creates and stores a CAPTCHA challenge.
     *
     * @param int $width Image width in pixels (default: 120)
     * @param int $height Image height in pixels (default: 40)
     * @param int $ttl Time-to-live in seconds (default: 300)
     * @param string|null $tag Optional business tag forwarded to CAPTCHA events for monitoring and analytics
     *
     * @return array{key: string, blob: string} Captcha key and image data URI (base64 JPEG)
     */
    public function create(int $width = 120, int $height = 40, int $ttl = 300, ?string $tag = null): array
    {
        $this->eventDispatcher->dispatch(new CaptchaCreating($width, $height, $ttl, $tag));

        // 1. Generate UUID as Key
        $key = $this->random->uuid();

        // 2. Generate random CAPTCHA code
        $code = $this->generateRandomCode();

        // 3. Store in StoreInterface (created_at with microtime, 3 decimal places)
        $this->store->put($key, [
            'code' => $code,
            'created_at' => round(microtime(true), 3),
            'ttl' => $ttl,
            'attempts' => $this->attempts
        ], $ttl);

        // 4. Draw image and convert to Base64
        $imageData = $this->draw($code, $width, $height);
        $base64 = base64_encode($imageData);

        $result = [
            'key' => $key,
            'blob' => "data:image/jpeg;base64,$base64"
        ];

        $this->eventDispatcher->dispatch(new CaptchaCreated($key, $ttl, $tag));

        return $result;
    }

    /**
     * Verifies user input for a stored CAPTCHA challenge.
     *
     * Attempt count is consumed after interval and expiry checks pass, so both
     * mismatch and successful checks decrease remaining attempts.
     *
     * Code comparison is case-insensitive.
     *
     * @param CaptchaProof $proof Proof object containing key and user-provided code
     * @param string|null $tag Optional business tag forwarded to CAPTCHA events for monitoring and analytics
     *
     * @throws InvalidCaptchaException When validation fails
     */
    public function verify(CaptchaProof $proof, ?string $tag = null): void
    {
        $key = $proof->captcha_key;
        $code = $proof->captcha_code;

        $this->eventDispatcher->dispatch(new CaptchaVerifying($key, $tag));

        $data = $this->store->get($key);

        if ($data === null) {
            $this->eventDispatcher->dispatch(new CaptchaFailed($key, $tag, CaptchaFailed::REASON_NOT_FOUND, null));
            CaptchaNotFoundException::raise(
                'Captcha not found for key: {key}',
                ['key' => $key]
            );
        }

        // Validate data integrity
        if (!isset($data['code'], $data['created_at'], $data['ttl'])) {
            $this->eventDispatcher->dispatch(new CaptchaFailed($key, $tag, CaptchaFailed::REASON_DATA_INVALID, null));
            CaptchaDataInvalidException::raise('Captcha data is invalid or corrupted');
        }

        // 1. Verify frequency (does NOT consume attempt); 3 decimal places
        $createdAt = (float)$data['created_at'];
        $timeSinceCreation = round(microtime(true) - $createdAt, 3);
        if ($timeSinceCreation < $this->min_interval) {
            $this->eventDispatcher->dispatch(new CaptchaFailed($key, $tag, CaptchaFailed::REASON_TOO_FREQUENT, null));
            CaptchaTooFrequentException::raise(
                'Captcha verification too frequent: {time_since_creation}s < {min_interval}s.',
                ['time_since_creation' => $timeSinceCreation, 'min_interval' => $this->min_interval]
            );
        }

        // 2. Verify expiration (does NOT consume attempt, as it's already invalid)
        if ($timeSinceCreation > (float)$data['ttl']) {
            $this->eventDispatcher->dispatch(new CaptchaFailed($key, $tag, CaptchaFailed::REASON_EXPIRED, null));
            CaptchaExpiredException::raise(
                'Captcha expired at {expired_at}, please refresh and try again',
                ['expired_at' => date('Y-m-d H:i:s', (int)round($createdAt) + (int)$data['ttl'])]
            );
        }

        // 3. Verify attempts exhausted (does NOT consume attempt)
        $attempts = (int)($data['attempts'] ?? 1);
        if ($attempts <= 0) {
            $this->eventDispatcher->dispatch(
                new CaptchaFailed($key, $tag, CaptchaFailed::REASON_ATTEMPTS_EXHAUSTED, 0)
            );
            CaptchaAttemptsExhaustedException::raise('Captcha verification attempts exhausted for key: {key}', ['key' => $key]);
        }

        // 4. Decrement Attempts (consumes one attempt regardless of code correctness)
        $attempts--;
        $data['attempts'] = $attempts;
        $remainingTtlSeconds = (float)$data['ttl'] - $timeSinceCreation;
        if ($remainingTtlSeconds > 0) {
            $this->store->put($key, $data, max(1, (int)round($remainingTtlSeconds)));
        } else {
            $this->store->forget($key);
        }

        // 4. Verify code match
        if (strtolower((string)$data['code']) !== strtolower($code)) {
            $this->eventDispatcher->dispatch(new CaptchaFailed($key, $tag, CaptchaFailed::REASON_MISMATCH, $attempts));
            CaptchaMismatchException::raise(
                'Captcha verification failed: incorrect code "{code}"',
                ['code' => $code]
            );
        }

        $this->eventDispatcher->dispatch(new CaptchaVerified($key, $tag, $attempts));
    }

    /**
     * Generates a random code string from <code>$charset</code>.
     */
    protected function generateRandomCode(): string
    {
        $code = '';
        $charsetCount = strlen($this->charset);
        for ($i = 0; $i < $this->length; $i++) {
            $code .= $this->charset[$this->random->int(0, $charsetCount - 1)];
        }
        return strtoupper($code);
    }

}
