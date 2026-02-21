<?php

namespace PostalWarmup\Modules\ScenarioEngine;

use PostalWarmup\Services\Logger;

class ScenarioResolver {

    /**
     * Parses the Return-Path address to identify the context (Scenario, Step, Log).
     *
     * Expected format:
     * - Scenario Context: pw-s{log_id}-{hash}@domain.com
     * - Legacy/Direct Context: pw-t{template_id}-{hash}@domain.com (might not be enough for scenarios)
     *
     * @param string $email_address The return-path email address
     * @return array|null Context array [ 'type' => 'scenario|direct', 'id' => int ] or null
     */
    public static function resolve( $email_address ) {
        // Extract local part
        $parts = explode( '@', $email_address );
        if ( count( $parts ) !== 2 ) return null;

        $local = $parts[0];

        // Scenario Log Pattern: pw-s{log_id}-{hash}
        if ( preg_match( '/^pw-s(\d+)-([a-zA-Z0-9]+)$/', $local, $matches ) ) {
            $log_id = (int) $matches[1];
            $hash = $matches[2];

            // Verify hash against log ID for security? (Optional but good)
            // For now, return the ID.
            return [
                'type' => 'scenario_log',
                'id' => $log_id,
                'hash' => $hash
            ];
        }

        // Direct Template Pattern: pw-t{template_id}-{hash}
        if ( preg_match( '/^pw-t(\d+)-([a-zA-Z0-9]+)$/', $local, $matches ) ) {
            return [
                'type' => 'template',
                'id' => (int) $matches[1],
                'hash' => $matches[2]
            ];
        }

        return null;
    }

    /**
     * Generates a return-path local part for a given context
     */
    public static function generate_return_path_local( $context_type, $id ) {
        $hash = substr( md5( $id . 'pw_secret' ), 0, 8 ); // Simple hash
        if ( $context_type === 'scenario_log' ) {
            return "pw-s{$id}-{$hash}";
        }
        return "pw-t{$id}-{$hash}";
    }
}
