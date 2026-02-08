<?php

namespace PostalWarmup\Services;

class ISPDetector {

    /**
     * Liste des regex pour détecter les ISP
     */
    private static $isp_rules = [
        'Google' => '/@(gmail|googlemail|google)\./i',
        'Yahoo' => '/@(yahoo|ymail|rocketmail)\./i',
        'Microsoft' => '/@(outlook|hotmail|live|msn|windowslive)\./i',
        'Apple' => '/@(icloud|me|mac)\./i',
        'AOL' => '/@(aol|aim)\./i',
        'Yandex' => '/@(yandex|ya)\./i',
        'Proton' => '/@(protonmail|proton|pm)\./i',
        'Zoho' => '/@(zoho|zohomail)\./i',
        'GMX' => '/@(gmx|mail\.com)\./i',
        'Orange' => '/@(orange|wanadoo)\./i',
        'SFR' => '/@(sfr|neuf|club-internet)\./i',
        'Free' => '/@(free|aliceadsl)\./i',
        'T-Online' => '/@(t-online)\./i',
        'Mail.ru' => '/@(mail|list|in|bk)\.ru/i',
        'Libero' => '/@(libero|inwind|iol)\.it/i',
    ];

    /**
     * Détecte l'ISP à partir d'une adresse email
     *
     * @param string $email
     * @return string Nom de l'ISP ou 'Other'
     */
    public static function detect( string $email ): string {
        $email = strtolower( trim( $email ) );

        // Vérifier les règles par défaut
        foreach ( self::$isp_rules as $isp => $regex ) {
            if ( preg_match( $regex, $email ) ) {
                return $isp;
            }
        }

        // Vérifier les règles personnalisées (Depuis DB)
        global $wpdb;
        $table = $wpdb->prefix . 'postal_isps';
        $custom_rules = $wpdb->get_results( "SELECT name, regex FROM $table", ARRAY_A );

        if ( ! empty( $custom_rules ) ) {
            foreach ( $custom_rules as $rule ) {
                if ( ! empty( $rule['name'] ) && ! empty( $rule['regex'] ) ) {
                    // Sécuriser la regex (on suppose qu'elle est stockée sans délimiteurs ou avec)
                    // Pour simplifier, on enlève les délimiteurs potentiels et on ajoute /i
                    $pattern = trim( $rule['regex'], '/' );
                    if ( @preg_match( '/' . $pattern . '/i', $email ) ) {
                        return $rule['name'];
                    }
                }
            }
        }

        return 'Other';
    }

    /**
     * Retourne la liste des ISP connus
     */
    public static function get_known_isps(): array {
        $isps = array_keys( self::$isp_rules );
        $custom = get_option( 'pw_custom_isp_rules', [] );
        if ( ! empty( $custom ) ) {
            foreach ( $custom as $rule ) {
                $isps[] = $rule['name'];
            }
        }
        return array_unique( $isps );
    }
}
