<?php

namespace PostalWarmup\Modules\ScenarioEngine;

use PostalWarmup\Services\Logger;

class PostalReplyAdapter {

    /**
     * Handles incoming Postal webhook payloads (specifically 'message.received').
     *
     * @param array $payload The JSON decoded payload from Postal
     * @return bool Processed status
     */
    public static function handle( $payload ) {
        if ( ! isset( $payload['message'] ) ) {
            return false;
        }

        $message = $payload['message'];
        $to_address = $message['to'][0]['address'] ?? ''; // The unique address we generated
        $from_address = $message['from']['address'] ?? ''; // The user replying
        $body = $message['plain_body'] ?? ($message['html_body'] ?? '');

        if ( empty( $to_address ) || empty( $from_address ) ) {
            Logger::warning( "PostalReplyAdapter: Missing To/From in payload." );
            return false;
        }

        Logger::info( "PostalReplyAdapter: Received reply from $from_address to $to_address" );

        // 1. Resolve Context
        $context = ScenarioResolver::resolve( $to_address );

        if ( ! $context ) {
            Logger::debug( "PostalReplyAdapter: Could not resolve context for $to_address. Ignoring." );
            return false;
        }

        // 2. Delegate to Engine
        if ( $context['type'] === 'scenario_log' ) {
            return ScenarioEngine::handle_reply( $context['id'], $body );
        }

        return true;
    }
}
