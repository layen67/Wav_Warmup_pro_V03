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
     * @param bool $ignore_limits Si true, ignore limites et timezone (pour affichage shortcode)
     * @return array|null Le serveur sélectionné ou null
     */
    public static function select_server($template_id_or_name, $ignore_limits = false) {
        // 1. Récupérer le template pour connaître son fuseau horaire
        $timezone = null;
        global $wpdb;
        $table_tpl = $wpdb->prefix . 'postal_templates';

        if (is_numeric($template_id_or_name)) {
            $timezone = $wpdb->get_var($wpdb->prepare("SELECT timezone FROM $table_tpl WHERE id = %d", $template_id_or_name));
        } else {
            $timezone = $wpdb->get_var($wpdb->prepare("SELECT timezone FROM $table_tpl WHERE name = %s", $template_id_or_name));
        }

        // 2. Récupérer les serveurs actifs
        $servers = Database::get_servers(true); // only active

        if (empty($servers)) {
            Logger::error('LoadBalancer: Aucun serveur actif disponible.');
            return null;
        }

        $eligible_servers = [];

        foreach ($servers as $server) {
            // Timezone check removed: Timezone controls WHEN to send (Queue), not WHICH server to use.

            // Check Daily Limit (Dynamic or Static)
            $limit = Stats::get_dynamic_limit($server);

            if ( ! $ignore_limits && $limit > 0 ) {
                $usage = Stats::get_server_daily_usage($server['id']);
                if ($usage >= $limit) {
                    // Capacity reached
                    continue;
                }
            }

            // Add usage percentage for smart balancing
            if ($limit > 0) {
                $usage = Stats::get_server_daily_usage($server['id']);
                $server['usage_pct'] = $usage / $limit;
            } else {
                $server['usage_pct'] = 0; // Unlimited/Uncalculated servers have 0 pressure (or high priority?)
            }

            $eligible_servers[] = $server;
        }

        if (empty($eligible_servers)) {
             if ( ! $ignore_limits ) {
                 Logger::warning("LoadBalancer: Aucun serveur éligible pour le template (Timezone: " . ($timezone ?: 'None') . ")");
             }
             return null;
        }

        // 3. Smart Scoring Algorithm (Load Balancing V2)
        foreach ($eligible_servers as &$server) {
            $usage_today = isset($server['sent_count']) ? (int)$server['sent_count'] : 0; // This is lifetime sent_count actually
            $usage_today = Stats::get_server_daily_usage($server['id']); // Get real daily usage

            // Retrieve reputation (placeholder for now, default 100)
            $reputation = 100;

            // Warmup step (days active)
            $warmup_step = isset($server['warmup_day']) ? (int)$server['warmup_day'] : 1;

            // ISP Usage (Not per server yet, global per ISP, but we can't filter by ISP here without destination email)
            // Load Balancer at shortcode level doesn't know destination email yet!
            // So we skip ISP specific scoring here. It will be handled in QueueManager which knows the recipient.

            // Score Formula: Lower is better
            // (Usage * 2) - (Reputation * 3) + (WarmupDay * 1)
            // Ideally we want to fill servers with higher reputation and higher warmup day capacity,
            // but balance the load (usage).

            // Adjusted Formula:
            // Base = Usage Percentage * 100 (0 to 100)
            // Priority Penalty = (100 - Priority) * 5 (High priority reduces score)

            $prio = isset($server['priority']) ? (int)$server['priority'] : 10;
            $usage_pct = isset($server['usage_pct']) ? $server['usage_pct'] * 100 : 0;

            $score = ($usage_pct * 2) + ((100 - $prio) * 5);

            $server['balancing_score'] = $score;
        }
        unset($server);

        // Sort by Score ASC
        usort($eligible_servers, function($a, $b) {
            return $a['balancing_score'] <=> $b['balancing_score'];
        });

        // Pick best (lowest score)
        return $eligible_servers[0];
    }

    /**
     * Sélectionne le meilleur serveur (alias pour compatibilité)
     */
    public static function choose_best_server($template_id) {
        return self::select_server($template_id);
    }
}
