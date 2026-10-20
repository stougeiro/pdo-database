<?php declare(strict_types=1);

use STDW\Contract\Pdo\DriverInterface;
use STDW\Contract\Pdo\Result\RawInterface;
use STDW\Contract\Pdo\Statement\StatementInterface;
use STDW\Contract\Pdo\Statement\Type\StatementKind;
use STDW\Pdo\Exception\ConnectionException;
use STDW\Pdo\Model\ModelAbstracted;
use STDW\Pdo\Result\Raw;
use Tests\Fixtures\UserTestable;

/**
 * A recording driver: it captures the last Statement it received and returns a
 * canned Raw, so the model wiring (kind, table, params, key handling) is
 * asserted without touching a real connection.
 */
function recordingDriver(?RawInterface $returns = null): DriverInterface
{
    return new class ($returns ?? new Raw()) implements DriverInterface {
        public ?StatementInterface $last = null;

        public function __construct(private readonly RawInterface $returns)
        {
        }

        public function translate(StatementInterface $statement): RawInterface
        {
            $this->last = $statement;

            return $this->returns;
        }
    };
}

it('read returns a single row and its statement carries a limit of one', function () {
    $driver = recordingDriver(new Raw([['id' => 1, 'name' => 'Ada']], 1));
    $model = new UserTestable();
    $model->fake = $driver;

    $result = $model->read(['name' => 'Ada']);

    expect($result->count())->toBe(1)
        ->and($result->data())->toBe(['id' => 1, 'name' => 'Ada'])
        ->and($driver->last?->kind())->toBe(StatementKind::Select)
        ->and($driver->last?->query()?->pagination())->not->toBeNull();
});

it('read returns null data and count zero when nothing matches', function () {
    $model = new UserTestable();
    $model->fake = recordingDriver(new Raw([], 0));

    $result = $model->read(['name' => 'nobody']);

    expect($result->data())->toBeNull()
        ->and($result->count())->toBe(0);
});

it('list returns every row and counts them', function () {
    $driver = recordingDriver(new Raw([['id' => 1], ['id' => 2]], 2));
    $model = new UserTestable();
    $model->fake = $driver;

    $result = $model->list();

    expect($result->data())->toBe([['id' => 1], ['id' => 2]])
        ->and($result->count())->toBe(2)
        ->and($driver->last?->kind())->toBe(StatementKind::Select);
});

it('update reports the affected count and carries typed params', function () {
    $driver = recordingDriver(new Raw([], 3));
    $model = new UserTestable();
    $model->fake = $driver;

    $result = $model->update(['active' => 0], ['name' => 'Ada']);
    $statement = $driver->last;

    expect($result->count())->toBe(3)
        ->and($result->data())->toBeNull()
        ->and($statement?->kind())->toBe(StatementKind::Update)
        ->and($statement?->data()['active']->value())->toBe(0);
});

it('delete reports the affected count', function () {
    $driver = recordingDriver(new Raw([], 2));
    $model = new UserTestable();
    $model->fake = $driver;

    $result = $model->delete(['active' => 0]);

    expect($result->count())->toBe(2)
        ->and($result->data())->toBeNull()
        ->and($driver->last?->kind())->toBe(StatementKind::Delete);
});

it('create requests the whole record and names the key column', function () {
    $driver = recordingDriver(new Raw([], 1, 9));
    $model = new UserTestable();
    $model->fake = $driver;

    $model->create(['name' => 'Ada']);

    expect($driver->last?->returning())->toBe(['*'])
        ->and($driver->last?->key())->toBe('id');
});

it('wraps raw values into params so no raw value reaches the statement', function () {
    $driver = recordingDriver(new Raw([], 1, 1));
    $model = new UserTestable();
    $model->fake = $driver;

    $model->create(['name' => 'Ada', 'age' => 36]);

    expect($driver->last?->data()['name']->value())->toBe('Ada')
        ->and($driver->last?->data()['age']->value())->toBe(36);
});

it('rejects an empty table declaration', function () {
    $model = new class extends ModelAbstracted {
        protected string $table = '';
    };

    $model->table();
})->throws(ConnectionException::class);

it('reuses the same driver instance across calls', function () {
    $driver = recordingDriver(new Raw());
    $model = new UserTestable();
    $model->fake = $driver;

    $first = (function () {
        return $this->driver();
    })->call($model);

    $second = (function () {
        return $this->driver();
    })->call($model);

    expect($first)->toBe($second);
});
