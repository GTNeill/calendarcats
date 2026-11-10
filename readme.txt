=== WPCalendarCats ===
Contributors: gtneill
Tags: calendar, events, ical, google calendar, community
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Community events calendar. Reads public iCalendar feeds, sorts events into categories, and shows them with one shortcode.

== Description ==

WPCalendarCats turns any set of public iCalendar (.ics) feeds — Google Calendar, Outlook, Teamup, Eventbrite, a city council calendar — into a browsable events calendar inside your WordPress site.

It runs entirely on your own site. There is no companion app to host, no API key to obtain, and no third-party service in the request path: WordPress fetches the feeds server-side, caches them, and serves the parsed events to the front end over its own REST API.

**What visitors get**

* **Cards view** — one card per category, each listing its upcoming events, with past events in the period tucked below a divider.
* **Calendar view** — a month, week or single-day grid with colour-coded event chips. Multi-day events appear on every day they span, with a continuation marker after the first.
* **Today view** — a one-day agenda, with previous / next day navigation.
* **Hover or tap popups** with time, duration, location, description, organiser, a link back to the source event, and an "Add to my calendar" link.
* **Search** across titles, locations, descriptions and category names within the current range.
* **Category filter pills** — the first pick solos a category; selecting everything reverts to "All".
* **Remember my filter** — an opt-in checkbox beside the category pills. When ticked, the visitor's chosen categories are kept in a first-party cookie and reopen as their default view. Off by default; nothing is stored unless they tick it.
* **About popup** — an accessible dialog listing every source calendar with a "Visit site" link, plus an optional contact email.
* **Light and dark themes** with an optional toggle.

**What you control**

* **Feeds** — one per line. Paste a full `.ics` URL, or just a Google Calendar id. Add `| Display Name` to label a feed.
* **More sources than .ics** — Squarespace event pages, Wild Apricot events RSS feeds, and a built-in Chicago 60625 farmers-market schedule, alongside ordinary iCalendar feeds.
* **Cross-source de-duplication** — when two sources list the same event, it is shown once and credited to both ("Also listed by …").
* **Categories** — every category's key, label, emoji or image icon, colour, group and keyword list is editable, reorderable, and exportable as JSON. Order is priority: the first category whose keywords match an event wins.
* **Colours** — accent and highlight colours, so the calendar sits inside your theme instead of on top of it.
* **Cache duration** — how long feeds are held before being refetched.

**How categorisation works**

Keywords are matched case-insensitively on whole word boundaries, so "walk" will not match "sidewalk". Titles are checked first across all categories; only if nothing matches is the description checked, and only against keywords that are plain multi-word phrases (single words and regular expressions are too loose to trust against a paragraph of text). Regular expressions are allowed in keywords — `ward.*office` works — and a keyword that fails to compile is skipped rather than breaking the rest of the category. The `other` category is the catch-all: it needs no keywords and always sorts last.

Times are rendered in the site's WordPress timezone, so every visitor sees the same posted time regardless of where they are.

== Installation ==

1. Upload `wpcalendarcats.zip` via **Plugins → Add New → Upload Plugin**, or unzip it into `wp-content/plugins/`.
2. Activate the plugin.
3. Go to **Calendar Cats** in the admin menu and paste in your feeds.
4. Optionally open the **Categories** tab to adjust the keyword lists to your community's vocabulary.
5. Add `[wpcalendarcats]` to any page or post.

== Usage ==

Basic:

`[wpcalendarcats]`

Calendar grid, week at a time, dark:

`[wpcalendarcats view="calendar" unit="week" theme="dark"]`

A single category, no chrome — good for a sidebar:

`[wpcalendarcats categories="public_safety" controls="no" search="no"]`

**Shortcode attributes**

* `view` — `cards` or `calendar`. Default: the admin setting.
* `unit` — `month`, `week` or `day`. Default: the admin setting.
* `theme` — `light` or `dark`. Default: the admin setting.
* `search` — `yes` or `no`.
* `controls` — `yes` or `no`. Hides the whole toolbar when `no`.
* `theme_toggle` — `yes` or `no`.
* `zoom` — `yes` or `no`.
* `remember` — `yes` or `no`. Shows the "Remember my filter" checkbox.
* `about` — `yes` or `no`. Shows the About button and popup.
* `categories` — comma-separated category keys to restrict the calendar to.
* `max_width` — pixel width to centre the calendar within.
* `submit_url` — URL for a "Submit Your Event" button.
* `mode` — `native` (default) or `iframe` for the legacy 1.x embed.
* `url`, `height` — used only by `mode="iframe"`.

