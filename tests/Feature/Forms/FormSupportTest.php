<?php

use App\Support\BikramSambat;
use App\Support\Forms\BsCalendar;
use App\Support\Forms\FormAnswers;
use App\Support\Forms\FormRichText;
use App\Support\Forms\FormSchema;
use App\Support\Forms\InvalidFormSchema;
use App\Support\Forms\NepalPlaces;
use Carbon\CarbonImmutable;

/*
 * The parts every form rests on: dates, places, rich text, the questions'
 * schema and the answers' checks
 * (docs/superpowers/specs/2026-11-07-forms-design.md).
 */

/** A schema with the given blocks in one step (and the languages given). */
function formSchemaOf(array $blocks, array $languages = ['en']): array
{
    return FormSchema::clean(['languages' => $languages, 'steps' => [['id' => 's_aaaaaa1', 'title' => ['en' => 'One'], 'blocks' => $blocks]]]);
}

function formQuestion(string $id, string $type, array $extra = []): array
{
    return ['id' => $id, 'type' => $type, 'label' => ['en' => ucfirst($type), 'ne' => 'प्रश्न'], ...$extra];
}

it('converts BS and AD both ways', function () {
    expect(BsCalendar::toAd('2083-07-21'))->toBe('2026-11-07')
        ->and(BsCalendar::fromAd('2026-11-07'))->toBe('2083-07-21')
        ->and(BsCalendar::toAd('2083-01-01'))->toBe('2026-04-14')
        ->and(BsCalendar::fromAd('1943-04-14'))->toBe('2000-01-01')
        ->and(BsCalendar::toAd('2083-07-31'))->toBeNull()
        ->and(BsCalendar::toAd('1999-01-01'))->toBeNull()
        ->and(BsCalendar::fromAd('2026-02-30'))->toBeNull()
        ->and(BsCalendar::toAd('garbage'))->toBeNull();
});

it('agrees with the app\'s other BS table on every day it has', function () {
    $day = CarbonImmutable::parse('2013-04-14');
    $last = CarbonImmutable::parse('2044-04-12');

    while ($day->lte($last)) {
        $theirs = BikramSambat::fromGregorian($day);
        $mine = BsCalendar::fromAd($day->toDateString());

        expect($mine)->toBe($theirs === null ? null : sprintf('%04d-%02d-%02d', $theirs['year'], $theirs['month'], $theirs['day']));
        $day = $day->addDays(17);
    }
});

it('keeps a place only when every level sits inside the one above', function () {
    expect(NepalPlaces::clean(['province' => 'P1', 'district' => 'P1.D01', 'local' => 'P1.D01.L01', 'ward' => 5], 'ward'))
        ->toBe(['province' => 'P1', 'district' => 'P1.D01', 'local' => 'P1.D01.L01', 'ward' => 5])
        ->and(NepalPlaces::clean(['province' => 'P1', 'district' => 'P1.D01', 'local' => 'P1.D01.L01', 'ward' => 99], 'ward'))->toBeNull()
        ->and(NepalPlaces::clean(['province' => 'P2', 'district' => 'P1.D01'], 'district'))->toBeNull()
        ->and(NepalPlaces::clean(['province' => 'P1', 'district' => 'P1.D01', 'local' => 'P1.D01.L01'], 'district'))->toBe(['province' => 'P1', 'district' => 'P1.D01'])
        ->and(NepalPlaces::label(['province' => 'P1', 'district' => 'P1.D01', 'local' => 'P1.D01.L01', 'ward' => 5]))->toBe('Ward 5, Bhojpur Municipality, Bhojpur, Koshi');
});

it('keeps only the rich text the editor makes, and only safe links', function () {
    $doc = FormRichText::clean(['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Read ', 'marks' => [['type' => 'bold'], ['type' => 'script']]],
            ['type' => 'text', 'text' => 'this', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]],
            ['type' => 'text', 'text' => ' and that', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://sdoc.gov.np']]]],
        ]],
        ['type' => 'image', 'attrs' => ['src' => 'https://evil.example/x.png']],
    ]]);

    expect($doc)->toEqual(['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Read ', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => 'this'],
            ['type' => 'text', 'text' => ' and that', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://sdoc.gov.np']]]],
        ]],
    ]])
        ->and(FormRichText::toText($doc))->toBe('Read this and that')
        ->and(FormRichText::clean(['type' => 'doc', 'content' => [['type' => 'paragraph']]]))->toBeNull();
});

