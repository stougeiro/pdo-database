<?php declare(strict_types=1);

use STDW\Contract\Pdo\Statement\Type\StatementKind;
use STDW\Pdo\ConnectionRegistry;
use STDW\Pdo\Param\Param;
use STDW\Pdo\Statement\Statement;
use Tests\Fixtures\User;

/**
 * Real-engine integration, opt-in only.
 *
 * These run against a live MySQL when the environment provides a connection;
 * otherwise the whole file is skipped, so the default suite stays hermetic and
 * needs no service. Export, e.g.:
 *
 *   PDO_MYSQL_TEST=1 \
 *   PDO_MYSQL_TEST_HOST=127.0.0.1 PDO_MYSQL_TEST_PORT=3306 \
 *   PDO_MYSQL_TEST_DATABASE=test PDO_MYSQL_TEST_USERNAME=root \
 *   PDO_MYSQL_TEST_PASSWORD=secret
 *
 * and run: pest tests/Feature/Integration
 */
$mysql = static fn (): array => [
    'host' => getenv('PDO_MYSQL_TEST_HOST') ?: '',
    'port' => (int) (getenv('PDO_MYSQL_TEST_PORT') ?: 3306),
    'schema' => getenv('PDO_MYSQL_TEST_DATABASE') ?: '',
    'charset' => getenv('PDO_MYSQL_TEST_CHARSET') ?: 'utf8mb4',
    'username' => getenv('PDO_MYSQL_TEST_USERNAME') ?: '',
    'password' => getenv('PDO_MYSQL_TEST_PASSWORD') ?: '',
];

beforeEach(function () use ($mysql) {
    $config = $mysql();

    if ($config['host'] === '') {
        $this->markTestSkipped('Set PDO_MYSQL_TEST_HOST to run the MySQL integration suite.');
    }

    ConnectionRegistry::reset();
    ConnectionRegistry::configure(['default' => ['driver' => 'mysql'] + $config]);

    ConnectionRegistry::get()->execute('DROP TABLE IF EXISTS users');
    ConnectionRegistry::get()->execute(
        'CREATE TABLE users ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY, '
        . 'name VARCHAR(255) NOT NULL, '
        . 'age INT NULL, '
        . 'active TINYINT NOT NULL DEFAULT 1'
        . ')'
    );
});

afterEach(function () {
    ConnectionRegistry::reset();
});

it('round-trips a record through the real engine and returns its key', function () {
    $result = (new User())->create(['name' => 'Ada', 'age' => 36]);

    expect($result->data())->toBe(['id' => 1, 'name' => 'Ada', 'age' => 36, 'active' => 1]);

    $read = (new User())->read(['name' => 'Ada']);

    expect($read->count())->toBe(1)
        ->and($read->data()['age'])->toBe(36);
});

it('applies filters, ordering and pagination on the real engine', function () {
    $user = new User();
    $user->create(['name' => 'Ada', 'age' => 36]);
    $user->create(['name' => 'Grace', 'age' => 45]);
    $user->create(['name' => 'Linus', 'age' => 28]);

    $listed = $user->list(filter: ['age gte' => 30], order: ['age' => 'desc'], pagination: ['limit' => 2]);

    expect($listed->count())->toBe(2)
        ->and($listed->data()[0]['name'])->toBe('Grace')
        ->and($listed->data()[1]['name'])->toBe('Ada');
});

it('runs aggregates and the generated-key path through the real engine', function () {
    $user = new User();
    $user->create(['name' => 'Ada', 'active' => 1]);
    $user->create(['name' => 'Grace', 'active' => 0]);

    $raw = ConnectionRegistry::resolve()->translate(new Statement(
        StatementKind::Select,
        'users',
        STDW\Pdo\QueryTree\QueryTreeBuilder::query(
            aggregates: [['alias' => 'total', 'function' => 'count', 'column' => '*']],
            filter: ['active' => 1],
        ),
    ));

    expect((int) $raw->rows()[0]['total'])->toBe(1);
});

it('binds a value that would break an interpolated query', function () {
    $user = new User();
    $user->create(['name' => "O'Brien; DROP TABLE users; --", 'age' => 1]);

    expect($user->list(['name' => "O'Brien; DROP TABLE users; --"])->count())->toBe(1)
        ->and((int) ConnectionRegistry::get()->fetch('SELECT COUNT(*) AS total FROM users')['total'])->toBe(1);
});

it('rolls back a transaction spanning model calls', function () {
    $connection = ConnectionRegistry::get();
    $connection->begin();
    (new User())->create(['name' => 'Temp', 'age' => 1]);
    $connection->rollback();

    expect((new User())->list()->count())->toBe(0);
});

it('keeps a typed param round-tripping through lastInsertId', function () {
    $raw = ConnectionRegistry::resolve()->translate(new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => Param::str('Ada')],
        returning: ['id'],
    ));

    expect($raw->insertedId())->toBe(1);
});
