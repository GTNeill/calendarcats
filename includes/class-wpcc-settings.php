<?php
/**
 * Options storage: feeds, display defaults, and the category list.
 *
 * @package CalendarCats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options storage and defaults.
 */
class WPCC_Settings {

	const OPTION      = 'calendarcats_settings';
	const OPTION_CATS = 'calendarcats_categories';

	/** Option names used before the 3.0 rename. */
	const LEGACY_OPTION      = 'wpcalendarcats_settings';
	const LEGACY_OPTION_CATS = 'wpcalendarcats_categories';

	/** Google's public iCal URL template, used when a feed is given as a calendar id. */
	const GCAL_ICAL = 'https://calendar.google.com/calendar/ical/%s/public/basic.ics';

	/**
	 * Carry settings over from the pre-3.0 option names. Runs once: it only
	 * copies when the new option doesn't exist yet, and leaves the old rows in
	 * place so a rollback still finds its data.
	 */
	public static function migrate_legacy() {
		$missing = new stdClass();
		foreach ( array( self::LEGACY_OPTION => self::OPTION, self::LEGACY_OPTION_CATS => self::OPTION_CATS ) as $old => $new ) {
			if ( $missing !== get_option( $new, $missing ) ) {
				continue;
			}
			$value = get_option( $old, $missing );
			if ( $missing !== $value ) {
				update_option( $new, $value, false );
			}
		}
	}

	/**
	 * Defaults for a fresh install. The two seeded feeds are the 40th Ward's
	 * public calendars; replace them under Settings → CalendarCats.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'feeds'             => "c_50dc8883383193a9f6ba4d86cd23a836978e1d42028f0e7bb263955d5539912c@group.calendar.google.com | 40th Ward Events\n"
				. 'c_05dba706bb25f28f63bfc0b821c9f8d5e29d9f2b105e78949388b675eb801572@group.calendar.google.com | 40th Ward Community',
			// Extra source types. Blank by default so upgrading never adds
			// calendars to an existing site; see the admin screen for examples.
			'feeds_squarespace' => '',
			'feeds_rss'         => '',
			'farmers_markets'   => 0,
			'accent'            => '#147671',
			'highlight'         => '#CF2C28',
			'default_view'      => 'cards',
			'default_unit'      => 'month',
			'default_theme'     => 'light',
			'show_theme_toggle' => 1,
			'show_zoom'         => 1,
			'show_search'       => 1,
			'show_controls'     => 1,
			'show_remember'     => 1,
			'show_about'        => 1,
			'contact_email'     => '',
			'submit_url'        => '',
			'submit_label'      => 'Submit Your Event',
			'cache_minutes'     => 15,
			'group_government'  => 'Your Government',
			'group_community'   => 'Your Community',
			// Legacy iframe mode — kept so existing embeds keep working.
			'calendar_url'      => '',
			'default_height'    => 1000,
		);
	}

	/**
	 * Current settings, defaults filled in.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get_option_value( $key, $default = null ) {
		$all = self::get();
		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	/** How long feed responses stay cached, in seconds. */
	public static function get_cache_seconds() {
		$minutes = (int) self::get_option_value( 'cache_minutes', 15 );
		if ( $minutes < 1 ) {
			$minutes = 1;
		}
		if ( $minutes > 1440 ) {
			$minutes = 1440;
		}
		return $minutes * MINUTE_IN_SECONDS;
	}

	/**
	 * Parse every feed textarea into usable sources.
	 *
	 * Each line is a URL (or, for the iCal box, a bare Google Calendar id),
	 * then an optional display name, then an optional public page that
	 * presents the calendar — the About popup links to that page:
	 *
	 *   https://example.com/events.ics | Village Events | https://example.com/events
	 *   abc123@group.calendar.google.com | Ward Events
	 *
	 * The line format is identical to the hosted Community Calendar app's
	 * admin panel, so lines can be copied between the two verbatim.
	 *
	 * @return array<int,array{type:string,url:string,name:string,page:string}>
	 */
	public static function get_feeds() {
		$feeds = array();

		foreach ( self::parse_lines( (string) self::get_option_value( 'feeds', '' ) ) as $row ) {
			$url = self::normalize_feed( $row['value'] );
			if ( '' === $url ) {
				continue;
			}
			$feeds[] = array(
				'type' => 'ics',
				'url'  => $url,
				'name' => '' !== $row['name'] ? $row['name'] : __( 'Calendar', 'calendarcats' ),
				'page' => $row['page'],
			);
		}

		// Squarespace's feed URL is already its events page, so it doubles as the link.
		foreach ( self::parse_lines( (string) self::get_option_value( 'feeds_squarespace', '' ) ) as $row ) {
			if ( ! preg_match( '#^https?://#i', $row['value'] ) ) {
				continue;
			}
			$feeds[] = array(
				'type' => 'squarespace',
				'url'  => esc_url_raw( $row['value'] ),
				'name' => '' !== $row['name'] ? $row['name'] : __( 'Events', 'calendarcats' ),
				'page' => '' !== $row['page'] ? $row['page'] : esc_url_raw( $row['value'] ),
			);
		}

		foreach ( self::parse_lines( (string) self::get_option_value( 'feeds_rss', '' ) ) as $row ) {
			if ( ! preg_match( '#^https?://#i', $row['value'] ) ) {
				continue;
			}
			$feeds[] = array(
				'type' => 'rss',
				'url'  => esc_url_raw( $row['value'] ),
				'name' => '' !== $row['name'] ? $row['name'] : __( 'Events', 'calendarcats' ),
				'page' => $row['page'],
			);
		}

		if ( self::get_option_value( 'farmers_markets', 0 ) ) {
			$feeds[] = array(
				'type' => 'builtin',
				'url'  => WPCC_Connectors::FARMERS_MARKET_PAGE_URL,
				'name' => WPCC_Connectors::FARMERS_MARKET_NAME,
				'page' => WPCC_Connectors::FARMERS_MARKET_PAGE_URL,
			);
		}

		return $feeds;
	}

