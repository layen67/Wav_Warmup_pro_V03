<?php

namespace PostalWarmup\Services;

use PostalWarmup\Models\Database;
use PostalWarmup\Services\Logger;
use PostalWarmup\API\Sender;
use PostalWarmup\Services\LoadBalancer;
use PostalWarmup\Services\ISPDetector;
use PostalWarmup\Models\Stats;
use PostalWarmup\Models\Strategy;
use PostalWarmup\Services\StrategyEngine;

class QueueManager {

    /**
     * Ajoute un email à la file d'attente
     */
    public static function add( $server_id, $to, $from, $subject, $meta = [] ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_queue';

        $data = [
            'server_id'    => $server_id,
            'template_id'  => $meta['template_id'] ?? null,
            'to_email'     => $to,
            'from_email'   => $from,
            'subject'      => $subject,
            'status'       => 'pending',
            'scheduled_at' => current_time( 'mysql' ), // Default: ASAP
            'created_at'   => current_time( 'mysql' ),
            'meta'         => json_encode( $meta ),
            'attempts'     => 0,
            'isp'          => 'Other' // Will be detected on process
        ];

        // Pre-detect ISP if possible to save time later
        if ( class_exists( 'PostalWarmup\Services\ISPDetector' ) ) {
            $data['isp'] = ISPDetector::detect( $to );
        }

        $result = $wpdb->insert( $table, $data );

        if ( $result ) {
            Logger::info( "Queue: Email ajouté (ID: $wpdb->insert_id)", [ 'to' => $to ] );
            return $wpdb->insert_id;
        }

        Logger::error( "Queue: Échec ajout DB", [ 'error' => $wpdb->last_error ] );
        return false;
    }

