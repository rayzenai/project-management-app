<?php

namespace App\Support\Forms;

/**
 * An answer in words, for exports, summaries and tasks: an option's label
 * rather than its id, Yes or No, a date in the form's calendar with its
 * twin, a place by name, files by name.
 */
final class FormAnswerText
{
    /**
     * @param  array<string, mixed>  $question
     * @param  array<int, string>  $fileNames  by file id
     */
    public static function of(array $question, mixed $value, string $language = 'en', array $fileNames = []): string
    {
        if ($value === null) {
            return '';
        }

        $options = array_column(is_array($question['options'] ?? null) ? $question['options'] : [], 'label', 'id');
        $option = fn (mixed $id): string => is_string($id) && isset($options[$id]) ? FormSchema::text(is_array($options[$id]) ? $options[$id] : null, $language) : (string) (is_scalar($id) ? $id : '');
        $rules = is_array($question['rules'] ?? null) ? $question['rules'] : [];

        return match ((string) ($question['type'] ?? '')) {
            'choice' => $option($value),
            'choices' => implode(', ', array_map($option, is_array($value) ? $value : [])),
            'yes_no' => $value === true ? 'Yes' : ($value === false ? 'No' : ''),
            'declaration' => $value === true ? 'Yes' : '',
            'date' => is_array($value) ? (($rules['calendar'] ?? 'bs') === 'ad'
                ? (string) ($value['ad'] ?? '')
                : trim(($value['bs'] ?? '').' BS ('.($value['ad'] ?? '').')')) : '',
            'place' => is_array($value) ? NepalPlaces::label($value, $language) : '',
            'file' => implode(', ', array_map(fn (mixed $id): string => is_int($id) ? ($fileNames[$id] ?? 'file') : '', is_array($value) ? $value : [])),
            'group' => is_array($value) ? count($value).' '.(count($value) === 1 ? 'entry' : 'entries') : '',
            default => is_scalar($value) ? (string) $value : '',
        };
    }
}
