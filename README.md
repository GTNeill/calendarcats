# CalendarCats

A self-contained WordPress community events calendar. Point it at public iCalendar feeds, and it renders a categorised, searchable, filterable calendar anywhere a shortcode goes.

No companion app to host. No API key. No third-party service in the request path — WordPress fetches the feeds server-side, caches them, and serves parsed events over its own REST API.

```
[calendarcats]
```

## What it does

**Front end**

- **Cards view** — a card per category listing its upcoming events, with earlier events in the period below a divider at reduced opacity.
- **Calendar view** — month, week or single-day grid with colour-coded event chips. Multi-day events appear on every day they span, with a `▸ cont'd` chip after the first day.
- **Today view** — one-day agenda (`unit="day"`) with previous / next day navigation.
- **Popups** on hover (or tap) with time, duration, location, linkified description, organiser, a link to the source event, and "Add to my calendar".
- **Search** over titles, locations, descriptions and category labels in the current range.
- **Filter pills** — first pick solos a category; selecting all reverts to "All".
- **Remember my filter** — opt-in checkbox beside the pills; stores the selected categories in a first-party cookie (`cc_category_filter`, opt-in marker `cc_remember_filter`, 365 days). Off by default.
- **About popup** — accessible modal listing every source with a "Visit site" link, plus an optional contact email.
- **Light/dark themes** with an optional toggle.

**Admin** (`CalendarCats` in the menu)

