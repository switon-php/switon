<?php

declare(strict_types=1);

namespace Switon\McpClient\Command;

use Switon\Ai\ToolSchema;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Switon\Core\FilesystemInterface;
use Switon\Core\InputInterface;
use Switon\Core\Json;
use Switon\McpClient\ClientsInterface;
use Switon\McpClient\Exception;
use Switon\McpClient\Importer\Entries;
use Switon\McpClient\Importer\SwitonYamlImporter;
use Switon\McpClient\ImportersInterface;
use Switon\Tooling\ArgumentBinderInterface;
use Switon\Tooling\Exception\ArgumentBindException;
use Switon\Yaml\YamlReaderInterface;
use Switon\Yaml\YamlWriterInterface;
use Throwable;

use function array_is_list;
use function array_merge;
use function array_slice;
use function array_values;
use function explode;
use function implode;
use function is_array;
use function is_string;
use function str_contains;
use function str_starts_with;
use function stream_get_contents;
use function substr;
use function trim;

/**
 * Inspect, import, and call MCP servers from `~/.switon/mcp.yml`.
 */
class McpClientCommand
{
    #[Autowired] protected ConsoleInterface $console;
    #[Autowired] protected ClientsInterface $clients;
    #[Autowired] protected ArgumentBinderInterface $argumentBinder;
    #[Autowired] protected FilesystemInterface $filesystem;
    #[Autowired] protected ImportersInterface $importers;
    #[Autowired] protected InputInterface $input;
    #[Autowired] protected YamlReaderInterface $yamlReader;
    #[Autowired] protected YamlWriterInterface $yamlWriter;

    /** Same default as {@see \Switon\McpClient\ClientDiscovery}. */
    #[Autowired] protected string $file = '~/.switon/mcp.yml';

    /** Print client id and brief. Does not connect. */
    public function listAction(): int
    {
        $rows = [];
        foreach ($this->clients->all() as $id => $client) {
            $rows[] = [$id, $client->brief()];
        }
        if ($rows === []) {
            $this->console->writeLn('No MCP servers.');

            return ConsoleInterface::SUCCESS;
        }

        $this->console->table(['id', 'brief'], $rows);

        return ConsoleInterface::SUCCESS;
    }

    /**
     * Print one server's entry from `~/.switon/mcp.yml`. Does not connect.
     *
     * @param string $server MCP server id (`mcp.yml` key).
     */
    public function getAction(string $server): int
    {
        try {
            $entries = $this->loadCatalog(requireFile: true);
        } catch (Throwable $e) {
            $this->console->writeLn($e->getMessage());

            return ConsoleInterface::FAILURE;
        }

        $entry = $entries[$server] ?? null;
        if (!is_array($entry)) {
            $this->console->writeLn('Unknown MCP server "' . $server . '".');

            return ConsoleInterface::FAILURE;
        }

        $this->console->writeLn(rtrim($this->yamlWriter->dump([$server => $entry])));

        return ConsoleInterface::SUCCESS;
    }

