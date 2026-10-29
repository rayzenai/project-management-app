<?php

namespace App\Support;

use App\Models\Project;
use App\Models\ProjectKeyAlias;
use Illuminate\Support\Str;

/**
 * Project keys: the stable first half of a task code (SG in SG-12). A key is
 * 1 to 10 capital letters, unique within an organization across current keys
 * and the aliases left behind by renames, so an old commit never starts
 * pointing at a different project.
 */
final class ProjectKeys
{
    public const MAX_LENGTH = 10;

    /**
     * The key a title suggests: the first ASCII letter of each of its first
     * four words. "P" when the title has none (a Nepali title, "100", "—").
     */
    public static function suggest(string $title): string
    {
        // Only Latin script is folded to ASCII (E acute -> E); Devanagari and the
        // like would transliterate into noise, so such words contribute nothing.
        $initials = collect(preg_split('/\s+/u', $title) ?: [])
            ->map(fn (string $word): string => substr((string) preg_replace('/[^A-Za-z]/', '', Str::ascii((string) preg_replace('/[^\p{Latin}]/u', '', $word))), 0, 1))
            ->filter()
            ->take(4)
            ->implode('');

        return $initials !== '' ? strtoupper($initials) : 'P';
    }

    public static function isTaken(int $organizationId, string $key, ?int $exceptProjectId = null): bool
    {
        $key = strtoupper($key);

        return Project::query()
            ->where('organization_id', $organizationId)
            ->where('key', $key)
            ->when($exceptProjectId !== null, fn ($q) => $q->where('id', '!=', $exceptProjectId))
            ->exists()
            || ProjectKeyAlias::query()
                ->where('organization_id', $organizationId)
                ->where('key', $key)
                ->when($exceptProjectId !== null, fn ($q) => $q->where('project_id', '!=', $exceptProjectId))
                ->exists();
    }

    /**
     * $base if free in the organization, else $base with B, C, ... Z added,
     * then BA, BB, ... (capped at ten characters).
     */
    public static function unique(int $organizationId, string $base, ?int $exceptProjectId = null): string
    {
        $base = strtoupper($base);
        $key = $base;

        for ($n = 0; self::isTaken($organizationId, $key, $exceptProjectId); $n++) {
            $key = self::variant($base, $n);
        }

        return $key;
    }

    /**
     * The n-th alternative to a taken key: B..Z (n 0-24), then BA, BB, ...
     * Capped at ten characters by trimming the base.
     */
    public static function variant(string $base, int $n): string
    {
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $suffix = $n < 25
            ? $letters[$n + 1]
            : $letters[(intdiv($n - 25, 26) % 25) + 1].$letters[($n - 25) % 26];

        return substr($base, 0, self::MAX_LENGTH - strlen($suffix)).$suffix;
    }

    /**
     * Whether a key is one the app assigned on its own: what the title
     * suggests, or that with a suffix letter or two for a clash. Anything
     * else was chosen by a person.
     */
    public static function isAutomatic(string $title, string $key): bool
    {
        $base = self::suggest($title);

        return str_starts_with($key, $base) && strlen($key) - strlen($base) <= 2;
    }

    /**
     * The project an organization's key points at: by current key, then by
     * alias. Case-insensitive.
     */
    public static function resolve(int $organizationId, string $key): ?Project
    {
        $key = strtoupper($key);

        return Project::query()->where('organization_id', $organizationId)->where('key', $key)->first()
            ?? ProjectKeyAlias::query()->where('organization_id', $organizationId)->where('key', $key)->first()?->project;
    }
}
