<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;

final class ProjectorRegistry
{
    /** @var array<string, Projector> */
    private array $projectors = [];

    /**
     * @param list<Projector> $projectors
     */
    public function __construct(array $projectors)
    {
        foreach ($projectors as $projector) {
            $this->projectors[$projector->aggregateType()->value] = $projector;
        }
    }

    public function for(AggregateType $type): Projector
    {
        return $this->projectors[$type->value]
            ?? throw new \RuntimeException('No projector registered for ' . $type->value);
    }

    public function tryFor(AggregateType $type): ?Projector
    {
        return $this->projectors[$type->value] ?? null;
    }

    /**
     * @return list<Projector>
     */
    public function all(): array
    {
        return array_values($this->projectors);
    }
}
