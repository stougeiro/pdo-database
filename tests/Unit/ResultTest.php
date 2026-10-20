<?php declare(strict_types=1);

use STDW\Pdo\Result\Raw;
use STDW\Pdo\Result\Result;

it('is always valid and carries its count', function () {
    $result = Result::ok(['id' => 1], 1);

    expect($result->isValid())->toBeTrue()
        ->and($result->count())->toBe(1)
        ->and($result->data())->toBe(['id' => 1]);
});

it('accepts null data for writes', function () {
    $result = Result::ok(null, 4);

    expect($result->data())->toBeNull()
        ->and($result->count())->toBe(4);
});

it('accepts an object payload', function () {
    $payload = new stdClass();
    $payload->id = 1;

    expect(Result::ok($payload, 1)->data())->toBe($payload);
});

it('defaults Raw to empty rows, zero affected and no key', function () {
    $raw = new Raw();

    expect($raw->rows())->toBe([])
        ->and($raw->affected())->toBe(0)
        ->and($raw->insertedId())->toBeNull();
});
