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

        // 3. Skip immediate server selection.
        // Let QueueManager handle server selection, load balancing, and schedule limits at send time.
        // This prevents dropping the step if limits are currently full or if we are scheduling for later.
        $server_id = 0;
        $server_domain = 'pending'; // Placeholder

        // 4. Calculate Scheduled Time (Timezone + Allowed Hours)
        $scheduled_at = self::calculate_schedule( $template, $step->delay_minutes );

        // 5. Prepare Email Content (Subject/From) from Template Data
        $data = json_decode( $template->data, true );
        $subject = self::pick_variant( $data['subject'] ?? [] ) ?: 'Hello';
        $from_name = self::pick_variant( $data['from_name'] ?? [] ) ?: 'Contact';

        // 6. Generate Tracking ID (Scenario Context)
        // We use ScenarioResolver to generate a unique hash for this log/step.
        // This hash will be used as the email prefix (local part) so replies can be routed back.
        $return_path_local = ScenarioResolver::generate_return_path_local( 'scenario_log', $log_id );

        // 7. Add to Queue
        // We pass the tracking hash as 'prefix' in meta.
        // QueueManager will use this prefix when constructing the final From address: prefix@server_domain
        $meta = [
            'scenario_log_id' => $log_id,
            'scenario_step_id' => $step->id,
            'template_id' => $template_id,
            'isp' => $isp_key,
            'prefix' => $return_path_local // CRITICAL: This ensures From address matches the tracking ID
        ];

        // We use a placeholder From address. QueueManager will update it.
        $placeholder_email = "$from_name <{$return_path_local}@pending>";

        $queued_id = QueueManager::add(
            $server_id,
            $email,
            $placeholder_email,
            $subject,
            $meta,
            $scheduled_at
        );

        if ( $queued_id ) {
            Logger::info( "StepExecutor: Queued Step #{$step->id} for $email (Scheduled: $scheduled_at)" );
            return true;
        } else {
            Logger::error( "StepExecutor: Failed to queue Step #{$step->id}" );
            return false;
        }
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
