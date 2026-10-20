<?php declare(strict_types=1);

use STDW\Contract\Pdo\QueryTree\Type\ConditionOperator;
use STDW\Contract\Pdo\QueryTree\Type\NullOrdering;
use STDW\Contract\Pdo\QueryTree\Type\OrderDirection;
use STDW\Pdo\Dialect\MysqlDialect;
use STDW\Pdo\Dialect\PostgresDialect;
use STDW\Pdo\Dialect\Spec\DialectSpecInterface;
use STDW\Pdo\Dialect\SqliteDialect;
use STDW\Pdo\Exception\DriverException;
use STDW\Pdo\QueryTree\Node\OrderNode;
use STDW\Pdo\QueryTree\QueryTreeBuilder;

it('quotes a simple identifier with each engine delimiters', function () {
    expect((new SqliteDialect())->quote('name'))->toBe('"name"')
        ->and((new PostgresDialect())->quote('name'))->toBe('"name"')
        ->and((new MysqlDialect())->quote('name'))->toBe('`name`');
});

it('never quotes the wildcard and quotes a qualified name segment by segment', function () {
    expect((new SqliteDialect())->quote('*'))->toBe('*')
        ->and((new SqliteDialect())->quote('t.*'))->toBe('"t".*')
        ->and((new SqliteDialect())->quote('t.col'))->toBe('"t"."col"');
});

it('escapes the closing delimiter to keep identifiers safe', function () {
    expect((new SqliteDialect())->quote('a"b'))->toBe('"a""b"')
        ->and((new MysqlDialect())->quote('a`b'))->toBe('`a``b`');
});

it('renders the common comparison operators', function () {
    $dialect = new SqliteDialect();

    expect($dialect->operator(ConditionOperator::Eq))->toBe('=')
        ->and($dialect->operator(ConditionOperator::Neq))->toBe('<>')
        ->and($dialect->operator(ConditionOperator::Gt))->toBe('>')
        ->and($dialect->operator(ConditionOperator::Gte))->toBe('>=')
        ->and($dialect->operator(ConditionOperator::Lt))->toBe('<')
        ->and($dialect->operator(ConditionOperator::Lte))->toBe('<=')
        ->and($dialect->operator(ConditionOperator::In))->toBe('IN')
        ->and($dialect->operator(ConditionOperator::NotIn))->toBe('NOT IN')
        ->and($dialect->operator(ConditionOperator::Like))->toBe('LIKE')
        ->and($dialect->operator(ConditionOperator::NotLike))->toBe('NOT LIKE')
        ->and($dialect->operator(ConditionOperator::Between))->toBe('BETWEEN')
        ->and($dialect->operator(ConditionOperator::NotBetween))->toBe('NOT BETWEEN')
        ->and($dialect->operator(ConditionOperator::IsNull))->toBe('IS NULL')
        ->and($dialect->operator(ConditionOperator::IsNotNull))->toBe('IS NOT NULL');
});

it('renders ilike natively on postgres', function () {
    $dialect = new PostgresDialect();

    expect($dialect->operator(ConditionOperator::Ilike))->toBe('ILIKE')
        ->and($dialect->operator(ConditionOperator::NotIlike))->toBe('NOT ILIKE');
});

it('refuses ilike on a dialect without native support', function () {
    (new SqliteDialect())->operator(ConditionOperator::Ilike);
})->throws(DriverException::class);

it('reports returning support per engine', function () {
    expect((new PostgresDialect())->supportsReturning())->toBeTrue()
        ->and((new SqliteDialect())->supportsReturning())->toBeFalse()
        ->and((new MysqlDialect())->supportsReturning())->toBeFalse();
});

it('reports ilike support per engine', function () {
    expect((new PostgresDialect())->supportsIlike())->toBeTrue()
        ->and((new SqliteDialect())->supportsIlike())->toBeFalse()
        ->and((new MysqlDialect())->supportsIlike())->toBeFalse();
});

