# WPCalendarCats

A self-contained WordPress community events calendar. Point it at public iCalendar feeds, and it renders a categorised, searchable, filterable calendar anywhere a shortcode goes.

No companion app to host. No API key. No third-party service in the request path — WordPress fetches the feeds server-side, caches them, and serves parsed events over its own REST API.

```
[wpcalendarcats]
```

## What it does

**Front end**

- **Cards view** — a card per category listing its upcoming events, with earlier events in the period below a divider at reduced opacity.
- **Calendar view** — month or week grid with colour-coded event chips.
- **Popups** on hover (or tap) with time, duration, location, linkified description, organiser, a link to the source event, and "Add to my calendar".
- **Search** over titles, locations, descriptions and category labels in the current range.
- **Filter pills** — first pick solos a category; selecting all reverts to "All".
- **Light/dark themes** with an optional toggle.

**Admin** (`Calendar Cats` in the menu)

- **Feeds** — one per line: a full `.ics` URL or a bare Google Calendar id, optionally suffixed `| Display Name`. `#` starts a comment.
- **Categories** — add, remove, reorder, recolour, change icons and groups, edit keyword lists, import/export JSON, restore shipped defaults.
- **Appearance** — accent and highlight colours, default view/unit/theme, toolbar and search visibility.
- **Cache** — feed cache duration (1–1440 minutes) and a manual flush.

## Install

1. Build or download `wpcalendarcats.zip`.
2. **Plugins → Add New → Upload Plugin**, choose the zip, install, activate.
3. **Calendar Cats** → paste your feeds → save.
4. Drop `[wpcalendarcats]` into a page.

To install from source instead, copy the repository into `wp-content/plugins/wpcalendarcats/`.

## Shortcode

| Attribute | Values | Notes |
| --- | --- | --- |
| `view` | `cards`, `calendar` | Defaults to the admin setting |
| `unit` | `month`, `week` | Defaults to the admin setting |
| `theme` | `light`, `dark` | Defaults to the admin setting |
| `search` | `yes`, `no` | |
| `controls` | `yes`, `no` | `no` hides the entire toolbar |
| `theme_toggle` | `yes`, `no` | |
| `categories` | `key,key` | Restrict to specific category keys |
| `max_width` | pixels | Centres the calendar within that width |
| `submit_url` | URL | Renders a "Submit Your Event" button |
| `mode` | `native`, `iframe` | `iframe` is the legacy 1.x embed |
| `url`, `height` | | `mode="iframe"` only |

`[ward_calendar]` is registered as an alias.

Examples:

```
[wpcalendarcats view="calendar" unit="week" theme="dark"]
[wpcalendarcats categories="public_safety,ward_office" controls="no" search="no"]
[wpcalendarcats max_width="1100" submit_url="https://example.com/submit-event"]
```

## How categorisation works

Order is priority: the first category whose keywords match an event wins. So put specific categories above general ones.

- Keywords are matched **case-insensitively on whole word boundaries**, so `walk` can't match `sidewalk`.
- **Titles are checked first** across every category. Only if nothing matched is the **description** checked — and only against keywords that are plain multi-word phrases. Single words and regular expressions are too loose to trust against a paragraph.
- Regular expressions are allowed (`ward.*office`). A keyword that fails to compile is skipped rather than breaking the rest of its category.
- `other` is the catch-all: no keywords, always sorted last.

## iCalendar support

Recurrence is expanded in PHP against the subset real feeds actually use:

- `FREQ` — `DAILY`, `WEEKLY`, `MONTHLY`, `YEARLY`
- `INTERVAL`, `COUNT`, `UNTIL`
- `BYDAY` including ordinals (`3TU`, `-1SU`), `BYMONTHDAY` including negatives, `BYMONTH`
- `EXDATE` (multiple lines, comma-separated values)
- `RECURRENCE-ID` single-instance overrides, which are excluded from the parent's expansion

Occurrences are generated chronologically so `COUNT` lands on the right instances, and expansion happens in the event's own timezone so a 7pm weekly event stays 7pm across a DST change. Caps: 1000 occurrences and 5000 iterations per rule.

Event times are emitted as naive wall-clock ISO strings in the **site's** WordPress timezone, so every visitor sees the same posted time rather than one shifted to their own zone.

## REST API

Both routes are public and read-only — the data comes from public feeds. Rate protection comes from the feed cache.

```
GET /wp-json/wpcalendarcats/v1/events?timeMin=2026-08-01T00:00:00&timeMax=2026-09-01T00:00:00
GET /wp-json/wpcalendarcats/v1/categories
```

The requested window is clamped to one year.

## Theming

Every class is prefixed `wpcc-`. Colours run through CSS custom properties:

```css
.wpcc-app {
	--wpcc-accent: #147671;
	--wpcc-highlight: #cf2c28;
}

.wpcc-theme-light {
	--wpcc-bg: #fffbf4;
	--wpcc-surface: #ffffff;
	--wpcc-border: #c8dde1;
	--wpcc-text: #0b3e4a;
	--wpcc-muted: #5b7c86;
}
```

Category colour arrives per element as `--wpcc-cat`. The front end has no build step and no dependencies, so overriding it from a theme stylesheet is enough.

## Layout

```
wpcalendarcats.php              Plugin header, constants, bootstrap, activation
uninstall.php                   Removes options + cached feeds (multisite aware)
includes/
  default-categories.php        17 shipped categories / 361 keywords
  class-wpcc-settings.php       Options, feed parsing, category normalisation
  class-wpcc-categories.php     Keyword compilation and event categorisation
  class-wpcc-ics.php            Feed fetch/cache, iCalendar parser, RRULE expansion
  class-wpcc-rest.php           /events and /categories routes
  class-wpcc-shortcode.php      [wpcalendarcats] + legacy iframe mode
  class-wpcc-admin.php          Settings and category editor screens
assets/
  wpcc.js                       Front-end calendar (vanilla JS)
  wpcc.css                      Front-end styles
  admin.js / admin.css          Category editor
  embed.js                      Legacy iframe auto-resize
```

## Requirements

WordPress 5.8+, PHP 7.4+.

## License

GPL-2.0-or-later.
