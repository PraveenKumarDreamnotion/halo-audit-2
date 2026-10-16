<?php
namespace HaloAudit;

final class Text
{
    public static function has(string $haystack, string $needle): bool
    {
        $needle = trim((string) preg_replace('/\s+/u', ' ', $needle));
        if ($needle === '') {
            return false;
        }
        $haystack = (string) preg_replace('/\s+/u', ' ', $haystack);
        return mb_stripos($haystack, $needle) !== false;
    }

    public static function words(string $text): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}][\p{L}\p{M}\p{N}\'’-]*/u', $text);
    }
}
