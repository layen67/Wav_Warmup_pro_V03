<?php

namespace PostalWarmup\Modules\ScenarioEngine;

use PostalWarmup\Services\Logger;
use PostalWarmup\Modules\ScenarioEngine\Models\Scenario;
use PostalWarmup\Modules\ScenarioEngine\Models\ScenarioStep;

class ScenarioRegistry {

    /**
     * Get a specific scenario by ID
     */
    public static function get( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_scenarios';

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( ! $row ) return null;

        $scenario = new Scenario( $row );
        self::hydrate_steps( $scenario );

        return $scenario;
    }

    /**
     * Load steps and options for a scenario
     */
    private static function hydrate_steps( Scenario $scenario ) {
        global $wpdb;
        $steps_table = $wpdb->prefix . 'postal_scenario_steps';
        $options_table = $wpdb->prefix . 'postal_scenario_step_options';

        $steps_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $steps_table WHERE scenario_id = %d ORDER BY step_number ASC",
            $scenario->id
        ) );

        foreach ( $steps_rows as $s_row ) {
            $step = new ScenarioStep( $s_row );

            // Get options (replies)
            $opts = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $options_table WHERE step_id = %d",
                $step->id
            ) );

            $step->options = $opts;
            $scenario->add_step( $step );
        }
    }

    /**
     * Find a scenario step by template ID (used for resolution)
     * Note: A template might be used in multiple scenarios/steps, so this returns potentially multiple results.
     * Usually the return-path contains enough info to be precise.
     */
    public static function find_step_by_id( $step_id ) {
        global $wpdb;
        $steps_table = $wpdb->prefix . 'postal_scenario_steps';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $steps_table WHERE id = %d", $step_id ) );

        if ( $row ) {
            $step = new ScenarioStep( $row );
            // Hydrate options
            $options_table = $wpdb->prefix . 'postal_scenario_step_options';
            $step->options = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $options_table WHERE step_id = %d", $step->id ) );
            return $step;
        }
        return null;
    }
}
