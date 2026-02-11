<?php

namespace PostalWarmup\Admin;

class ScenarioManager {

    /**
     * Récupère tous les scénarios
     */
    public static function get_all() {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_scenarios';
        return $wpdb->get_results( "SELECT * FROM $table ORDER BY created_at DESC", ARRAY_A );
    }

    /**
     * Récupère un scénario avec ses étapes
     */
    public static function get( $id ) {
        global $wpdb;
        $table_scenarios = $wpdb->prefix . 'postal_scenarios';
        $table_steps = $wpdb->prefix . 'postal_scenario_steps';
        $table_options = $wpdb->prefix . 'postal_scenario_step_options';

        $scenario = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_scenarios WHERE id = %d", $id ), ARRAY_A );
        if ( ! $scenario ) return null;

        $steps = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_steps WHERE scenario_id = %d ORDER BY step_number ASC", $id ), ARRAY_A );

        foreach ( $steps as &$step ) {
            $step['options'] = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_options WHERE step_id = %d", $step['id'] ), ARRAY_A );
        }

        $scenario['steps'] = $steps;
        return $scenario;
    }

    /**
     * Sauvegarde un scénario (Création/Édition)
     */
    public static function save( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_scenarios';

        $db_data = [
            'name' => sanitize_text_field( $data['name'] ),
            'description' => sanitize_textarea_field( $data['description'] ),
            'status' => sanitize_text_field( $data['status'] )
        ];

        if ( ! empty( $data['id'] ) ) {
            $result = $wpdb->update( $table, $db_data, [ 'id' => (int) $data['id'] ] );
            if ( $result === false ) {
                return new \WP_Error( 'db_error', 'Erreur lors de la mise à jour : ' . $wpdb->last_error );
            }
            return (int) $data['id'];
        } else {
            $result = $wpdb->insert( $table, $db_data );
            if ( $result === false ) {
                return new \WP_Error( 'db_error', 'Erreur lors de la création : ' . $wpdb->last_error );
            }
            return $wpdb->insert_id;
        }
    }

    /**
     * Sauvegarde une étape
     */
    public static function save_step( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_scenario_steps';

        $db_data = [
            'scenario_id' => (int) $data['scenario_id'],
            'step_number' => (int) $data['step_number'],
            'step_type' => sanitize_text_field( $data['step_type'] ),
            'template_id' => ! empty( $data['template_id'] ) ? (int) $data['template_id'] : null,
            'delay_minutes' => (int) $data['delay_minutes']
        ];

        if ( ! empty( $data['id'] ) ) {
            $wpdb->update( $table, $db_data, [ 'id' => (int) $data['id'] ] );
            return (int) $data['id'];
        } else {
            $wpdb->insert( $table, $db_data );
            return $wpdb->insert_id;
        }
    }

    /**
     * Sauvegarde une option d'étape (Réponse attendue)
     */
    public static function save_step_option( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_scenario_step_options';

        $db_data = [
            'step_id' => (int) $data['step_id'],
            'reply_keyword' => sanitize_text_field( $data['reply_keyword'] ),
            'action' => sanitize_text_field( $data['action'] ),
            'next_step_id' => ! empty( $data['next_step_id'] ) ? (int) $data['next_step_id'] : null
        ];

        if ( ! empty( $data['id'] ) ) {
            $wpdb->update( $table, $db_data, [ 'id' => (int) $data['id'] ] );
            return (int) $data['id'];
        } else {
            $wpdb->insert( $table, $db_data );
            return $wpdb->insert_id;
        }
    }

    /**
     * Supprime un scénario et ses dépendances
     */
    public static function delete( $id ) {
        global $wpdb;
        $table_scenarios = $wpdb->prefix . 'postal_scenarios';
        $table_steps = $wpdb->prefix . 'postal_scenario_steps';
        $table_options = $wpdb->prefix . 'postal_scenario_step_options';

        // Get all steps to delete options
        $steps = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table_steps WHERE scenario_id = %d", $id ) );

        if ( ! empty( $steps ) ) {
            $steps_list = implode( ',', array_map( 'intval', $steps ) );
            $wpdb->query( "DELETE FROM $table_options WHERE step_id IN ($steps_list)" );
        }

        $wpdb->delete( $table_steps, [ 'scenario_id' => $id ] );
        return $wpdb->delete( $table_scenarios, [ 'id' => $id ] );
    }
}
