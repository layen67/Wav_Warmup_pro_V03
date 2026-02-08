<?php

namespace PostalWarmup\Admin;

class WarmupSettings {

    public function init() {
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function register_settings() {
        register_setting( 'postal-warmup-settings', 'pw_warmup_settings', [ 'sanitize_callback' => [ $this, 'sanitize_settings' ] ] );

        add_settings_section( 'pw_warmup_strategy', __( 'Stratégie de Warmup', 'postal-warmup' ), null, 'postal-warmup-settings' );

        add_settings_field( 'pw_warmup_start', __( 'Volume de départ', 'postal-warmup' ), [ $this, 'render_start_field' ], 'postal-warmup-settings', 'pw_warmup_strategy' );
        add_settings_field( 'pw_warmup_growth', __( 'Croissance journalière (%)', 'postal-warmup' ), [ $this, 'render_growth_field' ], 'postal-warmup-settings', 'pw_warmup_strategy' );
        add_settings_field( 'pw_warmup_max_hour', __( 'Max par heure', 'postal-warmup' ), [ $this, 'render_max_hour_field' ], 'postal-warmup-settings', 'pw_warmup_strategy' );
        add_settings_field( 'pw_warmup_schedule', __( 'Créneaux horaires autorisés', 'postal-warmup' ), [ $this, 'render_schedule_field' ], 'postal-warmup-settings', 'pw_warmup_strategy' );
        add_settings_field( 'pw_global_timezone', __( 'Fuseau horaire global', 'postal-warmup' ), [ $this, 'render_timezone_field' ], 'postal-warmup-settings', 'pw_warmup_strategy' );

        // Nouvelle section : ISP Limits
        add_settings_section( 'pw_isp_limits', __( 'Limites par ISP', 'postal-warmup' ), null, 'postal-warmup-settings' );
        add_settings_field( 'pw_isp_daily_limits', __( 'Limites journalières (JSON)', 'postal-warmup' ), [ $this, 'render_isp_limits_field' ], 'postal-warmup-settings', 'pw_isp_limits' );
    }

    public function sanitize_settings( $input ) {
        $output = [];
        $output['start_volume'] = absint( $input['start_volume'] ?? 10 );
        $output['growth_rate'] = absint( $input['growth_rate'] ?? 20 );
        $output['max_per_hour'] = absint( $input['max_per_hour'] ?? 0 );
        $output['timezone'] = sanitize_text_field( $input['timezone'] ?? 'UTC' );

        if ( isset( $input['schedule'] ) && is_array( $input['schedule'] ) ) {
            $output['schedule'] = array_map( 'absint', $input['schedule'] );
        } else {
            $output['schedule'] = range( 9, 18 ); // Default 9h-18h
        }

        // Sanitize ISP Limits (JSON)
        if ( isset( $input['isp_limits'] ) ) {
            $json = json_decode( stripslashes( $input['isp_limits'] ), true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $json ) ) {
                 $output['isp_limits'] = $json;
            } else {
                 $output['isp_limits'] = []; // Invalid JSON reset
            }
        }

        return $output;
    }

    public function render_start_field() {
        $settings = get_option( 'pw_warmup_settings', [] );
        $val = $settings['start_volume'] ?? 10;
        echo '<input type="number" name="pw_warmup_settings[start_volume]" value="' . esc_attr( $val ) . '" class="small-text"> emails/jour/serveur';
    }

    public function render_growth_field() {
        $settings = get_option( 'pw_warmup_settings', [] );
        $val = $settings['growth_rate'] ?? 20;
        echo '<input type="number" name="pw_warmup_settings[growth_rate]" value="' . esc_attr( $val ) . '" class="small-text"> %';
    }

    public function render_max_hour_field() {
        $settings = get_option( 'pw_warmup_settings', [] );
        $val = $settings['max_per_hour'] ?? 0;
        echo '<input type="number" name="pw_warmup_settings[max_per_hour]" value="' . esc_attr( $val ) . '" class="small-text"> emails/heure (0 = illimité)';
    }

    public function render_isp_limits_field() {
        $settings = get_option( 'pw_warmup_settings', [] );
        $val = isset($settings['isp_limits']) ? json_encode($settings['isp_limits'], JSON_PRETTY_PRINT) : "{\n  \"Google\": 500,\n  \"Yahoo\": 200,\n  \"Microsoft\": 300\n}";
        echo '<textarea name="pw_warmup_settings[isp_limits]" rows="5" class="large-text code">' . esc_textarea( $val ) . '</textarea>';
        echo '<p class="description">Format JSON : "ISP Name": Daily Limit. 0 = Illimité.</p>';
    }

    public function render_timezone_field() {
        $settings = get_option( 'pw_warmup_settings', [] );
        $val = $settings['timezone'] ?? 'UTC';
        echo '<select name="pw_warmup_settings[timezone]">';
        foreach ( timezone_identifiers_list() as $tz ) {
            echo '<option value="' . esc_attr( $tz ) . '" ' . selected( $val, $tz, false ) . '>' . esc_html( $tz ) . '</option>';
        }
        echo '</select>';
    }

    public function render_schedule_field() {
        $settings = get_option( 'pw_warmup_settings', [] );
        $schedule = $settings['schedule'] ?? range( 9, 18 );

        echo '<div style="display: flex; flex-wrap: wrap; gap: 5px; max-width: 600px;">';
        for ( $i = 0; $i < 24; $i++ ) {
            $checked = in_array( $i, $schedule ) ? 'checked' : '';
            echo "<label style='display: inline-block; padding: 4px; border: 1px solid #ddd; border-radius: 3px;'>
                    <input type='checkbox' name='pw_warmup_settings[schedule][]' value='$i' $checked> {$i}h
                  </label>";
        }
        echo '</div>';
        echo '<p class="description">Heures durant lesquelles les envois sont autorisés (selon le fuseau horaire global).</p>';
    }
}
