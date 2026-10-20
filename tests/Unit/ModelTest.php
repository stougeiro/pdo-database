<?php declare(strict_types=1);

use STDW\Contract\Pdo\DriverInterface;
use STDW\Contract\Pdo\Result\RawInterface;
use STDW\Contract\Pdo\Statement\StatementInterface;
use STDW\Pdo\Result\Raw;
use Tests\Fixtures\UserTestable;

function fakeDriver(null|int|string $insertedId): DriverInterface
{
    return new class ($insertedId) implements DriverInterface {
        public function __construct(
            private readonly null|int|string $insertedId,
        ) {
        }

        public function execute(StatementInterface $statement): RawInterface
        {
            return new Raw([], 1, $this->insertedId);
        }
    };
}

it('creates without the registry when a driver is injected', function () {
    $model = new UserTestable();
    $model->fake = fakeDriver(42);

    $result = $model->create(['name' => 'Ada']);

    expect($result->data())->toBe(['name' => 'Ada', 'id' => 42])
        ->and($result->count())->toBe(1);
});

it('returns the input untouched when no key was generated', function () {
    $model = new UserTestable();
    $model->fake = fakeDriver(null);

    $result = $model->create(['code' => 'ABC', 'label' => 'Beta']);

    expect($result->data())->toBe(['code' => 'ABC', 'label' => 'Beta']);
});
