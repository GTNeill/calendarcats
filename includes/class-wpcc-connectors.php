<?php
/**
 * Extra event sources beyond plain iCal feeds.
 *
 * Ported from the hosted Community Calendar app, which reads four kinds of
 * source:
 *
 *   ics          any .ics URL or Google Calendar id   (class-wpcc-ics.php)
 *   squarespace  Squarespace events collections       (this file)
 *   rss          Wild Apricot style events RSS        (this file)
 *   builtin      the Chicago farmers-market schedule  (this file)
 *
 * Every connector returns events already shaped for the front end, so
 * categorisation, de-duplication and the UI treat them like any other.
 *
 * @package CalendarCats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Non-iCalendar sources: Squarespace, RSS and built-in schedules.
 */
class WPCC_Connectors {

	const FARMERS_MARKET_NAME     = 'Chicago Farmers Markets (60625)';
	const FARMERS_MARKET_PAGE_URL = 'https://www.chicago.gov/city/en/depts/dca/supp_info/farmers_market_schedule.html';

	/** Squarespace paginates past events 30 at a time; never crawl further than this. */
	const MAX_PAST_PAGES = 6;

	/*
	─────────────────────────────────────────────────────────────────────
	 * HTTP
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * GET a URL through a transient cache.
	 *
	 * The key keeps the `wpcc_ics_` prefix so WPCC_ICS::flush_cache() clears
	 * connector responses along with the iCal ones.
	 *
	 * @param string $url    URL.
	 * @param string $accept Accept header.
	 * @return array{body:string,type:string}|WP_Error
	 */
	protected static function http_get( $url, $accept ) {
		$key    = 'wpcc_ics_x_' . WPCC_ICS::cache_version() . '_' . md5( $url );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// The safe variant refuses localhost, private/reserved IPs and unusual
		// ports, so a feed URL can't be pointed at the site's own network.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'limit_response_size' => WPCC_ICS::MAX_FEED_BYTES,
				'user-agent'          => 'CalendarCats/' . WPCC_VERSION . '; ' . home_url( '/' ),
				'headers'             => array( 'Accept' => $accept ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'wpcc_http', sprintf( __( 'HTTP %d fetching the feed', 'calendarcats' ), $code ) );
		}

		// limit_response_size truncates silently, so a body exactly at the cap
		// means the feed was cut off; say so instead of showing partial events.
		if ( strlen( (string) wp_remote_retrieve_body( $response ) ) >= WPCC_ICS::MAX_FEED_BYTES ) {
			return new WP_Error( 'wpcc_too_large', __( 'The feed is too large to load.', 'calendarcats' ) );
		}

