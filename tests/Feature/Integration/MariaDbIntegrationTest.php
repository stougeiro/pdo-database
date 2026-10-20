<?php declare(strict_types=1);

use STDW\Contract\Pdo\Statement\Type\StatementKind;
use STDW\Pdo\ConnectionRegistry;
use STDW\Pdo\Param\Param;
use STDW\Pdo\Statement\Statement;
use Tests\Fixtures\User;

/**
 * Real-engine integration, opt-in only (see the MySQL suite for the rationale).
 *
 * MariaDB speaks the MySQL wire protocol, so it shares the "mysql:" driver and
 * the same env vars. It differs in the generated-key strategy: MariaDB >= 10.5
 * has a native INSERT ... RETURNING, exercised below by the default config,
 * while supportsReturning: false drops it to the last-insert-id fallback.
 *
 * Export, e.g.:
 *
 *   PDO_MYSQL_TEST=1 \
 *   PDO_MYSQL_TEST_HOST=127.0.0.1 PDO_MYSQL_TEST_PORT=3306 \
 *   PDO_MYSQL_TEST_DATABASE=test PDO_MYSQL_TEST_USERNAME=root \
 *   PDO_MYSQL_TEST_PASSWORD=secret
 *
 * and run: pest tests/Feature/Integration
 */
$mariadb = static fn (): array => [
    'host' => getenv('PDO_MYSQL_TEST_HOST') ?: '',
    'port' => (int) (getenv('PDO_MYSQL_TEST_PORT') ?: 3306),
    'schema' => getenv('PDO_MYSQL_TEST_DATABASE') ?: '',
    'charset' => getenv('PDO_MYSQL_TEST_CHARSET') ?: 'utf8mb4',
    'username' => getenv('PDO_MYSQL_TEST_USERNAME') ?: '',
    'password' => getenv('PDO_MYSQL_TEST_PASSWORD') ?: '',
];

beforeEach(function () use ($mariadb) {
    $config = $mariadb();

    if ($config['host'] === '') {
        $this->markTestSkipped('Set PDO_MYSQL_TEST_HOST to run the MariaDB integration suite.');
    }

    ConnectionRegistry::reset();
    ConnectionRegistry::configure(['default' => ['driver' => 'mariadb'] + $config]);

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

it('round-trips a record and returns its key through native RETURNING', function () {
    $result = (new User())->create(['name' => 'Ada', 'age' => 36]);

    expect($result->data())->toBe(['id' => 1, 'name' => 'Ada', 'age' => 36, 'active' => 1]);
});

it('falls back to lastInsertId when supportsReturning is off', function () {
    ConnectionRegistry::reset();
    ConnectionRegistry::configure([
        'default' => [
            'driver' => 'mariadb',
            'host' => getenv('PDO_MYSQL_TEST_HOST') ?: '',
            'port' => (int) (getenv('PDO_MYSQL_TEST_PORT') ?: 3306),
            'schema' => getenv('PDO_MYSQL_TEST_DATABASE') ?: '',
            'charset' => getenv('PDO_MYSQL_TEST_CHARSET') ?: 'utf8mb4',
            'username' => getenv('PDO_MYSQL_TEST_USERNAME') ?: '',
            'password' => getenv('PDO_MYSQL_TEST_PASSWORD') ?: '',
            'supportsReturning' => false,
            'emulateReturning' => false,
        ],
    ]);

    $raw = ConnectionRegistry::resolve()->translate(new Statement(
        StatementKind::Insert,
        'users',
        data: ['name' => Param::str('Ada')],
        returning: ['id'],
    ));

    expect($raw->insertedId())->toBe(1)
        ->and($raw->rows())->toBe([]);
});
