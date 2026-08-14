# PHP Todo List

A tiny todo-list web app written in plain PHP. No database server, no
frameworks — items are stored in a flat `todo.json` file next to the script.

Each item has a **task** (its name), a **group**, a **status**, and a
**completion percentage** (0–100). In the data file this is the `task` key on
each item.

Valid statuses: `PENDING`, `PROGRESS`, `DEPENDING`, `DONE`, `UNDONE`,
`URGENT`, `SKIPPED`.

## List name

The list has a name, shown as the heading at the top of the page and stored in
the data file. Click the heading to rename the list (it saves on Enter or when
you click away). The name also becomes the browser tab title.

## Groups & tree view

Items are shown as a collapsible tree. Each group is a section you can fold or
unfold by clicking its header; the header shows the item count and the group's
average completion. **Expand all** / **Collapse all** links sit above the tree,
and your fold/unfold choices are remembered in the browser between visits.
Items with no group are collected under **Ungrouped** at the bottom.

Use the small *rename* link next to a group header to rename that group across
all its items at once.

Within each group, items are automatically ordered by status in this
priority: **PROGRESS, URGENT, UNDONE, PENDING, DEPENDING, DONE, SKIPPED**.
Items sharing
the same status keep the order they were added in, and changing an item's
status re-sorts it into place.

The **Undone only** button in the toolbar is a latching filter: press it to
hide all DONE and SKIPPED items (any group left with nothing to show is hidden
too), and press it again to show everything. Its state is remembered per
browser and survives reloads.

On a wide browser window the group cards flow into two or more columns to make
use of the space; on a narrow window they stack into a single column. Each card
always stays whole — a group is never split across two columns.

## Inline editing

Everything is edited directly in the row — no separate edit page:

- **Name** — click it and type; it saves when you press Enter or click away.
- **Status** — pick from the coloured dropdown; saves immediately.
- **Completion** — type a number (0–100); saves immediately.
- **Move** — the *Move…* dropdown at the end of each row reassigns the item to
  another group. It lists the other existing groups, an *Ungrouped* option, and
  *＋ New group…* (which prompts for a new group name).

Each change is saved to the data file right away.

**Completion / status sync:** completion and the DONE status are kept
consistent, both ways:

- Setting completion to 100% marks the status **DONE**; setting the status to
  **DONE** sets completion to 100%.
- Dropping completion below 100% on a DONE item moves its status to
  **UNDONE**; changing a 100% item's status to anything other than DONE
  resets its completion to 0%.

Statuses and completion values that are already consistent (e.g. a non-DONE
item at 50%) are left untouched.

## Running it

You need PHP 8+ installed. From this folder, start PHP's built-in web server:

```
php -S localhost:8000
```

Then open <http://localhost:8000> in your browser.

### Choosing the data file

By default the app reads and writes `todo.json` next to the script. There are
two ways to use a different file:

- **The Data file dropdown** in the top-right corner lists the `.json` files in
  the app folder and lets you switch between them without restarting the
  server. The **New file** button beside it prompts for a name and creates an
  empty list, switching to it. The **Delete file** button permanently removes
  the current file (after a confirmation) and switches to another file, or back
  to `todo.json` if none remain.
- **A URL parameter** — open `index.php?file=work.json` (any name) to load that
  file directly. Handy for bookmarking a specific list.

Either way the choice is remembered in a browser cookie (the URL parameter
takes precedence when present). File names are validated to a safe name inside
the app folder, so neither the picker nor the URL can reach files elsewhere on
disk.

## Files

- `index.php` — the whole application (add / edit / delete items, update
  status and completion).
- `todo.json` — the default data file (switch files from the app header).
  Created automatically on first save; holds the list name and its items as
  `{ "name": ..., "items": [ ... ] }`. Older files that were a bare array of
  items are still read correctly and upgraded to this format on the next save.

## Notes

- The page auto-refreshes when the data file changes on disk (edited in another
  tab or by another process). It polls a lightweight endpoint every few seconds
  and reloads only when the file's timestamp/size changes — never while you're
  typing in a field, and not while the tab is in the background.
- All output is HTML-escaped, so item names are safe to display.
- Writes use an exclusive file lock so concurrent requests don't corrupt the
  data file.
- To reset the list, delete the data file (or empty it to `[]`).
