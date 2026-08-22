<?php

declare(strict_types=1);

namespace Switon\Skill\Command;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Switon\Skill\SkillsInterface;

use function explode;
use function mb_strlen;
use function mb_substr;
use function rtrim;
use function trim;

/**
 * Print the skill catalog.
 *
 * Guidance: keyword matching is {@see SkillsInterface::list()}. This command only prints.
 */
class SkillCommand
{
    protected const int DESCRIPTION_WIDTH = 48;

    #[Autowired] protected ConsoleInterface $console;
    #[Autowired] protected SkillsInterface $skills;

    /**
     * Print skill id, root, and a short description.
     *
     * @param string $keyword Match id or description. Comma-separated words: any one is enough. Omit for every skill.
     */
    public function listAction(string $keyword = ''): int
    {
        $keywords = [];
        foreach (explode(',', $keyword) as $word) {
            $word = trim($word);
            if ($word !== '') {
                $keywords[] = $word;
            }
        }

        $this->skills->reload();
        $rows = [];
        foreach ($this->skills->list($keywords) as $row) {
            $rows[] = [
                $row['id'],
                $this->skills->get($row['id'])->path,
                $this->clip($row['description']),
            ];
        }
        if ($rows === []) {
            $this->console->writeLn('No skills.');

            return 0;
        }

        $this->console->table(['id', 'path', 'description'], $rows);

        return 0;
    }

    protected function clip(string $description): string
    {
        $description = trim($description);
        if (mb_strlen($description) <= self::DESCRIPTION_WIDTH) {
            return $description;
        }

        return rtrim(mb_substr($description, 0, self::DESCRIPTION_WIDTH - 1)) . '…';
    }
}
