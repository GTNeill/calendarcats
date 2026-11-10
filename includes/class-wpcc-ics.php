<?php
/**
 * iCalendar fetching and parsing, including recurrence expansion.
 *
 * The plugin talks directly to public .ics feeds — no external service,
 * no API key. Feeds are fetched with the WordPress HTTP API and cached in
 * a transient so a page view never blocks on a network round trip.
 *
 * @package CalendarCats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches and parses iCalendar feeds into shaped events.
 */
class WPCC_ICS {

	/** Hard ceiling on generated occurrences per recurring event. */
	const MAX_OCCURRENCES = 1000;

	/** Hard ceiling on recurrence iterations, so a malformed rule can't hang a request. */
	const MAX_ITERATIONS = 5000;

	/** Largest feed body we will download. */
	const MAX_FEED_BYTES = 5 * MB_IN_BYTES;

	/** Largest body kept in a transient; bigger ones are refetched. */
	const MAX_CACHED_BYTES = 2 * MB_IN_BYTES;

	/**
	 * Version stamped into every cache key. Bumping it invalidates the cache
	 * in one write, which works the same with or without an object cache.
	 *
	 * @return string
	 */
	public static function cache_version() {
		return (string) get_option( 'wpcc_cache_ver', '1' );
	}

	/**
	 * Fetch every configured feed and return shaped events inside the window.
	 *
	 * @param DateTimeInterface $range_start Window start.
	 * @param DateTimeInterface $range_end   Window end.
	 * @return array{events:array,errors:array,fetched_at:string}
	 */
	public static function get_events( $range_start, $range_end ) {
		// Parsing and expanding recurrences is the expensive part, so keep the
		// shaped result per window. Windows far from today aren't cached, so a
		// crawler can't fill the options table with one-off entries.
		$cacheable = abs( $range_start->getTimestamp() - time() ) < 2 * YEAR_IN_SECONDS;
		$key       = 'wpcc_ics_ev_' . self::cache_version() . '_' . md5( $range_start->getTimestamp() . '|' . $range_end->getTimestamp() . '|' . wp_json_encode( array( WPCC_Settings::get_feeds(), WPCC_Settings::get_categories() ) ) );
		if ( $cacheable ) {
			$hit = get_transient( $key );
			if ( is_array( $hit ) ) {
				return $hit;
			}
		}

		$result = self::build_events( $range_start, $range_end );

		// Don't pin a partial result for the full TTL when a feed just failed.
		if ( $cacheable && empty( $result['errors'] ) ) {
			set_transient( $key, $result, WPCC_Settings::get_cache_seconds() );
		}
		return $result;
	}

	/**
	 * Fetch and shape every feed (uncached).
	 *
	 * @param DateTimeInterface $range_start Window start.
	 * @param DateTimeInterface $range_end   Window end.
	 * @return array{events:array,errors:array,fetched_at:string}
	 */
	protected static function build_events( $range_start, $range_end ) {
		$feeds  = WPCC_Settings::get_feeds();
		$events = array();
		$errors = array();

		foreach ( $feeds as $rank => $feed ) {
			$type = isset( $feed['type'] ) ? $feed['type'] : 'ics';

			if ( 'squarespace' === $type ) {
				$found = WPCC_Connectors::squarespace( $feed, $range_start, $range_end );
			} elseif ( 'rss' === $type ) {
				$found = WPCC_Connectors::rss( $feed, $range_start, $range_end );
			} elseif ( 'builtin' === $type ) {
				$found = WPCC_Connectors::farmers_markets( $feed, $range_start, $range_end );
			} else {
				$found = self::fetch( $feed['url'] );
				if ( ! is_wp_error( $found ) ) {
					$found = self::parse( $found, $feed['name'], $range_start, $range_end );
				}
			}

			if ( is_wp_error( $found ) ) {
				$errors[] = sprintf(
					/* translators: 1: feed name, 2: error message */
					__( '%1$s: %2$s', 'calendarcats' ),
					$feed['name'],
					$found->get_error_message()
				);
				continue;
			}

			// Earlier in the list = more authoritative when duplicates collapse.
			foreach ( $found as $ev ) {
				$ev['_rank'] = $rank;
				$events[]    = $ev;
			}
		}

		// The same real-world event is often published by several organisations
		// whose areas overlap; collapse those, keeping the most authoritative.
		$clean = WPCC_Dedupe::dedupe( $events );
		foreach ( $clean as $i => $ev ) {
			unset( $clean[ $i ]['_rank'] );
		}
		$clean = array_values( $clean );

		usort(
			$clean,
			function ( $a, $b ) {
				return strcmp( $a['start'], $b['start'] );
			}
		);

		return array(
			'events'     => $clean,
			'errors'     => $errors,
			'fetched_at' => gmdate( 'c' ),
		);
	}