`[ward_calendar]` is accepted as an alias.

**Analytics**

The plugin does not bundle or inject any analytics (GoatCounter included). If you want traffic stats, add the script through your theme or a header/footer snippet plugin.

**Squarespace and RSS sources**

Squarespace and RSS feeds are added in their own settings boxes, one per line as `URL | Display Name | About-page link`. RSS (Wild Apricot) events carry a start time only — no end time or location — so they display without an end. The built-in farmers-market schedule covers the 2026 season only and needs updating each year.

== Frequently Asked Questions ==

= Does this need an API key or an external service? =

No. It reads public `.ics` URLs with WordPress's own HTTP API. Nothing leaves your server except the feed requests.

= My Google Calendar events are not showing. =

The calendar has to be public. In Google Calendar, open **Settings → your calendar → Access permissions** and tick "Make available to public", then copy the calendar id (or the "Public address in iCal format") into the feeds box.

= Are recurring events supported? =

Yes. Daily, weekly, monthly and yearly recurrence with `INTERVAL`, `COUNT`, `UNTIL`, `BYDAY` (including ordinals like the third Tuesday or the last Sunday), `BYMONTHDAY` and `BYMONTH`, plus `EXDATE` exclusions and single-instance `RECURRENCE-ID` overrides.

= How often are feeds refreshed? =

Every 15 minutes by default; configurable from 1 minute to 24 hours. There is a "Clear cached feeds" button for when you need a change to show up immediately.

= Is it multisite-safe? =

Yes. Settings, categories and the feed cache are per-site, and deleting the plugin cleans up every site on the network.

= Can I style it with my theme? =

Every class is prefixed `wpcc-`, and the palette runs through CSS custom properties (`--wpcc-accent`, `--wpcc-highlight`, and the surface/text/border tokens on `.wpcc-theme-light` / `.wpcc-theme-dark`). Override them in your theme's stylesheet.

== Upgrade Notice ==

= 2.2.2 =
A feed over 5 MB now shows an error instead of loading only part of its events. Small admin accessibility fixes.

= 2.2.1 =
Accessibility, security and speed fixes from a code review: events open from the keyboard, the popup is a proper dialog, feed fetching is hardened against private addresses and oversized responses, and event windows are cached. No settings changes.

= 2.2.0 =
Adds multi-day events, a Today view, an opt-in remembered category filter, an About popup, Squarespace / RSS / farmers-market sources and cross-source de-duplication. Existing shortcodes and settings keep working; new options default to off.

= 2.0.0 =
Major rewrite: the plugin now renders the calendar itself instead of embedding an external app. Existing `[wpcalendarcats]` shortcodes keep working, and your old iframe URL is preserved — add `mode="iframe"` to any shortcode you want to keep as an embed.

== Changelog ==

= 2.2.2 =
* **Oversized feeds.** A feed larger than 5 MB now reports "The feed is too large to load." instead of silently showing only the events in its first 5 MB.
* Admin: the "Clear feed cache now" button no longer shares an id with Save Changes, and the categories import/export box has an accessible label.
* Letter-spacing from your theme is kept on event rows and calendar chips.

= 2.2.1 =
* **Keyboard access.** Events in the Cards and Calendar views are real buttons, so they can be reached with Tab and opened with Enter or Space. The event popup is now a labelled dialog: focus moves into it, Tab is kept inside, and Esc or Close returns focus to the event that opened it.
* **Focus and announcements.** Keyboard focus is kept when the calendar re-renders (Next / Previous, category pills, Cards / Calendar). Loading, search result counts, "no results" and errors are announced to screen readers. The search box has a proper label, a visible focus ring, and animations respect reduced-motion.
* **Safer feed fetching.** Feeds are fetched with `wp_safe_remote_get` (no localhost / private-network targets), responses are capped at 5 MB, and Squarespace paging can no longer be redirected to another host. The iframe `url` shortcode attribute is limited to the saved calendar's host.
* **Faster.** Each date window is cached after parsing, so repeat visits skip feed parsing and recurrence expansion. Cache flushing is instant and works with an object cache. Logged-out REST responses carry a short `Cache-Control`. Saving unrelated settings no longer flushes the feed cache.
* **Admin.** "Settings saved" and "Cache cleared" notices now show. Selects and group fields are labelled, the Show options are a fieldset, remove/move buttons name their category, and confirm prompts are translatable.
* Code: WordPress coding-standards formatting, docblocks, specific `phpcs:ignore` rules, ICS alarm blocks no longer leak into event descriptions, and the unused text-domain loader was removed.

