# QuickFilter — Design & Implementation Document

## Overview

QuickFilter is a filter builder UI for FreshRSS. It surfaces filter creation where users actually read — in the article view — instead of requiring raw filter syntax in a buried settings page.

**Problem:** FreshRSS has a powerful filter system (`author:`, `intitle:`, `#tag`, boolean logic, regex) but the only way to use it is typing raw syntax in Reading settings. Most users never touch it.

**Solution:** In-context filter controls on structured data (authors, tags) with a visual filter manager per feed. The extension creates native `FreshRSS_FilterAction` objects — FreshRSS owns all filter evaluation.

---

## Architecture

### Principles

1. **No parallel filter engine.** FreshRSS evaluates all filters. QuickFilter creates, displays, and manages `FreshRSS_FilterAction` objects via the existing PHP API.
2. **Single source of truth.** The extension reads all filter rules on a feed and displays them regardless of origin. No manifest tracking which filters QuickFilter created vs user-created. A filter is a filter.
3. **Per-feed scope.** Each filter belongs to one feed. Cross-feed and category-level operations are out of scope.
4. **Forward-only by default.** Inline filter creation affects future articles only. The filter manager's add-filter form offers "Apply to existing articles" as an opt-in checkbox (unchecked by default). Retroactive application always shows a preview before executing.
5. **Abstraction layer over filter storage.** All filter reads and writes go through a `QuickFilterService` class, not direct `_filtersAction()` calls. This isolates FreshRSS API assumptions and allows v2 to add metadata without rewriting UI or controller logic.

### How filters work in FreshRSS

Filters are `FreshRSS_FilterAction` objects stored in a feed's `attributes` JSON column via `FilterActionsTrait`. Each contains:
- A `FreshRSS_BooleanSearch` (the filter expression, e.g. `author:'John Doe'`)
- An array of action strings (`['read']`, `['star']`)

Filters are evaluated during feed actualization (new article ingestion). They are not applied retroactively — QuickFilter adds this as an explicit opt-in capability.

### Critical API behavior

`_filtersAction($action, $filters)` **replaces all filters for the given action**, not append. To add a single filter:

1. Read existing: `$feed->filtersAction('read')` → array of `FreshRSS_BooleanSearch`
2. Convert to strings, append new filter string
3. Write all: `$feed->_filtersAction('read', $allFilterStrings)`

This read-modify-write cycle means:
- AJAX requests must be serialized client-side (fast clicks lose data otherwise)
- The abstraction layer must preserve filter actions for action types it doesn't modify
- `updateFeed` behavior must be verified to do partial attribute updates, not full row replacement

### Extension components

```
xExtension-QuickFilter/
  metadata.json
  extension.php              # Hook registration, js_vars, static assets
  configure.phtml            # Extension settings
  Controllers/
    quickfilterController.php  # AJAX endpoints for filter CRUD + data queries
  lib/
    QuickFilterService.php     # Abstraction over FreshRSS filter API
  static/
    script.js                  # Inline UI, filter manager, visual indication
    style.css                  # Colors, panel layout, icons
```

---

## User Experience

### Discoverability

The extension's core interaction (inline filter controls) must be discoverable without documentation.

- **First-run state:** Filter control icons display persistently next to authors and tags until the user creates their first filter. After that, icons appear on hover only.
- **First-use tooltip:** On first page load after install, a dismissible banner below the nav bar: "QuickFilter installed — use the filter icons next to authors and tags to highlight or hide articles."
- **The banner uses `localStorage` to track dismissal.** No server-side state for onboarding.

### 1. Inline controls (while reading)

Filter icons appear next to author names (`.author`) and tags (`.link-tag`) in article headers.

**Icons (not +/-):**
- Star outline (☆) for positive action — tooltip: "Star articles by [author]"
- Eye-slash (◌̸) for negative action — tooltip: "Auto-read articles by [author]"
- Icons are 16px, muted gray at rest. Active state: filled star (green) or filled eye-slash (red).
- CSS-namespaced: `.qf-star`, `.qf-hide`, `.qf-active-positive`, `.qf-active-negative`

**Behavior:**
- Click creates a **forward-only** filter on the current feed. No retroactive application from inline controls.
- Already-filtered items show active state and colored text (green for starred, red for auto-read)
- Click active icon to **remove** the filter (toggle). Removing a filter does NOT un-mark previously affected articles — this is stated in the tooltip: "Remove filter (previously affected articles unchanged)"
- Optimistic UI: state updates immediately, rolls back on AJAX error with notification

