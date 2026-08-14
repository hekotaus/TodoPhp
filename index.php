<?php
declare(strict_types=1);

/**
 * Simple PHP Todo List
 * ---------------------
 * Storage: a flat JSON file (data.json) sitting next to this script.
 * Each item has: id, name, status, completion (0-100), group.
 *
 * The list is shown as a collapsible tree: each group is a section you can
 * fold/unfold, and item fields (task, status, completion, group) are edited
 * inline. Collapse state is remembered per browser via localStorage.
 *
 * Run with PHP's built-in server:
 *     php -S localhost:8000
 * then open http://localhost:8000 in your browser.
 */

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

const STATUSES = ['PENDING', 'PROGRESS', 'DEPENDING', 'DONE', 'UNDONE', 'URGENT', 'SKIPPED'];

/** Display order of statuses within a group (lower = shown first). */
const STATUS_ORDER = ['PROGRESS', 'URGENT', 'UNDONE', 'PENDING', 'DEPENDING', 'DONE', 'SKIPPED'];

/** Label used for items that have no group assigned. */
const UNGROUPED = 'Ungrouped';

/** Fields that may be updated inline. */
const EDITABLE_FIELDS = ['task', 'status', 'completion', 'group'];

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
    }
    unset($item);

    return ['name' => $name, 'items' => array_values($items)];
}

/**
 * Persist the list name and items to the JSON file with an exclusive lock so
 * concurrent requests don't clobber each other.
 */
function save_data(string $name, array $items): void
{
    $name = trim($name);
    if ($name === '') {
        $name = DEFAULT_LIST_NAME;
    }
    $payload = ['name' => $name, 'items' => array_values($items)];
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
        ];
    }
    return ['name' => $name, 'items' => $clean];
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

