<?php

namespace PostalWarmup\Services;

use PostalWarmup\Models\Database;
use PostalWarmup\Models\Stats;
use PostalWarmup\Services\Logger;
use PostalWarmup\Models\Strategy;
use PostalWarmup\Services\StrategyEngine;

class LoadBalancer {

    /**
     * Sélectionne le meilleur serveur pour un envoi
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

        // 1. Récupérer les serveurs actifs
        $servers = Database::get_servers( true ); // active = 1

        if ( empty( $servers ) ) {
            Logger::error( 'LoadBalancer: Aucun serveur actif disponible.' );
            return null;
        }

        $eligible_servers = [];
        $full_servers = []; // For fallback in ignore_limits mode

        foreach ( $servers as $server ) {
            $server_id = (int) $server['id'];

            // 1. Global Server Limit
            $limit = Stats::get_dynamic_limit( $server );
            $usage = Stats::get_server_daily_usage( $server_id );

            // 2. ISP Specific Limit (via Strategy)
            $isp_limit_reached = false;
            $isp_usage = 0;
            $isp_limit = 0;

            if ( $strategy_id && $target_isp ) {
                $strategy = Strategy::get( $strategy_id );
                if ( $strategy ) {
                    $warmup_day = isset( $server['warmup_day'] ) ? (int)$server['warmup_day'] : 1;
                    $isp_limit = StrategyEngine::calculate_daily_limit( $strategy, $warmup_day );
                    $isp_usage = Stats::get_server_isp_daily_usage( $server_id, $target_isp );

                    if ( $isp_limit > 0 && $isp_usage >= $isp_limit ) {
                        $isp_limit_reached = true;
                    }
                }
            }

            // Metrics
            $remaining = $limit > 0 ? max( 0, $limit - $usage ) : 999999;
            $usage_percent = $limit > 0 ? ( $usage / $limit ) * 100 : 0;

            $server['metrics'] = [
                'usage' => $usage,
                'limit' => $limit,
                'isp_usage' => $isp_usage,
                'isp_limit' => $isp_limit,
                'remaining' => $remaining,
                'usage_percent' => $usage_percent,
                'priority' => (int) ( $server['priority'] ?? 10 )
            ];

            // Vérification capacité (Globale & ISP)
            $is_full = ( $limit > 0 && $usage >= $limit ) || $isp_limit_reached;

            if ( $is_full ) {
                $full_servers[] = $server;
                if ( ! $ignore_limits ) {
                    continue; // Skip if full and strict mode
                }
            }

            $eligible_servers[] = $server;
        }

        // Cas : Tous complets
        if ( empty( $eligible_servers ) ) {
            if ( $ignore_limits && ! empty( $full_servers ) ) {
                // En mode affichage (ignore_limits), on prend les serveurs complets si rien d'autre
                $eligible_servers = $full_servers;
            } else {
                Logger::warning( "LoadBalancer: Tous les serveurs sont complets." );
                return null;
            }
        }

        // 2. Calcul du score et tri
        // Score plus bas = Meilleur
        foreach ( $eligible_servers as &$server ) {
            $m = $server['metrics'];

            // Formule de scoring :
            // Base : Usage % (0 à 100)
            // Priorité : Soustraire (Priorité * 5). Priorité 10 => -50. Priorité 1 => -5.
            // Résultat : Un serveur vide (0%) et haute prio (10) aura -50.
            // Un serveur plein (90%) et basse prio (1) aura 85.

            $score = $m['usage_percent'] - ( $m['priority'] * 5 );

            // Bonus pour "Remaining Capacity" brute ?
            // Si deux serveurs ont 50%, mais l'un a 1000 restants et l'autre 100.
            // On préfère celui avec plus de marge.
            // On soustrait log(remaining) pour donner un léger avantage
            if ( $m['remaining'] > 0 ) {
                $score -= log( $m['remaining'] );
            }

            $server['balancing_score'] = $score;
        }
        unset( $server );

        // Tri ASC
        usort( $eligible_servers, function( $a, $b ) {
            return $a['balancing_score'] <=> $b['balancing_score'];
        } );

        // Retourner le meilleur
        $selected = $eligible_servers[0];

        // Debug Log (only if strict mode to avoid spamming on display)
        if ( ! $ignore_limits ) {
            Logger::debug( "LoadBalancer: Serveur choisi ID {$selected['id']} ({$selected['domain']})", [
                'score' => round($selected['balancing_score'], 2),
                'usage' => $selected['metrics']['usage'] . '/' . $selected['metrics']['limit'],
                'prio'  => $selected['metrics']['priority']
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
