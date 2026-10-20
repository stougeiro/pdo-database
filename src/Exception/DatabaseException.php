<?php declare(strict_types=1);

namespace STDW\Pdo\Exception;

use RuntimeException;
use STDW\Contract\Pdo\Exception\DatabaseExceptionInterface;


/**
 * Base exception of the package. Every concrete exception extends this class
 * and implements the marker interface of its own layer, so any failure is
 * catchable through DatabaseExceptionInterface.
 */
abstract class DatabaseException extends RuntimeException implements DatabaseExceptionInterface
{
}
