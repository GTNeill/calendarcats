<?php
/**
 * The [wpcalendarcats] shortcode and its asset loading.
 *
 * @package WPCalendarCats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The [wpcalendarcats] shortcode.
 */
class WPCC_Shortcode {

	/** @var int Instances rendered on this page, used for unique ids. */
	protected static $instances = 0;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_shortcode( 'wpcalendarcats', array( __CLASS__, 'render' ) );
		// Historic alias from the iframe-only releases.
		add_shortcode( 'ward_calendar', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register the front-end script and style.
	 */
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
				'mode'         => 'native',
				'view'         => $settings['default_view'],
				'unit'         => $settings['default_unit'],
				'theme'        => $settings['default_theme'],
				'search'       => $settings['show_search'] ? 'yes' : 'no',
				'controls'     => $settings['show_controls'] ? 'yes' : 'no',
				'theme_toggle' => $settings['show_theme_toggle'] ? 'yes' : 'no',
				'zoom'         => $settings['show_zoom'] ? 'yes' : 'no',
				'remember'     => $settings['show_remember'] ? 'yes' : 'no',
				'about'        => $settings['show_about'] ? 'yes' : 'no',
				'categories'   => '',
				'max_width'    => '',
				'submit_url'   => $settings['submit_url'],
				// iframe mode only.
				'url'          => $settings['calendar_url'],
				'height'       => $settings['default_height'],
			),
			$atts,
			'wpcalendarcats'
		);

		if ( 'iframe' === $atts['mode'] ) {
			return self::render_iframe( $atts );
		}

		++self::$instances;
		$id = 'wpcc-' . self::$instances;

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
			'restUrl'         => rest_url( WPCC_REST::NAMESPACE_V1 . '/events' ),
			'view'            => in_array( $atts['view'], array( 'cards', 'calendar' ), true ) ? $atts['view'] : 'cards',
			'unit'            => in_array( $atts['unit'], array( 'day', 'week', 'month' ), true ) ? $atts['unit'] : 'month',
			'theme'           => 'dark' === $atts['theme'] ? 'dark' : 'light',
			'showSearch'      => self::is_yes( $atts['search'] ),
			'showControls'    => self::is_yes( $atts['controls'] ),
			'showThemeToggle' => self::is_yes( $atts['theme_toggle'] ),
			'showZoom'        => self::is_yes( $atts['zoom'] ),
			'showRemember'    => self::is_yes( $atts['remember'] ),
			'showAbout'       => self::is_yes( $atts['about'] ),
			'contactEmail'    => sanitize_email( $settings['contact_email'] ),
			'onlyCategories'  => $only,
			'accent'          => $settings['accent'],
			'highlight'       => $settings['highlight'],
			'submitUrl'       => esc_url_raw( $atts['submit_url'] ),
			'submitLabel'     => $settings['submit_label'],
			'timezone'        => wp_timezone_string(),
			'locale'          => str_replace( '_', '-', get_locale() ),
			'strings'         => self::strings(),
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
			<div class="wpcc-loading" role="status">
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
		$saved = trim( (string) WPCC_Settings::get()['calendar_url'] );
		$base  = trim( (string) $atts['url'] );

		// Only the saved URL may be embedded. A shortcode author may point at
		// another page on the same host, but not at an arbitrary site (contributors
		// cannot add iframes themselves, and this must not become a way around that).
		if ( '' === $saved ) {
			return '<p>' . esc_html__( 'WPCalendarCats: mode="iframe" needs a calendar URL. Set one in Settings → Calendar Cats, or use the default self-hosted mode.', 'wpcalendarcats' ) . '</p>';
		}
		if ( '' === $base || wp_parse_url( $base, PHP_URL_HOST ) !== wp_parse_url( $saved, PHP_URL_HOST ) ) {
			$base = $saved;
		}

		$base   = trailingslashit( esc_url_raw( $base ) );
		$src    = add_query_arg( 'embed', '1', $base );
		$height = max( 300, (int) $atts['height'] );
		++self::$instances;
		$id = 'wpcc-frame-' . self::$instances;

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
			></iframe>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Whether a shortcode value means "yes".
	 */
	protected static function is_yes( $value ) {
		return in_array( strtolower( (string) $value ), array( 'yes', 'true', '1', 'on' ), true );
	}

	/** Translatable UI strings handed to the front-end script. */
	protected static function strings() {
		return array(
			'day'                  => __( 'Today', 'wpcalendarcats' ),
			'week'                 => __( 'Week', 'wpcalendarcats' ),
			'month'                => __( 'Month', 'wpcalendarcats' ),
			'today'                => __( 'Today', 'wpcalendarcats' ),
			'cards'                => __( 'Cards', 'wpcalendarcats' ),
			'calendar'             => __( 'Calendar', 'wpcalendarcats' ),
			'refresh'              => __( 'Refresh', 'wpcalendarcats' ),
			'prev'                 => __( 'Previous', 'wpcalendarcats' ),
			'prevDay'              => __( 'Previous day', 'wpcalendarcats' ),
			'nextDay'              => __( 'Next day', 'wpcalendarcats' ),
			'next'                 => __( 'Next', 'wpcalendarcats' ),
			'lightMode'            => __( 'Light mode', 'wpcalendarcats' ),
			'zoom'                 => __( 'Content zoom', 'wpcalendarcats' ),
			'zoomLevel'            => __( 'Content text zoom level', 'wpcalendarcats' ),
			'zoomIn'               => __( 'Increase text size', 'wpcalendarcats' ),
			'zoomOut'              => __( 'Decrease text size', 'wpcalendarcats' ),
			'zoomReset'            => __( 'Reset zoom to 100%', 'wpcalendarcats' ),
			'scrollableList'       => __( 'scrollable event list', 'wpcalendarcats' ),
			'eventSingular'        => __( 'event', 'wpcalendarcats' ),
			'eventPlural'          => __( 'events', 'wpcalendarcats' ),
			'darkMode'             => __( 'Dark mode', 'wpcalendarcats' ),
			'loading'              => __( 'Loading events…', 'wpcalendarcats' ),
			'newTab'               => __( 'opens in a new tab', 'wpcalendarcats' ),
			'searchLabel'          => __( 'Search events', 'wpcalendarcats' ),
			'eventDetails'         => __( 'Event details', 'wpcalendarcats' ),
			'searchPlaceholder'    => __( 'Search events in this view…', 'wpcalendarcats' ),
			'clearSearch'          => __( 'Clear search', 'wpcalendarcats' ),
			'all'                  => __( 'All', 'wpcalendarcats' ),
			'filterTip'            => __( 'Tap a category to show only that type of event. Tap more to add them to the view. Tap the same one again to remove it, or tap All to reset.', 'wpcalendarcats' ),
			'rememberFilter'       => __( 'Remember my filter', 'wpcalendarcats' ),
			'rememberTitle'        => __( "Saves your chosen categories in a cookie on this device, so they're the default the next time you visit. Leave unchecked and your filter resets to All next visit.", 'wpcalendarcats' ),
			'about'                => __( 'About this calendar', 'wpcalendarcats' ),
			'aboutIntro'           => __( "This calendar brings together public events from several neighborhood organizations into one place, so you don't have to check each one separately. It's a community view, not any single organization's calendar — every event here still belongs to, and is run by, the group that posted it.", 'wpcalendarcats' ),
			'aboutSources'         => __( 'Where the events come from', 'wpcalendarcats' ),
			'visitSite'            => __( 'Visit site', 'wpcalendarcats' ),
			'aboutCorrections'     => __( 'Questions about a specific event, or want it corrected or removed? Reach out to the organization that posted it — the source list above is the fastest way to find who to contact.', 'wpcalendarcats' ),
			'aboutContact'         => __( "Questions about the site itself, or want your organization's calendar added?", 'wpcalendarcats' ),
			'aboutContactLink'     => __( 'Get in touch', 'wpcalendarcats' ),
			'aboutPrivacy'         => __( 'Privacy', 'wpcalendarcats' ),
			'aboutPrivacyBody'     => __( "This calendar doesn't track you and doesn't set any analytics cookies of its own.", 'wpcalendarcats' ),
			'aboutPrivacyRemember' => __( 'The only cookie it can set is optional: next to the category filter, a "Remember my filter" checkbox lets you save which categories you have selected so they are the default view next time you visit. It is off by default, and nothing is stored unless you tick it. The cookie holds only the names of the categories you picked, nothing that identifies you, and stays on your own device for up to a year. Unticking the box, or clearing your browser cookies, removes it.', 'wpcalendarcats' ),
			'aboutLoading'         => __( 'Loading sources…', 'wpcalendarcats' ),
			'aboutNoSources'       => __( "The source list isn't available right now.", 'wpcalendarcats' ),
			'continued'            => __( 'continued', 'wpcalendarcats' ),
			'continuedAbbr'        => __( "cont'd", 'wpcalendarcats' ),
			'through'              => __( 'through', 'wpcalendarcats' ),
			'noEventsDay'          => __( 'No events scheduled for this day.', 'wpcalendarcats' ),
			'alsoListedBy'         => __( 'Also listed by', 'wpcalendarcats' ),
			'noEvents'             => __( 'No upcoming events in the selected range.', 'wpcalendarcats' ),
			'noMatches'            => __( 'No events match the selected filters.', 'wpcalendarcats' ),
			'noneInRange'          => __( 'No events in this range', 'wpcalendarcats' ),
			'earlier'              => __( 'Earlier this period', 'wpcalendarcats' ),
			'allDay'               => __( 'All day', 'wpcalendarcats' ),
			'loadFailed'           => __( 'Failed to load events', 'wpcalendarcats' ),
			'retry'                => __( 'Retry', 'wpcalendarcats' ),
			'openSource'           => __( 'Open in Google Calendar', 'wpcalendarcats' ),
			'addToCalendar'        => __( 'Add to my calendar', 'wpcalendarcats' ),
			'close'                => __( 'Close', 'wpcalendarcats' ),
			'updated'              => __( 'Updated', 'wpcalendarcats' ),
			/* translators: %s: the search text */
			'searchNoneOne'        => __( 'No events match "%s" in the current view.', 'wpcalendarcats' ),
			/* translators: 1: number of matching events, 2: the search text */
			'searchCount'          => __( '%1$d events match "%2$s" in the current view', 'wpcalendarcats' ),
			'searchHint'           => __( 'Try a different search term, or switch to Week or Month to widen the current view.', 'wpcalendarcats' ),
		);
	}
}
