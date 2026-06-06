<?php

declare(strict_types=1);

namespace Switon\Console;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;

/**
 * Renders in-place CLI progress output.
 *
 * Guidance: Call finish() when the task completes so the next console output starts on a new line.
 */
class ProgressBar implements ProgressBarInterface
{
    use AnsiWidthCalculator;

    #[Autowired] protected ConsoleInterface $console;

    protected int $current = 0;
    protected int $max = 1;
    protected ?float $startTime = null;
    protected string $format = ' %current%/%max% [%bar%] %percent%% %elapsed%/%remaining% %rate%/s';
    protected string $message = '';
    protected int $barWidth = 28;
    protected bool $started = false;
    protected int $lastOutputWidth = 0;

    /**
     * Starts or restarts the progress bar.
     */
    public function start(?int $max = null): static
    {
        if ($max !== null) {
            $this->max = max(1, $max);
        }

        $this->current = 0;
        $this->startTime = microtime(true);
        $this->started = true;
        $this->display();

        return $this;
    }

    /**
     * Advances the current progress by the given step.
     */
    public function advance(int $step = 1): static
    {
        if (!$this->started) {
            $this->start();
        }

        $this->setProgress($this->current + $step);

        return $this;
    }

    /**
     * Sets the absolute current progress value.
     */
    public function setProgress(int $current): static
    {
        if (!$this->started) {
            $this->start();
        }

        $this->current = min($current, $this->max);
        $this->display();

        return $this;
    }

    /**
     * Completes the bar and moves to the next line.
     */
    public function finish(): static
    {
        if (!$this->started) {
            $this->start();
        }

        $this->current = $this->max;
        $this->display();
        $this->console->writeLn();

        return $this;
    }

    /**
     * Sets the output format template.
     */
    public function setFormat(string $format): static
    {
        $this->format = $format;
        return $this;
    }

    /**
     * Sets the trailing message appended to the bar.
     */
    public function setMessage(string $message): static
    {
        $this->message = $message;
        return $this;
    }

    public function getCurrent(): int
    {
        return $this->current;
    }

    public function getMax(): int
    {
        return $this->max;
    }

    public function getPercent(): float
    {
        return ($this->current / $this->max) * 100;
    }

    protected function display(): void
    {
        $output = $this->buildOutput();
        $outputWidth = $this->getDisplayWidth($output);
        $padding = max($this->lastOutputWidth - $outputWidth, 0);

        $this->console->write("\r" . $output . str_repeat(' ', $padding));
        $this->lastOutputWidth = $outputWidth;
    }

    protected function buildOutput(): string
    {
        $percent = $this->getPercent();
        $bar = $this->buildBar($percent);
        $elapsed = $this->getElapsedTime();
        $remaining = $this->getEstimatedRemaining();
        $rate = $this->getRate();

        $output = $this->format;
        $output = str_replace('%current%', (string)$this->current, $output);
        $output = str_replace('%max%', (string)$this->max, $output);
        $output = str_replace('%percent%', sprintf('%3d', (int)$percent), $output);
        $output = str_replace('%bar%', $bar, $output);
        $output = str_replace('%elapsed%', $this->formatTime($elapsed), $output);
        $output = str_replace('%remaining%', $remaining > 0 ? $this->formatTime($remaining) : '--:--', $output);
        $output = str_replace('%rate%', sprintf('%.1f', $rate), $output);

        if ($this->message) {
            $output .= ' ' . $this->message;
        }

        return $output;
    }

    protected function buildBar(float $percent): string
    {
        $filled = (int)(($percent / 100) * $this->barWidth);
        $empty = $this->barWidth - $filled;

        $bar = '[';
        $bar .= $this->console->colorize(
            str_repeat('=', max(0, $filled - 1)),
            Colors::FC_LIGHT_GREEN
        );

        if ($filled > 0 && $empty > 0) {
            $bar .= $this->console->colorize('>', Colors::FC_LIGHT_GREEN);
        } elseif ($filled > 0) {
            $bar .= $this->console->colorize('=', Colors::FC_LIGHT_GREEN);
        }

        $bar .= str_repeat(' ', max(0, $empty));
        $bar .= ']';

        return $bar;
    }

    protected function getElapsedTime(): float
    {
        if ($this->startTime === null) {
            return 0.0;
        }

        return microtime(true) - $this->startTime;
    }

    protected function getEstimatedRemaining(): float
    {
        if ($this->current === 0) {
            return 0.0;
        }

        $elapsed = $this->getElapsedTime();
        if ($elapsed < 0.001) {
            return 0.0;
        }

        $rate = $this->current / $elapsed;
        $remaining = $this->max - $this->current;

        return $rate > 0 ? $remaining / $rate : 0.0;
    }

    protected function getRate(): float
    {
        $elapsed = $this->getElapsedTime();

        if ($elapsed < 0.001) {
            return 0.0;
        }

        return $this->current / $elapsed;
    }

    protected function formatTime(float $seconds): string
    {
        $secondsInt = (int)$seconds;
        $hours = (int)($secondsInt / 3600);
        $minutes = (int)(($secondsInt % 3600) / 60);
        $secs = $secondsInt % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%02d:%02d', $minutes, $secs);
    }
}
