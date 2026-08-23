<?php

declare(strict_types=1);

namespace Switon\Agency\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Agency\ProfilesInterface;
use Switon\Agency\Segments;
use Switon\Agency\SegmentsInterface;
use Switon\Agency\Tests\CatalogTestCase;

#[CoversClass(Segments::class)]
class SegmentsCatalogTest extends CatalogTestCase
{
    protected function setUpContainer(): void
    {
        parent::setUpContainer();
        $this->useProfilePaths(['@agency/agents']);
    }

    public function testDiscoversBuiltinSegments(): void
    {
        /** @var SegmentsInterface $segments */
        $segments = $this->container->get(SegmentsInterface::class);

        $this->assertTrue($segments->has('copyable'));
        $this->assertTrue($segments->has('response'));
        $this->assertTrue($segments->has('workspace'));
        $this->assertTrue($segments->has('env'));
        $this->assertTrue($segments->has('assignment'));
        $this->assertTrue($segments->has('transcript'));
        $this->assertTrue($segments->has('mcp'));
        $this->assertFalse($segments->has('task-run'));
        $this->assertTrue($segments->has('task-deliver'));
        $this->assertSame(['mcp_servers', 'mcp_tools', 'mcp_schema', 'mcp_call'], $segments->get('mcp')->tools);
        $this->assertStringContainsString('Copyable blocks', $segments->get('copyable')->prompt);
        $this->assertStringContainsString('transcript.md', $segments->get('transcript')->prompt);
        $this->assertStringContainsString('tools: name1, name2', $segments->get('transcript')->prompt);
        $this->assertStringContainsString('{{ env.os }}', $segments->get('env')->prompt);
        $this->assertStringContainsString('{{ env.workspace }}', $segments->get('env')->prompt);
    }

    public function testResponseSegmentDefinesConciseStyle(): void
    {
        /** @var SegmentsInterface $segments */
        $segments = $this->container->get(SegmentsInterface::class);

        $response = $segments->get('response');
        $this->assertSame([], $response->tools);
        $this->assertStringContainsString('has a distinct conclusion or outcome', $response->prompt);
        $this->assertStringContainsString('state it in plain prose before supporting Markdown structure', $response->prompt);
        $this->assertStringContainsString('Do not add a prose lead that merely repeats a self-contained requested list, table, code block', $response->prompt);
        $this->assertStringContainsString('Never begin with a heading unless a required format says otherwise', $response->prompt);
        $this->assertStringContainsString('Do not restate the request as a title or standalone bold label', $response->prompt);
        $this->assertStringContainsString('begin with the answer itself', $response->prompt);
        $this->assertStringContainsString('fewest non-overlapping sections', $response->prompt);
        $this->assertStringContainsString('Derive headings from the content', $response->prompt);
        $this->assertStringContainsString('order them by importance', $response->prompt);
        $this->assertStringContainsString('omit sections that add no value', $response->prompt);
        $this->assertStringContainsString('supporting points are parallel and independently scannable', $response->prompt);
        $this->assertStringContainsString('Keep connected reasoning together', $response->prompt);
        $this->assertStringContainsString('a numbered list when the sequence itself matters', $response->prompt);
        $this->assertStringContainsString('Do not turn a single point into a list', $response->prompt);
        $this->assertStringContainsString('Stop once the answer is supported', $response->prompt);
        $this->assertStringContainsString('only restates the conclusion', $response->prompt);
        $this->assertStringContainsString('Stay within the requested scope and depth', $response->prompt);
        $this->assertStringContainsString('implementation advice, recommendations, or next steps', $response->prompt);
        $this->assertStringContainsString('necessary to avoid a materially misleading answer', $response->prompt);
        $this->assertStringContainsString('Skip the multi-part shape', $response->prompt);
        $this->assertStringContainsString('commentary preamble', $response->prompt);
        $this->assertStringContainsString('supplied context or conversation', $response->prompt);
        $this->assertStringNotContainsString('One sentence when enough', $response->prompt);
    }

