<?php

namespace App\Support\Documents;

use InvalidArgumentException;

/**
 * The file types documents may be, and what the editor calls each. One
 * home for the list, so the upload rule, the editor config and the page's
 * icons never disagree.
 */
final class DocumentTypes
{
    /**
     * @var array<string, array{editor: string, mime: string}>
     */
    private const TYPES = [
        'docx' => [
            'editor' => 'word',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
        'xlsx' => [
            'editor' => 'cell',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
        'pptx' => [
            'editor' => 'slide',
            'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::TYPES);
    }

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /** ONLYOFFICE's documentType: word, cell or slide. */
    public static function editorType(string $type): string
    {
        return self::TYPES[$type]['editor'];
    }

    public static function mime(string $type): string
    {
        return self::TYPES[$type]['mime'];
    }

    /** What people call the kind, in their language: Document, Spreadsheet, Presentation. */
    public static function label(string $type): string
    {
        return match ($type) {
            'docx' => __('Document'),
            'xlsx' => __('Spreadsheet'),
            'pptx' => __('Presentation'),
            default => throw new InvalidArgumentException("Unknown document type {$type}."),
        };
    }

    /** The blank file a new document starts from (ONLYOFFICE's, Apache-2.0). */
    public static function blankPath(string $type): string
    {
        return resource_path("documents/blank.{$type}");
    }
}