    /**
     * Add a server to `~/.switon/mcp.yml`. Pass `--url=` or `-- <command> [args...]` (not both).
     *
     * Named options (repeatable): `--env=KEY=VALUE` (stdio), `--header=Name=value` (HTTP).
     *
     * @param string $server MCP server id (`mcp.yml` key).
     * @param bool $force Replace an existing id.
     * @param string $url HTTP MCP endpoint (`--url=`).
     * @param list<string> $command Stdio launcher after `--` (executable first).
     */
    public function addAction(string $server, bool $force = false, string $url = '', array $command = []): int
    {
        if ($url !== '' && $command !== []) {
            $this->console->writeLn('Pass either --url= or -- <command> [args...], not both.');

            return ConsoleInterface::INVALID;
        }
        if ($url === '' && $command === []) {
            $this->console->writeLn('Pass --url= or -- <command> [args...].');

            return ConsoleInterface::INVALID;
        }
        if ($command !== [] && ($command[0] ?? '') === '') {
            $this->console->writeLn('Command after -- must start with an executable.');

            return ConsoleInterface::INVALID;
        }

        try {
            $env = $this->optionPairs('env');
            $headers = $this->optionPairs('header');
        } catch (Throwable $e) {
            $this->console->writeLn($e->getMessage());

            return ConsoleInterface::INVALID;
        }
        if ($env !== [] && $url !== '') {
            $this->console->writeLn('--env applies to stdio servers; use -- <command>, not --url=.');

            return ConsoleInterface::INVALID;
        }
        if ($headers !== [] && $command !== []) {
            $this->console->writeLn('--header applies to HTTP servers; use --url=, not -- <command>.');

            return ConsoleInterface::INVALID;
        }

        try {
            $entries = $this->loadCatalog(requireFile: false);
        } catch (Throwable $e) {
            $this->console->writeLn($e->getMessage());

            return ConsoleInterface::FAILURE;
        }

        if (isset($entries[$server]) && !$force) {
            $this->console->writeLn('MCP server "' . $server . '" already exists; pass --force to replace.');

            return ConsoleInterface::INVALID;
        }

        if ($url !== '') {
            $entry = ['url' => $url];
            if ($headers !== []) {
                $entry['headers'] = $headers;
            }
        } else {
            $entry = $this->stdioEntry($command);
            if ($env !== []) {
                $entry['env'] = $env;
            }
        }
        $entries[$server] = $entry;

        return $this->persistCatalog($entries, $server);
    }

    /**
     * Remove a server from `~/.switon/mcp.yml`.
     *
     * @param string $server MCP server id (`mcp.yml` key).
     */
    public function removeAction(string $server): int
    {
        try {
            $entries = $this->loadCatalog(requireFile: true);
        } catch (Throwable $e) {
            $this->console->writeLn($e->getMessage());

            return ConsoleInterface::FAILURE;
        }

        if (!isset($entries[$server])) {
            $this->console->writeLn('Unknown MCP server "' . $server . '".');

            return ConsoleInterface::FAILURE;
        }

        unset($entries[$server]);

        try {
            $this->yamlWriter->write($this->file, $entries);
        } catch (Throwable $e) {
            $this->console->writeLn($e->getMessage());

            return ConsoleInterface::FAILURE;
        }
        $this->console->writeLn('Removed "' . $server . '".');

        return ConsoleInterface::SUCCESS;
    }

    /**
     * Clear `enabled: false` for a server (or leave already-enabled entries unchanged).
     *
     * @param string $server MCP server id (`mcp.yml` key).
     */
    public function enableAction(string $server): int
    {
        return $this->setEnabled($server, true);
    }

    /**
     * Set `enabled: false` for a server without deleting it.
     *
     * @param string $server MCP server id (`mcp.yml` key).
     */
    public function disableAction(string $server): int
    {
        return $this->setEnabled($server, false);
    }

    /**
     * List tools on one server (connects).
     *
     * @param string $server MCP server id (`mcp.yml` key).
     */
    public function toolsAction(string $server): int
    {
        try {
            $catalog = $this->clients->get($server)->listTools(false);
        } catch (Throwable $e) {
            $this->console->writeLn($e->getMessage());

            return ConsoleInterface::FAILURE;
        }
        if ($catalog === []) {
            $this->console->writeLn('No tools.');

            return ConsoleInterface::SUCCESS;
        }

        $rows = [];
        foreach ($catalog as $name => $schema) {
            $rows[] = [
                $name,
                (string) ($schema->definition['description'] ?? ''),
            ];
        }
        $this->console->table(['name', 'description'], $rows);

        return ConsoleInterface::SUCCESS;
    }

