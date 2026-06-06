# Command (create / update / help)

Use when creating or changing a **CLI command** (app or package), adding options/arguments, or improving command
help/tool doc.

## 1. Where and shape

- **Location:** `app/Command/` (and `app/Areas/*/Command/`). One class per command; no base class. Discovery:
  `CommandDiscovery::$files` path mappings via `ClassScannerInterface` + `extra.switon.commands` via
  `ComposerExtra::collect()`.
- **Entry:** `bash bin/console`. On Windows, run the equivalent command via `php switon.php`.
- **Actions:** Public methods `*Action()` (e.g. `user:create`, `user:delete`). One action = one small, focused
  operation. Use `#[Autowired]` for dependencies; inject `Switon\Core\ConsoleInterface` for output.

## 2. Naming

- **Command name:** Class minus `Command` suffix, PascalCase → kebab. `UserCommand` → `user`, `BackupDatabaseCommand` →
  `backup-database`.
- **Action name:** Method minus `Action`, camelCase → kebab. `defaultAction()` → default (invoke as `user` or
  `user:default`); `createAction()` → `user:create`.
- **Multiple actions:** When a command has more than one action, do not use `defaultAction`; give each action an
  explicit name (e.g. `definitionsAction`, `instancesAction`) so the list and usage stay clear.
- **Hide from list:** `#[Hidden]` (Switon\Command\Attribute\Hidden):
    - On class → hidden from default `list`; shown with `list --all`.
    - On method → that action hidden.
    - Consider for diagnostic commands so list stays app-focused.

## 3. Parameters (options and arguments)

- **Options:** Action params → options. `--name=value`; short alias from first letter (`$name` → `-n`). Use
  `#[ShortOption('x')]` when letters collide.
- **Positional:** Same params in declaration order; named options win, then positional.
- **Types:** `int`, `float`, `bool`, `array` converted. Boolean: `--force` = true. Array: `--tag=a --tag=b` →
  `['a','b']`.
- **Optional:** Sensible defaults; nullable; last `array` can collect rest.
- **Syntax:** `--long` (flag), `--long=value`, `--long value`, `-o value`; `--` separates options from positional.
  Prefer single-word names (`--force`, `--name`); multi-word: `--max-size` / `--max_size` / `--maxSize` → `$maxSize`.
- **Dangerous operations:** Confirm (e.g. `ask()`) or require explicit flags (`--force` etc.). Prefer a companion
  inspector/list or dry-run command to show impact first.

## 4. Help (PHPDoc) and tool doc

- **Default:** PHPDoc → help; `bash bin/console user:create --help` shows options from `@param`.
- **Custom (rare):** Implement `{actionName}Help()` (e.g. `createHelp()`), output usage + option table; then framework
  uses it, not `@param`.
- **PHPDoc best practices:**
    - **Class:** First line → command description. Short, terminal-friendly. No "Usage: php ..."; CLI generates it.
    - **Method:** First line → action description (what it does). No usage line there.
    - **@param:** One short line per param; plain text only (no `<code>`); count/order/names match signature.

### Tool doc (only for AI-facing commands)

- A **tool** is a command action with the attribute `#[Switon\Command\Attribute\Tool('...')]`; only these actions appear
  in
  `tool:list`.
- Use `#[Tool('...')]` on each AI-facing action so the agent knows its args and return shape; optionally add
  `#[Switon\Command\Attribute\Hidden]` to hide it from normal CLI help while keeping it visible in `tool:list`.
- `Tool('...')` is a short English summary; focus on arguments and return JSON, do **not** repeat the command name (the
  key is already e.g. `event:class`).

## 5. Exit codes and output

- **Return:** `void`/`null` → 0; `int` → that code; `string` → error message, exit 1. Prefer consistent codes (0
  success, 1 error, 2 invalid usage).
- **Output:** Use `ConsoleInterface` for success/info/warning/error and tables; `ask()`, progress bars for long tasks.
- **Errors:** Actionable (what failed, how to fix). No vague or surprising behavior.
- **Scriptability:** No TTY → no mandatory prompts; defaults or exit with clear message. For list/export, prefer
  `--plain` or `--json` for pipeable/parseable output.
