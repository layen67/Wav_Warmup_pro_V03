<?php

namespace PostalWarmup\Modules\ScenarioEngine;

class ReplyMatcher {

    /**
     * Determines which step option matches the reply content.
     *
     * @param string $content The cleaned email body text
     * @param array $options Array of StepOption objects/arrays
     * @return object|null The matched option or null
     */
    public static function match( $content, $options ) {
        if ( empty( $options ) || ! is_array( $options ) ) {
            return null;
        }

        $clean_content = strtolower( trim( $content ) );

        foreach ( $options as $option ) {
            $keyword = isset($option->reply_keyword) ? strtolower( trim( $option->reply_keyword ) ) : '';

            if ( empty( $keyword ) ) continue;

            // Simple substring match (can be upgraded to regex)
            // If keyword starts/ends with slash, treat as regex
            if ( str_starts_with( $keyword, '/' ) && str_ends_with( $keyword, '/' ) ) {
                if ( preg_match( $keyword, $clean_content ) ) {
                    return $option;
                }
            } else {
                // Exact word match within text (not just contains, to avoid false positives?)
                // Or just simple 'contains' as requested?
                // The prompt says "keyword: ['OK', 'OUI']".
                // Often 'OK' is short. "I am ok with this".
                if ( str_contains( $clean_content, $keyword ) ) {
                    return $option;
                }
            }
        }

        return null;
    }
}
