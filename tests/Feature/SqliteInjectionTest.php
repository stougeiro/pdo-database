<?php declare(strict_types=1);

use STDW\Pdo\ConnectionRegistry;
use STDW\Pdo\Param\Param;
use Tests\Fixtures\User;

/**
 * Raw, hostile values at the call site are bound as typed Params, so they are
 * stored and matched verbatim and can never alter the statement. These tests
 * exercise every write/read path of the model with injection payloads.
 */
beforeEach(function () {
    ConnectionRegistry::reset();
    ConnectionRegistry::configure([
        'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ]);

    ConnectionRegistry::get()->execute(
        'CREATE TABLE users ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
        . 'name TEXT NOT NULL, '
        . 'age INTEGER, '
        . 'active INTEGER NOT NULL DEFAULT 1'
        . ')'
    );
});

afterEach(function () {
    ConnectionRegistry::reset();
});

$payloads = [
    "Robert'); DROP TABLE users; --",
    "' OR '1'='1",
    '1 OR 1=1',
    '"; DROP TABLE users; --',
    '%',
    '_',
];

it('binds hostile values verbatim on create and keeps the table intact', function () use ($payloads) {
    $user = new User();

    foreach ($payloads as $index => $payload) {
        $created = $user->create(['name' => $payload]);

        expect($created->data()['name'])->toBe($payload);
        expect($created->data()['id'])->toBe($index + 1);
    }

    expect($user->list()->count())->toBe(count($payloads));
});

it('matches hostile filter values as literals, not as SQL', function () {
    $user = new User();
    $user->create(['name' => "Robert'); DROP TABLE users; --"]);
    $user->create(['name' => 'Ada']);

    expect($user->read(['name' => "Robert'); DROP TABLE users; --"])->count())->toBe(1)
        ->and($user->read(["name" => "' OR '1'='1"])->count())->toBe(0)
        ->and($user->list(["name" => "' OR '1'='1"])->count())->toBe(0)
        ->and($user->list()->count())->toBe(2);
});

it('does not let a hostile WHERE value widen or drop on update and delete', function () {
    $user = new User();
    $user->create(['name' => 'Ada']);
    $user->create(['name' => 'Grace']);

    $updated = $user->update(['active' => 0], ['name' => "' OR '1'='1"]);
    $deleted = $user->delete(['name' => "' OR '1'='1"]);

    expect($updated->count())->toBe(0)
        ->and($deleted->count())->toBe(0)
        ->and($user->list()->count())->toBe(2)
        ->and($user->list(['active' => 0])->count())->toBe(0);
});

it('binds hostile values inside in, between and like filters', function () {
    $user = new User();
    $user->create(['name' => 'Ada', 'age' => 36]);

    expect($user->list(['name in' => ["' OR '1'='1"]])->count())->toBe(0)
        ->and($user->list(['age between' => [0, 1000]])->count())->toBe(1)
        ->and($user->list(['name like' => "' OR '1'='1%"])->count())->toBe(0)
        ->and($user->list()->count())->toBe(1);
});

it('accepts an explicit Param on data and filter without changing behavior', function () {
    $user = new User();

    $user->create(['name' => Param::str("Robert'); DROP TABLE users; --")]);

    expect($user->read(['name' => Param::str("Robert'); DROP TABLE users; --")])->count())->toBe(1)
        ->and($user->list()->count())->toBe(1);
});
