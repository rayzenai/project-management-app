<?php

namespace App\Support\Forms;

use App\Models\Form;

/**
 * The forms a new one can start from. Each is an ordinary form made from a
 * copy, with fresh ids; nothing links it back to its template.
 */
final class FormTemplates
{
    /** @return list<array{id: string, name: string, description: string}> */
    public static function all(): array
    {
        return [
            ['id' => 'blank', 'name' => 'Blank', 'description' => 'One empty step.'],
            ['id' => 'data_call', 'name' => 'Data call', 'description' => 'One response from each office, such as a progress report from every ministry.'],
            ['id' => 'survey', 'name' => 'Staff survey', 'description' => 'Ratings and comments from everyone in the office.'],
            ['id' => 'registration', 'name' => 'Event or training registration', 'description' => 'Who is coming, with a limit on seats.'],
            ['id' => 'request', 'name' => 'Request', 'description' => 'Requests that can each become a task for someone.'],
        ];
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_column(self::all(), 'id');
    }

    /**
     * A new form's questions and the settings that go with them.
     *
     * @return array{draft: array<string, mixed>, answering: string, respondents: string, settings: array<string, mixed>}
     */
    public static function make(string $template): array
    {
        $question = fn (string $type, string $label, array $extra = []): array => [
            'id' => FormSchema::newId('q'), 'type' => $type, 'show_if' => null, 'label' => ['en' => $label, 'ne' => ''], 'help' => ['en' => '', 'ne' => ''], 'required' => false, 'rules' => [], ...$extra,
        ];
        $options = fn (string ...$labels): array => array_map(fn (string $label): array => ['id' => FormSchema::newId('o'), 'label' => ['en' => $label, 'ne' => '']], $labels);
        $step = fn (string $title, array $blocks): array => ['id' => FormSchema::newId('s'), 'title' => ['en' => $title, 'ne' => ''], 'show_if' => null, 'blocks' => $blocks];
        $heading = fn (string $text): array => ['id' => FormSchema::newId('q'), 'type' => 'heading', 'show_if' => null, 'text' => ['en' => $text, 'ne' => '']];

        [$steps, $answering, $respondents] = match ($template) {
            'data_call' => [[$step('Report', [
                $heading('Your office\'s report'),
                $question('paragraph', 'What has your office done this period?', ['required' => true]),
                $question('number', 'How many of the planned items are done?', ['rules' => ['integer' => true, 'min' => 0]]),
                $question('file', 'Attach the report', ['rules' => ['kinds' => ['pdf', 'word', 'excel'], 'max_files' => 3]]),
            ])], Form::ANSWERING_ORGANIZATION, Form::RESPONDENTS_EVERYONE],
            'survey' => [[$step('Survey', [
                $question('scale', 'How satisfied are you with your work this year?', ['required' => true, 'rules' => ['max' => 5, 'min_label' => ['en' => 'Not at all', 'ne' => ''], 'max_label' => ['en' => 'Very', 'ne' => '']]]),
                $question('paragraph', 'What would make your work easier?'),
            ])], Form::ANSWERING_PERSON, Form::RESPONDENTS_EVERYONE],
            'registration' => [[$step('Registration', [
                $question('choice', 'Which session will you attend?', ['required' => true, 'options' => $options('Morning', 'Afternoon')]),
                $question('yes_no', 'Do you need a place to stay?'),
                $question('paragraph', 'Anything the organizers should know?'),
            ])], Form::ANSWERING_PERSON, Form::RESPONDENTS_EVERYONE],
            'request' => [[$step('Request', [
                $question('short_text', 'What do you need?', ['required' => true]),
                $question('paragraph', 'Tell us more'),
                $question('choice', 'How soon?', ['required' => true, 'options' => $options('This week', 'This month', 'No hurry')]),
                $question('file', 'Attach anything that helps', ['rules' => ['kinds' => ['pdf', 'image', 'word', 'excel'], 'max_files' => 3]]),
            ])], Form::ANSWERING_PERSON, Form::RESPONDENTS_EVERYONE],
            default => [FormSchema::blank()['steps'], Form::ANSWERING_PERSON, Form::RESPONDENTS_EVERYONE],
        };

        return [
            'draft' => FormSchema::clean(['languages' => ['en'], 'steps' => $steps]),
            'answering' => $answering,
            'respondents' => $respondents,
            // Publishing tells the respondents by default only for a data call:
            // a form for a whole office would tell everyone in it.
            'settings' => [...Form::defaultSettings(), 'notify' => $answering === Form::ANSWERING_ORGANIZATION],
        ];
    }
}
