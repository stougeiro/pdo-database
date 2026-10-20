<?php declare(strict_types=1);

namespace STDW\Pdo;

use STDW\Contract\Pdo\ConnectionInterface;
use STDW\Contract\Pdo\DriverInterface;
use STDW\Pdo\Config\MysqlConfig;
use STDW\Pdo\Config\PostgresConfig;
use STDW\Pdo\Config\SqliteConfig;
use STDW\Pdo\Connection\PdoConnection;
use STDW\Pdo\Dialect\Spec\DialectSpecInterface;
use STDW\Pdo\Dialect\MysqlDialect;
use STDW\Pdo\Dialect\PostgresDialect;
use STDW\Pdo\Dialect\SqliteDialect;
use STDW\Pdo\Driver\PdoDriver;
use STDW\Pdo\Exception\ConnectionException;


/**
 * The static registry that ties a connection name to a driver nickname, the
 * config built from it and the shared Connection instance.
 *
 * It is the place reached outside the Model: cross-model transactions use
 * the shared Connection from get(); raw SQL/IR uses the Driver from resolve().
 * Nothing connects until the first pdo()/query; get() caches one Connection
 * per name so a transaction spans every Model bound to it.
 */
final class ConnectionRegistry
{
    public const SQLITE = 'sqlite';
    public const MYSQL = 'mysql';
    public const POSTGRES = 'postgres';


    /**
     * @var array<string, array{driver: string, config: array<string, mixed>}>
     */
    private static array $definitions = [];

    /**
     * @var array<string, ConnectionInterface>
     */
    private static array $connections = [];


    /**
     * Registers the named connections.
     *
     * Each entry is the driver nickname plus its config keys, e.g.:
     *   ['driver' => 'sqlite', 'database' => ':memory:']
     *
     * @param array<string, array<string, mixed>> $definitions
     */
    public static function configure(array $definitions): void
    {
        self::reset();

        $parsed = [];

        foreach ($definitions as $name => $definition) {
            if (trim($name) === '') {
                throw ConnectionException::invalid('connection name');
            }

            $driver = $definition['driver'] ?? null;

            if ( ! is_string($driver) || ! in_array($driver, [self::SQLITE, self::MYSQL, self::POSTGRES], true)) {
                throw ConnectionException::unknownDriver(is_string($driver) ? $driver : get_debug_type($driver));
            }

            $config = $definition;
            unset($config['driver']);

            /** @var array<string, mixed> $config */
            $parsed[$name] = ['driver' => $driver, 'config' => $config];
        }

        self::$definitions = $parsed;
    }

    /**
     * The shared Connection for a configured name.
     */
    public static function get(string $name = 'default'): ConnectionInterface
    {
        if (isset(self::$connections[$name])) {
            return self::$connections[$name];
        }

        $definition = self::definition($name);

        return self::$connections[$name] = self::connect($definition['driver'], $definition['config']);
    }

    /**
     * A Driver bound to the shared Connection for a configured name.
     */
    public static function resolve(string $name = 'default'): DriverInterface
    {
        $definition = self::definition($name);

        return new PdoDriver(self::get($name), self::dialect($definition['driver']));
    }

    /**
     * Drops every cached connection (disconnecting them) and the registry.
     */
    public static function reset(): void
    {
        foreach (self::$connections as $connection) {
            if ($connection->isConnected()) {
                $connection->disconnect();
            }
        }

        self::$connections = [];
        self::$definitions = [];
    }


    /**
     * @return array{driver: string, config: array<string, mixed>}
     */
    private static function definition(string $name): array
    {
        if ( ! isset(self::$definitions[$name])) {
            throw ConnectionException::notConfigured($name);
        }

        return self::$definitions[$name];
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function connect(string $driver, array $config): PdoConnection
    {
        return match ($driver) {
            self::SQLITE => new PdoConnection(SqliteConfig::from($config)),
            self::MYSQL => new PdoConnection(MysqlConfig::from($config)),
            self::POSTGRES => new PdoConnection(PostgresConfig::from($config)),
            // @codeCoverageIgnoreStart configure() already rejected any other nickname.
            default => throw ConnectionException::unknownDriver($driver),
            // @codeCoverageIgnoreEnd
        };
    }

    private static function dialect(string $driver): DialectSpecInterface
    {
        return match ($driver) {
            self::SQLITE => new SqliteDialect(),
            self::MYSQL => new MysqlDialect(),
            self::POSTGRES => new PostgresDialect(),
            // @codeCoverageIgnoreStart configure() already rejected any other nickname.
            default => throw ConnectionException::unknownDriver($driver),
            // @codeCoverageIgnoreEnd
        };
    }
}
