<?php
/**
 * The [wpcalendarcats] shortcode and its asset loading.
 *
 * @package WPCalendarCats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPCC_Shortcode {

	/** @var int Instances rendered on this page, used for unique ids. */
	protected static $instances = 0;

	public static function init() {
		add_shortcode( 'wpcalendarcats', array( __CLASS__, 'render' ) );
		// Historic alias from the iframe-only releases.
		add_shortcode( 'ward_calendar', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_style(
			'wpcalendarcats',
			WPCC_URL . 'assets/wpcc.css',
			array(),
			WPCC_VERSION
		);

		wp_register_script(
			'wpcalendarcats',
			WPCC_URL . 'assets/wpcc.js',
			array(),
			WPCC_VERSION,
			true
		);

		// Legacy auto-resize listener, only used by mode="iframe".
		wp_register_script(
			'wpcalendarcats-embed',
			WPCC_URL . 'assets/embed.js',
			array(),
			WPCC_VERSION,
			true
		);
	}

	/**
	 * Render the calendar.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		$settings = WPCC_Settings::get();

		$atts = shortcode_atts(
			array(
				'mode'        => 'native',
				'view'        => $settings['default_view'],
				'unit'        => $settings['default_unit'],
				'theme'       => $settings['default_theme'],
				'search'      => $settings['show_search'] ? 'yes' : 'no',
				'controls'    => $settings['show_controls'] ? 'yes' : 'no',
				'theme_toggle' => $settings['show_theme_toggle'] ? 'yes' : 'no',
				'zoom'        => $settings['show_zoom'] ? 'yes' : 'no',
				'categories'  => '',
				'max_width'   => '',
				'submit_url'  => $settings['submit_url'],
				// iframe mode only.
				'url'         => $settings['calendar_url'],
				'height'      => $settings['default_height'],
			),
			$atts,
			'wpcalendarcats'
		);

		if ( 'iframe' === $atts['mode'] ) {
			return self::render_iframe( $atts );
		}

		++self::$instances;
		$id = 'wpcc-' . self::$instances . '-' . wp_rand( 1000, 9999 );

		wp_enqueue_style( 'wpcalendarcats' );
		wp_enqueue_script( 'wpcalendarcats' );

		$only = array();
		if ( '' !== trim( $atts['categories'] ) ) {
			foreach ( explode( ',', $atts['categories'] ) as $key ) {
				$key = sanitize_key( trim( $key ) );
				if ( '' !== $key ) {
					$only[] = $key;
				}
			}
		}

		$config = array(
			'restUrl'      => rest_url( WPCC_REST::NAMESPACE_V1 . '/events' ),
			'view'         => in_array( $atts['view'], array( 'cards', 'calendar' ), true ) ? $atts['view'] : 'cards',
			'unit'         => in_array( $atts['unit'], array( 'week', 'month' ), true ) ? $atts['unit'] : 'month',
			'theme'        => 'dark' === $atts['theme'] ? 'dark' : 'light',
			'showSearch'   => self::is_yes( $atts['search'] ),
			'showControls' => self::is_yes( $atts['controls'] ),
			'showThemeToggle' => self::is_yes( $atts['theme_toggle'] ),
			'showZoom'     => self::is_yes( $atts['zoom'] ),
			'onlyCategories' => $only,
			'accent'       => $settings['accent'],
			'highlight'    => $settings['highlight'],
			'submitUrl'    => esc_url_raw( $atts['submit_url'] ),
			'submitLabel'  => $settings['submit_label'],
			'timezone'     => wp_timezone_string(),
			'locale'       => str_replace( '_', '-', get_locale() ),
			'strings'      => self::strings(),
		);

		$style = 'width:100%;';
		if ( '' !== $atts['max_width'] ) {
			$style .= 'max-width:' . absint( $atts['max_width'] ) . 'px;margin-left:auto;margin-right:auto;';
		}

		$classes = 'wpcc-app wpcc-theme-' . $config['theme'];

		ob_start();
		?>
		<div
			id="<?php echo esc_attr( $id ); ?>"
			class="<?php echo esc_attr( $classes ); ?>"
			style="<?php echo esc_attr( $style ); ?>"
			data-wpcc-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>"
		>
			<div class="wpcc-loading">
				<span class="wpcc-spinner" aria-hidden="true"></span>
				<?php esc_html_e( 'Loading events…', 'wpcalendarcats' ); ?>
			</div>
			<noscript>
				<p><?php esc_html_e( 'This calendar needs JavaScript enabled to display events.', 'wpcalendarcats' ); ?></p>
			</noscript>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Legacy iframe embed, for sites still pointing at a separately hosted app.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	protected static function render_iframe( $atts ) {
		$base = trim( (string) $atts['url'] );
		if ( '' === $base ) {
			return '<p>' . esc_html__( 'WPCalendarCats: mode="iframe" needs a calendar URL. Set one in Settings → Calendar Cats, or use the default self-hosted mode.', 'wpcalendarcats' ) . '</p>';
		}

		$base   = trailingslashit( esc_url_raw( $base ) );
		$src    = add_query_arg( 'embed', '1', $base );
		$height = max( 300, (int) $atts['height'] );
		$id     = 'wpcc-frame-' . wp_rand( 1000, 9999 );

		$wrapper = 'width:100%;';
		if ( '' !== $atts['max_width'] ) {
			$wrapper .= 'max-width:' . absint( $atts['max_width'] ) . 'px;margin-left:auto;margin-right:auto;';
		}

		wp_enqueue_script( 'wpcalendarcats-embed' );

		ob_start();
		?>
		<div class="wpcalendarcats-wrapper" style="<?php echo esc_attr( $wrapper ); ?>">
			<iframe
				id="<?php echo esc_attr( $id ); ?>"
				class="wpcalendarcats-iframe"
				src="<?php echo esc_url( $src ); ?>"
				title="<?php esc_attr_e( 'Community Events Calendar', 'wpcalendarcats' ); ?>"
				style="width:100%;height:<?php echo esc_attr( $height ); ?>px;border:0;display:block;"
				loading="lazy"
				scrolling="no"
			></iframe>
		</div>
		<?php
		return ob_get_clean();
	}

	protected static function is_yes( $value ) {
		return in_array( strtolower( (string) $value ), array( 'yes', 'true', '1', 'on' ), true );
	}

	/** Translatable UI strings handed to the front-end script. */
	protected static function strings() {
		return array(
			'week'          => __( 'Week', 'wpcalendarcats' ),
			'month'         => __( 'Month', 'wpcalendarcats' ),
			'today'         => __( 'Today', 'wpcalendarcats' ),
			'cards'         => __( 'Cards', 'wpcalendarcats' ),
			'calendar'      => __( 'Calendar', 'wpcalendarcats' ),
			'refresh'       => __( 'Refresh', 'wpcalendarcats' ),
			'prev'          => __( 'Previous', 'wpcalendarcats' ),
			'next'          => __( 'Next', 'wpcalendarcats' ),
			'lightMode'     => __( 'Light mode', 'wpcalendarcats' ),
			'zoom'          => __( 'Content zoom', 'wpcalendarcats' ),
			'zoomLevel'     => __( 'Content text zoom level', 'wpcalendarcats' ),
			'zoomIn'        => __( 'Increase text size', 'wpcalendarcats' ),
			'zoomOut'       => __( 'Decrease text size', 'wpcalendarcats' ),
			'zoomReset'     => __( 'Reset zoom to 100%', 'wpcalendarcats' ),
			'scrollableList' => __( 'scrollable event list', 'wpcalendarcats' ),
			'darkMode'      => __( 'Dark mode', 'wpcalendarcats' ),
			'searchPlaceholder' => __( 'Search events in this view…', 'wpcalendarcats' ),
			'clearSearch'   => __( 'Clear search', 'wpcalendarcats' ),
			'all'           => __( 'All', 'wpcalendarcats' ),
			'filterTip'     => __( 'Tap a category to show only that type of event. Tap more to add them to the view. Tap the same one again to remove it, or tap All to reset.', 'wpcalendarcats' ),
			'noEvents'      => __( 'No upcoming events in the selected range.', 'wpcalendarcats' ),
			'noMatches'     => __( 'No events match the selected filters.', 'wpcalendarcats' ),
			'noneInRange'   => __( 'No events in this range', 'wpcalendarcats' ),
			'earlier'       => __( 'Earlier this period', 'wpcalendarcats' ),
			'allDay'        => __( 'All day', 'wpcalendarcats' ),
			'loadFailed'    => __( 'Failed to load events', 'wpcalendarcats' ),
			'retry'         => __( 'Retry', 'wpcalendarcats' ),
			'openSource'    => __( 'Open in Google Calendar', 'wpcalendarcats' ),
			'addToCalendar' => __( 'Add to my calendar', 'wpcalendarcats' ),
			'close'         => __( 'Close', 'wpcalendarcats' ),
			'updated'       => __( 'Updated', 'wpcalendarcats' ),
			'searchNoneOne' => __( 'No events match "%s" in the current view.', 'wpcalendarcats' ),
			'searchCount'   => __( '%1$d events match "%2$s" in the current view', 'wpcalendarcats' ),
			'searchHint'    => __( 'Try a different search term, or switch to Week/Month to widen the current view.', 'wpcalendarcats' ),
		);
	}
}
