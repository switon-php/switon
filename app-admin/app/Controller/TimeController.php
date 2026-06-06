<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Switon\Authorizing\Attribute\Authorize;
use Switon\Id\Snowflake;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[Authorize(Authorize::ANONYMOUS)]
#[RequestMapping('/time')]
class TimeController extends Controller
{
    #[GetMapping]
    public function currentAction(LoggerInterface $logger): array
    {
        $logger->info("abcd");
        return ['timestamp' => round(microtime(true), 3), 'human' => date('Y-m-d H:i:s')];
    }

    #[GetMapping('/snowflake')]
    public function snowflakeAction(): array
    {
        $generator = make(Snowflake::class);

        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = $generator->next();
        }

        $result = [
            'config' => [
                'datacenter_id' => $generator->getDatacenterId(),
                'machine_id' => $generator->getMachineId(),
            ],
            'ids' => [],
            'sequences' => [],
        ];

        foreach (array_slice($ids, 0, 10) as $index => $id) {
            $binary = decbin($id);
            $padded = str_pad($binary, 64, '0', STR_PAD_LEFT);

            $timestamp = substr($padded, 1, 41);
            $datacenter = substr($padded, 42, 5);
            $machine = substr($padded, 47, 5);
            $sequence = substr($padded, 52, 12);

            $result['ids'][] = [
                'index' => $index + 1,
                'id' => $id,
                'components' => [
                    'datacenter' => bindec($datacenter),
                    'machine' => bindec($machine),
                    'sequence' => bindec($sequence),
                ]
            ];
        }

        foreach (array_slice($ids, 0, 10) as $id) {
            $binary = decbin($id);
            $padded = str_pad($binary, 64, '0', STR_PAD_LEFT);
            $sequence = substr($padded, 52, 12);
            $result['sequences'][] = bindec($sequence);
        }

        $result['summary'] = [
            'total_generated' => count($ids),
            'min_id' => min($ids),
            'max_id' => max($ids),
        ];

        return $result;
    }
}
