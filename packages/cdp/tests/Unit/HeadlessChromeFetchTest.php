<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Switon\Cdp\Exception;
use Switon\Cdp\HeadlessChrome;
use Switon\Cdp\Page;
use Switon\Cdp\PageInterface;
use Switon\Cdp\Session;
use Switon\Core\WebFetcherInterface;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function microtime;
use function proc_close;
use function proc_open;
use function random_bytes;
use function sys_get_temp_dir;
use function trim;
use function unlink;
use function usleep;

use const PHP_BINARY;

class HeadlessChromeFetchTest extends TestCase
{
    #[Test]
    public function aDocumentFetchReadsThePageAndAPostedFetchUsesTheOrigin(): void
    {
        $chrome = $this->chrome();

        $document = $chrome->fetch('https://example.com/page');
        $plain = $chrome->request('GET', 'https://example.com/plain');
        $posted = $chrome->request('POST', 'https://example.com/api', [], '{"q":1}', 'https://example.com/');

        $this->assertSame('<html></html>', $document['body']);
        $this->assertSame('<html></html>', $plain['body']);
        $this->assertSame(0, $document['status']);
        $this->assertSame(201, $posted['status']);
        $this->assertSame(
            ['https://example.com/page', 'https://example.com/plain', 'https://example.com/'],
            $chrome->opened,
        );
        $this->assertTrue($chrome->last->closed);
    }

    #[Test]
    public function fetchWaitsForTheReadySelectorAndCanAbort(): void
    {
        $chrome = $this->chrome();

        $ready = $chrome->fetch('https://example.com/page', 'GET', [], null, [
            WebFetcherInterface::READY => 'main',
            WebFetcherInterface::TIMEOUT => 0.4,
        ]);
        $posted = $chrome->fetch('https://example.com/api', 'POST', ['X-A' => '1'], '{"q":1}', [
            WebFetcherInterface::ORIGIN => 'https://example.com/',
        ]);
        $this->assertSame(201, $posted['status']);
        $this->assertSame('<html></html>', $ready['body']);

        $this->expectException(Exception::class);
        $chrome->fetch('https://example.com/page', 'GET', [], null, [
            WebFetcherInterface::SHOULD_ABORT => static fn (): bool => true,
        ]);
    }

    #[Test]
    public function fetchRejectsAnEmptyUrl(): void
    {
        $chrome = new HeadlessChrome();

        $this->expectException(Exception::class);
        $chrome->fetch('  ');
    }

    #[Test]
    public function httpJsonReadsALocalReplyAndDropsABadBody(): void
    {
        $chrome = new HttpProbe();
        $this->assertNull($chrome->read('not-a-url', 1.0));

        $address = sys_get_temp_dir() . '/switon-cdp-http-' . bin2hex(random_bytes(4));
        $script = $address . '.php';
        file_put_contents($script, <<<'PHP'
<?php
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
file_put_contents($argv[1], (string) stream_socket_get_name($server, false));
for ($i = 0; $i < 2; $i++) {
    $client = stream_socket_accept($server, 3);
    if ($client === false) {
        break;
    }
    $raw = (string) fread($client, 4096);
    $body = str_contains($raw, '/bad') ? 'nope' : '{"ok":true}';
    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
    fclose($client);
}
PHP);
        $proc = proc_open([PHP_BINARY, $script, $address], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        try {
            $deadline = microtime(true) + 2;
            while (microtime(true) < $deadline && !is_file($address)) {
                usleep(1000);
            }
            $endpoint = trim((string) file_get_contents($address));

            $this->assertSame(['ok' => true], $chrome->read('http://' . $endpoint . '/json/version', 2.0, 'GET'));
            $this->assertNull($chrome->read('http://' . $endpoint . '/bad', 2.0));
            $this->assertNull($chrome->read('http://127.0.0.1:1/json/version', 0.2));
        } finally {
            proc_close($proc);
            @unlink($script);
            @unlink($address);
        }
    }

    #[Test]
    public function httpJsonDropsAClosedSocketAndAReplyThatIsNotHttp(): void
    {
        $chrome = new HttpProbe();
        $address = sys_get_temp_dir() . '/switon-cdp-http-bad-' . bin2hex(random_bytes(4));
        $script = $address . '.php';
        file_put_contents($script, <<<'PHP'
<?php
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
file_put_contents($argv[1], (string) stream_socket_get_name($server, false));
for ($i = 0; $i < 2; $i++) {
    $client = stream_socket_accept($server, 3);
    if ($client === false) {
        break;
    }
    if ($i === 1) {
        fwrite($client, 'not-http');
    }
    fclose($client);
}
PHP);
        $proc = proc_open([PHP_BINARY, $script, $address], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        try {
            $deadline = microtime(true) + 2;
            while (microtime(true) < $deadline && !is_file($address)) {
                usleep(1000);
            }
            $endpoint = trim((string) file_get_contents($address));

            $this->assertNull($chrome->read('http://' . $endpoint . '/empty', 1.0));
            $this->assertNull($chrome->read('http://' . $endpoint . '/garbage', 1.0));
        } finally {
            proc_close($proc);
            @unlink($script);
            @unlink($address);
        }
    }

    private function chrome(): OpenStub
    {
        $chrome = new OpenStub();
        $chrome->results['Runtime.evaluate'] = static function (array $message): array {
            $expression = (string) ($message['params']['expression'] ?? '');
            if (str_contains($expression, 'outerHTML')) {
                return ['result' => ['value' => '<html></html>']];
            }
            if (str_contains($expression, 'querySelector') && str_contains($expression, '!== null')) {
                return ['result' => ['value' => true]];
            }
            if (str_contains($expression, 'fetch(')) {
                return ['result' => ['value' => [
                    'status' => 201,
                    'body' => 'ok',
                    'headers' => ['content-type' => 'application/json'],
                ]]];
            }

            return ['result' => ['value' => null]];
        };

        return $chrome;
    }
}

final class OpenStub extends HeadlessChrome
{
    /** @var array<string, mixed> */
    public array $results = [];

    public ScriptedSocket $last;

    /** @var list<string> */
    public array $opened = [];

    public function open(string $url, ?float $timeout = null): PageInterface
    {
        $this->opened[] = $url;
        $this->last = new ScriptedSocket();
        $this->last->results = $this->results;

        return new Page(new Session($this->last), 'tab-' . count($this->opened), $this, 0.4);
    }

    public function dropTarget(string $targetId): void
    {
    }
}

final class HttpProbe extends HeadlessChrome
{
    /** @return array<mixed>|null */
    public function read(string $url, float $timeout, string $method = 'GET'): ?array
    {
        return $this->httpJson($url, $timeout, $method);
    }
}
