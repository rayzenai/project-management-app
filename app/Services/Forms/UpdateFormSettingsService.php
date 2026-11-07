<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Support\ServiceResult;
use Illuminate\Support\Str;

/**
 * A form's settings beside its questions: title, open and close dates, the
 * response limit, editing after Submit, the review page, whether publishing
 * tells people, and the stages. "Answering as" can't change once a response
 * is in (the request refuses it). Each key is changed only when given.
 */
class UpdateFormSettingsService
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function execute(Form $form, array $values): ServiceResult
    {
        if (array_key_exists('title', $values)) {
            $form->title = mb_substr(trim((string) $values['title']), 0, Form::TITLE_MAX) ?: $form->title;
        }

        foreach (['opens_at', 'closes_at', 'response_limit', 'answering'] as $key) {
            if (array_key_exists($key, $values)) {
                $form->{$key} = $values[$key];
            }
        }

        $settings = $form->settings;

        foreach (['allow_edit', 'review_page', 'notify'] as $key) {
            if (array_key_exists($key, $values)) {
                $settings[$key] = (bool) $values[$key];
            }
        }

        if (array_key_exists('stages', $values) && is_array($values['stages'])) {
            $settings['stages'] = $this->stages($values['stages'], $form->stages());
        }

        $form->settings = $settings;
        $form->save();

        return ServiceResult::success($form, 'Saved.');
    }

    /**
     * Stages keep their ids (responses point at them); a new one gets one.
     *
     * @param  array<mixed>  $given
     * @param  list<array{id: string, name: string}>  $current
     * @return list<array{id: string, name: string}>
     */
    private function stages(array $given, array $current): array
    {
        $known = array_column($current, 'id');
        $stages = [];

        foreach ($given as $stage) {
            $name = is_array($stage) ? mb_substr(trim((string) ($stage['name'] ?? '')), 0, 40) : '';

            if ($name === '') {
                continue;
            }

            $id = is_array($stage) && is_string($stage['id'] ?? null) && in_array($stage['id'], $known, true) ? $stage['id'] : 'st_'.Str::lower(Str::random(8));
            $stages[] = ['id' => $id, 'name' => $name];
        }

        return $stages === [] ? $current : array_slice($stages, 0, 12);
    }
}
