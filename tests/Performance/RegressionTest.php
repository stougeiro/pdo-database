<?php declare(strict_types=1);

use STDW\Contract\Pdo\Statement\Type\StatementKind;
use STDW\Pdo\Dialect\SqliteDialect;
use STDW\Pdo\Driver\Compiler;
use STDW\Pdo\Param\Param;
use STDW\Pdo\QueryTree\QueryTreeBuilder;
use STDW\Pdo\Statement\Statement;

/**
 * Regression guards, not micro-benchmarks: thresholds sit an order of
 * magnitude above the observed cost, so a green run tolerates a slow, noisy
 * CI while still catching a pathological slowdown (an accidental O(n^2), a
 * runaway loop). Tune only if the machine is dramatically slower.
 */
function opsPerSecond(int $iterations, Closure $work): float
{
    $work();

    $start = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $work();
    }

    $elapsed = hrtime(true) - $start;

    return $iterations / ($elapsed / 1_000_000_000);
}

it('sustains at least 10k compilations of a filtered SELECT per second', function () {
    $statement = new Statement(
        StatementKind::Select,
        'users',
        QueryTreeBuilder::query(
            filter: ['active' => 1, 'age gte' => 18],
            order: ['name' => 'asc'],
            pagination: ['limit' => 20],
        ),
    );

    $compiler = new Compiler(new SqliteDialect());
    $ops = opsPerSecond(5000, fn () => $compiler->compile($statement));

    expect($ops)->toBeGreaterThan(10_000);
});

it('sustains at least 10k INSERT compilations per second', function () {
    $statement = new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => Param::str('Ada'), 'age' => Param::int(36)],
        returning: ['id'],
    );

    $compiler = new Compiler(new SqliteDialect());
    $ops = opsPerSecond(5000, fn () => $compiler->compile($statement));

    expect($ops)->toBeGreaterThan(10_000);
});

it('sustains at least 5k filter-DSL lowerings per second', function () {
    $filter = [
        ['active' => 1],
        'AND',
        ['age between' => [18, 65]],
        'AND',
        [['role in' => ['admin', 'editor']], 'OR', ['name ilike' => 'a%']],
    ];

    $ops = opsPerSecond(5000, fn () => QueryTreeBuilder::query(filter: $filter));

    expect($ops)->toBeGreaterThan(5_000);
});
