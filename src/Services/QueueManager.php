<?php

namespace PostalWarmup\Services;

use PostalWarmup\Models\Database;
use PostalWarmup\Services\Logger;
use PostalWarmup\API\Sender;
use PostalWarmup\Services\LoadBalancer;
use PostalWarmup\Services\ISPDetector;
use PostalWarmup\Models\Stats;

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
            'scheduled_at' => current_time( 'mysql' ), // Default: ASAP (Cron will handle logic)
            'created_at'   => current_time( 'mysql' ),
            'meta'         => json_encode( $meta ),
            'attempts'     => 0
        ];

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

        $settings = get_option('pw_warmup_settings', []);
        $global_tz = !empty($settings['timezone']) ? $settings['timezone'] : 'UTC';
        $slots = !empty($settings['schedule']) ? array_map('intval', $settings['schedule']) : [];

        // 2. Fetch Pending Items (Safe Time)
        $now_mysql = current_time( 'mysql' );
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE status = 'pending' AND scheduled_at <= %s LIMIT 20",
            $now_mysql
        ), ARRAY_A );

        if ( empty( $items ) ) return;

        foreach ( $items as $item ) {
            // Update ISP info (if missing)
            if ( empty( $item['isp'] ) || $item['isp'] === 'Other' ) {
                $detected_isp = ISPDetector::detect( $item['to_email'] );
                if ( $detected_isp !== 'Other' ) {
                    $wpdb->update( $table, [ 'isp' => $detected_isp ], [ 'id' => $item['id'] ] );
                    $item['isp'] = $detected_isp;
                }
            }

            // 3. Check ISP Limits (From DB Manager V2)
            $isp_key = $item['isp'];
            if ( $isp_key !== 'other' ) {
                $isp_data = $wpdb->get_row( $wpdb->prepare( "SELECT max_daily, max_hourly, hour_start, hour_end, timezone FROM {$wpdb->prefix}postal_isps WHERE isp_key = %s", $isp_key ), ARRAY_A );

                if ( $isp_data ) {
                    // Check Daily
                    $limit_daily = (int) $isp_data['max_daily'];
                    if ( $limit_daily > 0 ) {
                        $usage_daily = Stats::get_isp_daily_usage( $isp_key );
                        if ( $usage_daily >= $limit_daily ) {
                            Logger::info( "Queue: Item #{$item['id']} reporté (Quota Jour ISP {$isp_key}: $usage_daily/$limit_daily)" );
                            $this->postpone( $item['id'], '+1 hour' );
                            continue;
                        }
                    }

                    // Check Hourly
                    $limit_hourly = (int) $isp_data['max_hourly'];
                    if ( $limit_hourly > 0 ) {
                        // Need a get_isp_hourly_usage func? Or approximation.
                        // Let's assume we implement get_isp_hourly_usage in Stats
                        $usage_hourly = Stats::get_isp_hourly_usage( $isp_key );
                        if ( $usage_hourly >= $limit_hourly ) {
                            Logger::info( "Queue: Item #{$item['id']} reporté (Quota Heure ISP {$isp_key}: $usage_hourly/$limit_hourly)" );
                            $this->postpone( $item['id'], '+1 hour' );
                            continue;
                        }
                    }

                    // Check ISP Time Window
                    $isp_tz = $isp_data['timezone'] ?: 'UTC';
                    try {
                        $now_isp = new \DateTime( 'now', new \DateTimeZone( $isp_tz ) );
                        $h = (int) $now_isp->format('G');
                        $start = (int) $isp_data['hour_start'];
                        $end = (int) $isp_data['hour_end'];

                        // Handle crossing midnight? Assuming simple start < end for now
                        if ( $h < $start || $h >= $end ) {
                            Logger::info( "Queue: Item #{$item['id']} reporté (Hors plage ISP {$isp_key}: {$h}h vs {$start}-{$end}h)" );
                            $this->postpone( $item['id'], '+1 hour' );
                            continue;
                        }
                    } catch ( \Exception $e ) {}
                }
            }

            // 4. Check Timezone Per Item
            // Determine effective timezone: Template > Global
            $timezone = $global_tz;
            $meta = json_decode( $item['meta'], true );

            // Try to find Template Timezone if not in meta
            // Note: Template ID in queue table is safer than meta, let's use it
            if ( ! empty( $item['template_id'] ) ) {
                $tpl_tz = $wpdb->get_var( $wpdb->prepare( "SELECT timezone FROM {$wpdb->prefix}postal_templates WHERE id = %d", $item['template_id'] ) );
                if ( ! empty( $tpl_tz ) ) {
                    $timezone = $tpl_tz;
                }
            }

            if ( ! empty( $slots ) ) {
                try {
                    $tz_string = !empty($timezone) ? $timezone : 'UTC';
                    $now_obj = new \DateTime( 'now', new \DateTimeZone( $tz_string ) );
                    $current_hour = (int) $now_obj->format( 'G' );

                    if ( ! in_array( $current_hour, $slots, true ) ) {
                        // Out of slot: Postpone 1 hour
                        Logger::info( "Queue: Item #{$item['id']} reporté (Hors créneau)", [
                            'tz' => $tz_string,
                            'hour' => $current_hour,
                            'slots' => implode(',', $slots)
                        ] );

                        $this->postpone( $item['id'], '+1 hour' );
                        continue;
                    }
                } catch ( \Exception $e ) {
                    Logger::error( "Queue: Erreur Timezone Item #{$item['id']}", [ 'msg' => $e->getMessage() ] );
                }
            }

            // 5. Smart Server Re-assignment & Quota Check
            // Context: sending mode, specific ISP
            $best_server = LoadBalancer::select_server( $item['template_id'] ?: 'default', [
                'ignore_limits' => false,
                'isp' => $item['isp']
            ]);

            if ( ! $best_server ) {
                Logger::warning( "Queue: Item #{$item['id']} reporté (Aucun serveur disponible pour ISP {$item['isp']})" );
                $this->postpone( $item['id'], '+1 hour' );
                continue;
            }

            $server_id = $best_server['id'];

            // 5. Send
            // Decode meta for template name, prefix, etc.
            $meta = json_decode( $item['meta'], true );
            $prefix = $meta['prefix'] ?? 'contact';

            // Always use the selected server's domain to prevent SPF failures
            $domain = $best_server['domain'];

            $wpdb->update( $table, [ 'status' => 'processing' ], [ 'id' => $item['id'] ] );

            // Sender::send uses Database::get_server internally if object passed, or we can pass array
            $result = Sender::send( $item['to_email'], $domain, $prefix, $best_server );

            if ( isset( $result['success'] ) && $result['success'] ) {
                $wpdb->update(
                    $table,
                    [ 'status' => 'sent', 'updated_at' => current_time( 'mysql' ) ],
                    [ 'id' => $item['id'] ]
                );
            } else {
                $wpdb->update(
                    $table,
                    [
                        'status' => 'failed',
                        'attempts' => $item['attempts'] + 1,
                        'error_message' => $result['error'] ?? 'Unknown error',
                        'updated_at' => current_time( 'mysql' )
                    ],
                    [ 'id' => $item['id'] ]
                );
            }
        }
    }

    private static function check_server_capacity( $server_id ) {
        // Retrieve Server Settings
        $server = Database::get_server( $server_id );
        if ( ! $server ) return false;

        // Use unified logic in Stats model
        $limit = \PostalWarmup\Models\Stats::get_dynamic_limit( $server );

        if ( $limit <= 0 ) return true; // Unlimited

        $used = \PostalWarmup\Models\Stats::get_server_daily_usage( $server_id );

        return $used < $limit;
    }

    /**
     * Nettoyage automatique des vieux éléments de la file d'attente
     * Appelé par CRON (pw_cleanup_queue)
     */
    public static function cleanup() {
        global $wpdb;
        $table_queue = $wpdb->prefix . 'postal_queue';
        $table_logs = $wpdb->prefix . 'postal_logs';

        // Rétention Queue : défaut 30 jours (ou option)
        $days_queue = (int) get_option('pw_queue_retention_days', 30);
        $date_queue = date('Y-m-d H:i:s', strtotime("-$days_queue days"));

        // Rétention Logs : défaut 60 jours (ou option)
        $days_logs = (int) get_option('pw_log_retention_days', 60);
        $date_logs = date('Y-m-d H:i:s', strtotime("-$days_logs days"));

        // Supprimer les éléments terminés de la queue
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_queue WHERE status IN ('sent', 'failed') AND updated_at < %s",
            $date_queue
        ));

        // Supprimer les logs
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_logs WHERE created_at < %s",
            $date_logs
        ));

        Logger::info("Maintenance: Nettoyage effectué (Queue < $date_queue, Logs < $date_logs).");
    }

    public static function get_health_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_queue';

        $stats = [
            'pending' => 0,
            'processing' => 0,
            'sent_24h' => 0,
            'failed_24h' => 0,
            'top_isp' => 'N/A',
            'top_server' => 'N/A'
        ];

        // Counts
        $counts = $wpdb->get_results("SELECT status, COUNT(*) as count FROM $table GROUP BY status", ARRAY_A);
        foreach ($counts as $row) {
            if ($row['status'] === 'pending') $stats['pending'] = $row['count'];
            if ($row['status'] === 'processing') $stats['processing'] = $row['count'];
        }

        // 24h Activity
        $yesterday = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $activity = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count FROM $table WHERE updated_at >= %s GROUP BY status",
            $yesterday
        ), ARRAY_A);
        foreach ($activity as $row) {
            if ($row['status'] === 'sent') $stats['sent_24h'] = $row['count'];
            if ($row['status'] === 'failed') $stats['failed_24h'] = $row['count'];
        }

        // Top ISP (Last 24h)
        $top_isp = $wpdb->get_var($wpdb->prepare(
            "SELECT isp FROM $table WHERE updated_at >= %s AND isp != 'Other' GROUP BY isp ORDER BY COUNT(*) DESC LIMIT 1",
            $yesterday
        ));
        if ($top_isp) $stats['top_isp'] = $top_isp;

        return $stats;
    }

    /**
     * Helper to postpone item
     */
    private function postpone( $id, $delay = '+1 hour' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'postal_queue';
        $wpdb->update(
            $table,
            [ 'scheduled_at' => date( 'Y-m-d H:i:s', strtotime( $delay, current_time( 'timestamp' ) ) ) ],
            [ 'id' => $id ]
        );
    }
}
