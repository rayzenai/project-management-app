<?php

namespace App\Support;

class MentionParser
{
    /**
     * Extract unique member ids from canonical mention tokens
     * of the form @[Display Name](member:ID).
     *
     * @return list<int>
     */
    public static function memberIds(string $body): array
    {
        preg_match_all('/@\[[^\]]+\]\(member:(\d+)\)/', $body, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    /**
     * Convert canonical mention tokens @[Display Name](member:ID) into readable
     * "@Display Name" text for display in notifications, previews, etc.
     */
    public static function toDisplayText(string $body): string
    {
        return preg_replace('/@\[([^\]]+)\]\(member:\d+\)/', '@$1', $body) ?? $body;
    }
}
