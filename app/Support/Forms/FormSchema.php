<?php

namespace App\Support\Forms;

use Illuminate\Support\Str;

/**
 * A form's questions: languages, then steps, each with blocks (content or
 * questions). The builder sends the whole draft on every save and it is
 * kept only as clean() returns it; a published version is a copy that never
 * changes. Every step, block and option has an id that never changes and is
 * never used again, and an answer stores ids, never labels, so renaming an
 * option keeps every answer right. Answers are checked by FormAnswers.
 * The shape is in docs/superpowers/plans/2026-11-07-forms.md.
 */
final class FormSchema
{
    public const LANGUAGES = ['en', 'ne'];

    public const LANGUAGE_NAMES = ['en' => 'English', 'ne' => 'Nepali'];

    public const CONTENT_TYPES = ['heading', 'text', 'image', 'video', 'download', 'divider'];

    public const QUESTION_TYPES = ['short_text', 'paragraph', 'number', 'email', 'phone', 'url', 'date', 'choice', 'choices', 'yes_no', 'scale', 'file', 'place', 'group', 'declaration'];

    /** What a repeating group may hold: questions, not groups, declarations or content. */
    public const GROUP_TYPES = ['short_text', 'paragraph', 'number', 'email', 'phone', 'url', 'date', 'choice', 'choices', 'yes_no', 'scale', 'file', 'place'];

    public const OPS = ['equals', 'not_equals', 'includes', 'answered', 'not_answered'];

    public const FORMATS = ['any', 'devanagari', 'latin', 'digits', 'pattern'];

    public const FILE_KINDS = ['pdf', 'image', 'word', 'excel'];

    /** The file extensions each kind of upload takes. */
    public const FILE_EXTENSIONS = [
        'pdf' => ['pdf'],
        'image' => ['jpg', 'jpeg', 'png', 'webp'],
        'word' => ['doc', 'docx', 'odt'],
        'excel' => ['xls', 'xlsx', 'ods', 'csv'],
    ];

    public const MAX_STEPS = 20;

    public const MAX_BLOCKS = 300;

    public const MAX_OPTIONS = 1000;

    public const MAX_CONDITIONS = 10;

    public const MAX_GROUP_ENTRIES = 50;

    public const MAX_FILES = 10;

    public const LABEL_MAX = 500;

    public const HELP_MAX = 1000;

    public const ID_PATTERN = '/^[a-z]_[a-z0-9]{6,16}$/';

    /** @return array{languages: list<string>, steps: list<array<string, mixed>>} */
    public static function blank(): array
    {
        return ['languages' => ['en'], 'steps' => [['id' => self::newId('s'), 'title' => self::translations(null, 200), 'show_if' => null, 'blocks' => []]]];
    }

    public static function newId(string $prefix): string
    {
        return $prefix.'_'.Str::lower(Str::random(10));
    }

    public static function isQuestion(string $type): bool
    {
        return in_array($type, self::QUESTION_TYPES, true);
    }

