<?php

namespace PostalWarmup\Services;

use PostalWarmup\Models\Database;
use PostalWarmup\Models\Stats;
use PostalWarmup\Services\Logger;
use PostalWarmup\Models\Strategy;
use PostalWarmup\Services\StrategyEngine;
use PostalWarmup\Admin\ISPManager;
use PostalWarmup\Services\TemplateLoader;

class LoadBalancer {

    /**
     * Sélectionne le meilleur serveur pour un envoi (Algorithme V3)
     *
     * @param string|int $template_id_or_name ID ou Nom du template
     * @param array|bool $context_or_ignore_limits Contexte (ignore_limits, isp, etc.) ou booléen (legacy)
     * @return array|null Le serveur sélectionné ou null
     */
    public static function select_server( $template_id_or_name, $context_or_ignore_limits = [] ) {
        // Normalisation du contexte
        if ( is_bool( $context_or_ignore_limits ) ) {
            $context = [ 'ignore_limits' => $context_or_ignore_limits ];
        } else {
            $context = (array) $context_or_ignore_limits;
        }

        $ignore_limits = $context['ignore_limits'] ?? false;
        $target_isp = $context['isp'] ?? null;
        $strategy_id = $context['strategy_id'] ?? null;

        // 0. Time Schedule & Timezone Check (Template Level)
        if ( ! $ignore_limits ) {
            $template = TemplateLoader::load( $template_id_or_name );

            if ( $template ) {
                // Use Template Timezone or fallback to WP Site Timezone (better for user experience than UTC)
                $timezone = ! empty( $template['timezone'] ) ? $template['timezone'] : wp_timezone_string();
                if ( empty( $timezone ) ) $timezone = 'UTC';

                try {
                    $dt = new \DateTime( 'now', new \DateTimeZone( $timezone ) );
                    $current_hour = (int) $dt->format( 'G' ); // 0-23

                    // Defaults to 9-18 if not set (Safe Warmup Practice)
                    $start_hour = isset( $template['allowed_start_hour'] ) ? (int) $template['allowed_start_hour'] : 9;
                    $end_hour = isset( $template['allowed_end_hour'] ) ? (int) $template['allowed_end_hour'] : 18;

                    $is_allowed = false;

                    if ( $start_hour == $end_hour ) {
                        // If start == end, assume 24h allowed (or user can disable template)
                        $is_allowed = true;
                    } elseif ( $start_hour < $end_hour ) {
                        // Ex: 9 to 18 (9:00 -> 17:59)
                        $is_allowed = ( $current_hour >= $start_hour && $current_hour < $end_hour );
                    } else {
                        // Ex: 22 to 6 (22:00 -> 05:59)
                        $is_allowed = ( $current_hour >= $start_hour || $current_hour < $end_hour );
                    }

                    if ( ! $is_allowed ) {
                        // Only log if we are blocking
                        Logger::info( "LoadBalancer: Template '{$template['name']}' restricted by schedule ($start_hour-$end_hour in $timezone, current $current_hour). Skipping." );
                        return null;
                    }

                } catch ( \Exception $e ) {
                    Logger::error( "LoadBalancer: Timezone error - " . $e->getMessage() );
                }
            }
        }

        // Auto-resolve Strategy if ISP is provided but Strategy ID is not
        if ( $target_isp && ! $strategy_id ) {
             $isp_data = ISPManager::get_by_key( $target_isp );
             if ( $isp_data && ! empty( $isp_data['strategy_id'] ) ) {
                 $strategy_id = (int) $isp_data['strategy_id'];
             }
        }

        // 1. Récupérer les serveurs actifs
        $servers = Database::get_servers( true ); // active = 1

        if ( empty( $servers ) ) {
            Logger::error( 'LoadBalancer: Aucun serveur actif disponible.' );
            return null;
        }

        $eligible_servers = [];
        $full_servers = []; // Fallback pour mode ignore_limits

        foreach ( $servers as $server ) {
            $server_id = (int) $server['id'];

            // 1. Global Metrics
            $global_limit = Stats::get_dynamic_limit( $server );
            $global_usage = Stats::get_server_daily_usage( $server_id );
            $global_usage_pct = $global_limit > 0 ? ( $global_usage / $global_limit ) * 100 : 0;
            if ( $global_usage_pct > 100 ) $global_usage_pct = 100;

            // 2. ISP Specific Metrics & Safety
            $isp_stats = $target_isp ? Stats::get_server_isp_stats( $server_id, $target_isp ) : null;

            // Default values if no ISP context
            $warmup_day = $isp_stats ? (int) $isp_stats->warmup_day : ( (int) ($server['warmup_day'] ?? 1) );
            $reputation = $isp_stats ? (int) $isp_stats->score : 100;
            $isp_sent_today = $isp_stats ? (int) $isp_stats->sent_today : 0;

            $isp_limit = 0;
            $isp_usage_pct = 0;
            $isp_limit_reached = false;
            $safety_blocked = false;

            if ( $strategy_id ) {
                $strategy = Strategy::get( $strategy_id );
                if ( $strategy ) {
                    // Update V3: Pass target_isp to handle Per-ISP Overrides
                    $isp_limit = StrategyEngine::calculate_daily_limit( $strategy, $warmup_day, $target_isp );
                    $isp_usage_pct = $isp_limit > 0 ? ( $isp_sent_today / $isp_limit ) * 100 : 0;
                    if ( $isp_usage_pct > 100 ) $isp_usage_pct = 100;

                    if ( $isp_limit > 0 && $isp_sent_today >= $isp_limit ) {
                        $isp_limit_reached = true;
                    }

                    // V3 Safety Check: Per Server
                    if ( $isp_stats ) {
                        // Cast object to array for StrategyEngine
                        $stats_arr = [
                            'sent_today' => $isp_stats->sent_today,
                            'fails_today' => $isp_stats->fails_today
                        ];
                        $safety = StrategyEngine::check_safety_rules( $strategy, $stats_arr );
                        if ( ! $safety['allowed'] ) {
                            $safety_blocked = true;
                            if ( ! $ignore_limits ) {
                                Logger::debug( "LoadBalancer: Server {$server['domain']} excluded by safety rules for $target_isp", [ 'reason' => $safety['reason'] ] );
                            }
                        }
                    }
                }
            } else {
                // If no strategy, use global usage as proxy for ISP usage
                $isp_usage_pct = $global_usage_pct;
            }

            // Check Full
            $is_full_global = ( $global_limit > 0 && $global_usage >= $global_limit );
            $is_full = $is_full_global || $isp_limit_reached || $safety_blocked;

            // Prepare Metrics for Score
            $server['lb_metrics'] = [
                'usage_pct' => $global_usage_pct,
                'isp_usage_pct' => $isp_usage_pct,
                'warmup_day' => $warmup_day,
                'reputation' => $reputation,
                'isp_limit' => $isp_limit,
                'isp_usage' => $isp_sent_today
            ];

            // Filter logic
            if ( $is_full ) {
                $full_servers[] = $server;
                if ( ! $ignore_limits ) {
                    continue; // Skip in strict mode
                }
            }

            $eligible_servers[] = $server;
        }

        // Cas : Tous complets
        if ( empty( $eligible_servers ) ) {
            if ( $ignore_limits && ! empty( $full_servers ) ) {
                // Fallback display mode: pick from full servers
                $eligible_servers = $full_servers;
            } else {
                Logger::warning( "LoadBalancer: Tous les serveurs sont complets (Global/ISP limit/Safety)." );
                return null;
            }
        }

        // 3. Scoring V3
        // Formula: Score = (Usage server * 2) + (Quota ISP used * 1.5) + (Warmup Day * 1) - (Reputation * 3)
        // Lower Score = Better
        foreach ( $eligible_servers as &$server ) {
            $m = $server['lb_metrics'];

            $score = ( $m['usage_pct'] * 2 )
                   + ( $m['isp_usage_pct'] * 1.5 )
                   + ( $m['warmup_day'] * 1 )
                   - ( $m['reputation'] * 3 );

            $server['balancing_score'] = $score;
        }
        unset( $server );

        // Tri ASC (Lowest score first)
        usort( $eligible_servers, function( $a, $b ) {
            return $a['balancing_score'] <=> $b['balancing_score'];
        } );

        // Retourner le meilleur
        $selected = $eligible_servers[0];

        // Debug Log (only if strict mode)
        if ( ! $ignore_limits ) {
            Logger::debug( "LoadBalancer V3: Selected {$selected['domain']}", [
                'isp' => $target_isp,
                'score' => round($selected['balancing_score'], 1),
                'metrics' => $selected['lb_metrics']
            ] );
        }

        return $selected;
    }

    /**
     * Alias pour compatibilité
     */
    public static function choose_best_server( $template_id ) {
        return self::select_server( $template_id, [ 'ignore_limits' => true ] );
    }
}
