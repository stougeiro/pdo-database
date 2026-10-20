<?php declare(strict_types=1);

namespace STDW\Pdo\Result;

use STDW\Contract\Pdo\Result\ResultInterface;


/**
 * The standardized return of every Model operation.
 *
 * - create/read: count() = 1 on success, 0 otherwise; data() = the record.
 * - list: count() = number of returned records; data() = the records.
 * - update/delete: count() = number of affected records; data() = null.
 */
final readonly class Result implements ResultInterface
{
    /**
     * @param null|array<array-key, mixed>|object $data
     */
    public function __construct(
        private null|array|object $data,
        private int $count,
        private bool $valid,
    ) {
    }


    /**
     * @param null|array<array-key, mixed>|object $data
     */
    public static function ok(null|array|object $data, int $count): self
    {
        return new self($data, $count, true);
    }

    public function data(): null|array|object
    {
        return $this->data;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }
}
