<?php declare(strict_types=1);

use STDW\Contract\Pdo\Param\ParamInterface;
use STDW\Contract\Pdo\QueryTree\Node\ConditionNodeInterface as ConditionNodeContract;
use STDW\Contract\Pdo\QueryTree\Type\ConditionOperator;
use STDW\Contract\Pdo\QueryTree\Type\LogicOperator;
use STDW\Pdo\Dialect\Spec\DialectSpecInterface;
use STDW\Pdo\Dialect\PostgresDialect;
use STDW\Pdo\Dialect\SqliteDialect;
use STDW\Pdo\Driver\Conditions;
use STDW\Pdo\Param\Param;
use STDW\Pdo\QueryTree\Node\GroupNode;
use STDW\Pdo\QueryTree\Node\LogicNode;
use STDW\Pdo\QueryTree\QueryTreeBuilder;

/**
 * A minimal ConditionNodeInterface double, so Conditions can be exercised on
 * the shapes the builder rejects (e.g. an empty IN list). Lives only in tests.
 */
function conditionStub(string $column, ConditionOperator $operator, null|ParamInterface|array $value): ConditionNodeContract
{
    return new class ($column, $operator, $value) implements ConditionNodeContract {
        public function __construct(
            private readonly string $column,
            private readonly ConditionOperator $operator,
            private readonly null|ParamInterface|array $value,
        ) {
        }

        public function column(): string
        {
            return $this->column;
        }

        public function operator(): ConditionOperator
        {
            return $this->operator;
        }

        public function value(): null|ParamInterface|array
        {
            return $this->value;
        }
    };
}

/**
 * @param array<array-key, mixed> $filter
 * @return array{sql: string, params: array<int, STDW\Contract\Pdo\Param\ParamInterface>}
 */
function compileFilter(array $filter, ?DialectSpecInterface $dialect = null): array
{
    return Conditions::compile(QueryTreeBuilder::filter($filter), $dialect ?? new SqliteDialect());
}

it('returns an empty fragment for a null filter', function () {
    expect(Conditions::compile(null, new SqliteDialect()))->toBe(['sql' => '', 'params' => []]);
});

it('compiles every scalar comparison operator', function () {
    expect(compileFilter(['a' => 1])['sql'])->toBe('"a" = ?')
        ->and(compileFilter(['a neq' => 1])['sql'])->toBe('"a" <> ?')
        ->and(compileFilter(['a gt' => 1])['sql'])->toBe('"a" > ?')
        ->and(compileFilter(['a gte' => 1])['sql'])->toBe('"a" >= ?')
        ->and(compileFilter(['a lt' => 1])['sql'])->toBe('"a" < ?')
        ->and(compileFilter(['a lte' => 1])['sql'])->toBe('"a" <= ?')
        ->and(compileFilter(['a like' => 'x%'])['sql'])->toBe('"a" LIKE ?')
        ->and(compileFilter(['a not_like' => 'x%'])['sql'])->toBe('"a" NOT LIKE ?');
});

it('compiles is_null and is_not_null without a param', function () {
    expect(compileFilter(['a is_null' => null]))->toBe(['sql' => '"a" IS NULL', 'params' => []])
        ->and(compileFilter(['a is_not_null' => null]))->toBe(['sql' => '"a" IS NOT NULL', 'params' => []]);
});

it('compiles an in list', function () {
    $in = compileFilter(['a in' => [1, 2, 3]]);

    expect($in['sql'])->toBe('"a" IN (?, ?, ?)')
        ->and($in['params'])->toHaveCount(3);
});

it('compiles a not_in list', function () {
    expect(compileFilter(['a not_in' => [1]])['sql'])->toBe('"a" NOT IN (?)');
});