	/**
	 * Split a feeds textarea into value / name / page rows, dropping blanks
	 * and # comments.
	 *
	 * @param string $raw Textarea contents.
	 * @return array<int,array{value:string,name:string,page:string}>
	 */
	public static function parse_lines( $raw ) {
		$rows = array();

		foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
				continue;
			}

			$parts = array_map( 'trim', explode( '|', $line ) );
			$value = isset( $parts[0] ) ? $parts[0] : '';
			if ( '' === $value ) {
				continue;
			}

			$page = isset( $parts[2] ) ? $parts[2] : '';
			if ( '' !== $page && ! preg_match( '#^https?://#i', $page ) ) {
				$page = ''; // Only a full http(s) URL is a usable link.
			}

			$rows[] = array(
				'value' => $value,
				'name'  => isset( $parts[1] ) ? $parts[1] : '',
				'page'  => '' === $page ? '' : esc_url_raw( $page ),
			);
		}

		return $rows;
	}

	/**
	 * Turn one feed line into an https URL.
	 *
	 * @param string $value URL, webcal URL, or Google Calendar id.
	 * @return string Empty when unusable.
	 */
	public static function normalize_feed( $value ) {
		$value = trim( $value );

		if ( preg_match( '#^webcal://#i', $value ) ) {
			$value = 'https://' . substr( $value, 9 );
		}

		if ( preg_match( '#^https?://#i', $value ) ) {
			return esc_url_raw( $value );
		}

		// Anything else is treated as a Google Calendar id.
		if ( preg_match( '/^[^\s\/\\\\]+$/', $value ) ) {
			return esc_url_raw( sprintf( self::GCAL_ICAL, rawurlencode( $value ) ) );
		}

		return '';
	}

	/* ── Categories ──────────────────────────────────────────────────── */

	/**
	 * The category list, ordered, with "other" forced last.
	 *
	 * @return array
	 */
	public static function get_categories() {
		$cats = get_option( self::OPTION_CATS, null );

		if ( ! is_array( $cats ) || empty( $cats ) ) {
			$cats = wpcc_default_categories();
		}

		$clean = array();
		foreach ( $cats as $cat ) {
			$normalized = self::normalize_category( $cat );
			if ( $normalized ) {
				$clean[] = $normalized;
			}
		}

		usort(
			$clean,
			function ( $a, $b ) {
				return $a['order'] <=> $b['order'];
			}
		);

		// "other" is the catch-all, so it can only ever be evaluated last.
		$other  = null;
		$others = array();
		foreach ( $clean as $cat ) {
			if ( 'other' === $cat['key'] ) {
				$other = $cat;
				continue;
			}
			$others[] = $cat;
		}
		if ( $other ) {
			$others[] = $other;
		}

		// Re-index order so it always matches position.
		foreach ( $others as $i => $cat ) {
			$others[ $i ]['order'] = $i;
		}

		return $others;
	}

	/**
	 * Coerce one stored category into a complete, safe shape.
	 *
	 * @param mixed $cat Raw stored value.
	 * @return array|null
	 */
	public static function normalize_category( $cat ) {
		if ( ! is_array( $cat ) ) {
			return null;
		}

		$key = isset( $cat['key'] ) ? sanitize_key( $cat['key'] ) : '';
		if ( '' === $key ) {
			return null;
		}

		$keywords = array();
		if ( isset( $cat['keywords'] ) ) {
			$list = is_array( $cat['keywords'] ) ? $cat['keywords'] : preg_split( '/\r?\n/', (string) $cat['keywords'] );
			foreach ( $list as $keyword ) {
				$keyword = trim( (string) $keyword );
				if ( '' !== $keyword ) {
					$keywords[] = $keyword;
				}
			}
		}

		$color = isset( $cat['color'] ) ? sanitize_hex_color( $cat['color'] ) : '';
		if ( ! $color ) {
			$color = '#5A5A5A';
		}

		$icon  = isset( $cat['icon'] ) ? trim( (string) $cat['icon'] ) : '';
		$group = isset( $cat['group'] ) ? sanitize_key( $cat['group'] ) : 'community';
		if ( '' === $group ) {
			$group = 'community';
		}

		return array(
			'key'      => $key,
			'label'    => isset( $cat['label'] ) ? sanitize_text_field( $cat['label'] ) : $key,
			'icon'     => self::sanitize_icon( $icon ),
			'color'    => $color,
			'group'    => $group,
			'order'    => isset( $cat['order'] ) ? (int) $cat['order'] : 0,
			'keywords' => 'other' === $key ? array() : $keywords,
		);
	}

	/**
	 * An icon is either a short text glyph (emoji) or an image URL.
	 *
	 * @param string $icon Raw icon value.
	 * @return string
	 */
	public static function sanitize_icon( $icon ) {
		if ( '' === $icon ) {
			return '';
		}
		if ( preg_match( '#^(https?://|/)#i', $icon ) ) {
			return esc_url_raw( $icon );
		}
		// Emoji and other glyphs: keep it short, strip markup.
		$icon = wp_strip_all_tags( $icon );

		/*
		 * Cap the length on a codepoint boundary. A byte-based substr() would
		 * cut a ZWJ sequence like 👨‍👩‍👧‍👦 mid-codepoint, and the invalid UTF-8
		 * that produces makes wp_json_encode() fail for the whole response.
		 * 16 characters leaves room for the longest emoji sequences in use.
		 */
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $icon, 0, 16, 'UTF-8' );
		}

		$chars = preg_split( '//u', $icon, -1, PREG_SPLIT_NO_EMPTY );
		if ( is_array( $chars ) ) {
			return implode( '', array_slice( $chars, 0, 16 ) );
		}

		// Not valid UTF-8 at all: trim any trailing partial sequence.
		return preg_replace( '/[\x80-\xFF]+$/', '', substr( $icon, 0, 24 ) );
	}

	/**
	 * Persist a category list.
	 *
	 * @param array $cats Category arrays.
	 * @return int Number saved.
	 */
	public static function save_categories( $cats ) {
		$clean = array();
		$seen  = array();

		foreach ( array_values( (array) $cats ) as $i => $cat ) {
			$normalized = self::normalize_category( $cat );
			if ( ! $normalized ) {
				continue;
			}
			// Keys identify categories, so collisions have to be resolved.
			$key = $normalized['key'];
			if ( isset( $seen[ $key ] ) ) {
				$suffix = 2;
				while ( isset( $seen[ $key . '-' . $suffix ] ) ) {
					++$suffix;
				}
				$key               = $key . '-' . $suffix;
				$normalized['key'] = $key;
			}
			$seen[ $key ]        = true;
			$normalized['order'] = $i;
			$clean[]             = $normalized;
		}

		update_option( self::OPTION_CATS, $clean, false );
		WPCC_Categories::reset();
		return count( $clean );
	}

	/** Reinstate the shipped category set. */
	public static function restore_default_categories() {
		update_option( self::OPTION_CATS, wpcc_default_categories(), false );
		WPCC_Categories::reset();
	}

	/**
	 * Human label for a category group.
	 *
	 * @param string $group Group key.
	 * @return string
	 */
	public static function group_label( $group ) {
		$settings = self::get();
		$map      = array(
			'government' => $settings['group_government'],
			'community'  => $settings['group_community'],
		);
		if ( isset( $map[ $group ] ) && '' !== trim( $map[ $group ] ) ) {
			return $map[ $group ];
		}
		return ucwords( str_replace( array( '-', '_' ), ' ', $group ) );
	}

	/**
	 * Groups in display order, derived from where each group's first category
	 * sits in the overall order — so reordering categories reorders sections
	 * without a second setting to keep in sync.
	 *
	 * @return array<int,array{key:string,label:string,keys:array}>
	 */
	public static function get_groups() {
		$groups = array();
		foreach ( self::get_categories() as $cat ) {
			if ( ! isset( $groups[ $cat['group'] ] ) ) {
				$groups[ $cat['group'] ] = array(
					'key'   => $cat['group'],
					'label' => self::group_label( $cat['group'] ),
					'keys'  => array(),
				);
			}
			$groups[ $cat['group'] ]['keys'][] = $cat['key'];
		}
		return array_values( $groups );
	}
}
