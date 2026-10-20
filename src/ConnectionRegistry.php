<?php declare(strict_types=1);

namespace STDW\Pdo;

use STDW\Contract\Pdo\ConfigInterface;
use STDW\Contract\Pdo\ConnectionInterface;
use STDW\Contract\Pdo\DialectInterface;
use STDW\Contract\Pdo\DriverInterface;
use STDW\Pdo\Config\MariaDbConfig;
use STDW\Pdo\Config\MysqlConfig;
use STDW\Pdo\Config\PostgresConfig;
use STDW\Pdo\Config\SqliteConfig;
use STDW\Pdo\Connection\PdoConnection;
use STDW\Pdo\Dialect\MariaDbDialect;
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
 * Nothing connects until the first query; get() caches one Connection per
 * name so a transaction spans every Model bound to it.
 */
final class ConnectionRegistry
{
    public const SQLITE = 'sqlite';
    public const MYSQL = 'mysql';
    public const MARIADB = 'mariadb';
    public const POSTGRES = 'postgres';


    /**
     * @var array<string, array{driver: string, config: array<string, mixed>}>
     */
    private static array $definitions = [];

    /**
     * @var array<string, array{connection: PdoConnection, dialect: DialectInterface, supportsReturning: bool, emulateReturning: bool}>
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

            if ( ! is_string($driver) || ! in_array($driver, [self::SQLITE, self::MYSQL, self::MARIADB, self::POSTGRES], true)) {
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
        return self::pair($name)['connection'];
    }

    /**
     * A Driver bound to the shared Connection for a configured name.
     */
    public static function resolve(string $name = 'default'): DriverInterface
    {
        $pair = self::pair($name);

        return new PdoDriver($pair['connection'], $pair['dialect'], $pair['supportsReturning'], $pair['emulateReturning']);
    }

    /**
     * Drops every cached connection (disconnecting them) and the registry.
     */
    public static function reset(): void
    {
        foreach (self::$connections as $pair) {
            if ($pair['connection']->isConnected()) {
                $pair['connection']->disconnect();
            }
        }

        self::$connections = [];
        self::$definitions = [];
    }


    /**
     * @return array{connection: PdoConnection, dialect: DialectInterface, supportsReturning: bool, emulateReturning: bool}
     */
    private static function pair(string $name): array
    {
        if (isset(self::$connections[$name])) {
            return self::$connections[$name];
        }

        $definition = self::definition($name);

        return self::$connections[$name] = self::build($definition['driver'], $definition['config']);
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
     * @return array{connection: PdoConnection, dialect: DialectInterface, supportsReturning: bool, emulateReturning: bool}
     */
    private static function build(string $driver, array $config): array
    {
        return match ($driver) {
            self::SQLITE => self::compose(SqliteConfig::from($config), new SqliteDialect()),
            self::MYSQL => self::compose(MysqlConfig::from($config), new MysqlDialect()),
            self::MARIADB => self::compose(MariaDbConfig::from($config), new MariaDbDialect()),
            self::POSTGRES => self::compose(PostgresConfig::from($config), new PostgresDialect()),
            // @codeCoverageIgnoreStart configure() already rejected any other nickname.
            default => throw ConnectionException::unknownDriver($driver),
            // @codeCoverageIgnoreEnd
        };
    }

    /**
     * @return array{connection: PdoConnection, dialect: DialectInterface, supportsReturning: bool, emulateReturning: bool}
     */
    private static function compose(ConfigInterface $config, DialectInterface $dialect): array
    {
        return [
            'connection' => new PdoConnection($config),
            'dialect' => $dialect,
            'supportsReturning' => $config->supportsReturning(),
            'emulateReturning' => $config->emulateReturning(),
        ];
    }
}