it('refuses a draft it can\'t keep, and drops conditions that point nowhere', function () {
    expect(fn () => FormSchema::clean(['steps' => [['id' => 'bad id', 'blocks' => []]]]))->toThrow(InvalidFormSchema::class)
        ->and(fn () => formSchemaOf([formQuestion('q_aaaaaa1', 'short_text'), formQuestion('q_aaaaaa1', 'number')]))->toThrow(InvalidFormSchema::class)
        ->and(fn () => formSchemaOf([formQuestion('q_aaaaaa1', 'script')]))->toThrow(InvalidFormSchema::class);

    $schema = formSchemaOf([
        formQuestion('q_later01', 'short_text', ['show_if' => ['match' => 'all', 'conditions' => [['question' => 'q_after01', 'op' => 'answered'], ['question' => 'q_gone001', 'op' => 'answered']]]]),
        formQuestion('q_after01', 'yes_no', ['show_if' => ['conditions' => [['question' => 'q_later01', 'op' => 'equals', 'value' => 'Yes']]]]),
    ]);

    expect($schema['steps'][0]['blocks'][0]['show_if'])->toBeNull()
        ->and($schema['steps'][0]['blocks'][1]['show_if'])->toEqual(['match' => 'all', 'conditions' => [['question' => 'q_later01', 'op' => 'equals', 'value' => 'Yes']]]);
});

it('lists what stops a draft from being published', function () {
    $schema = formSchemaOf([
        ['id' => 'q_choice01', 'type' => 'choice', 'label' => ['en' => 'Office'], 'options' => [['id' => 'o_only0001', 'label' => ['en' => 'One']]]],
        ['id' => 'q_number01', 'type' => 'number', 'label' => ['en' => 'Seats', 'ne' => ''], 'rules' => ['min' => 10, 'max' => 2]],
    ], ['en', 'ne']);

    $messages = array_column(FormSchema::problems($schema), 'message');

    expect($messages)->toContain('Give "Office" at least two answers to choose from.')
        ->toContain('A question has no Nepali words.')
        ->toContain('The limits of "Seats" can\'t all be met: the lowest is above the highest.');

    $changed = formSchemaOf([formQuestion('q_choice01', 'number')]);
    expect(array_column(FormSchema::problems($changed, [$schema]), 'message'))
        ->toContain('"Number" was another kind of question when it was last published. Add a new question instead of changing its kind.');
});

it('shows a question only when its condition holds, and drops answers to hidden ones', function () {
    $schema = formSchemaOf([
        formQuestion('q_worked01', 'yes_no', ['required' => true]),
        formQuestion('q_office01', 'short_text', ['required' => true, 'show_if' => ['conditions' => [['question' => 'q_worked01', 'op' => 'equals', 'value' => true]]]]),
    ]);

    $hidden = FormAnswers::check($schema, ['q_worked01' => false, 'q_office01' => 'Left over']);
    expect($hidden['answers'])->toEqual(['q_worked01' => false])->and($hidden['errors'])->toBe([]);

    $shown = FormAnswers::check($schema, ['q_worked01' => true]);
    expect($shown['errors'])->toBe(['q_office01' => 'Answer this question.']);
});