    /**
     * Print one tool's description and argument schema (connects).
     *
     * @param string $server MCP server id (`mcp.yml` key).
     * @param string $tool Remote tool name.
     */
    public function schemaAction(string $server, string $tool): int
    {
        try {
            $schema = $this->remoteSchema($server, $tool);
        } catch (Throwable $e) {
            $this->console->writeLn($e->getMessage());

            return ConsoleInterface::FAILURE;
        }
        $this->console->writeLn((string) ($schema->definition['description'] ?? ''));
        $this->console->writeLn(Json::stringify(
            $schema->definition['inputSchema'] ?? ['type' => 'object', 'properties' => []],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return ConsoleInterface::SUCCESS;
    }

    /**
     * Ping one server (connects / reuses the session).
     *
     * @param string $server MCP server id (`mcp.yml` key).
     */
    public function pingAction(string $server): int
    {
        try {
            $this->clients->get($server)->ping();
        } catch (Throwable $e) {
            $this->console->writeLn($e->getMessage());

            return ConsoleInterface::FAILURE;
        }
        $this->console->writeLn('ok');

        return ConsoleInterface::SUCCESS;
    }

    /**
     * Import from every registered `extra.switon.mcp.importers` path that exists into `~/.switon/mcp.yml`.
     *
     * @param bool $force Overwrite an existing `~/.switon/mcp.yml`; appends our file last so its ids win.
     * @param bool $dry Print sources and id/brief only; do not write or refuse an existing file.
     */
    public function importAction(bool $force = false, bool $dry = false): int
    {
        if (!$dry && $this->filesystem->exists($this->file) && !$force) {
            $this->console->writeLn('~/.switon/mcp.yml already exists; pass --force to overwrite.');

            return ConsoleInterface::INVALID;
        }
        if ($force) {
            $this->importers->set($this->file, SwitonYamlImporter::class);
        }

        $entries = [];
        $found = [];
        foreach ($this->importers->all() as $path => $_) {
            if (!$this->filesystem->exists($path)) {
                continue;
            }
            try {
                $entries = array_merge($entries, $this->importers->import($path));
            } catch (Throwable $e) {
                $this->console->writeLn($e->getMessage());

                return ConsoleInterface::FAILURE;
            }
            $found[] = $path;
        }

        if ($found === []) {
            $this->console->writeLn('No MCP configs found.');

            return ConsoleInterface::SUCCESS;
        }

        if (!$dry) {
            try {
                $this->yamlWriter->write($this->file, $entries);
            } catch (Throwable $e) {
                $this->console->writeLn($e->getMessage());

                return ConsoleInterface::FAILURE;
            }
        }

        $this->console->writeLn('From:');
        foreach ($found as $path) {
            $this->console->writeLn('  ' . $path);
        }
        if ($entries === []) {
            $this->console->writeLn('No servers imported into ' . $this->file . '.');

            return ConsoleInterface::SUCCESS;
        }

        $rows = [];
        foreach ($entries as $id => $entry) {
            $rows[] = [$id, Entries::brief($entry)];
        }
        $this->console->table(['id', 'brief'], $rows);

        return ConsoleInterface::SUCCESS;
    }

    /**
     * Call one tool. Arguments are a JSON object, `@path`, or `-`/`@-` for stdin; bound before send.
     *
     * @param string $server MCP server id (`mcp.yml` key).
     * @param string $tool Remote tool name.
     * @param string $arguments JSON object, `@file`, or `-` / `@-` for stdin (default `{}`).
     * @param bool $raw Print the wire `tools/call` result JSON instead of text blocks.
     */
    public function callAction(string $server, string $tool, string $arguments = '{}', bool $raw = false): int
    {
        try {
            $args = $this->loadArguments($arguments);
        } catch (Throwable $e) {
            $this->console->writeLn($e->getMessage());

            return ConsoleInterface::INVALID;
        }

        try {
            $client = $this->clients->get($server);
            $schema = $this->remoteSchema($server, $tool);
        } catch (Throwable $e) {
            $this->console->writeLn($e->getMessage());

            return ConsoleInterface::FAILURE;
        }

        try {
            $args = $this->argumentBinder->bind($schema, $args);
        } catch (ArgumentBindException $e) {
            $this->console->writeLn($e->getMessage());

            return ConsoleInterface::INVALID;
        }

        try {
            $result = $client->callTool($tool, $args, raw: true);
        } catch (Throwable $e) {
            $this->console->writeLn($e->getMessage());

            return ConsoleInterface::FAILURE;
        }

        $this->console->writeLn($this->presentResult($result, $raw));

        if (is_array($result) && ($result['isError'] ?? false) === true) {
            return ConsoleInterface::FAILURE;
        }

        return ConsoleInterface::SUCCESS;
    }

    protected function setEnabled(string $server, bool $enabled): int
    {
        try {
            $entries = $this->loadCatalog(requireFile: true);
        } catch (Throwable $e) {
            $this->console->writeLn($e->getMessage());

            return ConsoleInterface::FAILURE;
        }

        if (!isset($entries[$server]) || !is_array($entries[$server])) {
            $this->console->writeLn('Unknown MCP server "' . $server . '".');

            return ConsoleInterface::FAILURE;
        }

        $entry = $entries[$server];
        if ($enabled) {
            unset($entry['enabled']);
        } else {
            $entry['enabled'] = false;
        }
        $entries[$server] = $entry;

        return $this->persistCatalog($entries, $server);
    }

    /**
     * @param list<string> $command
     *
     * @return array{command: string, args?: list<string>}
     */
    protected function stdioEntry(array $command): array
    {
        $entry = ['command' => $command[0]];
        $args = array_slice($command, 1);
        if ($args !== []) {
            $entry['args'] = $args;
        }

        return $entry;
    }

    /**
     * Reads repeatable `--name=KEY=VALUE` (or a single string) from CLI input.
     *
     * @return array<string, string>
     */
    protected function optionPairs(string $name): array
    {
        if (!$this->input->has($name)) {
            return [];
        }
        $raw = $this->input->get($name);
        if ($raw === true || $raw === false || $raw === null || $raw === '') {
            Exception::raise('Each --{name} must be KEY=VALUE.', ['name' => $name]);
        }
        $list = is_array($raw) ? $raw : [$raw];
        $out = [];
        foreach ($list as $pair) {
            if (!is_string($pair) || !str_contains($pair, '=')) {
                Exception::raise('Each --{name} must be KEY=VALUE.', ['name' => $name]);
            }
            [$key, $value] = explode('=', $pair, 2);
            if ($key === '') {
                Exception::raise('Each --{name} must be KEY=VALUE.', ['name' => $name]);
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadCatalog(bool $requireFile): array
    {
        if (!$this->filesystem->exists($this->file)) {
            if ($requireFile) {
                Exception::raise('No ~/.switon/mcp.yml.');
            }

            return [];
        }

        /** @var array<string, mixed> $entries */
        $entries = $this->yamlReader->load($this->file);

        return $entries;
    }

    /**
     * @param array<string, mixed> $entries
     */
    protected function persistCatalog(array $entries, string $server): int
    {
        try {
            $this->yamlWriter->write($this->file, $entries);
        } catch (Throwable $e) {
            $this->console->writeLn($e->getMessage());

            return ConsoleInterface::FAILURE;
        }
        $this->console->writeLn(rtrim($this->yamlWriter->dump([$server => $entries[$server]])));

        return ConsoleInterface::SUCCESS;
    }

    protected function remoteSchema(string $server, string $tool): ToolSchema
    {
        $schema = $this->clients->get($server)->listTools(false)[$tool] ?? null;
        if (!$schema instanceof ToolSchema) {
            Exception::raise('Unknown MCP tool "{tool}" on "{server}".', ['tool' => $tool, 'server' => $server]);
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadArguments(string $arguments): array
    {
        $arguments = trim($arguments);
        if ($arguments === '-' || $arguments === '@-') {
            $raw = stream_get_contents(STDIN);
            if ($raw === false) {
                Exception::raise('Failed to read arguments from stdin.');
            }

            return $this->decodeArguments($raw);
        }
        if (str_starts_with($arguments, '@') && $arguments !== '@') {
            return $this->decodeArguments($this->filesystem->read(substr($arguments, 1)));
        }

        return $this->decodeArguments($arguments);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeArguments(string $arguments): array
    {
        $arguments = trim($arguments);
        if ($arguments === '') {
            return [];
        }

        $decoded = Json::parse($arguments);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            Exception::raise('Arguments must be a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    protected function presentResult(mixed $result, bool $raw): string
    {
        if ($raw || !is_array($result)) {
            return Json::stringify($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $text = $this->textFromResult($result);
        if ($text !== '') {
            return $text;
        }

        return Json::stringify($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $result */
    protected function textFromResult(array $result): string
    {
        $content = $result['content'] ?? null;
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                    $parts[] = $block['text'];
                }
            }
            if ($parts !== []) {
                return implode("\n", $parts);
            }
        }

        return is_string($result['text'] ?? null) ? $result['text'] : '';
    }
}