	/**
	 * Fetch one feed, using the cache unless it has expired.
	 *
	 * @param string $url Feed URL.
	 * @return string|WP_Error Raw iCalendar text.
	 */
	public static function fetch( $url ) {
		$key    = 'wpcc_ics_' . self::cache_version() . '_' . md5( $url );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		// The safe variant refuses localhost, private/reserved IPs and unusual
		// ports, so a feed URL can't be pointed at the site's own network.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'limit_response_size' => self::MAX_FEED_BYTES,
				'user-agent'          => 'CalendarCats/' . WPCC_VERSION . '; ' . home_url( '/' ),
				'headers'             => array( 'Accept' => 'text/calendar, text/plain, */*' ),
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

		$body = wp_remote_retrieve_body( $response );
		// limit_response_size truncates silently, so a body exactly at the cap
		// means the feed was cut off; say so instead of showing partial events.
		if ( strlen( $body ) >= self::MAX_FEED_BYTES ) {
			return new WP_Error( 'wpcc_too_large', __( 'The feed is too large to load.', 'calendarcats' ) );
		}
		if ( '' === trim( $body ) || false === stripos( $body, 'BEGIN:VCALENDAR' ) ) {
			return new WP_Error( 'wpcc_not_ics', __( 'The response was not an iCalendar feed.', 'calendarcats' ) );
		}

		// Very large bodies would bloat the options table; fetch them again instead.
		if ( strlen( $body ) <= self::MAX_CACHED_BYTES ) {
			set_transient( $key, $body, WPCC_Settings::get_cache_seconds() );
		}
		return $body;
	}

	/** Delete every cached feed body, so the next request refetches. */
	public static function flush_cache() {
		// New version = every old key is unreachable immediately, including
		// when transients live in an object cache rather than the options table.
		update_option( 'wpcc_cache_ver', (string) microtime( true ), false );

		// Without an object cache the stale rows would linger until expiry, so
		// tidy them too.
		if ( wp_using_ext_object_cache() ) {
			return;
		}
		global $wpdb;
		$like = $wpdb->esc_like( '_transient_wpcc_ics_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transients are in the options table here; no API lists them by prefix.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		foreach ( (array) $names as $name ) {
			delete_transient( str_replace( '_transient_', '', $name ) );
		}
	}

