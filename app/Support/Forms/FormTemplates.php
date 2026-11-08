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
            ['id' => 'internship', 'name' => 'Internship or volunteer application', 'description' => 'Applications with education, availability and a CV, taken from New to Selected.'],
            ['id' => 'request', 'name' => 'Request', 'description' => 'Requests that can each become a task for someone.'],
            ['id' => 'complaint', 'name' => 'Complaint', 'description' => 'Complaints, each with a reference number, taken from Received to Resolved.'],
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
        $when = fn (string $question, mixed $value): array => ['match' => 'all', 'conditions' => [['question' => $question, 'op' => 'equals', 'value' => $value]]];
        // Questions and answers that a later question's condition names.
        [$applyingAs, $intern, $mayContact] = [FormSchema::newId('q'), FormSchema::newId('o'), FormSchema::newId('q')];

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
            'internship' => [[
                $step('About you', [
                    $question('short_text', 'Full name', ['required' => true]),
                    $question('email', 'Email', ['required' => true]),
                    $question('phone', 'Mobile number', ['required' => true]),
                    $question('date', 'Date of birth', ['required' => true, 'rules' => ['calendar' => 'bs', 'age_min' => 16]]),
                    $question('place', 'Where do you live?', ['required' => true, 'rules' => ['depth' => 'local']]),
                    $question('choice', 'You are applying as', ['id' => $applyingAs, 'required' => true, 'options' => [
                        ['id' => $intern, 'label' => ['en' => 'An intern', 'ne' => '']],
                        ...$options('A volunteer'),
                    ]]),
                ]),
                $step('Education', [
                    $question('choice', 'Highest level of education completed', ['required' => true, 'options' => $options('SEE or equivalent', '+2 or equivalent', 'Bachelor\'s', 'Master\'s or higher')]),
                    $question('short_text', 'Subject or field of study'),
                    $question('short_text', 'School, college or university', ['required' => true]),
                    $question('yes_no', 'Are you studying now?', ['required' => true]),
                ]),
                $step('Availability', [
                    $question('choices', 'Which areas interest you?', ['required' => true, 'options' => $options('Office administration', 'IT and digital services', 'Research and data', 'Communication and media', 'Field work')]),
                    $question('date', 'When can you start?', ['required' => true, 'rules' => ['calendar' => 'bs']]),
                    $question('choice', 'How long can you stay?', ['required' => true, 'options' => $options('Up to 3 months', '3 to 6 months', 'More than 6 months')]),
                    $question('choice', 'How much time can you give?', ['required' => true, 'options' => $options('Full time', 'Part time')]),
                    $question('paragraph', 'Why do you want to join, and what can you bring?', ['required' => true, 'rules' => ['max_length' => 2000]]),
                    $question('file', 'Your CV', ['required' => true, 'rules' => ['kinds' => ['pdf', 'word'], 'max_files' => 1]]),
                    $question('file', 'A letter from your college, if you have one', ['show_if' => $when($applyingAs, $intern), 'rules' => ['kinds' => ['pdf', 'image'], 'max_files' => 1]]),
                    $question('declaration', 'Declaration', ['text' => ['en' => 'What I have given here is true. I understand that anything false ends my application.', 'ne' => '']]),
                ]),
            ], Form::ANSWERING_PERSON, Form::RESPONDENTS_EVERYONE],
            'complaint' => [[
                $step('Your complaint', [
                    $question('choice', 'What is it about?', ['required' => true, 'options' => $options('A service that is late', 'How someone was treated', 'Misuse of money or position', 'Office facilities', 'Something else')]),
                    $question('short_text', 'The complaint in a few words', ['required' => true, 'rules' => ['max_length' => 200]]),
                    $question('paragraph', 'What happened?', ['required' => true, 'help' => ['en' => 'Say what happened, when, and who was involved, if you know.', 'ne' => '']]),
                    $question('date', 'When did it happen?', ['rules' => ['calendar' => 'bs']]),
                    $question('place', 'Where did it happen?', ['rules' => ['depth' => 'ward']]),
                    $question('file', 'Photos or papers that show it', ['rules' => ['kinds' => ['pdf', 'image'], 'max_files' => 5]]),
                    $question('paragraph', 'What would you like the office to do?'),
                ]),
                $step('Follow-up', [
                    $question('yes_no', 'May the office contact you about this complaint?', ['id' => $mayContact, 'required' => true]),
                    $question('phone', 'Mobile number', ['required' => true, 'show_if' => $when($mayContact, true)]),
                ]),
            ], Form::ANSWERING_PERSON, Form::RESPONDENTS_EVERYONE],
            'request' => [[$step('Request', [
                $question('short_text', 'What do you need?', ['required' => true]),
                $question('paragraph', 'Tell us more'),
                $question('choice', 'How soon?', ['required' => true, 'options' => $options('This week', 'This month', 'No hurry')]),
                $question('file', 'Attach anything that helps', ['rules' => ['kinds' => ['pdf', 'image', 'word', 'excel'], 'max_files' => 3]]),
            ])], Form::ANSWERING_PERSON, Form::RESPONDENTS_EVERYONE],
            default => [FormSchema::blank()['steps'], Form::ANSWERING_PERSON, Form::RESPONDENTS_EVERYONE],
        };

        // The default stages' ids, renamed where a template has its own words.
        $stages = match ($template) {
            'internship' => [['id' => 'new', 'name' => 'New'], ['id' => 'review', 'name' => 'In review'], ['id' => 'selected', 'name' => 'Selected'], ['id' => 'not_selected', 'name' => 'Not selected']],
            'complaint' => [['id' => 'new', 'name' => 'Received'], ['id' => 'review', 'name' => 'Looking into it'], ['id' => 'done', 'name' => 'Resolved']],
            default => Form::DEFAULT_STAGES,
        };

        return [
            'draft' => FormSchema::clean(['languages' => ['en'], 'steps' => $steps]),
            'answering' => $answering,
            'respondents' => $respondents,
            // Publishing tells the respondents by default only for a data call:
            // a form for a whole office would tell everyone in it.
            'settings' => [...Form::defaultSettings(), 'notify' => $answering === Form::ANSWERING_ORGANIZATION, 'stages' => $stages],
        ];
    }
}