		$out = array(
			'body' => (string) wp_remote_retrieve_body( $response ),
			'type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
		);
		// Very large bodies would bloat the options table; fetch them again instead.
		if ( strlen( $out['body'] ) <= WPCC_ICS::MAX_CACHED_BYTES ) {
			set_transient( $key, $out, WPCC_Settings::get_cache_seconds() );
		}
		return $out;
	}

	/**
	 * The scheme://host[:port] of a URL.
	 */
	protected static function origin_of( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		return $parts['scheme'] . '://' . $parts['host'] . ( ! empty( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/** Flatten rich HTML descriptions to readable plain text. */
	public static function html_to_text( $html ) {
		$html = (string) $html;
		$html = preg_replace( '#<\s*(br|/p|/div|/h[1-6]|/li|/tr)\s*/?>#i', "\n", $html );
		$html = wp_strip_all_tags( $html );
		$html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$html = str_replace( "\xC2\xA0", ' ', $html );
		$html = preg_replace( '/[ \t]+\n/', "\n", $html );
		$html = preg_replace( '/\n{3,}/', "\n\n", $html );
		return trim( $html );
	}

	/*
	─────────────────────────────────────────────────────────────────────
	 * Squarespace
	 *
	 * Event collections expose no .ics, but /events?format=json returns
	 * structured events: { upcoming, past, pagination }, with startDate /
	 * endDate as epoch milliseconds. One item is exactly one occurrence, so
	 * there is no recurrence to expand.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * @param array             $feed        Feed row from WPCC_Settings::get_feeds().
	 * @param DateTimeInterface $range_start Window start.
	 * @param DateTimeInterface $range_end   Window end.
	 * @return array|WP_Error Shaped events.
	 */
	public static function squarespace( $feed, $range_start, $range_end ) {
		$origin = self::origin_of( $feed['url'] );
		$tz     = wp_timezone();
		$min_ms = $range_start->getTimestamp() * 1000;
		$max_ms = $range_end->getTimestamp() * 1000;

		$out  = array();
		$seen = array();

		$first = self::squarespace_page( add_query_arg( 'format', 'json', $feed['url'] ) );
		if ( is_wp_error( $first ) ) {
			return $first;
		}

		$collect = function ( $items ) use ( &$out, &$seen, $feed, $origin, $tz, $min_ms, $max_ms ) {
			foreach ( (array) $items as $item ) {
				if ( ! is_array( $item ) || ! isset( $item['startDate'] ) || ! is_numeric( $item['startDate'] ) ) {
					continue;
				}
				$start_ms = (float) $item['startDate'];
				// A missing/invalid end is a one-hour event rather than a dropped one.
				$end_ms = ( isset( $item['endDate'] ) && is_numeric( $item['endDate'] ) && $item['endDate'] > $start_ms )
					? (float) $item['endDate']
					: $start_ms + 3600000;

				// Overlap, not start-in-window: a multi-day event that began
				// before the window is still happening inside it.
				if ( $start_ms > $max_ms || $end_ms < $min_ms ) {
					continue;
				}

				$id = 'sqsp:' . $origin . ':' . ( isset( $item['id'] ) ? $item['id'] : (int) $start_ms );
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;

				$start = ( new DateTimeImmutable( '@' . (int) floor( $start_ms / 1000 ) ) )->setTimezone( $tz );
				$end   = ( new DateTimeImmutable( '@' . (int) floor( $end_ms / 1000 ) ) )->setTimezone( $tz );

				// Squarespace has no all-day flag: a run from local midnight to
				// local midnight is all-day, so it renders without a stray 12:00 AM.
				$all_day = '00:00:00' === $start->format( 'H:i:s' ) && '00:00:00' === $end->format( 'H:i:s' )
					&& ( $start->format( 'Y-m-d' ) !== $end->format( 'Y-m-d' ) || $start_ms === $end_ms );

				$link = $feed['url'];
				if ( ! empty( $item['fullUrl'] ) ) {
					$link = 0 === strpos( $item['fullUrl'], 'http' ) ? $item['fullUrl'] : $origin . $item['fullUrl'];
				}

				$desc = isset( $item['excerpt'] ) ? $item['excerpt'] : ( isset( $item['body'] ) ? $item['body'] : '' );

				$out[] = WPCC_ICS::shape(
					array(
						'summary'     => '' !== trim( isset( $item['title'] ) ? (string) $item['title'] : '' ) ? trim( $item['title'] ) : __( '(no title)', 'calendarcats' ),
						'location'    => self::squarespace_location( isset( $item['location'] ) ? $item['location'] : null ),
						'description' => self::html_to_text( $desc ),
						'organizer'   => $feed['name'],
						'status'      => 'CONFIRMED',
						'link'        => esc_url_raw( $link ),
						'all_day'     => $all_day,
						'has_end'     => true,
					),
					$start,
					max( 0, $end->getTimestamp() - $start->getTimestamp() ),
					$id,
					$tz
				);
			}
		};

		// `upcoming` holds every future event in one shot, so page one covers
		// any forward-looking window.
		$collect( isset( $first['upcoming'] ) ? $first['upcoming'] : array() );
		$collect( isset( $first['items'] ) ? $first['items'] : array() );
		$collect( isset( $first['past'] ) ? $first['past'] : array() );

		// Walk back through `past` only when the window reaches further back
		// than this page does. nextPageOffset is the epoch-ms cursor of the
		// oldest item returned so far.
		$page = $first;
		for ( $i = 0; $i < self::MAX_PAST_PAGES; $i++ ) {
			$pg = isset( $page['pagination'] ) && is_array( $page['pagination'] ) ? $page['pagination'] : array();
			if ( empty( $pg['nextPage'] ) || empty( $pg['nextPageUrl'] ) ) {
				break;
			}
			if ( isset( $pg['nextPageOffset'] ) && is_numeric( $pg['nextPageOffset'] ) && $pg['nextPageOffset'] <= $min_ms ) {
				break;
			}
			$next = 0 === strpos( $pg['nextPageUrl'], 'http' ) ? $pg['nextPageUrl'] : $origin . $pg['nextPageUrl'];
			// The cursor comes from remote JSON, so never follow it off the
			// site we were asked to read.
			if ( self::origin_of( $next ) !== $origin ) {
				break;
			}
			$page = self::squarespace_page( add_query_arg( 'format', 'json', $next ) );
			if ( is_wp_error( $page ) ) {
				break; // Older history is a bonus; keep what we have.
			}
			$collect( isset( $page['past'] ) ? $page['past'] : array() );
			$collect( isset( $page['items'] ) ? $page['items'] : array() );
		}

		return $out;
	}

	/**
	 * Fetch and decode one Squarespace JSON page.
	 */
	protected static function squarespace_page( $url ) {
		$res = self::http_get( $url, 'application/json' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = false !== stripos( $res['type'], 'json' ) ? json_decode( $res['body'], true ) : null;
		if ( ! is_array( $data ) ) {
			// Squarespace answers with the rendered HTML page when the path
			// isn't an events collection.
			/* translators: %s: content type returned */
			return new WP_Error( 'wpcc_not_sqsp', sprintf( __( 'Expected JSON, got "%s" — is that URL a Squarespace events collection?', 'calendarcats' ), $res['type'] ) );
		}
		return $data;
	}

	/**
	 * Build a one-line location from a Squarespace item.
	 */
	protected static function squarespace_location( $loc ) {
		if ( ! is_array( $loc ) ) {
			return '';
		}
		$bits = array();
		foreach ( array( 'addressTitle', 'addressLine1', 'addressLine2' ) as $k ) {
			if ( ! empty( $loc[ $k ] ) && '' !== trim( $loc[ $k ] ) ) {
				$bits[] = trim( $loc[ $k ] );
			}
		}
		return implode( ', ', $bits );
	}

	/*
	─────────────────────────────────────────────────────────────────────
	 * Events RSS (Wild Apricot and friends)
	 *
	 * pubDate is the event START in UTC, not a publication date, and the
	 * title carries the authoritative date in parentheses, which is used to
	 * cross-check it. The feed has no end time or location, so these events
	 * are start-only — inventing a duration would publish a wrong end time.
	 * Recurring series repeat one <link> per occurrence, so the date is part
	 * of the event id.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * @param array             $feed        Feed row.
	 * @param DateTimeInterface $range_start Window start.
	 * @param DateTimeInterface $range_end   Window end.
	 * @return array|WP_Error Shaped events.
	 */
	public static function rss( $feed, $range_start, $range_end ) {
		$res = self::http_get( $feed['url'], 'application/rss+xml, application/xml, text/xml' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( false === stripos( $res['type'], 'xml' ) && ! preg_match( '/<rss[\s>]|<feed[\s>]/i', $res['body'] ) ) {
			return new WP_Error( 'wpcc_not_rss', sprintf( 'Expected RSS, got "%s" — is that URL an events RSS feed?', $res['type'] ) );
		}

		$tz     = wp_timezone();
		$origin = self::origin_of( $feed['url'] );
		$from   = $range_start->getTimestamp() - DAY_IN_SECONDS;
		$to     = $range_end->getTimestamp() + DAY_IN_SECONDS;
		$out    = array();
		$seen   = array();

		foreach ( self::parse_rss( $res['body'] ) as $item ) {
			$mapped = self::map_rss_item( $item, $feed, $origin, $tz );
			if ( ! $mapped ) {
				continue;
			}
			list( $id, $start, $all_day, $title ) = $mapped;

			$ts = $start->getTimestamp();
			if ( $ts < $from || $ts > $to || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;

			$out[] = WPCC_ICS::shape(
				array(
					'summary'     => $title,
					'location'    => '',
					'description' => self::html_to_text( $item['description'] ),
					'organizer'   => $feed['name'],
					'status'      => 'CONFIRMED',
					'link'        => esc_url_raw( '' !== $item['link'] ? $item['link'] : $feed['url'] ),
					'all_day'     => $all_day,
					'has_end'     => false,
				),
				$start,
				$all_day ? DAY_IN_SECONDS : 0,
				$id,
				$tz
			);
		}

		return $out;
	}

	/** Minimal RSS 2.0 <item> extractor — these feeds are machine-made and shallow. */
	public static function parse_rss( $xml ) {
		$items = array();
		if ( ! preg_match_all( '#<item(?:\s[^>]*)?>(.*?)</item>#is', $xml, $m ) ) {
			return $items;
		}
		foreach ( $m[1] as $block ) {
			$items[] = array(
				'title'       => self::rss_tag( $block, 'title' ),
				'link'        => self::rss_tag( $block, 'link' ),
				'guid'        => self::rss_tag( $block, 'guid' ),
				'description' => self::rss_tag( $block, 'description' ),
				'pubDate'     => self::rss_tag( $block, 'pubDate' ),
			);
		}
		return $items;
	}

	/**
	 * Text of the first matching tag in an RSS item.
	 */
	protected static function rss_tag( $block, $name ) {
		if ( ! preg_match( '#<' . $name . '(?:\s[^>]*)?>(.*?)</' . $name . '>#is', $block, $m ) ) {
			return '';
		}
		$raw = trim( $m[1] );
		if ( preg_match( '#^<!\[CDATA\[(.*?)\]\]>$#s', $raw, $c ) ) {
			return trim( $c[1] );
		}
		return trim( html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Wild Apricot appends the date to every title:
	 *   "Blood Drive (Saturday, September 19, 2026)"
	 *
	 * @param string $title Raw title.
	 * @return array{clean:string,date:string} date is Y-m-d or ''.
	 */
	public static function parse_title_date( $title ) {
		if ( ! preg_match( '/\s*\((?:[A-Za-z]+,\s*)?([A-Za-z]+)\s+(\d{1,2}),\s*(\d{4})\)\s*$/', $title, $m, PREG_OFFSET_CAPTURE ) ) {
			return array(
				'clean' => trim( $title ),
				'date'  => '',
			);
		}
		$months = array(
			'january'   => 1,
			'february'  => 2,
			'march'     => 3,
			'april'     => 4,
			'may'       => 5,
			'june'      => 6,
			'july'      => 7,
			'august'    => 8,
			'september' => 9,
			'october'   => 10,
			'november'  => 11,
			'december'  => 12,
		);
		$month  = isset( $months[ strtolower( $m[1][0] ) ] ) ? $months[ strtolower( $m[1][0] ) ] : 0;
		$day    = (int) $m[2][0];
		if ( ! $month || $day < 1 || $day > 31 ) {
			return array(
				'clean' => trim( $title ),
				'date'  => '',
			);
		}
		return array(
			'clean' => trim( substr( $title, 0, $m[0][1] ) ),
			'date'  => sprintf( '%04d-%02d-%02d', (int) $m[3][0], $month, $day ),
		);
	}

	/**
	 * @return array|null [ id, DateTimeImmutable start, bool all_day, string title ]
	 */
	protected static function map_rss_item( $item, $feed, $origin, $tz ) {
		if ( '' === $item['title'] && '' === $item['link'] ) {
			return null;
		}

		$parsed     = self::parse_title_date( $item['title'] );
		$title_date = $parsed['date'];

		$pub = null;
		if ( '' !== $item['pubDate'] ) {
			$ts = strtotime( $item['pubDate'] );
			if ( false !== $ts ) {
				$pub = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
			}
		}
		$pub_day  = $pub ? $pub->format( 'Y-m-d' ) : '';
		$pub_time = $pub ? $pub->format( 'H:i:s' ) : '';

		if ( $pub && ( '' === $title_date || $title_date === $pub_day ) ) {
			// pubDate agrees with the title, so its time is trustworthy.
			// Local midnight means an all-day entry.
			$day  = $pub_day;
			$time = '00:00:00' === $pub_time ? '' : $pub_time;
		} elseif ( '' !== $title_date ) {
			// They disagree: pubDate is a real publication date. The title
			// wins for the day; with no trustworthy time it becomes all-day.
			$day  = $title_date;
			$time = '';
		} else {
			return null; // No usable date at all — never place it at the epoch.
		}

		try {
			$start = new DateTimeImmutable( $day . ' ' . ( '' === $time ? '00:00:00' : $time ), $tz );
		} catch ( Exception $e ) {
			return null;
		}

		$ref = '' !== $item['guid'] ? $item['guid'] : $item['link'];
		if ( preg_match( '/event-(\d+)/i', $ref, $m ) ) {
			$part = $m[1];
		} else {
			$part = substr( preg_replace( '/[^\w.-]+/', '-', preg_replace( '#^https?://#i', '', $ref ) ), 0, 80 );
		}

		return array(
			'rss:' . $origin . ':' . $part . ':' . $day,
			$start,
			'' === $time,
			'' !== $parsed['clean'] ? $parsed['clean'] : __( '(no title)', 'calendarcats' ),
		);
	}

	/*
	─────────────────────────────────────────────────────────────────────
	 * Chicago farmers markets (zip 60625)
	 *
	 * DCASE publishes the season schedule only as a flat HTML page, so this
	 * is a small hand-built iCal, checked against that page and each
	 * market's street address. It goes through the same parser as a network
	 * feed, so recurrence and TZID handling are identical.
	 *
	 * Only three of the ~30 markets sit inside 60625: Lincoln Square
	 * (2301 W. Leland), Ravenswood Community (4900 N. Damen) and North Park
	 * Community (5555 N. Kimball). Update the dates each season.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * @param array             $feed        Feed row.
	 * @param DateTimeInterface $range_start Window start.
	 * @param DateTimeInterface $range_end   Window end.
	 * @return array Shaped events.
	 */
	public static function farmers_markets( $feed, $range_start, $range_end ) {
		$ics = str_replace(
			array( '{{LINK_NOTE}}', '{{PAGE_URL}}' ),
			array( 'Accepts Link Match — SNAP benefits matched dollar-for-dollar, up to $25 per swipe.', self::FARMERS_MARKET_PAGE_URL ),
			self::farmers_market_ics()
		);
		return WPCC_ICS::parse( $ics, $feed['name'], $range_start, $range_end );
	}

	/**
	 * The built-in farmers-market schedule as iCalendar text.
	 */
	protected static function farmers_market_ics() {
		return <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//George's Community Calendar//Farmers Markets 60625//EN
CALSCALE:GREGORIAN
BEGIN:VEVENT
UID:fm-lincoln-square-tue-2026@60625.communitycalendar
SUMMARY:Lincoln Square Farmers Market
LOCATION:2301 W. Leland Ave.\, Chicago\, IL 60625
DESCRIPTION:{{LINK_NOTE}} Tuesdays through Nov 17.
URL:{{PAGE_URL}}
DTSTART;TZID=America/Chicago:20260505T070000
DTEND;TZID=America/Chicago:20260505T120000
RRULE:FREQ=WEEKLY;BYDAY=TU;UNTIL=20261117T235959Z
STATUS:CONFIRMED
END:VEVENT
BEGIN:VEVENT
UID:fm-lincoln-square-thu-2026@60625.communitycalendar
SUMMARY:Lincoln Square Farmers Market
LOCATION:2301 W. Leland Ave.\, Chicago\, IL 60625
DESCRIPTION:{{LINK_NOTE}} Thursdays through Oct 29.
URL:{{PAGE_URL}}
DTSTART;TZID=America/Chicago:20260507T150000
DTEND;TZID=America/Chicago:20260507T190000
RRULE:FREQ=WEEKLY;BYDAY=TH;UNTIL=20261029T235959Z
STATUS:CONFIRMED
END:VEVENT
BEGIN:VEVENT
UID:fm-ravenswood-wed-2026@60625.communitycalendar
SUMMARY:Ravenswood Community Farmers Market
LOCATION:4900 N. Damen Ave.\, Chicago\, IL 60625
DESCRIPTION:Wednesdays through Oct 14.
URL:{{PAGE_URL}}
DTSTART;TZID=America/Chicago:20260513T160000
DTEND;TZID=America/Chicago:20260513T200000
RRULE:FREQ=WEEKLY;BYDAY=WE;UNTIL=20261014T235959Z
STATUS:CONFIRMED
END:VEVENT
BEGIN:VEVENT
UID:fm-northpark-2026-05-17@60625.communitycalendar
SUMMARY:North Park Community Market
LOCATION:5555 N. Kimball Ave.\, Chicago\, IL 60625
DESCRIPTION:{{LINK_NOTE}} One Sunday a month\, May-Oct.
URL:{{PAGE_URL}}
DTSTART;TZID=America/Chicago:20260517T100000
DTEND;TZID=America/Chicago:20260517T140000
STATUS:CONFIRMED
END:VEVENT
BEGIN:VEVENT
UID:fm-northpark-2026-06-21@60625.communitycalendar
SUMMARY:North Park Community Market
LOCATION:5555 N. Kimball Ave.\, Chicago\, IL 60625
DESCRIPTION:{{LINK_NOTE}} One Sunday a month\, May-Oct.
URL:{{PAGE_URL}}
DTSTART;TZID=America/Chicago:20260621T100000
DTEND;TZID=America/Chicago:20260621T140000
STATUS:CONFIRMED
END:VEVENT
BEGIN:VEVENT
UID:fm-northpark-2026-07-19@60625.communitycalendar
SUMMARY:North Park Community Market
LOCATION:5555 N. Kimball Ave.\, Chicago\, IL 60625
DESCRIPTION:{{LINK_NOTE}} One Sunday a month\, May-Oct.
URL:{{PAGE_URL}}
DTSTART;TZID=America/Chicago:20260719T100000
DTEND;TZID=America/Chicago:20260719T140000
STATUS:CONFIRMED
END:VEVENT
BEGIN:VEVENT
UID:fm-northpark-2026-08-16@60625.communitycalendar
SUMMARY:North Park Community Market
LOCATION:5555 N. Kimball Ave.\, Chicago\, IL 60625
DESCRIPTION:{{LINK_NOTE}} One Sunday a month\, May-Oct.
URL:{{PAGE_URL}}
DTSTART;TZID=America/Chicago:20260816T100000
DTEND;TZID=America/Chicago:20260816T140000
STATUS:CONFIRMED
END:VEVENT
BEGIN:VEVENT
UID:fm-northpark-2026-09-20@60625.communitycalendar
SUMMARY:North Park Community Market
LOCATION:5555 N. Kimball Ave.\, Chicago\, IL 60625
DESCRIPTION:{{LINK_NOTE}} One Sunday a month\, May-Oct.
URL:{{PAGE_URL}}
DTSTART;TZID=America/Chicago:20260920T100000
DTEND;TZID=America/Chicago:20260920T140000
STATUS:CONFIRMED
END:VEVENT
BEGIN:VEVENT
UID:fm-northpark-2026-10-18@60625.communitycalendar
SUMMARY:North Park Community Market
LOCATION:5555 N. Kimball Ave.\, Chicago\, IL 60625
DESCRIPTION:{{LINK_NOTE}} One Sunday a month\, May-Oct.
URL:{{PAGE_URL}}
DTSTART;TZID=America/Chicago:20261018T100000
DTEND;TZID=America/Chicago:20261018T140000
STATUS:CONFIRMED
END:VEVENT
END:VCALENDAR
ICS;
	}
}
