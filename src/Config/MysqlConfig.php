<?php declare(strict_types=1);

namespace STDW\Pdo\Config;

use STDW\Contract\Pdo\ConfigInterface;
use STDW\Pdo\Exception\ConnectionException;


/**
 * Configuration for MySQL.
 *
 * Required keys: host, port, schema (= database name), charset, username,
 * password. There are no silent defaults; a missing key fails fast.
 *
 * DSN: "mysql:host=…;port=…;dbname=<schema>;charset=…".
 */
final class MysqlConfig implements ConfigInterface
{
    use ConfigValidationTrait;


    /**
     * @param array<array-key, mixed> $options
     */
    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $schema,
        private readonly string $charset,
        private readonly string $username,
        private readonly string $password,
        private readonly array $options,
        private readonly bool $supportsReturning,
        private readonly bool $emulateReturning,
    ) {
    }


    /**
     * @param array<array-key, mixed> $config
     */
    public static function from(array $config): self
    {
        $config = self::normalize($config);

        $host = self::requiredString($config, 'host');
        $schema = self::requiredString($config, 'schema');
        $charset = self::requiredString($config, 'charset');

        foreach (['host' => $host, 'schema' => $schema, 'charset' => $charset] as $key => $value) {
            if (trim($value) === '') {
                throw ConnectionException::invalid($key);
            }
        }

        return new self(
            $host,
            self::requiredInt($config, 'port'),
            $schema,
            $charset,
            self::requiredString($config, 'username'),
            self::requiredString($config, 'password'),
            self::rawOptions($config),
            self::supportsReturningFlag($config),
            self::emulateReturningFlag($config),
        );
    }


    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function schema(): string
    {
        return $this->schema;
    }

    public function charset(): string
    {
        return $this->charset;
    }

    public function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->schema,
            $this->charset,
        );
    }

    public function username(): string
    {
        return $this->username;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function options(): array
    {
        return $this->options;
    }

    public function supportsReturning(): bool
    {
        return $this->supportsReturning;
    }

    public function emulateReturning(): bool
    {
        return $this->emulateReturning;
    }
}
