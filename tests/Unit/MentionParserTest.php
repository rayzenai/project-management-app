<?php

use App\Support\MentionParser;

it('extracts member ids from canonical mention tokens', function () {
    $body = 'Looping in @[Asha Rai](member:7) and @[Bob](member:12) here.';
    expect(MentionParser::memberIds($body))->toBe([7, 12]);
});

it('returns an empty list when there are no mentions', function () {
    expect(MentionParser::memberIds('no mentions here'))->toBe([]);
});

it('dedupes repeated mentions', function () {
    expect(MentionParser::memberIds('@[A](member:5) @[A](member:5)'))->toBe([5]);
});

it('ignores malformed tokens', function () {
    expect(MentionParser::memberIds('@[NoId]() @[X](member:) @[Y](member:abc)'))->toBe([]);
});

it('renders mention tokens as readable @name text', function () {
    expect(MentionParser::toDisplayText('Hi @[Asha Rai](member:7) and @[Bob](member:12)!'))
        ->toBe('Hi @Asha Rai and @Bob!');
});

it('leaves text without mentions unchanged', function () {
    expect(MentionParser::toDisplayText('no mentions'))->toBe('no mentions');
});
