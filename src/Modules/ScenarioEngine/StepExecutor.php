<?php

namespace PostalWarmup\Modules\ScenarioEngine;

use PostalWarmup\Services\LoadBalancer;
use PostalWarmup\Services\QueueManager;
use PostalWarmup\Services\Logger;
use PostalWarmup\Services\ISPDetector;
use PostalWarmup\Admin\TemplateManager;

class StepExecutor {

    /**
     * Executes a scenario step (SEND type)
     *
     * @param ScenarioStep $step The step to execute
     * @param string $email The recipient email
     * @param int $log_id The Scenario Log ID (context)
     * @return bool Success or failure to queue
     */
    public static function execute( $step, $email, $log_id ) {
        global $wpdb;

        if ( $step->step_type !== 'SEND' ) {
            return true; // Nothing to send
        }

        // 1. Resolve Template
        $template_id = $step->template_id;
        $template = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}postal_templates WHERE id = %d", $template_id ) );

        if ( ! $template ) {
            Logger::error( "StepExecutor: Template #{$template_id} not found for Step #{$step->id}" );
            return false;
        }

        // 2. Detect ISP for Load Balancing
        $isp_key = ISPDetector::detect( $email );

        // 3. Select Server (LoadBalancer V3)
        $server_data = LoadBalancer::select_server( $template_id, [ 'isp' => $isp_key ] );

        if ( ! $server_data ) {
            Logger::warning( "StepExecutor: No server available for Step #{$step->id} (ISP: $isp_key). Retrying later." );
            // Optionally queue without server ID and let Cron resolve it?
            // Current QueueManager requires server_id.
            // We can return false and let the caller retry or fail.
            return false;
        }

        $server_id = (int) $server_data['id'];
        $server_domain = $server_data['domain'];

        // 4. Calculate Scheduled Time (Timezone + Allowed Hours)
        $scheduled_at = self::calculate_schedule( $template, $step->delay_minutes );

        // 5. Prepare Email Content (Subject/From) from Template Data
        $data = json_decode( $template->data, true );
        // Randomize variants if multiple
        $subject = self::pick_variant( $data['subject'] ?? [] ) ?: 'Hello';
        $from_name = self::pick_variant( $data['from_name'] ?? [] ) ?: 'Contact';

        // 6. Generate Return-Path (Context)
        // We need to inject this into the queue item so the Sender uses it.
        // QueueManager usually constructs the FROM header.
        // The Return-Path is often set by the mailer (Postal) based on the sender or explicit header.
        // We will pass it in 'meta'.
        $return_path_local = ScenarioResolver::generate_return_path_local( 'scenario_log', $log_id );
        $return_path = $return_path_local . '@' . $server_domain;

        // 7. Add to Queue
        $meta = [
            'scenario_log_id' => $log_id,
            'scenario_step_id' => $step->id,
            'return_path' => $return_path,
            'template_id' => $template_id,
            'isp' => $isp_key
        ];

        // QueueManager::add($server_id, $to, $from_email, $subject, $meta, $scheduled_at)
        // Construct from_email: name <local@domain>? Or just email?
        // Usually "From Name <random@domain>"
        // Let's assume standard format: "From Name <{$return_path_local}@{$server_domain}>"
        // Wait, return-path is for bounces. From address should be clean.
        // "From: contact@domain". "Return-Path: pw-s1-xyz@domain".
        // Using return-path as From address (VERP style) is common for tracking replies to unique address.
        // Prompt says: "ScenarioResolver Identifie... à partir du return-path".
        // Replies go to the From address or Reply-To.
        // If the user hits Reply, they reply to the From header (or Reply-To).
        // Postal Webhook "message.received" captures these.
        // So we must set the From (or Reply-To) to the unique address.

        $unique_email = "{$return_path_local}@{$server_domain}";

        QueueManager::add(
            $server_id,
            $email,
            "$from_name <$unique_email>",
            $subject,
            $meta,
            $scheduled_at
        );

        Logger::info( "StepExecutor: Queued Step #{$step->id} for $email on Server {$server_domain} at $scheduled_at" );

        return true;
    }

    /**
     * Calculates the correct schedule time based on delays and time windows.
     */
    private static function calculate_schedule( $template, $delay_minutes ) {
        // Start from NOW + Delay
        $base_time = time() + ( $delay_minutes * 60 );

        $tz_string = $template->timezone;
        if ( ! $tz_string ) {
            $tz_string = wp_timezone_string(); // Fallback to WP Timezone
        }

        try {
            $tz = new \DateTimeZone( $tz_string );
            $dt = new \DateTime( '@' . $base_time ); // Create from timestamp (UTC)
            $dt->setTimezone( $tz ); // Convert to target TZ
        } catch ( \Exception $e ) {
            // Fallback
            $tz = new \DateTimeZone( 'UTC' );
            $dt = new \DateTime( '@' . $base_time );
            $dt->setTimezone( $tz );
        }

        // Check Allowed Hours
        $start_hour = isset($template->allowed_start_hour) ? (int)$template->allowed_start_hour : 9;
        $end_hour = isset($template->allowed_end_hour) ? (int)$template->allowed_end_hour : 18;

        $current_hour = (int) $dt->format( 'G' );

        // If outside window, move to next available slot
        if ( $current_hour < $start_hour ) {
            // Too early: Move to start_hour today
            $dt->setTime( $start_hour, rand(0, 59), 0 ); // Add randomness to minute
        } elseif ( $current_hour >= $end_hour ) {
            // Too late: Move to start_hour tomorrow
            $dt->modify( '+1 day' );
            $dt->setTime( $start_hour, rand(0, 59), 0 );
        }

        // Return in UTC for DB storage
        $dt->setTimezone( new \DateTimeZone( 'UTC' ) );
        return $dt->format( 'Y-m-d H:i:s' );
    }

    private static function pick_variant( $variants ) {
        if ( empty( $variants ) ) return null;
        if ( is_string( $variants ) ) return $variants;
        return $variants[ array_rand( $variants ) ];
    }
}
