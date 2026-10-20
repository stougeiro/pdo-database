<?php declare(strict_types=1);

use STDW\Contract\Pdo\DriverInterface;
use STDW\Pdo\ConnectionRegistry;
use STDW\Pdo\Exception\ConnectionException;

beforeEach(function () {
    ConnectionRegistry::reset();
});

afterEach(function () {
    ConnectionRegistry::reset();
});

it('fails to resolve an unconfigured connection', function () {
    ConnectionRegistry::get('missing');
})->throws(ConnectionException::class);

it('fails on an unknown driver nickname', function () {
    ConnectionRegistry::configure([
        'default' => ['driver' => 'oracle'],
    ]);
})->throws(ConnectionException::class);

it('caches one shared connection per name', function () {
    ConnectionRegistry::configure([
        'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'other' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ]);

    expect(ConnectionRegistry::get('default'))->toBe(ConnectionRegistry::get('default'))
        ->and(ConnectionRegistry::get('default'))->not->toBe(ConnectionRegistry::get('other'));
});

it('resolves a driver over the shared connection', function () {
    ConnectionRegistry::configure([
        'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ]);

    expect(ConnectionRegistry::resolve())->toBeInstanceOf(DriverInterface::class);
});

it('connects lazily and disconnects idempotently', function () {
    ConnectionRegistry::configure([
        'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ]);

    $connection = ConnectionRegistry::get();

    expect($connection->isConnected())->toBeFalse();

    $connection->connect();
    $connection->connect();

    expect($connection->isConnected())->toBeTrue();

    $connection->disconnect();

    expect($connection->isConnected())->toBeFalse();
});

it('cannot turn off the error mode or fetch mode invariants', function () {
    ConnectionRegistry::configure([
        'default' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
            ],
        ],
    ]);

    $pdo = ConnectionRegistry::get()->pdo();

    expect($pdo->getAttribute(PDO::ATTR_ERRMODE))->toBe(PDO::ERRMODE_EXCEPTION)
        ->and($pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE))->toBe(PDO::FETCH_ASSOC);
});

it('drops the registry on reset', function () {
    ConnectionRegistry::configure([
        'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ]);

    ConnectionRegistry::get();
    ConnectionRegistry::reset();

    ConnectionRegistry::get();
})->throws(ConnectionException::class);