    /**
     * The draft as the builder sends it, as it is kept: unknown keys dropped,
     * words cut to length, rules made to fit their question, and conditions
     * that point at a question that is gone (or not earlier) dropped.
     * Refuses (InvalidFormSchema) only what can't be kept as it is: an
     * unknown kind, a bad or repeated id, too many steps or blocks.
     *
     * @return array{languages: list<string>, steps: list<array<string, mixed>>}
     */
    public static function clean(mixed $input): array
    {
        if (! is_array($input) || ! is_array($input['steps'] ?? null)) {
            throw new InvalidFormSchema('The form came in a shape it can\'t be saved in.');
        }

        $languages = array_values(array_intersect(self::LANGUAGES, is_array($input['languages'] ?? null) ? $input['languages'] : []));
        $languages = $languages === [] ? ['en'] : $languages;

        if (count($input['steps']) > self::MAX_STEPS) {
            throw new InvalidFormSchema('A form can have at most '.self::MAX_STEPS.' steps.');
        }

        $ids = [];
        $blockCount = 0;
        $steps = [];

        foreach (array_values($input['steps']) as $step) {
            if (! is_array($step)) {
                throw new InvalidFormSchema('A step came in a shape it can\'t be saved in.');
            }

            $blocks = [];

            foreach (array_values(is_array($step['blocks'] ?? null) ? $step['blocks'] : []) as $block) {
                $blocks[] = self::cleanBlock($block, $ids, $blockCount, false);
            }

            $steps[] = [
                'id' => self::claimId($step['id'] ?? null, $ids),
                'title' => self::translations($step['title'] ?? null, 200),
                'show_if' => $step['show_if'] ?? null,
                'blocks' => $blocks,
            ];
        }

        if ($steps === []) {
            $steps = self::blank()['steps'];
        }

        // Conditions, now that every question's place is known: a step's
        // look at questions in the steps before it, a block's at questions
        // before it.
        $earlier = [];

        foreach ($steps as $s => $step) {
            $steps[$s]['show_if'] = self::cleanCondition($step['show_if'], $earlier);

            foreach ($step['blocks'] as $b => $block) {
                $steps[$s]['blocks'][$b]['show_if'] = self::cleanCondition($block['show_if'] ?? null, $earlier);

                if (self::isQuestion($block['type'])) {
                    $earlier[$block['id']] = $block;
                }
            }
        }

        return ['languages' => $languages, 'steps' => $steps];
    }

