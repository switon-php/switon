<?php

declare(strict_types=1);

namespace Switon\Beacon\Command;

use Switon\Command\Attribute\Hidden;
use Switon\Command\Attribute\Tool;
use Switon\Command\CommandDiscoveryInterface;
use Switon\Command\CommandInspectorInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Switon\Core\Json;
use Switon\Core\Naming;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

use function basename;
use function class_exists;
use function explode;
use function is_string;
use function ksort;
use function str_contains;
use function strtolower;
use function ucfirst;

/**
 * AI-facing: tool:list (tools), tool:commands (all CLI commands), tool:description (one command), tool:entry (topic to entry class) — all output JSON only.
 *
 * Road-signs:
 * - `*Command` class name decides command name (`FooBarCommand` -> `foo-bar`)
 * - `*Action` method name decides action name (`checkAction` -> `:check`)
 * - If you want `config:check`, put `checkAction()` into `ConfigCommand` (do not create `ConfigCheckCommand`)
 *
 * @see \Switon\Cli\Command\HelpCommand User-facing help (e.g. sword --help).
 * @see \Switon\Command\CommandDiscoveryInterface
 * @see \Switon\Command\CommandInspectorInterface
 * @see \Switon\Command\Attribute\Tool
 * @see \Switon\Cli\Command\ListCommand
 */
#[Hidden]
class ToolCommand
{
    #[Autowired] protected ConsoleInterface $console;
    #[Autowired] protected CommandDiscoveryInterface $commandDiscovery;
    #[Autowired] protected CommandInspectorInterface $commandInspector;

    /**
     * Tool list: JSON mapping of invocation to tool doc (only actions with #[Tool]); for human read use tool:list with jq
     */
    #[Tool('Returns JSON: invocation => tool doc string per entry (single line, no banner). For human read use tool:list | jq.')]
    public function listAction(): int
    {
        $list = [
            '_conventions' => 'Filter = substring or wildcards * ?; exit 0 = success.',
        ];
        foreach ($this->commandDiscovery->discover() as $name => $definition) {
            if (!is_string($definition) || !class_exists($definition)) {
                continue;
            }
            $plainName = ucfirst($name);
            $commandName = Naming::snake(basename($plainName, 'Command'));
            $actionDescriptions = $this->commandInspector->getActions($definition);
            foreach ($actionDescriptions as $action => $actionDesc) {
                $aiDoc = $this->commandInspector->getActionAiDoc($definition, $action);
                if ($aiDoc === null) {
                    continue;
                }
                $actionKebab = $action === 'default' ? $action : Naming::kebab($action);
                $invocation = $actionKebab === 'default' ? $commandName : ($commandName . ':' . $actionKebab);
                $list[$invocation] = $aiDoc;
            }
        }
        ksort($list);
        $this->console->writeLn(Json::stringify($list));
        return 0;
    }

    /**
     * All CLI commands: JSON mapping of invocation to description (all actions, expanded)
     */
    #[Tool('Returns JSON: invocation => description per entry, all CLI commands.')]
    public function commandsAction(): int
    {
        $list = [];
        foreach ($this->commandDiscovery->discover() as $name => $definition) {
            if (!is_string($definition) || !class_exists($definition)) {
                continue;
            }
            $plainName = ucfirst($name);
            $commandName = Naming::snake(basename($plainName, 'Command'));
            $actionDescriptions = $this->commandInspector->getActions($definition);
            foreach ($actionDescriptions as $action => $actionDesc) {
                $actionKebab = $action === 'default' ? $action : Naming::kebab($action);
                $invocation = $actionKebab === 'default' ? $commandName : ($commandName . ':' . $actionKebab);
                $list[$invocation] = $actionDesc !== ''
                    ? $actionDesc
                    : ($this->commandInspector->getActionAiDoc($definition, $action) ?? '');
            }
        }
        ksort($list);
        $this->console->writeLn(Json::stringify($list));
        return 0;
    }

    /**
     * One command description: JSON command, description, actions with options.
     *
     * @param string $invocation Invocation (name or name:action)
     */
    #[Tool('<invocation>. Omit :action (e.g. tool:description router) to get all actions. Returns JSON: command, description, actions[].')]
    public function descriptionAction(string $invocation = ''): int
    {
        if ($invocation === '') {
            $this->console->writeLn(Json::stringify(['error' => 'invocation required', 'usage' => 'tool:description <invocation>']));
            return 1;
        }
        if (str_contains($invocation, ':')) {
            [$command, $action] = explode(':', $invocation, 2);
        } else {
            $command = $invocation;
            $action = '';
        }
        $commands = $this->commandDiscovery->discover();
        $commandKey = Naming::kebab($command);
        if (($definition = $commands[$commandKey] ?? null) === null) {
            $this->console->writeLn(Json::stringify(['error' => 'Command not found', 'command' => $commandKey]));
            return 1;
        }

        $commandName = $commandKey;
        $description = $this->commandInspector->getCommandDescription($definition);

        $this->console->writeLn($this->buildCommandHelpJson($commandName, $description, $definition, $action));
        return 0;
    }

