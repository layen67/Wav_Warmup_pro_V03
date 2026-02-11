<?php

namespace PostalWarmup\Modules\ScenarioEngine;

use PostalWarmup\Services\Logger;
use PostalWarmup\Modules\ScenarioEngine\Models\Scenario;
use PostalWarmup\Modules\ScenarioEngine\Models\ScenarioStep;

class ScenarioEngine {

    /**
     * Handles a reply for a specific scenario log
     *
     * @param int $log_id The Scenario Log ID
     * @param string $content The reply body content
     * @return bool Success
     */
    public static function handle_reply( $log_id, $content ) {
        global $wpdb;
        $table_logs = $wpdb->prefix . 'postal_scenario_logs';
        $table_options = $wpdb->prefix . 'postal_scenario_step_options';
        $table_steps = $wpdb->prefix . 'postal_scenario_steps';

        // 1. Load Log
        $log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_logs WHERE id = %d", $log_id ) );

        if ( ! $log || $log->status !== 'active' ) {
            Logger::warning( "ScenarioEngine: Log #$log_id not found or not active." );
            return false;
        }

        // 2. Identify Current Step
        $current_step_id = $log->current_step_id;

        // Load Options for this Step
        $options = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_options WHERE step_id = %d", $current_step_id ) );

        // 3. Match Reply
        $matched_option = ReplyMatcher::match( $content, $options );

        if ( ! $matched_option ) {
            Logger::info( "ScenarioEngine: No match for reply in Log #$log_id." );
            return false; // No action taken, user just replied something irrelevant? Or maybe log it?
        }

        Logger::info( "ScenarioEngine: Matched option '{$matched_option->reply_keyword}' (Action: {$matched_option->action})" );

        // 4. Handle Action
        if ( $matched_option->action === 'STOP_FLOW' ) {
            $wpdb->update( $table_logs, [
                'status' => 'stopped',
                'last_activity' => current_time( 'mysql' )
            ], [ 'id' => $log_id ] );
            return true;
        }

        if ( $matched_option->action === 'JUMP_SCENARIO' ) {
            // Not implemented fully yet, maybe just update scenario_id?
            // For now, treat as stop or error.
            Logger::warning( "ScenarioEngine: JUMP_SCENARIO not implemented." );
            return false;
        }

        // CONTINUE -> Next Step
        $next_step_id = $matched_option->next_step_id;

        if ( ! $next_step_id ) {
            // End of flow?
            $wpdb->update( $table_logs, [
                'status' => 'completed',
                'last_activity' => current_time( 'mysql' )
            ], [ 'id' => $log_id ] );
            return true;
        }

        // Load Next Step
        $next_step_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_steps WHERE id = %d", $next_step_id ) );
        if ( ! $next_step_row ) {
            Logger::error( "ScenarioEngine: Next Step #$next_step_id not found." );
            return false;
        }

        $next_step = new ScenarioStep( $next_step_row );

        // 5. Execute Next Step
        $success = StepExecutor::execute( $next_step, $log->email, $log_id );

        if ( $success ) {
            // Update Log to point to new step
            $wpdb->update( $table_logs, [
                'current_step_id' => $next_step_id,
                'last_activity' => current_time( 'mysql' )
            ], [ 'id' => $log_id ] );
        }

        return $success;
    }
}
