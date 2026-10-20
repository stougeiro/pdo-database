<?php declare(strict_types=1);

namespace STDW\Pdo\Connection;

use PDO;
use PDOException;
use PDOStatement;
use STDW\Contract\Pdo\ConfigInterface;
use STDW\Contract\Pdo\ConnectionInterface;
use STDW\Contract\Pdo\Param\ParamInterface;
use STDW\Pdo\Exception\ConnectionException;


/**
 * PDO-backed connection: lifecycle, transactions and the raw SQL boundary.
 *
 * The handle is created lazily from the config, so resolving a connection
 * never opens a socket; the first query does. connect() is idempotent,
 * disconnect() closes the handle (a later query reconnects). Transactions and
 * raw SQL delegate straight to the native handle, which is what makes one
 * shared Connection span multiple Models. The handle itself is not exposed:
 * raw access goes through fetch/fetchAll/execute and the generated key
 * through lastInsertId(), each bind every value as a typed Param and never
 * interpolate it.
 */
class PdoConnection implements ConnectionInterface
{
    private ?PDO $pdo = null;


    public function __construct(
        protected readonly ConfigInterface $config,
    ) { }


    public function connect(): void
    {
        $this->handle();
    }

    public function isConnected(): bool
    {
        return $this->pdo instanceof PDO;
    }

    public function disconnect(): void
    {
        $this->pdo = null;
    }

    public function begin(): void
    {
        $this->wrapTransaction('begin', fn () => $this->handle()->beginTransaction());
    }

    public function commit(): void
    {
        $this->wrapTransaction('commit', fn () => $this->handle()->commit());
    }

    public function rollback(): void
    {
        $this->wrapTransaction('rollback', fn () => $this->handle()->rollBack());
    }

    public function inTransaction(): bool
    {
        return $this->pdo instanceof PDO && $this->pdo->inTransaction();
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return is_array($row) ? $row : null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var array<int, array<int|string, mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll();

        return $rows;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function lastInsertId(): ?string
    {
        $id = $this->handle()->lastInsertId();

        return $id === false ? null : $id;
    }


    /**
     * The one place a statement is prepared and its values bound: every entry
     * of $params MUST be a ParamInterface (positional ? or named :name), so a
     * value never reaches SQL as text. Shared by the raw surface and the
     * Driver, which delegates its execution here.
     *
     * @param array<array-key, mixed> $params
     */
    final protected function run(string $sql, array $params = []): PDOStatement
    {
        $pdo = $this->handle();

        try {
            $statement = $pdo->prepare($sql);

            foreach ($params as $key => $param) {
                if ( ! $param instanceof ParamInterface) {
                    throw ConnectionException::parameterRequired(is_string($key) ? $key : (string) $key);
                }

                $statement->bindValue(
                    is_int($key) ? $key + 1 : ':' . ltrim($key, ':'),
                    $param->value(),
                    $param->type()->value,
                );
            }

            $statement->execute();
        } catch (PDOException $exception) {
            throw ConnectionException::queryFailed($sql, $exception);
        }

        return $statement;
    }

    /**
     * The lazy native handle: created on first use from the config, with the
     * package attributes applied. Kept protected — the contract exposes raw
     * access through methods, never the handle.
     */
    protected function handle(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        try {
            $pdo = new PDO(
                $this->config->dsn(),
                $this->config->username(),
                $this->config->password(),
            );
        } catch (PDOException $exception) {
            throw ConnectionException::failed($this->config->dsn(), $exception);
        }

        foreach ($this->config->options() as $attribute => $value) {
            if (is_int($attribute)) {
                $pdo->setAttribute($attribute, $value);
            }
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $this->pdo = $pdo;
    }

    private function wrapTransaction(string $action, callable $operation): void
    {
        try {
            $operation();
        } catch (PDOException $exception) {
            throw ConnectionException::transactionFailed($action, $exception);
        }
    }
}
