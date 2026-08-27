<?php

namespace Automattic\WP\Cron_Control\Tests;

use Automattic\WP\Cron_Control\Event;
use Automattic\WP\Cron_Control\Events;
use WP_Error;

class Transition_Failure_Events extends Events {
	private $transition_error;

	protected function class_init() {}

	public function set_transition_error( WP_Error $transition_error ): void {
		$this->transition_error = $transition_error;
	}

	protected function transition_event( Event $event ) {
		return $this->transition_error;
	}
}