	/*
	─────────────────────────────────────────────────────────────────────
	 * Parsing
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * Parse iCalendar text into shaped events within a window.
	 *
	 * @param string            $ics         Raw feed.
	 * @param string            $feed_name   Human-readable calendar name.
	 * @param DateTimeInterface $range_start Window start.
	 * @param DateTimeInterface $range_end   Window end.
	 * @return array
	 */
	public static function parse( $ics, $feed_name, $range_start, $range_end ) {
		// Unfold RFC 5545 continuation lines before anything else.
		$unfolded = preg_replace( "/\r?\n[ \t]/", '', $ics );
		$blocks   = preg_split( '/BEGIN:VEVENT/', $unfolded );
		array_shift( $blocks );

		$tz      = wp_timezone();
		$default = self::calendar_timezone( $unfolded, $tz );

		// Pass 1 — collect the instances that recurring events have overrides
		// for, so the RRULE expansion below skips them and the override's own
		// VEVENT (which has no RRULE) is what actually shows.
		$overrides = array();
		foreach ( $blocks as $block ) {
			$rec_line = self::raw_line( $block, 'RECURRENCE-ID' );
			if ( '' === $rec_line ) {
				continue;
			}
			$uid = self::field( $block, 'UID' );
			if ( '' === $uid ) {
				continue;
			}
			$stamp = self::stamp_of_raw_value( self::value_of_line( $rec_line ) );
			if ( '' !== $stamp ) {
				$overrides[ $uid ][ $stamp ] = true;
			}
		}

		$events = array();

		foreach ( $blocks as $block ) {
			$uid     = self::field( $block, 'UID' );
			$summary = self::field( $block, 'SUMMARY' );
			if ( '' === $summary ) {
				$summary = __( '(no title)', 'calendarcats' );
			}
			$rec_id = self::field( $block, 'RECURRENCE-ID' );
			$status = self::field( $block, 'STATUS' );
			if ( 'CANCELLED' === strtoupper( $status ) ) {
				continue;
			}

			$start_line = self::raw_line( $block, 'DTSTART' );
			if ( '' === $start_line ) {
				continue;
			}
			$end_line = self::raw_line( $block, 'DTEND' );

			$start_info = self::parse_date( $start_line, $default );
			if ( null === $start_info ) {
				continue;
			}
			$end_info = '' === $end_line ? null : self::parse_date( $end_line, $default );

			if ( null === $end_info ) {
				// No DTEND: all-day events last one day, timed events one hour.
				$duration = $start_info['all_day'] ? 86400 : 3600;
			} else {
				$duration = max( 0, $end_info['date']->getTimestamp() - $start_info['date']->getTimestamp() );
			}

			$base = array(
				'uid'         => $uid,
				'summary'     => $summary,
				'location'    => self::field( $block, 'LOCATION' ),
				'description' => self::field( $block, 'DESCRIPTION' ),
				'organizer'   => $feed_name,
				'status'      => '' === $status ? 'CONFIRMED' : $status,
				'link'        => self::event_link( $block ),
				'all_day'     => $start_info['all_day'],
			);

			$rrule = self::field( $block, 'RRULE' );

			if ( '' === $rrule ) {
				// Single event, or one override instance of a recurring event.
				if ( self::overlaps( $start_info['date'], $duration, $range_start, $range_end ) ) {
					$suffix   = '' === $rec_id ? '' : '_' . $rec_id;
					$events[] = self::shape( $base, $start_info['date'], $duration, $uid . $suffix, $tz );
				}
				continue;
			}

			$excluded = self::exdate_stamps( $block );
			if ( isset( $overrides[ $uid ] ) ) {
				$excluded = array_merge( $excluded, $overrides[ $uid ] );
			}

			$occurrences = self::expand_rrule( $rrule, $start_info['date'], $range_start, $range_end, $duration );

			foreach ( $occurrences as $occ ) {
				if ( isset( $excluded[ $occ->format( 'Ymd\THis' ) ] ) || isset( $excluded[ $occ->format( 'Ymd' ) ] ) ) {
					continue;
				}
				$events[] = self::shape( $base, $occ, $duration, $uid . '_' . $occ->format( 'Ymd\THis' ), $tz );
			}
		}

		return $events;
	}

	/**
	 * Turn a parsed occurrence into the array the front end consumes.
	 * Times are emitted as naive local-wall-clock ISO strings in the site's
	 * timezone, so the browser renders them identically for every visitor
	 * regardless of where they are.
	 *
	 * @param array             $base     Shared event fields.
	 * @param DateTimeInterface $start    Occurrence start.
	 * @param int               $duration Seconds.
	 * @param string            $id       Unique instance id.
	 * @param DateTimeZone      $tz       Display timezone.
	 * @return array
	 */
	public static function shape( $base, $start, $duration, $id, $tz ) {
		$start_local = ( new DateTimeImmutable( '@' . $start->getTimestamp() ) )->setTimezone( $tz );
		$has_end     = ! isset( $base['has_end'] ) || $base['has_end'];

		if ( $base['all_day'] ) {
			// Count whole days rather than adding seconds: a span that crosses
			// the autumn clock change is 25 hours long, and +N*86400s from
			// midnight would land on 23:00 of the previous day and lose a day.
			$days      = max( 1, (int) round( $duration / DAY_IN_SECONDS ) );
			$start_str = $start_local->format( 'Y-m-d' );
			$end_str   = $has_end ? $start_local->modify( '+' . $days . ' days' )->format( 'Y-m-d' ) : '';
		} else {
			$end_local = $start_local->modify( '+' . (int) $duration . ' seconds' );
			$start_str = $start_local->format( 'Y-m-d\TH:i:s' );
			$end_str   = $has_end ? $end_local->format( 'Y-m-d\TH:i:s' ) : '';
		}

		$cat = WPCC_Categories::categorize( $base['summary'], $base['description'] );

		return array(
			'id'            => $id,
			'title'         => $base['summary'],
			'start'         => $start_str,
			'end'           => $end_str,
			'isAllDay'      => (bool) $base['all_day'],
			'location'      => $base['location'],
			'description'   => $base['description'],
			'organizer'     => $base['organizer'],
			'status'        => $base['status'],
			'htmlLink'      => $base['link'],
			'category'      => $cat['key'],
			'categoryLabel' => $cat['label'],
			'categoryIcon'  => $cat['icon'],
			'categoryColor' => $cat['color'],
			'categoryGroup' => $cat['group'],
		);
	}

