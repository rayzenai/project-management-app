<?php

use App\Support\FirstRefusals;

it('holds the refusals while every send so far was refused, and calls three the server\'s doing', function () {
    $first = new FirstRefusals;

    expect($first->hold('a'))->toBeTrue()
        ->and($first->hold('b'))->toBeTrue()
        ->and($first->serverRefuses())->toBeFalse()
        ->and($first->hold('c'))->toBeTrue()
        ->and($first->serverRefuses())->toBeTrue()
        ->and($first->take())->toBe(['a', 'b', 'c'])
        ->and($first->take())->toBe([]);
});

it('holds nothing after a send went out, and keeps what it held for the end', function () {
    $first = new FirstRefusals;

    expect($first->hold('a'))->toBeTrue();
    $first->passed();

    expect($first->hold('b'))->toBeFalse()
        ->and($first->hold('c'))->toBeFalse()
        ->and($first->serverRefuses())->toBeFalse()
        ->and($first->take())->toBe(['a']);
});

it('holds nothing past the first three sends', function () {
    $first = new FirstRefusals;
    $first->passed();
    $first->passed();
    $first->passed();

    expect($first->hold('a'))->toBeFalse()
        ->and($first->take())->toBe([]);
});