    public function testResponseSegmentDefinesTaskOutcomeProtocol(): void
    {
        /** @var SegmentsInterface $segments */
        $segments = $this->container->get(SegmentsInterface::class);

        $response = $segments->get('response');
        $this->assertStringContainsString('**Done**, **Partial**, or **Blocked**', $response->prompt);
        $this->assertStringContainsString("specialist's required format wins", $response->prompt);
        $this->assertStringContainsString('not for a direct user reply', $response->prompt);
    }

    public function testVerificationSegmentStopsUnchangedRetries(): void
    {
        /** @var SegmentsInterface $segments */
        $segments = $this->container->get(SegmentsInterface::class);

        $verification = $segments->get('verification');
        $this->assertStringContainsString('Do not repeat a failing check unchanged', $verification->prompt);
        $this->assertStringContainsString('after two identical failures', $verification->prompt);
        $this->assertStringContainsString('changed input or hypothesis needed', $verification->prompt);
    }

    public function testVerificationSegmentRequiresExecutedComputation(): void
    {
        /** @var SegmentsInterface $segments */
        $segments = $this->container->get(SegmentsInterface::class);

        $verification = $segments->get('verification');
        $this->assertStringContainsString('numeric, rendered, or tabular conclusions', $verification->prompt);
        $this->assertStringContainsString('executed code or a specialized tool', $verification->prompt);
        $this->assertStringContainsString('not mental arithmetic', $verification->prompt);
    }

    public function testVerificationSegmentInvalidatesStaleChecks(): void
    {
        /** @var SegmentsInterface $segments */
        $segments = $this->container->get(SegmentsInterface::class);

        $verification = $segments->get('verification');
        $this->assertStringContainsString('started before the latest relevant edit', $verification->prompt);
        $this->assertStringContainsString('rerun the applicable check', $verification->prompt);
        $this->assertStringContainsString('test or artifact created from a check executed', $verification->prompt);
        $this->assertStringContainsString('final status, exit code, or test summary', $verification->prompt);
    }

    public function testWorkspaceSegmentDefinesWorkspaceContext(): void
    {
        /** @var SegmentsInterface $segments */
        $segments = $this->container->get(SegmentsInterface::class);

        $workspace = $segments->get('workspace');
        $this->assertSame([], $workspace->tools);
        $this->assertStringContainsString('Use absolute paths for workspace files', $workspace->prompt);
        $this->assertStringContainsString('{{ env.workspace }}', $workspace->prompt);
        $this->assertStringContainsString('nearest `AGENTS.md`', $workspace->prompt);
        $this->assertStringContainsString('Walk up from each target path', $workspace->prompt);
        $this->assertStringContainsString('nearest relevant `README.md`', $workspace->prompt);
        $this->assertStringContainsString('Never print or copy secret values', $workspace->prompt);
        $markdown = $segments->get('markdown-output')->prompt;
        $this->assertStringContainsString(
            'workspace-relative `path/to/file.php:123`',
            $markdown,
        );
        $this->assertStringContainsString('only where they improve scanability', $markdown);
        $this->assertStringContainsString('grouping multiple related points', $markdown);
        $this->assertStringContainsString('single list item or reasoning step', $markdown);
        $this->assertStringContainsString('use bullets (`-`) by default for parallel points', $markdown);
        $this->assertStringContainsString('Use numbers (`1.`) only when changing the order would change the meaning', $markdown);
        $this->assertStringContainsString('item count alone does not make a list ordered', $markdown);
        $this->assertStringContainsString('Use Markdown to expose structure, not decorate prose', $markdown);
        $this->assertStringContainsString('not as a standalone pseudo-heading', $markdown);
        $this->assertStringContainsString('not thematic breaks', $markdown);
        $this->assertStringContainsString('Do not add emoji, status icons, or colored callout styling', $markdown);
        $this->assertStringContainsString('only when multiple rows or columns make a comparison or mapping easier to scan', $markdown);
        $this->assertStringContainsString('Represent a hierarchy as a nested list by default', $markdown);
        $this->assertStringContainsString('only when the user explicitly requests that representation', $markdown);
        $this->assertStringContainsString('Put character-aligned output in a `text` fence', $markdown);
        $this->assertStringContainsString('prefer simple labeled edges over decorative boxes', $markdown);
        $this->assertStringContainsString('Use blockquotes only for text presented as a quotation', $markdown);
        $this->assertStringContainsString('explicitly excluded relationship', $markdown);
        $this->assertStringContainsString('Do not draw an excluded edge', $markdown);
        $this->assertStringContainsString('do not restate the same topology as a list, table, or arrow inventory', $markdown);
    }

