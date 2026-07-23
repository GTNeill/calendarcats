<?php
/**
 * Plugin Name:       WPCalendarCats
 * Plugin URI:        https://github.com/GTNeill/wpcalendarcats
 * Description:       Embeds the 40th Ward category calendar (search, category filters, cards/timeline views) into any WordPress page or post via a shortcode, without the calendar app's own header/footer branding.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            George Neill
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpcalendarcats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'WPCC_VERSION', '1.0.0' );
define( 'WPCC_OPTION_KEY', 'wpcalendarcats_settings' );

/**
 * Default settings.
 */
function wpcc_default_settings() {
	return array(
		'calendar_url'  => 'https://georgec-508brmt-preview-4200.runable.site/',
		'default_height' => 1000,
	);
}

function wpcc_get_settings() {
	$saved = get_option( WPCC_OPTION_KEY, array() );
	return wp_parse_args( $saved, wpcc_default_settings() );
}

/* ─────────────────────────────────────────────────────────────────────────
 * Admin settings page — Settings → WPCalendarCats
 * ────────────────────────────────────────────────────────────────────── */

add_action( 'admin_menu', function () {
	add_options_page(
		__( 'WPCalendarCats', 'wpcalendarcats' ),
		__( 'WPCalendarCats', 'wpcalendarcats' ),
		'manage_options',
		'wpcalendarcats',
		'wpcc_render_settings_page'
	);
} );

add_action( 'admin_init', function () {
	register_setting( 'wpcalendarcats', WPCC_OPTION_KEY, array(
		'sanitize_callback' => 'wpcc_sanitize_settings',
	) );
} );

function wpcc_sanitize_settings( $input ) {
	$clean = array();
	$clean['calendar_url']   = isset( $input['calendar_url'] ) ? esc_url_raw( trailingslashit( trim( $input['calendar_url'] ) ) ) : '';
	$clean['default_height'] = isset( $input['default_height'] ) ? max( 300, absint( $input['default_height'] ) ) : 1000;
	return $clean;
}

function wpcc_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = wpcc_get_settings();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'WPCalendarCats Settings', 'wpcalendarcats' ); ?></h1>
		<p><?php esc_html_e( 'Configure the calendar app URL that the [wpcalendarcats] shortcode embeds.', 'wpcalendarcats' ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'wpcalendarcats' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="wpcc_calendar_url"><?php esc_html_e( 'Calendar App URL', 'wpcalendarcats' ); ?></label>
					</th>
					<td>
						<input type="url" id="wpcc_calendar_url" name="<?php echo esc_attr( WPCC_OPTION_KEY ); ?>[calendar_url]"
							value="<?php echo esc_attr( $settings['calendar_url'] ); ?>" class="regular-text" placeholder="https://your-calendar-app.example.com/" />
						<p class="description"><?php esc_html_e( 'The live calendar app\'s base URL (no path). The plugin appends ?embed=1 automatically.', 'wpcalendarcats' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wpcc_default_height"><?php esc_html_e( 'Default Height (px)', 'wpcalendarcats' ); ?></label>
					</th>
					<td>
						<input type="number" id="wpcc_default_height" name="<?php echo esc_attr( WPCC_OPTION_KEY ); ?>[default_height]"
							value="<?php echo esc_attr( $settings['default_height'] ); ?>" min="300" step="50" class="small-text" />
						<p class="description"><?php esc_html_e( 'Initial iframe height before it auto-resizes to fit content.', 'wpcalendarcats' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Shortcode Usage', 'wpcalendarcats' ); ?></h2>
		<p><code>[wpcalendarcats]</code></p>
		<p><?php esc_html_e( 'Optional attributes:', 'wpcalendarcats' ); ?></p>
		<ul style="list-style: disc; margin-left: 20px;">
			<li><code>url="https://..."</code> — <?php esc_html_e( 'override the calendar app URL for this embed only.', 'wpcalendarcats' ); ?></li>
			<li><code>height="800"</code> — <?php esc_html_e( 'override the initial height in pixels.', 'wpcalendarcats' ); ?></li>
			<li><code>max_width="1200"</code> — <?php esc_html_e( 'cap the embed width in pixels (default: none/100%).', 'wpcalendarcats' ); ?></li>
		</ul>
	</div>
	<?php
}

/* ─────────────────────────────────────────────────────────────────────────
 * Shortcode
 * ────────────────────────────────────────────────────────────────────── */

add_action( 'wp_enqueue_scripts', function () {
	wp_register_script(
		'wpcalendarcats-embed',
		plugins_url( 'assets/embed.js', __FILE__ ),
		array(),
		WPCC_VERSION,
		true
	);
} );

function wpcc_shortcode( $atts ) {
	$settings = wpcc_get_settings();

	$atts = shortcode_atts( array(
		'url'       => $settings['calendar_url'],
		'height'    => $settings['default_height'],
		'max_width' => '',
	), $atts, 'wpcalendarcats' );

	$base_url = trailingslashit( esc_url_raw( $atts['url'] ) );
	if ( empty( $base_url ) ) {
		return '<p>' . esc_html__( 'WPCalendarCats: no calendar URL configured. Set one in Settings → WPCalendarCats.', 'wpcalendarcats' ) . '</p>';
	}

	$embed_src = add_query_arg( 'embed', '1', $base_url );
	$iframe_id = 'wpcc-frame-' . wp_unique_id();
	$height    = max( 300, (int) $atts['height'] );

	$wrapper_style = 'width:100%;';
	if ( ! empty( $atts['max_width'] ) ) {
		$wrapper_style .= 'max-width:' . absint( $atts['max_width'] ) . 'px;margin-left:auto;margin-right:auto;';
	}

	wp_enqueue_script( 'wpcalendarcats-embed' );

	ob_start();
	?>
	<div class="wpcalendarcats-wrapper" style="<?php echo esc_attr( $wrapper_style ); ?>">
		<iframe
			id="<?php echo esc_attr( $iframe_id ); ?>"
			class="wpcalendarcats-iframe"
			src="<?php echo esc_url( $embed_src ); ?>"
			title="<?php esc_attr_e( 'Community Events Calendar', 'wpcalendarcats' ); ?>"
			style="width:100%;height:<?php echo esc_attr( $height ); ?>px;border:0;display:block;"
			loading="lazy"
			scrolling="no"
		></iframe>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'wpcalendarcats', 'wpcc_shortcode' );
