<?php declare(strict_types=1);

use STDW\Pdo\Driver\Compiler;
use STDW\Pdo\Dialect\SqliteDialect;
use STDW\Pdo\Exception\QueryTreeException;
use STDW\Pdo\QueryTree\QueryTreeBuilder;
use STDW\Pdo\Statement\Statement;
use STDW\Contract\Pdo\Statement\Type\StatementKind;

/**
 * Lowers the DSL and renders the resulting WHERE, so the assertion follows
 * observable behavior (the SQL) instead of the internal node shape. This is
 * the scope filter of BEHAVIORIAL §8.2: refactoring the tree must not break
 * these tests as long as the emitted SQL is unchanged.
 */
function where(string $filterKey, mixed $value, string $operator = 'eq'): string
{
    $query = QueryTreeBuilder::query(filter: [$filterKey . ($operator === 'eq' ? '' : ' ' . $operator) => $value]);

    return (new Compiler(new SqliteDialect()))
        ->compile(new Statement(StatementKind::Select, 't', $query))['sql'];
}

it('lowers shorthand and explicit operators to SQL', function () {
    expect(where('age', 18, 'gte'))->toContain('"age" >= ?')
        ->and(where('name', 'Ada'))->toContain('"name" = ?');
});

it('lowers logic tokens and nested groups to SQL', function () {
    $query = QueryTreeBuilder::query(filter: [
        ['age gte' => 18],
        'OR',
        ['name' => 'Ada'],
        'AND',
        'NOT',
        ['active' => true],
    ]);

    $sql = (new Compiler(new SqliteDialect()))
        ->compile(new Statement(StatementKind::Select, 't', $query))['sql'];

    expect($sql)->toContain('"age" >= ?')
        ->and($sql)->toContain(' OR ')
        ->and($sql)->toContain(' AND ')
        ->and($sql)->toContain('NOT (');
});

it('rejects an unknown operator', function () {
    QueryTreeBuilder::filter(['age nope' => 1]);
})->throws(QueryTreeException::class);

it('rejects a null value for a comparison', function () {
    QueryTreeBuilder::filter(['age' => null]);
})->throws(QueryTreeException::class);

it('rejects a wrong arity for between', function () {
    QueryTreeBuilder::filter(['age between' => [1]]);
})->throws(QueryTreeException::class);

it('rejects malformed logic', function () {
    QueryTreeBuilder::filter(['AND', ['age' => 1]]);
})->throws(QueryTreeException::class);

it('lowers order maps and lists to SQL', function () {
    $map = QueryTreeBuilder::query(order: ['name' => 'desc']);
    $list = QueryTreeBuilder::query(order: ['age desc', 'name']);

    $compiler = new Compiler(new SqliteDialect());

    expect($compiler->compile(new Statement(StatementKind::Select, 't', $map))['sql'])
        ->toContain('ORDER BY "name" DESC')
        ->and($compiler->compile(new Statement(StatementKind::Select, 't', $list))['sql'])
        ->toContain('ORDER BY "age" DESC, "name" ASC');
});

it('lowers offset and cursor pagination to SQL', function () {
    $offset = QueryTreeBuilder::query(pagination: ['limit' => 10, 'offset' => 5]);
    $cursor = QueryTreeBuilder::query(order: ['id' => 'asc'], pagination: ['limit' => 10, 'cursor' => 42]);

    $compiler = new Compiler(new SqliteDialect());

    expect($compiler->compile(new Statement(StatementKind::Select, 't', $offset))['sql'])
        ->toContain('LIMIT 10 OFFSET 5')
        ->and($compiler->compile(new Statement(StatementKind::Select, 't', $cursor))['sql'])
        ->toContain('"id" > ?');
});

it('rejects cursor mixed with offset', function () {
    QueryTreeBuilder::pagination(['cursor' => 10, 'offset' => 1]);
})->throws(QueryTreeException::class);

it('rejects a negative limit', function () {
    QueryTreeBuilder::pagination(['limit' => -1]);
})->throws(QueryTreeException::class);

it('omits the WHERE fragment when the dialect cannot express a cursor', function () {
    $dialect = new class extends STDW\Pdo\Dialect\DialectAbstracted {
        protected function delimiters(): array
        {
            return ['"', '"'];
        }

        protected function renderLimitOffset(?int $limit, ?int $offset): string
        {
            return '';
        }

        public function cursorClause(mixed $cursor, array $order, array &$params): ?string
        {
            return '';
        }
    };

    $query = QueryTreeBuilder::query(pagination: ['limit' => 5, 'cursor' => 1]);
    $sql = (new Compiler($dialect))->compile(new Statement(StatementKind::Select, 't', $query))['sql'];

    expect($sql)->not->toContain('WHERE');
});