    /**
     * @param  array<string, true>  $ids
     * @return array<string, mixed>
     */
    private static function cleanBlock(mixed $block, array &$ids, int &$count, bool $inGroup): array
    {
        if (! is_array($block)) {
            throw new InvalidFormSchema('A block came in a shape it can\'t be saved in.');
        }

        if (++$count > self::MAX_BLOCKS) {
            throw new InvalidFormSchema('A form can have at most '.self::MAX_BLOCKS.' blocks.');
        }

        $type = is_string($block['type'] ?? null) ? $block['type'] : '';
        $allowed = $inGroup ? self::GROUP_TYPES : [...self::CONTENT_TYPES, ...self::QUESTION_TYPES];

        if (! in_array($type, $allowed, true)) {
            throw new InvalidFormSchema($inGroup ? 'A repeating group holds questions only.' : 'A block is of a kind the builder doesn\'t know.');
        }

        $id = self::claimId($block['id'] ?? null, $ids);
        $rules = is_array($block['rules'] ?? null) ? $block['rules'] : [];
        $clean = ['id' => $id, 'type' => $type];

        if (! $inGroup) {
            $clean['show_if'] = $block['show_if'] ?? null;
        }

        if (! self::isQuestion($type)) {
            return $clean + self::cleanContent($type, $block);
        }

        $clean['label'] = self::translations($block['label'] ?? null, self::LABEL_MAX);
        $clean['help'] = self::translations($block['help'] ?? null, self::HELP_MAX);
        $clean['required'] = $type === 'declaration' || (bool) ($block['required'] ?? false);

        if (in_array($type, ['choice', 'choices'], true)) {
            $clean['options'] = self::cleanOptions($block['options'] ?? null, $ids);
        }

        if ($type === 'group') {
            $children = [];

            foreach (array_values(is_array($block['blocks'] ?? null) ? $block['blocks'] : []) as $child) {
                $children[] = self::cleanBlock($child, $ids, $count, true);
            }

            $clean['blocks'] = $children;
        }

        if ($type === 'declaration') {
            $clean['text'] = self::translations($block['text'] ?? null, 1000);
        }

        $clean['rules'] = self::cleanRules($type, $rules);

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function cleanContent(string $type, array $block): array
    {
        return match ($type) {
            'heading' => ['text' => self::translations($block['text'] ?? null, 200)],
            'text' => ['doc' => [
                'en' => FormRichText::clean(is_array($block['doc'] ?? null) ? ($block['doc']['en'] ?? null) : null),
                'ne' => FormRichText::clean(is_array($block['doc'] ?? null) ? ($block['doc']['ne'] ?? null) : null),
            ]],
            'image' => ['file' => self::fileId($block['file'] ?? null), 'alt' => self::translations($block['alt'] ?? null, 200)],
            'video' => ['url' => self::videoEmbed(is_string($block['url'] ?? null) ? $block['url'] : '') === null ? '' : trim((string) $block['url'])],
            'download' => ['file' => self::fileId($block['file'] ?? null), 'label' => self::translations($block['label'] ?? null, 200)],
            default => [],
        };
    }

    private static function fileId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($id) && $id > 0 ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private static function cleanRules(string $type, array $rules): array
    {
        $int = fn (string $key, int $min, int $max): ?int => is_numeric($rules[$key] ?? null) ? max($min, min($max, (int) $rules[$key])) : null;
        $number = fn (string $key): int|float|null => is_numeric($rules[$key] ?? null) ? $rules[$key] + 0 : null;
        $date = fn (string $key): ?string => is_string($rules[$key] ?? null) && BsCalendar::fromAd($rules[$key]) !== null ? $rules[$key] : null;

        return array_filter(match ($type) {
            'short_text' => [
                'format' => in_array($rules['format'] ?? null, self::FORMATS, true) ? $rules['format'] : 'any',
                'pattern' => ($rules['format'] ?? null) === 'pattern' && is_string($rules['pattern'] ?? null) ? mb_substr($rules['pattern'], 0, 300) : null,
                'min_length' => $int('min_length', 0, 5000),
                'max_length' => $int('max_length', 1, 5000),
            ],
            'paragraph' => ['min_length' => $int('min_length', 0, 20000), 'max_length' => $int('max_length', 1, 20000)],
            'number' => ['min' => $number('min'), 'max' => $number('max'), 'integer' => (bool) ($rules['integer'] ?? false)],
            'date' => [
                'calendar' => ($rules['calendar'] ?? null) === 'ad' ? 'ad' : 'bs',
                'earliest' => $date('earliest'),
                'latest' => $date('latest'),
                'age_min' => $int('age_min', 0, 150),
                'age_max' => $int('age_max', 0, 150),
                'age_on' => $date('age_on'),
            ],
            'choice' => ['display' => ($rules['display'] ?? null) === 'dropdown' ? 'dropdown' : 'radio'],
            'choices' => ['min' => $int('min', 0, self::MAX_OPTIONS), 'max' => $int('max', 1, self::MAX_OPTIONS)],
            'scale' => [
                'max' => (int) ($rules['max'] ?? 5) === 10 ? 10 : 5,
                'min_label' => self::translations($rules['min_label'] ?? null, 60),
                'max_label' => self::translations($rules['max_label'] ?? null, 60),
            ],
            'file' => [
                'kinds' => array_values(array_intersect(self::FILE_KINDS, is_array($rules['kinds'] ?? null) ? $rules['kinds'] : [])) ?: ['pdf', 'image'],
                'max_mb' => $int('max_mb', 1, self::maxUploadMb()) ?? min(10, self::maxUploadMb()),
                'max_files' => $int('max_files', 1, self::MAX_FILES) ?? 1,
            ],
            'place' => ['depth' => in_array($rules['depth'] ?? null, NepalPlaces::DEPTHS, true) ? $rules['depth'] : 'local'],
            'group' => [
                'min' => $int('min', 0, self::MAX_GROUP_ENTRIES),
                'max' => $int('max', 1, self::MAX_GROUP_ENTRIES),
                'add_label' => self::translations($rules['add_label'] ?? null, 60),
            ],
            default => [],
        }, fn (mixed $value): bool => $value !== null && $value !== false);
    }

    public static function maxUploadMb(): int
    {
        return max(1, (int) config('forms.max_upload_mb', 20));
    }

    /**
     * @param  array<string, true>  $ids
     * @return list<array{id: string, label: array{en: string, ne: string}}>
     */
    private static function cleanOptions(mixed $options, array &$ids): array
    {
        $kept = [];

        foreach (array_slice(array_values(is_array($options) ? $options : []), 0, self::MAX_OPTIONS) as $option) {
            if (! is_array($option)) {
                continue;
            }

            $kept[] = ['id' => self::claimId($option['id'] ?? null, $ids), 'label' => self::translations($option['label'] ?? null, 300)];
        }

        return $kept;
    }

    /**
     * A condition as kept, or null: conditions on earlier questions only,
     * each with a test and value its question can have.
     *
     * @param  array<string, array<string, mixed>>  $earlier  questions before it, by id
     * @return array{match: string, conditions: list<array{question: string, op: string, value?: mixed}>}|null
     */
    private static function cleanCondition(mixed $condition, array $earlier): ?array
    {
        if (! is_array($condition) || ! is_array($condition['conditions'] ?? null)) {
            return null;
        }

        $kept = [];

        foreach (array_slice(array_values($condition['conditions']), 0, self::MAX_CONDITIONS) as $test) {
            if (! is_array($test) || ! is_string($test['question'] ?? null) || ! isset($earlier[$test['question']])) {
                continue;
            }

            $question = $earlier[$test['question']];
            $op = $test['op'] ?? null;
            $value = $test['value'] ?? null;

            if ($op === 'answered' || $op === 'not_answered') {
                $kept[] = ['question' => $test['question'], 'op' => $op];

                continue;
            }

            $clean = match ($question['type']) {
                'choice' => in_array($op, ['equals', 'not_equals'], true) && in_array($value, array_column($question['options'], 'id'), true) ? $value : null,
                'choices' => $op === 'includes' && in_array($value, array_column($question['options'], 'id'), true) ? $value : null,
                'yes_no' => in_array($op, ['equals', 'not_equals'], true) && is_bool($value) ? $value : null,
                'number', 'scale' => in_array($op, ['equals', 'not_equals'], true) && is_numeric($value) ? $value + 0 : null,
                'short_text', 'email', 'phone', 'url' => in_array($op, ['equals', 'not_equals'], true) && is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 300) : null,
                default => null,
            };

            if ($clean !== null) {
                $kept[] = ['question' => $test['question'], 'op' => (string) $op, 'value' => $clean];
            }
        }

        return $kept === [] ? null : ['match' => ($condition['match'] ?? null) === 'any' ? 'any' : 'all', 'conditions' => $kept];
    }

