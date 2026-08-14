# Right-Click Actions

Adds context menus to articles, feeds, and categories in FreshRSS.

<!-- screenshot -->

## Features

- Right-click article headlines to toggle read/unread, star, open in new tab, or filter
- Build a standing rule from an article you are looking at: **Hide articles like this** and
  **Star articles like this** save a title filter on that feed, then apply it to the articles
  already stored so the rule is not invisible until the next refresh
- Right-click sidebar feeds to mark all read/unread or open feed settings
- Right-click sidebar categories to manage subscriptions, expand/collapse, or bulk-mark
- Each context zone (headlines, article body, sidebar feeds, sidebar categories) can be toggled independently
- Individual actions within each zone can be enabled or disabled

## Installation

Copy the `xExtension-RightClickActions` directory into your FreshRSS `extensions/` directory, or install it through Extension Manager. Enable it in Settings > Extensions.

## Configuration

Four context zones, each independently togglable:

- **Article headlines** -- toggle read, star, open in new tab, mark older/newer, hide or star by title keyword, show this feed only
- **Article content** -- same actions as headlines, applied to the article body area
- **Sidebar feeds** -- mark all read/unread, recently read, open settings
- **Sidebar categories** -- mark all read/unread, recently read, expand/collapse, add/manage subscriptions

## Title filters

Both title actions prompt with the article's title pre-filled, so you can cut it down to the
part that should match. The result is saved as a filter action on that feed — `intitle:"…"`,
quoted, because FreshRSS reads an unquoted `intitle:two words` as `intitle:two` plus a loose
search for `words`.

Filter actions normally only run against articles as they are fetched, so both actions also
apply the rule once to articles already stored in that feed: hiding marks them read, starring
stars them (up to the 1000 most recent, and it says so when it hits that).

Feed-level star filters are not reachable from stock FreshRSS — its subscription page only
writes the read filter — but the engine applies read, star and label filters from any owner,
so the rule behaves exactly like a built-in one.

## Compatibility

Requires FreshRSS 1.20+.
