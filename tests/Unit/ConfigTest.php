<?php declare(strict_types=1);

use STDW\Pdo\Config\MariaDbConfig;
use STDW\Pdo\Config\MysqlConfig;
use STDW\Pdo\Config\PostgresConfig;
use STDW\Pdo\Config\SqliteConfig;
use STDW\Pdo\Exception\ConnectionException;

it('builds the sqlite dsn and exposes no credentials', function () {
    $config = SqliteConfig::from(['database' => ':memory:']);

    expect($config->dsn())->toBe('sqlite::memory:')
        ->and($config->database())->toBe(':memory:')
        ->and($config->username())->toBeNull()
        ->and($config->password())->toBeNull()
        ->and($config->options())->toBe([]);
});

it('builds the mysql dsn from every required key', function () {
    $config = MysqlConfig::from([
        'host' => 'localhost',
        'port' => 3306,
        'schema' => 'app',
        'charset' => 'utf8mb4',
        'username' => 'root',
        'password' => 'secret',
        'options' => [PDO::ATTR_EMULATE_PREPARES => false],
    ]);

    expect($config->dsn())->toBe('mysql:host=localhost;port=3306;dbname=app;charset=utf8mb4')
        ->and($config->host())->toBe('localhost')
        ->and($config->port())->toBe(3306)
        ->and($config->schema())->toBe('app')
        ->and($config->charset())->toBe('utf8mb4')
        ->and($config->username())->toBe('root')
        ->and($config->password())->toBe('secret')
        ->and($config->options())->toBe([PDO::ATTR_EMULATE_PREPARES => false]);
});

it('builds the postgres dsn from every required key', function () {
    $config = PostgresConfig::from([
        'host' => 'db',
        'port' => '5432',
        'schema' => 'app',
        'username' => 'postgres',
        'password' => 'secret',
    ]);

    expect($config->dsn())->toBe('pgsql:host=db;port=5432;dbname=app')
        ->and($config->port())->toBe(5432)
        ->and($config->username())->toBe('postgres');
});

it('fails fast on a missing required key', function () {
    SqliteConfig::from([]);
})->throws(ConnectionException::class);

it('fails fast on a malformed port', function () {
    MysqlConfig::from([
        'host' => 'localhost',
        'port' => 'not-a-port',
        'schema' => 'app',
        'charset' => 'utf8mb4',
        'username' => 'root',
        'password' => 'secret',
    ]);
})->throws(ConnectionException::class);

it('fails fast on an empty required value', function () {
    SqliteConfig::from(['database' => '   ']);
})->throws(ConnectionException::class);

it('rejects non-string configuration keys', function () {
    SqliteConfig::from([':memory:']);
})->throws(ConnectionException::class);

it('rejects a non-array options value', function () {
    SqliteConfig::from(['database' => ':memory:', 'options' => 'nope']);
})->throws(ConnectionException::class);

it('rejects a non-string value for a required string key', function () {
    SqliteConfig::from(['database' => 123]);
})->throws(ConnectionException::class);

it('accepts a numeric string port and an int port alike', function () {
    $base = [
        'host' => 'localhost',
        'schema' => 'app',
        'charset' => 'utf8mb4',
        'username' => 'root',
        'password' => 'secret',
    ];

    expect(MysqlConfig::from($base + ['port' => '3306'])->port())->toBe(3306)
        ->and(MysqlConfig::from($base + ['port' => 3306])->port())->toBe(3306);
});

it('rejects a missing port', function () {
    MysqlConfig::from([
        'host' => 'localhost',
        'schema' => 'app',
        'charset' => 'utf8mb4',
        'username' => 'root',
        'password' => 'secret',
    ]);
})->throws(ConnectionException::class);

it('rejects an empty host', function () {
    MysqlConfig::from([
        'host' => '   ',
        'port' => 3306,
        'schema' => 'app',
        'charset' => 'utf8mb4',
        'username' => 'root',
        'password' => 'secret',
    ]);
})->throws(ConnectionException::class);

it('rejects an empty postgres host', function () {
    PostgresConfig::from([
        'host' => '  ',
        'port' => 5432,
        'schema' => 'app',
        'username' => 'postgres',
        'password' => 'secret',
    ]);
})->throws(ConnectionException::class);