**Lazy-loaded article support:**
- FreshRSS loads articles on scroll. Initial DOM walk misses later articles.
- Use `MutationObserver` on the article container (`#stream`) to detect new `.flux` elements and apply filter indication + attach event handlers
- If FreshRSS exposes a JS event for new article rendering, prefer that over MutationObserver

### 2. Per-feed filter manager

A dedicated panel for viewing and managing all filter rules on the current feed.

**Entry points:**
- Button in the feed's article list header (filter icon, always visible)
- Feed context menu in sidebar (if RightClickActions is installed, register as a menu item)
- "Manage filters visually" link in Reading settings next to raw filter text areas

**Panel behavior:**
- Opens as a right-side panel (400px) on desktop, full-screen modal on mobile (< 768px)
- Overlays the article list, does not push it aside
- Keyboard-dismissible (Escape), click-outside-to-close

**Panel contents — lightweight by default:**

```
┌─ Filters for "Volokh Conspiracy" ──────────────┐
│                                                  │
│  Active filters (3)                              │
│                                                  │
│  ☆ Author: Eugene Volokh          [Run] [Delete] │
│  ◌̸ Keyword: cryptocurrency        [Run] [Delete] │
│  ☆ Tag: #scotus                   [Run] [Delete] │
│                                                  │
│  + Add filter                                    │
│                                                  │
│  ─────────────────────────────────────────────── │
│  Also managing filters in Settings? They appear  │
│  here too. [Open Reading settings]               │
│                                                  │
└──────────────────────────────────────────────────┘
```

- **Active filters list:** Type icon, human-readable value, action buttons
- **[Run]** — "Run on existing articles" — opens preview before applying
- **[Delete]** — removes the filter rule (confirmation dialog)
- **+ Add filter** — expands inline form (not a separate screen)

**Add filter form (expanded):**

```
  Type:    [Author ▼]    Value: [Eugene Volokh ▼]
  Action:  [☆ Star  ▼]

  [ ] Apply to existing articles (23 match)

  [Preview]  [Save]
```

- Author and Tag types show dropdowns populated from feed data
- Keyword type shows a free text input with helper text: "Matches words in article titles (case-insensitive)"
- Minimum keyword length: 3 characters
- "Apply to existing articles" checkbox (unchecked by default) — live match count shown next to it
- When checked, "Save" opens the preview window before applying. When unchecked, "Save" creates a forward-only filter immediately.
- "Preview" always available to see matching articles regardless of checkbox state

**Note on filter coexistence:** QuickFilter shows ALL filters on the feed, including those created via FreshRSS Reading settings. The footer note makes this explicit. Filters created in either place are the same thing — native `FreshRSS_FilterAction` objects.

### 3. Preview window

When "Run on existing" or "Preview matches" is clicked:

```
┌─ Preview: author:'Eugene Volokh' → Star ────────┐
│                                                   │
│  23 articles match this filter                    │
│                                                   │
│  ☆ Today in Supreme Court History: Mar 31...      │
│    Eugene Volokh · March 31, 2026                 │
│  ☆ The Second Amendment and Historical...         │
│    Eugene Volokh · March 30, 2026                 │
│  ... (scrollable list, max 50 shown)              │
│                                                   │
│  [Apply to 23 articles]  [Cancel]                 │
│                                                   │
└───────────────────────────────────────────────────┘
```

- Shows matching articles with title, author, date
- Capped at 50 displayed, total count always shown
- "Apply" executes the batch update with progress indicator
- "Cancel" returns to the filter manager without changes

### 4. Mobile experience

- **No hover states.** Filter icons display persistently (same as first-run desktop state)
- **Touch targets:** Icons are 44px tap targets (visual size can be smaller with padding)
- **Filter manager:** Full-screen modal, not side panel
- **Dropdowns:** Native `<select>` elements for maximum mobile compatibility
- **Long-press alternative (v2):** Long-press on author name opens a context menu with filter options. Not in v1 — persistent icons are sufficient.

---

## Visual Design

### Color system

| State | Text color | Icon | Meaning |
|-------|-----------|------|---------|
| No filter | Default | Gray outline | No filter active |
| Positive (star) | `--qf-positive` | Green filled star | Articles starred |
| Negative (read) | `--qf-negative` | Red filled eye-slash | Articles auto-hidden |

CSS custom properties for theme support:

