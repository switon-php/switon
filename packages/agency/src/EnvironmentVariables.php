<?php

declare(strict_types=1);

namespace Switon\Agency;

use Switon\Ai\AgentInterface;
use Switon\Ai\PromptVariablesInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\LocaleInterface;

use function date;
use function get_current_user;
use function getenv;
use function gethostname;
use function is_string;
use function php_uname;

/**
 * Process facts every prompt can read (`env.date_line`, `env.os`, `env.locale`, …).
 *
 * Guidance: `$cached` holds process constants only; `date_line` and `workspace` are per-call and returned as a copy (`workspace()` as given, including empty).
 */
class EnvironmentVariables implements PromptVariablesInterface
{
    #[Autowired] protected LocaleInterface $locale;

    /** BCP-47 / underscore tag. Empty uses {@see LocaleInterface::get()}. */
    #[Autowired] protected string $localeTag = '';

    /** @var array<string, string>|null */
    protected ?array $cached = null;

    public function variables(AgentInterface $agent): array
    {
        if ($this->cached === null) {
            $this->cached = [
                'date_line' => '',
                'os' => PHP_OS_FAMILY,
                'os.name' => PHP_OS,
                'arch' => php_uname('m'),
                'hostname' => gethostname() ?: '',
                'workspace' => '',
                'home' => $this->envVar('HOME') ?: $this->envVar('USERPROFILE'),
                'user' => get_current_user(),
                'shell' => $this->envVar('SHELL'),
                'lang' => $this->envVar('LANG') ?: $this->envVar('LC_ALL'),
                'locale' => $this->localeTag !== '' ? $this->localeTag : $this->locale->get(),
                'php' => PHP_VERSION,
            ];
        }

        $env = $this->cached;
        $env['date_line'] = date('Y-m-d (D), e');
        $env['workspace'] = $agent->workspace();

        return ['env' => $env];
    }

    protected function envVar(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? $value : '';
    }
}
