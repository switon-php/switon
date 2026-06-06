<?php

declare(strict_types=1);

namespace Switon\Beacon;

use Switon\Core\Attribute\Autowired;

use function array_filter;
use function array_keys;
use function array_values;
use function class_implements;
use function str_contains;
use function str_ends_with;

/**
 * Event scanner backed by ClassIndex for Beacon inspection commands.
 *
 * Guidance: Namespace-based discovery stays intentionally broad; downstream commands can add presentation or payload inspection.
 *
 * Road-signs:
 * - filters FQCNs containing <code>\Event\</code>
 * - excludes interface-only symbols
 * - downstream commands shape the final output
 *
 * @see \Switon\Beacon\ClassIndexInterface
 * @see \Switon\Beacon\EventScannerInterface
 * @see \Switon\Beacon\Command\EventCommand
 */
class EventScanner implements EventScannerInterface
{
    #[Autowired] protected ClassIndexInterface $classIndex;

    /** @return list<class-string> */
    public function scanEventClasses(): array
    {
        $all = $this->classIndex->all();
        $byNamespace = array_filter($all, static function (string $fqcn): bool {
            return str_contains($fqcn, '\\Event\\');
        });
        return array_values(array_filter($byNamespace, [$this, 'isEventClass']));
    }

    protected function isEventClass(string $fqcn): bool
    {
        if (str_ends_with($fqcn, 'Interface')) {
            return false;
        }
        $ifaces = class_implements($fqcn);
        if ($ifaces === false) {
            return false; // not a class (e.g. interface, trait)
        }
        if ($ifaces === []) {
            return true; // class with no interfaces → event
        }
        foreach (array_keys($ifaces) as $iface) {
            if (str_ends_with($iface, 'Interface')) {
                return false;
            }
        }
        return true;
    }
}