```css
:root {
  --qf-positive: #2e7d32;
  --qf-negative: #c62828;
  --qf-positive-bg: rgba(46, 125, 50, 0.12);
  --qf-negative-bg: rgba(198, 40, 40, 0.12);
  --qf-icon-muted: #999;
}

/* Dark theme overrides */
[data-theme="dark"] {
  --qf-positive: #66bb6a;
  --qf-negative: #ef5350;
  --qf-positive-bg: rgba(102, 187, 106, 0.15);
  --qf-negative-bg: rgba(239, 83, 80, 0.15);
  --qf-icon-muted: #666;
}
```

### Title keyword highlighting

Keywords matching `intitle:` filters are highlighted with a low-opacity background:
- Positive: `background: var(--qf-positive-bg)`
- Negative: `background: var(--qf-negative-bg)`
- Applied via JS `Range` API to avoid modifying the DOM structure of the title

### Accessibility

- All interactive elements keyboard-focusable with visible focus ring
- ARIA labels: `aria-label="Star articles by Eugene Volokh"` on filter icons
- Color coding always supplemented with icon shape (star vs eye-slash)
- Filter manager panel: focus trap when open, `role="dialog"`, `aria-modal="true"`
- Screen reader announcement on filter create/delete: `aria-live="polite"` region

---

## Performance

### Inline UI initialization

1. Page load: `js_vars` hook injects current feed's filter actions (already available, no extra request)
2. JS parses filter strings into lookup maps:
   ```javascript
   { authors: { 'Eugene Volokh': 'star' }, tags: { 'scotus': 'read' }, keywords: { 'crypto': 'read' } }
   ```
3. Initial DOM walk: find `.author` and `.link-tag` elements, apply classes and attach handlers
4. `MutationObserver` on `#stream` for lazy-loaded articles

Cost: O(n) for n visible articles. DOM mutation only for icon insertion. String matching for title keywords is negligible even with 100+ filters and 200+ articles.

### Dropdown population

**Problem:** No FreshRSS DAO method for distinct authors/tags per feed. Authors stored as delimited strings (`"Author1 · Author2"`) require PHP-side splitting.

**Solution:**

```sql
-- Authors: fetch raw strings from recent entries
SELECT DISTINCT author FROM entry
WHERE id_feed = ? AND author != ''
ORDER BY id DESC LIMIT 500

-- Tags: same approach
SELECT DISTINCT tags FROM entry
WHERE id_feed = ? AND tags != ''
ORDER BY id DESC LIMIT 500
```

- `LIMIT 500` on entry rows (not distinct values) — caps the scan cost
- PHP splits on ` · ` delimiter (authors) and `,` (tags), deduplicates, sorts alphabetically
- Result cached in the AJAX response — client caches per filter-manager-open session
- If the query takes > 500ms on a large feed, reduce the limit or add a "Loading..." state

### Retroactive filter application

- Preview query: count matching articles (parameterized, see Security section)
- Apply: batch `UPDATE` in groups of 50 IDs
- Progress indicator: "Applied to 23 of 45 articles..."
- Non-blocking: each batch is a separate AJAX call, UI updates between batches

### Author matching strategy

**Problem:** Authors stored as `"Alice · Bob"` means `LIKE '%Bob%'` false-matches "Bobby."

**Solution:** Match against the delimited format. For author "Bob":

```sql
WHERE author = 'Bob'                          -- exact single author
   OR author LIKE 'Bob · %'                   -- first in list
   OR author LIKE '% · Bob · %'              -- middle of list
   OR author LIKE '% · Bob'                   -- last in list
```

This is verbose but correct. The `QuickFilterService` constructs this pattern from a validated author name. The `·` delimiter is consistent in FreshRSS's author storage.

For tags, FreshRSS stores them as comma-separated. Same delimited match pattern with `,` instead of ` · `.

For `intitle:` keywords: `LIKE '%keyword%'` is correct (substring match on titles). Special characters (`%`, `_`) escaped in the pattern.

---

## Security

### Access control

- All controller actions require `FreshRSS_Auth::hasAccess()` (authenticated user)
- Users can only modify filters on feeds they own (FreshRSS enforces this at the DAO level)
- CSRF token required on all POST requests via `FreshRSS_Auth::isCsrfOk()`
- No admin requirement — filter management is a user-level operation

### Input validation

- **Author/tag from dropdown:** Validated against actual feed data before filter creation. The controller queries the feed's articles and confirms the value exists. Rejects values not found in the feed.
- **Keyword input:** Sanitized via `Minz_Request::paramString()`. HTML entities escaped. Length capped: minimum 3 characters, maximum 200. Wrapped server-side in `intitle:'...'` with proper quoting.
- **Filter strings constructed server-side.** Users never pass raw filter syntax through the AJAX API. The controller accepts structured parameters (`type`, `value`, `action`) and constructs the `FreshRSS_BooleanSearch` string internally.

