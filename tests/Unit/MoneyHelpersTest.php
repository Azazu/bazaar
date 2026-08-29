<?php

it('formats minor units as money', function () {
    expect(money(39902))->toBe('$399.02')
        ->and(money(5))->toBe('$0.05');
});

it('parses human-entered amounts into exact minor units', function () {
    expect(cents('12.50'))->toBe(1250)
        ->and(cents('0.1'))->toBe(10)
        ->and(cents('19.999'))->toBe(2000) // rounded half-up to whole cents
        ->and(cents(7))->toBe(700)
        ->and(cents(' 3 '))->toBe(300)
        ->and(cents(''))->toBeNull()
        ->and(cents(null))->toBeNull()
        ->and(cents('abc'))->toBeNull();
});

it('round-trips between cents() and money()', function () {
    expect(money(cents('399.02')))->toBe('$399.02');
});