it('checks each kind of answer and keeps it in its stored shape', function () {
    $schema = formSchemaOf([
        formQuestion('q_nepali01', 'short_text', ['rules' => ['format' => 'devanagari']]),
        formQuestion('q_mobile01', 'phone'),
        formQuestion('q_count001', 'number', ['rules' => ['integer' => true, 'max' => 10]]),
        formQuestion('q_born0001', 'date', ['rules' => ['calendar' => 'bs', 'age_min' => 18, 'age_max' => 35, 'age_on' => '2026-11-07']]),
        ['id' => 'q_pick0001', 'type' => 'choices', 'label' => ['en' => 'Pick'], 'options' => [['id' => 'o_a000001', 'label' => ['en' => 'A']], ['id' => 'o_b000001', 'label' => ['en' => 'B']]], 'rules' => ['max' => 1]],
        formQuestion('q_place001', 'place', ['rules' => ['depth' => 'district']]),
        formQuestion('q_agree001', 'declaration', ['text' => ['en' => 'True']]),
    ]);

    $bad = FormAnswers::check($schema, [
        'q_nepali01' => 'Ram Bahadur',
        'q_mobile01' => '01-4211000',
        'q_count001' => '2.5',
        'q_born0001' => ['bs' => '2075-01-01'],
        'q_pick0001' => ['o_a000001', 'o_b000001'],
        'q_place001' => ['province' => 'P1'],
    ])['errors'];

    expect($bad)->toBe([
        'q_nepali01' => 'Type this in Nepali (Unicode), not in Preeti or English letters.',
        'q_mobile01' => 'Give a Nepali mobile number of 10 digits, like 9841234567.',
        'q_count001' => 'Give a whole number.',
        'q_born0001' => 'The age on 2083-07-21 BS must be between 18 and 35 years.',
        'q_pick0001' => 'Choose no more than 1.',
        'q_place001' => 'Choose the place down to the district.',
        'q_agree001' => 'Tick this to go on.',
    ]);

    $good = FormAnswers::check($schema, [
        'q_nepali01' => '  राम बहादुर  ',
        'q_mobile01' => '+977 984-1234567',
        'q_count001' => '७',
        'q_born0001' => ['bs' => '2050-05-15'],
        'q_pick0001' => ['o_b000001'],
        'q_place001' => ['province' => 'P1', 'district' => 'P1.D01', 'local' => 'P1.D01.L01'],
        'q_agree001' => true,
        'q_unknown1' => 'dropped',
    ]);

    expect($good['errors'])->toBe([])
        ->and($good['answers'])->toEqual([
            'q_nepali01' => 'राम बहादुर',
            'q_mobile01' => '9841234567',
            'q_count001' => 7,
            'q_born0001' => ['ad' => BsCalendar::toAd('2050-05-15'), 'bs' => '2050-05-15'],
            'q_pick0001' => ['o_b000001'],
            'q_place001' => ['province' => 'P1', 'district' => 'P1.D01'],
            'q_agree001' => true,
        ]);
});

it('checks a repeating group entry by entry, and files by who uploaded them', function () {
    $schema = formSchemaOf([formQuestion('q_degree01', 'group', ['required' => true, 'rules' => ['max' => 2], 'blocks' => [
        formQuestion('q_title001', 'short_text', ['required' => true]),
        formQuestion('q_proof001', 'file'),
    ]])]);

    $own = fn (array $ids, string $question): array => $question === 'q_proof001' ? array_values(array_intersect($ids, [7])) : [];

    expect(FormAnswers::check($schema, ['q_degree01' => []])['errors'])->toBe(['q_degree01' => 'Add at least one.'])
        ->and(FormAnswers::check($schema, ['q_degree01' => [['q_title001' => 'BE'], ['q_proof001' => [7]]]], $own)['errors'])->toBe(['q_degree01.1.q_title001' => 'Answer this question.'])
        ->and(FormAnswers::check($schema, ['q_degree01' => [['q_title001' => 'BE', 'q_proof001' => [8]]]], $own)['errors'])->toBe(['q_degree01.0.q_proof001' => 'A file is missing. Add it again.'])
        ->and(FormAnswers::check($schema, ['q_degree01' => [['q_title001' => 'BE', 'q_proof001' => [7]], []]], $own))->toEqual(['answers' => ['q_degree01' => [['q_title001' => 'BE', 'q_proof001' => [7]]]], 'errors' => []]);
});

it('keeps whatever a draft holds, unchecked, for the form\'s questions only', function () {
    $schema = formSchemaOf([formQuestion('q_email001', 'email'), formQuestion('q_count001', 'number')]);

    expect(FormAnswers::draft($schema, ['q_email001' => 'half@', 'q_count001' => '1x', 'q_other001' => 'no']))
        ->toEqual(['q_email001' => 'half@', 'q_count001' => '1x']);
});

it('embeds YouTube and Vimeo videos only', function () {
    expect(FormSchema::videoEmbed('https://www.youtube.com/watch?v=dQw4w9WgXcQ'))->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->and(FormSchema::videoEmbed('https://youtu.be/dQw4w9WgXcQ'))->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->and(FormSchema::videoEmbed('https://vimeo.com/123456789'))->toBe('https://player.vimeo.com/video/123456789')
        ->and(FormSchema::videoEmbed('https://evil.example/watch?v=dQw4w9WgXcQ'))->toBeNull();
});
