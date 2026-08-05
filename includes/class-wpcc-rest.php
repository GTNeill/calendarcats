<?php
/**
 * REST endpoints the front-end script calls.
 *
 * Public and read-only — the data comes from public calendar feeds, so there
 * is nothing to protect, but responses are still rate-limited by the feed
 * cache rather than hitting Google on every request.
 *
 * @package WPCalendarCats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPCC_REST {

	const NAMESPACE_V1 = 'wpcalendarcats/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/events',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_events' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'timeMin' => array(
						'type'     => 'string',
						'required' => false,
					),
					'timeMax' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/categories',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_categories' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Events in a window, plus the same events grouped by category.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_events( $request ) {
		$tz = wp_timezone();

		$min = self::parse_param( $request->get_param( 'timeMin' ), $tz );
		$max = self::parse_param( $request->get_param( 'timeMax' ), $tz );

		if ( ! $min ) {
			$min = new DateTimeImmutable( 'today midnight', $tz );
		}
		if ( ! $max ) {
			$max = $min->modify( '+31 days' );
		}
		if ( $max->getTimestamp() < $min->getTimestamp() ) {
			$swap = $min;
			$min  = $max;
			$max  = $swap;
		}
		// Guard against an absurd window being requested by hand.
		if ( $max->getTimestamp() - $min->getTimestamp() > YEAR_IN_SECONDS ) {
			$max = $min->modify( '+1 year' );
		}

		$result = WPCC_ICS::get_events( $min, $max );

		$grouped = array();
		foreach ( $result['events'] as $event ) {
			$grouped[ $event['category'] ][] = $event;
		}

		return new WP_REST_Response(
			array(
				'events'     => $result['events'],
				'grouped'    => $grouped,
				'categories' => self::category_payload(),
				'groups'     => WPCC_Settings::get_groups(),
				'errors'     => $result['errors'],
				'fetchedAt'  => $result['fetched_at'],
				'timeMin'    => $min->format( 'c' ),
				'timeMax'    => $max->format( 'c' ),
			),
			200
		);
	}

	/**
	 * Categories without keyword lists — the front end only needs presentation.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_categories() {
		return new WP_REST_Response( self::category_payload(), 200 );
	}

	protected static function category_payload() {
		$out = array();
		foreach ( WPCC_Settings::get_categories() as $cat ) {
			$out[] = array(
				'key'   => $cat['key'],
				'label' => $cat['label'],
				'icon'  => $cat['icon'],
				'color' => $cat['color'],
				'group' => $cat['group'],
				'order' => $cat['order'],
			);
		}
		return $out;
	}

	/**
	 * Accept an ISO string or a plain date for the window bounds.
	 *
	 * @param mixed        $value Raw parameter.
	 * @param DateTimeZone $tz    Site timezone.
	 * @return DateTimeImmutable|null
	 */
	protected static function parse_param( $value, $tz ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		try {
			$date = new DateTimeImmutable( $value, $tz );
			return $date;
		} catch ( Exception $e ) {
			return null;
		}
	}
}