it('exposes the postgres credentials and options', function () {
    $config = PostgresConfig::from([
        'host' => 'db',
        'port' => 5432,
        'schema' => 'app',
        'username' => 'postgres',
        'password' => 'secret',
        'options' => [PDO::ATTR_PERSISTENT => true],
    ]);

    expect($config->host())->toBe('db')
        ->and($config->schema())->toBe('app')
        ->and($config->password())->toBe('secret')
        ->and($config->options())->toBe([PDO::ATTR_PERSISTENT => true]);
});

it('defaults emulateReturning to true on every config', function () {
    expect(SqliteConfig::from(['database' => ':memory:'])->emulateReturning())->toBeTrue()
        ->and(MysqlConfig::from([
            'host' => 'localhost',
            'port' => 3306,
            'schema' => 'app',
            'charset' => 'utf8mb4',
            'username' => 'root',
            'password' => 'secret',
        ])->emulateReturning())->toBeTrue()
        ->and(PostgresConfig::from([
            'host' => 'db',
            'port' => 5432,
            'schema' => 'app',
            'username' => 'postgres',
            'password' => 'secret',
        ])->emulateReturning())->toBeTrue();
});

it('honors an explicit boolean emulateReturning', function () {
    expect(SqliteConfig::from(['database' => ':memory:', 'emulateReturning' => false])->emulateReturning())
        ->toBeFalse();
});

it('rejects a non-boolean emulateReturning', function () {
    SqliteConfig::from(['database' => ':memory:', 'emulateReturning' => 'yes']);
})->throws(ConnectionException::class);

it('defaults supportsReturning to true on every config', function () {
    expect(SqliteConfig::from(['database' => ':memory:'])->supportsReturning())->toBeTrue()
        ->and(MysqlConfig::from([
            'host' => 'localhost',
            'port' => 3306,
            'schema' => 'app',
            'charset' => 'utf8mb4',
            'username' => 'root',
            'password' => 'secret',
        ])->supportsReturning())->toBeTrue()
        ->and(PostgresConfig::from([
            'host' => 'db',
            'port' => 5432,
            'schema' => 'app',
            'username' => 'postgres',
            'password' => 'secret',
        ])->supportsReturning())->toBeTrue();
});

it('honors an explicit boolean supportsReturning', function () {
    expect(SqliteConfig::from(['database' => ':memory:', 'supportsReturning' => false])->supportsReturning())
        ->toBeFalse();
});

it('rejects a non-boolean supportsReturning', function () {
    SqliteConfig::from(['database' => ':memory:', 'supportsReturning' => 'yes']);
})->throws(ConnectionException::class);

it('builds the mariadb dsn and exposes the generated-key policy', function () {
    $config = MariaDbConfig::from([
        'host' => 'localhost',
        'port' => 3306,
        'schema' => 'app',
        'charset' => 'utf8mb4',
        'username' => 'root',
        'password' => 'secret',
        'options' => [PDO::ATTR_EMULATE_PREPARES => false],
    ]);

    expect($config->dsn())->toBe('mysql:host=localhost;port=3306;dbname=app;charset=utf8mb4')
        ->and($config->host())->toBe('localhost')
        ->and($config->port())->toBe(3306)
        ->and($config->schema())->toBe('app')
        ->and($config->charset())->toBe('utf8mb4')
        ->and($config->username())->toBe('root')
        ->and($config->password())->toBe('secret')
        ->and($config->options())->toBe([PDO::ATTR_EMULATE_PREPARES => false])
        ->and($config->supportsReturning())->toBeTrue()
        ->and($config->emulateReturning())->toBeTrue();
});

it('fails fast on a missing mariadb key', function () {
    MariaDbConfig::from(['host' => 'localhost']);
})->throws(ConnectionException::class);

it('rejects an empty mariadb charset', function () {
    MariaDbConfig::from([
        'host' => 'localhost',
        'port' => 3306,
        'schema' => 'app',
        'charset' => '   ',
        'username' => 'root',
        'password' => 'secret',
    ]);
})->throws(ConnectionException::class);

it('allows turning off supportsReturning on mariadb for an older server', function () {
    $config = MariaDbConfig::from([
        'host' => 'localhost',
        'port' => 3306,
        'schema' => 'app',
        'charset' => 'utf8mb4',
        'username' => 'root',
        'password' => 'secret',
        'supportsReturning' => false,
    ]);

    expect($config->supportsReturning())->toBeFalse();
});
