<?php
/**
 * Cross-source event de-duplication.
 *
 * The calendar aggregates organisations whose service areas overlap, so the
 * same real-world event is often published by more than one of them. Each
 * source mints its own UID, so an exact-id check never fires across sources.
 *
 * The constraint is asymmetric: hiding a real event is much worse than
 * showing a duplicate. Two classes that are 98% similar by character ratio —
 * "Creative Movement (Ages 5-7)" at 16:00 and "(Ages 7-10)" at 17:00 — are
 * genuinely different events, so similarity ratios are deliberately NOT used.
 * Matching is exact on a normalised title within a single day, then gated a
 * second time on whether the times and locations can describe one event.
 * Anything ambiguous is kept.
 *
 * Ported from the hosted Community Calendar app (api/lib/dedupe.ts).
 *
 * @package WPCalendarCats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collapses the same event published by several sources.
 */
class WPCC_Dedupe {

	/** Two events whose starts differ by more than this are separate sessions. */
	const START_TOLERANCE_MIN = 30;

	/**
	 * Collapse duplicates across all sources.
	 *
	 * Events must carry a numeric `_rank` (lower = more authoritative, i.e.
	 * earlier in the feed list). Survivors gain `sources` (every publisher,
	 * primary first) and `duplicateCount`.
	 *
	 * @param array $events Shaped events.
	 * @return array
	 */
	public static function dedupe( $events ) {
		// Exact id collisions first — cheap, and still possible within one feed.
		$by_id = array();
		$input = array();
		foreach ( $events as $ev ) {
			if ( isset( $by_id[ $ev['id'] ] ) ) {
				continue;
			}
			$by_id[ $ev['id'] ] = true;
			$input[]            = $ev;
		}

		$buckets = array();
		$out     = array();
		foreach ( $input as $ev ) {
			$day = self::day_of( $ev );
			if ( '' === $day ) {
				$out[] = $ev;
				continue;
			}
			$buckets[ $day ][] = $ev;
		}

		foreach ( $buckets as $bucket ) {
			$groups = array();

			foreach ( $bucket as $ev ) {
				// Transitive: join the first group containing any matching member.
				$placed = false;
				foreach ( $groups as $gi => $group ) {
					foreach ( $group as $member ) {
						if ( self::is_duplicate( $member, $ev ) ) {
							$groups[ $gi ][] = $ev;
							$placed          = true;
							break 2;
						}
					}
				}
				if ( ! $placed ) {
					$groups[] = array( $ev );
				}
			}

			foreach ( $groups as $group ) {
				$out[] = 1 === count( $group ) ? $group[0] : self::merge( $group );
			}
		}

		return $out;
	}

