<?php declare(strict_types=1);

namespace STDW\Pdo\Result;

use STDW\Contract\Pdo\Result\RawInterface;


/**
 * The engine-neutral outcome of an executed statement.
 *
 * A SELECT yields rows(); a write yields affected(); an insert additionally
 * yields the generated key through insertedId() — whether the engine produced
 * it via RETURNING or the native last-insert-id.
 */
final readonly class Raw implements RawInterface
{
    /**
     * @param array<int, array<int|string, mixed>> $rows
     */
    public function __construct(
        private array $rows = [],
        private int $affected = 0,
        private null|int|string $insertedId = null,
    ) {
    }


    public function rows(): array
    {
        return $this->rows;
    }

    public function affected(): int
    {
        return $this->affected;
    }

    public function insertedId(): null|int|string
    {
        return $this->insertedId;
    }
}
