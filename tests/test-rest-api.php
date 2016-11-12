<?php
/**
 * Class WPCCR_REST_API_Test
 *
 * @package WP_Cron_Control_Revisited
 */

/**
 * Sample test case.
 */
class WPCCR_REST_API_Test extends WP_Test_REST_Controller_Testcase {

	public function test_register_routes() {}

	public function test_context_param() {}

	/**
	 * Test that event-list endpoint lists events
	 */
	public function test_get_items() {
		$request = new WP_REST_Request( 'POST', WP_Cron_Control_Revisited\REST_API_NAMESPACE . '/' . WP_Cron_Control_Revisited\REST_API_ENDPOINT_LIST );
		$request->set_body_params( array( 'secret' => WP_CRON_CONTROL_SECRET, ) );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );

		$this->assertArrayHasKey( 'events', $data );
		$this->assertArrayHasKey( 'endpoint', $data );
	}

	public function test_get_item() {}

	public function test_create_item() {}

	public function test_update_item() {}

	public function test_delete_item() {}

	public function test_prepare_item() {}

	public function test_get_item_schema() {}
}
