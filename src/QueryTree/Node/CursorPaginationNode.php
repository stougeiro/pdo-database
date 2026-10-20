<?php declare(strict_types=1);

namespace STDW\Pdo\QueryTree\Node;

use STDW\Contract\Pdo\QueryTree\Node\CursorPaginationNodeInterface;
use STDW\Pdo\Exception\QueryTreeException;


/**
 * Cursor (seek) pagination: resume after an opaque marker emitted by the
 * previous page, bounded by the shared row cap. The deterministic ordering
 * it relies on comes from QueryInterface::order().
 */
final readonly class CursorPaginationNode implements CursorPaginationNodeInterface
{
    public function __construct(
        private ?int $limit = null,
        private mixed $cursor = null,
    ) {
        if ($limit !== null && $limit < 0) {
            throw QueryTreeException::invalid('limit cannot be negative');
        }
    }


    public function limit(): ?int
    {
        return $this->limit;
    }

    public function cursor(): mixed
    {
        return $this->cursor;
    }
}
