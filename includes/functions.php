<?php

namespace Automattic\WP\Cron_Control;

/**
 * Check if an event is an internal one that the plugin will always run
 */
function is_internal_event( $action ) {
	return Internal_Events::instance()->is_internal_event( $action );
}

/**
 * Check if the current request is to one of the plugin's REST endpoints
 *
 * @param string $type list|run
 *
 * @return bool
 */
function is_rest_endpoint_request( $type = 'list' ) {
	// Which endpoint are we checking
	$endpoint = null;
	switch ( $type ) {
		case 'list' :
			$endpoint = REST_API::ENDPOINT_LIST;
			break;

		case 'run' :
			$endpoint = REST_API::ENDPOINT_RUN;
			break;
	}

	// No endpoint to check
	if ( is_null( $endpoint ) ) {
		return false;
	}

	// Build the full endpoint and check against the current request
	$run_endpoint = sprintf( '%s/%s/%s', rest_get_url_prefix(), REST_API::API_NAMESPACE, $endpoint );

	return in_array( $run_endpoint, parse_request() );
}

/**
 * Flush plugin's internal caches
 *
 * FOR INTERNAL USE ONLY - see WP-CLI; all other cache clearance should happen through the `Events_Store` class
 */
function _flush_internal_caches() {
	return wp_cache_delete( Events_Store::CACHE_KEY );
}

/**
 * Schedule an event directly, bypassing the plugin's filtering to capture Core's scheduling functions
 *
 * @param int      $timestamp Time event should run
 * @param string   $action    Hook to fire
 * @param array    $args      Array of arguments, such as recurrence and parameters to pass to hook callback
 * @param int|null $job_id    Optional. Job ID to update
 */
function schedule_event( $timestamp, $action, $args, $job_id = null ) {
	Events_Store::instance()->create_or_update_job( $timestamp, $action, $args, $job_id );
}

/**
 * Delete an event entry directly, bypassing the plugin's filtering to capture same
 *
 * @param int    $timestamp Time event should run
 * @param string $action    Hook to fire
 * @param string $instance  Hashed version of event's arguments
 */
function delete_event( $timestamp, $action, $instance ) {
	Events_Store::instance()->mark_job_completed( $timestamp, $action, $instance );
}

/**
 * Check if an entry exists for a particular job, and return its ID if requested
 *
 * @param int    $timestamp Time event should run
 * @param string $action    Hook to fire
 * @param string $instance  Hashed version of event's arguments
 * @param bool   $return_id Return job ID instead of boolean indicating job's existence
 *
 * @return bool|int Boolean when fourth parameter is false, integer when true
 */
function event_exists( $timestamp, $action, $instance, $return_id = false ) {
	return Events_Store::instance()->job_exists( $timestamp, $action, $instance, $return_id );
}