    /**
     * Entry map for framework concepts.
     *
     * @param string $topic Optional topic filter
     */
    #[Tool('Returns JSON: entries[] with topic and class. Optional filter: tool:entry <topic>.')]
    public function entryAction(string $topic = ''): int
    {
        $entries = $this->getEntryMap();

        if ($topic !== '') {
            $key = strtolower($topic);
            $entry = $entries[$key] ?? null;
            $list = $entry === null ? [] : [['topic' => $key, 'class' => $entry]];
            $this->console->writeLn(Json::stringify(['entries' => $list]));
            return 0;
        }

        $list = [];
        foreach ($entries as $name => $class) {
            $list[] = ['topic' => $name, 'class' => $class];
        }
        $this->console->writeLn(Json::stringify(['entries' => $list]));
        return 0;
    }

    /**
     * Builds machine-readable JSON for one command's help (reflection only, no instantiation).
     *
     * @param class-string $definition
     */
    protected function buildCommandHelpJson(
        string $commandName,
        string $description,
        string $definition,
        string $action
    ): string {
        $actions = [];
        $rClass = new ReflectionClass($definition);
        foreach ($rClass->getMethods(ReflectionMethod::IS_PUBLIC) as $rMethod) {
            $method = $rMethod->getName();
            if (!preg_match('#^([a-z].*)Action$#', $method, $match)) {
                continue;
            }
            $actionName = $match[1];
            $actionKebab = Naming::kebab($actionName);
            if ($action !== '' && $actionKebab !== $action) {
                continue;
            }
            try {
                $inv = $actionName === 'default' ? $commandName : ($commandName . ':' . $actionKebab);
                $actionDesc = $this->commandInspector->getMethodDescription($rMethod);
                $opts = $this->commandInspector->getOptions($rMethod);
            } catch (Throwable $e) {
                continue;
            }
            $options = [];
            foreach ($opts as $name => $meta) {
                $options[] = [
                    'name' => $name,
                    'type' => $meta['type'],
                    'default' => $this->jsonSafeDefault($meta['default']),
                    'description' => $meta['description'],
                ];
            }
            $actions[] = [
                'name' => $actionKebab,
                'invocation' => $inv,
                'description' => $actionDesc,
                'options' => $options,
            ];
        }
        return Json::stringify([
            'command' => $commandName,
            'description' => $description,
            'actions' => $actions,
        ]);
    }

    protected function jsonSafeDefault(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (is_scalar($v) || $v === null) {
                    $out[$k] = $v;
                }
            }
            return $out;
        }
        return null;
    }

    /**
     * @return array<string, class-string>
     */
    protected function getEntryMap(): array
    {
        return [
            'autowired' => \Switon\Core\Attribute\Autowired::class,
            'authorization' => \Switon\Authorizing\AuthorizationInterface::class,
            'cache' => \Switon\Caching\SimpleCache::class,
            'command-discovery' => \Switon\Command\CommandDiscoveryInterface::class,
            'command-inspector' => \Switon\Command\CommandInspectorInterface::class,
            'config' => \Switon\Kernel\Config::class,
            'container' => \Switon\Core\ContainerInterface::class,
            'context' => \Switon\Core\ContextManagerInterface::class,
            'controller' => \Switon\Http\Controller::class,
            'db' => \Switon\Db\ClientInterface::class,
            'entrypoint' => \Switon\Kernel\KernelInterface::class,
            'event' => \Switon\Eventing\Attribute\EventListener::class,
            'exception' => \Switon\Http\ExceptionDispatcherInterface::class,
            'filesystem' => \Switon\Core\FilesystemInterface::class,
            'http-status' => \Switon\Http\DefaultExceptionHandler::class,
            'http-client' => \Switon\HttpClient\HttpClientInterface::class,
            'i18n' => \Switon\Core\LocaleInterface::class,
            'identity' => \Switon\Principal\IdentityInterface::class,
            'interceptor' => \Switon\Invocation\Attribute\InterceptorInterface::class,
            'invoker' => \Switon\Invoking\InvokerInterface::class,
            'jwt' => \Switon\Jwt\JwtInterface::class,
            'logging' => \Switon\Logging\LoggerInterface::class,
            'filter' => \Switon\Http\FilterDiscoveryInterface::class,
            'orm' => \Switon\Orm\EntityManagerInterface::class,
            'parameter-injection' => \Switon\Binding\ArgumentsBinderInterface::class,
            'path-alias' => \Switon\Core\PathAliasInterface::class,
            'query' => \Switon\Query\QueryInterface::class,
            'redis' => \Switon\Redis\ClientInterface::class,
            'request' => \Switon\Http\RequestHandlerInterface::class,
            'renderer' => \Switon\Rendering\RendererInterface::class,
            'routing' => \Switon\Routing\RouterInterface::class,
            'service-provider' => \Switon\Core\ServiceProviderInterface::class,
            'session' => \Switon\Session\SessionInterface::class,
            'sharding' => \Switon\Sharding\ShardingManagerInterface::class,
            'validation' => \Switon\Validating\ValidatorInterface::class,
            'view' => \Switon\Viewing\ViewInterface::class,
        ];
    }
}
