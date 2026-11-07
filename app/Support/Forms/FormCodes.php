<?php

namespace App\Support\Forms;

/**
 * Codes people read and type: a form's link (`/f/K7M3QX9A`) and a response's
 * reference, from an alphabet without look-alike characters (no 0, O, 1, I,
 * L, U), as sdoc's references are.
 */
final class FormCodes
{
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function make(int $length): string
    {
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /** A code as typed (any case, spaces, dashes), as it is stored. */
    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