= 2.2.0 =
* **Multi-day events.** An event that spans several days now shows on every day it covers in the Calendar view, with a "▸ cont'd" chip after the first day. Card rows show "→ Mon D" under the date badge, an in-progress multi-day event stays in the current list instead of dropping to "Earlier this period", and the popup and search results show the full range ("Sep 25 – Sep 27"). All-day ends are handled as exclusive, per iCalendar, so a one-day all-day event is not stretched to two.
* **Today view.** New `unit="day"` shortcode value and a Today button in the Week / Month switch: a one-day agenda with previous / next day navigation.
* **Remember my filter.** New opt-in checkbox beside the category pills (`remember="yes|no"`, also an admin setting). Stores the picked categories in a first-party cookie (`cc_category_filter`, with `cc_remember_filter` as the opt-in marker) for up to a year. Choosing All, or unticking the box, removes it. Cards and Calendar share one selection.
* **About popup.** New About button (`about="yes|no"`, also an admin setting) opening a keyboard-accessible dialog: Esc to close, focus kept inside, focus returned on close. It lists every source with a "Visit site" link and shows an optional contact email (new admin field).
* **New source types.** Squarespace event pages, Wild Apricot events RSS feeds, and a built-in 2026 Chicago 60625 farmers-market schedule, each configurable in the admin. RSS events are start-only.
* **Cross-source de-duplication.** The same event published by two sources is merged into one, keeping the richer record and listing the other publisher as "Also listed by".
* Accessibility: new text and controls were checked against WCAG 1.4.3 (4.5:1) in both themes; the dialog is labelled and modal, and the continuation marker is announced as "continued".
* The `/events` REST response gains a `sources` list (name and link) used by the About popup.

= 2.1.3 =
* **Accessibility: the toolbar icons are now inline SVG instead of text glyphs.** The back, forward, refresh, text-size and light/dark buttons used characters (‹ › ⟳ − + ☾ ☀), and the search box used an emoji magnifier. Their contrast was fine when measured directly — 5.24:1 in light mode, 6.2:1 in dark — but a character is not a shape, so contrast checkers can only report it as "unable to determine", and it stays on the report permanently. SVG icons have no text to measure, so the rule no longer applies. They also render identically on every platform, which glyphs and emoji do not.
* Category emoji are now hidden from screen readers. The category name always sits right beside the icon, so announcing both turned "Ward Office" into "classical building, Ward Office".
* No visual change is intended in this release. If an icon looks off in your theme, please report it.

= 2.1.2 =
* **Accessibility: cleared the two remaining issues reported by third-party scanners.** Both were introduced or missed by the 2.1.1 pass.
* The scrollable category lists carried `role="group"`. That reads correctly, but a focusable scroll container is only allowed to be `application`, `main` or `region`, so scanners flagged all ten of them. They are now `role="region"`, which keeps the accessible name and satisfies the rule.
* The event count badge painted a hardcoded white number on the raw category colour. That measured 4.32:1 on the mid greens and 3.54:1 on the greys, against a required 4.5:1. The number now takes a foreground computed against whichever colour the badge is actually painted, so every category clears 4.5:1 — worst case is now 4.53:1. White is kept wherever it already passed, so the badges look unchanged for most categories.
* Count badges now read as "5 events" instead of a bare "5" to screen readers.

