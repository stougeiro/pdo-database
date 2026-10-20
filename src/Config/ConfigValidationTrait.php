<?php declare(strict_types=1);

namespace STDW\Pdo\Config;

use STDW\Pdo\Exception\ConnectionException;


/**
 * Shared reading/validation of the raw configuration array. Each dialect
 * value object declares its own required keys and validates them on
 * construction (from()), so the driver never receives a half-specified entry.
 */
trait ConfigValidationTrait
{
    /**
     * @param array<array-key, mixed> $config
     * @return array<string, mixed>
     */
    private static function normalize(array $config): array
    {
        foreach (array_keys($config) as $key) {
            if ( ! is_string($key)) {
                throw ConnectionException::invalid('configuration keys must be strings');
            }
        }

        /** @var array<string, mixed> $config */
        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requiredString(array $config, string $key): string
    {
        if ( ! array_key_exists($key, $config)) {
            throw ConnectionException::missing($key);
        }

        $value = $config[$key];

        if ( ! is_string($value)) {
            throw ConnectionException::invalid($key);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requiredInt(array $config, string $key): int
    {
        if ( ! array_key_exists($key, $config)) {
            throw ConnectionException::missing($key);
        }

        $value = $config[$key];

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw ConnectionException::invalid($key);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<array-key, mixed>
     */
    private static function rawOptions(array $config): array
    {
        if ( ! array_key_exists('options', $config)) {
            return [];
        }

        $value = $config['options'];

        if ( ! is_array($value)) {
            throw ConnectionException::invalid('options');
        }

        return $value;
    }
}
