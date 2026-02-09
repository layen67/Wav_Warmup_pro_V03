<?php

namespace PostalWarmup\Services;

class StrategyEngine {

    /**
     * Calcule la limite journalière pour un jour donné de la stratégie
     */
    public static function calculate_daily_limit( $strategy, $day ) {
        $config = $strategy['config'] ?? [];
        $start = (int) ($config['start_volume'] ?? 10);
        $max = (int) ($config['max_volume'] ?? 1000);
        $type = $config['growth_type'] ?? 'linear';
        $value = (float) ($config['growth_value'] ?? 10); // +10 or +10%

        if ( $day <= 1 ) return $start;

        $limit = $start;

        if ( $type === 'mixed' ) {
            // J1-J5: +30%, J6-J10: +50%, J11-20: +70%, >J20: x2
            // Complex Loop Calculation
            for ( $i = 2; $i <= $day; $i++ ) {
                if ( $i <= 5 ) $factor = 1.30;
                elseif ( $i <= 10 ) $factor = 1.50;
                elseif ( $i <= 20 ) $factor = 1.70;
                else $factor = 2.00;

                $limit = ceil( $limit * $factor );
                if ( $limit >= $max ) { $limit = $max; break; }
            }
        } elseif ( $type === 'exponential' ) {
            // Limit = Start * (1 + Growth%)^(Day-1)
            $factor = 1 + ( $value / 100 );
            $limit = floor( $start * pow( $factor, $day - 1 ) );
        } else {
            // Linear: Start + (Day-1)*Value
            $limit = $start + ( ($day - 1) * $value );
        }

        return min( $limit, $max );
    }

    /**
     * Vérifie les règles de sécurité (bounces, complaints)
     * Retourne [ 'allowed' => bool, 'reason' => string, 'action' => 'pause'|'reduce'|'stop' ]
     */
    public static function check_safety_rules( $strategy, $stats ) {
        $config = $strategy['config'] ?? [];
        $rules = $config['safety_rules'] ?? [];

        // Defaults if not set
        $max_hard_bounce = $rules['max_hard_bounce'] ?? 2.0; // %
        $max_complaint = $rules['max_complaint'] ?? 0.1; // %

        $sent = $stats['sent_today'] ?? 0;
        if ( $sent < 10 ) return [ 'allowed' => true ]; // Not enough data

        // Hard Bounces
        $bounces = $stats['bounces_today'] ?? 0;
        $bounce_rate = ($bounces / $sent) * 100;

        if ( $bounce_rate > 5.0 ) {
            return [ 'allowed' => false, 'reason' => "Hard Bounce Rate Critical ($bounce_rate%)", 'action' => 'reduce_30' ];
        }
        if ( $bounce_rate > $max_hard_bounce ) {
            return [ 'allowed' => false, 'reason' => "Hard Bounce Rate High ($bounce_rate%)", 'action' => 'pause_24h' ];
        }

        // Complaints
        $complaints = $stats['complaints_today'] ?? 0;
        $complaint_rate = ($complaints / $sent) * 100;

        if ( $complaint_rate > 0.3 ) {
            return [ 'allowed' => false, 'reason' => "Spam Complaint Rate Critical ($complaint_rate%)", 'action' => 'stop_immediate' ];
        }
        if ( $complaint_rate > $max_complaint ) {
            return [ 'allowed' => false, 'reason' => "Spam Complaint Rate High ($complaint_rate%)", 'action' => 'reduce_growth' ];
        }

        return [ 'allowed' => true ];
    }

    /**
     * Vérifie si l'heure actuelle est autorisée par la stratégie
     * (Optionnel: Si la stratégie force une plage spécifique)
     */
    public static function is_time_allowed( $strategy, $current_hour, $template_timezone ) {
        $config = $strategy['config'] ?? [];

        // Check "Force Strategy Schedule" option
        if ( ! empty( $config['force_schedule'] ) && ! empty( $config['schedule_hours'] ) ) {
            // Use strategy specific hours
            return in_array( $current_hour, $config['schedule_hours'] );
        }

        // Otherwise, rely on QueueManager's global/template check (return true here to not block)
        return true;
    }
}