= 2.1.1 =
* **Accessibility: fixed 505 WCAG failures found by an automated audit of a live page.** Every one was inside the calendar itself; the surrounding theme was clean.
* Raised the muted and faint text colours in both themes. They were measured against plain white, but the calendar paints them on four different surfaces — on the darkest, "Updated just now" and the day-of-week labels were down at 2.3:1 against a required 4.5:1.
* Event times inside calendar chips now use the full text colour. They previously used the muted colour on top of a category tint, which only reached ~3.6:1 — this alone accounted for 156 of the failures.
* Out-of-month day numbers in the grid no longer fade to `opacity: 0.4`, which had pushed them to 1.6:1. They recede by colour instead and stay readable.
* Category colours used as *text* are now contrast-corrected at render time against the surface behind them. Because categories are editable, a fixed palette cannot guarantee this — a red or blue category number on the dark theme was sitting at ~2.6:1. Borders and backgrounds keep the exact colour you chose.
* Category cards that scroll are now reachable by keyboard and announced by screen readers. Previously the events past the fourth in a card could not be reached without a mouse.

= 2.1.0 =
* Restored the content zoom control that was lost in the 2.0.0 rewrite. It now lives inline in the calendar toolbar as `[−] [slider] [+] [100%]` rather than floating against the viewport edge, so it never overlays your theme's content or follows visitors down the page.
* Zoom runs from 75% to 150% in 5% steps and scales only the calendar body, leaving the toolbar itself at a fixed size. Clicking the percentage resets to 100%.
* The zoom level survives a view switch, a filter change and a feed refresh, and card heights are re-measured after every change so the four-event cap stays correct at any zoom level.
* The control is keyboard operable and screen-reader labelled. On screens narrower than 640px the slider is hidden and the −/+ buttons remain.
* Added a "Content zoom control" setting and a `zoom="no"` shortcode attribute to hide it.
* **Update notifications.** The plugin now tells WordPress where to look for new versions, so releases show up on the Plugins and Updates screens with a working Update button instead of having to be re-uploaded by hand. Updates are checked against the project's public GitHub releases at most twice a day, and nothing installs on its own — you still click Update.
* Added a "Check for updates" link under the plugin on the Plugins screen, and wired up the "View details" popup so release notes are readable before updating.

= 2.0.1 =
* Category cards are now sized for four events and scroll from the fifth onwards. Previously a card holding exactly five events stretched its whole grid row, making every neighbouring card too tall.
* The visible height is measured from the rendered rows instead of assuming a fixed row height, so cards with wrapped titles or a location line no longer clip their last visible event. Card heights are re-measured on window resize.

= 2.0.0 =
* **Self-contained rewrite.** The plugin renders the calendar in PHP and vanilla JavaScript and reads public iCalendar feeds directly. No companion app, no configured app URL, no API key.
* Added an iCalendar parser with recurrence expansion (`FREQ`, `INTERVAL`, `COUNT`, `UNTIL`, `BYDAY` including ordinals, `BYMONTHDAY` including negatives, `BYMONTH`), `EXDATE` exclusions and `RECURRENCE-ID` overrides. Recurrence is expanded in the event's own timezone, so a 7pm weekly event stays at 7pm across a DST change.
* Added keyword-driven categorisation: titles matched first, then descriptions for plain multi-word phrases only, on whole-word boundaries, with `other` as the enforced catch-all. Malformed keywords are skipped instead of breaking their category.
* Added a full category editor — add, remove, reorder, recolour, re-icon, regroup, edit keyword lists, import/export JSON, and restore the shipped defaults.
* Added a cards view, a month/week calendar grid, event popups, in-range search, category filter pills, light/dark themes and a theme toggle.
* Added public read-only REST routes `wpcalendarcats/v1/events` and `wpcalendarcats/v1/categories`, backed by a transient feed cache with a configurable duration.
* Events are emitted in the site's WordPress timezone so all visitors see the same posted time; locale comes from `get_locale()`.
* Front end is dependency-free vanilla JavaScript with no build step, so it drops into any theme.
* Added `uninstall.php` to remove options and cached feeds, multisite included.
* Kept backwards compatibility: `mode="iframe"` still renders the 1.x embed, the `ward_calendar` alias is registered, and upgrading preserves existing settings.

= 1.0.0 =
* Initial release: `[wpcalendarcats]` shortcode embedding an externally hosted calendar app in an auto-resizing iframe.
