<?php declare(strict_types=1);

namespace STDW\Pdo\Statement;

use STDW\Contract\Pdo\Param\ParamInterface;
use STDW\Contract\Pdo\QueryTree\QueryInterface;
use STDW\Contract\Pdo\Statement\StatementInterface;
use STDW\Contract\Pdo\Statement\Type\StatementKind;
use STDW\Pdo\Exception\StatementException;


/**
 * The executable IR envelope: kind (what), table (where), query (which rows,
 * for read/update/delete) and data (the typed values to write).
 *
 * The envelope is validated at construction, so a malformed statement never
 * reaches the engine: an insert cannot carry a query, writes need data,
 * update/delete need a non-empty WHERE, and returning is only meaningful on
 * an insert. An unfiltered write is never silently allowed.
 */
final readonly class Statement implements StatementInterface
{
    private readonly StatementKind $kind;

    private readonly string $table;

    private readonly ?QueryInterface $query;

    /** @var array<string, ParamInterface> */
    private readonly array $data;

    /** @var array<int, string> */
    private readonly array $returning;


    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $returning
     */
    public function __construct(
        StatementKind $kind,
        string $table,
        ?QueryInterface $query = null,
        array $data = [],
        array $returning = [],
    ) {
        if (trim($table) === '') {
            throw StatementException::invalid('the target table cannot be empty');
        }

        $this->kind = $kind;
        $this->table = $table;
        $this->query = $query;
        $this->data = self::typedData($data);
        $this->returning = $returning;

        $this->assertEnvelope();
    }


    public function kind(): StatementKind
    {
        return $this->kind;
    }

    public function table(): string
    {
        return $this->table;
    }

    public function query(): ?QueryInterface
    {
        return $this->query;
    }

    public function data(): array
    {
        return $this->data;
    }

    public function returning(): array
    {
        return $this->returning;
    }


    /**
     * Every written value must already be a typed Param, so the compiler can
     * trust the type and no raw value ever reaches the bind stage. Fail-fast
     * at the envelope edge, before any translation or execution.
     *
     * @param array<string, mixed> $data
     * @return array<string, ParamInterface>
     */
    private static function typedData(array $data): array
    {
        $typed = [];

        foreach ($data as $column => $param) {
            if ( ! $param instanceof ParamInterface) {
                throw StatementException::dataMustBeParams($column);
            }

            $typed[$column] = $param;
        }

        return $typed;
    }

    private function assertEnvelope(): void
    {
        $where = $this->query?->where();
        $hasWhere = $where !== null && $where->nodes() !== [];

        match ($this->kind) {
            StatementKind::Select => $this->assertSelect(),
            StatementKind::Insert => $this->assertInsert(),
            StatementKind::Update => $this->assertUpdate($hasWhere),
            StatementKind::Delete => $this->assertDelete($hasWhere),
        };
    }

    private function assertSelect(): void
    {
        if ($this->data !== []) {
            throw StatementException::invalid('a select cannot carry data');
        }

        if ($this->returning !== []) {
            throw StatementException::invalid('a select cannot carry returning columns');
        }
    }

    private function assertInsert(): void
    {
        if ($this->query !== null) {
            throw StatementException::insertWithQuery();
        }

        if ($this->data === []) {
            throw StatementException::writeWithoutData('insert');
        }
    }

    private function assertUpdate(bool $hasWhere): void
    {
        if ( ! $hasWhere) {
            throw StatementException::unfilteredWrite('update');
        }

        if ($this->data === []) {
            throw StatementException::writeWithoutData('update');
        }

        if ($this->returning !== []) {
            throw StatementException::returningOutsideInsert();
        }
    }

    private function assertDelete(bool $hasWhere): void
    {
        if ( ! $hasWhere) {
            throw StatementException::unfilteredWrite('delete');
        }

        if ($this->data !== []) {
            throw StatementException::invalid('a delete cannot carry data');
        }

        if ($this->returning !== []) {
            throw StatementException::returningOutsideInsert();
        }
    }
}
