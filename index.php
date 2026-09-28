<?php
declare(strict_types=1);

/**
 * Simple PHP Todo List
 * ---------------------
 * Storage: a flat JSON file (data.json) sitting next to this script.
 * Each item has: id, task, status, completion (0-100), group, and the dates it
 * was added and completed. The added date is shown while an item is still
 * outstanding and the completion date once it is DONE, under a "Show dates"
 * tick box in the toolbar.
 *
 * The list is shown as a collapsible tree: each group is a section you can
 * fold/unfold, and item fields (task, status, completion, group) are edited
 * inline. Collapse state is remembered per browser via localStorage.
 *
 * Edits are applied in place: the change is POSTed in the background, the JSON
 * file is written, and the answer carries the re-rendered list and stats, which
 * the page swaps in. So the file and the page both end up current without a
 * reload and without reading the file back. A POST without the "ajax" flag
 * still redirects the old way, so the app works with JavaScript disabled.
 *
 * Run with PHP's built-in server:
 *     php -S localhost:8000
 * then open http://localhost:8000 in your browser.
 */

/**
 * Timezone the app works in. PHP falls back to UTC when php.ini doesn't say
 * otherwise, which is what the built-in server does — and that puts "today"
 * hours behind the people using the list, so a due date can fall on the wrong
 * side of the five-day window all morning. Set it to '' to follow whatever the
 * server is configured for instead. An unknown name is ignored rather than
 * being allowed to take the whole page down.
 */
const TIMEZONE = 'Australia/Adelaide';

/** A timezone name PHP knows, or '' for "not set". */
function clean_timezone($value): string
{
    $value = trim((string) $value);
    return ($value !== '' && in_array($value, DateTimeZone::listIdentifiers(), true)) ? $value : '';
}

/**
 * Work in the given timezone, falling back to TIMEZONE. Each data file records
 * its own (see load_data), so a list written in one place keeps meaning the
 * same thing when it is opened somewhere else.
 */
function apply_timezone(string $zone = ''): void
{
    $zone = clean_timezone($zone) ?: clean_timezone(TIMEZONE);
    if ($zone !== '') {
        date_default_timezone_set($zone);
    }
}

apply_timezone();

/** Directory the app manages data files in (and lists in the file picker). */
const DATA_DIR = __DIR__;

/**
 * Validate a user-supplied data-file name and return a safe basename ending in
 * ".json", or null if it isn't acceptable. Blocks directory traversal (no
 * slashes survive basename(), leading dots rejected) so a browser request can
 * never point the app outside DATA_DIR.
 */
function safe_data_filename($name): ?string
{
    $name = trim((string) $name);
    if ($name === '') {
        return null;
    }
    $name = basename($name);              // strip any directory part
    if ($name === '' || $name[0] === '.') {
        return null;                      // no hidden names, no "." / ".."
    }
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
        return null;                      // only safe filename characters
    }
    if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'json') {
        $name .= '.json';                 // always a .json file
    }
    return $name;
}

/**
 * Resolve the active data file. Precedence:
 *   1. A "file" query parameter on the page (e.g. index.php?file=work.json),
 *      which also updates the remembered selection.
 *   2. The file selected in the UI (remembered in the "todo_file" cookie).
 *   3. "todo.json".
 * The name is always validated to a safe file inside DATA_DIR.
 */
$paramFile = safe_data_filename($_GET['file'] ?? '');
if ($paramFile !== null) {
    setcookie('todo_file', $paramFile, ['path' => '/', 'samesite' => 'Lax']);
    $selectedFile = $paramFile;
} else {
    $selectedFile = safe_data_filename($_COOKIE['todo_file'] ?? '');
}
define('DATA_FILE', DATA_DIR . DIRECTORY_SEPARATOR . ($selectedFile ?? 'todo.json'));

/** Current signature (mtime + size) of the data file; 0/0 if it doesn't exist. */
function file_signature(): array
{
    clearstatcache(true, DATA_FILE);
    if (!is_file(DATA_FILE)) {
        return ['mtime' => 0, 'size' => 0];
    }
    return ['mtime' => (int) filemtime(DATA_FILE), 'size' => (int) filesize(DATA_FILE)];
}

// Lightweight polling endpoint: returns the data file's change signature so the
// page can auto-refresh when the file is modified elsewhere.
if (($_GET['poll'] ?? '') === '1') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(file_signature());
    exit;
}

// Download endpoint: serve the current data file as a file attachment.
if (($_GET['download'] ?? '') === '1') {
    $dlName    = basename(DATA_FILE);
    $dlContent = is_file(DATA_FILE)
        ? (string) file_get_contents(DATA_FILE)
        : "{\n    \"name\": \"Todo List\",\n    \"items\": []\n}";
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $dlName . '"');
    header('Content-Length: ' . strlen($dlContent));
    header('Cache-Control: no-store');
    echo $dlContent;
    exit;
}

// Pin the page URL to the active file. Without an explicit ?file=, this tab's
// forms (action="") would post with no file and fall back to the shared cookie
// — which another tab may have changed — so edits could land in the wrong file.
// Redirecting bare page loads to ?file=<current> keeps each tab bound to its
// own file regardless of what other tabs do with the cookie.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['file'])) {
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?file=' . rawurlencode(basename(DATA_FILE)));
    exit;
}

const STATUSES = ['PENDING', 'PROGRESS', 'DEPENDANT', 'DONE', 'UNDONE', 'URGENT', 'SKIPPED'];

/** Display order of statuses within a group (lower = shown first). */
const STATUS_ORDER = ['PROGRESS', 'URGENT', 'UNDONE', 'PENDING', 'DEPENDANT', 'DONE', 'SKIPPED'];

/**
 * Statuses that count as outstanding work, in the order they are shown in the
 * stats panel breakdown. DONE and SKIPPED are the only statuses left out.
 */
const OPEN_STATUSES = ['URGENT', 'PROGRESS', 'UNDONE', 'DEPENDANT', 'PENDING'];

/** Label used for items that have no group assigned. */
const UNGROUPED = 'Ungrouped';

/**
 * How the "added" and "completed" timestamps are stored: ISO-8601 with the UTC
 * offset, e.g. 2026-09-28T10:21:00+09:30. PHP's built-in server runs in UTC
 * unless php.ini says otherwise, so the offset is what lets the browser show
 * each date in the reader's own timezone instead of being a day out.
 */
const DATE_FORMAT = 'c';

/**
 * A task that is still only waiting — UNDONE or PENDING — turns URGENT once
 * its due date is less than DUE_URGENT_DAYS away, overdue ones included. The
 * other statuses are left alone: PROGRESS and DEPENDANT say something the due
 * date shouldn't overwrite, and DONE and SKIPPED are finished with.
 */
const DUE_URGENT_FROM = ['UNDONE', 'PENDING'];
const DUE_URGENT_DAYS = 5;

/** Fields that may be updated inline. */
const EDITABLE_FIELDS = ['task', 'status', 'completion', 'group', 'comment', 'due'];

/**
 * Default name for a todo list that hasn't been named yet.
 */
const DEFAULT_LIST_NAME = 'Todo List';

/**
 * Load the todo list from the JSON file as ['name' => string, 'items' => array].
 * Missing/corrupt file yields the default name and an empty list.
 *
 * The current on-disk format is an object: { "name": ..., "items": [...] }.
 * Older files were a bare array of items; those are still read correctly.
 */
function load_data(): array
{
    $default = ['name' => DEFAULT_LIST_NAME, 'items' => []];
    if (!is_file(DATA_FILE)) {
        return $default;
    }
    $raw = @file_get_contents(DATA_FILE);
    if ($raw === false || $raw === '') {
        return $default;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $default;
    }

    if (array_is_list($data)) {
        // Legacy format: a bare array of items.
        $name  = DEFAULT_LIST_NAME;
        $items = $data;
    } else {
        $name  = (isset($data['name']) && is_string($data['name']) && trim($data['name']) !== '')
            ? $data['name'] : DEFAULT_LIST_NAME;
        $items = (isset($data['items']) && is_array($data['items'])) ? $data['items'] : [];
    }

    // Groups the user has archived, by group name ('' is the ungrouped bucket).
    $archived = clean_archived(is_array($data) ? ($data['archived'] ?? []) : []);
    // The timezone this list is kept in; '' means the app's own.
    $timezone = clean_timezone(is_array($data) ? ($data['timezone'] ?? '') : '');

    // Normalize items: migrate the old "name" key to "task", and make sure a
    // group key exists (older data files may lack both).
    foreach ($items as &$item) {
        if (!isset($item['task'])) {
            $item['task'] = (isset($item['name']) && is_string($item['name'])) ? $item['name'] : '';
        }
        unset($item['name']);   // drop the legacy key so saves use "task"
        if (!isset($item['group']) || !is_string($item['group'])) {
            $item['group'] = '';
        }
        $item['comment'] = clean_comment($item['comment'] ?? '');
        $item['due']     = clean_due($item['due'] ?? '');
        // Items written before dates were recorded simply have none.
        $item['added']     = clean_stamp($item['added'] ?? '');
        $item['completed'] = clean_stamp($item['completed'] ?? '');
    }
    unset($item);

    return [
        'name'     => $name,
        'items'    => array_values($items),
        'archived' => $archived,
        'timezone' => $timezone,
    ];
}

/** Keep only usable group names, once each. */
function clean_archived($value): array
{
    if (!is_array($value)) {
        return [];
    }
    $out = [];
    foreach ($value as $g) {
        if (is_string($g)) {
            $out[trim($g)] = true;
        }
    }
    return array_keys($out);
}

/** Is this group (the raw group value, '' for ungrouped) archived? */
function is_archived(string $group, array $archived): bool
{
    return in_array(trim($group), $archived, true);
}

/**
 * Persist the list name and items to the JSON file with an exclusive lock so
 * concurrent requests don't clobber each other.
 */
