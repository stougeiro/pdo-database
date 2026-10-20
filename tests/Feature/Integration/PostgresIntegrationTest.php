<?php declare(strict_types=1);

use STDW\Contract\Pdo\Statement\Type\StatementKind;
use STDW\Pdo\ConnectionRegistry;
use STDW\Pdo\Param\Param;
use STDW\Pdo\Statement\Statement;
use Tests\Fixtures\User;

/**
 * Real-engine integration, opt-in only (see the MySQL suite for the rationale).
 *
 * Export, e.g.:
 *
 *   PDO_PGSQL_TEST_HOST=127.0.0.1 PDO_PGSQL_TEST_PORT=5432 \
 *   PDO_PGSQL_TEST_DATABASE=test PDO_PGSQL_TEST_USERNAME=postgres \
 *   PDO_PGSQL_TEST_PASSWORD=secret
 *
 * and run: pest tests/Feature/Integration
 */
$pgsql = static fn (): array => [
    'host' => getenv('PDO_PGSQL_TEST_HOST') ?: '',
    'port' => (int) (getenv('PDO_PGSQL_TEST_PORT') ?: 5432),
    'schema' => getenv('PDO_PGSQL_TEST_DATABASE') ?: '',
    'username' => getenv('PDO_PGSQL_TEST_USERNAME') ?: '',
    'password' => getenv('PDO_PGSQL_TEST_PASSWORD') ?: '',
];

beforeEach(function () use ($pgsql) {
    $config = $pgsql();

    if ($config['host'] === '') {
        $this->markTestSkipped('Set PDO_PGSQL_TEST_HOST to run the Postgres integration suite.');
    }

    ConnectionRegistry::reset();
    ConnectionRegistry::configure(['default' => ['driver' => 'postgres'] + $config]);

    ConnectionRegistry::get()->execute('DROP TABLE IF EXISTS users');
    ConnectionRegistry::get()->execute(
        'CREATE TABLE users ('
        . 'id SERIAL PRIMARY KEY, '
        . 'name VARCHAR(255) NOT NULL, '
        . 'age INT NULL, '
        . 'active SMALLINT NOT NULL DEFAULT 1'
        . ')'
    );
});

afterEach(function () {
    ConnectionRegistry::reset();
});

it('round-trips a record and returns the generated key through RETURNING', function () {
    $result = (new User())->create(['name' => 'Ada', 'age' => 36]);

    expect($result->data())->toBe(['id' => 1, 'name' => 'Ada', 'age' => 36, 'active' => 1]);
});

it('supports native ILIKE on postgres', function () {
    $user = new User();
    $user->create(['name' => 'Ada', 'age' => 36]);
    $user->create(['name' => 'Grace', 'age' => 45]);

    expect($user->list(['name ilike' => 'a%'])->count())->toBe(2);
});

it('paginates with a keyset cursor on postgres', function () {
    $user = new User();
    $user->create(['name' => 'Ada']);
    $user->create(['name' => 'Grace']);
    $user->create(['name' => 'Linus']);

    $listed = $user->list(order: ['id' => 'asc'], pagination: ['cursor' => 1, 'limit' => 2]);

    expect($listed->count())->toBe(2)
        ->and((int) $listed->data()[0]['id'])->toBe(2)
        ->and((int) $listed->data()[1]['id'])->toBe(3);
});

it('rolls back a transaction spanning model calls', function () {
    $connection = ConnectionRegistry::get();
    $connection->begin();
    (new User())->create(['name' => 'Temp', 'age' => 1]);
    $connection->rollback();

    expect((new User())->list()->count())->toBe(0);
});

it('returns the inserted key from the RETURNING column', function () {
    $raw = ConnectionRegistry::resolve()->translate(new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => Param::str('Ada')],
        returning: ['id'],
    ));

    expect((int) $raw->insertedId())->toBe(1);
});
