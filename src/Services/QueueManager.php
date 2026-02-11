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
use PostalWarmup\Admin\ISPManager;

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
        // Authorized hours (Global setting)
        $slots = !empty($settings['schedule']) ? array_map('intval', $settings['schedule']) : range(9, 18); // Default 9h-18h

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

            // 3. Timezone & Schedule Check
            $timezone = $global_tz;
            $start_h = 9;
            $end_h = 18;

            if ( $template_id ) {
                // Fetch allowed hours from template if defined
                $tpl_row = $wpdb->get_row( $wpdb->prepare( "SELECT timezone, allowed_start_hour, allowed_end_hour FROM {$wpdb->prefix}postal_templates WHERE id = %d", $template_id ) );
                if ( $tpl_row ) {
                    if ( ! empty( $tpl_row->timezone ) ) $timezone = $tpl_row->timezone;
                    if ( isset( $tpl_row->allowed_start_hour ) ) $start_h = (int)$tpl_row->allowed_start_hour;
                    if ( isset( $tpl_row->allowed_end_hour ) ) $end_h = (int)$tpl_row->allowed_end_hour;
                }
            }

            // Check if current hour in TARGET timezone is allowed
            try {
                $dt = new \DateTime( 'now', new \DateTimeZone( $timezone ) );
                $current_hour = (int) $dt->format( 'G' );

                if ( $current_hour < $start_h || $current_hour >= $end_h ) {
                    // Not in allowed slots
                    Logger::debug( "Queue: Item $id postponed (Hour $current_hour not allowed in $timezone [{$start_h}-{$end_h}])" );

                    // Postpone to next available slot
                    $next_run = clone $dt;
                    if ( $current_hour < $start_h ) {
                        // Today, later
                        $next_run->setTime($start_h, rand(0,59), 0);
                    } else {
                        // Tomorrow, start
                        $next_run->modify('+1 day');
                        $next_run->setTime($start_h, rand(0,59), 0);
                    }
                    $next_run->setTimezone(new \DateTimeZone('UTC')); // Convert back to UTC for DB

                    $wpdb->update( $table, [ 'scheduled_at' => $next_run->format('Y-m-d H:i:s') ], [ 'id' => $id ] );
                    continue;
                }
            } catch ( \Exception $e ) {
                Logger::error( "Queue: Timezone Error for item $id", [ 'error' => $e->getMessage() ] );
            }

            // 4. Strategy & Safety Check (Global for ISP)
            $strategy_id = null;
            if ( $isp !== 'Other' ) {
                $isp_data = ISPManager::get_by_key( $isp );

                if ( $isp_data && ! empty( $isp_data['strategy_id'] ) ) {
                    $strategy_id = $isp_data['strategy_id'];
                    // Note: Global ISP safety check could be added here if we had global aggregated fail stats easily available.
                    // For now, we rely on LoadBalancer's per-server safety check which is stricter and more accurate per IP reputation.
                }
            }

            // 5. Select Server (LoadBalancer V3)
            // This handles Per-Server ISP Quotas & Per-Server Safety
            $server = LoadBalancer::select_server( $template_id ?: 'default', [
                'ignore_limits' => false,
                'isp' => $isp,
                'strategy_id' => $strategy_id
            ] );

            if ( ! $server ) {
                Logger::warning( "Queue: No available server for item $id (Limits or Safety)", [ 'isp' => $isp, 'strategy' => $strategy_id ] );
                self::postpone( $id, '+30 minutes' ); // Try again later
                continue;
            }

            // 6. Send
            $meta = json_decode( $item['meta'], true );
            $prefix = $meta['prefix'] ?? 'contact';
            // Determine "From" address: prefix@server_domain
            $domain = $server['domain'];
            $sender_email = $prefix . '@' . $domain;

            $wpdb->update( $table, [
                'status' => 'processing',
                'server_id' => $server['id'], // Update server ID to the one actually used
                'from_email' => $sender_email // Update sender to match server
            ], [ 'id' => $id ] );

            // Logger::info("Queue: Sending item $id via {$server['domain']}", ['isp'=>$isp]);

            // Use Sender Worker directly to avoid double queuing in ActionScheduler
            $sender_service = new Sender();
            $result = $sender_service->process_queue(
                $item['to_email'],
                $domain,
                $prefix,
                $server['id']
            );

            $success = isset( $result['success'] ) && $result['success'];

            if ( $success ) {
                $wpdb->update( $table, [
                    'status' => 'sent',
                    'updated_at' => current_time( 'mysql' ),
                    'attempts' => $item['attempts'] + 1
                ], [ 'id' => $id ] );

                // Log detailed success
                Logger::info("Queue: Sent Item $id", [
                    'server' => $domain,
                    'isp' => $isp,
                    'strategy' => $strategy_id,
                    'day' => $server['lb_metrics']['warmup_day'] ?? '?'
                ]);

            } else {
                $wpdb->update( $table, [
                    'status' => 'failed',
                    'error_message' => $result['error'] ?? 'Unknown error',
                    'updated_at' => current_time( 'mysql' ),
                    'attempts' => $item['attempts'] + 1
                ], [ 'id' => $id ] );

                Logger::error("Queue: Failed Item $id", [
                    'server' => $domain,
                    'error' => $result['error'] ?? 'Unknown'
                ]);
            }

            // 7. Update V3 Stats (Tracking)
            Stats::increment_server_isp_usage( $server['id'], $isp, $success );
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

    /**
     * Récupère les infos sur la prochaine vague d'envois prévue
     */
    public static function get_next_batch_info() {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_queue';

        // Trouver la date minimale prévue dans le futur
        $next_time = $wpdb->get_var("
            SELECT MIN(scheduled_at)
            FROM $table
            WHERE status = 'pending'
            AND scheduled_at > NOW()
        ");

        if ( ! $next_time ) {
            // Si rien dans le futur, check si on a des pending "maintenant" (ou en retard)
            $pending_now = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending' AND scheduled_at <= NOW()");
            if ($pending_now > 0) {
                return [
                    'count' => $pending_now,
                    'time' => 'Maintenant',
                    'timestamp' => time(),
                    'is_now' => true
                ];
            }
            return null;
        }

        // Compter combien d'emails pour ce créneau précis (ou minute précise)
        // On groupe par minute
        $count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM $table
            WHERE status = 'pending'
            AND scheduled_at BETWEEN %s AND %s
        ", $next_time, date('Y-m-d H:i:59', strtotime($next_time))));

        return [
            'count' => $count,
            'time' => $next_time,
            'timestamp' => strtotime($next_time),
            'is_now' => false
        ];
    }
}
