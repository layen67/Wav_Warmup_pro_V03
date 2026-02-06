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
     * @return array|null Le serveur sélectionné ou null
     */
    public static function select_server($template_id_or_name) {
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
            // Check Timezone match (if template has strict timezone)
            if (!empty($timezone)) {
                if (empty($server['timezone']) || $server['timezone'] !== $timezone) {
                    continue; // Skip mismatch
                }
            }

            // Check Daily Limit
            $daily_limit = isset($server['daily_limit']) ? (int)$server['daily_limit'] : 0;
            if ($daily_limit > 0) {
                $usage = Stats::get_server_daily_usage($server['id']);
                if ($usage >= $daily_limit) {
                    // Capacity reached
                    continue;
                }
            }

            $eligible_servers[] = $server;
        }

        if (empty($eligible_servers)) {
             Logger::warning("LoadBalancer: Aucun serveur éligible pour le template (Timezone: " . ($timezone ?: 'None') . ")");
             return null;
        }

        // 3. Sort by Priority (DESC)
        usort($eligible_servers, function($a, $b) {
            // Primary: Priority (High to Low)
            $prio_a = isset($a['priority']) ? (int)$a['priority'] : 10;
            $prio_b = isset($b['priority']) ? (int)$b['priority'] : 10;

            if ($prio_a !== $prio_b) {
                return $prio_b - $prio_a;
            }

            return 0;
        });

        // Filter top priority group
        $top_server = $eligible_servers[0];
        $top_priority = isset($top_server['priority']) ? (int)$top_server['priority'] : 10;

        $top_tier = array_filter($eligible_servers, function($s) use ($top_priority) {
            $p = isset($s['priority']) ? (int)$s['priority'] : 10;
            return $p === $top_priority;
        });

        // Pick random from top tier (Round Robin simulation)
        return $top_tier[array_rand($top_tier)];
    }
}
