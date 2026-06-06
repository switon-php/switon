<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Integration;

use Switon\Mail\Address;
use Switon\Mail\Connection;
use Switon\Mail\Event\MailFailed;
use Switon\Mail\Event\MailSending;
use Switon\Mail\Event\MailSent;
use Switon\Mail\Exception\ConnectionException;
use Switon\Mail\Exception\DeliveryException;
use Switon\Mail\Mailer;
use Switon\Mail\Message;
use Switon\Mail\Rfc822Serializer;
use Switon\Mail\Rfc822SerializerInterface;
use Switon\Mail\Tests\Fixtures\SpyEventDispatcher;
use Switon\Mail\Tests\Fixtures\SpyPoolManager;
use Switon\Mail\Tests\TestCase;

/**
 * End-to-end {@see Mailer::send()} against {@see \Switon\Mail\Tests\Fixtures\fake-smtp-server.php}.
 */
class MailerDeliveryTest extends TestCase
{
    private string $serverScript;

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Fake SMTP subprocess + TCP is validated on Unix.');
        }

        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            $details = $errstr !== '' ? $errstr : 'local TCP listener unavailable';
            self::markTestSkipped('Local TCP listener not permitted in this environment: ' . $details);
        }
        fclose($probe);

        $path = realpath(__DIR__ . '/../Fixtures/fake-smtp-server.php');
        if ($path === false || !is_file($path)) {
            self::markTestSkipped('fake-smtp-server.php not found.');
        }

        $this->serverScript = $path;
    }

    protected function tearDown(): void
    {
        putenv('FAKE_SMTP_MODE');
        putenv('EXPECT_SMTP_USER');
        putenv('EXPECT_SMTP_PASS');
        putenv('FAKE_SMTP_DATA_OUT');
    }

    /**
     * @return array{0: resource, 1: int, 2: array<int, resource>}
     */
    private function startFakeSmtpServer(array $envExtra): array
    {
        $env = $this->inheritServerEnv($envExtra);

        $proc = proc_open(
            [
                PHP_BINARY,
                '-dxdebug.mode=off',
                '-dxdebug.start_with_request=no',
                $this->serverScript,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            $env,
        );

        if (!is_resource($proc)) {
            self::fail('proc_open failed for fake-smtp-server');
        }

        fclose($pipes[0]);

        $portLine = fgets($pipes[1]);
        $this->assertNotFalse($portLine);
        $port = (int)trim($portLine);
        $this->assertGreaterThan(0, $port);

        return [$proc, $port, $pipes];
    }

    /**
     * @param resource $proc
     * @param array<int, resource> $pipes
     */
    private function finishFakeSmtpServer($proc, array $pipes): void
    {
        foreach ($pipes as $idx => $p) {
            if ($idx === 0) {
                continue;
            }
            if (is_resource($p)) {
                fclose($p);
            }
        }

        $code = proc_close($proc);
        $this->assertContains(
            $code,
            [0, 10],
            'fake-smtp-server should complete session or close on missing QUIT'
        );
    }

    /**
     * @return array<string, string>
     */
    private function inheritServerEnv(array $extra): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $env[$k] = (string)$v;
            }
        }
        foreach ($extra as $k => $v) {
            $env[$k] = $v;
        }

        return $env;
    }

    /**
     * @return array{mailer: Mailer, pool: SpyPoolManager, events: SpyEventDispatcher}
     */
    private function makeMailer(
        int                        $port,
        string                     $user = '',
        string                     $pass = '',
        ?Rfc822SerializerInterface $serializer = null,
        string                     $encryption = 'none',
    ): array {
        $serializer ??= new Rfc822Serializer();
        $poolManager = new SpyPoolManager();
        $poolManager->defaultResource = new Connection();
        $eventDispatcher = new SpyEventDispatcher();
        /** @var Mailer $mailer */
        $mailer = $this->make(Mailer::class, [
            'eventDispatcher' => $eventDispatcher,
            'serializer' => $serializer,
            'poolManager' => $poolManager,
            'host' => '127.0.0.1',
            'port' => $port,
            'encryption' => $encryption,
            'username' => $user,
            'password' => $pass,
            'from' => 'from@example.com',
            'timeout' => 2.0,
        ]);

        return ['mailer' => $mailer, 'pool' => $poolManager, 'events' => $eventDispatcher];
    }

    public function testStartTlsNotAdvertisedRaisesDeliveryException(): void
    {
        [$proc, $port, $pipes] = $this->startFakeSmtpServer([
            'FAKE_SMTP_MODE' => 'starttls_missing',
        ]);

        $message = Message::compose()
            ->from(new Address('from@example.com'))
            ->to(new Address('to@example.com'))
            ->text('x')
            ->subject('S')
            ->messageId('mid@example.com')
            ->get();

        try {
            $this->expectException(DeliveryException::class);
            $state = $this->makeMailer($port, '', '', null, 'starttls');
            $state['mailer']->send($message);
        } finally {
            if (isset($state)) {
                $this->assertInstanceOf(MailSending::class, $state['events']->events[0] ?? null);
                $this->assertInstanceOf(MailFailed::class, $state['events']->events[1] ?? null);
                $state['pool']->remove($state['mailer']);
            }
            $this->finishFakeSmtpServer($proc, $pipes);
        }
    }

    public function testStartTlsHandshakeFailsWhenServerStaysPlain(): void
    {
        [$proc, $port, $pipes] = $this->startFakeSmtpServer([
            'FAKE_SMTP_MODE' => 'starttls_broken',
        ]);

        $message = Message::compose()
            ->from(new Address('from@example.com'))
            ->to(new Address('to@example.com'))
            ->text('x')
            ->subject('S')
            ->messageId('mid@example.com')
            ->get();

        try {
            $this->expectException(ConnectionException::class);
            $state = $this->makeMailer($port, '', '', null, 'starttls');
            $state['mailer']->send($message);
        } finally {
            if (isset($state)) {
                $state['pool']->remove($state['mailer']);
            }
            $this->finishFakeSmtpServer($proc, $pipes);
        }
    }

    public function testPlainSmtpSessionDeliversDotStuffedPayload(): void
    {
        $dataOut = tempnam(sys_get_temp_dir(), 'smtp-data');
        $this->assertNotFalse($dataOut);

        [$proc, $port, $pipes] = $this->startFakeSmtpServer([
            'FAKE_SMTP_MODE' => 'plain',
            'FAKE_SMTP_DATA_OUT' => $dataOut,
        ]);

        $message = Message::compose()
            ->from(new Address('from@example.com'))
            ->to(new Address('to@example.com'), new Address('cc@example.com'))
            ->cc(new Address('cc@example.com'))
            ->text('line1')
            ->subject('S')
            ->messageId('mid@example.com')
            ->get();

        $live = new Rfc822Serializer();
        $rawMime = $live->serialize($message, true);
        $expectedData = $live->dotStuff($rawMime);

        $serializerStub = $this->createStub(Rfc822SerializerInterface::class);
        $serializerStub->method('serialize')->willReturn($rawMime);
        $serializerStub->method('dotStuff')->willReturn($expectedData);

        try {
            $state = $this->makeMailer($port, '', '', $serializerStub);
            $state['mailer']->send($message);
        } finally {
            if (isset($state)) {
                $this->assertInstanceOf(MailSending::class, $state['events']->events[0] ?? null);
                $this->assertInstanceOf(MailSent::class, $state['events']->events[1] ?? null);
                $state['pool']->remove($state['mailer']);
            }
            $this->finishFakeSmtpServer($proc, $pipes);
        }

        $captured = file_get_contents($dataOut);
        @unlink($dataOut);
        $this->assertNotFalse($captured);
        $this->assertSame($expectedData . "\r\n", $captured);
    }

    public function testAuthPlainSessionSucceeds(): void
    {
        [$proc, $port, $pipes] = $this->startFakeSmtpServer([
            'FAKE_SMTP_MODE' => 'auth_plain',
            'EXPECT_SMTP_USER' => 'u1',
            'EXPECT_SMTP_PASS' => 'p9',
        ]);

        $message = Message::compose()
            ->from(new Address('from@example.com'))
            ->to(new Address('to@example.com'))
            ->text('x')
            ->subject('A')
            ->messageId('m@e.com')
            ->get();

        try {
            $state = $this->makeMailer($port, 'u1', 'p9');
            $state['mailer']->send($message);
        } finally {
            if (isset($state)) {
                $state['pool']->remove($state['mailer']);
            }
            $this->finishFakeSmtpServer($proc, $pipes);
        }
    }

    public function testAuthLoginSessionSucceeds(): void
    {
        [$proc, $port, $pipes] = $this->startFakeSmtpServer([
            'FAKE_SMTP_MODE' => 'auth_login',
            'EXPECT_SMTP_USER' => 'login-user',
            'EXPECT_SMTP_PASS' => 'secret!',
        ]);

        $message = Message::compose()
            ->from(new Address('a@b.com'))
            ->to(new Address('c@d.com'))
            ->text('y')
            ->subject('B')
            ->messageId('id@b.com')
            ->get();

        try {
            $state = $this->makeMailer($port, 'login-user', 'secret!');
            $state['mailer']->send($message);
        } finally {
            if (isset($state)) {
                $state['pool']->remove($state['mailer']);
            }
            $this->finishFakeSmtpServer($proc, $pipes);
        }
    }
}
