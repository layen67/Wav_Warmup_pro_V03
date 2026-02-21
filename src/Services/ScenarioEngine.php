<?php

namespace PostalWarmup\Services;

use PostalWarmup\Services\Logger;
use PostalWarmup\Services\QueueManager;
use PostalWarmup\Services\ISPDetector;
use PostalWarmup\Services\TemplateLoader;
use PostalWarmup\Models\Database;

class ScenarioEngine {

    /**
     * Start a scenario for an email address
     */
    public static function start_scenario( $email, $scenario_id ) {
        global $wpdb;
        $table_logs = $wpdb->prefix . 'postal_scenario_logs';
        $table_steps = $wpdb->prefix . 'postal_scenario_steps';

        // Check if already active
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_logs WHERE email = %s AND scenario_id = %d AND status = 'active'", $email, $scenario_id ) );
        if ( $exists ) {
            Logger::info( "ScenarioEngine: Email $email already active in scenario #$scenario_id" );
            return false;
        }

        // Get first step
        $first_step = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_steps WHERE scenario_id = %d ORDER BY step_number ASC LIMIT 1", $scenario_id ) );

        if ( ! $first_step ) {
            Logger::error( "ScenarioEngine: No steps found for scenario #$scenario_id" );
            return false;
        }

        // Initialize log
        $wpdb->insert( $table_logs, [
            'scenario_id' => $scenario_id,
            'email' => $email,
            'current_step_id' => $first_step->id,
            'status' => 'active',
            'last_activity' => current_time( 'mysql' )
        ] );
        $log_id = $wpdb->insert_id;

        // Execute first step
        self::execute_step( $first_step, $email, $log_id );

        return $log_id;
    }

    /**
     * Execute a specific step logic
     */
    private static function execute_step( $step, $email, $log_id ) {
        global $wpdb;

        Logger::info( "ScenarioEngine: Executing Step #{$step->id} ({$step->step_type}) for $email" );

        if ( $step->step_type === 'SEND' ) {
            // Fetch Template Data directly using TemplateLoader (handles JSON parsing & fallbacks)
            $template_data = TemplateLoader::load( $step->template_id );

            if ( $template_data ) {
                // Get Random Subject/From Name from variants
                $subject = TemplateLoader::pick_random( $template_data['subject'] ?? [] );
                if ( empty( $subject ) ) $subject = 'Hello';

                $from_name = TemplateLoader::pick_random( $template_data['from_name'] ?? [] );
                if ( empty( $from_name ) ) $from_name = 'Contact';

                $prefix = sanitize_title( $from_name ) ?: 'contact';

                // Meta for tracking reply & context
                $meta = [
                    'template_id' => $step->template_id,
                    'scenario_log_id' => $log_id,
                    'scenario_step_id' => $step->id,
                    'prefix' => $prefix
                ];

                // Add to Queue WITHOUT pre-selecting server.
                // QueueManager will handle Load Balancing, Time Windows, and Limits.
                // We pass 0 as server_id and a placeholder domain.
                // QueueManager::process_queue updates server_id and from_email.
                $queued_id = QueueManager::add( 0, $email, $prefix . '@pending', $subject, $meta );

                if ( $queued_id ) {
                    Logger::info( "ScenarioEngine: Queued step #{$step->id} for $email (Queue ID: $queued_id)" );
                } else {
                    Logger::error( "ScenarioEngine: Failed to queue step #{$step->id} for $email" );
                }

            } else {
                Logger::warning( "ScenarioEngine: Template #{$step->template_id} not found for step #{$step->id}" );
            }

        } elseif ( $step->step_type === 'WAIT' ) {
            // Logic for wait? Usually handled by checking last_activity timestamp before proceeding.
            // This engine is triggered by Events (Replies) or Cron?
            // If strictly Reply-Driven, WAIT might mean "Wait for reply".
            // If Time-Driven, we need a cron to check "pending" steps.
            // User requirement: "Scenario Engine lit la réponse → déclenche step suivant exact."
            // So mostly event driven.
        }
    }

    /**
     * Process an incoming reply
     */
    public static function process_reply( $email, $reply_content ) {
        global $wpdb;
        $table_logs = $wpdb->prefix . 'postal_scenario_logs';
        $table_options = $wpdb->prefix . 'postal_scenario_step_options';
        $table_steps = $wpdb->prefix . 'postal_scenario_steps';

        // Find active scenario for this email
        $log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_logs WHERE email = %s AND status = 'active'", $email ) );

        if ( ! $log ) return;

        // Check conditions for current step
        $options = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_options WHERE step_id = %d", $log->current_step_id ) );

        $matched_option = null;
        $reply_clean = trim( strtolower( $reply_content ) );

        foreach ( $options as $opt ) {
            // Simple keyword match (can be enhanced to regex)
            if ( empty( $opt->reply_keyword ) || str_contains( $reply_clean, strtolower( $opt->reply_keyword ) ) ) {
                $matched_option = $opt;
                break;
            }
        }

        if ( $matched_option ) {
            Logger::info( "ScenarioEngine: Reply matched keyword '{$matched_option->reply_keyword}'" );

            if ( $matched_option->action === 'STOP_FLOW' ) {
                $wpdb->update( $table_logs, [ 'status' => 'stopped' ], [ 'id' => $log->id ] );
                return;
            }

            $next_step_id = $matched_option->next_step_id;

            // Move to next step
            if ( $next_step_id ) {
                $wpdb->update( $table_logs, [
                    'current_step_id' => $next_step_id,
                    'last_activity' => current_time( 'mysql' )
                ], [ 'id' => $log->id ] );

                $next_step = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_steps WHERE id = %d", $next_step_id ) );
                if ( $next_step ) {
                    self::execute_step( $next_step, $email, $log->id );
                }
            } else {
                // End of scenario
                $wpdb->update( $table_logs, [ 'status' => 'completed' ], [ 'id' => $log->id ] );
            }
        }
    }
}