function save_data(string $name, array $items, array $archived = [], string $timezone = ''): void
{
    $name = trim($name);
    if ($name === '') {
        $name = DEFAULT_LIST_NAME;
    }
    // A file with no timezone of its own records the one in use, so it says
    // what its dates mean from the first save onwards.
    $payload = [
        'name'     => $name,
        'timezone' => clean_timezone($timezone) ?: date_default_timezone_get(),
        'items'    => array_values($items),
        'archived' => array_values($archived),
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents(DATA_FILE, $json, LOCK_EX);
}

/**
 * Normalize decoded JSON (from an uploaded file) into the canonical
 * ['name' => ..., 'items' => [...]] shape, sanitizing each item. Accepts both
 * the current object format and the legacy bare-array-of-items format.
 * Returns null if the input isn't a usable structure.
 */
function normalize_uploaded($data): ?array
{
    if (!is_array($data)) {
        return null;
    }
    if (array_is_list($data)) {
        $name  = DEFAULT_LIST_NAME;
        $items = $data;
    } else {
        $name  = (isset($data['name']) && is_string($data['name']) && trim($data['name']) !== '')
            ? $data['name'] : DEFAULT_LIST_NAME;
        $items = (isset($data['items']) && is_array($data['items'])) ? $data['items'] : [];
    }
    $clean = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $task = isset($it['task']) && is_string($it['task']) ? $it['task']
              : (isset($it['name']) && is_string($it['name']) ? $it['name'] : '');
        $clean[] = [
            'id'         => (isset($it['id']) && is_string($it['id']) && $it['id'] !== '') ? $it['id'] : new_id(),
            'task'       => trim($task),
            'status'     => clean_status($it['status'] ?? null),
            'completion' => clean_completion($it['completion'] ?? 0),
            'group'      => clean_group($it['group'] ?? ''),
            'comment'    => clean_comment($it['comment'] ?? ''),
            'due'        => clean_due($it['due'] ?? ''),
            'added'      => clean_stamp($it['added'] ?? ''),
            'completed'  => clean_stamp($it['completed'] ?? ''),
        ];
    }
    return [
        'name'     => $name,
        'timezone' => clean_timezone(is_array($data) ? ($data['timezone'] ?? '') : ''),
        'items'    => $clean,
        'archived' => clean_archived(is_array($data) ? ($data['archived'] ?? []) : []),
    ];
}

/** Coerce a status string to a valid value, defaulting to PENDING. */
function clean_status(?string $status): string
{
    $status = strtoupper(trim((string) $status));
    return in_array($status, STATUSES, true) ? $status : 'PENDING';
}

/** Clamp a completion value into the 0-100 range. */
function clean_completion($value): int
{
    $n = (int) $value;
    if ($n < 0) {
        $n = 0;
    }
    if ($n > 100) {
        $n = 100;
    }
    return $n;
}

/**
 * Tidy a free-text comment: one newline style, no stray whitespace, and a
 * ceiling so a pasted document can't bloat the data file.
 */
function clean_comment($value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
    $value = trim($value);
    if (strlen($value) > 2000) {
        $value = rtrim(substr($value, 0, 2000));
    }
    return $value;
}

/** Trim a group name (empty means ungrouped). */
function clean_group($value): string
{
    return trim((string) $value);
}

/**
 * A due date is a day on a calendar, not a moment, so it is kept as plain
 * YYYY-MM-DD with no timezone attached. Anything else is dropped.
 */
function clean_due($value): string
{
    $value = trim((string) $value);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return '';
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
}

/**
 * Apply the due-date rule to the whole list, and say whether anything moved so
 * the caller knows to write the file. "Today" is the server's date, so a list
 * served from a machine in another timezone can be a day out at the boundary.
 */
function promote_due_soon(array &$items): bool
{
    $cutoff  = date('Y-m-d', strtotime('+' . DUE_URGENT_DAYS . ' days'));
    $changed = false;
    foreach ($items as &$item) {
        if (($item['due'] ?? '') !== ''
            && in_array($item['status'] ?? '', DUE_URGENT_FROM, true)
            && $item['due'] < $cutoff) {          // both are YYYY-MM-DD
            $item['status'] = 'URGENT';
            $changed = true;
        }
    }
    unset($item);
    return $changed;
}

/** Timestamp for right now, in the stored format. */
function now_stamp(): string
{
    return date(DATE_FORMAT);
}

/**
 * Accept a stored timestamp, or '' for "no date". Anything that isn't a
 * readable date is dropped rather than shown back to the user.
 */
function clean_stamp($value): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = trim($value);
    if ($value === '' || strlen($value) > 40 || strtotime($value) === false) {
        return '';
    }
    return $value;
}

/**
 * Keep the completion date in step with the status: stamp it when an item
 * becomes DONE, clear it when it moves back off DONE, and leave the date of an
 * item that was already DONE alone.
 */
function stamp_done(array $item): array
{
    if (($item['status'] ?? '') === 'DONE') {
        if (clean_stamp($item['completed'] ?? '') === '') {
            $item['completed'] = now_stamp();
        }
    } else {
        $item['completed'] = '';
    }
    return $item;
}

/**
 * The date to show for an item, or null for none: when it was added while it
 * is still outstanding, when it was completed once it is DONE. SKIPPED items
 * get neither, and items from before dates were recorded have nothing to show.
 */
function item_date(array $item): ?array
{
    $status = (string) ($item['status'] ?? '');
    if ($status === 'DONE') {
        $stamp = clean_stamp($item['completed'] ?? '');
        $label = 'Completed';
        $class = 'date-done';
    } elseif (in_array($status, OPEN_STATUSES, true)) {
        $stamp = clean_stamp($item['added'] ?? '');
        $label = 'Added';
        $class = 'date-added';
    } else {
        return null;
    }
    if ($stamp === '') {
        return null;
    }
    return [
        'label' => $label,
        'class' => $class,
        'stamp' => $stamp,
        'short' => substr($stamp, 0, 10),   // the date, without the time
    ];
}

/**
 * Point the app at another data file (pass '' to fall back to the default).
 * The group the add form defaults to belongs to the file being left — its
 * groups mean nothing in the new one — so it is forgotten at the same time.
 * The remembered status is kept: statuses are the same in every file.
 */
function switch_data_file(string $fname): void
{
    if ($fname === '') {
        setcookie('todo_file', '', ['path' => '/', 'expires' => 1]);
    } else {
        setcookie('todo_file', $fname, ['path' => '/', 'samesite' => 'Lax']);
    }
    setcookie('add_group', '', ['path' => '/', 'expires' => 1]);
    unset($_COOKIE['add_group']);   // also for the rest of this request
}

/** Generate a reasonably unique id for a new item. */
function new_id(): string
{
    return bin2hex(random_bytes(8));
}

/** Return the sorted, de-duplicated list of group names currently in use. */
function existing_groups(array $items): array
{
    $groups = [];
    foreach ($items as $it) {
        $g = trim((string) ($it['group'] ?? ''));
        if ($g !== '') {
            $groups[$g] = true;
        }
    }
    $groups = array_keys($groups);
    natcasesort($groups);
    return array_values($groups);
}

/** Rank of a status for within-group ordering (unknown statuses sort last). */
function status_rank(string $status): int
{
    $i = array_search($status, STATUS_ORDER, true);
    return $i === false ? count(STATUS_ORDER) : (int) $i;
}

/**
 * Bucket items by group. Ungrouped items go into a bucket keyed by the
 * UNGROUPED label, which is always sorted last. Items within each group are
 * ordered by status per STATUS_ORDER (stable: equal statuses keep their
 * existing relative order).
 */
function group_items(array $items, array $archived = []): array
{
    $buckets = [];
    foreach ($items as $it) {
        $g = trim((string) ($it['group'] ?? ''));
        $key = $g === '' ? UNGROUPED : $g;
        $buckets[$key][] = $it;
    }
    uksort($buckets, static function ($a, $b) use ($archived) {
        // Archived groups sit after everything else, then the ungrouped bucket
        // after the named ones, then plain alphabetical order.
        $aa = is_archived($a === UNGROUPED ? '' : $a, $archived);
        $ab = is_archived($b === UNGROUPED ? '' : $b, $archived);
        if ($aa !== $ab) {
            return $aa ? 1 : -1;
        }
        if ($a === UNGROUPED) {
            return 1;
        }
        if ($b === UNGROUPED) {
            return -1;
        }
        return strnatcasecmp($a, $b);
    });
    foreach ($buckets as &$bucketItems) {
        usort($bucketItems, static fn($x, $y) => status_rank($x['status']) <=> status_rank($y['status']));
    }
    unset($bucketItems);
    return $buckets;
}

/** Average completion across a group's items (0-100), excluding SKIPPED. */
function group_progress(array $groupItems): int
{
    $counted = array_filter($groupItems, static fn($it) => ($it['status'] ?? '') !== 'SKIPPED');
    if (empty($counted)) {
        return 0;
    }
    $sum = 0;
    foreach ($counted as $it) {
        $sum += (int) $it['completion'];
    }
    return (int) round($sum / count($counted));
}

