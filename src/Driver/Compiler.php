<?php declare(strict_types=1);

namespace STDW\Pdo\Driver;

use STDW\Contract\Pdo\Param\ParamInterface;
use STDW\Contract\Pdo\QueryTree\Node\AggregateNodeInterface;
use STDW\Contract\Pdo\QueryTree\Node\CursorPaginationNodeInterface;
use STDW\Contract\Pdo\QueryTree\QueryInterface;
use STDW\Contract\Pdo\Statement\StatementInterface;
use STDW\Contract\Pdo\Statement\Type\StatementKind;
use STDW\Pdo\Dialect\Spec\DialectSpecInterface;


/**
 * The shared compiler: renders the executable IR (StatementInterface) to SQL
 * plus an ordered list of typed params.
 *
 * All the engine-neutral shape lives here (SELECT/INSERT/UPDATE/DELETE,
 * projection, DISTINCT, GROUP BY/HAVING, aggregates, WHERE). The dialect
 * supplies only what diverges: identifier quoting, operator rendering,
 * row limiting and NULL ordering. Values are always placeholders; tables and
 * columns are quoted identifiers.
 */
final class Compiler
{
    public function __construct(
        private readonly DialectSpecInterface $dialect,
    ) {
    }


    /**
     * @return array{sql: string, params: array<int, ParamInterface>}
     */
    public function compile(StatementInterface $statement): array
    {
        return match ($statement->kind()) {
            StatementKind::Select => $this->select($statement),
            StatementKind::Insert => $this->insert($statement),
            StatementKind::Update => $this->update($statement),
            StatementKind::Delete => $this->delete($statement),
        };
    }


    /**
     * @return array{sql: string, params: array<int, ParamInterface>}
     */
    private function select(StatementInterface $statement): array
    {
        $table = $this->dialect->quote($statement->table());
        $query = $statement->query();

        if ($query === null) {
            return ['sql' => 'SELECT * FROM ' . $table, 'params' => []];
        }

        $sql = 'SELECT ' . ($query->distinct() ? 'DISTINCT ' : '') . $this->projection($query) . ' FROM ' . $table;

        $params = [];
        $filters = [];

        $where = Conditions::compile($query->where(), $this->dialect);

        if ($where['sql'] !== '') {
            $filters[] = '(' . $where['sql'] . ')';
            $params = [...$params, ...$where['params']];
        }

        $cursor = $this->cursor($query);

        if ($cursor !== null) {
            $filters[] = '(' . $cursor['sql'] . ')';
            $params = [...$params, ...$cursor['params']];
        }

        if ($filters !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $filters);
        }

        if ($query->groupBy() !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map($this->dialect->quote(...), $query->groupBy()));
        }

        $having = Conditions::compile($query->having(), $this->dialect);

        if ($having['sql'] !== '') {
            $sql .= ' HAVING ' . $having['sql'];
            $params = [...$params, ...$having['params']];
        }

        if ($query->order() !== []) {
            $sql .= ' ORDER BY ' . implode(', ', array_map($this->dialect->orderClause(...), $query->order()));
        }

        $sql .= $this->dialect->limitOffset($query->pagination());

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @return array{sql: string, params: array<int, ParamInterface>}
     */
    private function insert(StatementInterface $statement): array
    {
        $columns = [];
        $placeholders = [];
        $params = [];

        foreach ($statement->data() as $column => $param) {
            $columns[] = $this->dialect->quote($column);
            $placeholders[] = '?';
            $params[] = $param;
        }

        $sql = 'INSERT INTO ' . $this->dialect->quote($statement->table())
            . ' (' . implode(', ', $columns) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';

        $returning = $statement->returning();

        if ($returning !== [] && $this->dialect->supportsReturning()) {
            $sql .= ' RETURNING ' . implode(', ', array_map($this->dialect->quote(...), $returning));
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @return array{sql: string, params: array<int, ParamInterface>}
     */
    private function update(StatementInterface $statement): array
    {
        $assignments = [];
        $params = [];

        foreach ($statement->data() as $column => $param) {
            $assignments[] = $this->dialect->quote($column) . ' = ?';
            $params[] = $param;
        }

        $where = Conditions::compile($statement->query()?->where(), $this->dialect);

        $sql = 'UPDATE ' . $this->dialect->quote($statement->table())
            . ' SET ' . implode(', ', $assignments)
            . ' WHERE ' . $where['sql'];

        return ['sql' => $sql, 'params' => [...$params, ...$where['params']]];
    }

    /**
     * @return array{sql: string, params: array<int, ParamInterface>}
     */
    private function delete(StatementInterface $statement): array
    {
        $where = Conditions::compile($statement->query()?->where(), $this->dialect);

        $sql = 'DELETE FROM ' . $this->dialect->quote($statement->table()) . ' WHERE ' . $where['sql'];

        return ['sql' => $sql, 'params' => $where['params']];
    }

    private function projection(QueryInterface $query): string
    {
        $parts = [];

        foreach ($query->columns() as $column) {
            $parts[] = $this->dialect->quote($column);
        }

        foreach ($query->aggregates() as $aggregate) {
            $parts[] = $this->aggregate($aggregate);
        }

        return $parts === [] ? '*' : implode(', ', $parts);
    }

    private function aggregate(AggregateNodeInterface $aggregate): string
    {
        $column = $aggregate->column() === '*' ? '*' : $this->dialect->quote($aggregate->column());

        return $aggregate->operation()->value . '(' . $column . ') AS ' . $this->dialect->quote($aggregate->alias());
    }

    /**
     * @return null|array{sql: string, params: array<int, ParamInterface>}
     */
    private function cursor(QueryInterface $query): ?array
    {
        $pagination = $query->pagination();

        if ( ! $pagination instanceof CursorPaginationNodeInterface || $pagination->cursor() === null) {
            return null;
        }

        $params = [];
        $sql = $this->dialect->cursorClause($pagination->cursor(), $query->order(), $params);

        if ($sql === null || $sql === '') {
            return null;
        }

        return ['sql' => $sql, 'params' => $params];
    }
}
