<?php

namespace App\Support;

use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Reporting lines: who is above and below a person, any number of levels
 * (`members.reports_to_id`). The organogram draws them; WorkspaceAccess
 * uses them to decide who sees whose attendance and work. Recursive CTEs
 * with UNION, so even a loop that slipped in ends.
 *
 * Memoized per member id for the request; WorkspaceAccess::flush() clears
 * it.
 */
final class OrgChart
{
    /** @var array<int, list<int>> */
    private static array $below = [];

    /** @var array<int, list<int>> */
    private static array $above = [];

    public static function flush(): void
    {
        self::$below = [];
        self::$above = [];
    }

    /**
     * Everyone who reports to the member, directly or through others; never
     * the member, never a deleted person.
     *
     * @return list<int>
     */
    public static function descendantIds(Member|int $member): array
    {
        $id = $member instanceof Member ? (int) $member->getKey() : $member;

        return self::$below[$id] ??= self::ids(DB::select(<<<'SQL'
                WITH RECURSIVE below(id) AS (
                    SELECT id FROM members WHERE reports_to_id = ? AND deleted_at IS NULL
                    UNION
                    SELECT m.id FROM members m JOIN below b ON m.reports_to_id = b.id WHERE m.deleted_at IS NULL
                )
                SELECT id FROM below WHERE id <> ?
                SQL, [$id, $id]));
    }

    /**
     * The member's higher authority, theirs, and so on to the top; never a
     * deleted person, and the walk stops at one, as `descendantIds` does.
     *
     * @return list<int>
     */
    public static function ancestorIds(Member|int $member): array
    {
        $id = $member instanceof Member ? (int) $member->getKey() : $member;

        return self::$above[$id] ??= self::ids(DB::select(<<<'SQL'
                WITH RECURSIVE above(id) AS (
                    SELECT reports_to_id FROM members WHERE id = ? AND reports_to_id IS NOT NULL
                    UNION
                    SELECT m.reports_to_id FROM members m JOIN above a ON m.id = a.id
                    WHERE m.reports_to_id IS NOT NULL AND m.deleted_at IS NULL
                )
                SELECT above.id FROM above JOIN members up ON up.id = above.id
                WHERE above.id <> ? AND up.deleted_at IS NULL
                SQL, [$id, $id]));
    }

    /**
     * Whether making `$boss` the member's higher authority would close a
     * loop: the member themselves, or anyone below them.
     */
    public static function wouldLoop(Member $member, ?Member $boss): bool
    {
        if ($boss === null) {
            return false;
        }

        return $boss->is($member) || in_array((int) $boss->getKey(), self::descendantIds($member), true);
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<int>
     */
    private static function ids(array $rows): array
    {
        return array_values(array_map(fn (mixed $row): int => (int) data_get($row, 'id'), $rows));
    }
}