// ---------------------------------------------------------------------------
// Handle actions (POST) using the Post/Redirect/Get pattern so a refresh
// doesn't re-submit the form.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $data       = load_data();
    $listName   = $data['name'];
    $items      = $data['items'];
    $archived   = $data['archived'];
    $timezone   = $data['timezone'];
    $activeFile = basename(DATA_FILE);   // carried into the redirect URL

    // Dates below — stamps on edits, the due-date window — are this list's own.
    apply_timezone($timezone);

    if ($action === 'add') {
        $task    = trim((string) ($_POST['task'] ?? ''));
        $status  = clean_status($_POST['status'] ?? null);
        $group   = clean_group($_POST['group'] ?? '');
        $comment = clean_comment($_POST['comment'] ?? '');
        $due     = clean_due($_POST['due'] ?? '');
        // Remember the last-used group and status so the add form keeps them
        // for the next item (handy when adding several to the same group).
        setcookie('add_group', $group, ['path' => '/', 'samesite' => 'Lax']);
        setcookie('add_status', $status, ['path' => '/', 'samesite' => 'Lax']);
        if ($task !== '') {
            // New tasks start at 0% — unless added as DONE, which means 100%.
            $completion = $status === 'DONE' ? 100 : 0;
            $items[] = stamp_done([
                'id'         => new_id(),
                'task'       => $task,
                'status'     => $status,
                'completion' => $completion,
                'group'      => $group,
                'comment'    => $comment,
                'due'        => $due,
                'added'      => now_stamp(),
                'completed'  => '',
            ]);
            save_data($listName, $items, $archived, $timezone);
        }
    } elseif ($action === 'update_field') {
        // Inline edit of a single field on a single item.
        $id    = (string) ($_POST['id'] ?? '');
        $field = (string) ($_POST['field'] ?? '');
        $value = $_POST['value'] ?? '';
        if (in_array($field, EDITABLE_FIELDS, true)) {
            foreach ($items as &$item) {
                if ($item['id'] === $id) {
                    if ($field === 'task') {
                        $task = trim((string) $value);
                        if ($task !== '') {   // never blank a task
                            $item['task'] = $task;
                        }
                    } elseif ($field === 'status') {
                        $item['status'] = clean_status((string) $value);
                        if ($item['status'] === 'DONE') {
                            // Marking an item DONE means it's fully complete.
                            $item['completion'] = 100;
                        } elseif ((int) $item['completion'] === 100) {
                            // Reverse: moving off DONE while at 100% drops completion.
                            $item['completion'] = 0;
                        }
                        $item = stamp_done($item);
                    } elseif ($field === 'completion') {
                        $wasDone = ($item['status'] === 'DONE');
                        $item['completion'] = clean_completion($value);
                        if ($item['completion'] === 100) {
                            // Reaching 100% marks the item DONE.
                            $item['status'] = 'DONE';
                        } elseif ($wasDone) {
                            // Reverse: dropping below 100% un-marks a DONE item.
                            $item['status'] = 'UNDONE';
                        }
                        $item = stamp_done($item);
                    } elseif ($field === 'group') {
                        $item['group'] = clean_group($value);
                    } elseif ($field === 'comment') {
                        $item['comment'] = clean_comment($value);
                    } elseif ($field === 'due') {
                        $item['due'] = clean_due($value);
                    }
                    break;
                }
            }
            unset($item);
            save_data($listName, $items, $archived, $timezone);
        }
    } elseif ($action === 'delete') {
        $id = (string) ($_POST['id'] ?? '');
        $items = array_filter($items, static fn($it) => $it['id'] !== $id);
        save_data($listName, $items, $archived, $timezone);
    } elseif ($action === 'rename_group') {
        // Rename a whole group in one go.
        $from = clean_group($_POST['from'] ?? '');
        $to   = clean_group($_POST['to'] ?? '');
        if ($from !== '') {
            foreach ($items as &$item) {
                if (trim((string) ($item['group'] ?? '')) === $from) {
                    $item['group'] = $to;
                }
            }
            unset($item);
            // The archived flag belongs to the group, so it follows the name.
            if (is_archived($from, $archived)) {
                $archived = array_values(array_diff($archived, [$from]));
                $archived[] = $to;
                $archived = clean_archived($archived);
            }
            save_data($listName, $items, $archived, $timezone);
        }
    } elseif ($action === 'set_archived') {
        // Archive or un-archive a whole group ('' is the ungrouped bucket).
        $group = clean_group($_POST['group'] ?? '');
        $on    = ($_POST['value'] ?? '') === '1';
        $archived = array_values(array_diff($archived, [$group]));
        if ($on) {
            $archived[] = $group;
        }
        save_data($listName, $items, $archived, $timezone);
    } elseif ($action === 'set_timezone') {
        // Change the timezone this list is kept in. The rest of this request
        // works in the new one, so the due-date window below uses it too.
        $picked = clean_timezone($_POST['value'] ?? '');
        if ($picked !== '') {
            $timezone = $picked;
            apply_timezone($timezone);
            save_data($listName, $items, $archived, $timezone);
        }
    } elseif ($action === 'rename_list') {
        // Rename the whole todo list.
        $listName = trim((string) ($_POST['value'] ?? ''));
        save_data($listName, $items, $archived, $timezone);
    } elseif ($action === 'select_file') {
        // Switch which data file the app uses; create it if new.
        $fname = safe_data_filename($_POST['file'] ?? '');
        if ($fname !== null) {
            $path = DATA_DIR . DIRECTORY_SEPARATOR . $fname;
            if (!is_file($path)) {
                $base = (string) pathinfo($fname, PATHINFO_FILENAME);
                $seed = json_encode(
                    [
                        'name'     => ($base !== '' ? $base : DEFAULT_LIST_NAME),
                        'timezone' => date_default_timezone_get(),
                        'items'    => [],
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                file_put_contents($path, $seed, LOCK_EX);
            }
            switch_data_file($fname);
            $activeFile = $fname;
        }
    } elseif ($action === 'upload') {
        // Import a JSON data file from the user's computer, save it into the
        // app folder under its (sanitized) name, and switch to it.
        $up = $_FILES['datafile'] ?? null;
        if (is_array($up) && ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && is_uploaded_file($up['tmp_name'] ?? '') && ($up['size'] ?? 0) <= 5 * 1024 * 1024) {
            $fname = safe_data_filename($up['name'] ?? '');
            $raw   = (string) file_get_contents($up['tmp_name']);
            $norm  = normalize_uploaded(json_decode($raw, true));
            if ($fname !== null && $norm !== null) {
                $json = json_encode($norm, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                file_put_contents(DATA_DIR . DIRECTORY_SEPARATOR . $fname, $json, LOCK_EX);
                switch_data_file($fname);
                $activeFile = $fname;
            }
        }
    } elseif ($action === 'delete_file') {
        // Delete a data file and switch to another (or back to the default).
        $fname = safe_data_filename($_POST['file'] ?? '');
        if ($fname !== null) {
            $path = DATA_DIR . DIRECTORY_SEPARATOR . $fname;
            if (is_file($path)) {
                @unlink($path);
            }
            // Pick the first remaining .json file, if any, as the new selection.
            $remaining = array_map('basename', glob(DATA_DIR . DIRECTORY_SEPARATOR . '*.json') ?: []);
            natcasesort($remaining);
            $remaining = array_values($remaining);
            if (!empty($remaining)) {
                switch_data_file($remaining[0]);
                $activeFile = $remaining[0];
            } else {
                // Nothing left: clear the selection so it falls back to todo.json.
                switch_data_file('');
                $activeFile = 'todo.json';
            }
        }
    }

    // A change may have brought a task inside the due-date window, and time
    // alone may have done the same to others, so the rule runs over the list
    // before the answer goes out. File actions are left alone: $items there
    // belongs to the file being switched away from.
    if (in_array($action, ['add', 'update_field', 'delete', 'rename_group', 'rename_list', 'set_archived', 'set_timezone'], true)
        && promote_due_soon($items)) {
        save_data($listName, $items, $archived, $timezone);
    }

    // Background edit from the page: answer with the re-rendered fragments
    // instead of redirecting. They are built from the items still in memory —
    // exactly what was just written — so the data file is not read back, and
    // the browser patches the page in place rather than reloading it. Only the
    // list actions qualify; the file actions change which file is shown and
    // still go through a normal submit and full load.
    if (($_POST['ajax'] ?? '') === '1'
        && in_array($action, ['add', 'update_field', 'delete', 'rename_group', 'rename_list', 'set_archived', 'set_timezone'], true)) {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok'     => true,
            'list'   => render_list($items, $archived),
            'stats'  => render_stats($items, $archived),
            'groups' => render_group_options($items),
            'name'   => $listName,
            'sig'    => file_signature(),   // now ours, so the poll stays quiet
        ]);
        exit;
    }

    // Redirect back including the active file in the URL, so a later refresh
    // reads the current file even if cookies aren't available.
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?file=' . rawurlencode($activeFile));
    exit;
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------
$data     = load_data();
$listName = $data['name'];
$items    = $data['items'];
$archived = $data['archived'];
$timezone = $data['timezone'];

apply_timezone($timezone);

// Due dates come round on their own, so the rule is applied when the page is
// loaded too, and written back when it actually changed something.
if (promote_due_soon($items)) {
    save_data($listName, $items, $archived, $timezone);
}

// Sticky defaults for the add form (kept from the last added item).
$addGroup  = clean_group($_COOKIE['add_group'] ?? '');
$addStatus = clean_status($_COOKIE['add_status'] ?? 'PENDING');

// Data-file change signature at render time, for the auto-refresh poll.
$fileSig = file_signature();

// Data files available in the app folder, plus the current one (which may not
// exist on disk yet if nothing has been saved).
$jsonFiles   = array_map('basename', glob(DATA_DIR . DIRECTORY_SEPARATOR . '*.json') ?: []);
$currentFile = basename(DATA_FILE);
if (!in_array($currentFile, $jsonFiles, true)) {
    $jsonFiles[] = $currentFile;
}
$jsonFiles = array_unique($jsonFiles);
natcasesort($jsonFiles);
$jsonFiles = array_values($jsonFiles);

/** Shortcut for HTML-escaping output. */
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * The timezone <select>, grouped by region so the list is navigable, with
 * $current pre-selected.
 */
function timezone_options(string $current): string
{
    $regions = [];
    foreach (DateTimeZone::listIdentifiers() as $id) {
        $cut    = strpos($id, '/');
        $region = $cut === false ? 'Other' : substr($id, 0, $cut);
        // The whole name, so the closed select still says which region it is;
        // the groups are there to make the open list navigable.
        $regions[$region][$id] = str_replace('_', ' ', $id);
    }
    $out = '';
    foreach ($regions as $region => $ids) {
        $out .= '<optgroup label="' . e($region) . '">';
        foreach ($ids as $id => $label) {
            $sel = $id === $current ? ' selected' : '';
            $out .= '<option value="' . e($id) . '"' . $sel . '>' . e($label) . '</option>';
        }
        $out .= '</optgroup>';
    }
    return $out;
}

/** Render the shared status <select>; $current is pre-selected. */
function status_options(string $current): string
{
    $out = '';
    foreach (STATUSES as $s) {
        $sel = $s === $current ? ' selected' : '';
        $out .= '<option value="' . $s . '"' . $sel . '>' . $s . '</option>';
    }
    return $out;
}

/**
 * Render the options for a row's "Move…" dropdown: the other existing
 * groups, an Ungrouped choice (if currently grouped), and New group.
 * Special sentinel values are handled by the moveItem() JS.
 */
function move_options(string $currentGroup, array $allGroups): string
{
    $out = '<option value="" selected>Move&hellip;</option>';
    if ($currentGroup !== '') {
        $out .= '<option value="__ungroup__">&mdash; Ungrouped &mdash;</option>';
    }
    foreach ($allGroups as $g) {
        if ($g === $currentGroup) {
            continue;
        }
        $out .= '<option value="' . e($g) . '">' . e($g) . '</option>';
    }
    $out .= '<option value="__new__">&#43; New group&hellip;</option>';
    return $out;
}

/**
 * A column caption that sorts the panel's rows when clicked. The sorting
 * itself is done in the browser (see initSort), so it never touches the file
 * or the order items are stored in.
 */
function sort_link(string $key, string $label, string $title = ''): string
{
    return '<button type="button" class="sort" data-sort="' . e($key) . '"'
        . ($title !== '' ? ' title="' . e($title) . '"' : '')
        . '>' . e($label) . '</button>';
}

/**
 * Overall figures for the stats panel. SKIPPED items are left out of the
 * completion average; OPEN_STATUSES are tallied as outstanding work.
 */
function compute_stats(array $items, array $archived = []): array
{
    // Anything in an archived group is out of the picture entirely.
    $live       = array_values(array_filter($items, static fn($i) => !is_archived((string) ($i['group'] ?? ''), $archived)));
    $archivedN  = count($items) - count($live);
    $items      = $live;

    $counted  = array_values(array_filter($items, static fn($i) => ($i['status'] ?? '') !== 'SKIPPED'));
    $countedN = count($counted);

    $open = [];
    foreach (OPEN_STATUSES as $s) {
        $open[$s] = count(array_filter($items, static fn($i) => ($i['status'] ?? '') === $s));
    }

    return [
        'countedN'  => $countedN,
        'archivedN' => $archivedN,
        'skippedN' => count($items) - $countedN,
        'overall'  => $countedN
            ? (int) round(array_sum(array_map(static fn($i) => (int) $i['completion'], $counted)) / $countedN)
            : 0,
        'doneN'    => count(array_filter($counted, static fn($i) => (int) $i['completion'] === 100)),
        'open'     => $open,
        'openN'    => array_sum($open),
    ];
}

// ---------------------------------------------------------------------------
// The fragments below are rendered from an items array rather than from the
// data file, so the same code serves a full page load and the answer to a
// background edit (see the AJAX branch of the POST handler).
// ---------------------------------------------------------------------------

/** Stats panel; empty string when there is nothing to report. */
function render_stats(array $items, array $archived = []): string
{
    if (empty($items)) {
        return '';
    }
    $st = compute_stats($items, $archived);
    ob_start(); ?>
    <div class="stats">
        <div class="stat-figure">
            <span class="stat-pct"><?= $st['overall'] ?>%</span>
            <span class="stat-label">overall completion</span>
        </div>
        <div class="stat-bar bar"><span style="width: <?= $st['overall'] ?>%;"></span></div>
        <div class="stat-meta">
            <b><?= $st['countedN'] ?></b> task<?= $st['countedN'] === 1 ? '' : 's' ?>
            <span class="sep">·</span> <b><?= $st['doneN'] ?></b> done
            <span class="sep">·</span> <b><?= $st['openN'] ?></b> uncompleted
            <?php if ($st['skippedN'] > 0): ?><span class="sep">·</span> <b><?= $st['skippedN'] ?></b> skipped <span class="quiet">(excluded)</span><?php endif; ?>
            <?php if ($st['archivedN'] > 0): ?><span class="sep">·</span> <b><?= $st['archivedN'] ?></b> inactive <span class="quiet">(excluded)</span><?php endif; ?>
        </div>
        <?php if ($st['openN'] > 0): ?>
        <div class="stat-open">
            <?php foreach ($st['open'] as $s => $n): ?>
                <?php if ($n > 0): ?>
                    <span class="open-chip <?= e($s) ?>"><?= e($s) ?> <b><?= $n ?></b></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php return (string) ob_get_clean();
}

/** <option>s for the group-name autocomplete list. */
function render_group_options(array $items): string
{
    $out = '';
    foreach (existing_groups($items) as $g) {
        $out .= '<option value="' . e($g) . '"></option>';
    }
    return $out;
}

/** The toolbar, the grouped item tables and the item-count footer. */
function render_list(array $items, array $archived = []): string
{
    $items   = array_values($items);
    $groups  = existing_groups($items);
    $buckets = group_items($items, $archived);

    ob_start(); ?>
    <?php if (empty($items)): ?>
        <p class="empty">No items yet. Add your first one above.</p>
    <?php else: ?>

    <div class="toolbar">
        <button type="button" id="expand-all">Expand all</button>
        <button type="button" id="collapse-all">Collapse all</button>
        <label class="check" title="Show each task's completion">
            <input type="checkbox" id="show-completion"> Show completion
        </label>
        <label class="check" title="Show when each task was added, or when it was completed">
            <input type="checkbox" id="show-dates"> Show dates
        </label>
        <label class="check" title="Show the date each task is due">
            <input type="checkbox" id="show-due"> Show due
        </label>
        <label class="check" title="Show the Move and Delete controls on each task">
            <input type="checkbox" id="show-controls"> Show task controls
        </label>
        <button type="button" id="undone-only" class="toggle" aria-pressed="false"
                title="Hide DONE and SKIPPED items">Uncompleted only</button>
    </div>

    <div class="groups">
    <?php foreach ($buckets as $groupName => $groupItems): ?>
        <?php
        $gp       = group_progress($groupItems);
        $rawGroup = $groupName === UNGROUPED ? '' : $groupName;
        $isArch   = is_archived($rawGroup, $archived);
        ?>
        <details class="group<?= $isArch ? ' archived' : '' ?>" data-group="<?= e($groupName) ?>" open>
            <summary>
                <span class="caret">&#9654;</span>
                <span class="gname"><?= e($groupName) ?></span>
                <span class="count"><?= count($groupItems) ?> item<?= count($groupItems) === 1 ? '' : 's' ?></span>
                <span class="gbar">
                    <span class="bar sm"><span style="width: <?= $gp ?>%;"></span></span>
                    <span class="count"><?= $gp ?>%</span>
                    <?php if ($groupName !== UNGROUPED): ?>
                        <form class="inline" method="post" action=""
                              onsubmit="var t=prompt('Rename group “<?= e($groupName) ?>” to:', '<?= e($groupName) ?>'); if(t===null){return false;} this.to.value=t; return true;">
                            <input type="hidden" name="action" value="rename_group">
                            <input type="hidden" name="from" value="<?= e($groupName) ?>">
                            <input type="hidden" name="to" value="">
                            <button class="rename" type="submit" onclick="event.stopPropagation();">rename</button>
                        </form>
                    <?php endif; ?>
                </span>
                <!-- Ticked is the normal state. Unticking archives the group:
                     it drops to the end of the list and out of the statistics.
                     The posted value is the archived flag, so it is still the
                     opposite of where the group is now. -->
                <form class="inline arch-form" method="post" action="" onclick="event.stopPropagation();">
                    <input type="hidden" name="action" value="set_archived">
                    <input type="hidden" name="group" value="<?= e($rawGroup) ?>">
                    <input type="hidden" name="value" value="<?= $isArch ? '0' : '1' ?>">
                    <label class="check arch-check" title="Untick to set this group aside: it moves to the end and is left out of the statistics">
                        <input type="checkbox" <?= $isArch ? '' : 'checked' ?>
                               onchange="submitList(this.form)"> Active
                    </label>
                </form>
            </summary>
            <div class="group-body">
                <table>
                    <thead>
                        <tr>
                            <th><?= sort_link('task', 'Task', 'Sort this group by task name') ?></th>
                            <th class="colw-status"><?= sort_link('status', 'Status', 'Sort this group by status') ?></th>
                            <th class="colw-comp"><?= sort_link('completion', 'Completion', 'Sort this group by completion') ?></th>
                            <th class="colw-date"><?= sort_link('date', 'Date', 'Sort this group by date (added, or completed once DONE)') ?></th>
                            <th class="colw-due"><?= sort_link('due', 'Due', 'Sort this group by due date') ?></th>
                            <th class="colw-act"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($groupItems as $it): ?>
                        <?php
                        // Hover text: the full task name (the column often cuts
                        // it short), and the comments under it when there are any.
                        $tip = $it['task'];
                        if ($it['comment'] !== '') {
                            $tip .= "\n\n" . $it['comment'];
                        }
                        ?>
                        <tr data-status="<?= e($it['status']) ?>" title="<?= e($tip) ?>"
                            class="<?= $it['status'] === 'SKIPPED' ? 'item-skipped' : '' ?>">
                            <!-- Task: inline edit, saves on blur/Enter -->
                            <td>
                                <form class="inline" method="post" action="" style="display:block;">
                                    <input type="hidden" name="action" value="update_field">
                                    <input type="hidden" name="id" value="<?= e($it['id']) ?>">
                                    <input type="hidden" name="field" value="task">
                                    <input class="edit-name" name="value" value="<?= e($it['task']) ?>"
                                           onchange="submitList(this.form)"
                                           onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}">
                                </form>
                            </td>
                            <!-- Status: inline select, saves on change -->
                            <td class="colw-status">
                                <form class="inline" method="post" action="">
                                    <input type="hidden" name="action" value="update_field">
                                    <input type="hidden" name="id" value="<?= e($it['id']) ?>">
                                    <input type="hidden" name="field" value="status">
                                    <select class="status <?= e($it['status']) ?>" name="value" onchange="submitList(this.form)">
                                        <?= status_options($it['status']) ?>
                                    </select>
                                </form>
                            </td>
                            <!-- Completion: inline number, saves on change -->
                            <td class="colw-comp">
                                <div class="comp-cell">
                                    <form class="inline" method="post" action="">
                                        <input type="hidden" name="action" value="update_field">
                                        <input type="hidden" name="id" value="<?= e($it['id']) ?>">
                                        <input type="hidden" name="field" value="completion">
                                        <input type="number" name="value" min="0" max="100" step="1"
                                               value="<?= (int) $it['completion'] ?>" onchange="submitList(this.form)">
                                    </form>
                                    <span class="bar"><span style="width: <?= (int) $it['completion'] ?>%;"></span></span>
                                </div>
                            </td>
                            <!-- Date: added while outstanding, completed once DONE -->
                            <td class="colw-date">
                                <?php $d = item_date($it); ?>
                                <?php if ($d !== null): ?>
                                    <span class="date <?= $d['class'] ?>"
                                          data-stamp="<?= e($d['stamp']) ?>" data-label="<?= e($d['label']) ?>"
                                          title="<?= e($d['label'] . ' ' . $d['stamp']) ?>"><?= e($d['short']) ?></span>
                                <?php endif; ?>
                            </td>
                            <!-- Due: the day the task is due, if it has one -->
                            <td class="colw-due" data-due="<?= e($it['due']) ?>">
                                <form class="inline" method="post" action="">
                                    <input type="hidden" name="action" value="update_field">
                                    <input type="hidden" name="id" value="<?= e($it['id']) ?>">
                                    <input type="hidden" name="field" value="due">
                                    <input type="date" class="edit-due<?= $it['due'] === '' ? ' empty' : '' ?>"
                                           name="value" value="<?= e($it['due']) ?>"
                                           title="<?= $it['due'] === '' ? 'Set a due date' : 'Due date — clear the field to remove it' ?>"
                                           onchange="submitList(this.form)">
                                </form>
                            </td>
                            <!-- Actions: edit the comments, move to another group, delete -->
                            <td class="colw-act">
                                <div class="row-actions">
                                    <button type="button" class="note-btn<?= $it['comment'] === '' ? '' : ' has' ?>"
                                            data-id="<?= e($it['id']) ?>" data-task="<?= e($it['task']) ?>"
                                            data-comment="<?= e($it['comment']) ?>"
                                            title="<?= $it['comment'] === '' ? 'Add comments' : 'Edit comments' ?>">Comment</button>
                                    <form class="inline move-form" method="post" action="">
                                        <input type="hidden" name="action" value="update_field">
                                        <input type="hidden" name="id" value="<?= e($it['id']) ?>">
                                        <input type="hidden" name="field" value="group">
                                        <input type="hidden" name="value" value="">
                                        <select class="move-select" onchange="moveItem(this)" title="Move to another group">
                                            <?= move_options($it['group'], $groups) ?>
                                        </select>
                                    </form>
                                    <form class="inline" method="post" action="" onsubmit="return confirm('Delete this item?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= e($it['id']) ?>">
                                        <button class="del" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php endforeach; ?>
    </div>

    <p class="muted"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?> in <?= count($buckets) ?> group<?= count($buckets) === 1 ? '' : 's' ?>. Data stored in <?= e(basename(DATA_FILE)) ?>.</p>
    <?php endif; ?>
    <?php return (string) ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($listName) ?></title>
<style>
    /* A control sized at width:100% has to include its own padding and border,
       or it hangs over the edge of the cell holding it (the task name and the
       list title both did). */
    *, *::before, *::after { box-sizing: border-box; }

    body { font-family: system-ui, Arial, sans-serif; max-width: 1720px; margin: 2rem auto; padding: 0 1rem; color: #222; }
    h1 { font-size: 1.5rem; margin-bottom: .5rem; }
    .head-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem 1rem; margin-bottom: .5rem; }
    .title-form { margin: 0; flex: 1 1 320px; }
    .file-form { margin: 0; }
    .file-label { font-size: .8rem; color: #666; display: inline-flex; align-items: center; gap: .35rem; }
    #file-select { font-size: .85rem; padding: .3rem .4rem; border: 1px solid #bbb; border-radius: 4px; background: #fff; cursor: pointer; }
    .file-controls { display: flex; align-items: center; flex-wrap: wrap; gap: .5rem; }
    .file-form { display: inline-flex; align-items: center; gap: .5rem; }
    .file-del-form { margin: 0; }
    .file-new { font-size: .82rem; padding: .3rem .6rem; }
    .file-up-form { margin: 0; }
    .file-up { font-size: .82rem; padding: .3rem .6rem; }
    .file-del { font-size: .82rem; padding: .3rem .6rem; color: #c0392b; border-color: #e3b6b1; background: #fff; }
    .file-del:hover { background: #fdecea; }
    .file-dl { display: inline-block; text-decoration: none; font-size: .82rem; padding: .3rem .6rem;
               color: #256b34; background: linear-gradient(180deg, #ffffff, #e2efe4);
               border: 1px solid #a9c6b0; border-radius: 6px; cursor: pointer; line-height: normal;
               box-shadow: 0 2px 3px rgba(28,66,42,.2), inset 0 1px 0 rgba(255,255,255,.85);
               text-shadow: 0 1px 0 rgba(255,255,255,.6); }
    .file-dl:hover { background: linear-gradient(180deg, #ffffff, #d3e8d8); }
    .file-dl:active { transform: translateY(1px); box-shadow: inset 0 2px 4px rgba(20,40,25,.25); }
    .list-title { font-size: 1.5rem; font-weight: 700; color: #222; border: 1px solid transparent; background: transparent; border-radius: 5px; padding: .1rem .3rem; margin-left: -.3rem; width: 100%; max-width: 640px; font-family: inherit; }
    .list-title:hover { border-color: #e0e0e0; }
    .list-title:focus { border-color: #2d6cdf; background: #fff; outline: none; }
    .toolbar { display: flex; gap: 1rem; align-items: center; margin-bottom: 1rem; font-size: .8rem; }
    .toolbar button { background: none; border: none; color: #2d6cdf; padding: 0; cursor: pointer; font-size: .8rem; }
    .toolbar button:hover { text-decoration: underline; }
    /* Latching filter button */
    .toolbar button.toggle { margin-left: auto; border: 1px solid #bbb; border-radius: 4px; padding: .25rem .6rem; color: #444; }
    .toolbar button.toggle:hover { text-decoration: none; background: #f0f0f0; }
    .toolbar button.toggle.pressed { background: #2d6cdf; border-color: #2d6cdf; color: #fff; }
    .toolbar button.toggle.pressed:hover { background: #245ac0; }

    /* "Uncompleted only" filter: hide completed / skipped rows. */
    body.undone-only tr[data-status="DONE"],
    body.undone-only tr[data-status="SKIPPED"] { display: none; }

    .add-form { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .5rem; align-items: flex-end; margin-bottom: 1.5rem; padding: 1rem; border: 1px solid #ccc; border-radius: 6px; background: #fafafa; }
    .add-form label { display: flex; flex-direction: column; font-size: .8rem; color: #555; gap: .2rem; }
    /* Fields share the page font: browsers give each input type its own default
       (a date field lands on monospace), which makes a row of them look mixed. */
    input[type=text], input[type=number], input[type=date], select { padding: .4rem; border: 1px solid #bbb; border-radius: 4px; font-family: inherit; font-size: .9rem; background: #fff; }
    /* A date field is taller than a text one by default (its picker button) and
       picks a different family; pin both so it sits in line with the rest. */
    input[type=date] { line-height: normal; }
    .add-form input[type=text], .add-form input[type=date] { width: 150px; min-width: 0; }
    /* Task takes whatever width is left over, which pushes Group, Status and
       the button to the right-hand end of the row. The rest are kept to what
       they need, so the row stays unbroken on a narrower panel. */
    .add-form label.grow { flex: 1 1 180px; }
    .add-form label.grow input { width: 100%; }
    .add-form label.wide { flex: 1 1 100%; }
    .add-form textarea {
        font: inherit; font-size: .85rem; resize: vertical; min-height: 2.6rem;
        padding: .3rem .4rem; border: 1px solid #bbb; border-radius: 4px; background: #fff;
    }
    input[type=number] { width: 68px; }
    button { padding: .45rem .8rem; border: 1px solid #888; border-radius: 4px; background: #eee; cursor: pointer; font-size: .9rem; }
    button:hover { background: #ddd; }
    button.primary { background: #2d6cdf; color: #fff; border-color: #2d6cdf; }
    button.primary:hover { background: #245ac0; }

    form.inline { margin: 0; display: inline; }

    /* Responsive multi-column flow: adds columns as the window widens,
       and never splits a group card across two columns. */
    /* Card columns. The width has to fit twice inside the body's content box
       (1720px max-width, less its 1rem padding on each side) or the list drops
       back to a single column. */
    .groups { column-width: 820px; column-gap: 1.2rem; }

    /* Collapsible group (tree node) */
    details.group { border: 1px solid #e2e2e2; border-radius: 6px; margin-bottom: .6rem; background: #fff; break-inside: avoid; -webkit-column-break-inside: avoid; page-break-inside: avoid; }
    details.group > summary { list-style: none; cursor: pointer; padding: .55rem .7rem; display: flex; align-items: center; gap: .6rem; border-radius: 6px; user-select: none; }
    details.group > summary::-webkit-details-marker { display: none; }
    summary .caret { transition: transform .15s ease; color: #999; font-size: .8rem; width: .8rem; }
    details[open] > summary .caret { transform: rotate(90deg); }
    summary .gname { font-weight: 600; }
    summary .count { font-size: .75rem; color: #999; font-weight: normal; }
    summary .gbar { margin-left: auto; display: flex; align-items: center; gap: .4rem; }
    summary .rename { font-size: .72rem; color: #2d6cdf; background: none; border: none; cursor: pointer; padding: 0; }
    summary .rename:hover { text-decoration: underline; }
    summary:hover { background: #f7f9ff; }

    .group-body { padding: 0 .35rem .35rem; overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    td, th { text-align: left; padding: .12rem .4rem; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    th { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; color: #aaa; font-weight: 600; }

    /* Sortable captions. All four look alike — the arrow is what says which
       column the panel is sorted by. */
    th .sort {
        font: inherit; letter-spacing: inherit; text-transform: inherit;
        color: #2d6cdf; background: none; border: none; box-shadow: none;
        padding: 0; margin: 0; cursor: pointer; white-space: nowrap;
    }
    th .sort:hover { text-decoration: underline; }
    th .sort[data-dir="asc"]::after  { content: " \25B2"; font-size: .62rem; }
    th .sort[data-dir="desc"]::after { content: " \25BC"; font-size: .62rem; }

    /* Inline editable name */
    .edit-name { width: 100%; min-width: 120px; border: 1px solid transparent; background: transparent; border-radius: 4px; padding: .18rem .35rem; font-size: .92rem; }
    .edit-name:hover { border-color: #e0e0e0; }
    .edit-name:focus { border-color: #2d6cdf; background: #fff; outline: none; }

    select.status { font-weight: 600; font-size: .72rem; border-radius: 10px; padding: .1rem .4rem; border: 1px solid transparent; cursor: pointer; }
    select.status.PENDING   { background: #eef; color: #445; }
    select.status.PROGRESS  { background: #d6e4ff; color: #1d4ed8; }
    select.status.DEPENDANT { background: #fef3d6; color: #8a6d1c; }
    select.status.DONE      { background: #dff5e1; color: #256b34; }
    select.status.UNDONE    { background: #fde2e0; color: #b02a20; }
    select.status.URGENT    { background: #b02020; color: #fff; }
    select.status.SKIPPED   { background: #d9d9d9; color: #666; text-decoration: line-through; }

    /* Skipped items are shown struck through. */
    tr.item-skipped .edit-name { text-decoration: line-through; color: #999; }

    .bar { background: #eee; border-radius: 4px; height: 7px; width: 80px; overflow: hidden; display: inline-block; vertical-align: middle; }
    .bar > span { display: block; height: 100%; background: #2d6cdf; }
    .bar.sm { width: 60px; height: 6px; }
    /* Completion: the value, with its progress as a thin line underneath. The
       cell is only as wide as the field, so the line reads as belonging to it. */
    .comp-cell { display: inline-flex; flex-direction: column; align-items: stretch; gap: .2rem; width: 68px; }
    .comp-cell .bar { width: 100%; height: 3px; border-radius: 2px; }
    .comp-cell input[type=number] { padding-top: .15rem; padding-bottom: .15rem; }
    .row-actions { display: flex; align-items: center; gap: .35rem; justify-content: flex-end; }
    .move-select { font-size: .78rem; padding: .12rem .25rem; border: 1px solid #ccc; border-radius: 4px; background: #fff; color: #555; cursor: pointer; }
    /* Move and Delete share a width, so the two controls line up down the column.
       76px is what the select needs to show "Move…" next to its arrow; Delete
       would fit in less, but matching widths matter more than a few pixels. */
    .row-actions .move-select, .row-actions button.del, .row-actions button.note-btn { width: 84px; }
    button.note-btn {
        font-size: .8rem; padding: .12rem .4rem; border-radius: 4px;
        border: 1px solid #c3cbd9; background: #fff; color: #4a5468; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center; gap: .3rem;
    }
    /* A task that already has comments says so on its button */
    button.note-btn.has {
        border-color: #7d9ede; background: #e9f0fd; color: #1d4ed8; font-weight: 600;
    }
    button.note-btn.has::before {
        content: ""; width: 5px; height: 5px; border-radius: 50%; background: currentColor;
    }

    /* Comment editor */
    dialog.note-dialog {
        padding: 0; width: min(560px, 92vw); border: 1px solid #b9c2d2; border-radius: 8px;
        box-shadow: 0 12px 34px rgba(20,30,50,.32);
    }
    dialog.note-dialog::backdrop { background: rgba(20,30,50,.35); }
    .note-dialog h2 {
        font-size: .95rem; margin: 0; padding: .55rem .8rem; color: #33405a;
        border-bottom: 1px solid #dfe4ee; background: linear-gradient(180deg, #ffffff, #eef1f7);
        border-radius: 8px 8px 0 0;
    }
    .note-dialog form { margin: 0; padding: .7rem .8rem .8rem; display: flex; flex-direction: column; gap: .6rem; }
    .note-dialog textarea {
        width: 100%; font: inherit; font-size: .9rem; min-height: 8rem; resize: vertical;
        padding: .4rem .5rem; border: 1px solid #b9c2d2; border-radius: 4px;
    }
    .note-actions { display: flex; justify-content: flex-end; gap: .5rem; }
    button.del { background: #fff; color: #c0392b; border: 1px solid #e3b6b1; border-radius: 4px; padding: .12rem .5rem; font-size: .8rem; }
    button.del:hover { background: #fdecea; }
    .empty { color: #999; font-style: italic; padding: 1rem 0; }
    .muted { color: #999; font-size: .85rem; margin-top: 1rem; }
    .colw-status { width: 104px; }
    .colw-comp { width: 86px; }
    .colw-act { width: 150px; }

    /* Columns each tick box can hide */
    th.colw-act, td.colw-act { display: none; }
    body.show-controls th.colw-act, body.show-controls td.colw-act { display: table-cell; }
    th.colw-comp, td.colw-comp { display: none; }
    body.show-completion th.colw-comp, body.show-completion td.colw-comp { display: table-cell; }
    .colw-date { width: 80px; white-space: nowrap; }
    .colw-due { width: 112px; white-space: nowrap; }
    th.colw-date, td.colw-date, th.colw-due, td.colw-due { display: none; }
    body.show-dates th.colw-date, body.show-dates td.colw-date { display: table-cell; }
    body.show-due th.colw-due, body.show-due td.colw-due { display: table-cell; }
    .date { font-size: .75rem; color: #6a7280; white-space: nowrap; }
    .date-done { color: #256b34; }
    .date-due { color: #4a5468; }

    /* Due date: reads as plain text in the list, and looks like a field once
       the pointer is on it. An empty one keeps its dd/mm/yyyy out of sight
       until then, so rows without a due date stay quiet. */
    /* (kept above the shared field rule, so it is written to outrank it) */
    .colw-due input.edit-due {
        width: 100%; font-size: .75rem; color: #4a5468;
        padding: .08rem .2rem; border: 1px solid transparent; border-radius: 4px;
        background: transparent; box-shadow: none;
    }
    .colw-due input.edit-due:hover, .colw-due input.edit-due:focus {
        border-color: #b9c2d2; background: #fff;
        box-shadow: inset 0 1px 2px rgba(20,30,50,.12);
    }
    .colw-due input.edit-due.empty:not(:hover):not(:focus)::-webkit-datetime-edit { color: transparent; }
    .colw-due input.edit-due.empty:not(:hover):not(:focus)::-webkit-calendar-picker-indicator { opacity: 0; }
    .toolbar .check { display: inline-flex; align-items: center; gap: .3rem; cursor: pointer; color: #444; }
    .toolbar .check input { margin: 0; cursor: pointer; }

    /* Archive tick box on a group header, and how an archived group looks */
    .arch-form { margin: 0; }
    .arch-check {
        display: inline-flex; align-items: center; gap: .25rem; cursor: pointer;
        font-size: .72rem; font-weight: 600; letter-spacing: .02em; color: #7a8497;
        text-transform: uppercase;
    }
    .arch-check input { margin: 0; cursor: pointer; }
    details.group.archived > summary { background: #f1f2f5; }
    details.group.archived .gname { color: #7c8496; }
    details.group.archived .arch-check { color: #5b657f; }

    /* ============================================================
       3D THEME — depth via gradients, bevels and drop shadows.
       Appended last so it layers over the base styles above.
       ============================================================ */
    body { background: linear-gradient(180deg, #eef1f6, #dde3ec); background-attachment: fixed; }

    /* Raised panels */
    .add-form, details.group {
        background: linear-gradient(180deg, #ffffff, #eef1f7);
        border: 1px solid #c2cad7;
        box-shadow: 0 3px 7px rgba(28,42,66,.18), inset 0 1px 0 rgba(255,255,255,.9);
    }

    /* Sunken fields */
    input[type=text], input[type=number], input[type=date], select, #file-select, .move-select {
        border: 1px solid #b0b9c8;
        background: linear-gradient(180deg, #eef1f5, #ffffff 55%);
        box-shadow: inset 0 2px 3px rgba(20,30,50,.16);
    }
    .edit-name { box-shadow: none; background: transparent; }
    .edit-name:hover { box-shadow: inset 0 1px 2px rgba(20,30,50,.12); }
    .edit-name:focus, .list-title:focus {
        background: linear-gradient(180deg, #f2f6ff, #ffffff 60%);
        box-shadow: inset 0 2px 3px rgba(20,30,50,.18);
    }

    /* Raised buttons */
    button {
        background: linear-gradient(180deg, #ffffff, #dfe4ee);
        border: 1px solid #a7b1c1;
        border-radius: 6px;
        box-shadow: 0 2px 3px rgba(28,42,66,.22), inset 0 1px 0 rgba(255,255,255,.85);
        text-shadow: 0 1px 0 rgba(255,255,255,.6);
    }
    button:hover { background: linear-gradient(180deg, #ffffff, #d4dbe6); }
    button:active { transform: translateY(1px); box-shadow: 0 1px 1px rgba(28,42,66,.25), inset 0 2px 4px rgba(20,30,50,.28); }

    button.primary {
        background: linear-gradient(180deg, #5a90ec, #2560c8);
        border-color: #1f4fa8; color: #fff;
        text-shadow: 0 -1px 0 rgba(0,0,0,.28);
        box-shadow: 0 2px 4px rgba(24,60,130,.4), inset 0 1px 0 rgba(255,255,255,.4);
    }
    button.primary:hover { background: linear-gradient(180deg, #6a9cf0, #2a68d2); }

    button.del, .file-del {
        background: linear-gradient(180deg, #ffffff, #fbe4e1);
        border-color: #dda6a0; color: #c0392b;
        box-shadow: 0 2px 3px rgba(120,30,30,.2), inset 0 1px 0 rgba(255,255,255,.8);
        text-shadow: 0 1px 0 rgba(255,255,255,.5);
    }
    button.del:hover, .file-del:hover { background: linear-gradient(180deg, #ffffff, #f7d2cd); }

    /* Status chips — beveled pills */
    select.status {
        border: 1px solid rgba(0,0,0,.18);
        box-shadow: 0 1px 2px rgba(28,42,66,.3), inset 0 1px 0 rgba(255,255,255,.4);
    }

    /* Progress bar — sunken track, glossy fill */
    .bar {
        background: linear-gradient(180deg, #d5dae3, #e7ebf1);
        box-shadow: inset 0 1px 2px rgba(20,30,50,.35);
        height: 9px; border: none;
    }
    .bar.sm { height: 8px; }
    .comp-cell .bar { height: 3px; }
    .bar > span {
        background: linear-gradient(180deg, #6098f4, #2d6cdf);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.5);
    }

    /* Toolbar buttons — small raised pills */
    .toolbar button {
        background: linear-gradient(180deg, #ffffff, #e2e7ef);
        border: 1px solid #aab3c2; border-radius: 6px;
        color: #2d6cdf; padding: .28rem .6rem;
        box-shadow: 0 2px 3px rgba(28,42,66,.2), inset 0 1px 0 rgba(255,255,255,.85);
        text-shadow: 0 1px 0 rgba(255,255,255,.7);
    }
    .toolbar button:hover { text-decoration: none; background: linear-gradient(180deg, #ffffff, #d6dce6); }
    .toolbar button:active { transform: translateY(1px); box-shadow: inset 0 2px 4px rgba(20,30,50,.28); }
    .toolbar button.toggle {
        background: linear-gradient(180deg, #ffffff, #e2e7ef);
        box-shadow: 0 2px 3px rgba(28,42,66,.2), inset 0 1px 0 rgba(255,255,255,.85);
    }
    .toolbar button.toggle.pressed {
        background: linear-gradient(180deg, #2560c8, #4d84e6);
        border-color: #1f4fa8; color: #fff;
        box-shadow: inset 0 2px 5px rgba(0,0,0,.4);
        transform: translateY(1px);
        text-shadow: 0 -1px 0 rgba(0,0,0,.3);
    }

    /* Group header gloss */
    details.group > summary:hover { background: rgba(255,255,255,.5); }

    /* Group summary completion — longer bar, bigger, higher-contrast % */
    summary .gbar .bar.sm { width: 150px; height: 11px; }
    summary .gbar .count { font-size: 1rem; font-weight: 700; color: #1d4ed8; text-shadow: 0 1px 0 rgba(255,255,255,.75); }

    /* Add form + stats side by side, equal width */
    .top-row { display: flex; flex-wrap: wrap; align-items: stretch; gap: 1rem; margin-bottom: 1.5rem; }
    .top-row > .add-form { flex: 1 1 0; min-width: 280px; margin-bottom: 0; }
    .top-row > .stats-slot { flex: 1 1 0; min-width: 280px; display: flex; }
    .top-row > .stats-slot:empty { display: none; }
    .stats-slot > .stats { flex: 1 1 100%; margin-bottom: 0; }

    /* Overall completion stat panel */
    .stats {
        display: flex; align-items: center; flex-wrap: wrap; gap: .6rem 1.1rem;
        padding: .7rem 1rem; border-radius: 6px;
        background: linear-gradient(180deg, #ffffff, #eef1f7);
        border: 1px solid #c2cad7;
        box-shadow: 0 3px 7px rgba(28,42,66,.18), inset 0 1px 0 rgba(255,255,255,.9);
    }
    .stat-figure { display: flex; align-items: baseline; gap: .4rem; }
    .stat-pct { font-size: 1.9rem; font-weight: 700; color: #2560c8; text-shadow: 0 1px 0 rgba(255,255,255,.8); }
    .stat-label { font-size: .88rem; color: #4a5468; }
    .stat-bar { flex: 1 1 100%; height: 14px; }
    /* The counts are what gets read, so they are dark and set in figures that
       line up; the words around them stay quieter. */
    .stat-meta { font-size: .92rem; color: #4a5468; line-height: 1.5; }
    .stat-meta b { font-weight: 700; color: #1f2733; font-variant-numeric: tabular-nums; }
    .stat-meta .sep { color: #9aa4b5; margin: 0 .15rem; }
    .stat-meta .quiet { color: #78839a; }

    /* Per-status breakdown of the uncompleted tasks */
    .stat-open { flex: 1 1 100%; display: flex; flex-wrap: wrap; gap: .35rem; }
    .open-chip {
        font-size: .76rem; font-weight: 600; letter-spacing: .02em;
        border-radius: 11px; padding: .14rem .55rem;
        border: 1px solid rgba(0,0,0,.08);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.65);
    }
    .open-chip b { font-weight: 700; font-variant-numeric: tabular-nums; }
    .open-chip.PENDING   { background: #eef; color: #445; }
    .open-chip.PROGRESS  { background: #d6e4ff; color: #1a45b8; }
    .open-chip.DEPENDANT { background: #fdeec2; color: #7a5f10; }
    .open-chip.UNDONE    { background: #fde2e0; color: #a5241b; }
    .open-chip.URGENT    { background: #b02020; color: #fff; }
</style>
</head>
<body>
<div class="head-row">
    <form method="post" action="" class="title-form">
        <input type="hidden" name="action" value="rename_list">
        <input class="list-title" name="value" value="<?= e($listName) ?>"
               aria-label="List name" title="Click to rename this list"
               onchange="submitList(this.form)"
               onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}">
    </form>

    <div class="file-controls">
        <!-- Data file selector -->
        <form method="post" action="" class="file-form">
            <input type="hidden" name="action" value="select_file">
            <input type="hidden" name="file" value="">
            <label class="file-label">Data file:
                <select id="file-select" data-current="<?= e($currentFile) ?>" onchange="selectFile(this)">
                    <?php foreach ($jsonFiles as $f): ?>
                        <option value="<?= e($f) ?>" <?= $f === $currentFile ? 'selected' : '' ?>><?= e($f) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="button" class="file-new" onclick="newFile(this)">New file</button>
        </form>

        <!-- Timezone this list is kept in; stored in the data file -->
        <form method="post" action="" class="file-form">
            <input type="hidden" name="action" value="set_timezone">
            <label class="file-label">Timezone:
                <select id="tz-select" name="value" title="The timezone this list's dates are in"
                        onchange="submitList(this.form)">
                    <?= timezone_options(date_default_timezone_get()) ?>
                </select>
            </label>
        </form>

        <!-- Upload a data file from the user's computer -->
        <form method="post" action="" class="file-up-form" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload">
            <input type="file" name="datafile" id="upload-input" accept=".json,application/json"
                   style="display:none" onchange="this.form.submit()">
            <button type="button" class="file-up"
                    onclick="document.getElementById('upload-input').click()">Upload</button>
        </form>

        <!-- Download the current data file -->
        <a class="file-dl" href="?download=1&amp;file=<?= rawurlencode($currentFile) ?>"
           download="<?= e($currentFile) ?>" title="Download this data file">Download</a>

        <!-- Delete the current data file -->
        <form method="post" action="" class="file-del-form"
              onsubmit="return confirm('Delete the data file “<?= e($currentFile) ?>”?\n\nThis permanently deletes the file and all items in it.');">
            <input type="hidden" name="action" value="delete_file">
            <input type="hidden" name="file" value="<?= e($currentFile) ?>">
            <button type="submit" class="file-del">Delete file</button>
        </form>
    </div>
</div>

<div class="top-row">
    <!-- Add form -->
    <form class="add-form" method="post" action="">
        <input type="hidden" name="action" value="add">
        <label class="grow">Task
            <input type="text" name="task" required>
        </label>
        <label>Group
            <input type="text" name="group" list="group-list" placeholder="(optional)"
                   autocomplete="off" value="<?= e($addGroup) ?>">
        </label>
        <label>Status
            <select name="status"><?= status_options($addStatus) ?></select>
        </label>
        <label>Due
            <input type="date" name="due" title="(optional) when this task is due">
        </label>
        <button class="primary" type="submit">Add task</button>
        <label class="wide">Comments
            <textarea name="comment" rows="2" placeholder="(optional) notes about this task"></textarea>
        </label>
    </form>

    <!-- Overall completion stats (replaced in place after an edit) -->
    <div id="stats-slot" class="stats-slot"><?= render_stats($items, $archived) ?></div>
</div>

<!-- Autocomplete list of existing group names (used by add + move) -->
<datalist id="group-list"><?= render_group_options($items) ?></datalist>

<!-- The list itself (replaced in place after an edit) -->
<div id="list-slot"><?= render_list($items, $archived) ?></div>

<!-- Comment editor. One for the page: a row's Comment button fills it in and
     opens it, and saving posts the same update_field the inline fields do. -->
<dialog id="note-dialog" class="note-dialog">
    <h2>Comments — <span class="note-task"></span></h2>
    <form method="post" action="">
        <input type="hidden" name="action" value="update_field">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="field" value="comment">
        <textarea name="value" placeholder="Notes about this task"></textarea>
        <div class="note-actions">
            <button type="button" class="note-cancel">Cancel</button>
            <button type="button" class="primary note-save">Save</button>
        </div>
    </form>
</dialog>

<script>
// ---------------------------------------------------------------------------
// Editing is done in place. A change to the list is POSTed in the background;
// the server writes the JSON file and answers with the re-rendered list and
// stats, which are swapped into the slots below. So both the file and the page
// end up updated, without reloading the page or re-reading the file. The poll
// at the bottom still reloads — but only when someone *else* changes the file.
// ---------------------------------------------------------------------------

// Actions that only change list contents, and so can be applied in place. File
// actions (select / upload / delete file) submit normally and reload the page.
var LIST_ACTIONS = ['add', 'update_field', 'delete', 'rename_group', 'rename_list', 'set_archived', 'set_timezone'];

// Signature of the data file as the page currently shows it, and the file the
// page is bound to. Both are kept current by applyUpdate().
var pollSig  = { mtime: <?= (int) $fileSig['mtime'] ?>, size: <?= (int) $fileSig['size'] ?> };
var pollFile = <?= json_encode($currentFile, JSON_UNESCAPED_SLASHES) ?>;

// Only the newest background edit may repaint: a slower earlier response would
// otherwise overwrite the page with a stale list.
var editSeq = 0;

/** The action a form performs, from its hidden "action" field. */
function formAction(form) {
    var a = form ? form.querySelector('input[name="action"]') : null;
    return a ? a.value : '';
}

/**
 * Save a list change and repaint from the answer. Called by the inline
 * onchange handlers and by the submit hook below.
 */
function submitList(form) {
    if (!form) { return; }
    var body = new FormData(form);
    body.append('ajax', '1');          // ask for fragments instead of a redirect
    var mine  = ++editSeq;
    var focus = focusKey(document.activeElement);
    var isAdd = formAction(form) === 'add';

    fetch(window.location.href, {
        method: 'POST',
        body: body,
        cache: 'no-store',
        headers: { 'X-Requested-With': 'fetch' }
    })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) { throw new Error('unexpected response'); }
            if (mine !== editSeq) { return; }   // a later edit already repainted
            applyUpdate(d, focus);
            if (isAdd) {
                // Ready for the next item. The group and status are sticky on
                // purpose; the task and its comments belong to the item just
                // added, so they are cleared.
                var task = form.querySelector('input[name="task"]');
                var note = form.querySelector('textarea[name="comment"]');
                var due  = form.querySelector('input[name="due"]');
                if (note) { note.value = ''; }
                if (due) { due.value = ''; }
                if (task) { task.value = ''; task.focus(); }
            }
        })
        .catch(function () {
            // The write may or may not have landed; a reload shows the truth.
            window.location.reload();
        });
}

/** Swap in the new fragments and put the page back the way the user had it. */
function applyUpdate(d, focus) {
    var list  = document.getElementById('list-slot');
    var stats = document.getElementById('stats-slot');
    var dl    = document.getElementById('group-list');
    if (list)  { list.innerHTML  = d.list; }
    if (stats) { stats.innerHTML = d.stats; }
    if (dl)    { dl.innerHTML    = d.groups; }
    if (d.name) { document.title = d.name; }
    initList();
    restoreFocus(focus);
    // The file on disk is now exactly what the page shows: adopt its signature
    // so the poll doesn't mistake our own write for an outside change.
    if (d.sig) { pollSig = d.sig; }
}

/** Identify the field being edited, so focus survives the swap. */
function focusKey(el) {
    var form = el && el.form;
    if (!form) { return null; }
    var id = form.querySelector('input[name="id"]');
    var fd = form.querySelector('input[name="field"]');
    // Group moves re-sort the row; leave focus alone for those.
    if (!id || !fd || fd.value === 'group') { return null; }
    return { id: id.value, field: fd.value };
}

/** Re-focus the control focusKey() recorded, now that the row is a new one. */
function restoreFocus(key) {
    if (!key) { return; }
    var forms = document.querySelectorAll('#list-slot form.inline');
    for (var i = 0; i < forms.length; i++) {
        var id = forms[i].querySelector('input[name="id"]');
        var fd = forms[i].querySelector('input[name="field"]');
        if (id && fd && id.value === key.id && fd.value === key.field) {
            var c = forms[i].querySelector('input:not([type=hidden]), select');
            if (c) { c.focus(); }
            return;
        }
    }
}

// Forms submitted by a button (add, delete, rename group) go the same way.
// Inline onsubmit handlers — the delete confirm, the rename prompt — have
// already run and cancelled the event if the user backed out.
document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form || form.tagName !== 'FORM') { return; }
    if (LIST_ACTIONS.indexOf(formAction(form)) === -1) { return; }
    ev.preventDefault();
    submitList(form);
});

// Switch the active data file to the one chosen in the dropdown.
function selectFile(sel) {
    var form = sel.form;
    form.querySelector('input[name="file"]').value = sel.value;
    form.submit();
}

// Create (and switch to) a new data file. Prompts for a name.
function newFile(btn) {
    var name = prompt('New data file name:', 'list.json');
    if (name === null || name.trim() === '') { return; }
    var form = btn.form;
    form.querySelector('input[name="file"]').value = name.trim();
    form.submit();
}

// Move an item to another group. Handles the "Ungrouped" and
// "New group…" sentinel options, then saves the row's move form.
function moveItem(sel) {
    var v = sel.value;
    if (v === '') { return; }
    var form = sel.form;
    if (v === '__new__') {
        var name = prompt('Move to new group:');
        if (name === null) { sel.selectedIndex = 0; return; }
        v = name.trim();
    } else if (v === '__ungroup__') {
        v = '';
    }
    form.querySelector('input[name="value"]').value = v;
    submitList(form);
}

// Browsers restore the previous values of form controls across a reload, which
// would show a stale status/task/completion (e.g. the status chip recolours but
// the dropdown text stays old). Force every row control back to its
// server-rendered value so the page always reflects the file on disk.
function resetRowControls() {
    document.querySelectorAll('.groups select, .groups input').forEach(function (el) {
        if (el.tagName === 'SELECT') {
            var idx = 0;
            for (var i = 0; i < el.options.length; i++) {
                if (el.options[i].defaultSelected) { idx = i; break; }
            }
            el.selectedIndex = idx;
        } else if (el.type !== 'hidden') {
            el.value = el.defaultValue;
        }
    });
}
window.addEventListener('pageshow', resetRowControls);

// Latching "Uncompleted only" filter: hide DONE/SKIPPED rows and any group left
// empty by the filter. State persists (per browser) across reloads and edits.
var UNDONE_KEY = 'todo-undone-only';

function undoneOnlyOn() {
    try { return localStorage.getItem(UNDONE_KEY) === '1'; } catch (e) { return false; }
}

function applyUndoneOnly(on) {
    var btn = document.getElementById('undone-only');
    document.body.classList.toggle('undone-only', on);
    if (btn) {
        btn.classList.toggle('pressed', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    }
    // Hide groups that have no remaining (non-DONE/SKIPPED) items.
    document.querySelectorAll('details.group').forEach(function (d) {
        if (!on) { d.style.display = ''; return; }
        var visible = 0;
        d.querySelectorAll('tbody tr').forEach(function (r) {
            var s = r.getAttribute('data-status');
            if (s !== 'DONE' && s !== 'SKIPPED') visible++;
        });
        d.style.display = visible ? '' : 'none';
    });
}

function initFilter() {
    var btn = document.getElementById('undone-only');
    applyUndoneOnly(undoneOnlyOn());
    if (!btn) { return; }
    btn.addEventListener('click', function () {
        var on = !document.body.classList.contains('undone-only');
        try { localStorage.setItem(UNDONE_KEY, on ? '1' : '0'); } catch (e) {}
        applyUndoneOnly(on);
    });
}

// "Show dates" tick box: reveals the date column (added while a task is still
// outstanding, completed once it is DONE). Ticked unless the user says
// otherwise, and the choice persists per browser.
// Each of these is a tick box that shows or hides a column, by putting a class
// on <body> for the CSS to match. Ticked unless the user says otherwise, and
// the choice is remembered per browser.
var COLUMN_TOGGLES = [
    { id: 'show-completion', key: 'todo-show-completion', cls: 'show-completion' },
    { id: 'show-dates',      key: 'todo-show-dates',      cls: 'show-dates' },
    { id: 'show-due',        key: 'todo-show-due',        cls: 'show-due' },
    { id: 'show-controls',   key: 'todo-show-controls',   cls: 'show-controls' }
];

function toggleIsOn(t) {
    try {
        var v = localStorage.getItem(t.key);
        return v === null ? true : v === '1';
    } catch (e) { return true; }
}

function applyToggle(t, on) {
    document.body.classList.toggle(t.cls, on);
    var box = document.getElementById(t.id);
    if (box) { box.checked = on; }
}

function initToggles() {
    COLUMN_TOGGLES.forEach(function (t) {
        applyToggle(t, toggleIsOn(t));
        var box = document.getElementById(t.id);
        if (!box) { return; }
        box.addEventListener('change', function () {
            try { localStorage.setItem(t.key, box.checked ? '1' : '0'); } catch (e) {}
            applyToggle(t, box.checked);
        });
    });
}

// Remember which groups are collapsed, per browser.
var COLLAPSE_KEY = 'todo-collapsed-groups';

function initCollapse() {
    var state;
    try { state = JSON.parse(localStorage.getItem(COLLAPSE_KEY)) || {}; } catch (e) { state = {}; }
    function save() {
        try { localStorage.setItem(COLLAPSE_KEY, JSON.stringify(state)); } catch (e) {}
    }
    var groups = Array.prototype.slice.call(document.querySelectorAll('details.group'));

    groups.forEach(function (d) {
        var name = d.getAttribute('data-group');
        if (Object.prototype.hasOwnProperty.call(state, name)) {
            d.open = !!state[name];
        }
        d.addEventListener('toggle', function () {
            state[name] = d.open;
            save();
        });
    });

    function setAll(open) {
        groups.forEach(function (d) {
            d.open = open;
            state[d.getAttribute('data-group')] = open;
        });
        save();
    }
    var ea = document.getElementById('expand-all');
    var ca = document.getElementById('collapse-all');
    if (ea) ea.addEventListener('click', function () { setAll(true); });
    if (ca) ca.addEventListener('click', function () { setAll(false); });
}

// Timestamps are stored as an absolute instant, so show them in whatever
// timezone the reader is in. Stamps written before the offset was recorded
// (no trailing Z or +hh:mm) are left exactly as the server rendered them,
// since their timezone isn't known.
function localizeDates() {
    document.querySelectorAll('.date[data-stamp]').forEach(function (el) {
        var raw = el.getAttribute('data-stamp');
        if (!/(Z|[+-]\d\d:?\d\d)$/.test(raw)) { return; }
        var t = new Date(raw);
        if (isNaN(t.getTime())) { return; }
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        var day = t.getFullYear() + '-' + p(t.getMonth() + 1) + '-' + p(t.getDate());
        el.textContent = day;
        el.title = el.getAttribute('data-label') + ' ' + day + ' ' + p(t.getHours()) + ':' + p(t.getMinutes());
    });
}

// Clicking a column caption sorts that panel. A third click comes back to the
// default, Status, which is the order the server sends: status priority, then
// the order items were added. The choice is per group and per browser, and is
// re-applied after every edit, since the server always sends its own order.
var SORT_KEY = 'todo-sort';
var DEFAULT_SORT = { key: 'status', dir: 'asc' };
var STATUS_ORDER = <?= json_encode(STATUS_ORDER) ?>;

function loadSorts() {
    try { return JSON.parse(localStorage.getItem(SORT_KEY)) || {}; } catch (e) { return {}; }
}

function saveSorts(all) {
    try { localStorage.setItem(SORT_KEY, JSON.stringify(all)); } catch (e) {}
}

// What a row is worth for a given column. '' means "nothing to sort on", and
// those rows are parked at the end whichever way the column is sorted.
function rowValue(tr, key) {
    if (key === 'task') {
        var n = tr.querySelector('.edit-name');
        return n ? n.value.trim().toLowerCase() : '';
    }
    if (key === 'status') {
        var i = STATUS_ORDER.indexOf(tr.getAttribute('data-status'));
        return i < 0 ? STATUS_ORDER.length : i;      // unknown statuses last
    }
    if (key === 'completion') {
        var c = tr.querySelector('input[type=number]');
        return c ? (parseInt(c.value, 10) || 0) : 0;
    }
    if (key === 'date') {
        var d = tr.querySelector('.date[data-stamp]');
        var t = d ? Date.parse(d.getAttribute('data-stamp')) : NaN;
        return isNaN(t) ? '' : t;                    // undated rows last
    }
    if (key === 'due') {
        var cell = tr.querySelector('td.colw-due');
        return cell ? (cell.getAttribute('data-due') || '') : '';   // YYYY-MM-DD sorts as text
    }
    return '';
}

function applySort(group, key, dir) {
    var tbody = group.querySelector('tbody');
    if (tbody) {
        var rows = Array.prototype.slice.call(tbody.children);
        // Note the order the rows arrived in, to break ties the way the server
        // does: by when the items were added.
        rows.forEach(function (r, i) { if (!r.dataset.ord) { r.dataset.ord = i + 1; } });
        var back = function (a, b) { return a.dataset.ord - b.dataset.ord; };
        rows.sort(function (a, b) {
            var x = rowValue(a, key), y = rowValue(b, key);
            if (x === '' || y === '') { return x === y ? back(a, b) : (x === '' ? 1 : -1); }
            if (x < y) { return dir === 'desc' ? 1 : -1; }
            if (x > y) { return dir === 'desc' ? -1 : 1; }
            return back(a, b);
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
    }
    group.querySelectorAll('th .sort').forEach(function (btn) {
        btn.setAttribute('data-dir', btn.getAttribute('data-sort') === key ? dir : '');
    });
}

function initSort() {
    var all = loadSorts();
    document.querySelectorAll('#list-slot details.group').forEach(function (group) {
        var name = group.getAttribute('data-group');
        var cur = all[name] || DEFAULT_SORT;
        applySort(group, cur.key, cur.dir);

        group.querySelectorAll('th .sort').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var key = btn.getAttribute('data-sort');
                var sorts = loadSorts();
                var was = sorts[name] || DEFAULT_SORT;
                var next = was.key !== key ? { key: key, dir: 'asc' }
                         : was.dir === 'asc' ? { key: key, dir: 'desc' }
                         : DEFAULT_SORT;             // third click: back to default
                // Nothing is stored for the default, so a panel left alone
                // follows it even if the default ever changes.
                if (next === DEFAULT_SORT) { delete sorts[name]; } else { sorts[name] = next; }
                saveSorts(sorts);
                applySort(group, next.key, next.dir);
            });
        });
    });
}

// The comment editor. The dialog lives outside the list, so its own buttons
// are wired once; the row buttons are wired again for each list the server
// sends back.
var noteDialog = document.getElementById('note-dialog');

function initNoteButtons() {
    if (!noteDialog) { return; }
    document.querySelectorAll('#list-slot .note-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            noteDialog.querySelector('.note-task').textContent = btn.getAttribute('data-task');
            noteDialog.querySelector('input[name="id"]').value = btn.getAttribute('data-id');
            var box = noteDialog.querySelector('textarea');
            box.value = btn.getAttribute('data-comment') || '';
            noteDialog.showModal();
            box.focus();
        });
    });
}

if (noteDialog) {
    noteDialog.querySelector('.note-save').addEventListener('click', function () {
        submitList(noteDialog.querySelector('form'));   // saves, then repaints the row
        noteDialog.close();
    });
    noteDialog.querySelector('.note-cancel').addEventListener('click', function () {
        noteDialog.close();                             // Escape does the same
    });
}

// Run for the page as loaded, and again for every list the server sends back
// (the swap discards the elements these handlers were attached to).
function initList() {
    resetRowControls();
    initCollapse();
    initFilter();
    initToggles();
    initNoteButtons();
    localizeDates();
    initSort();
}
initList();

// Auto-refresh: poll the data file's change signature and reload if it changed
// on disk behind our back (another tab, another process). Our own edits keep
// pollSig current, so they never trigger this. Skips reloading while you're
// editing a field so it never interrupts typing.
(function () {
    var url = '?poll=1&file=' + encodeURIComponent(pollFile);

    function editing() {
        var a = document.activeElement;
        return a && /^(INPUT|SELECT|TEXTAREA)$/.test(a.tagName);
    }

    function check() {
        if (document.hidden) return;   // don't poll a background tab
        fetch(url, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d) return;
                if (d.mtime !== pollSig.mtime || d.size !== pollSig.size) {
                    if (editing()) return;   // try again next tick, don't clobber edits
                    window.location.reload();
                }
            })
            .catch(function () { /* ignore transient errors */ });
    }

    setInterval(check, 4000);
})();
</script>
</body>
</html>
