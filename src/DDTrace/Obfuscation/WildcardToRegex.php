<?php

namespace DDTrace\Obfuscation;

/**
 * Converts strings with wildcards into search/replace regex arrays
 * The following is converted
 * - * -> ?
 * - $* -> ${n}
 */
// Examples: /api/v1/users/*,/api/v1/rooms/*/$*,/api/v1/bookings/*/guests
// - /api/v1/users/123 -> /api/v1/users/?
// - /api/v1/rooms/123/details -> /api/v1/rooms/?/details
// - /api/v1/rooms/foo-bar-room/gallery -> /api/v1/rooms/?/gallery
// - /api/v1/bookings/123/guests/ -> /api/v1/bookings/?/guests
final class WildcardToRegex
{
    const REPLACEMENT_CHARACTER = '?';

    /**
     * @param string $wildcardPattern
     *
     * @return string[]
     */
    public static function convert($wildcardPattern)
    {
        $wildcardPattern = $replacement = trim($wildcardPattern);
        // Replace occurrences of '$*'
        if (false !== strpos($replacement, '$*')) {
            // Escape '%' chars to not interfere with sprintf()
            $replacement = str_replace('%', '%%', $replacement);
            // Add numbered replacements: ${n}
            $replacementCount = 0;
            $replacement = str_replace('$*', '%s', $replacement, $replacementCount);
            $sprintfArgs = [$replacement];
            for ($i = 1; $i <= $replacementCount; $i++) {
                $sprintfArgs[] = '${' . $i . '}';
            }
            $replacement = call_user_func_array('sprintf', $sprintfArgs);
        }
        // Replace occurrences of '*'
        $replacement = str_replace('*', self::REPLACEMENT_CHARACTER, $replacement);
        return [
            '|^' . str_replace(['\\$\\*', '\\*'], ['(.+)', '.+'], preg_quote($wildcardPattern, '|')) . '$|',
            $replacement
        ];
    }
}
