<?php declare(strict_types=1);

use STDW\Contract\Pdo\Statement\Type\StatementKind;
use STDW\Pdo\ConnectionRegistry;
use STDW\Pdo\Param\Param;
use STDW\Pdo\QueryTree\QueryTreeBuilder;
use STDW\Pdo\Statement\Statement;
use Tests\Fixtures\User;

beforeEach(function () {
    ConnectionRegistry::reset();
    ConnectionRegistry::configure([
        'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ]);

    ConnectionRegistry::get()->pdo()->exec(
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

it('creates a record and returns its generated key', function () {
    $result = (new User())->create(['name' => 'Ada', 'age' => 36]);

    expect($result->isValid())->toBeTrue()
        ->and($result->count())->toBe(1)
        ->and($result->data())->toBe(['name' => 'Ada', 'age' => 36, 'id' => 1]);
});

it('reads a single record by filter', function () {
    $user = new User();
    $user->create(['name' => 'Ada', 'age' => 36]);

    $result = $user->read(['name' => 'Ada']);

    expect($result->count())->toBe(1);

    $row = $result->data();

    expect($row)->toBeArray()
        ->and($row['name'])->toBe('Ada')
        ->and($row['age'])->toBe(36);
});

it('reads the first record with a filter and limit', function () {
    $user = new User();
    $user->create(['name' => 'Ada', 'age' => 36]);
    $user->create(['name' => 'Grace', 'age' => 45]);

    $result = $user->read(['age gte' => 30]);

    expect($result->count())->toBe(1)
        ->and($result->data()['name'])->toBe('Ada');
});

it('lists with filter, order and pagination', function () {
    $user = new User();
    $user->create(['name' => 'Ada', 'age' => 36]);
    $user->create(['name' => 'Grace', 'age' => 45]);
    $user->create(['name' => 'Linus', 'age' => 28]);

    $result = $user->list(
        filter: ['age gte' => 30],
        order: ['age' => 'desc'],
        pagination: ['limit' => 1],
    );

    expect($result->count())->toBe(1)
        ->and($result->data()[0]['name'])->toBe('Grace');
});

it('paginates a list with a cursor', function () {
    $user = new User();
    $user->create(['name' => 'Ada']);
    $user->create(['name' => 'Grace']);
    $user->create(['name' => 'Linus']);

    $result = $user->list(
        order: ['id' => 'asc'],
        pagination: ['cursor' => 1, 'limit' => 2],
    );

    expect($result->count())->toBe(2)
        ->and($result->data()[0]['id'])->toBe(2)
        ->and($result->data()[1]['id'])->toBe(3);
});

it('supports in, between, is null and ilike filters', function () {
    $user = new User();
    $user->create(['name' => 'Ada', 'age' => 36, 'active' => 1]);
    $user->create(['name' => 'Grace', 'age' => 45, 'active' => 0]);
    $user->create(['name' => 'Linus', 'age' => null, 'active' => 1]);

    expect($user->list(['age in' => [28, 36]])->count())->toBe(1)
        ->and($user->list(['age between' => [30, 50]])->count())->toBe(2)
        ->and($user->list(['age is_null' => null])->count())->toBe(1)
        ->and($user->list(['age is_not_null' => null])->count())->toBe(2)
        ->and($user->list(['name ilike' => 'a%'])->count())->toBe(1);
});

it('updates and deletes the matching records', function () {
    $user = new User();
    $user->create(['name' => 'Ada', 'age' => 36, 'active' => 1]);

    $updated = $user->update(['active' => 0], ['name' => 'Ada']);

    expect($updated->count())->toBe(1)
        ->and($user->read(['active' => 0])->count())->toBe(1);

    $deleted = $user->delete(['name' => 'Ada']);

    expect($deleted->count())->toBe(1)
        ->and($user->read(['name' => 'Ada'])->count())->toBe(0);
});

it('rolls back a transaction spanning model calls', function () {
    $connection = ConnectionRegistry::get();

    $connection->begin();
    (new User())->create(['name' => 'Temp', 'age' => 1]);

    expect($connection->inTransaction())->toBeTrue();

    $connection->rollback();

    expect($connection->inTransaction())->toBeFalse()
        ->and((new User())->list()->count())->toBe(0);
});

it('commits a transaction spanning model calls', function () {
    $connection = ConnectionRegistry::get();

    $connection->begin();
    (new User())->create(['name' => 'Kept', 'age' => 1]);
    $connection->commit();

    expect((new User())->list()->count())->toBe(1);
});

it('runs aggregates through the driver', function () {
    $user = new User();
    $user->create(['name' => 'Ada', 'active' => 1]);
    $user->create(['name' => 'Grace', 'active' => 1]);
    $user->create(['name' => 'Linus', 'active' => 0]);

    $query = QueryTreeBuilder::query(
        aggregates: [['alias' => 'total', 'function' => 'count', 'column' => '*']],
        filter: ['active' => 1],
    );

    $raw = ConnectionRegistry::resolve()->execute(new Statement(StatementKind::Select, 'users', $query));

    expect($raw->rows())->toHaveCount(1)
        ->and($raw->rows()[0]['total'])->toBe(2);
});

it('rejects a write without a filter', function () {
    (new User())->delete([]);
})->throws(STDW\Pdo\Exception\StatementException::class);

it('treats a zero last insert id as no generated key', function () {
    ConnectionRegistry::get()->pdo()->exec(
        'CREATE TABLE codes (code TEXT PRIMARY KEY, label TEXT) WITHOUT ROWID'
    );

    $raw = ConnectionRegistry::resolve()->execute(new Statement(
        StatementKind::Insert,
        'codes',
        data: ['code' => Param::str('ABC'), 'label' => Param::str('Beta')],
    ));

    expect($raw->insertedId())->toBeNull();
});
