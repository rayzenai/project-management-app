<?php

use App\Support\Git\TaskReferences;

it('finds keys in a branch name in any case', function () {
    expect(TaskReferences::inBranch('tp-12-fix-login'))->toBe(['TP-12'])
        ->and(TaskReferences::inBranch('feature/TP-3_and_tp-4'))->toBe(['TP-3', 'TP-4']);
});

it('returns each reference once, in order', function () {
    expect(TaskReferences::inBranch('tp-4-TP-3-tp-4'))->toBe(['TP-4', 'TP-3'])
        ->and(TaskReferences::inText('TP-2 then TP-1 then TP-2'))->toBe(['TP-2', 'TP-1']);
});

it('finds keys in text in upper case only', function () {
    expect(TaskReferences::inText('Fix TP-12 and TP-13'))->toBe(['TP-12', 'TP-13'])
        ->and(TaskReferences::inText('fix tp-12'))->toBe([]);
});

it('skips inline code, fenced code and URLs', function () {
    expect(TaskReferences::inText("see `TP-9` and ```\nTP-8\n```"))->toBe([])
        ->and(TaskReferences::inText('https://x.test/TP-7 then TP-6'))->toBe(['TP-6'])
        ->and(TaskReferences::inText("```\nTP-8"))->toBe(['TP-8']);
});

it('never picks a key out of a longer word', function () {
    // ABTP is a key of its own (left out later unless it resolves), never TP.
    expect(TaskReferences::inText('ABTP-5'))->toBe(['ABTP-5'])
        ->and(TaskReferences::inText('abTP-5 x2TP-5'))->toBe([])
        ->and(TaskReferences::inBranch('x2tp-5'))->toBe([]);
});

it('ignores a number that is zero or starts with a zero', function () {
    expect(TaskReferences::inText('TP-0 TP-05'))->toBe([]);
});