    /** @param  array<string, true>  $ids */
    private static function claimId(mixed $id, array &$ids): string
    {
        if (! is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidFormSchema('Something in the form has no usable id. Reload the builder and try again.');
        }

        if (isset($ids[$id])) {
            throw new InvalidFormSchema('Two things in the form share an id. Reload the builder and try again.');
        }

        $ids[$id] = true;

        return $id;
    }

    /** @return array{en: string, ne: string} */
    public static function translations(mixed $value, int $max): array
    {
        $value = is_array($value) ? $value : [];
        $text = fn (string $language): string => is_string($value[$language] ?? null) ? mb_substr(trim($value[$language]), 0, $max) : '';

        return ['en' => $text('en'), 'ne' => $text('ne')];
    }

    /**
     * The address a video block shows: YouTube's privacy-enhanced player or
     * Vimeo's, or null for any other link.
     */
    public static function videoEmbed(string $url): ?string
    {
        $url = trim($url);

        if (preg_match('~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m) === 1) {
            return 'https://www.youtube-nocookie.com/embed/'.$m[1];
        }

        if (preg_match('~^https?://(?:www\.|player\.)?vimeo\.com/(?:video/)?(\d{6,12})~', $url, $m) === 1) {
            return 'https://player.vimeo.com/video/'.$m[1];
        }

        return null;
    }

    /**
     * The ids of the files a schema's pictures and downloads show.
     *
     * @param  array<string, mixed>  $schema
     * @return list<int>
     */
    public static function contentFileIds(array $schema): array
    {
        $ids = [];

        foreach (self::steps($schema) as $step) {
            foreach (self::blocks($step) as $block) {
                if (in_array($block['type'] ?? null, ['image', 'download'], true) && is_int($block['file'] ?? null)) {
                    $ids[] = $block['file'];
                }
            }
        }

        return $ids;
    }

    /**
     * The questions outside repeating groups, in order, by id, each with the
     * index of its step.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, array<string, mixed>>
     */
    public static function questions(array $schema): array
    {
        $questions = [];

        foreach (self::steps($schema) as $s => $step) {
            foreach (self::blocks($step) as $block) {
                if (self::isQuestion((string) ($block['type'] ?? ''))) {
                    $questions[(string) $block['id']] = $block + ['step' => $s];
                }
            }
        }

        return $questions;
    }

    /**
     * Every question, those inside repeating groups too (with `group`, the
     * group's id), by id.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, array<string, mixed>>
     */
    public static function allQuestions(array $schema): array
    {
        $all = [];

        foreach (self::questions($schema) as $id => $question) {
            $all[$id] = $question;

            foreach (self::blocks($question) as $child) {
                $all[(string) $child['id']] = $child + ['group' => $id, 'step' => $question['step']];
            }
        }

        return $all;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<array<string, mixed>>
     */
    public static function steps(array $schema): array
    {
        return array_values(array_filter(is_array($schema['steps'] ?? null) ? $schema['steps'] : [], 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $parent  a step or a group
     * @return list<array<string, mixed>>
     */
    public static function blocks(array $parent): array
    {
        return array_values(array_filter(is_array($parent['blocks'] ?? null) ? $parent['blocks'] : [], 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public static function languages(array $schema): array
    {
        $languages = array_values(array_intersect(self::LANGUAGES, is_array($schema['languages'] ?? null) ? $schema['languages'] : []));

        return $languages === [] ? ['en'] : $languages;
    }

    /**
     * Words in the language asked for, or in the form's other language when
     * they are missing there.
     *
     * @param  array<string, mixed>|null  $translations
     */
    public static function text(?array $translations, string $language = 'en'): string
    {
        $translations ??= [];
        $wanted = trim((string) ($translations[$language] ?? ''));

        if ($wanted !== '') {
            return $wanted;
        }

        foreach (self::LANGUAGES as $other) {
            $text = trim((string) ($translations[$other] ?? ''));

            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * A question's name for messages and exports.
     *
     * @param  array<string, mixed>  $question
     */
    public static function label(array $question, string $language = 'en'): string
    {
        $label = self::text(is_array($question['label'] ?? null) ? $question['label'] : null, $language);

        return $label !== '' ? $label : 'Untitled question';
    }

    /** Whether a custom pattern can be used at all. */
    public static function patternWorks(string $pattern): bool
    {
        return $pattern !== '' && @preg_match(self::regex($pattern), '') !== false;
    }

    /** A custom pattern as a whole-answer, Unicode PCRE. */
    public static function regex(string $pattern): string
    {
        return "\x01^(?:".$pattern.")$\x01u";
    }

    /**
     * What stops the draft from being published, by step or block id, most
     * of it said in the builder's words. A question whose id an earlier
     * version used for another kind of question is refused: its answers
     * would stop making sense.
     *
     * @param  array<string, mixed>  $schema  a clean() draft
     * @param  list<array<string, mixed>>  $published  every version published before
     * @return list<array{message: string, block: string|null}>
     */
    public static function problems(array $schema, array $published = []): array
    {
        $problems = [];
        $languages = self::languages($schema);
        $missing = function (mixed $translations, string $what, string $block) use ($languages, &$problems): void {
            $translations = is_array($translations) ? $translations : [];

            foreach ($languages as $language) {
                if (trim((string) ($translations[$language] ?? '')) === '') {
                    $problems[] = ['message' => count($languages) > 1 ? "{$what} has no ".self::LANGUAGE_NAMES[$language].' words.' : "{$what} has no words.", 'block' => $block];

                    return;
                }
            }
        };
        $partly = function (mixed $translations, string $what, string $block) use ($languages, &$problems): void {
            $translations = is_array($translations) ? $translations : [];
            $filled = array_filter($languages, fn (string $language): bool => trim((string) ($translations[$language] ?? '')) !== '');

            if ($filled !== [] && count($filled) < count($languages)) {
                $problems[] = ['message' => "{$what} is missing its ".self::LANGUAGE_NAMES[array_values(array_diff($languages, $filled))[0]].' words.', 'block' => $block];
            }
        };

        $kinds = [];

        foreach ($published as $version) {
            foreach (self::allQuestions($version) as $id => $question) {
                $kinds[$id] = (string) $question['type'];
            }
        }

        if (self::questions($schema) === []) {
            $problems[] = ['message' => 'Add at least one question.', 'block' => null];
        }

        foreach (self::steps($schema) as $s => $step) {
            $number = $s + 1;
            $hasQuestion = false;

            if (count(self::steps($schema)) > 1) {
                $partly($step['title'] ?? null, "Step {$number}'s title", (string) $step['id']);
            }

            foreach (self::blocks($step) as $block) {
                $type = (string) $block['type'];
                $id = (string) $block['id'];

                if (! self::isQuestion($type)) {
                    match ($type) {
                        'heading' => $missing($block['text'] ?? null, 'A heading', $id),
                        'text' => $missing(is_array($block['doc'] ?? null) ? array_map(fn (mixed $doc): string => FormRichText::toText(is_array($doc) ? $doc : null), $block['doc']) : null, 'A text block', $id),
                        'image' => $block['file'] === null ? $problems[] = ['message' => 'An image block has no image.', 'block' => $id] : null,
                        'download' => $block['file'] === null ? $problems[] = ['message' => 'A file to download has no file.', 'block' => $id] : $missing($block['label'] ?? null, 'A file to download\'s name', $id),
                        'video' => $block['url'] === '' ? $problems[] = ['message' => 'A video block needs a YouTube or Vimeo link.', 'block' => $id] : null,
                        default => null,
                    };

                    continue;
                }

                $hasQuestion = true;

                foreach ([$block, ...self::blocks($block)] as $question) {
                    self::questionProblems($question, $missing, $partly, $kinds, $problems);
                }

                if ($type === 'group' && self::blocks($block) === []) {
                    $problems[] = ['message' => 'Add a question to the repeating group "'.self::label($block).'".', 'block' => $id];
                }
            }

            if (! $hasQuestion) {
                $problems[] = ['message' => "Step {$number} has no question.", 'block' => (string) $step['id']];
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, string>  $kinds
     * @param  list<array{message: string, block: string|null}>  $problems
     */
    private static function questionProblems(array $question, callable $missing, callable $partly, array $kinds, array &$problems): void
    {
        $id = (string) $question['id'];
        $type = (string) $question['type'];
        $name = '"'.self::label($question).'"';
        $rules = is_array($question['rules'] ?? null) ? $question['rules'] : [];

        $missing($question['label'] ?? null, 'A question', $id);
        $partly($question['help'] ?? null, "The help of {$name}", $id);

        if (isset($kinds[$id]) && $kinds[$id] !== $type) {
            $problems[] = ['message' => "{$name} was another kind of question when it was last published. Add a new question instead of changing its kind.", 'block' => $id];
        }

        if (in_array($type, ['choice', 'choices'], true)) {
            $options = is_array($question['options'] ?? null) ? $question['options'] : [];

            if (count($options) < 2) {
                $problems[] = ['message' => "Give {$name} at least two answers to choose from.", 'block' => $id];
            }

            foreach ($options as $option) {
                $missing($option['label'] ?? null, "An answer of {$name}", $id);
            }
        }

        if ($type === 'declaration') {
            $missing($question['text'] ?? null, "The declaration {$name}", $id);
        }

        if ($type === 'short_text' && ($rules['format'] ?? null) === 'pattern' && ! self::patternWorks((string) ($rules['pattern'] ?? ''))) {
            $problems[] = ['message' => "The pattern of {$name} doesn't work. Fix it or choose another check.", 'block' => $id];
        }

        foreach ([['min_length', 'max_length'], ['min', 'max'], ['age_min', 'age_max'], ['earliest', 'latest']] as [$low, $high]) {
            if (isset($rules[$low], $rules[$high]) && $rules[$low] > $rules[$high]) {
                $problems[] = ['message' => "The limits of {$name} can't all be met: the lowest is above the highest.", 'block' => $id];
            }
        }
    }
}
