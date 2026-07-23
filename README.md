# WPCalendarCats

A WordPress plugin that embeds the 40th Ward category calendar app into any page or post via the `[wpcalendarcats]` shortcode — using an auto-resizing iframe, with the calendar app's own header/footer branding stripped out.

## How it works

The calendar app (a separate Bun/Vite/React project) supports an `?embed=1` query param that renders just the interactive calendar — search bar, category filter chips, week/month toggle, cards/calendar view switch, and the events themselves — without its site title/logo header or footer. In embed mode it also posts its content height to the parent window via `postMessage` so this plugin's script can keep the iframe sized correctly.

## Install

1. Zip this folder (or clone it) into `wp-content/plugins/wpcalendarcats/`.
2. Activate **WPCalendarCats** in the WordPress admin under Plugins.
3. Go to **Settings → WPCalendarCats** and set the Calendar App URL (e.g. `https://georgec-508brmt-preview-4200.runable.site/`, or your production domain once you point one at the app).
4. Add the shortcode to any page:

   ```
   [wpcalendarcats]
   ```

## Shortcode attributes

| Attribute   | Description                                              | Default                     |
|-------------|------------------------------------------------------------|------------------------------|
| `url`       | Override the calendar app URL for this embed only          | value set in Settings        |
| `height`    | Initial iframe height in px before auto-resize kicks in     | value set in Settings (1000) |
| `max_width` | Cap the embed width in px                                   | none (full width)            |

```
[wpcalendarcats url="https://calendar.example.com/" height="900" max_width="1100"]
```

## Files

- `wpcalendarcats.php` — plugin bootstrap, settings page, shortcode.
- `assets/embed.js` — listens for `postMessage` height updates from the embedded calendar and resizes the matching iframe.

## Notes

- Whenever the calendar app's preview URL changes (e.g. a new Runable preview URL), update it in Settings → WPCalendarCats — no code changes needed.
- Multiple `[wpcalendarcats]` shortcodes on the same page are supported; each iframe resizes independently.
