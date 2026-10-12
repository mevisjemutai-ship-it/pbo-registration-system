<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

requireAdmin();
requirePasswordChanged();

$connection = $pdo ?? $db ?? null;

if (!$connection instanceof PDO) {
    http_response_code(500);
    exit('Database connection was not initialized.');
}

/*
| Works even if sql/audit-trail.sql has not been run yet: the extra
| columns are only read when they exist.
*/
$auditColumns = $connection->query('SHOW COLUMNS FROM application_audit_log')
    ->fetchAll(PDO::FETCH_COLUMN);

$changesSelect = in_array('changes', $auditColumns, true) ? 'l.changes' : 'NULL';
$ipSelect = in_array('ip_address', $auditColumns, true) ? 'l.ip_address' : 'NULL';
$descriptionSelect = in_array('description', $auditColumns, true) ? 'l.description' : 'NULL';

$pageTitle = 'Audit Trail';
$activePage = 'audit';


function auditEscape($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * One readable line, e.g.
 *   ADDED    Objective: Health
 *   REMOVED  Beneficiary: Children
 *   CHANGED  Organization name: Old -> New
 */
function auditChangeLine(array $change): string
{
    $type = in_array($change['type'], ['added', 'removed', 'changed'], true) ? $change['type'] : 'changed';
    $field = auditEscape($change['field']);
    $before = auditEscape($change['before']);
    $after = auditEscape($change['after']);

    if (pboAuditIsSignature((string) $change['field'])) {
        $text = '<b>' . $field . '</b>' . ($type === 'changed' ? ' replaced with a new upload' : '');
    } elseif ($type === 'added' || $type === 'removed') {
        $value = $type === 'added' ? (string) $change['after'] : (string) $change['before'];
        $class = $type === 'added' ? 'audit-new' : 'audit-old';
        $details = '';

        /* "John Kamau (Phone: 07.., Email: ..)" -> name stands out, details quieter */
        if (in_array($change['field'], ['Director', 'Other area', 'Beneficiary'], true)
            && preg_match('/^(.+?) \((.+)\)$/s', $value, $match)) {
            $value = $match[1];
            $details = '<span class="audit-details">' . auditEscape($match[2]) . '</span>';
        }

        $text = '<b>' . $field . ':</b> <span class="' . $class . '">' . auditEscape($value) . '</span>' . $details;
    } else {
        $text = '<b>' . $field . ':</b> <span class="audit-old">' . $before . '</span>'
              . '<span class="audit-arrow" aria-label="changed to">&rarr;</span>'
              . '<span class="audit-new">' . $after . '</span>';
    }

    return '<li class="audit-change ' . $type . '">'
         . '<span class="audit-verb">' . ucfirst($type) . '</span>'
         . '<span class="audit-change-text">' . $text . '</span>'
         . '</li>';
}


/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = trim((string) ($_GET['q'] ?? ''));
$selectedUserId = max(0, (int) ($_GET['user'] ?? 0));

$dateFrom = (string) ($_GET['from'] ?? '');
$dateTo = (string) ($_GET['to'] ?? '');

$validDate = static fn (string $d): bool =>
    (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;

if (!$validDate($dateFrom)) {
    $dateFrom = '';
}

if (!$validDate($dateTo)) {
    $dateTo = '';
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

/* Only administrator edits of submitted records are shown. */
$editAction = defined('PBO_AUDIT_EDIT_ACTION') ? PBO_AUDIT_EDIT_ACTION : 'submitted_record_edited';

$where = ['LOWER(l.action) = :edit_action'];
$params = ['edit_action' => $editAction];

if ($search !== '') {
    $where[] = '(a.pbo_name LIKE :search_name
                 OR a.application_reference LIKE :search_reference
                 OR a.pbo_registration_number LIKE :search_number)';
    $params['search_name'] = '%' . $search . '%';
    $params['search_reference'] = '%' . $search . '%';
    $params['search_number'] = '%' . $search . '%';
}

if ($selectedUserId > 0) {
    $where[] = 'l.user_id = :user_id';
    $params['user_id'] = $selectedUserId;
}

if ($dateFrom !== '') {
    $where[] = 'l.created_at >= :date_from';
    $params['date_from'] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $where[] = 'l.created_at <= :date_to';
    $params['date_to'] = $dateTo . ' 23:59:59';
}

$whereSql = implode(' AND ', $where);


/*
|--------------------------------------------------------------------------
| People who appear in the log (for the filter)
|--------------------------------------------------------------------------
*/

$people = $connection->query(
    'SELECT DISTINCT u.id, u.full_name, u.role
     FROM application_audit_log l
     JOIN users u ON u.id = l.user_id
     WHERE LOWER(l.action) = ' . $connection->quote($editAction) . '
     ORDER BY u.full_name'
)->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Counts
|--------------------------------------------------------------------------
*/

$editOnly = 'LOWER(action) = ' . $connection->quote($editAction);

$totalEntries = (int) $connection->query(
    "SELECT COUNT(*) FROM application_audit_log WHERE $editOnly"
)->fetchColumn();

$entriesThisMonth = (int) $connection->query(
    "SELECT COUNT(*) FROM application_audit_log
     WHERE $editOnly AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
)->fetchColumn();

$recordsTouched = (int) $connection->query(
    "SELECT COUNT(DISTINCT application_id) FROM application_audit_log WHERE $editOnly"
)->fetchColumn();

$countStatement = $connection->prepare(
    "SELECT COUNT(*)
     FROM application_audit_log l
     LEFT JOIN pbo_applications a ON a.id = l.application_id
     WHERE $whereSql"
);
$countStatement->execute($params);
$filteredTotal = (int) $countStatement->fetchColumn();

$totalPages = max(1, (int) ceil($filteredTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;


/*
|--------------------------------------------------------------------------
| Entries
|--------------------------------------------------------------------------
*/

$statement = $connection->prepare(
    "SELECT
        l.id, l.application_id, l.action,
        $descriptionSelect AS description,
        $changesSelect AS changes,
        $ipSelect AS ip_address,
        l.created_at,
        u.full_name AS user_name, u.username, u.role AS user_role,
        a.pbo_name, a.application_reference
     FROM application_audit_log l
     LEFT JOIN users u ON u.id = l.user_id
     LEFT JOIN pbo_applications a ON a.id = l.application_id
     WHERE $whereSql
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT :limit_value OFFSET :offset_value"
);

foreach ($params as $name => $value) {
    $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}

$statement->bindValue(':limit_value', $perPage, PDO::PARAM_INT);
$statement->bindValue(':offset_value', $offset, PDO::PARAM_INT);
$statement->execute();

$entries = $statement->fetchAll(PDO::FETCH_ASSOC);

$hasFilters = $search !== '' || $selectedUserId > 0 || $dateFrom !== '' || $dateTo !== '';

$queryBase = array_filter([
    'q'    => $search,
    'user' => $selectedUserId ?: '',
    'from' => $dateFrom,
    'to'   => $dateTo,
], static fn ($v) => $v !== '' && $v !== 0);

$pageUrl = static function (int $number) use ($queryBase): string {
    return '?' . http_build_query($queryBase + ['page' => $number]);
};

require_once __DIR__ . '/../includes/header.php';

?>

<style>

.audit {
    --audit-navy: #0b3557;
    --audit-navy-deep: #062945;
    --audit-gold: #d69a18;
    --audit-line: #dfe5eb;
    --audit-muted: #64717d;
    --audit-ink: #15283b;

    max-width: 1320px;
    margin: 0 auto;
    color: var(--text, #26313d);
}

.audit h1, .audit h2, .audit p { margin: 0; }

/* Heading (same family as List of PBOs) */

.audit-hero {
    position: relative;
    overflow: hidden;
    margin-bottom: 18px;
    border-radius: 14px;
    background: linear-gradient(112deg, var(--audit-navy-deep), var(--audit-navy) 58%, #176b9f);
    box-shadow: 0 9px 28px rgba(8, 45, 75, .12);
    color: #fff;
}

.audit-hero::after {
    content: "";
    position: absolute;
    top: -105px;
    right: -55px;
    width: 235px;
    height: 235px;
    border: 38px solid rgba(255, 255, 255, .04);
    border-radius: 50%;
}

.audit-hero-copy {
    position: relative;
    z-index: 1;
    padding: 27px 29px 24px;
}

.audit-hero h1 {
    color: #ffffff;
    font-size: 28px;
    font-weight: 800;
}

.audit-hero p {
    max-width: 640px;
    margin-top: 6px;
    color: rgba(255, 255, 255, .8);
    font-size: 13px;
    line-height: 1.55;
}

.audit-figures {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    border-top: 5px solid var(--audit-gold);
    background: #fff;
    color: var(--audit-ink);
}

.audit-figure {
    padding: 16px 29px 18px;
}

.audit-figure + .audit-figure {
    border-left: 1px solid var(--audit-line);
}

.audit-figure strong {
    display: block;
    font-size: 26px;
    font-weight: 750;
    line-height: 1.1;
}

.audit-figure span {
    display: block;
    margin-top: 6px;
    font-size: 13.5px;
    font-weight: 600;
}

.audit-figure span::before {
    content: "";
    display: inline-block;
    width: 9px;
    height: 9px;
    margin-right: 8px;
    border-radius: 50%;
    background: var(--dot, var(--blue));
    vertical-align: 1px;
}

/* Filters */

.audit-panel {
    margin-bottom: 18px;
    background: #fff;
    border: 1px solid var(--audit-line);
    border-radius: 12px;
}

.audit-filters {
    display: grid;
    grid-template-columns: minmax(240px, 2fr) minmax(170px, 1fr) repeat(2, minmax(140px, .8fr)) auto;
    gap: 12px;
    align-items: end;
    padding: 18px 20px;
}

.audit-filters label {
    display: block;
    margin-bottom: 6px;
    color: var(--audit-ink);
    font-size: 13px;
    font-weight: 600;
}

.audit-control {
    width: 100%;
    height: 42px;
    padding: 0 12px;
    border: 1px solid #c9d1da;
    border-radius: 8px;
    background: #fff;
    color: var(--audit-ink);
    font-size: 14px;
}

.audit-control:focus {
    border-color: var(--blue, #123b69);
    box-shadow: 0 0 0 3px rgba(18, 59, 105, .12);
    outline: none;
}

.audit-actions {
    display: flex;
    gap: 8px;
}

.audit-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 42px;
    padding: 0 18px;
    border-radius: 8px;
    border: 1px solid var(--blue, #123b69);
    background: var(--blue, #123b69);
    color: #fff;
    font-size: 14px;
    font-weight: 650;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
}

.audit-button.quiet {
    border-color: #c9d1da;
    background: #fff;
    color: var(--audit-ink);
}

/* Entries */

.audit-list-head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 12px;
    padding: 18px 22px 12px;
    border-bottom: 1px solid var(--audit-line);
}

.audit-list-head h2 {
    color: var(--audit-ink);
    font-size: 17px;
    font-weight: 700;
}

.audit-list-head span {
    color: var(--audit-muted);
    font-size: 13px;
}

.audit-entry {
    display: grid;
    grid-template-columns: 150px minmax(0, 1fr);
    gap: 18px;
    padding: 16px 22px;
    border-bottom: 1px solid #eef1f4;
}

.audit-entry:last-child {
    border-bottom: 0;
}

.audit-when strong {
    display: block;
    color: var(--audit-ink);
    font-size: 13.5px;
}

.audit-when span {
    color: var(--audit-muted);
    font-size: 12.5px;
}

.audit-what {
    min-width: 0;
}

.audit-line {
    color: var(--audit-ink);
    font-size: 14px;
    line-height: 1.5;
}

.audit-line a {
    color: var(--blue, #123b69);
    font-weight: 650;
}

.audit-tag {
    display: inline-block;
    margin-right: 6px;
    padding: 2px 9px;
    border-radius: 999px;
    background: var(--gold-light, #fff8e5);
    color: var(--gold-dark, #9d741d);
    font-size: 12px;
    font-weight: 650;
}

.audit-meta {
    margin-top: 3px;
    color: var(--audit-muted);
    font-size: 12.5px;
}

.audit-change-list {
    display: grid;
    gap: 6px;
    margin: 12px 0 0;
    padding: 0;
    list-style: none;
}

.audit-change {
    display: grid;
    grid-template-columns: 84px minmax(0, 1fr);
    gap: 12px;
    align-items: baseline;
    padding: 9px 12px;
    border: 1px solid #edf0f3;
    border-radius: 8px;
    background: #fbfcfd;
    font-size: 13.5px;
    line-height: 1.5;
}

.audit-verb {
    display: inline-block;
    padding: 1px 0;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 750;
    letter-spacing: .03em;
    text-align: center;
    text-transform: uppercase;
}

.audit-change.added .audit-verb   { background: #e3f4ea; color: #1b603b; }
.audit-change.removed .audit-verb { background: #fbe5e7; color: #8f1720; }
.audit-change.changed .audit-verb { background: #fff3d6; color: #8a6413; }

.audit-change-text {
    min-width: 0;
    color: var(--audit-ink);
    overflow-wrap: anywhere;
}

.audit-change-text b {
    font-weight: 700;
}

.audit-old {
    color: #8f1720;
    text-decoration: line-through;
    text-decoration-color: rgba(143, 23, 32, .45);
}

.audit-new {
    color: #1b603b;
    font-weight: 600;
}

.audit-details {
    display: block;
    margin-top: 2px;
    color: var(--audit-muted);
    font-size: 12.5px;
}

.audit-arrow {
    margin: 0 6px;
    color: var(--audit-muted);
}

.audit-more summary {
    margin-top: 8px;
    color: var(--blue, #123b69);
    font-size: 13px;
    font-weight: 650;
    cursor: pointer;
}

.audit-more .audit-change-list {
    margin-top: 6px;
}

.audit-count {
    display: inline-block;
    margin-left: 6px;
    color: var(--audit-muted);
    font-size: 12.5px;
    font-weight: 500;
}

.audit-note {
    margin-top: 8px;
    color: var(--audit-muted);
    font-size: 12.5px;
}

.audit-nothing {
    margin: 18px 22px 22px;
    padding: 30px;
    border: 1px dashed #cfd6de;
    border-radius: 10px;
    color: var(--audit-muted);
    text-align: center;
}

.audit-pages {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 14px 22px;
    border-top: 1px solid var(--audit-line);
    color: var(--audit-muted);
    font-size: 13px;
}

.audit-pages nav {
    display: flex;
    gap: 6px;
}

@media (max-width: 1000px) {
    .audit-filters {
        grid-template-columns: 1fr 1fr;
    }
    .audit-filters .audit-search,
    .audit-actions {
        grid-column: 1 / -1;
    }
}

@media (max-width: 680px) {
    .audit-figures,
    .audit-filters {
        grid-template-columns: 1fr;
    }
    .audit-figure + .audit-figure {
        border-left: 0;
        border-top: 1px solid var(--audit-line);
    }
    .audit-entry {
        grid-template-columns: 1fr;
        gap: 6px;
    }
    .audit-hero-copy,
    .audit-figure {
        padding-left: 20px;
        padding-right: 20px;
    }
    .audit-change {
        grid-template-columns: 1fr;
        gap: 4px;
    }
    .audit-verb {
        justify-self: start;
        padding: 1px 10px;
    }
}

</style>


<div class="audit">

    <section class="audit-hero">

        <div class="audit-hero-copy">
            <h1>Audit trail</h1>
            <p>
                Every time an administrator edits a submitted record: who
                did it, when, and exactly what was added, removed or changed.
                Entries cannot be edited or deleted. Visible to administrators only.
            </p>
        </div>

        <div class="audit-figures">

            <div class="audit-figure" style="--dot: var(--blue);">
                <strong><?= number_format($totalEntries) ?></strong>
                <span>Edits recorded</span>
            </div>

            <div class="audit-figure" style="--dot: var(--gold);">
                <strong><?= number_format($entriesThisMonth) ?></strong>
                <span>This month</span>
            </div>

            <div class="audit-figure" style="--dot: var(--red);">
                <strong><?= number_format($recordsTouched) ?></strong>
                <span>Records edited</span>
            </div>

        </div>

    </section>


    <section class="audit-panel">

        <form method="get" class="audit-filters">

            <div class="audit-search">
                <label for="audit-q">Record</label>
                <input
                    type="search"
                    id="audit-q"
                    name="q"
                    class="audit-control"
                    value="<?= auditEscape($search) ?>"
                    placeholder="Organization, reference or PBO number"
                >
            </div>

            <div>
                <label for="audit-user">Edited by</label>
                <select id="audit-user" name="user" class="audit-control">
                    <option value="">Anyone</option>
                    <?php foreach ($people as $person): ?>
                        <option
                            value="<?= (int) $person['id'] ?>"
                            <?= $selectedUserId === (int) $person['id'] ? 'selected' : '' ?>
                        >
                            <?= auditEscape($person['full_name']) ?>
                            (<?= $person['role'] === 'admin' ? 'Administrator' : 'Staff' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="audit-from">From</label>
                <input type="date" id="audit-from" name="from" class="audit-control" value="<?= auditEscape($dateFrom) ?>">
            </div>

            <div>
                <label for="audit-to">To</label>
                <input type="date" id="audit-to" name="to" class="audit-control" value="<?= auditEscape($dateTo) ?>">
            </div>

            <div class="audit-actions">
                <button type="submit" class="audit-button">Search</button>
                <?php if ($hasFilters): ?>
                    <a href="audit-trail.php" class="audit-button quiet">Reset</a>
                <?php endif; ?>
            </div>

        </form>

    </section>


    <section class="audit-panel">

        <div class="audit-list-head">
            <h2>Edits, newest first</h2>
            <span>
                <strong><?= number_format($filteredTotal) ?></strong>
                <?= $filteredTotal === 1 ? 'entry' : 'entries' ?>
            </span>
        </div>

        <?php if ($entries === []): ?>

            <div class="audit-nothing">
                <?= $hasFilters
                    ? 'No edits match these filters. Widen the dates or clear the search.'
                    : 'No edits yet. When an administrator edits a submitted record, it will appear here.' ?>
            </div>

        <?php endif; ?>

        <?php foreach ($entries as $entry): ?>

            <?php
            $changes = pboAuditDecodeChanges($entry['changes'] ?? null);
            $visibleChanges = array_slice($changes, 0, 8);
            $moreChanges = array_slice($changes, 8);

            $when = strtotime((string) $entry['created_at']) ?: time();
            $userName = trim((string) ($entry['user_name'] ?? '')) ?: 'Unknown user';
            $recordName = trim((string) ($entry['pbo_name'] ?? '')) ?: 'Deleted record';
            ?>

            <article class="audit-entry">

                <div class="audit-when">
                    <strong><?= date('d M Y', $when) ?></strong>
                    <span><?= date('h:i A', $when) ?></span>
                </div>

                <div class="audit-what">

                    <div class="audit-line">
                        <strong><?= auditEscape($userName) ?></strong>
                        edited
                        <?php if (!empty($entry['application_id']) && !empty($entry['pbo_name'])): ?>
                            <a href="/pbo-portal/applications/view.php?id=<?= (int) $entry['application_id'] ?>">
                                <?= auditEscape($recordName) ?>
                            </a>
                        <?php else: ?>
                            <?= auditEscape($recordName) ?>
                        <?php endif; ?>
                        <?php if ($changes !== []): ?>
                            <span class="audit-count">
                                &middot; <?= count($changes) ?> <?= count($changes) === 1 ? 'change' : 'changes' ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="audit-meta">
                        <?= auditEscape($entry['application_reference'] ?? '') ?>
                        <?php if (!empty($entry['username'])): ?>
                            &nbsp;&bull;&nbsp; @<?= auditEscape($entry['username']) ?>
                        <?php endif; ?>
                        <?php if (!empty($entry['ip_address'])): ?>
                            &nbsp;&bull;&nbsp; from <?= auditEscape($entry['ip_address']) ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($changes !== []): ?>

                        <ul class="audit-change-list">
                            <?php foreach ($visibleChanges as $change): ?>
                                <?= auditChangeLine($change) ?>
                            <?php endforeach; ?>
                        </ul>

                        <?php if ($moreChanges !== []): ?>
                            <details class="audit-more">
                                <summary>Show <?= count($moreChanges) ?> more</summary>
                                <ul class="audit-change-list">
                                    <?php foreach ($moreChanges as $change): ?>
                                        <?= auditChangeLine($change) ?>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>

                    <?php elseif ($entry['changes'] === '[]'): ?>

                        <p class="audit-note">Saved without changing anything.</p>

                    <?php else: ?>

                        <p class="audit-note">
                            The details of this edit were not recorded (it was
                            saved before change tracking was added).
                        </p>

                    <?php endif; ?>

                </div>

            </article>

        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>

            <div class="audit-pages">
                <span>Page <?= $page ?> of <?= $totalPages ?></span>
                <nav aria-label="Pagination">
                    <?php if ($page > 1): ?>
                        <a class="audit-button quiet" href="<?= auditEscape($pageUrl($page - 1)) ?>">Newer</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a class="audit-button quiet" href="<?= auditEscape($pageUrl($page + 1)) ?>">Older</a>
                    <?php endif; ?>
                </nav>
            </div>

        <?php endif; ?>

    </section>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
