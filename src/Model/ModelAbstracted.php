<?php declare(strict_types=1);

namespace STDW\Pdo\Model;

use STDW\Contract\Pdo\DriverInterface;
use STDW\Contract\Pdo\ModelInterface;
use STDW\Contract\Pdo\Param\ParamInterface;
use STDW\Contract\Pdo\Result\ResultInterface;
use STDW\Contract\Pdo\Result\RawInterface;
use STDW\Contract\Pdo\Statement\StatementInterface;
use STDW\Contract\Pdo\Statement\Type\StatementKind;
use STDW\Pdo\ConnectionRegistry;
use STDW\Pdo\Exception\ConnectionException;
use STDW\Pdo\Param\Param;
use STDW\Pdo\QueryTree\QueryTreeBuilder;
use STDW\Pdo\Result\Result;
use STDW\Pdo\Statement\Statement;


/**
 * CRUD spearhead: a table reached through arrays.
 *
 * A subclass declares $table and, optionally, $connection (the configured
 * name) and $key (the generated-key column). The model lowers the array DSL
 * to the neutral query tree, wraps values into typed Params and hands an IR
 * Statement to the driver; it never branches on dialect and exposes neither
 * driver nor connection. What it gains from the registry is a shared
 * Connection: the same instance used by every model bound to that name,
 * which is what makes one transaction span several models.
 */
abstract class ModelAbstracted implements ModelInterface
{
    protected string $table = '';

    protected string $connection = 'default';

    protected string $key = 'id';

    private ?DriverInterface $driver = null;


    public function table(): string
    {
        if (trim($this->table) === '') {
            throw ConnectionException::tableNotDeclared(static::class);
        }

        return $this->table;
    }

    public function create(array $data): ResultInterface
    {
        $raw = $this->execute(new Statement(
            kind: StatementKind::Insert,
            table: $this->table(),
            data: $this->params($data),
            returning: [$this->key],
        ));

        $insertedId = $raw->insertedId();

        $created = $insertedId === null
            ? $data
            : array_merge($data, [$this->key => $insertedId]);

        return Result::ok($created, $raw->affected() > 0 ? 1 : 0);
    }

    public function read(array $filter): ResultInterface
    {
        $raw = $this->execute(new Statement(
            kind: StatementKind::Select,
            table: $this->table(),
            query: QueryTreeBuilder::query(filter: $filter, pagination: ['limit' => 1]),
        ));

        $row = $raw->rows()[0] ?? null;

        return Result::ok($row, $row === null ? 0 : 1);
    }

    public function list(array $filter = [], array $order = [], array $pagination = []): ResultInterface
    {
        $raw = $this->execute(new Statement(
            kind: StatementKind::Select,
            table: $this->table(),
            query: QueryTreeBuilder::query(filter: $filter, order: $order, pagination: $pagination),
        ));

        $rows = $raw->rows();

        return Result::ok($rows, count($rows));
    }

    public function update(array $data, array $filter): ResultInterface
    {
        $raw = $this->execute(new Statement(
            kind: StatementKind::Update,
            table: $this->table(),
            query: QueryTreeBuilder::query(filter: $filter),
            data: $this->params($data),
        ));

        return Result::ok(null, $raw->affected());
    }

    public function delete(array $filter): ResultInterface
    {
        $raw = $this->execute(new Statement(
            kind: StatementKind::Delete,
            table: $this->table(),
            query: QueryTreeBuilder::query(filter: $filter),
        ));

        return Result::ok(null, $raw->affected());
    }


    protected function driver(): DriverInterface
    {
        return $this->driver ??= ConnectionRegistry::resolve($this->connection);
    }

    private function execute(StatementInterface $statement): RawInterface
    {
        return $this->driver()->execute($statement);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, ParamInterface>
     */
    private function params(array $data): array
    {
        $params = [];

        foreach ($data as $column => $value) {
            $params[$column] = $value instanceof ParamInterface ? $value : Param::from($value);
        }

        return $params;
    }
}
