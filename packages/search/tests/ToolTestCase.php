<?php

declare(strict_types=1);

namespace Switon\Search\Tests;

use LogicException;
use Switon\Ai\ToolCall;
use Switon\Ai\ToolInterface;
use Switon\Search\Site;
use Switon\Search\SiteExtractorInterface;
use Switon\Search\Sites;
use Switon\Search\SiteSearchInterface;
use Switon\Search\Tests\Fixtures\StubAgent;
use Switon\Testing\TestCase as BaseTestCase;

/** Base for search tool unit tests. */
abstract class ToolTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (extension_loaded('swoole')) {
            \Swoole\Runtime::setHookFlags(0);
            \Switon\Core\Runtime::setCoroutineEnabled(false);
        }

        // Schema and delegation tests never reach a browser; this keeps the real driver out of
        // the container so a test that does need one has to say so.
        $this->container->set(
            \Switon\Core\WebFetcherInterface::class,
            new class () implements \Switon\Core\WebFetcherInterface {
                public function fetch(
                    string $url,
                    string $method = 'GET',
                    array $headers = [],
                    ?string $body = null,
                    array $options = [],
                ): array {
                    throw new LogicException('No browser is configured for this test: ' . $url);
                }
            },
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function callTool(ToolInterface $tool, string $name, array $arguments = []): mixed
    {
        return $tool->call(StubAgent::in(), new ToolCall(name: $name, arguments: $arguments), $arguments);
    }

    /** One registered site definition, for tests that call {@see SiteSearchInterface::search()}. */
    protected function site(string $id): Site
    {
        return $this->sites()->get($id);
    }

    /** A registry filled from disk, the way `boot()` fills it. */
    protected function sites(): Sites
    {
        $sites = $this->make(Sites::class);
        $sites->reload();

        return $sites;
    }

    /** The site-file mapper, for tests that read a site's mapping directly. */
    protected function extractor(): SiteExtractorInterface
    {
        return $this->make(SiteExtractorInterface::class);
    }
}
