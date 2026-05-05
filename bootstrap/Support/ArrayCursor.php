<?php

namespace Nraa\Support;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Lightweight cursor wrapper used when a backend does not return MongoDB cursors.
 *
 * @template T
 * @implements IteratorAggregate<int, T>
 */
class ArrayCursor implements IteratorAggregate, Countable
{
    /**
     * @param array<int, mixed> $rows
     */
    public function __construct(
        private readonly array $rows
    ) {
    }

    /**
     * @return ArrayIterator<int, mixed>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows);
    }

    /**
     * @return array<int, mixed>
     */
    public function toArray(): array
    {
        return $this->rows;
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
