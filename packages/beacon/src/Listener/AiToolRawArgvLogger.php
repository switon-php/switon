<?php

declare(strict_types=1);

namespace Switon\Beacon\Listener;

use Switon\Cli\Event\ToolInvoking;
use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Core\Json;
use Switon\Eventing\Attribute\EventListener;

use function count;
use function date;
use function getmypid;
use function str_starts_with;

/**
 * Writes a dedicated audit log for AI tool invocations (raw argv).
 *
 * Road-signs:
 * - ToolInvoking event
 * - raw argv from Handler::handle() args
 * - redact secrets before logging
 * - append to @runtime/beacon/tool.log
 *
 * Guidance: Keep this log file internal; redact secrets in argv.
 *
 * @see \Switon\Cli\Event\ToolInvoking
 * @see \Switon\Core\FilesystemInterface::append()
 */
class AiToolRawArgvLogger
{
    #[Autowired] protected FilesystemInterface $filesystem;

    /** @var string Log file path (supports aliases). */
    #[Autowired] protected string $file = '@runtime/beacon/tool.log';

    /** @var list<string> */
    protected array $secretKeys = [
        '--token',
        '--password',
        '--passwd',
        '--secret',
        '--key',
        '--api-key',
        '--access-key',
        '--private-key',
    ];

    #[EventListener(priority: -100)]
    public function onToolInvoking(ToolInvoking $event): void
    {
        $row = [
            'ts' => date('c'),
            'pid' => getmypid(),
            'invocation' => $event->router->getCommand() . ':' . $event->router->getAction(),
            'argv' => $this->redactArgv($event->argv),
        ];

        $this->filesystem->append($this->file, Json::stringify($row) . "\n");
    }

    /**
     * @param list<string> $argv
     *
     * @return list<string>
     */
    protected function redactArgv(array $argv): array
    {
        $out = $argv;

        for ($i = 0, $iMax = count($out); $i < $iMax; $i++) {
            $arg = $out[$i];

            foreach ($this->secretKeys as $k) {
                if ($arg === $k && isset($out[$i + 1])) {
                    $out[$i + 1] = '***';
                    continue;
                }

                if (str_starts_with($arg, $k . '=')) {
                    $out[$i] = $k . '=***';
                }
            }
        }

        return array_values($out);
    }
}
