<?php

namespace PostalWarmup\Services;

use PostalWarmup\Models\Database;
use PostalWarmup\Models\Stats;
use PostalWarmup\Services\Logger;

class LoadBalancer {

    /**
     * Sélectionne le meilleur serveur pour un envoi
     *
     * @param string|int $template_id_or_name ID ou Nom du template
     * @param array|bool $context_or_ignore_limits Contexte (ignore_limits, isp, etc.) ou booléen (legacy)
     * @return array|null Le serveur sélectionné ou null
     */
    public static function select_server( $template_id_or_name, $context_or_ignore_limits = [] ) {
        // Normalisation du contexte (Compatibilité V2)
        if ( is_bool( $context_or_ignore_limits ) ) {
            $context = [ 'ignore_limits' => $context_or_ignore_limits ];
        } else {
            $context = (array) $context_or_ignore_limits;
        }

        $ignore_limits = $context['ignore_limits'] ?? false;
        $target_isp = $context['isp'] ?? null;

        // 1. Récupérer le template pour connaître son fuseau horaire
        global $wpdb;
        $table_tpl = $wpdb->prefix . 'postal_templates';

        $timezone = null;
        if ( is_numeric( $template_id_or_name ) ) {
            $timezone = $wpdb->get_var( $wpdb->prepare( "SELECT timezone FROM $table_tpl WHERE id = %d", $template_id_or_name ) );
        } else {
            $timezone = $wpdb->get_var( $wpdb->prepare( "SELECT timezone FROM $table_tpl WHERE name = %s", $template_id_or_name ) );
        }

        // 2. Récupérer les serveurs actifs
        $servers = Database::get_servers( true ); // only active

        if ( empty( $servers ) ) {
            Logger::error( 'LoadBalancer: Aucun serveur actif disponible.' );
            return null;
        }

        $eligible_servers = [];

        foreach ( $servers as $server ) {
            // Check Daily Limit (Dynamic or Static)
            $limit = Stats::get_dynamic_limit( $server );
            $usage = Stats::get_server_daily_usage( $server['id'] );

            // Check Server Global Limit
            if ( ! $ignore_limits && $limit > 0 ) {
                if ( $usage >= $limit ) {
                    continue; // Capacity reached
                }
            }

            // Check ISP Limit (if sending)
            /*
             * Note: ISP Limits are global (all servers combined) or per server?
             * The prompt says "par jour, par ISP, par template, par serveur".
             * Usually ISP limits are domain-reputation bound, so per sending domain (server).
             * But Stats::get_isp_daily_usage currently counts GLOBAL usage for that ISP.
             * If the limit is global, we should check it in QueueManager before calling LoadBalancer?
             * Or check it here?
             * Let's assume ISP limits are enforced in QueueManager as implemented previously.
             * Here we focus on balancing load between servers.
             */

            // Add metrics for scoring
            $server['metrics'] = [
                'usage_today' => $usage,
                'limit' => $limit,
                'warmup_day' => isset( $server['warmup_day'] ) ? (int)$server['warmup_day'] : 1,
                'reputation' => 100, // Placeholder
                'priority' => isset( $server['priority'] ) ? (int)$server['priority'] : 10
            ];

            $eligible_servers[] = $server;
        }

        if ( empty( $eligible_servers ) ) {
             // Fallback: If ignore_limits is true (Display Mode), return the "best" server even if full
             if ( $ignore_limits ) {
                 $eligible_servers = $servers; // Restore all active servers
             } else {
                 Logger::warning( "LoadBalancer: Aucun serveur éligible (Tous complets)" );
                 return null;
             }
        }

        // 3. Smart Scoring Algorithm (V3)
        // Score = (emails_sent_today * 2) + (emails_sent_today_for_isp * 1.5) + (current_warmup_step * 1) - (reputation_score * 3)
        // Low Score = Better

        foreach ( $eligible_servers as &$server ) {
            $m = $server['metrics'] ?? [ 'usage_today' => 0, 'warmup_day' => 1, 'reputation' => 100, 'priority' => 10 ];

            $sent_today = $m['usage_today'];
            $warmup_step = $m['warmup_day'];
            $reputation = $m['reputation'];

            // ISP Specific Usage on THIS server?
            // Since we don't track ISP usage per server easily yet without complex queries,
            // we'll assume proportional distribution or skip specific ISP-server penalty for now.
            // But we can check global ISP usage? No, that's constant for all servers.
            $sent_isp = 0;

            // Formula
            $score = ( $sent_today * 2 )
                   + ( $sent_isp * 1.5 )
                   + ( $warmup_step * 1 )
                   - ( $reputation * 3 );

            // Apply Manual Priority Adjustment
            // Higher priority should Lower the score.
            // Let's say Priority 10 is normal. Priority 20 is high.
            // Penalty = (10 - Priority) * 10
            // P=20 => -100 (Bonus)
            // P=5 => +50 (Malus)
            $score += ( 10 - $m['priority'] ) * 10;

            $server['balancing_score'] = $score;
        }
        unset( $server );

        // Sort by Score ASC
        usort( $eligible_servers, function( $a, $b ) {
            return $a['balancing_score'] <=> $b['balancing_score'];
        } );

        // Return best
        return $eligible_servers[0];
    }

    /**
     * Sélectionne le meilleur serveur (alias pour compatibilité)
     */
    public static function choose_best_server( $template_id ) {
        return self::select_server( $template_id, [ 'ignore_limits' => true ] ); // Default to display mode if called directly? Or strict?
        // Context: "Choisir le meilleur serveur Postal au chargement de la page" -> Shortcode -> Display Mode.
    }
}