	/** Does an event touch the requested window at all? */
	protected static function overlaps( $start, $duration, $range_start, $range_end ) {
		$s = $start->getTimestamp();
		$e = $s + (int) $duration;
		return $e >= $range_start->getTimestamp() && $s <= $range_end->getTimestamp();
	}

	/* ── Field helpers ───────────────────────────────────────────────── */

	/** The whole raw property line, parameters included. */
	protected static function raw_line( $block, $name ) {
		// A VALARM carries its own DESCRIPTION / SUMMARY (the reminder text).
		// Drop it so those can't be mistaken for the event's.
		if ( false !== stripos( $block, 'BEGIN:VALARM' ) ) {
			$block = preg_replace( '/^BEGIN:VALARM.*?^END:VALARM[^\r\n]*/msi', '', $block );
		}
		if ( preg_match( '/^' . preg_quote( $name, '/' ) . '[;:][^\r\n]*/mi', $block, $m ) ) {
			return $m[0];
		}
		return '';
	}

	/** Everything after the first unquoted colon on a property line. */
	protected static function value_of_line( $line ) {
		$pos = strpos( $line, ':' );
		return false === $pos ? '' : substr( $line, $pos + 1 );
	}

	/** A property's decoded text value. */
	protected static function field( $block, $name ) {
		$line = self::raw_line( $block, $name );
		if ( '' === $line ) {
			return '';
		}
		return self::decode_text( self::value_of_line( $line ) );
	}

	/** Undo RFC 5545 escaping. */
	protected static function decode_text( $value ) {
		$value = str_replace( array( '\\r\\n', '\\n', '\\N' ), "\n", $value );
		$value = str_replace( array( '\\,', '\\;' ), array( ',', ';' ), $value );
		$value = str_replace( '\\\\', '\\', $value );
		return trim( $value );
	}

	/** A named parameter from a property line, e.g. TZID. */
	protected static function param( $line, $name ) {
		if ( preg_match( '/;' . preg_quote( $name, '/' ) . '=([^;:]+)/i', $line, $m ) ) {
			return trim( $m[1], '"' );
		}
		return '';
	}

	/**
	 * The calendar-level X-WR-TIMEZONE, falling back to the site timezone.
	 * Floating times in a feed are meant to be read in this zone.
	 */
	protected static function calendar_timezone( $ics, $fallback ) {
		if ( preg_match( '/^X-WR-TIMEZONE:([^\r\n]+)/mi', $ics, $m ) ) {
			$tz = self::safe_timezone( trim( $m[1] ) );
			if ( $tz ) {
				return $tz;
			}
		}
		return $fallback;
	}

