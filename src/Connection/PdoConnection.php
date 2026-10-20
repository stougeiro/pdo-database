<?php declare(strict_types=1);

namespace STDW\Pdo\Connection;

use PDO;
use PDOException;
use STDW\Contract\Pdo\ConfigInterface;
use STDW\Contract\Pdo\ConnectionInterface;
use STDW\Pdo\Exception\ConnectionException;


/**
 * PDO-backed connection: lifecycle plus transactions.
 *
 * The handle is created lazily from the config, so resolving a connection
 * never opens a socket; the first pdo() call does. connect() is idempotent,
 * disconnect() closes the handle (a later pdo() reconnects). Transactions
 * delegate straight to the native handle, which is what makes one shared
 * Connection span multiple Models.
 */
class PdoConnection implements ConnectionInterface
{
    private ?PDO $pdo = null;


    public function __construct(
        private readonly ConfigInterface $config,
    ) { }


    public function config(): ConfigInterface
    {
        return $this->config;
    }

    public function connect(): void
    {
        $this->pdo();
    }

    public function isConnected(): bool
    {
        return $this->pdo instanceof PDO;
    }

    public function disconnect(): void
    {
        $this->pdo = null;
    }

    public function pdo(): PDO
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

        foreach ($this->attributes() as $attribute => $value) {
            $pdo->setAttribute($attribute, $value);
        }

        return $this->pdo = $pdo;
    }

    public function begin(): void
    {
        try {
            $this->pdo()->beginTransaction();
        } catch (PDOException $exception) {
            throw ConnectionException::transactionFailed('begin', $exception);
        }
    }

    public function commit(): void
    {
        try {
            $this->pdo()->commit();
        } catch (PDOException $exception) {
            throw ConnectionException::transactionFailed('commit', $exception);
        }
    }

    public function rollback(): void
    {
        try {
            $this->pdo()->rollBack();
        } catch (PDOException $exception) {
            throw ConnectionException::transactionFailed('rollback', $exception);
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo instanceof PDO && $this->pdo->inTransaction();
    }


    /**
     * PDO attributes applied on every fresh handle: the config-provided
     * options, then the package invariants (which cannot be turned off).
     *
     * @return array<int, mixed>
     */
    protected function attributes(): array
    {
        $attributes = [];

        foreach ($this->config->options() as $attribute => $value) {
            if (is_int($attribute)) {
                $attributes[$attribute] = $value;
            }
        }

        $attributes[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $attributes[PDO::ATTR_DEFAULT_FETCH_MODE] = PDO::FETCH_ASSOC;

        return $attributes;
    }
}