it('renders the limit/offset suffix per engine', function () {
    $limitOffset = QueryTreeBuilder::pagination(['limit' => 10, 'offset' => 5]);
    $offsetOnly = QueryTreeBuilder::pagination(['offset' => 5]);

    expect((new SqliteDialect())->limitOffset($limitOffset))->toBe(' LIMIT 10 OFFSET 5')
        ->and((new SqliteDialect())->limitOffset(null))->toBe('')
        ->and((new SqliteDialect())->limitOffset($offsetOnly))->toBe(' LIMIT -1 OFFSET 5')
        ->and((new MysqlDialect())->limitOffset($offsetOnly))->toBe(' LIMIT 18446744073709551615 OFFSET 5')
        ->and((new PostgresDialect())->limitOffset($offsetOnly))->toBe(' OFFSET 5');
});

it('ignores a zero offset', function () {
    expect((new SqliteDialect())->limitOffset(QueryTreeBuilder::pagination(['offset' => 0])))->toBe('');
});

it('renders a mysql limit with an offset through the shared base', function () {
    $pagination = QueryTreeBuilder::pagination(['limit' => 10, 'offset' => 5]);

    expect((new MysqlDialect())->limitOffset($pagination))->toBe(' LIMIT 10 OFFSET 5');
});

it('renders null ordering natively on sqlite and postgres', function () {
    $first = new OrderNode('name', OrderDirection::Asc, NullOrdering::First);
    $last = new OrderNode('name', OrderDirection::Desc, NullOrdering::Last);

    expect((new SqliteDialect())->nulls($first))->toBe(' NULLS FIRST')
        ->and((new SqliteDialect())->nulls($last))->toBe(' NULLS LAST')
        ->and((new PostgresDialect())->nulls($first))->toBe(' NULLS FIRST')
        ->and((new SqliteDialect())->nulls(new OrderNode('name')))->toBe('');
});

it('emulates null ordering on mysql through the whole order clause', function () {
    $first = new OrderNode('name', OrderDirection::Asc, NullOrdering::First);
    $last = new OrderNode('name', OrderDirection::Asc, NullOrdering::Last);
    $plain = new OrderNode('name', OrderDirection::Desc);

    expect((new MysqlDialect())->orderClause($first))->toBe('`name` IS NULL ASC, `name` ASC')
        ->and((new MysqlDialect())->orderClause($last))->toBe('`name` IS NULL DESC, `name` ASC')
        ->and((new MysqlDialect())->orderClause($plain))->toBe('`name` DESC');
});

it('renders a keyset cursor over the ordered column, throwing without one', function () {
    $params = [];
    $clause = (new PostgresDialect())->cursorClause(
        42,
        [new OrderNode('id', OrderDirection::Asc)],
        $params,
    );

    expect($clause)->toBe('"id" > ?')
        ->and($params)->toHaveCount(1)
        ->and($params[0]->value())->toBe(42);
});

it('uses a descending comparison for a descending cursor key', function () {
    $params = [];
    $clause = (new PostgresDialect())->cursorClause(
        42,
        [new OrderNode('id', OrderDirection::Desc)],
        $params,
    );

    expect($clause)->toBe('"id" < ?');
});

it('falls back to rowid on sqlite when no order is given', function () {
    $params = [];
    $clause = (new SqliteDialect())->cursorClause(7, [], $params);

    expect($clause)->toBe('rowid > ?')
        ->and($params[0]->value())->toBe(7);
});

it('throws when a cursor key is requested without an order on engines without fallback', function () {
    $params = [];
    (new MysqlDialect())->cursorClause(7, [], $params);
})->throws(DriverException::class);

it('uses the base NULL ordering and ORDER BY rendering when a dialect only sets delimiters', function () {
    $dialect = new class extends STDW\Pdo\Dialect\DialectAbstracted {
        protected function delimiters(): array
        {
            return ['"', '"'];
        }

        protected function renderLimitOffset(?int $limit, ?int $offset): string
        {
            return '';
        }
    };

    $order = new OrderNode('name', OrderDirection::Desc, NullOrdering::First);

    expect($dialect->nulls($order))->toBe('')
        ->and($dialect->orderClause($order))->toBe('"name" DESC')
        ->and($dialect->supportsIlike())->toBeFalse()
        ->and($dialect->supportsReturning())->toBeFalse();
});

it('exposes the spec contract on every dialect', function () {
    expect(new SqliteDialect())->toBeInstanceOf(DialectSpecInterface::class)
        ->and(new MysqlDialect())->toBeInstanceOf(DialectSpecInterface::class)
        ->and(new PostgresDialect())->toBeInstanceOf(DialectSpecInterface::class);
});