it('renders an empty in list as a never-match and an empty not_in as match-all', function () {
    $in = Conditions::compile(
        new GroupNode([conditionStub('a', ConditionOperator::In, [])]),
        new SqliteDialect(),
    );

    $notIn = Conditions::compile(
        new GroupNode([conditionStub('a', ConditionOperator::NotIn, [])]),
        new SqliteDialect(),
    );

    expect($in['sql'])->toBe('0 = 1')
        ->and($in['params'])->toBe([])
        ->and($notIn['sql'])->toBe('1 = 1')
        ->and($notIn['params'])->toBe([]);
});

it('compiles between and not_between', function () {
    $between = compileFilter(['a between' => [1, 9]]);

    expect($between['sql'])->toBe('"a" BETWEEN ? AND ?')
        ->and($between['params'])->toHaveCount(2)
        ->and(compileFilter(['a not_between' => [1, 9]])['sql'])->toBe('"a" NOT BETWEEN ? AND ?');
});

it('emulates ilike and not_ilike on sqlite', function () {
    expect(compileFilter(['a ilike' => 'x%'], new SqliteDialect())['sql'])
        ->toBe('LOWER("a") LIKE LOWER(?)')
        ->and(compileFilter(['a not_ilike' => 'x%'], new SqliteDialect())['sql'])
        ->toBe('LOWER("a") NOT LIKE LOWER(?)');
});

it('keeps ilike native on postgres', function () {
    expect(compileFilter(['a ilike' => 'x%'], new PostgresDialect())['sql'])
        ->toBe('"a" ILIKE ?')
        ->and(compileFilter(['a not_ilike' => 'x%'], new PostgresDialect())['sql'])
        ->toBe('"a" NOT ILIKE ?');
});

it('joins conditions with AND by default and with explicit connectors', function () {
    $and = compileFilter(['a' => 1, 'b' => 2]);
    $or = compileFilter([['a' => 1], 'OR', ['b' => 2]]);

    expect($and['sql'])->toBe('("a" = ?) AND ("b" = ?)')
        ->and($or['sql'])->toBe('("a" = ?) OR ("b" = ?)');
});

it('negates the operand after a NOT token', function () {
    $sql = compileFilter([['a' => 1], 'AND', 'NOT', ['b' => 2]])['sql'];

    expect($sql)->toBe('("a" = ?) AND (NOT ("b" = ?))');
});

it('wraps a nested group in parentheses', function () {
    $sql = compileFilter([['a' => 1], 'OR', [['b' => 2], 'AND', ['c' => 3]]])['sql'];

    expect($sql)->toBe('("a" = ?) OR (("b" = ?) AND ("c" = ?))');
});

it('compiles a group that only holds a logic token to an empty fragment', function () {
    $group = new GroupNode([new LogicNode(LogicOperator::Not)]);
    $compiled = Conditions::compile($group, new SqliteDialect());

    expect($compiled['sql'])->toBe('')
        ->and($compiled['params'])->toBe([]);
});

it('skips an empty nested group when joining operands', function () {
    $group = new GroupNode([conditionStub('a', ConditionOperator::Eq, Param::int(1)), new LogicNode(LogicOperator::And), new GroupNode([])]);
    $compiled = Conditions::compile($group, new SqliteDialect());

    expect($compiled['sql'])->toBe('"a" = ?');
});

it('throws when an IN condition does not hold a list', function () {
    Conditions::compile(new GroupNode([conditionStub('a', ConditionOperator::In, Param::int(1))]), new SqliteDialect());
})->throws(STDW\Pdo\Exception\DriverException::class);

it('throws when a BETWEEN condition does not hold a pair', function () {
    Conditions::compile(new GroupNode([conditionStub('a', ConditionOperator::Between, [Param::int(1)])]), new SqliteDialect());
})->throws(STDW\Pdo\Exception\DriverException::class);

it('throws when a scalar condition does not hold a typed param', function () {
    Conditions::compile(new GroupNode([conditionStub('a', ConditionOperator::Eq, null)]), new SqliteDialect());
})->throws(STDW\Pdo\Exception\DriverException::class);
