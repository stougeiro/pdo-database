<?php declare(strict_types=1);

namespace STDW\Pdo\Driver;

use PDO;
use PDOException;
use PDOStatement;
use STDW\Contract\Pdo\ConnectionInterface;
use STDW\Contract\Pdo\DriverInterface;
use STDW\Contract\Pdo\Result\RawInterface;
use STDW\Contract\Pdo\Statement\StatementInterface;
use STDW\Contract\Pdo\Statement\Type\StatementKind;
use STDW\Pdo\Dialect\Spec\DialectSpecInterface;
use STDW\Pdo\Exception\DriverException;
use STDW\Pdo\Result\Raw;


/**
 * The single, IR-driven PDO driver.
 *
 * It renders the Statement through the common Compiler and its DialectSpecInterface
 * prepares and binds every value with ParamType->value (which IS the PDO
 * binding constant; values are never interpolated) and returns a normalized
 * Raw. It also absorbs the generated-key split: RETURNING where supported,
 * lastInsertId() otherwise.
 */
final class PdoDriver implements DriverInterface
{
    private readonly Compiler $compiler;


    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly DialectSpecInterface $dialect,
    ) {
        $this->compiler = new Compiler($dialect);
    }


    public function execute(StatementInterface $statement): RawInterface
    {
        $compiled = $this->compiler->compile($statement);
        $pdo = $this->connection->pdo();

        try {
            $prepared = $pdo->prepare($compiled['sql']);

            $position = 1;

            foreach ($compiled['params'] as $param) {
                $prepared->bindValue($position, $param->value(), $param->type()->value);
                $position++;
            }

            $prepared->execute();
        } catch (PDOException $exception) {
            throw DriverException::executionFailed($compiled['sql'], $exception);
        }

        return match ($statement->kind()) {
            StatementKind::Select => new Raw(self::fetchRows($prepared), 0, null),
            StatementKind::Insert => $this->inserted($statement, $prepared, $pdo),
            default => new Raw([], $prepared->rowCount(), null),
        };
    }


    private function inserted(StatementInterface $statement, PDOStatement $prepared, PDO $pdo): RawInterface
    {
        $affected = $prepared->rowCount();
        $returning = $statement->returning();

        if ($returning !== [] && $this->dialect->supportsReturning()) {
            $rows = self::fetchRows($prepared);
            $insertedId = null;

            if ($rows !== []) {
                $insertedId = self::returnedKey($rows[0], $returning[0]);
            }

            return new Raw($rows, $affected, $insertedId);
        }

        $last = $pdo->lastInsertId();

        $insertedId = match (true) {
            $last === false, $last === '', $last === '0' => null,
            ctype_digit($last) => (int) $last,
            default => $last,
        };

        return new Raw([], $affected, $insertedId);
    }

    /**
     * @param array<int|string, mixed> $row
     */
    private static function returnedKey(array $row, string $column): null|int|string
    {
        $value = $row[$column] ?? null;

        if ($value === null) {
            $fallback = reset($row);
            $value = $fallback === false ? null : $fallback;
        }

        return is_int($value) || is_string($value) ? $value : null;
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    private static function fetchRows(PDOStatement $statement): array
    {
        /** @var array<int, array<int|string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }
}
