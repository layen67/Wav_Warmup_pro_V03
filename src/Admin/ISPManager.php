<?php

namespace PostalWarmup\Admin;

/**
 * Manager pour les FAI (ISP)
 */
class ISPManager {

    /**
     * Récupère tous les ISP (Custom + Default)
     * Les custom ont priorité ou sont ajoutés à la liste
     */
    public static function get_all() {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_isps';

        $custom_isps = $wpdb->get_results( "SELECT * FROM $table ORDER BY name ASC", ARRAY_A );

        // Default ISPs (from ISPDetector)
        // We might want to allow overriding default limits too?
        // For now, let's just return custom ones + defaults that are NOT overridden by name.

        return $custom_isps;
    }

    public static function add( $name, $regex, $daily_limit = 0, $hourly_limit = 0, $warmup_score = 10 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_isps';

        $data = [
            'name' => sanitize_text_field( $name ),
            'regex' => sanitize_text_field( $regex ), // Should be validated as valid regex
            'daily_limit' => absint( $daily_limit ),
            'hourly_limit' => absint( $hourly_limit ),
            'warmup_score' => absint( $warmup_score ),
            'created_at' => current_time( 'mysql' )
        ];

        // Validate regex
        if ( @preg_match( '/' . str_replace('/', '\/', $data['regex']) . '/', '' ) === false ) {
            return new \WP_Error( 'invalid_regex', 'Expression régulière invalide' );
        }

        $wpdb->insert( $table, $data );
        return $wpdb->insert_id;
    }

    public static function update( $id, $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_isps';

        $update_data = [];
        if ( isset( $data['name'] ) ) $update_data['name'] = sanitize_text_field( $data['name'] );
        if ( isset( $data['regex'] ) ) $update_data['regex'] = sanitize_text_field( $data['regex'] );
        if ( isset( $data['daily_limit'] ) ) $update_data['daily_limit'] = absint( $data['daily_limit'] );
        if ( isset( $data['hourly_limit'] ) ) $update_data['hourly_limit'] = absint( $data['hourly_limit'] );
        if ( isset( $data['warmup_score'] ) ) $update_data['warmup_score'] = absint( $data['warmup_score'] );

        // Validate regex if updated
        if ( isset( $update_data['regex'] ) ) {
             if ( @preg_match( '/' . str_replace('/', '\/', $update_data['regex']) . '/', '' ) === false ) {
                return new \WP_Error( 'invalid_regex', 'Expression régulière invalide' );
            }
        }

        $wpdb->update( $table, $update_data, [ 'id' => $id ] );
        return true;
    }

    public static function delete( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_isps';
        return $wpdb->delete( $table, [ 'id' => $id ] );
    }

    public static function get_by_name( $name ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_isps';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE name = %s", $name ), ARRAY_A );
    }
}