/** Trim a group name (empty means ungrouped). */
function clean_group($value): string
{
    return trim((string) $value);
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
function group_items(array $items): array
{
    $buckets = [];
    foreach ($items as $it) {
        $g = trim((string) ($it['group'] ?? ''));
        $key = $g === '' ? UNGROUPED : $g;
        $buckets[$key][] = $it;
    }
    uksort($buckets, static function ($a, $b) {
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
    $activeFile = basename(DATA_FILE);   // carried into the redirect URL

    if ($action === 'add') {
        $task   = trim((string) ($_POST['task'] ?? ''));
        $status = clean_status($_POST['status'] ?? null);
        $group  = clean_group($_POST['group'] ?? '');
        // Remember the last-used group and status so the add form keeps them
        // for the next item (handy when adding several to the same group).
        setcookie('add_group', $group, ['path' => '/', 'samesite' => 'Lax']);
        setcookie('add_status', $status, ['path' => '/', 'samesite' => 'Lax']);
        if ($task !== '') {
            // New tasks start at 0% — unless added as DONE, which means 100%.
            $completion = $status === 'DONE' ? 100 : 0;
            $items[] = [
                'id'         => new_id(),
                'task'       => $task,
                'status'     => $status,
                'completion' => $completion,
                'group'      => $group,
            ];
            save_data($listName, $items);
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
                    } elseif ($field === 'group') {
                        $item['group'] = clean_group($value);
                    }
                    break;
                }
            }
            unset($item);
            save_data($listName, $items);
        }
    } elseif ($action === 'delete') {
        $id = (string) ($_POST['id'] ?? '');
        $items = array_filter($items, static fn($it) => $it['id'] !== $id);
        save_data($listName, $items);
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
            save_data($listName, $items);
        }
    } elseif ($action === 'rename_list') {
        // Rename the whole todo list.
        $listName = trim((string) ($_POST['value'] ?? ''));
        save_data($listName, $items);
    } elseif ($action === 'select_file') {
        // Switch which data file the app uses; create it if new.
        $fname = safe_data_filename($_POST['file'] ?? '');
        if ($fname !== null) {
            $path = DATA_DIR . DIRECTORY_SEPARATOR . $fname;
            if (!is_file($path)) {
                $base = (string) pathinfo($fname, PATHINFO_FILENAME);
                $seed = json_encode(
                    ['name' => ($base !== '' ? $base : DEFAULT_LIST_NAME), 'items' => []],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                file_put_contents($path, $seed, LOCK_EX);
            }
            setcookie('todo_file', $fname, ['path' => '/', 'samesite' => 'Lax']);
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
                setcookie('todo_file', $fname, ['path' => '/', 'samesite' => 'Lax']);
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
                setcookie('todo_file', $remaining[0], ['path' => '/', 'samesite' => 'Lax']);
                $activeFile = $remaining[0];
            } else {
                // Nothing left: clear the selection so it falls back to todo.json.
                setcookie('todo_file', '', ['path' => '/', 'expires' => 1]);
                $activeFile = 'todo.json';
            }
        }
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
$groups   = existing_groups($items);
$buckets  = group_items($items);

// Sticky defaults for the add form (kept from the last added item).
$addGroup  = clean_group($_COOKIE['add_group'] ?? '');
$addStatus = clean_status($_COOKIE['add_status'] ?? 'PENDING');

// Overall completion stats — SKIPPED items are excluded from the calculation.
$counted  = array_values(array_filter($items, static fn($i) => ($i['status'] ?? '') !== 'SKIPPED'));
$countedN = count($counted);
$skippedN = count($items) - $countedN;
$overall  = $countedN
    ? (int) round(array_sum(array_map(static fn($i) => (int) $i['completion'], $counted)) / $countedN)
    : 0;
$doneN    = count(array_filter($counted, static fn($i) => (int) $i['completion'] === 100));

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($listName) ?></title>
<style>
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

    /* "Undone only" filter: hide completed / skipped rows. */
    body.undone-only tr[data-status="DONE"],
    body.undone-only tr[data-status="SKIPPED"] { display: none; }

    .add-form { display: flex; flex-wrap: wrap; gap: .5rem; align-items: flex-end; margin-bottom: 1.5rem; padding: 1rem; border: 1px solid #ccc; border-radius: 6px; background: #fafafa; }
    .add-form label { display: flex; flex-direction: column; font-size: .8rem; color: #555; gap: .2rem; }
    input[type=text], input[type=number], select { padding: .4rem; border: 1px solid #bbb; border-radius: 4px; font-size: .9rem; background: #fff; }
    .add-form input[type=text] { min-width: 180px; }
    .add-form input[name="task"] { min-width: 360px; }
    input[type=number] { width: 68px; }
    button { padding: .45rem .8rem; border: 1px solid #888; border-radius: 4px; background: #eee; cursor: pointer; font-size: .9rem; }
    button:hover { background: #ddd; }
    button.primary { background: #2d6cdf; color: #fff; border-color: #2d6cdf; }
    button.primary:hover { background: #245ac0; }

    form.inline { margin: 0; display: inline; }

    /* Responsive multi-column flow: adds columns as the window widens,
       and never splits a group card across two columns. */
    .groups { column-width: 840px; column-gap: 1.2rem; }

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

    .group-body { padding: 0 .35rem .35rem; }
    table { width: 100%; border-collapse: collapse; }
    td, th { text-align: left; padding: .12rem .4rem; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    th { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; color: #aaa; font-weight: 600; }

    /* Inline editable name */
    .edit-name { width: 100%; min-width: 300px; border: 1px solid transparent; background: transparent; border-radius: 4px; padding: .18rem .35rem; font-size: .92rem; }
    .edit-name:hover { border-color: #e0e0e0; }
    .edit-name:focus { border-color: #2d6cdf; background: #fff; outline: none; }

    select.status { font-weight: 600; font-size: .72rem; border-radius: 10px; padding: .1rem .4rem; border: 1px solid transparent; cursor: pointer; }
    select.status.PENDING   { background: #eef; color: #445; }
    select.status.PROGRESS  { background: #d6e4ff; color: #1d4ed8; }
    select.status.DEPENDING { background: #fef3d6; color: #8a6d1c; }
    select.status.DONE      { background: #dff5e1; color: #256b34; }
    select.status.UNDONE    { background: #fde2e0; color: #b02a20; }
    select.status.URGENT    { background: #b02020; color: #fff; }
    select.status.SKIPPED   { background: #d9d9d9; color: #666; text-decoration: line-through; }

    /* Skipped items are shown struck through. */
    tr.item-skipped .edit-name { text-decoration: line-through; color: #999; }

    .bar { background: #eee; border-radius: 4px; height: 7px; width: 80px; overflow: hidden; display: inline-block; vertical-align: middle; }
    .bar > span { display: block; height: 100%; background: #2d6cdf; }
    .bar.sm { width: 60px; height: 6px; }
    .comp-cell { display: flex; align-items: center; gap: .4rem; }
    .comp-cell input[type=number] { padding-top: .15rem; padding-bottom: .15rem; }
    .row-actions { display: flex; align-items: center; gap: .35rem; justify-content: flex-end; }
    .move-select { font-size: .78rem; padding: .12rem .25rem; border: 1px solid #ccc; border-radius: 4px; background: #fff; color: #555; max-width: 110px; cursor: pointer; }
    button.del { background: #fff; color: #c0392b; border: 1px solid #e3b6b1; border-radius: 4px; padding: .12rem .5rem; font-size: .8rem; }
    button.del:hover { background: #fdecea; }
    .empty { color: #999; font-style: italic; padding: 1rem 0; }
    .muted { color: #999; font-size: .85rem; margin-top: 1rem; }
    .colw-status { width: 104px; }
    .colw-comp { width: 150px; }
    .colw-act { width: 185px; }

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
    input[type=text], input[type=number], select, #file-select, .move-select {
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

    /* Add form + stats side by side, equal width */
    .top-row { display: flex; flex-wrap: wrap; align-items: stretch; gap: 1rem; margin-bottom: 1.5rem; }
    .top-row > .add-form { flex: 1 1 0; min-width: 280px; margin-bottom: 0; }
    .top-row > .stats { flex: 1 1 0; min-width: 280px; margin-bottom: 0; }

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
    .stat-label { font-size: .8rem; color: #667; }
    .stat-bar { flex: 1 1 100%; height: 14px; }
    .stat-meta { font-size: .82rem; color: #778; }
</style>
</head>
<body>
<div class="head-row">
    <form method="post" action="" class="title-form">
        <input type="hidden" name="action" value="rename_list">
        <input class="list-title" name="value" value="<?= e($listName) ?>"
               aria-label="List name" title="Click to rename this list"
               onchange="this.form.submit()"
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
        <label>Task
            <input type="text" name="task" required>
        </label>
        <label>Group
            <input type="text" name="group" list="group-list" placeholder="(optional)"
                   autocomplete="off" value="<?= e($addGroup) ?>">
        </label>
        <label>Status
            <select name="status"><?= status_options($addStatus) ?></select>
        </label>
        <button class="primary" type="submit">Add item</button>
    </form>

    <?php if (!empty($items)): ?>
    <!-- Overall completion stats -->
    <div class="stats">
        <div class="stat-figure">
            <span class="stat-pct"><?= $overall ?>%</span>
            <span class="stat-label">overall completion</span>
        </div>
        <div class="stat-bar bar"><span style="width: <?= $overall ?>%;"></span></div>
        <div class="stat-meta">
            <?= $countedN ?> task<?= $countedN === 1 ? '' : 's' ?>
            · <?= $doneN ?> done
            <?php if ($skippedN > 0): ?>· <?= $skippedN ?> skipped (excluded)<?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Autocomplete list of existing group names (used by add + move) -->
<datalist id="group-list">
    <?php foreach ($groups as $g): ?>
        <option value="<?= e($g) ?>"></option>
    <?php endforeach; ?>
</datalist>

<?php if (empty($items)): ?>
    <p class="empty">No items yet. Add your first one above.</p>
<?php else: ?>

<div class="toolbar">
    <button type="button" id="expand-all">Expand all</button>
    <button type="button" id="collapse-all">Collapse all</button>
    <button type="button" id="undone-only" class="toggle" aria-pressed="false"
            title="Hide DONE and SKIPPED items">Undone only</button>
</div>

<div class="groups">
<?php foreach ($buckets as $groupName => $groupItems): ?>
    <?php $gp = group_progress($groupItems); ?>
    <details class="group" data-group="<?= e($groupName) ?>" open>
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
        </summary>
        <div class="group-body">
            <table>
                <thead>
                    <tr>
                        <th>Task</th>
                        <th class="colw-status">Status</th>
                        <th class="colw-comp">Completion</th>
                        <th class="colw-act"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($groupItems as $it): ?>
                    <tr data-status="<?= e($it['status']) ?>" class="<?= $it['status'] === 'SKIPPED' ? 'item-skipped' : '' ?>">
                        <!-- Task: inline edit, submits on blur/Enter -->
                        <td>
                            <form class="inline" method="post" action="" style="display:block;">
                                <input type="hidden" name="action" value="update_field">
                                <input type="hidden" name="id" value="<?= e($it['id']) ?>">
                                <input type="hidden" name="field" value="task">
                                <input class="edit-name" name="value" value="<?= e($it['task']) ?>"
                                       onchange="this.form.submit()"
                                       onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}">
                            </form>
                        </td>
                        <!-- Status: inline select, submits on change -->
                        <td class="colw-status">
                            <form class="inline" method="post" action="">
                                <input type="hidden" name="action" value="update_field">
                                <input type="hidden" name="id" value="<?= e($it['id']) ?>">
                                <input type="hidden" name="field" value="status">
                                <select class="status <?= e($it['status']) ?>" name="value" onchange="this.form.submit()">
                                    <?= status_options($it['status']) ?>
                                </select>
                            </form>
                        </td>
                        <!-- Completion: inline number, submits on change -->
                        <td class="colw-comp">
                            <div class="comp-cell">
                                <form class="inline" method="post" action="">
                                    <input type="hidden" name="action" value="update_field">
                                    <input type="hidden" name="id" value="<?= e($it['id']) ?>">
                                    <input type="hidden" name="field" value="completion">
                                    <input type="number" name="value" min="0" max="100" step="1"
                                           value="<?= (int) $it['completion'] ?>" onchange="this.form.submit()">
                                </form>
                                <span class="bar"><span style="width: <?= (int) $it['completion'] ?>%;"></span></span>
                            </div>
                        </td>
                        <!-- Actions: move to another group + delete -->
                        <td class="colw-act">
                            <div class="row-actions">
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

<script>
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
// "New group…" sentinel options, then submits the row's move form.
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
    form.submit();
}

// After a reload, browsers restore the previous values of form controls, which
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
resetRowControls();
window.addEventListener('pageshow', resetRowControls);

// Latching "Undone only" filter: hide DONE/SKIPPED rows and any group left
// empty by the filter. State persists (per browser) across reloads.
(function () {
    var KEY = 'todo-undone-only';
    var btn = document.getElementById('undone-only');
    if (!btn) return;

    function apply(on) {
        document.body.classList.toggle('undone-only', on);
        btn.classList.toggle('pressed', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
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

    var on = false;
    try { on = localStorage.getItem(KEY) === '1'; } catch (e) {}
    apply(on);

    btn.addEventListener('click', function () {
        on = !document.body.classList.contains('undone-only');
        try { localStorage.setItem(KEY, on ? '1' : '0'); } catch (e) {}
        apply(on);
    });
})();

(function () {
    // Remember which groups are collapsed, per browser.
    var KEY = 'todo-collapsed-groups';
    function load() {
        try { return JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) { return {}; }
    }
    function save(state) {
        try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) {}
    }
    var state = load();
    var groups = Array.prototype.slice.call(document.querySelectorAll('details.group'));

    groups.forEach(function (d) {
        var name = d.getAttribute('data-group');
        if (Object.prototype.hasOwnProperty.call(state, name)) {
            d.open = !!state[name];
        }
        d.addEventListener('toggle', function () {
            state[name] = d.open;
            save(state);
        });
    });

    function setAll(open) {
        groups.forEach(function (d) {
            d.open = open;
            state[d.getAttribute('data-group')] = open;
        });
        save(state);
    }
    var ea = document.getElementById('expand-all');
    var ca = document.getElementById('collapse-all');
    if (ea) ea.addEventListener('click', function () { setAll(true); });
    if (ca) ca.addEventListener('click', function () { setAll(false); });
})();

// Auto-refresh: poll the data file's change signature and reload if it changed
// on disk (e.g. edited in another tab or by another process). Skips reloading
// while you're editing a field so it never interrupts typing.
(function () {
    var current = { mtime: <?= (int) $fileSig['mtime'] ?>, size: <?= (int) $fileSig['size'] ?> };
    var file = <?= json_encode($currentFile, JSON_UNESCAPED_SLASHES) ?>;
    var url = '?poll=1&file=' + encodeURIComponent(file);

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
                if (d.mtime !== current.mtime || d.size !== current.size) {
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