	/** Pick the survivor and backfill what it lacks from the records it replaces. */
	protected static function merge( $group ) {
		usort(
			$group,
			function ( $a, $b ) {
				// A live record beats a cancelled one; then the most
				// authoritative source; then the most complete record.
				$ca = self::is_cancelled( $a ) ? 1 : 0;
				$cb = self::is_cancelled( $b ) ? 1 : 0;
				if ( $ca !== $cb ) {
					return $ca - $cb;
				}
				$ra = isset( $a['_rank'] ) ? $a['_rank'] : 999;
				$rb = isset( $b['_rank'] ) ? $b['_rank'] : 999;
				if ( $ra !== $rb ) {
					return $ra - $rb;
				}
				return self::richness( $b ) - self::richness( $a );
			}
		);

		$merged = $group[0];
		foreach ( array_slice( $group, 1 ) as $dropped ) {
			foreach ( array( 'location', 'description', 'htmlLink' ) as $field ) {
				if ( '' === trim( (string) $merged[ $field ] ) && '' !== trim( (string) $dropped[ $field ] ) ) {
					$merged[ $field ] = $dropped[ $field ];
				}
			}
		}

		// Credit every organisation that published it, primary first.
		$names = array();
		foreach ( $group as $ev ) {
			$name = (string) $ev['organizer'];
			if ( '' !== $name && ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}
		$merged['sources']        = $names;
		$merged['duplicateCount'] = count( $group );

		return $merged;
	}

	/** The core decision: both the title gate and the time/place gate must agree. */
	public static function is_duplicate( $a, $b ) {
		$day = self::day_of( $a );
		if ( '' === $day || self::day_of( $b ) !== $day ) {
			return false;
		}

		$rel = self::title_relation( self::normalize_title( $a['title'] ), self::normalize_title( $b['title'] ) );
		if ( 'none' === $rel ) {
			return false;
		}

		$ma = self::start_minutes( $a );
		$mb = self::start_minutes( $b );

		// One source lists it all-day, the other with real times. Same day +
		// same title is enough; nobody publishes an all-day placeholder and a
		// distinct timed event under one name on one day.
		if ( null === $ma || null === $mb ) {
			return ( ! empty( $a['isAllDay'] ) ) !== ( ! empty( $b['isAllDay'] ) ) || 'equal' === $rel;
		}

		$delta = abs( $ma - $mb );

		// Identical start is the strongest signal, and the only tier a subset
		// title match may use.
		if ( 0 === $delta ) {
			return self::locations_compatible( $a['location'], $b['location'] );
		}
		if ( 'subset' === $rel ) {
			return false;
		}

		// Near-but-not-equal starts are ambiguous: two sources rounding
		// differently, or two real sessions. Require the venue to corroborate.
		if ( $delta <= self::START_TOLERANCE_MIN ) {
			$la = self::normalize_location( $a['location'] );
			$lb = self::normalize_location( $b['location'] );
			// Both blank is exactly how a two-session day looks. Keep both.
			if ( '' === $la && '' === $lb ) {
				return false;
			}
			return self::locations_compatible( $a['location'], $b['location'] );
		}

		return false;
	}

	/**
	 * Collapse a title to a comparable form. Digits are deliberately
	 * preserved — "(ages 5-7)" vs "(ages 7-10)" is the only thing separating
	 * two real classes in the live feeds.
	 */
	public static function normalize_title( $raw ) {
		$raw = (string) $raw;
		$t   = strtolower( html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		// Unicode punctuation to ASCII, so a source that curls its quotes
		// still equals one that doesn't.
		$t = preg_replace( '/[\x{2010}-\x{2015}\x{2212}]/u', '-', $t );
		$t = preg_replace( '/[\x{2018}\x{2019}]/u', "'", $t );
		$t = preg_replace( '/[\x{201C}\x{201D}]/u', '"', $t );

		// Trailing publisher noise: separators, not part of the event name.
		$t = preg_replace( '/\s*::.*$/s', '', $t );
		$t = preg_replace( '/\s*\|.*$/s', '', $t );
		$t = preg_replace( '/\s*[-\x{2013}\x{2014}]?\s*\(?\s*(presented|hosted|sponsored|organized)\s+by\b[^)]*\)?\s*$/iu', '', $t );

		// Leading "[Org]" / "Org:" tags.
		$t    = preg_replace( '/^\s*\[[^\]]{1,40}\]\s*/', '', $t );
		$half = strlen( $raw ) / 2;
		$t    = preg_replace_callback(
			'/^\s*[a-z0-9 .\'&-]{2,40}:\s+/',
			function ( $m ) use ( $half ) {
				// Only strip when it reads like an org tag, not "Q&A: ..." titles.
				return strlen( trim( $m[0] ) ) < $half ? '' : $m[0];
			},
			$t
		);

		$t = preg_replace( '/[^a-z0-9 ]+/', ' ', $t );
		$t = preg_replace( '/^(the|a|an)\s+/', '', $t );

		return trim( preg_replace( '/\s+/', ' ', $t ) );
	}

	/** Compare locations loosely: city/state/zip tails and punctuation are noise. */
	public static function normalize_location( $raw ) {
		$l = strtolower( html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$l = preg_replace( '/[^a-z0-9 ]+/', ' ', $l );
		$l = preg_replace( '/\b(chicago|illinois|il|usa|united states)\b/', ' ', $l );
		$l = preg_replace( '/\b\d{5}(\d{4})?\b/', ' ', $l );
		$l = preg_replace( '/\b(street|st|avenue|ave|road|rd|boulevard|blvd|drive|dr|north|south|east|west|n|s|e|w)\b/', ' ', $l );
		return trim( preg_replace( '/\s+/', ' ', $l ) );
	}

	/**
	 * Could these describe the same place? An empty location is compatible
	 * with anything — absence is not evidence of a different venue.
	 */
	public static function locations_compatible( $a, $b ) {
		$x = self::normalize_location( $a );
		$y = self::normalize_location( $b );
		if ( '' === $x || '' === $y ) {
			return true;
		}
		if ( $x === $y || false !== strpos( $x, $y ) || false !== strpos( $y, $x ) ) {
			return true;
		}

		$xs = array_flip(
			array_filter(
				explode( ' ', $x ),
				function ( $w ) {
					return strlen( $w ) > 3;
				}
			)
		);
		$ys = array_values(
			array_filter(
				explode( ' ', $y ),
				function ( $w ) {
					return strlen( $w ) > 3;
				}
			)
		);
		if ( ! $xs || ! $ys ) {
			return true;
		}

		$shared = 0;
		foreach ( $ys as $w ) {
			if ( isset( $xs[ $w ] ) ) {
				++$shared;
			}
		}
		return $shared >= min( 2, min( count( $xs ), count( $ys ) ) );
	}

	/** Exact normalised equality, or a strict token subset ("Bike the Drive" vs "Bike the Drive 2026"). */
	protected static function title_relation( $a, $b ) {
		if ( $a === $b ) {
			return 'equal';
		}
		$ta = array_values( array_filter( explode( ' ', $a ), 'strlen' ) );
		$tb = array_values( array_filter( explode( ' ', $b ), 'strlen' ) );
		if ( count( $ta ) <= count( $tb ) ) {
			$short = $ta;
			$long  = $tb;
		} else {
			$short = $tb;
			$long  = $ta;
		}
		if ( count( $short ) < 2 ) {
			return 'none';
		}
		return ! array_diff( $short, $long ) ? 'subset' : 'none';
	}

	/**
	 * The calendar day (Y-m-d) of an event start.
	 */
	protected static function day_of( $ev ) {
		return isset( $ev['start'] ) ? substr( (string) $ev['start'], 0, 10 ) : '';
	}

	/** Minutes since the epoch of a timed start, or null for all-day. */
	protected static function start_minutes( $ev ) {
		if ( ! empty( $ev['isAllDay'] ) ) {
			return null;
		}
		// Starts are naive local wall-clock strings; comparing them as UTC is
		// consistent for two events in the same zone.
		$t = strtotime( $ev['start'] . ' UTC' );
		return false === $t ? null : $t / 60;
	}

	/**
	 * Score how much detail an event carries.
	 */
	protected static function richness( $ev ) {
		$n = 0;
		if ( '' !== trim( (string) $ev['description'] ) ) {
			$n += 2;
		}
		if ( '' !== trim( (string) $ev['location'] ) ) {
			$n += 2;
		}
		if ( '' !== trim( (string) $ev['htmlLink'] ) ) {
			++$n;
		}
		if ( '' !== (string) $ev['end'] ) {
			++$n;
		}
		if ( empty( $ev['isAllDay'] ) ) {
			++$n; // A timed record beats an all-day one.
		}
		return $n;
	}

	/**
	 * Whether an event is marked cancelled.
	 */
	protected static function is_cancelled( $ev ) {
		return 'CANCELLED' === strtoupper( isset( $ev['status'] ) ? (string) $ev['status'] : '' );
	}
}