### Filter injection prevention

The controller builds filter strings from validated components:

```php
// User sends: { type: 'author', value: 'John Doe', action: 'star' }
// Controller builds: author:'John Doe'
// NOT: user sends "author:'John' OR intitle:hack" as a raw string
```

The keyword input is the only free-text field. It's escaped and wrapped:

```php
$keyword = str_replace("'", "\\'", $validated_keyword);
$filterString = "intitle:'" . $keyword . "'";
```

No path exists for injecting boolean operators or additional filter clauses through the structured API.

### LIKE pattern safety

Retroactive application queries use parameterized statements. LIKE wildcards in user-provided values are escaped:

```php
$escaped = str_replace(['%', '_'], ['\\%', '\\_'], $value);
// Used in: WHERE author LIKE ? with ESCAPE '\'
```

### Batch update safety

- Retroactive apply uses explicit article ID lists: `WHERE id IN (?, ?, ...)`
- IDs come from a preceding SELECT, not from user input
- Batch size capped at 50 to prevent resource exhaustion
- Each batch is a separate transaction

---

## AJAX request serialization

Filter CRUD operations must execute sequentially to prevent the read-modify-write race condition.

**Client-side queue:**

```javascript
var pendingRequest = Promise.resolve();

function serializedApiCall(action, params) {
  pendingRequest = pendingRequest.then(function () {
    return apiCall(action, params);
  });
  return pendingRequest;
}
```

All filter create/delete/update operations go through `serializedApiCall`. The UI disables interactive elements while a request is in flight and re-enables on completion.

Each AJAX response returns the updated filter list for the feed. The client replaces its local state with the server response, ensuring consistency after each operation.

---

## Implementation Plan

### Phase 1: Foundation (scaffold + filter service)

1. Extension scaffold — metadata.json, extension.php, controller, static assets
2. `QuickFilterService` class — abstraction over `_filtersAction()` with read-modify-write safety
   - `getFilters($feedId)` → structured array of all filter rules
   - `addFilter($feedId, $type, $value, $action)` → creates filter, returns updated list
   - `removeFilter($feedId, $type, $value, $action)` → removes filter, returns updated list
   - Preserves unrelated filter actions during write
3. AJAX controller — endpoints for add/remove/list
4. `js_vars` hook — pass current feed's filter rules and feed ID to client
5. Client-side filter map — parse filter actions into author/tag/keyword lookups

### Phase 2: Inline UI

6. Author filter icons — persistent on first run, hover after first filter created
7. Tag filter icons — same behavior, gated on `show_tags` user setting
8. Visual indication — green/red coloring on filtered authors/tags
9. Title keyword highlighting — background color on matching title substrings
10. Toggle behavior — click active icon removes filter
11. `MutationObserver` for lazy-loaded articles
12. First-use banner and dismissal

### Phase 3: Filter manager

13. Panel UI — side panel (desktop) / full-screen modal (mobile)
14. Active filter list with type, value, action, delete
15. Add filter form — dropdowns for author/tag, text input for keyword
16. Dropdown population endpoint — distinct authors/tags with caching
17. AJAX request serialization queue

### Phase 4: Retroactive application

18. Preview endpoint — count and list matching articles
19. Preview UI in filter manager
20. Batch apply with progress indicator
21. "Run on existing" per-rule button

### Phase 5: Integration and polish

22. Reading settings "Manage visually" link
23. RightClickActions integration (if installed)
24. Dark theme CSS custom properties
25. Keyboard accessibility and ARIA
26. Mobile touch targets and layout
27. Error handling — network failures, empty states, edge cases (no authors, special characters)
28. Tooltip text and onboarding copy

---

## Known Limitations (v1)

- **No undo for retroactive application.** Once articles are marked read/starred, removing the filter does not reverse the marking. Users are warned in the preview step.
- **Per-feed only.** Same author filter needed on multiple feeds must be created separately on each.
- **No filter ownership tracking.** Cannot distinguish QuickFilter-created rules from manually-created ones. The abstraction layer is designed to accommodate this in v2.
- **No `intext:` body search.** Only title keywords are supported.
- **No regex support.** Keyword matching is literal substring.
- **Dropdown population may be slow on very large feeds (10,000+ articles).** Capped at 500 recent entries to limit query cost.

## Future work (v2 candidates)

- `intext:` body search filters
- Regex support behind a toggle
- Filter ownership metadata (creation date, source, match history)
- Long-press context menu on mobile
- Cross-feed filter templates
- Per-article "why was this filtered" indicator
- Match count tracking per rule
