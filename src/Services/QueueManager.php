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

            // 4. Re-evaluate Server (Load Balancing / Quota Check)
            // Even if assigned to server_id, we check if it can send.
            // Or maybe re-assign via LB?
            // For now, let's respect the assigned server but check limit.

            $server_id = $item['server_id'];

            // Check Server Limit (Strategy)
            // If limit reached, postpone item to tomorrow?
            // Or just skip for now.
            // Let's implement simple check:
            $can_send = self::check_server_capacity( $server_id );

            if ( ! $can_send ) {
                // Postpone to tomorrow same time? Or +1 hour?
                // Let's just skip this loop iteration, leaving it 'pending' for next run.
                // But if we do that, we might loop forever if limits are tight.
                // Better: Postpone 1 hour.
                $wpdb->update(
                    $table,
                    [ 'scheduled_at' => date( 'Y-m-d H:i:s', strtotime( '+1 hour' ) ) ],
                    [ 'id' => $item['id'] ]
                );
                continue;
            }

            // 4. Send
            // Decode meta for template name, prefix, etc.
            $meta = json_decode( $item['meta'], true );
            $domain = $meta['domain'] ?? ''; // Need domain
            $prefix = $meta['prefix'] ?? 'contact';

            // If domain missing in meta, get from server_id (expensive query inside loop, but safer)
            if ( empty( $domain ) ) {
                $server = Database::get_server( $server_id );
                $domain = $server['domain'];
            }

            $wpdb->update( $table, [ 'status' => 'processing' ], [ 'id' => $item['id'] ] );

            $result = Sender::send( $item['to_email'], $domain, $prefix, Database::get_server( $server_id ) );

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

        $limit = (int) $server['daily_limit'];

        // Strategy: "Warmup Day" Logic
        // If daily_limit is 0 (unlimited), check global strategy?
        // User wants "Volume de départ" + "Croissance".
        // Let's assume if daily_limit is set in Server Config, it overrides strategy.
        // If daily_limit is 0, we use strategy?
        // Actually, the new columns "daily_limit" I added are meant to be the *current* limit.
        // The "Strategy" Cron will likely update this `daily_limit` value every night.
        // So here, we just check `daily_limit`.

        if ( $limit <= 0 ) return true; // Unlimited

        $used = \PostalWarmup\Models\Stats::get_server_daily_usage( $server_id );

        return $used < $limit;
    }
}
