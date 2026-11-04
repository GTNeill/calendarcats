<?php
/**
 * Admin screens: general settings and the category editor.
 *
 * @package WPCalendarCats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screens and form handlers.
 */
class WPCC_Admin {

	const PAGE_MAIN = 'wpcalendarcats';
	const PAGE_CATS = 'wpcalendarcats-categories';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_wpcc_save_categories', array( __CLASS__, 'handle_save_categories' ) );
		add_action( 'admin_post_wpcc_restore_categories', array( __CLASS__, 'handle_restore_categories' ) );
		add_action( 'admin_post_wpcc_import_categories', array( __CLASS__, 'handle_import_categories' ) );
		add_action( 'admin_post_wpcc_flush_cache', array( __CLASS__, 'handle_flush_cache' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Add the admin menu pages.
	 */
	public static function add_menu() {
		add_menu_page(
			__( 'Calendar Cats', 'wpcalendarcats' ),
			__( 'Calendar Cats', 'wpcalendarcats' ),
			'manage_options',
			self::PAGE_MAIN,
			array( __CLASS__, 'render_main' ),
			'dashicons-calendar-alt',
			58
		);

		add_submenu_page(
			self::PAGE_MAIN,
			__( 'Settings', 'wpcalendarcats' ),
			__( 'Settings', 'wpcalendarcats' ),
			'manage_options',
			self::PAGE_MAIN,
			array( __CLASS__, 'render_main' )
		);

		add_submenu_page(
			self::PAGE_MAIN,
			__( 'Categories', 'wpcalendarcats' ),
			__( 'Categories', 'wpcalendarcats' ),
			'manage_options',
			self::PAGE_CATS,
			array( __CLASS__, 'render_categories' )
		);
	}

	/**
	 * Load the admin assets on this plugin's screens.
	 */
	public static function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, 'wpcalendarcats' ) ) {
			return;
		}
		wp_enqueue_style( 'wpcc-admin', WPCC_URL . 'assets/admin.css', array(), WPCC_VERSION );
		wp_enqueue_script( 'wpcc-admin', WPCC_URL . 'assets/admin.js', array(), WPCC_VERSION, true );
		wp_localize_script(
			'wpcc-admin',
			'wpccAdmin',
			array(
				/* translators: %s: category name */
				'confirmRemove' => __( 'Remove "%s"? Its events will fall through to the catch-all category.', 'wpcalendarcats' ),
				'newCategory'   => __( 'New category', 'wpcalendarcats' ),
			)
		);
	}

	/* ── General settings ────────────────────────────────────────────── */

	public static function register_settings() {
		register_setting(
			'wpcalendarcats',
			WPCC_Settings::OPTION,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => WPCC_Settings::defaults(),
			)
		);
	}

	/**
	 * Sanitise the general settings form.
	 *
	 * @param mixed $input Raw submission.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		$defaults = WPCC_Settings::defaults();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		// Feeds: keep the raw lines (they're validated at read time) but strip tags.
		$feeds          = isset( $input['feeds'] ) ? (string) $input['feeds'] : '';
		$clean['feeds'] = trim( wp_strip_all_tags( $feeds ) );

		foreach ( array( 'feeds_squarespace', 'feeds_rss' ) as $key ) {
			$clean[ $key ] = isset( $input[ $key ] ) ? trim( wp_strip_all_tags( (string) $input[ $key ] ) ) : '';
		}
		$clean['farmers_markets'] = empty( $input['farmers_markets'] ) ? 0 : 1;

		$clean['accent']    = sanitize_hex_color( isset( $input['accent'] ) ? $input['accent'] : '' );
		$clean['highlight'] = sanitize_hex_color( isset( $input['highlight'] ) ? $input['highlight'] : '' );
		if ( ! $clean['accent'] ) {
			$clean['accent'] = $defaults['accent'];
		}
		if ( ! $clean['highlight'] ) {
			$clean['highlight'] = $defaults['highlight'];
		}

		$clean['default_view']  = in_array( isset( $input['default_view'] ) ? $input['default_view'] : '', array( 'cards', 'calendar' ), true ) ? $input['default_view'] : 'cards';
		$clean['default_unit']  = in_array( isset( $input['default_unit'] ) ? $input['default_unit'] : '', array( 'day', 'week', 'month' ), true ) ? $input['default_unit'] : 'month';
		$clean['default_theme'] = in_array( isset( $input['default_theme'] ) ? $input['default_theme'] : '', array( 'light', 'dark' ), true ) ? $input['default_theme'] : 'light';

		$clean['show_theme_toggle'] = empty( $input['show_theme_toggle'] ) ? 0 : 1;
		$clean['show_zoom']         = empty( $input['show_zoom'] ) ? 0 : 1;
		$clean['show_search']       = empty( $input['show_search'] ) ? 0 : 1;
		$clean['show_controls']     = empty( $input['show_controls'] ) ? 0 : 1;
		$clean['show_remember']     = empty( $input['show_remember'] ) ? 0 : 1;
		$clean['show_about']        = empty( $input['show_about'] ) ? 0 : 1;
		$clean['contact_email']     = isset( $input['contact_email'] ) ? sanitize_email( $input['contact_email'] ) : '';

		$clean['submit_url']   = isset( $input['submit_url'] ) ? esc_url_raw( trim( $input['submit_url'] ) ) : '';
		$clean['submit_label'] = isset( $input['submit_label'] ) ? sanitize_text_field( $input['submit_label'] ) : $defaults['submit_label'];

		$minutes                = isset( $input['cache_minutes'] ) ? absint( $input['cache_minutes'] ) : 15;
		$clean['cache_minutes'] = max( 1, min( 1440, $minutes ) );

		$clean['group_government'] = isset( $input['group_government'] ) ? sanitize_text_field( $input['group_government'] ) : $defaults['group_government'];
		$clean['group_community']  = isset( $input['group_community'] ) ? sanitize_text_field( $input['group_community'] ) : $defaults['group_community'];

		$clean['calendar_url']   = isset( $input['calendar_url'] ) ? esc_url_raw( trim( $input['calendar_url'] ) ) : '';
		$clean['default_height'] = isset( $input['default_height'] ) ? max( 300, absint( $input['default_height'] ) ) : $defaults['default_height'];

		// Only a change to the feed list makes cached bodies stale.
		$old = get_option( 'wpcalendarcats_settings', array() );
		$old = is_array( $old ) ? $old : array();
		foreach ( array( 'feeds', 'feeds_squarespace', 'feeds_rss', 'farmers_markets' ) as $key ) {
			$before = isset( $old[ $key ] ) ? (string) $old[ $key ] : '';
			if ( (string) $clean[ $key ] !== $before ) {
				WPCC_ICS::flush_cache();
				break;
			}
		}

		return $clean;
	}

	/**
	 * Render the Settings page.
	 */
	public static function render_main() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = WPCC_Settings::get();
		$feeds    = WPCC_Settings::get_feeds();
		?>
		<div class="wrap wpcc-admin">
			<h1><?php esc_html_e( 'Calendar Cats', 'wpcalendarcats' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'A self-contained community events calendar. It reads public iCalendar feeds directly and renders them on your site — no external app, no API key.', 'wpcalendarcats' ); ?>
			</p>

			<?php settings_errors(); ?>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_MAIN ) ); ?>" class="nav-tab nav-tab-active" aria-current="page"><?php esc_html_e( 'Settings', 'wpcalendarcats' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_CATS ) ); ?>" class="nav-tab"><?php esc_html_e( 'Categories', 'wpcalendarcats' ); ?></a>
			</h2>

			<form method="post" action="options.php">
				<?php settings_fields( 'wpcalendarcats' ); ?>
				<?php $name = WPCC_Settings::OPTION; ?>

				<h2><?php esc_html_e( 'Calendar feeds', 'wpcalendarcats' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpcc_feeds"><?php esc_html_e( 'Feeds', 'wpcalendarcats' ); ?></label></th>
						<td>
							<textarea id="wpcc_feeds" name="<?php echo esc_attr( $name ); ?>[feeds]" rows="6" class="large-text code"><?php echo esc_textarea( $settings['feeds'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'One feed per line. Each line is a public .ics URL or a Google Calendar ID, optionally followed by a pipe and a display name, and a second pipe and the public page that presents the calendar (the About popup links there):', 'wpcalendarcats' ); ?>
							</p>
							<pre class="wpcc-code-sample">https://example.com/events.ics | Village Events | https://example.com/events
abc123@group.calendar.google.com | Neighborhood Events</pre>
							<p class="description">
								<?php esc_html_e( 'For a Google Calendar, the calendar must be set to "Make available to public". Lines starting with # are ignored.', 'wpcalendarcats' ); ?>
							</p>

							<?php if ( ! empty( $feeds ) ) : ?>
								<p><strong><?php esc_html_e( 'Currently resolving to:', 'wpcalendarcats' ); ?></strong></p>
								<ul class="wpcc-feed-list">
									<?php foreach ( $feeds as $feed ) : ?>
										<li>
											<code><?php echo esc_html( $feed['name'] ); ?></code> →
											<a href="<?php echo esc_url( $feed['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $feed['url'] ); ?></a>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php else : ?>
								<p class="wpcc-warning"><?php esc_html_e( 'No usable feeds configured yet — the calendar will render empty.', 'wpcalendarcats' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpcc_feeds_sqsp"><?php esc_html_e( 'Squarespace events', 'wpcalendarcats' ); ?></label></th>
						<td>
							<textarea id="wpcc_feeds_sqsp" name="<?php echo esc_attr( $name ); ?>[feeds_squarespace]" rows="3" class="large-text code"><?php echo esc_textarea( $settings['feeds_squarespace'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'For organizations whose site is Squarespace and publishes no .ics. Use the events page URL (it already serves as the About link):', 'wpcalendarcats' ); ?></p>
							<pre class="wpcc-code-sample">https://example.org/events | Neighborhood Association</pre>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpcc_feeds_rss"><?php esc_html_e( 'Events RSS', 'wpcalendarcats' ); ?></label></th>
						<td>
							<textarea id="wpcc_feeds_rss" name="<?php echo esc_attr( $name ); ?>[feeds_rss]" rows="3" class="large-text code"><?php echo esc_textarea( $settings['feeds_rss'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'For Wild Apricot sites, which offer no calendar export, only an events RSS feed. These events show a start time only — the feed carries no end time or location.', 'wpcalendarcats' ); ?></p>
							<pre class="wpcc-code-sample">https://example.org/events/rss | Community Hall | https://example.org/events</pre>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Built-in sources', 'wpcalendarcats' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[farmers_markets]" value="1" <?php checked( $settings['farmers_markets'], 1 ); ?> /> <?php esc_html_e( 'Chicago farmers markets in 60625 (Lincoln Square, Ravenswood, North Park) — the published 2026 season', 'wpcalendarcats' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpcc_cache"><?php esc_html_e( 'Cache feeds for', 'wpcalendarcats' ); ?></label></th>
						<td>
							<input type="number" id="wpcc_cache" name="<?php echo esc_attr( $name ); ?>[cache_minutes]" value="<?php echo esc_attr( $settings['cache_minutes'] ); ?>" min="1" max="1440" class="small-text" />
							<?php esc_html_e( 'minutes', 'wpcalendarcats' ); ?>
							<p class="description"><?php esc_html_e( 'How long a fetched feed is reused before going back to the source.', 'wpcalendarcats' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Appearance', 'wpcalendarcats' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Colors', 'wpcalendarcats' ); ?></th>
						<td>
							<label>
								<input type="text" name="<?php echo esc_attr( $name ); ?>[accent]" value="<?php echo esc_attr( $settings['accent'] ); ?>" class="wpcc-color small-text" />
								<?php esc_html_e( 'Accent — active buttons and tabs', 'wpcalendarcats' ); ?>
							</label><br />
							<label>
								<input type="text" name="<?php echo esc_attr( $name ); ?>[highlight]" value="<?php echo esc_attr( $settings['highlight'] ); ?>" class="wpcc-color small-text" />
								<?php esc_html_e( 'Highlight — today marker', 'wpcalendarcats' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default view', 'wpcalendarcats' ); ?></th>
						<td>
							<label class="screen-reader-text" for="wpcc_default_view"><?php esc_html_e( 'Default layout', 'wpcalendarcats' ); ?></label>
							<select id="wpcc_default_view" name="<?php echo esc_attr( $name ); ?>[default_view]">
								<option value="cards" <?php selected( $settings['default_view'], 'cards' ); ?>><?php esc_html_e( 'Cards (grouped by category)', 'wpcalendarcats' ); ?></option>
								<option value="calendar" <?php selected( $settings['default_view'], 'calendar' ); ?>><?php esc_html_e( 'Calendar grid', 'wpcalendarcats' ); ?></option>
							</select>
							<label class="screen-reader-text" for="wpcc_default_unit"><?php esc_html_e( 'Default time range', 'wpcalendarcats' ); ?></label>
							<select id="wpcc_default_unit" name="<?php echo esc_attr( $name ); ?>[default_unit]">
								<option value="day" <?php selected( $settings['default_unit'], 'day' ); ?>><?php esc_html_e( 'One day at a time (Today)', 'wpcalendarcats' ); ?></option>
								<option value="week" <?php selected( $settings['default_unit'], 'week' ); ?>><?php esc_html_e( 'One week at a time', 'wpcalendarcats' ); ?></option>
								<option value="month" <?php selected( $settings['default_unit'], 'month' ); ?>><?php esc_html_e( 'One month at a time', 'wpcalendarcats' ); ?></option>
							</select>
							<label class="screen-reader-text" for="wpcc_default_theme"><?php esc_html_e( 'Default theme', 'wpcalendarcats' ); ?></label>
							<select id="wpcc_default_theme" name="<?php echo esc_attr( $name ); ?>[default_theme]">
								<option value="light" <?php selected( $settings['default_theme'], 'light' ); ?>><?php esc_html_e( 'Light theme', 'wpcalendarcats' ); ?></option>
								<option value="dark" <?php selected( $settings['default_theme'], 'dark' ); ?>><?php esc_html_e( 'Dark theme', 'wpcalendarcats' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show', 'wpcalendarcats' ); ?></th>
						<td>
							<fieldset>
							<legend class="screen-reader-text"><span><?php esc_html_e( 'Show', 'wpcalendarcats' ); ?></span></legend>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_controls]" value="1" <?php checked( $settings['show_controls'], 1 ); ?> /> <?php esc_html_e( 'Navigation controls (today/week/month, back/today/forward, view switch)', 'wpcalendarcats' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_search]" value="1" <?php checked( $settings['show_search'], 1 ); ?> /> <?php esc_html_e( 'Search box', 'wpcalendarcats' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_theme_toggle]" value="1" <?php checked( $settings['show_theme_toggle'], 1 ); ?> /> <?php esc_html_e( 'Light/dark theme toggle', 'wpcalendarcats' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_zoom]" value="1" <?php checked( $settings['show_zoom'], 1 ); ?> /> <?php esc_html_e( 'Content zoom control (text size)', 'wpcalendarcats' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_remember]" value="1" <?php checked( $settings['show_remember'], 1 ); ?> /> <?php esc_html_e( '"Remember my filter" checkbox (visitor opts in to a cookie that keeps their category choice)', 'wpcalendarcats' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_about]" value="1" <?php checked( $settings['show_about'], 1 ); ?> /> <?php esc_html_e( 'About button (lists the sources and links to each one)', 'wpcalendarcats' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpcc_contact"><?php esc_html_e( 'Contact email', 'wpcalendarcats' ); ?></label></th>
						<td>
							<input type="email" id="wpcc_contact" name="<?php echo esc_attr( $name ); ?>[contact_email]" value="<?php echo esc_attr( $settings['contact_email'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Optional. Shown in the About popup for visitors who want their organization added. Leave blank to omit.', 'wpcalendarcats' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Group headings', 'wpcalendarcats' ); ?></th>
						<td>
							<label class="screen-reader-text" for="wpcc_group_government"><?php esc_html_e( 'Heading for the government group', 'wpcalendarcats' ); ?></label>
							<input type="text" id="wpcc_group_government" name="<?php echo esc_attr( $name ); ?>[group_government]" value="<?php echo esc_attr( $settings['group_government'] ); ?>" class="regular-text" />
							<code>government</code><br />
							<label class="screen-reader-text" for="wpcc_group_community"><?php esc_html_e( 'Heading for the community group', 'wpcalendarcats' ); ?></label>
							<input type="text" id="wpcc_group_community" name="<?php echo esc_attr( $name ); ?>[group_community]" value="<?php echo esc_attr( $settings['group_community'] ); ?>" class="regular-text" />
							<code>community</code>
							<p class="description"><?php esc_html_e( 'Section headings above each group of category cards.', 'wpcalendarcats' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpcc_submit_url"><?php esc_html_e( 'Submit-an-event link', 'wpcalendarcats' ); ?></label></th>
						<td>
							<input type="url" id="wpcc_submit_url" name="<?php echo esc_attr( $name ); ?>[submit_url]" value="<?php echo esc_attr( $settings['submit_url'] ); ?>" class="regular-text" placeholder="https://…" />
							<label class="screen-reader-text" for="wpcc_submit_label"><?php esc_html_e( 'Button label', 'wpcalendarcats' ); ?></label>
							<input type="text" id="wpcc_submit_label" name="<?php echo esc_attr( $name ); ?>[submit_label]" value="<?php echo esc_attr( $settings['submit_label'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Optional button in the control bar — a form, a mailto:, or any page. Leave the URL empty to hide it.', 'wpcalendarcats' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Legacy iframe mode', 'wpcalendarcats' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Only needed if you are still embedding a separately hosted calendar app with [wpcalendarcats mode="iframe"]. Leave blank otherwise.', 'wpcalendarcats' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpcc_calendar_url"><?php esc_html_e( 'External app URL', 'wpcalendarcats' ); ?></label></th>
						<td>
							<input type="url" id="wpcc_calendar_url" name="<?php echo esc_attr( $name ); ?>[calendar_url]" value="<?php echo esc_attr( $settings['calendar_url'] ); ?>" class="regular-text" placeholder="https://…" />
							<label class="screen-reader-text" for="wpcc_default_height"><?php esc_html_e( 'Initial height in pixels', 'wpcalendarcats' ); ?></label>
							<input type="number" id="wpcc_default_height" name="<?php echo esc_attr( $name ); ?>[default_height]" value="<?php echo esc_attr( $settings['default_height'] ); ?>" min="300" step="50" class="small-text" />
							<?php esc_html_e( 'px initial height', 'wpcalendarcats' ); ?>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Using it', 'wpcalendarcats' ); ?></h2>
			<p><?php esc_html_e( 'Add this shortcode to any page or post:', 'wpcalendarcats' ); ?> <code>[wpcalendarcats]</code></p>
			<table class="widefat striped wpcc-attr-table">
				<thead><tr><th><?php esc_html_e( 'Attribute', 'wpcalendarcats' ); ?></th><th><?php esc_html_e( 'What it does', 'wpcalendarcats' ); ?></th></tr></thead>
				<tbody>
					<tr><td><code>view="calendar"</code></td><td><?php esc_html_e( 'Start on the calendar grid instead of the cards.', 'wpcalendarcats' ); ?></td></tr>
					<tr><td><code>unit="week"</code></td><td><?php esc_html_e( 'Start on a one-week range instead of a month. Use unit="day" to start on Today.', 'wpcalendarcats' ); ?></td></tr>
					<tr><td><code>remember="no"</code></td><td><?php esc_html_e( 'Hide the "Remember my filter" checkbox.', 'wpcalendarcats' ); ?></td></tr>
					<tr><td><code>about="no"</code></td><td><?php esc_html_e( 'Hide the About button.', 'wpcalendarcats' ); ?></td></tr>
					<tr><td><code>theme="dark"</code></td><td><?php esc_html_e( 'Start in the dark theme.', 'wpcalendarcats' ); ?></td></tr>
					<tr><td><code>categories="ward,arts"</code></td><td><?php esc_html_e( 'Only show these category keys.', 'wpcalendarcats' ); ?></td></tr>
					<tr><td><code>search="no"</code></td><td><?php esc_html_e( 'Hide the search box.', 'wpcalendarcats' ); ?></td></tr>
					<tr><td><code>controls="no"</code></td><td><?php esc_html_e( 'Hide the navigation bar — useful for a compact widget-style embed.', 'wpcalendarcats' ); ?></td></tr>
					<tr><td><code>theme_toggle="no"</code></td><td><?php esc_html_e( 'Hide the light/dark toggle.', 'wpcalendarcats' ); ?></td></tr>
					<tr><td><code>max_width="1100"</code></td><td><?php esc_html_e( 'Cap the width in pixels and center it.', 'wpcalendarcats' ); ?></td></tr>
					<tr><td><code>mode="iframe" url="…"</code></td><td><?php esc_html_e( 'Legacy: embed an externally hosted calendar app instead.', 'wpcalendarcats' ); ?></td></tr>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Data endpoints', 'wpcalendarcats' ); ?></h3>
			<p class="description"><?php esc_html_e( 'The front end reads from these public REST routes, which you can also call directly:', 'wpcalendarcats' ); ?></p>
			<ul class="wpcc-feed-list">
				<li><code><?php echo esc_html( rest_url( WPCC_REST::NAMESPACE_V1 . '/events' ) ); ?></code></li>
				<li><code><?php echo esc_html( rest_url( WPCC_REST::NAMESPACE_V1 . '/categories' ) ); ?></code></li>
			</ul>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpcc_flush_cache' ); ?>
				<input type="hidden" name="action" value="wpcc_flush_cache" />
				<?php submit_button( __( 'Clear feed cache now', 'wpcalendarcats' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/* ── Category editor ─────────────────────────────────────────────── */

	public static function render_categories() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$cats = WPCC_Settings::get_categories();
		?>
		<div class="wrap wpcc-admin">
			<h1><?php esc_html_e( 'Calendar Cats — Categories', 'wpcalendarcats' ); ?></h1>

			<?php settings_errors( 'wpcc_categories' ); ?>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_MAIN ) ); ?>" class="nav-tab"><?php esc_html_e( 'Settings', 'wpcalendarcats' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_CATS ) ); ?>" class="nav-tab nav-tab-active" aria-current="page"><?php esc_html_e( 'Categories', 'wpcalendarcats' ); ?></a>
			</h2>

			<p class="description">
				<?php esc_html_e( 'Events are sorted into categories by matching keywords against the event title, then — for multi-word keywords only — against the description. The first category that matches wins, so order matters: put the most specific categories first. "other" is always evaluated last and needs no keywords.', 'wpcalendarcats' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wpcc-cats-form">
				<?php wp_nonce_field( 'wpcc_save_categories' ); ?>
				<input type="hidden" name="action" value="wpcc_save_categories" />

				<div id="wpcc-cat-rows">
					<?php foreach ( $cats as $i => $cat ) : ?>
						<?php self::render_category_row( $i, $cat ); ?>
					<?php endforeach; ?>
				</div>

				<p>
					<button type="button" class="button" id="wpcc-add-cat"><?php esc_html_e( '+ Add category', 'wpcalendarcats' ); ?></button>
				</p>

				<?php submit_button( __( 'Save categories', 'wpcalendarcats' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Import / export', 'wpcalendarcats' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Copy this JSON to move your categories to another site, or paste a list in to replace them.', 'wpcalendarcats' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wpcc_import_categories' ); ?>
				<input type="hidden" name="action" value="wpcc_import_categories" />
				<textarea name="wpcc_json" rows="10" class="large-text code" spellcheck="false"><?php echo esc_textarea( wp_json_encode( $cats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></textarea>
				<?php submit_button( __( 'Replace categories with this JSON', 'wpcalendarcats' ), 'secondary' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Replace all categories with the defaults that ship with the plugin? Your current list will be lost.', 'wpcalendarcats' ) ); ?>');">
				<?php wp_nonce_field( 'wpcc_restore_categories' ); ?>
				<input type="hidden" name="action" value="wpcc_restore_categories" />
				<?php submit_button( __( 'Restore default categories', 'wpcalendarcats' ), 'delete', 'submit', false ); ?>
			</form>
		</div>

		<script type="text/template" id="wpcc-cat-template">
			<?php
			self::render_category_row(
				'__INDEX__',
				array_merge(
					WPCC_Categories::fallback(),
					array(
						'key'   => '',
						'label' => '',
						'icon'  => '',
						'color' => '#147671',
					)
				)
			);
			?>
		</script>
		<?php
	}

	/**
	 * One editable category row.
	 *
	 * @param int|string $index Row index (or the template placeholder).
	 * @param array      $cat   Category values.
	 */
	protected static function render_category_row( $index, $cat ) {
		$is_other = 'other' === $cat['key'];
		$name     = 'wpcc_cats[' . $index . ']';
		$desc_kw  = WPCC_Categories::description_keywords( $cat['keywords'] );
		?>
		<div class="wpcc-cat-row" data-key="<?php echo esc_attr( $cat['key'] ); ?>">
			<div class="wpcc-cat-head">
				<span class="wpcc-cat-handle" aria-hidden="true" title="<?php esc_attr_e( 'Move', 'wpcalendarcats' ); ?>">⠿</span>
				<span class="wpcc-cat-swatch" aria-hidden="true" style="background: <?php echo esc_attr( $cat['color'] ); ?>"></span>
				<strong class="wpcc-cat-title"><?php echo esc_html( '' !== $cat['label'] ? $cat['label'] : __( 'New category', 'wpcalendarcats' ) ); ?></strong>
				<span class="wpcc-cat-badge"><?php echo esc_html( count( $cat['keywords'] ) ); ?> <?php esc_html_e( 'keywords', 'wpcalendarcats' ); ?></span>
				<span class="wpcc-cat-actions">
					<button type="button" class="button-link wpcc-move-up" aria-label="<?php esc_attr_e( 'Move up', 'wpcalendarcats' ); ?>" title="<?php esc_attr_e( 'Move up', 'wpcalendarcats' ); ?>">↑</button>
					<button type="button" class="button-link wpcc-move-down" aria-label="<?php esc_attr_e( 'Move down', 'wpcalendarcats' ); ?>" title="<?php esc_attr_e( 'Move down', 'wpcalendarcats' ); ?>">↓</button>
					<?php if ( ! $is_other ) : ?>
						<button type="button" class="button-link wpcc-remove" aria-label="<?php esc_attr_e( 'Remove', 'wpcalendarcats' ); ?>" title="<?php esc_attr_e( 'Remove', 'wpcalendarcats' ); ?>">✕</button>
					<?php endif; ?>
					<button type="button" class="button-link wpcc-toggle" aria-expanded="false"><?php esc_html_e( 'Edit', 'wpcalendarcats' ); ?></button>
				</span>
			</div>

			<div class="wpcc-cat-body" hidden>
				<div class="wpcc-field-grid">
					<label>
						<?php esc_html_e( 'Label', 'wpcalendarcats' ); ?>
						<input type="text" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $cat['label'] ); ?>" class="wpcc-label-input" />
					</label>
					<label>
						<?php esc_html_e( 'Key', 'wpcalendarcats' ); ?>
						<input type="text" name="<?php echo esc_attr( $name ); ?>[key]" value="<?php echo esc_attr( $cat['key'] ); ?>" <?php wp_readonly( $is_other ); ?> />
						<span class="description"><?php esc_html_e( 'Lowercase id used in shortcodes.', 'wpcalendarcats' ); ?></span>
					</label>
					<label>
						<?php esc_html_e( 'Icon', 'wpcalendarcats' ); ?>
						<input type="text" name="<?php echo esc_attr( $name ); ?>[icon]" value="<?php echo esc_attr( $cat['icon'] ); ?>" />
						<span class="description"><?php esc_html_e( 'An emoji, or an image URL.', 'wpcalendarcats' ); ?></span>
					</label>
					<label>
						<?php esc_html_e( 'Color', 'wpcalendarcats' ); ?>
						<input type="text" name="<?php echo esc_attr( $name ); ?>[color]" value="<?php echo esc_attr( $cat['color'] ); ?>" class="wpcc-color-input" />
					</label>
					<label>
						<?php esc_html_e( 'Group', 'wpcalendarcats' ); ?>
						<input type="text" name="<?php echo esc_attr( $name ); ?>[group]" value="<?php echo esc_attr( $cat['group'] ); ?>" list="wpcc-groups" />
					</label>
				</div>

				<?php if ( ! $is_other ) : ?>
					<label class="wpcc-keywords-label">
						<?php esc_html_e( 'Keywords — one per line', 'wpcalendarcats' ); ?>
						<textarea name="<?php echo esc_attr( $name ); ?>[keywords]" rows="6" class="large-text code" spellcheck="false"><?php echo esc_textarea( implode( "\n", $cat['keywords'] ) ); ?></textarea>
					</label>
					<p class="description">
						<?php esc_html_e( 'Matched against the event title, case-insensitively, on whole words. Regular expressions are allowed, e.g.', 'wpcalendarcats' ); ?>
						<code>ward.*office</code>.
						<?php esc_html_e( 'Keep patterns simple: a badly written one can slow every request, and one that fails is skipped.', 'wpcalendarcats' ); ?>
						<?php
						if ( empty( $desc_kw ) ) {
							esc_html_e( 'None of these are eligible for description matching — that needs plain multi-word phrases with no regex characters.', 'wpcalendarcats' );
						} else {
							printf(
								/* translators: %s: comma-separated keyword list */
								esc_html__( 'Also matched against descriptions: %s', 'wpcalendarcats' ),
								'<code>' . esc_html( implode( '</code>, <code>', $desc_kw ) ) . '</code>'
							);
						}
						?>
					</p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'The catch-all category. It takes any event nothing else matched, so it has no keywords and always sorts last.', 'wpcalendarcats' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
		// Only once per page: the row template must not clone a duplicate id.
		if ( 0 === $index ) {
			?>
			<datalist id="wpcc-groups">
				<?php foreach ( WPCC_Settings::get_groups() as $group ) : ?>
					<option value="<?php echo esc_attr( $group['key'] ); ?>"></option>
				<?php endforeach; ?>
			</datalist>
			<?php
		}
	}

	/* ── Form handlers ───────────────────────────────────────────────── */

	public static function handle_save_categories() {
		self::guard( 'wpcc_save_categories' );

		$raw   = isset( $_POST['wpcc_cats'] ) ? wp_unslash( $_POST['wpcc_cats'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Each field is sanitized in WPCC_Settings::save_categories().
		$count = WPCC_Settings::save_categories( is_array( $raw ) ? $raw : array() );

		add_settings_error(
			'wpcc_categories',
			'wpcc_saved',
			sprintf(
				/* translators: %d: number of categories */
				_n( '%d category saved.', '%d categories saved.', $count, 'wpcalendarcats' ),
				$count
			),
			'success'
		);
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		self::redirect_back();
	}

	/**
	 * Handle restoring the default categories.
	 */
	public static function handle_restore_categories() {
		self::guard( 'wpcc_restore_categories' );
		WPCC_Settings::restore_default_categories();
		add_settings_error( 'wpcc_categories', 'wpcc_restored', __( 'Default categories restored.', 'wpcalendarcats' ), 'success' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		self::redirect_back();
	}

	/**
	 * Handle importing categories from JSON.
	 */
	public static function handle_import_categories() {
		self::guard( 'wpcc_import_categories' );

		$json   = isset( $_POST['wpcc_json'] ) ? wp_unslash( $_POST['wpcc_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Decoded as JSON, then sanitized in WPCC_Settings::save_categories().
		$parsed = json_decode( (string) $json, true );

		if ( ! is_array( $parsed ) || empty( $parsed ) ) {
			add_settings_error( 'wpcc_categories', 'wpcc_bad_json', __( 'That JSON could not be read as a list of categories — nothing was changed.', 'wpcalendarcats' ), 'error' );
		} else {
			$count = WPCC_Settings::save_categories( $parsed );
			if ( 0 === $count ) {
				add_settings_error( 'wpcc_categories', 'wpcc_empty_json', __( 'No valid categories were found in that JSON — nothing was changed.', 'wpcalendarcats' ), 'error' );
			} else {
				add_settings_error(
					'wpcc_categories',
					'wpcc_imported',
					sprintf(
						/* translators: %d: number of categories */
						_n( '%d category imported.', '%d categories imported.', $count, 'wpcalendarcats' ),
						$count
					),
					'success'
				);
			}
		}

		set_transient( 'settings_errors', get_settings_errors(), 30 );
		self::redirect_back();
	}

	/**
	 * Handle the "clear feed cache" button.
	 */
	public static function handle_flush_cache() {
		self::guard( 'wpcc_flush_cache' );
		WPCC_ICS::flush_cache();
		add_settings_error( 'wpcalendarcats', 'wpcc_flushed', __( 'Feed cache cleared.', 'wpcalendarcats' ), 'success' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', admin_url( 'admin.php?page=' . self::PAGE_MAIN ) ) );
		exit;
	}

	/**
	 * Check capability and nonce for an admin action.
	 */
	protected static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wpcalendarcats' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Return to the Categories page.
	 */
	protected static function redirect_back() {
		// settings_errors() only reads the stored notices when this is present.
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', admin_url( 'admin.php?page=' . self::PAGE_CATS ) ) );
		exit;
	}
}
