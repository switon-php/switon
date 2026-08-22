<?php

declare(strict_types=1);

namespace Switon\Skill;

use Switon\Ai\AbstractExtension;
use Switon\Ai\AgentInterface;
use Switon\Ai\Envelope;
use Switon\Ai\Message\UserMessage;
use Switon\Ai\Part\AskPart;
use Switon\Ai\Part\PinnedPart;
use Switon\Core\Attribute\Autowired;
use Switon\Skill\SkillsInterface;

use function preg_match_all;

/**
 * `[label](skill://id)` puts the skill body in front of the model and keeps the markdown link.
 *
 * Runs on the inbound letter — no turn exists, so the body rides as a {@see PinnedPart} on the
 * message instead of a thread row. The link itself is never rewritten, so this only reads ids.
 *
 * Guidance: the ask is the first {@see AskPart} (`UserMessage::text()`), so that is the only
 * part scanned. Registered by this package's `extra.switon.agent.extensions`; a host that does
 * not want skill links drops that entry.
 */
class SkillLinkExtension extends AbstractExtension
{
    /** The scheme this extension claims. */
    public const string SCHEME = 'skill';

    /** `[label](skill://id)` — group 1 is a catalog leaf or `desk.leaf`; a longer path never matches. */
    protected const string LINK_PATTERN = '/\[[^\]]*]\(skill:\/\/([a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)?)\)/';

    #[Autowired] protected SkillsInterface $skills;

    public function inbound(AgentInterface $agent, Envelope $envelope): void
    {
        $letter = $envelope->letter;
        if (!$letter instanceof UserMessage) {
            return;
        }
        $ask = $letter->text();
        if ($ask === null || preg_match_all(self::LINK_PATTERN, $ask, $matches) === 0) {
            return;
        }

        foreach ($matches[1] as $id) {
            $letter->parts[] = new PinnedPart('skill:' . $id, $this->skills->block($id));
        }
    }
}