    public function testWorkspaceSegmentRequiresCurrentEvidenceAndSafeChanges(): void
    {
        /** @var SegmentsInterface $segments */
        $segments = $this->container->get(SegmentsInterface::class);

        $workspace = $segments->get('workspace');
        $this->assertStringContainsString('current tool evidence', $workspace->prompt);
        $this->assertStringContainsString('"already verified" bypass a cheap check', $workspace->prompt);
        $this->assertStringContainsString('Never revert, reset, or discard changes you did not make', $workspace->prompt);
    }

    public function testWorkspaceSegmentReusesEvidenceAndFollowsTruncation(): void
    {
        /** @var SegmentsInterface $segments */
        $segments = $this->container->get(SegmentsInterface::class);

        $workspace = $segments->get('workspace');
        $this->assertStringContainsString('Reuse recent tool evidence while file, configuration, runtime state, or task scope is unchanged', $workspace->prompt);
        $this->assertStringContainsString('a steering message alone is not a change', $workspace->prompt);
        $this->assertStringContainsString('truncated or abbreviated output as partial', $workspace->prompt);
        $this->assertStringContainsString('fetch the needed range before asserting', $workspace->prompt);
    }

    public function testExecutionPolicyKeepsCorrectionsActive(): void
    {
        /** @var SegmentsInterface $segments */
        $segments = $this->container->get(SegmentsInterface::class);

        $policy = $segments->get('execution-policy');
        $this->assertStringContainsString('corrections and scope constraints active until explicitly lifted', $policy->prompt);
        $this->assertStringContainsString('steering the active task', $policy->prompt);
    }

    public function testExecutionPolicyKeepsCurrentToolsAndExactValues(): void
    {
        /** @var SegmentsInterface $segments */
        $segments = $this->container->get(SegmentsInterface::class);

        $policy = $segments->get('execution-policy');
        $this->assertStringContainsString('only tools exposed in the current charter', $policy->prompt);
        $this->assertStringContainsString('conversation history does not revive an unavailable tool', $policy->prompt);
        $this->assertStringContainsString('Preserve load-bearing user-provided values exactly', $policy->prompt);
        $this->assertStringContainsString('normalized or similar variants', $policy->prompt);
    }

    public function testParallelSegmentAvoidsRepeatedIdenticalCalls(): void
    {
        /** @var SegmentsInterface $segments */
        $segments = $this->container->get(SegmentsInterface::class);

        $parallel = $segments->get('parallel');
        $this->assertStringContainsString('Once a call has produced useful results', $parallel->prompt);
        $this->assertStringContainsString('do not repeat the same tool and arguments', $parallel->prompt);
    }

    public function testDispatchSegmentsOwnSeparateCapabilities(): void
    {
        /** @var SegmentsInterface $segments */
        $segments = $this->container->get(SegmentsInterface::class);

        $assignment = $segments->get('assignment');
        $taskDeliver = $segments->get('task-deliver');

        $this->assertSame([], $assignment->tools);
        $this->assertStringContainsString('Every `agent_*` call is an intelligent tool', $assignment->prompt);
        $this->assertStringContainsString('do not delegate work your own tools can finish', $assignment->prompt);
        $this->assertStringContainsString('yields to any mandatory delegation route in the current profile', $assignment->prompt);
        $this->assertStringContainsString('must call `agent_diagram`', $assignment->prompt);
        $this->assertStringContainsString('Explicitly requested ASCII remains direct host output', $assignment->prompt);
        $this->assertStringContainsString('Copy each supplied entity, directed relationship, condition, branch, and explicitly excluded relationship into the assignment', $assignment->prompt);
        $this->assertStringContainsString('only when the diagram alone satisfies the whole user request', $assignment->prompt);
        $this->assertStringContainsString('is not diagram-only', $assignment->prompt);
        $this->assertStringContainsString('embed the returned fence verbatim', $assignment->prompt);
        $this->assertStringNotContainsString('accepts a `message` argument', $assignment->prompt);
        $this->assertStringContainsString('Pick the narrowest matching capability', $assignment->prompt);
        $this->assertStringContainsString('`as_reply: true`', $assignment->prompt);
        $this->assertStringContainsString("entirely in the user's language", $assignment->prompt);
        $this->assertStringContainsString('only on the last tool call', $assignment->prompt);
        $this->assertStringNotContainsString('task_deliver', $assignment->prompt);
        $this->assertStringNotContainsString('agent spec id', $assignment->prompt);

        $this->assertSame(['task_deliver'], $taskDeliver->tools);
        $this->assertStringContainsString('[signal:task_finish]', $taskDeliver->prompt);
    }

