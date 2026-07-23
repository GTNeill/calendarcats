=== WPCalendarCats ===
Contributors: gtneill
Tags: calendar, events, embed, iframe, shortcode
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embed the 40th Ward category calendar (search, category filters, cards/timeline views) into any WordPress page or post — without the calendar app's own header/footer branding.

== Description ==

WPCalendarCats adds a `[wpcalendarcats]` shortcode that embeds the live calendar app in an iframe, stripped of its site branding header and footer so it drops cleanly into a WordPress page. The interactive controls — search, category filters, week/month toggle, cards/calendar view switch — are all kept.

The iframe auto-resizes to fit its content via `postMessage`, so you don't need to guess a fixed height.

== Installation ==

1. Upload the `wpcalendarcats` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to Settings → WPCalendarCats and set the Calendar App URL.
4. Add `[wpcalendarcats]` to any page or post.

== Shortcode Attributes ==

* `url` — override the calendar app URL for this embed only.
* `height` — initial height in pixels before auto-resize kicks in (default: value from settings).
* `max_width` — cap the embed width in pixels (default: full width).

Example:

`[wpcalendarcats url="https://calendar.example.com/" height="900" max_width="1100"]`

== Changelog ==

= 1.0.0 =
* Initial release.
