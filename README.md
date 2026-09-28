# PHP Todo List

A tiny todo-list web app written in plain PHP. No database server, no
frameworks — items are stored in a flat `todo.json` file next to the script.

Each item has a **task** (its name), a **group**, a **status**, and a
**completion percentage** (0–100). In the data file this is the `task` key on
each item. Items also carry free-text **comments** (`comment`), an optional
**due date** (`due`), and the date they were added and the date they were
completed (`added` and `completed`); see *Comments* and *Dates* below.

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

## Active and inactive groups

Each group header has an **Active** tick box, ticked by default. Unticking it
sets the group aside: it drops below every other group and its items are left
out of the overall statistics — the panel counts them separately as
*N inactive (excluded)*, the same way it treats SKIPPED items. The group
itself still works normally: you can open it, edit its items and move items in
or out; tick the box again to bring it back.

The set-aside group names live in the data file under `archived`, so the
state belongs to the list rather than to one browser, and renaming a group
carries the flag across.

## Sorting a panel

Each panel's column captions — **Task**, **Status**, **Completion** and
**Date** — are sort links. Panels start sorted by **Status**, in the priority
order above, and that caption carries the arrow to show it. Click another
caption to sort by it, click it again to reverse, and a third click returns to
Status. Status sorts by priority, not alphabetically; rows with no date sort
last either way. Each panel sorts on its own, the choice is remembered per
browser, and it only changes what you see — the order of items
in the data file is untouched.

A panel above the list shows **overall completion** — the average completion
across all tasks, with a count of how many are done and how many are still
uncompleted, broken down by status (URGENT, PROGRESS, UNDONE, DEPENDANT,
PENDING). SKIPPED items are excluded from the average and the task count, and
count as neither done nor uncompleted (they are noted separately).

The **Uncompleted only** button in the toolbar is a latching filter: press it to
hide all DONE and SKIPPED items (any group left with nothing to show is hidden
too), and press it again to show everything. Its state is remembered per
browser and survives reloads.

## Comments

The add panel has a multi-line **Comments** field for notes about the task
being added. Hovering a task row shows a tooltip with its full name (handy
when the column is too narrow for it) and its comments underneath.

The **Comment** button at the start of each row's controls opens an editor
for that task's comments: type, then **Save** (or **Cancel** to leave them as
they were). Saving an empty box clears the comments. On a task that already
has comments the button is tinted blue and carries a dot, so you can see at a
glance which tasks have notes.

## Dates

Each item records when it was added, and when it was completed. The **Show
dates** tick box in the toolbar reveals a *Date* column:

- An item that is still uncompleted (URGENT, PROGRESS, UNDONE, DEPENDANT,
  PENDING) shows the date it was **added**.
- An item that is DONE shows the date it was **completed**. That date is
  stamped when the item becomes DONE — by its status, or by its completion
  reaching 100% — and is cleared if it moves back off DONE.
- SKIPPED items show neither, and so do items created before the app recorded
  dates. Nothing is back-filled: an undated item simply shows nothing.

A task can also be given a **due date** when it is added — the *Due* field in
the add panel, which is optional. It appears in its own *Due* column beside
the *Date* one, with its own **Show due** tick box in the toolbar, and the
column sorts like the others (tasks with no due date go last either way). The tick box is the only
thing that decides whether the column is shown, so every row offers somewhere
to put a date, whether or not anything in the list has one yet. A due date is
a day on a calendar, so it is stored and shown as plain YYYY-MM-DD with no
timezone attached.

**A near due date makes a task urgent.** A task that is still only **UNDONE**
or **PENDING** is moved to **URGENT** once its due date is less than five days
away, overdue ones included. PROGRESS and DEPENDANT are left alone (they say
something the due date shouldn't overwrite), as are DONE and SKIPPED. The rule
is applied when the page is loaded and after every change, so a task becomes
urgent on its own as the date approaches; the change is written to the data
file. It is one-way: moving such a task back to PENDING or UNDONE by hand
promotes it again while its due date is still near.

“Today” comes from the timezone the list is kept in, which each data file
records under `timezone` (e.g. `"Australia/Adelaide"`). A file without one —
anything written before this existed — picks up the `TIMEZONE` constant at the
top of `index.php`, and records it the first time it is saved, so every list
ends up saying what its dates mean. PHP otherwise falls back to UTC, which
would put the five-day window hours behind the people using the list. The
timezone also decides the offset written into the added and completed
timestamps.

Pick it from the **Timezone** dropdown at the top of the page, next to the
data file selector: the choice is saved into that file straight away, and the
rest of the page — the due-date window included — works in it from that moment
on. The list is grouped by region, and an unrecognised value is ignored.

Click a due date in the list to change it, the same as any other field: the
cell is a date field that reads as plain text until you point at it, and it
saves when you pick a date. Clearing the field removes the due date.

Hovering a date shows the full timestamp. Dates are stored as an absolute
instant (ISO-8601 with the UTC offset) and displayed in the timezone of
whoever is reading the page. The tick box is remembered per browser.

On a wide browser window the group cards flow into two or more columns to make
use of the space; on a narrow window they stack into a single column. Each card
always stays whole — a group is never split across two columns.

## Inline editing

Everything is edited directly in the row — no separate edit page:

- **Name** — click it and type; it saves when you press Enter or click away.
- **Status** — pick from the coloured dropdown; saves immediately.
- **Completion** — type a number (0–100); saves immediately. A thin line
  under the field shows that percentage at a glance. The **Show completion**
  tick box in the toolbar hides the whole column when you don't need it
  (remembered per browser, like **Show dates** next to it).
- The add form remembers the group and status of the last item you added, so
  adding several to the same group is quick. Switching data file forgets the
  group (the new file has its own groups); the status is kept.
- **Move** — the *Move…* dropdown at the end of each row reassigns the item to
  another group. It lists the other existing groups, an *Ungrouped* option, and
  *＋ New group…* (which prompts for a new group name).
- The **Show task controls** tick box in the toolbar hides the *Move…* and
  *Delete* controls, for when you are reading the list rather than changing it.

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
  empty list, switching to it. The **Upload** button imports a JSON data file
  from your computer (saved into the app folder under its name and switched to;
  contents are validated and normalized). The **Download** button saves a copy
  of the current data file to your computer. The **Delete file** button permanently
  removes the current file (after a confirmation) and switches to another file,
  or back to `todo.json` if none remain.
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
  `{ "name": ..., "timezone": ..., "items": [ ... ], "archived": [ ... ] }`.
  Older files that were a bare array of items are still read correctly and
  upgraded to this format on the next save.

## Notes

- Your own edits never reload the page. Each change is sent in the background,
  written to the data file, and the server sends back the re-rendered list and
  statistics, which replace those parts of the page in place — the file is not
  read back to do it. Collapse state, the “Uncompleted only” filter and the field you
  were in are all kept. (Without JavaScript the old submit-and-reload path still
  works.)
- The page auto-refreshes when the data file changes on disk (edited in another
  tab or by another process). It polls a lightweight endpoint every few seconds
  and reloads only when the file's timestamp/size changes — never while you're
  typing in a field, and not while the tab is in the background.
- All output is HTML-escaped, so item names are safe to display.
- Writes use an exclusive file lock so concurrent requests don't corrupt the
  data file.
- To reset the list, delete the data file (or empty it to `[]`).
