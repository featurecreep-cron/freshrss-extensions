# Silent Refresh

Updates unread counts in the background without interrupting your reading.

<!-- screenshot -->

## Features

- Periodically polls for new articles and updates sidebar counts
- Updates the browser tab title with the current unread count
- No page reload -- counts update in place
- Configurable polling interval (1-60 minutes)
- Pauses polling while the tab is hidden; refreshes immediately when you return
- Stops polling when the session expires (FreshRSS login redirect, or a bare
  401/403 from a fronting auth proxy) and reloads once to re-authenticate -- a
  stale background tab never streams failed requests at your server or trips a
  fail2ban-style jail

## Installation

Copy the `xExtension-SilentRefresh` directory into your FreshRSS `extensions/` directory, or install it through Extension Manager. Enable it in Settings > Extensions.

## Configuration

- **Refresh interval** -- how often to check for new articles (default: 2 minutes)
- **Title bar count** -- show all unread articles or only unread in the current view

## Compatibility

Requires FreshRSS 1.20+.
