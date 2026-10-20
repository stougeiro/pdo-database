<?php declare(strict_types=1);

namespace STDW\Pdo\QueryTree\Node;

use STDW\Contract\Pdo\QueryTree\Node\OffsetPaginationNodeInterface;
use STDW\Pdo\Exception\QueryTreeException;


/**
 * Offset (positional) pagination: skip offset rows, take limit rows.
 */
final readonly class OffsetPaginationNode implements OffsetPaginationNodeInterface
{
    public function __construct(
        private ?int $limit = null,
        private ?int $offset = null,
    ) {
        if ($limit !== null && $limit < 0) {
            throw QueryTreeException::invalid('limit cannot be negative');
        }

        if ($offset !== null && $offset < 0) {
            throw QueryTreeException::invalid('offset cannot be negative');
        }
    }


    public function limit(): ?int
    {
        return $this->limit;
    }

    public function offset(): ?int
    {
        return $this->offset;
    }
}
