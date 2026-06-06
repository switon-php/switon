<?php

declare(strict_types=1);

namespace Switon\Inspector;

use ArrayObject;
use JsonSerializable;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Backtrace;
use Switon\Core\Json;
use Switon\Core\Strings;
use Throwable;

use function basename;
use function count;
use function date;
use function is_array;
use function is_scalar;
use function is_string;
use function microtime;
use function rtrim;
use function sprintf;
use function str_contains;
use function strtr;
use function substr_count;

/**
 * Formats debug values and writes them through <code>console_log()</code>.
 *
 * Use when you need timestamped dump output with source location.
 *
 * @see \Switon\Inspector\DataDumpInterface
 */
class DataDump implements DataDumpInterface
{
    /** Output format template for dump messages. */
    #[Autowired] protected string $format = '[:time][:location] :message';

    /**
     * Resolves source location from captured traces.
     *
     * @param array<int, array<string, mixed>> $traces
     *
     * @return array<string, mixed>
     */
    protected function getLocation(array $traces): array
    {
        for ($i = count($traces) - 1; $i >= 0; $i--) {
            $trace = $traces[$i];
            $function = $trace['function'];
            if ($function === 'output') {
                return $traces[$i + 1];
            }
        }

        return [];
    }

    /**
     * Formats one dump payload to text.
     *
     * @param mixed $message Value to format
     *
     * @return string Formatted output message
     */
    protected function formatMessage(mixed $message): string
    {
        if ($message instanceof Throwable) {
            return Strings::renderException($message);
        } elseif ($message instanceof JsonSerializable || $message instanceof ArrayObject) {
            return Json::stringify($message, JSON_PARTIAL_OUTPUT_ON_ERROR);
        } elseif (!is_array($message)) {
            return (string)$message;
        }

        if (!isset($message[0]) || !is_string($message[0])) {
            return Json::stringify($message, JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        if (substr_count($message[0], '%') + 1 >= ($count = count($message)) && isset($message[$count - 1])) {
            foreach ($message as $k => $v) {
                if ($k === 0 || is_scalar($v) || $v === null) {
                    continue;
                }

                if ($v instanceof Throwable) {
                    $message[$k] = Strings::renderException($v);
                } elseif (is_array($v)) {
                    $message[$k] = Json::stringify($v, JSON_PARTIAL_OUTPUT_ON_ERROR);
                } elseif ($v instanceof JsonSerializable || $v instanceof ArrayObject) {
                    $message[$k] = Json::stringify($v, JSON_PARTIAL_OUTPUT_ON_ERROR);
                }
            }
            return sprintf(...$message);
        }

        if (count($message) === 2) {
            if (isset($message[1]) && !str_contains($message[0], ':1')) {
                $message[0] = rtrim($message[0], ': ') . ': :1';
            }
        } elseif (count($message) === 3) {
            /** @noinspection NotOptimalIfConditionsInspection */
            if (isset($message[1], $message[2]) && !str_contains($message[0], ':1') && is_scalar($message[1])) {
                $message[0] = rtrim($message[0], ': ') . ': :1 => :2';
            }
        }

        $replaces = [];
        foreach ($message as $k => $v) {
            if ($k === 0) {
                continue;
            }

            if ($v instanceof Throwable) {
                $v = Strings::renderException($v);
            } elseif (is_array($v)) {
                $v = Json::stringify($v, JSON_PARTIAL_OUTPUT_ON_ERROR);
            } elseif ($v instanceof JsonSerializable || $v instanceof ArrayObject) {
                $v = Json::stringify($v, JSON_PARTIAL_OUTPUT_ON_ERROR);
            } elseif (is_string($v)) {
                //no-op
            } elseif ($v === null || is_scalar($v)) {
                $v = Json::stringify($v, JSON_PARTIAL_OUTPUT_ON_ERROR);
            } else {
                $v = (string)$v;
            }

            $replaces[":$k"] = $v;
        }

        return strtr($message[0], $replaces);
    }

    /** {@inheritDoc} */
    public function output(mixed $message): void
    {
        if (is_array($message) && count($message) === 1 && isset($message[0])) {
            $message = $message[0];
        }

        if ($message instanceof Throwable) {
            $file = basename($message->getFile());
            $line = $message->getLine();
        } else {
            $traces = Backtrace::get(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            $location = $this->getLocation($traces);
            if (isset($location['file'])) {
                $file = basename($location['file']);
                $line = $location['line'];
            } else {
                $file = '';
                $line = 0;
            }
        }

        $message = is_string($message) ? $message : $this->formatMessage($message);
        $timestamp = microtime(true);

        $replaced = [];

        $replaced[':time'] = date('H:i:s', (int)$timestamp) . sprintf('.%03d', ($timestamp - (int)$timestamp) * 1000);
        $replaced[':date'] = date('Y-m-d\T', (int)$timestamp) . $replaced[':time'];
        $replaced[':location'] = "$file:$line";
        $replaced[':message'] = $message;

        console_log('debug', strtr($this->format, $replaced));
    }
}
