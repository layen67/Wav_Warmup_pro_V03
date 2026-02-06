<?php

namespace PostalWarmup\Services;

use PostalWarmup\Models\Database;
use PostalWarmup\Services\Logger;
use PostalWarmup\API\Sender;
use PostalWarmup\Services\LoadBalancer;

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
        $global_tz = $settings['timezone'] ?? 'UTC';
        $slots = $settings['schedule'] ?? [];

        // 2. Fetch Pending Items (Safe Time)
        $now_mysql = current_time( 'mysql' );
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE status = 'pending' AND scheduled_at <= %s LIMIT 20",
            $now_mysql
        ), ARRAY_A );

        if ( empty( $items ) ) return;

        foreach ( $items as $item ) {
            // 3. Check Timezone Per Item
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
                    $now_obj = new \DateTime( 'now', new \DateTimeZone( $timezone ) );
                    $current_hour = (int) $now_obj->format( 'G' );
                    if ( ! in_array( $current_hour, $slots ) ) {
                        // Out of slot: Postpone 1 hour
                        $wpdb->update(
                            $table,
                            [ 'scheduled_at' => date( 'Y-m-d H:i:s', strtotime( '+1 hour', current_time( 'timestamp' ) ) ) ],
                            [ 'id' => $item['id'] ]
                        );
                        continue;
                    }
                } catch ( \Exception $e ) {
                    Logger::error( "Queue: Erreur Timezone Item #{$item['id']}", [ 'msg' => $e->getMessage() ] );
                }
            }

            // 4. Smart Server Re-assignment & Quota Check
            // We re-run LoadBalancer to find the BEST current server, potentially overriding the original one.
            // This ensures "Dynamic choice per email".
            $best_server = LoadBalancer::select_server( $item['template_id'] ?: 'default' );

            if ( ! $best_server ) {
                // No server available at all (all limits reached or timezones mismatch)
                // Postpone 1 hour
                $wpdb->update(
                    $table,
                    [ 'scheduled_at' => date( 'Y-m-d H:i:s', strtotime( '+1 hour' ) ) ],
                    [ 'id' => $item['id'] ]
                );
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
}
