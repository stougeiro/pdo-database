<?php declare(strict_types=1);

namespace STDW\Pdo\Param;

use DateTimeInterface;
use JsonException;
use STDW\Contract\Pdo\Param\ParamInterface;
use STDW\Contract\Pdo\Param\Type\ParamType;
use STDW\Pdo\Exception\QueryTreeException;
use Stringable;


/**
 * The typed value sent to a bind slot.
 *
 * The type is fixed at construction, so the driver never guesses from the
 * PHP value: it binds $type()->value (which IS the matching PDO::PARAM_*)
 * directly. Semantic PHP variants are folded into storage primitives by
 * from(): float becomes a String (PDO has no PARAM_FLOAT), DateTimeInterface
 * a "Y-m-d H:i:s" string, arrays a JSON string and Stringable objects a
 * string. Binary payloads go through binary() (bound as LOB).
 */
final readonly class Param implements ParamInterface
{
    private function __construct(
        private null|bool|int|float|string $value,
        private ParamType $type,
    ) {
    }


    public static function null(): self
    {
        return new self(null, ParamType::Null);
    }

    public static function bool(bool $value): self
    {
        return new self($value, ParamType::Bool);
    }

    public static function int(int $value): self
    {
        return new self($value, ParamType::Int);
    }

    public static function str(string $value): self
    {
        return new self($value, ParamType::String);
    }

    public static function binary(string $value): self
    {
        return new self($value, ParamType::Binary);
    }

    public static function from(mixed $value): self
    {
        return match (true) {
            $value === null => self::null(),
            is_bool($value) => self::bool($value),
            is_int($value) => self::int($value),
            is_float($value) => new self($value, ParamType::String),
            is_string($value) => self::str($value),
            is_array($value) => self::str(self::encode($value)),
            $value instanceof DateTimeInterface => self::str($value->format('Y-m-d H:i:s')),
            $value instanceof Stringable => self::str($value->__toString()),

            default => throw QueryTreeException::invalid(sprintf(
                'value of type "%s" cannot be typed',
                get_debug_type($value),
            )),
        };
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function type(): ParamType
    {
        return $this->type;
    }

    public function __toString(): string
    {
        return match ($this->type) {
            ParamType::Null => '',
            ParamType::Bool => $this->value ? '1' : '0',

            default => (string) $this->value,
        };
    }


    /**
     * @param array<array-key, mixed> $value
     */
    private static function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw QueryTreeException::invalid('array value cannot be encoded as JSON: ' . $exception->getMessage());
        }
    }
}
