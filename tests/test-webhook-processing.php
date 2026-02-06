<?php
/**
 * Tests pour le traitement des webhooks Postal
 */

class Test_Webhook_Processing extends WP_UnitTestCase {

    private $handler;

    public function setUp(): void {
        parent::setUp();
        $this->handler = new PW_Webhook_Handler();
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'postal_servers', ['domain' => 'example.com', 'api_url' => 'https://postal.example.com', 'api_key' => 'test-key', 'active' => 1]);
    }

    public function test_handle_delivered_event() {
        $payload = ['event' => 'MessageDelivered', 'payload' => ['message' => ['from' => 'support@example.com', 'to' => 'user@gmail.com', 'id' => 'msg-123']]];
        $request = new WP_REST_Request('POST', '/postal-warmup/v1/webhook');
        $request->set_body(json_encode($payload));
        $request->set_header('Content-Type', 'application/json');
        $response = $this->handler->handle_webhook($request);
        $this->assertEquals(200, $response->get_status());
        global $wpdb;
        $table = $wpdb->prefix . 'postal_metrics';
        $metric = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE event_type = %s", 'delivered'));
        $this->assertNotNull($metric);
    }
}
