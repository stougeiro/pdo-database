<?php declare(strict_types=1);

namespace STDW\Pdo\Driver;

use STDW\Contract\Pdo\ConnectionInterface;
use STDW\Contract\Pdo\DialectInterface;
use STDW\Contract\Pdo\DriverInterface;
use STDW\Contract\Pdo\Exception\ConnectionExceptionInterface;
use STDW\Contract\Pdo\Param\ParamInterface;
use STDW\Contract\Pdo\Result\RawInterface;
use STDW\Contract\Pdo\Statement\StatementInterface;
use STDW\Contract\Pdo\Statement\Type\StatementKind;
use STDW\Pdo\Exception\DriverException;
use STDW\Pdo\Param\Param;
use STDW\Pdo\QueryTree\QueryTreeBuilder;
use STDW\Pdo\Result\Raw;
use STDW\Pdo\Statement\Statement;


/**
 * The single, IR-driven PDO driver.
 *
 * It is an IR translator: it compiles the Statement through the common
 * Compiler and delegates the actual preparation/execution to the Connection
 * (the only place that talks to the native handle), returning a normalized
 * Raw. It never interpolates a value — every param reaches the Connection as
 * a typed Param.
 *
 * It owns the generated-key strategy, resolved in strict priority:
 *
 *   1. returning != [] and supportsReturning (config AND dialect) -> native
 *      RETURNING;
 *   2. otherwise, when emulateReturning is on, a follow-up select reads the
 *      stored record back by its last-insert-id (native-like shape);
 *   3. otherwise, only the last-insert-id is returned.
 *
 * supportsReturning wins: when the native clause is available, emulateReturning
 * is never consulted.
 */
final class PdoDriver implements DriverInterface
{
    private readonly Compiler $compiler;


    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly DialectInterface $dialect,
        private readonly bool $supportsReturning = true,
        private readonly bool $emulateReturning = true,
    ) {
        $this->compiler = new Compiler($dialect);
    }


    public function translate(StatementInterface $statement): RawInterface
    {
        return match ($statement->kind()) {
            StatementKind::Select => $this->select($statement),
            StatementKind::Insert => $this->insert($statement),
            StatementKind::Update, StatementKind::Delete => $this->write($statement),
        };
    }


    private function select(StatementInterface $statement): RawInterface
    {
        $compiled = $this->compiler->compile($statement);

        return new Raw($this->rows($compiled['sql'], $compiled['params']), 0, null);
    }

    private function write(StatementInterface $statement): RawInterface
    {
        $compiled = $this->compiler->compile($statement);

        return new Raw([], $this->affected($compiled['sql'], $compiled['params']), null);
    }

    private function insert(StatementInterface $statement): RawInterface
    {
        $returning = $statement->returning();

        if ($returning !== [] && $this->supportsReturning && $this->dialect->supportsReturning()) {
            $compiled = $this->compiler->compile($statement);
            $rows = $this->rows($compiled['sql'], $compiled['params']);
            $id = $rows === [] ? null : self::key($rows[0], $returning);

            return new Raw($rows, $rows === [] ? 0 : 1, $id);
        }

        $plain = $this->returnless($statement);
        $compiled = $this->compiler->compile($plain);
        $affected = $this->affected($compiled['sql'], $compiled['params']);
        $id = $this->connection->lastInsertId();

        if ($returning === [] || ! $this->emulateReturning || $id === null) {
            return new Raw([], $affected, self::normalizeId($id));
        }

        return new Raw($this->lookup($statement, $id), $affected, self::normalizeId($id));
    }


    /**
     * The insert without its returning columns: an engine that lacks native
     * RETURNING must not receive the clause (it would be a syntax error), so
     * the generated-key fallback compiles the plain INSERT instead.
     */
    private function returnless(StatementInterface $statement): Statement
    {
        return new Statement(
            kind: $statement->kind(),
            table: $statement->table(),
            query: $statement->query(),
            data: $statement->data(),
            key: $statement->key(),
        );
    }


    /**
     * The follow-up select that emulates RETURNING: read back the row just
     * inserted by its generated key, so the caller receives the stored record
     * (with database-filled defaults), not just the key.
     *
     * @return array<int, array<int|string, mixed>>
     */
    private function lookup(StatementInterface $statement, string $id): array
    {
        $query = QueryTreeBuilder::query(
            filter: [$statement->key() => Param::from($id)],
            pagination: ['limit' => 1],
        );

        $compiled = $this->compiler->compile(new Statement(
            kind: StatementKind::Select,
            table: $statement->table(),
            query: $query,
        ));

        return $this->rows($compiled['sql'], $compiled['params']);
    }


    /**
     * @param array<int|string, ParamInterface> $params
     * @return array<int, array<int|string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        try {
            return $this->connection->fetchAll($sql, $params);
        } catch (ConnectionExceptionInterface $exception) {
            throw DriverException::executionFailed($sql, $exception);
        }
    }

    /**
     * @param array<int|string, ParamInterface> $params
     */
    private function affected(string $sql, array $params): int
    {
        try {
            return $this->connection->execute($sql, $params);
        } catch (ConnectionExceptionInterface $exception) {
            throw DriverException::executionFailed($sql, $exception);
        }
    }


    /**
     * @param array<int|string, mixed> $row
     * @param array<int, string> $returning
     */
    private static function key(array $row, array $returning): null|int|string
    {
        $value = $row[$returning[0]] ?? reset($row);

        return is_int($value) || is_string($value) ? $value : null;
    }

    private static function normalizeId(?string $id): null|int|string
    {
        if ($id === null || $id === '' || $id === '0') {
            return null;
        }

        return ctype_digit($id) ? (int) $id : $id;
    }
}
