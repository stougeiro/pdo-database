<?php declare(strict_types=1);

namespace STDW\Pdo\Dialect\Spec;

use STDW\Contract\Pdo\DialectInterface;
use STDW\Contract\Pdo\Param\ParamInterface;
use STDW\Contract\Pdo\QueryTree\Node\OrderNodeInterface;


/**
 * The internal dialect spec: the syntax-rendering seam as this implementation
 * actually needs it.
 *
 * The vendor DialectInterface is the published contract and only orients the
 * design; it is not sufficient here. Our compiler must express divergences a
 * plain operator/suffix rendering cannot: case-insensitive LIKE emulation,
 * NULL-position emulation that rewrites the whole ORDER BY item, the
 * generated-key split (RETURNING vs last-insert-id) and keyset cursors. Those
 * live on this spec, which only internal collaborators (Compiler, Conditions,
 * PdoDriver, the registry) depend on — never an external implementer.
 *
 * It extends DialectInterface, so quote/operator/limitOffset/nulls stay the
 * public, cross-package surface; the extra hooks below are the internal one.
 */
interface DialectSpecInterface extends DialectInterface
{
    /**
     * Whether a case-insensitive LIKE is native (no LOWER() emulation).
     */
    public function supportsIlike(): bool;

    /**
     * A full ORDER BY item, so NULL positioning can be emulated when needed.
     */
    public function orderClause(OrderNodeInterface $order): string;

    /**
     * Whether a write can hand columns back through RETURNING.
     */
    public function supportsReturning(): bool;

    /**
     * The keyset WHERE fragment for a cursor, appending its bound param.
     * Returns null when the dialect cannot express it.
     *
     * @param array<int, OrderNodeInterface> $order
     * @param array<int, ParamInterface> $params
     */
    public function cursorClause(mixed $cursor, array $order, array &$params): ?string;
}
