<?php declare(strict_types=1);

namespace STDW\Pdo\Config;

use STDW\Contract\Pdo\ConfigInterface;
use STDW\Pdo\Exception\ConnectionException;


/**
 * Configuration for SQLite.
 *
 * Required key: database (a file path or ":memory:").
 *
 * DSN: "sqlite:<database>".
 */
final class SqliteConfig implements ConfigInterface
{
    use ConfigValidationTrait;


    /**
     * @param array<array-key, mixed> $options
     */
    private function __construct(
        private readonly string $database,
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
        $database = self::requiredString($config, 'database');

        if (trim($database) === '') {
            throw ConnectionException::invalid('database');
        }

        return new self(
            $database,
            self::rawOptions($config),
            self::supportsReturningFlag($config),
            self::emulateReturningFlag($config),
        );
    }

    public function database(): string
    {
        return $this->database;
    }

    public function dsn(): string
    {
        return 'sqlite:' . $this->database;
    }

    public function username(): ?string
    {
        return null;
    }

    public function password(): ?string
    {
        return null;
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