    /**
     * Traite la file d'attente (Appelé par CRON)
     */
    public static function process_queue() {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_queue';

        // 1. Load Settings
        $settings = get_option('pw_warmup_settings', []);
        $global_tz = wp_timezone_string(); // Use WP default timezone if not set in template
        $slots = !empty($settings['schedule']) ? array_map('intval', $settings['schedule']) : []; // e.g. [8,9,10,...]

        // 2. Fetch Pending Items
        $now_mysql = current_time( 'mysql' );
        // Process in batches
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE status = 'pending' AND scheduled_at <= %s LIMIT 20",
            $now_mysql
        ), ARRAY_A );

        if ( empty( $items ) ) return;

        foreach ( $items as $item ) {
            $id = $item['id'];
            $template_id = $item['template_id'];
            $isp = $item['isp'];

            // Detect ISP if missing
            if ( empty( $isp ) || $isp === 'Other' ) {
                $isp = ISPDetector::detect( $item['to_email'] );
                if ( $isp !== 'Other' ) {
                    $wpdb->update( $table, [ 'isp' => $isp ], [ 'id' => $id ] );
                    $item['isp'] = $isp;
                }
            }

            // 3. ISP & Strategy Limits Check
            if ( $isp !== 'Other' ) {
                $isp_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}postal_isps WHERE isp_key = %s", $isp ), ARRAY_A );

                if ( $isp_data ) {
                    // Strategy Enforcement
                    if ( ! empty( $isp_data['strategy_id'] ) ) {
                        $strategy = Strategy::get( $isp_data['strategy_id'] );
                        if ( $strategy ) {
                            $warmup_day = isset($item['warmup_day']) ? (int)$item['warmup_day'] : 1;

                            // Calculate Dynamic Limit
                            $dynamic_limit = StrategyEngine::calculate_daily_limit( $strategy, $warmup_day );

                            // Check Safety
                            $safety = StrategyEngine::check_safety_rules( $strategy, [
                                'sent_today' => Stats::get_isp_daily_usage( $isp ),
                                'bounces_today' => 0, // Need to implement get_bounces_today
                                'complaints_today' => 0
                            ]);

                            if ( ! $safety['allowed'] ) {
                                Logger::warning( "Queue: Strategy Block ({$safety['reason']})", [ 'item_id' => $id ] );
                                self::postpone( $id, '+4 hours' ); // Safety pause
                                continue;
                            }

                            // Use Dynamic Limit if stricter than Static
                            if ( $dynamic_limit < ($isp_data['max_daily'] > 0 ? $isp_data['max_daily'] : 999999) ) {
                                $isp_data['max_daily'] = $dynamic_limit;
                            }
                        }
                    }

                    // Hourly Limit (Still Global for now, or per server? Usually per IP but we aggregate)
                    $limit_hourly = (int) $isp_data['max_hourly'];
                    if ( $limit_hourly > 0 ) {
                        $used_hourly = Stats::get_isp_hourly_usage( $isp );
                        if ( $used_hourly >= $limit_hourly ) {
                            Logger::info( "Queue: ISP $isp Hourly Limit Reached ($used_hourly/$limit_hourly)", [ 'item_id' => $id ] );
                            self::postpone( $id, '+1 hour' );
                            continue;
                        }
                    }
                }
            }

            // 4. Timezone & Schedule Check
            $timezone = $global_tz;
            // Override with Template Timezone
            if ( $template_id ) {
                $tpl_tz = $wpdb->get_var( $wpdb->prepare( "SELECT timezone FROM {$wpdb->prefix}postal_templates WHERE id = %d", $template_id ) );
                if ( ! empty( $tpl_tz ) ) {
                    $timezone = $tpl_tz;
                }
            }

            // Check if current hour in TARGET timezone is allowed
            if ( ! empty( $slots ) ) {
                try {
                    $dt = new \DateTime( 'now', new \DateTimeZone( $timezone ) );
                    $current_hour = (int) $dt->format( 'G' );

                    if ( ! in_array( $current_hour, $slots, true ) ) {
                        // Not in allowed slots
                        self::postpone( $id, '+1 hour' );
                        continue;
                    }
                } catch ( \Exception $e ) {
                    Logger::error( "Queue: Timezone Error for item $id", [ 'error' => $e->getMessage() ] );
                }
            }

            // 5. Select Server (Checks ISP limits per server)
            $server = LoadBalancer::select_server( $template_id ?: 'default', [
                'ignore_limits' => false,
                'isp' => $isp,
                'strategy_id' => $isp_data['strategy_id'] ?? null
            ] );

            if ( ! $server ) {
                Logger::warning( "Queue: No available server for item $id (ISP limits or Capacity reached)" );
                self::postpone( $id, '+30 minutes' ); // Try again later
                continue;
            }

            // 6. Send
            $meta = json_decode( $item['meta'], true );
            $prefix = $meta['prefix'] ?? 'contact';
            $domain = $server['domain'];

            $wpdb->update( $table, [
                'status' => 'processing',
                'server_id' => $server['id'] // Update server ID to the one actually used
            ], [ 'id' => $id ] );

            // Use Sender
            $result = Sender::send( $item['to_email'], $domain, $prefix, $server );

            if ( isset( $result['success'] ) && $result['success'] ) {
                $wpdb->update( $table, [
                    'status' => 'sent',
                    'updated_at' => current_time( 'mysql' ),
                    'attempts' => $item['attempts'] + 1
                ], [ 'id' => $id ] );
            } else {
                $wpdb->update( $table, [
                    'status' => 'failed',
                    'error_message' => $result['error'] ?? 'Unknown error',
                    'updated_at' => current_time( 'mysql' ),
                    'attempts' => $item['attempts'] + 1
                ], [ 'id' => $id ] );

                // Retry logic is handled by Sender (ActionScheduler) usually,
                // but here we are in the queue processor.
                // If Sender::send was synchronous fallback or async queued successfully?
                // Sender::send returns 'queued' => true if async.
                // If 'queued', we might want to mark as 'processing' and let async job handle it?
                // But Sender::send logic is a bit mixed.
                // Assuming result['success'] means "Handed off successfully" or "Sent successfully".
            }
        }
    }

    private static function postpone( $id, $delay = '+1 hour' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_queue';
        $wpdb->update(
            $table,
            [ 'scheduled_at' => date( 'Y-m-d H:i:s', strtotime( $delay, current_time( 'timestamp' ) ) ) ],
            [ 'id' => $id ]
        );
    }

    /**
     * Nettoyage automatique
     */
    public static function cleanup() {
        global $wpdb;
        $table_queue = $wpdb->prefix . 'postal_queue';
        $table_logs = $wpdb->prefix . 'postal_logs';

        $days_queue = (int) get_option('pw_queue_retention_days', 30);
        $date_queue = date('Y-m-d H:i:s', strtotime("-$days_queue days"));

        $days_logs = (int) get_option('pw_log_retention_days', 60);
        $date_logs = date('Y-m-d H:i:s', strtotime("-$days_logs days"));

        $wpdb->query($wpdb->prepare("DELETE FROM $table_queue WHERE status IN ('sent', 'failed') AND updated_at < %s", $date_queue));
        $wpdb->query($wpdb->prepare("DELETE FROM $table_logs WHERE created_at < %s", $date_logs));

        Logger::info("Maintenance: Cleanup done.");
    }

    public static function get_health_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_queue';

        $stats = [
            'pending' => 0,
            'processing' => 0,
            'sent_24h' => 0,
            'failed_24h' => 0,
            'top_isp' => 'N/A'
        ];

        $counts = $wpdb->get_results("SELECT status, COUNT(*) as count FROM $table GROUP BY status", ARRAY_A);
        foreach ($counts as $row) {
            if (isset($stats[$row['status']])) $stats[$row['status']] = $row['count'];
        }

        $yesterday = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $sent_24 = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = 'sent' AND updated_at >= %s", $yesterday));
        $failed_24 = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = 'failed' AND updated_at >= %s", $yesterday));

        $stats['sent_24h'] = $sent_24;
        $stats['failed_24h'] = $failed_24;

        $top = $wpdb->get_var("SELECT isp FROM $table WHERE updated_at >= '$yesterday' GROUP BY isp ORDER BY COUNT(*) DESC LIMIT 1");
        if ($top) $stats['top_isp'] = $top;

        return $stats;
    }
}
