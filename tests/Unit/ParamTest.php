<?php declare(strict_types=1);

use STDW\Contract\Pdo\Param\Type\ParamType;
use STDW\Pdo\Exception\QueryTreeException;
use STDW\Pdo\Param\Param;

it('builds the scalar constructors with their PDO type', function () {
    expect(Param::null()->type())->toBe(ParamType::Null)
        ->and(Param::null()->value())->toBeNull()
        ->and(Param::bool(true)->type())->toBe(ParamType::Bool)
        ->and(Param::bool(true)->value())->toBeTrue()
        ->and(Param::int(7)->type())->toBe(ParamType::Int)
        ->and(Param::int(7)->value())->toBe(7)
        ->and(Param::str('ada')->type())->toBe(ParamType::String)
        ->and(Param::str('ada')->value())->toBe('ada')
        ->and(Param::binary("\x00\x01")->type())->toBe(ParamType::Binary)
        ->and(Param::binary("\x00\x01")->value())->toBe("\x00\x01");
});

it('maps a bool through from()', function () {
    expect(Param::from(false)->type())->toBe(ParamType::Bool);
});

it('maps an int through from()', function () {
    expect(Param::from(3)->type())->toBe(ParamType::Int);
});

it('maps a string through from()', function () {
    expect(Param::from('x')->type())->toBe(ParamType::String);
});

it('binds a float as a string with the raw float value', function () {
    $param = Param::from(3.5);

    expect($param->type())->toBe(ParamType::String)
        ->and($param->value())->toBe(3.5);
});

it('encodes an array as a JSON string', function () {
    $param = Param::from(['a' => 1, 'b' => 'x']);

    expect($param->type())->toBe(ParamType::String)
        ->and($param->value())->toBe('{"a":1,"b":"x"}');
});

it('formats a DateTimeInterface as Y-m-d H:i:s', function () {
    $param = Param::from(new DateTimeImmutable('2024-01-02 03:04:05'));

    expect($param->type())->toBe(ParamType::String)
        ->and($param->value())->toBe('2024-01-02 03:04:05');
});

it('casts a Stringable to string', function () {
    $stringable = new class implements Stringable {
        public function __toString(): string
        {
            return 'stringable';
        }
    };

    expect(Param::from($stringable)->value())->toBe('stringable');
});

it('rejects a value it cannot type', function () {
    Param::from(new stdClass());
})->throws(QueryTreeException::class);

it('rejects an array it cannot encode as JSON', function () {
    Param::from(['ratio' => INF]);
})->throws(QueryTreeException::class);

it('renders null, bool and scalar as string', function () {
    expect((string) Param::null())->toBe('')
        ->and((string) Param::bool(true))->toBe('1')
        ->and((string) Param::bool(false))->toBe('0')
        ->and((string) Param::int(42))->toBe('42')
        ->and((string) Param::str('ada'))->toBe('ada');
});