    public function testSkillsSegmentDeclaresProgressiveLoadTools(): void
    {
        /** @var SegmentsInterface $directives */
        $directives = $this->container->get(SegmentsInterface::class);

        $skills = $directives->get('skills');
        $this->assertSame(['skill_list', 'skill_load', 'read', 'ls', 'glob', 'skill_run'], $skills->tools);
        $this->assertStringContainsString('metadata only', $skills->prompt);
        $this->assertStringContainsString('[label](skill://{id})', $skills->prompt);
        $this->assertStringContainsString('within the current charter and user constraints', $skills->prompt);
        $this->assertStringContainsString('never expands permissions', $skills->prompt);
        $this->assertStringContainsString('Skill root:', $skills->prompt);
        $this->assertStringContainsString('{% for skill in skills %}', $skills->prompt);
        $this->assertStringContainsString('{{ skill.id }}', $skills->prompt);
        $this->assertStringContainsString('{{ skill.description }}', $skills->prompt);
        $this->assertStringContainsString('skill_run', $skills->prompt);
        $this->assertStringContainsString('Call `skill_list`', $skills->prompt);
        $this->assertStringContainsString('## Advertised skills', $skills->prompt);
    }

    public function testBrowserSegmentGrantsTabsAndExplainsTheirLifetime(): void
    {
        /** @var SegmentsInterface $segments */
        $segments = $this->container->get(SegmentsInterface::class);

        $browser = $segments->get('browser');
        $this->assertSame([
            'browser_navigate',
            'browser_close',
            'browser_snapshot',
            'browser_click',
            'browser_type',
            'browser_scroll',
            'browser_assert',
            'browser_console',
            'browser_inspect',
            'browser_take_screenshot',
        ], $browser->tools);
        $this->assertStringContainsString('takes a `tab` id you choose', $browser->prompt);
        $this->assertStringContainsString('a ref from one id means nothing on another', $browser->prompt);
        $this->assertStringContainsString('`browser_close` releases an id you are done with', $browser->prompt);
        $this->assertStringContainsString('`browser_assert` answers "did that work"', $browser->prompt);
        $this->assertStringContainsString('pass a CSS selector such as `#result` instead of a ref', $browser->prompt);
        $this->assertStringContainsString('`browser_console` is the only place an uncaught exception', $browser->prompt);
        $this->assertStringContainsString('{% if web_fetch in tools %}', $browser->prompt);
    }

    public function testSiteSegmentGrantsToolsAndRendersCatalog(): void
    {
        /** @var SegmentsInterface $segments */
        $segments = $this->container->get(SegmentsInterface::class);

        $site = $segments->get('site');
        $this->assertSame(['site_search', 'site_list'], $site->tools);
        $this->assertStringContainsString('exactly the `site_search` enum', $site->prompt);
        $this->assertStringContainsString('before generic web search', $site->prompt);
        $this->assertStringContainsString('{% for site in sites %}', $site->prompt);
        $this->assertStringContainsString('{{ site.id }}', $site->prompt);
        $this->assertStringContainsString('{{ site.label }}', $site->prompt);
    }

    public function testCopyableSegmentRequiresLanguageTaggedFences(): void
    {
        /** @var SegmentsInterface $segments */
        $segments = $this->container->get(SegmentsInterface::class);

        $copyable = $segments->get('copyable');
        $this->assertStringContainsString('Always tag the language', $copyable->prompt);
        $this->assertStringContainsString('four-backtick outer fence', $copyable->prompt);
        $this->assertStringNotContainsString('optionally with a language tag', $copyable->prompt);
    }
}