	/** A DateTimeZone, or null when the identifier is unusable. */
	protected static function safe_timezone( $id ) {
		if ( '' === $id ) {
			return null;
		}
		try {
			return new DateTimeZone( $id );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Parse a DTSTART/DTEND/RECURRENCE-ID line into an absolute instant.
	 *
	 * Handles the three RFC 5545 forms: UTC ("…Z"), zoned (";TZID=…"), and
	 * floating (no zone — read in the calendar's own timezone).
	 *
	 * @param string       $line    Raw property line.
	 * @param DateTimeZone $default Timezone for floating values.
	 * @return array{date:DateTimeImmutable,all_day:bool}|null
	 */
	protected static function parse_date( $line, $default ) {
		$value = trim( self::value_of_line( $line ) );
		if ( '' === $value ) {
			return null;
		}

		$is_all_day = (bool) preg_match( '/VALUE=DATE(?!-TIME)/i', $line ) || (bool) preg_match( '/^\d{8}$/', $value );

		try {
			if ( $is_all_day ) {
				// An all-day date has no zone of its own; anchor it to local midnight.
				$date = new DateTimeImmutable( substr( $value, 0, 4 ) . '-' . substr( $value, 4, 2 ) . '-' . substr( $value, 6, 2 ) . ' 00:00:00', $default );
				return array(
					'date'    => $date,
					'all_day' => true,
				);
			}

			if ( ! preg_match( '/^(\d{8})T(\d{6})(Z?)$/', $value, $m ) ) {
				return null;
			}

			$iso = substr( $m[1], 0, 4 ) . '-' . substr( $m[1], 4, 2 ) . '-' . substr( $m[1], 6, 2 )
				. ' ' . substr( $m[2], 0, 2 ) . ':' . substr( $m[2], 2, 2 ) . ':' . substr( $m[2], 4, 2 );

			if ( 'Z' === $m[3] ) {
				$zone = new DateTimeZone( 'UTC' );
			} else {
				$zone = self::safe_timezone( self::param( $line, 'TZID' ) );
				if ( ! $zone ) {
					$zone = $default;
				}
			}

			return array(
				'date'    => new DateTimeImmutable( $iso, $zone ),
				'all_day' => false,
			);
		} catch ( Exception $e ) {
			return null;
		}
	}

	/** Normalise a raw date value to the Ymd\THis / Ymd stamp used for matching. */
	protected static function stamp_of_raw_value( $value ) {
		$value = trim( $value );
		if ( preg_match( '/^(\d{8})T(\d{6})/', $value, $m ) ) {
			return $m[1] . 'T' . $m[2];
		}
		if ( preg_match( '/^(\d{8})$/', $value, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Every EXDATE stamp in a block, keyed for O(1) lookup. A block can carry
	 * several EXDATE lines and each can list several comma-separated values.
	 *
	 * @param string $block VEVENT body.
	 * @return array<string,bool>
	 */
	protected static function exdate_stamps( $block ) {
		$stamps = array();
		if ( preg_match_all( '/^EXDATE[;:][^\r\n]*/mi', $block, $matches ) ) {
			foreach ( $matches[0] as $line ) {
				foreach ( explode( ',', self::value_of_line( $line ) ) as $value ) {
					$stamp = self::stamp_of_raw_value( $value );
					if ( '' !== $stamp ) {
						$stamps[ $stamp ] = true;
					}
				}
			}
		}
		return $stamps;
	}

	/**
	 * A public link for the event: its own URL when the feed provides one,
	 * otherwise a Google Calendar deep link built from UID + calendar id.
	 *
	 * @param string $block VEVENT body.
	 * @return string
	 */
	protected static function event_link( $block ) {
		$url = self::field( $block, 'URL' );
		if ( '' !== $url && preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		return '';
	}

	/*
	─────────────────────────────────────────────────────────────────────
	 * Recurrence expansion
	 *
	 * PHP has no RRULE engine, so this implements the subset that real
	 * calendar feeds actually use: FREQ DAILY/WEEKLY/MONTHLY/YEARLY with
	 * INTERVAL, COUNT, UNTIL, BYDAY (including ordinals like 3TU / -1SU),
	 * BYMONTHDAY and BYMONTH. Occurrences are produced in chronological
	 * order, which is what makes COUNT correct.
	 *
	 * Expansion happens in the DTSTART's own timezone, so a weekly 7pm event
	 * stays at 7pm across a daylight-saving boundary.
	 * ────────────────────────────────────────────────────────────────── */

	/**
	 * @param string            $rrule       RRULE value.
	 * @param DateTimeImmutable $dtstart     First occurrence.
	 * @param DateTimeInterface $range_start Window start.
	 * @param DateTimeInterface $range_end   Window end.
	 * @param int               $duration    Event length in seconds.
	 * @return DateTimeImmutable[]
	 */
	protected static function expand_rrule( $rrule, $dtstart, $range_start, $range_end, $duration ) {
		$parts = array();
		foreach ( explode( ';', $rrule ) as $chunk ) {
			if ( false === strpos( $chunk, '=' ) ) {
				continue;
			}
			list( $k, $v )             = explode( '=', $chunk, 2 );
			$parts[ strtoupper( $k ) ] = strtoupper( trim( $v ) );
		}

		$freq = isset( $parts['FREQ'] ) ? $parts['FREQ'] : '';
		if ( ! in_array( $freq, array( 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' ), true ) ) {
			// Unsupported frequency (SECONDLY/MINUTELY/HOURLY): treat as single.
			return self::overlaps( $dtstart, $duration, $range_start, $range_end ) ? array( $dtstart ) : array();
		}

		$interval = isset( $parts['INTERVAL'] ) ? max( 1, (int) $parts['INTERVAL'] ) : 1;
		$count    = isset( $parts['COUNT'] ) ? max( 1, (int) $parts['COUNT'] ) : 0;

		$until = null;
		if ( isset( $parts['UNTIL'] ) ) {
			$until = self::parse_until( $parts['UNTIL'], $dtstart->getTimezone() );
		}

		$bymonth    = isset( $parts['BYMONTH'] ) ? array_map( 'intval', explode( ',', $parts['BYMONTH'] ) ) : array();
		$bymonthday = isset( $parts['BYMONTHDAY'] ) ? array_map( 'intval', explode( ',', $parts['BYMONTHDAY'] ) ) : array();
		$byday      = isset( $parts['BYDAY'] ) ? self::parse_byday( $parts['BYDAY'] ) : array();

		$results      = array();
		$emitted      = 0;
		$iterations   = 0;
		$window_end   = $range_end->getTimestamp();
		$window_start = $range_start->getTimestamp();
		$time         = $dtstart->format( 'H:i:s' );
		$zone         = $dtstart->getTimezone();

		// Cursor walks period by period from DTSTART.
		$cursor = $dtstart;

		while ( $iterations < self::MAX_ITERATIONS && $emitted < self::MAX_OCCURRENCES ) {
			++$iterations;

			$candidates = self::period_candidates( $freq, $cursor, $dtstart, $byday, $bymonthday, $bymonth, $time, $zone );

			foreach ( $candidates as $candidate ) {
				if ( $candidate->getTimestamp() < $dtstart->getTimestamp() ) {
					continue;
				}
				if ( $until && $candidate->getTimestamp() > $until->getTimestamp() ) {
					return $results;
				}

				++$emitted;

				if ( $candidate->getTimestamp() + $duration >= $window_start && $candidate->getTimestamp() <= $window_end ) {
					$results[] = $candidate;
				}

				if ( $count && $emitted >= $count ) {
					return $results;
				}
				if ( $emitted >= self::MAX_OCCURRENCES ) {
					return $results;
				}
			}

			// Past the window with nothing left to find — stop.
			if ( $cursor->getTimestamp() > $window_end ) {
				break;
			}

			$cursor = self::advance( $cursor, $freq, $interval );
			if ( null === $cursor ) {
				break;
			}
		}

		return $results;
	}

	/** UNTIL can be a UTC instant or a floating date/date-time. */
	protected static function parse_until( $value, $zone ) {
		try {
			if ( preg_match( '/^(\d{8})T(\d{6})Z$/', $value, $m ) ) {
				return new DateTimeImmutable(
					substr( $m[1], 0, 4 ) . '-' . substr( $m[1], 4, 2 ) . '-' . substr( $m[1], 6, 2 ) . ' ' .
					substr( $m[2], 0, 2 ) . ':' . substr( $m[2], 2, 2 ) . ':' . substr( $m[2], 4, 2 ),
					new DateTimeZone( 'UTC' )
				);
			}
			if ( preg_match( '/^(\d{8})T(\d{6})$/', $value, $m ) ) {
				return new DateTimeImmutable(
					substr( $m[1], 0, 4 ) . '-' . substr( $m[1], 4, 2 ) . '-' . substr( $m[1], 6, 2 ) . ' ' .
					substr( $m[2], 0, 2 ) . ':' . substr( $m[2], 2, 2 ) . ':' . substr( $m[2], 4, 2 ),
					$zone
				);
			}
			if ( preg_match( '/^(\d{8})$/', $value, $m ) ) {
				// Date-only UNTIL is inclusive of the whole day.
				return new DateTimeImmutable(
					substr( $m[1], 0, 4 ) . '-' . substr( $m[1], 4, 2 ) . '-' . substr( $m[1], 6, 2 ) . ' 23:59:59',
					$zone
				);
			}
		} catch ( Exception $e ) {
			return null;
		}
		return null;
	}

	/**
	 * Parse BYDAY into ordinal/weekday pairs, e.g. "3TU,-1SU".
	 *
	 * @param string $value BYDAY value.
	 * @return array<int,array{ordinal:int,day:int}> day is 0=Sunday…6=Saturday.
	 */
	protected static function parse_byday( $value ) {
		$map = array(
			'SU' => 0,
			'MO' => 1,
			'TU' => 2,
			'WE' => 3,
			'TH' => 4,
			'FR' => 5,
			'SA' => 6,
		);
		$out = array();
		foreach ( explode( ',', $value ) as $token ) {
			$token = trim( $token );
			if ( ! preg_match( '/^([+-]?\d+)?(SU|MO|TU|WE|TH|FR|SA)$/', $token, $m ) ) {
				continue;
			}
			$out[] = array(
				'ordinal' => isset( $m[1] ) && '' !== $m[1] ? (int) $m[1] : 0,
				'day'     => $map[ $m[2] ],
			);
		}
		return $out;
	}

	/** Move the cursor to the next period. */
	protected static function advance( $cursor, $freq, $interval ) {
		try {
			switch ( $freq ) {
				case 'DAILY':
					return $cursor->modify( '+' . ( $interval ) . ' days' );
				case 'WEEKLY':
					return $cursor->modify( '+' . ( $interval * 7 ) . ' days' );
				case 'MONTHLY':
					// Step on the first of the month so a 31st start doesn't
					// skip February by overflowing into March.
					return $cursor->modify( 'first day of this month' )->modify( '+' . $interval . ' months' );
				case 'YEARLY':
					return $cursor->modify( '+' . $interval . ' years' );
			}
		} catch ( Exception $e ) {
			return null;
		}
		return null;
	}

	/**
	 * All occurrence candidates inside the period the cursor sits in,
	 * in chronological order.
	 *
	 * @param string            $freq       Frequency.
	 * @param DateTimeImmutable $cursor     Current period anchor.
	 * @param DateTimeImmutable $dtstart    Series start.
	 * @param array             $byday      Parsed BYDAY.
	 * @param array             $bymonthday BYMONTHDAY values.
	 * @param array             $bymonth    BYMONTH values.
	 * @param string            $time       H:i:s from DTSTART.
	 * @param DateTimeZone      $zone       Series timezone.
	 * @return DateTimeImmutable[]
	 */
	protected static function period_candidates( $freq, $cursor, $dtstart, $byday, $bymonthday, $bymonth, $time, $zone ) {
		$out = array();

		switch ( $freq ) {
			case 'DAILY':
				if ( $bymonth && ! in_array( (int) $cursor->format( 'n' ), $bymonth, true ) ) {
					return array();
				}
				if ( $byday && ! self::matches_weekday( $cursor, $byday ) ) {
					return array();
				}
				if ( $bymonthday && ! self::matches_monthday( $cursor, $bymonthday ) ) {
					return array();
				}
				$out[] = $cursor;
				break;

			case 'WEEKLY':
				$days = array();
				if ( $byday ) {
					foreach ( $byday as $bd ) {
						$days[] = $bd['day'];
					}
				} else {
					$days[] = (int) $dtstart->format( 'w' );
				}
				sort( $days );
				// Walk from the Sunday of the cursor's week.
				$week_start = $cursor->modify( '-' . (int) $cursor->format( 'w' ) . ' days' );
				foreach ( $days as $dow ) {
					$candidate = self::at_time( $week_start->modify( '+' . $dow . ' days' ), $time, $zone );
					if ( ! $candidate ) {
						continue;
					}
					if ( $bymonth && ! in_array( (int) $candidate->format( 'n' ), $bymonth, true ) ) {
						continue;
					}
					$out[] = $candidate;
				}
				break;

			case 'MONTHLY':
				if ( $bymonth && ! in_array( (int) $cursor->format( 'n' ), $bymonth, true ) ) {
					return array();
				}
				$out = self::month_candidates( $cursor, $dtstart, $byday, $bymonthday, $time, $zone );
				break;

			case 'YEARLY':
				$months = $bymonth ? $bymonth : array( (int) $dtstart->format( 'n' ) );
				sort( $months );
				foreach ( $months as $month ) {
					$month_anchor = self::first_of_month( $cursor, $month, $zone );
					if ( ! $month_anchor ) {
						continue;
					}
					$out = array_merge( $out, self::month_candidates( $month_anchor, $dtstart, $byday, $bymonthday, $time, $zone ) );
				}
				break;
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $a->getTimestamp() <=> $b->getTimestamp();
			}
		);
		return $out;
	}

	/** Candidates within a single month, honouring BYDAY ordinals and BYMONTHDAY. */
	protected static function month_candidates( $anchor, $dtstart, $byday, $bymonthday, $time, $zone ) {
		$out       = array();
		$year      = (int) $anchor->format( 'Y' );
		$month     = (int) $anchor->format( 'n' );
		$days_in_m = (int) $anchor->format( 't' );

		if ( $byday ) {
			foreach ( $byday as $bd ) {
				if ( 0 === $bd['ordinal'] ) {
					// Every matching weekday in the month.
					for ( $day = 1; $day <= $days_in_m; $day++ ) {
						$candidate = self::make( $year, $month, $day, $time, $zone );
						if ( $candidate && (int) $candidate->format( 'w' ) === $bd['day'] ) {
							$out[] = $candidate;
						}
					}
					continue;
				}
				$candidate = self::nth_weekday( $year, $month, $bd['day'], $bd['ordinal'], $time, $zone );
				if ( $candidate ) {
					$out[] = $candidate;
				}
			}
			return $out;
		}

		$days = $bymonthday ? $bymonthday : array( (int) $dtstart->format( 'j' ) );
		foreach ( $days as $day ) {
			$day = (int) $day;
			if ( $day < 0 ) {
				$day = $days_in_m + 1 + $day; // -1 = last day of month.
			}
			if ( $day < 1 || $day > $days_in_m ) {
				continue; // e.g. the 31st in a 30-day month — skipped, per RFC.
			}
			$candidate = self::make( $year, $month, $day, $time, $zone );
			if ( $candidate ) {
				$out[] = $candidate;
			}
		}
		return $out;
	}

	/** The nth (or -nth) given weekday of a month. */
	protected static function nth_weekday( $year, $month, $dow, $ordinal, $time, $zone ) {
		$days_in_m = (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );
		$matches   = array();
		for ( $day = 1; $day <= $days_in_m; $day++ ) {
			$candidate = self::make( $year, $month, $day, $time, $zone );
			if ( $candidate && (int) $candidate->format( 'w' ) === $dow ) {
				$matches[] = $candidate;
			}
		}
		if ( empty( $matches ) ) {
			return null;
		}
		$index = $ordinal > 0 ? $ordinal - 1 : count( $matches ) + $ordinal;
		return isset( $matches[ $index ] ) ? $matches[ $index ] : null;
	}

	/** Build a zoned instant, or null if the date doesn't exist. */
	protected static function make( $year, $month, $day, $time, $zone ) {
		try {
			return new DateTimeImmutable( sprintf( '%04d-%02d-%02d %s', $year, $month, $day, $time ), $zone );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/** Same date as $date, at $time. */
	protected static function at_time( $date, $time, $zone ) {
		return self::make( (int) $date->format( 'Y' ), (int) $date->format( 'n' ), (int) $date->format( 'j' ), $time, $zone );
	}

	/** The first of $month in the cursor's year. */
	protected static function first_of_month( $cursor, $month, $zone ) {
		return self::make( (int) $cursor->format( 'Y' ), $month, 1, '00:00:00', $zone );
	}

	/**
	 * Whether a date falls on one of the BYDAY weekdays.
	 */
	protected static function matches_weekday( $date, $byday ) {
		$dow = (int) $date->format( 'w' );
		foreach ( $byday as $bd ) {
			if ( $bd['day'] === $dow ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a date matches BYMONTHDAY.
	 */
	protected static function matches_monthday( $date, $bymonthday ) {
		$day       = (int) $date->format( 'j' );
		$days_in_m = (int) $date->format( 't' );
		foreach ( $bymonthday as $value ) {
			$value = (int) $value;
			$want  = $value < 0 ? $days_in_m + 1 + $value : $value;
			if ( $want === $day ) {
				return true;
			}
		}
		return false;
	}
}
