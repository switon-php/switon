<?php

declare(strict_types=1);

namespace Switon\Ai\Suspension;

use JsonSerializable;
use Psr\Container\ContainerInterface;
use Switon\Ai\AgentInterface;
use Switon\Ai\Turn;
use function property_exists;

/**
 * Opaque suspend reason on a {@see Turn} — Turn only knows it was suspended.
 *
 * Resumable by nature: an interrupt waits for one paired {@see Continuation}, and only a
 * `suspended` turn carries one.
 *
 * Guidance: the host clears {@see Turn::$interrupt} and sets {@see TurnStatus::Running} before `resume()`. The caller persists the turn.
 *
 * @see \Switon\Ai\Suspension\Continuation
 */
abstract class Interrupt implements JsonSerializable
{
    /** Unix time (float seconds) the turn suspended; stamped by the caller that parks the turn. */
    public ?float $suspendedAt = null;

    /**
     * Apply inbox mail on the suspended {@see Turn}. The host has already paired `$mail`, cleared the interrupt, and set the turn back to running.
     *
     * @param AgentInterface $agent Ambient context (conversation / charter / profile); built-ins ignore it.
     * @param Turn $turn The suspended turn (passed so implementations skip the `lastTurn()` null check).
     * @param Continuation $mail Host-routed mail that pairs with this interrupt.
     * @param ContainerInterface $container Service access for subclasses (interrupts are `new`-constructed).
     */
    abstract public function resume(AgentInterface $agent, Turn $turn, Continuation $mail, ContainerInterface $container): void;

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['class' => static::class] + get_object_vars($this);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): static
    {
        $interrupt = new static();
        foreach ($data as $key => $value) {
            if (!property_exists($interrupt, $key)) {
                continue;
            }
            $interrupt->$key = $value;
        }

        return $interrupt;
    }
}
