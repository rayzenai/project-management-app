<?php

namespace App\Support\Forms;

/**
 * A text block's words: the builder's editor (TipTap) sends its JSON
 * document, and only what that editor makes for a form survives:
 * paragraphs, bulleted and numbered lists, line breaks, and bold, italic
 * and links (http, https and mailto only). The page draws it with
 * components/forms/RichText.svelte, never as HTML.
 */
final class FormRichText
{
    /** The most characters a block's words may have in one language. */
    public const MAX_CHARACTERS = 10000;

    private const BLOCKS = ['paragraph', 'bulletList', 'orderedList', 'listItem'];

    private const MARKS = ['bold', 'italic', 'link'];

    /**
     * The document as kept: null when nothing in it survives.
     *
     * @return array{type: 'doc', content: list<array<string, mixed>>}|null
     */
    public static function clean(mixed $doc): ?array
    {
        if (! is_array($doc) || ($doc['type'] ?? null) !== 'doc') {
            return null;
        }

        $budget = self::MAX_CHARACTERS;
        $content = self::children($doc['content'] ?? null, $budget, 0);

        if ($content === [] || trim(self::toText(['type' => 'doc', 'content' => $content])) === '') {
            return null;
        }

        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function children(mixed $nodes, int &$budget, int $depth): array
    {
        if (! is_array($nodes) || $depth > 6) {
            return [];
        }

        $kept = [];

        foreach (array_slice(array_values($nodes), 0, 500) as $node) {
            if (! is_array($node) || $budget <= 0) {
                continue;
            }

            $type = $node['type'] ?? null;

            if ($type === 'text') {
                $text = is_string($node['text'] ?? null) ? mb_substr($node['text'], 0, $budget) : '';

                if ($text === '') {
                    continue;
                }

                $budget -= mb_strlen($text);
                $marks = self::marks($node['marks'] ?? null);
                $kept[] = $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => $marks];
            } elseif ($type === 'hardBreak') {
                $kept[] = ['type' => 'hardBreak'];
            } elseif (in_array($type, self::BLOCKS, true)) {
                $children = self::children($node['content'] ?? null, $budget, $depth + 1);
                $kept[] = $children === [] ? ['type' => $type] : ['type' => $type, 'content' => $children];
            }
        }

        return $kept;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function marks(mixed $marks): array
    {
        if (! is_array($marks)) {
            return [];
        }

        $kept = [];

        foreach ($marks as $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;

            if (! in_array($type, self::MARKS, true) || in_array($type, array_column($kept, 'type'), true)) {
                continue;
            }

            if ($type === 'link') {
                $href = is_array($mark['attrs'] ?? null) && is_string($mark['attrs']['href'] ?? null) ? trim($mark['attrs']['href']) : '';

                if (! self::safeLink($href)) {
                    continue;
                }

                $kept[] = ['type' => 'link', 'attrs' => ['href' => $href]];

                continue;
            }

            $kept[] = ['type' => $type];
        }

        return $kept;
    }

    /** http, https or mailto, nothing else (no javascript:, no data:). */
    public static function safeLink(string $href): bool
    {
        if ($href === '' || mb_strlen($href) > 2000) {
            return false;
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto'], true);
    }

    /**
     * The words without their formatting, a line per paragraph or item.
     *
     * @param  array<string, mixed>|null  $doc
     */
    public static function toText(?array $doc): string
    {
        if ($doc === null) {
            return '';
        }

        $lines = [];
        $walk = function (mixed $nodes) use (&$walk, &$lines): void {
            foreach (is_array($nodes) ? $nodes : [] as $node) {
                if (! is_array($node)) {
                    continue;
                }

                if (($node['type'] ?? null) === 'paragraph') {
                    $lines[] = self::inline($node['content'] ?? null);
                } else {
                    $walk($node['content'] ?? null);
                }
            }
        };
        $walk($doc['content'] ?? null);

        return trim(implode("\n", $lines));
    }

    private static function inline(mixed $nodes): string
    {
        $text = '';

        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'text' && is_string($node['text'] ?? null)) {
                $text .= $node['text'];
            } elseif (is_array($node) && ($node['type'] ?? null) === 'hardBreak') {
                $text .= "\n";
            }
        }

        return $text;
    }
}