- **Feeds** — one per line: a full `.ics` URL or a bare Google Calendar id, optionally suffixed `| Display Name`. `#` starts a comment.
- **More sources** — Squarespace event pages, Wild Apricot events RSS, and a built-in 2026 Chicago 60625 farmers-market schedule (see [Sources](#sources)).
- **Categories** — add, remove, reorder, recolour, change icons and groups, edit keyword lists, import/export JSON, restore shipped defaults.
- **Appearance** — accent and highlight colours, default view/unit/theme, toolbar and search visibility.
- **Cache** — feed cache duration (1–1440 minutes) and a manual flush.

## Install

1. Build or download `calendarcats.zip`.
2. **Plugins → Add New → Upload Plugin**, choose the zip, install, activate.
3. **CalendarCats** → paste your feeds → save.
4. Drop `[calendarcats]` into a page.

To install from source instead, copy the repository into `wp-content/plugins/calendarcats/`.

## Shortcode

| Attribute | Values | Notes |
| --- | --- | --- |
| `view` | `cards`, `calendar` | Defaults to the admin setting |
| `unit` | `month`, `week`, `day` | Defaults to the admin setting |
| `theme` | `light`, `dark` | Defaults to the admin setting |
| `search` | `yes`, `no` | |
| `controls` | `yes`, `no` | `no` hides the entire toolbar |
| `theme_toggle` | `yes`, `no` | |
| `zoom` | `yes`, `no` | |
| `remember` | `yes`, `no` | "Remember my filter" checkbox |
| `about` | `yes`, `no` | About button and popup |
| `categories` | `key,key` | Restrict to specific category keys |
| `max_width` | pixels | Centres the calendar within that width |
| `submit_url` | URL | Renders a "Submit Your Event" button |
| `mode` | `native`, `iframe` | `iframe` is the legacy 1.x embed |
| `url`, `height` | | `mode="iframe"` only |

`[ward_calendar]` is registered as an alias.

Examples:

```
[calendarcats view="calendar" unit="week" theme="dark"]
[calendarcats view="calendar" unit="day" about="yes" remember="yes"]
[calendarcats categories="public_safety,ward_office" controls="no" search="no"]
[calendarcats max_width="1100" submit_url="https://example.com/submit-event"]
```

## Sources

Besides plain `.ics` feeds, **CalendarCats → Feeds** takes:

- **Squarespace events** — `Events page URL | Display Name | About-page link`, one per line, for organisations that publish no `.ics`.
- **Events RSS** — same syntax, for Wild Apricot sites that offer only an RSS feed. RSS events carry a **start time only**: no end time, no location.
- **Farmers markets** — a checkbox for the published 2026 Chicago 60625 season (Lincoln Square, Ravenswood, North Park). The schedule is hard-coded in `class-wpcc-connectors.php` and **needs updating each year**.

When two sources list the same event, `WPCC_Dedupe` merges them: the richer record wins, higher-priority feeds break ties, and the other publisher is shown as "Also listed by".

## Analytics

No analytics are bundled or injected. To add one (GoatCounter, Plausible, …), use your theme or a header/footer snippet plugin. The About popup's privacy note describes only what this plugin itself does.

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
GET /wp-json/calendarcats/v1/events?timeMin=2026-08-01T00:00:00&timeMax=2026-09-01T00:00:00
GET /wp-json/calendarcats/v1/categories
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
calendarcats.php              Plugin header, constants, bootstrap, activation
uninstall.php                   Removes options + cached feeds (multisite aware)
includes/
  default-categories.php        17 shipped categories / 361 keywords
  class-wpcc-settings.php       Options, feed parsing, category normalisation
  class-wpcc-categories.php     Keyword compilation and event categorisation
  class-wpcc-ics.php            Feed fetch/cache, iCalendar parser, RRULE expansion
  class-wpcc-connectors.php     Squarespace, events-RSS and farmers-market sources
  class-wpcc-dedupe.php         Cross-source duplicate merging
  class-wpcc-rest.php           /events and /categories routes
  class-wpcc-shortcode.php      [calendarcats] + legacy iframe mode
  class-wpcc-admin.php          Settings and category editor screens
assets/
  wpcc.js                       Front-end calendar (vanilla JS)
  wpcc.css                      Front-end styles
  admin.js / admin.css          Category editor
  embed.js                      Legacy iframe auto-resize
```

## Releasing a new version

WordPress sites update from the **latest published GitHub Release**, not from the repo or tags alone. `includes/class-wpcc-updater.php` reads `/releases/latest`, strips the leading `v` from `tag_name`, and downloads the asset named exactly `calendarcats.zip`.

1. Bump the version in three places: `Version:` and `WPCC_VERSION` in `calendarcats.php`, and `Stable tag` in `readme.txt`.
2. Add a `= X.Y.Z =` entry under `== Changelog ==` in `readme.txt` (and an Upgrade Notice if needed).
3. Check syntax: `php -l` on each PHP file, `node --check assets/wpcc.js`.
4. Rebuild the zip from the folder *above* the repo (the zip must contain a top-level `calendarcats/` folder):
   ```
   cd /home/user
   zip -rq /tmp/calendarcats.zip calendarcats -x "calendarcats/.git/*" "calendarcats/calendarcats.zip" "calendarcats/.gitignore"
   cp /tmp/calendarcats.zip calendarcats/calendarcats.zip
   ```
5. Commit, tag and push:
   ```
   git add -A && git commit -m "Describe change (vX.Y.Z)"
   git tag vX.Y.Z
   git push origin main && git push origin vX.Y.Z
   ```
6. Create the release at https://github.com/GTNeill/calendarcats/releases/new: pick tag `vX.Y.Z`, title `vX.Y.Z`, paste the changelog entry, attach `calendarcats.zip`, and click **Publish release**. It must not be a draft or pre-release, or `/releases/latest` ignores it. With the GitHub CLI: `gh release create vX.Y.Z calendarcats.zip --title vX.Y.Z --notes-file notes.md`.
7. Verify: `curl -s https://api.github.com/repos/GTNeill/calendarcats/releases/latest` should show the new `tag_name` and an asset called `calendarcats.zip`.

WordPress caches update checks, so sites may lag; Dashboard → Updates → **Check again** forces it.

## Requirements

WordPress 5.9+ (tested up to 7.1), PHP 7.4+.

## License

GPL-2.0-or-later.
