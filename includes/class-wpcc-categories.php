<?php
/**
 * Keyword-driven event categorisation.
 *
 * Each category owns a list of keywords, which may be plain phrases or
 * regular-expression fragments (e.g. "ward.*office"). Titles are matched
 * first; only then are long-form descriptions consulted, and only with
 * unambiguous multi-word literal phrases — see match_description() for why.
 *
 * @package WPCalendarCats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPCC_Categories {

	/** @var array|null Compiled matcher cache for the current request. */
	protected static $compiled = null;

	/** Drop the compiled cache — call after categories are saved. */
	public static function reset() {
		self::$compiled = null;
	}

	/**
	 * Categories with their compiled patterns, ordered.
	 *
	 * @return array
	 */
	public static function compiled() {
		if ( null !== self::$compiled ) {
			return self::$compiled;
		}

		$cats = WPCC_Settings::get_categories();
		$out  = array();

		foreach ( $cats as $cat ) {
			$cat['title_pattern'] = self::build_title_pattern( $cat['keywords'] );
			$cat['desc_pattern']  = self::build_description_pattern( $cat['keywords'] );
			$out[]                = $cat;
		}

		self::$compiled = $out;
		return $out;
	}

	/**
	 * Pick the best category for an event.
	 *
	 * @param string $title       Event title.
	 * @param string $description Event description.
	 * @return array Category array (always non-empty).
	 */
	public static function categorize( $title, $description = '' ) {
		$cats = self::compiled();
		if ( empty( $cats ) ) {
			return self::fallback();
		}

		// Pass 1 — title only. Highest confidence.
		foreach ( $cats as $cat ) {
			if ( 'other' === $cat['key'] || empty( $cat['title_pattern'] ) ) {
				continue;
			}
			if ( preg_match( $cat['title_pattern'], $title ) ) {
				return $cat;
			}
		}

		// Pass 2 — description, restricted to safe multi-word phrases. Catches
		// events whose defining detail (producer, venue, organiser) only ever
		// appears in the body text.
		if ( '' !== $description ) {
			$plain = wp_strip_all_tags( $description );
			foreach ( $cats as $cat ) {
				if ( 'other' === $cat['key'] || empty( $cat['desc_pattern'] ) ) {
					continue;
				}
				if ( preg_match( $cat['desc_pattern'], $plain ) ) {
					return $cat;
				}
			}
		}

		// Explicit "other" bucket if one exists, else the last category.
		foreach ( $cats as $cat ) {
			if ( 'other' === $cat['key'] ) {
				return $cat;
			}
		}
		return end( $cats );
	}

	/**
	 * A case-insensitive alternation of every keyword, each wrapped in word
	 * boundaries so "walk" can't match inside "sidewalk".
	 *
	 * @param array $keywords Keyword list.
	 * @return string Regex, or '' when there is nothing to match.
	 */
	protected static function build_title_pattern( $keywords ) {
		$parts = array();
		foreach ( $keywords as $keyword ) {
			$keyword = trim( $keyword );
			if ( '' === $keyword ) {
				continue;
			}
			$parts[] = '\b(?:' . str_replace( '/', '\/', $keyword ) . ')\b';
		}
		if ( empty( $parts ) ) {
			return '';
		}
		return self::validate( '/' . implode( '|', $parts ) . '/i', $keywords );
	}

	/**
	 * Description matching is deliberately conservative. A keyword only
	 * qualifies when it is both:
	 *
	 *   1. Free of regex metacharacters — a wildcard tuned for a short title
	 *      ("ward.*office") can otherwise span unrelated sentences in a long
	 *      description.
	 *   2. A multi-word phrase — a single generic word like "groundbreaking"
	 *      is too ambiguous out of context ("a groundbreaking new musical"),
	 *      whereas "ward night" is specific enough to trust.
	 *
	 * @param array $keywords Keyword list.
	 * @return string Regex, or ''.
	 */
	protected static function build_description_pattern( $keywords ) {
		$parts = array();
		foreach ( $keywords as $keyword ) {
			$keyword = trim( $keyword );
			if ( '' === $keyword || false === strpos( $keyword, ' ' ) ) {
				continue;
			}
			if ( preg_match( '/[.*+?^${}()|\[\]\\\\]/', $keyword ) ) {
				continue;
			}
			$parts[] = '\b' . preg_quote( $keyword, '/' ) . '\b';
		}
		if ( empty( $parts ) ) {
			return '';
		}
		return self::validate( '/' . implode( '|', $parts ) . '/i', $keywords );
	}

	/**
	 * Make sure a compiled pattern is usable. One malformed hand-entered
	 * keyword would otherwise take down the whole alternation, so on failure
	 * we rebuild from only the keywords that compile on their own.
	 *
	 * @param string $pattern  Candidate regex.
	 * @param array  $keywords Source keywords, for the fallback rebuild.
	 * @return string
	 */
	protected static function validate( $pattern, $keywords ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false !== @preg_match( $pattern, '' ) ) {
			return $pattern;
		}

		$safe = array();
		foreach ( $keywords as $keyword ) {
			$keyword = trim( $keyword );
			if ( '' === $keyword ) {
				continue;
			}
			$single = '/\b(?:' . str_replace( '/', '\/', $keyword ) . ')\b/i';
			// phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== @preg_match( $single, '' ) ) {
				$safe[] = '\b(?:' . str_replace( '/', '\/', $keyword ) . ')\b';
			}
		}

		if ( empty( $safe ) ) {
			return '';
		}
		$rebuilt = '/' . implode( '|', $safe ) . '/i';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false !== @preg_match( $rebuilt, '' ) ? $rebuilt : '';
	}

	/** Last-resort category, used only when none are configured at all. */
	public static function fallback() {
		return array(
			'key'      => 'other',
			'label'    => __( 'Other', 'wpcalendarcats' ),
			'icon'     => '📌',
			'color'    => '#5A5A5A',
			'group'    => 'community',
			'order'    => 999,
			'keywords' => array(),
		);
	}

	/**
	 * Which keywords are ignored for description matching, and why. Surfaced
	 * in the admin editor so the behaviour isn't a mystery.
	 *
	 * @param array $keywords Keyword list.
	 * @return array
	 */
	public static function description_keywords( $keywords ) {
		$used = array();
		foreach ( $keywords as $keyword ) {
			$keyword = trim( $keyword );
			if ( '' === $keyword || false === strpos( $keyword, ' ' ) ) {
				continue;
			}
			if ( preg_match( '/[.*+?^${}()|\[\]\\\\]/', $keyword ) ) {
				continue;
			}
			$used[] = $keyword;
		}
		return $used;
	}
}
