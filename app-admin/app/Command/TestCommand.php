<?php

declare(strict_types=1);

namespace App\Command;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Switon\Id\Snowflake;

class TestCommand
{
    #[Autowired] protected ConsoleInterface $console;

    /**
     * Output a demo greeting.
     */
    public function defaultAction(string $name = 'switon'): void
    {
        $this->console->writeLn(sprintf('Hello %s!', $name));
    }

    /**
     * Test Snowflake ID generation.
     */
    public function snowflakeAction(): void
    {
        $generator = make(Snowflake::class);

        $this->console->writeLn('Snowflake ID Generator Test');
        $this->console->writeLn('===========================');
        $this->console->writeLn('');

        $this->console->writeLn('Configuration:');
        $this->console->writeLn('Datacenter ID: ' . $generator->getDatacenterId());
        $this->console->writeLn('Machine ID: ' . $generator->getMachineId());
        $this->console->writeLn('');

        $this->console->writeLn('Generating 100 IDs...');
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = $generator->next();
        }

        $this->console->writeLn('First 10 IDs with components:');
        for ($i = 0; $i < 10; $i++) {
            $id = $ids[$i];
            $binary = decbin($id);
            $padded = str_pad($binary, 64, '0', STR_PAD_LEFT);

            $timestamp = substr($padded, 1, 41);
            $datacenter = substr($padded, 42, 5);
            $machine = substr($padded, 47, 5);
            $sequence = substr($padded, 52, 12);

            $this->console->writeLn(sprintf(
                '%2d: %20d (DC:%2d MC:%2d SEQ:%3d)',
                $i + 1,
                $id,
                bindec($datacenter),
                bindec($machine),
                bindec($sequence)
            ));
        }

        $this->console->writeLn('');
        $this->console->writeLn('Sequence check (first 10 sequences):');
        $sequences = [];
        foreach (array_slice($ids, 0, 10) as $id) {
            $binary = decbin($id);
            $padded = str_pad($binary, 64, '0', STR_PAD_LEFT);
            $sequence = substr($padded, 52, 12);
            $sequences[] = bindec($sequence);
        }
        $this->console->writeLn('Sequences: ' . implode(', ', $sequences));

        $this->console->writeLn('');
        $this->console->writeLn('All 100 IDs generated successfully!');
        $this->console->writeLn('Min ID: ' . min($ids));
        $this->console->writeLn('Max ID: ' . max($ids));
    }
}
