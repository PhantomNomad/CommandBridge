<?php
declare(strict_types=1);

/**
 * ICS 209 blocks 48-52: Incident Resource Commitment Summary (shared by ICS 209 and ICS 209-WF).
 *
 * The grid is built automatically from what the Resources Unit and Check-In have recorded:
 *   - ICS 211 check-in entries: an entry with a Kind is one resource (its personnel = Total Personnel); an entry with only a
 *     person's name is an individual (counted under block 50, "additional personnel not assigned to a resource");
 *   - ICS 218 vehicle / equipment entries (one resource each);
 *   - anything released in the Resources demob tracker (or ICS 218 status "released") is left out.
 * The person preparing the 209 can edit every cell, label and agency before and after saving; a saved grid is never overwritten
 * unless "Refresh from current resources" is pressed.
 *
 * Grid shape (all 1-based): ['columns' => [1..21 => label], 'rows' => [1..17 => ['agency','add','cells' => [col => ['r','p']]]], 'coop' => text]
 */

const S209RES_COLS = 21;
const S209RES_ROWS = 17;

function s209res_empty_grid(): array
{
    $rows = [];
    for ($r = 1; $r <= S209RES_ROWS; $r++) { $rows[$r] = ['agency' => '', 'add' => '', 'cells' => []]; }
    return ['columns' => array_fill(1, S209RES_COLS, ''), 'rows' => $rows, 'coop' => ''];
}

function s209res_num($v): string
{
    $v = preg_replace('/[^0-9]/', '', (string)$v);
    return $v === '' ? '' : (string)min(999999, (int)$v);
}

/** Coerce anything (stored JSON, posted JSON) into a valid grid. */
function s209res_normalize(?array $raw): array
{
    $g = s209res_empty_grid();
    if (!$raw) { return $g; }
    for ($c = 1; $c <= S209RES_COLS; $c++) {
        $g['columns'][$c] = trim(mb_substr((string)($raw['columns'][$c] ?? $raw['columns'][$c - 1] ?? ''), 0, 60));
    }
    for ($r = 1; $r <= S209RES_ROWS; $r++) {
        $src = $raw['rows'][$r] ?? $raw['rows'][$r - 1] ?? [];
        $g['rows'][$r]['agency'] = trim(mb_substr((string)($src['agency'] ?? ''), 0, 120));
        $g['rows'][$r]['add'] = s209res_num($src['add'] ?? '');
        foreach ((array)($src['cells'] ?? []) as $c => $cell) {
            $c = (int)$c;
            if ($c < 1 || $c > S209RES_COLS || !is_array($cell)) { continue; }
            $res = s209res_num($cell['r'] ?? ''); $per = s209res_num($cell['p'] ?? '');
            if ($res !== '' || $per !== '') { $g['rows'][$r]['cells'][$c] = ['r' => $res, 'p' => $per]; }
        }
    }
    $g['coop'] = trim(mb_substr((string)($raw['coop'] ?? ''), 0, 4000));
    return $g;
}

/** @return array{row_personnel: array<int,int>, col_resources: array<int,int>, resources: int, personnel: int} */
function s209res_totals(array $g): array
{
    $rowP = []; $colR = array_fill(1, S209RES_COLS, 0); $resources = 0; $personnel = 0;
    for ($r = 1; $r <= S209RES_ROWS; $r++) {
        $p = (int)($g['rows'][$r]['add'] ?? 0);
        foreach ((array)($g['rows'][$r]['cells'] ?? []) as $c => $cell) {
            $colR[(int)$c] += (int)($cell['r'] ?? 0);
            $p += (int)($cell['p'] ?? 0);
            $resources += (int)($cell['r'] ?? 0);
        }
        $rowP[$r] = $p; $personnel += $p;
    }
    return ['row_personnel' => $rowP, 'col_resources' => $colR, 'resources' => $resources, 'personnel' => $personnel];
}

function s209res_has_data(array $g): bool
{
    foreach ($g['rows'] as $row) { if ($row['agency'] !== '' || $row['add'] !== '' || $row['cells']) { return true; } }
    return trim((string)$g['coop']) !== '';
}

function s209res_hash(array $g): string
{
    return md5(json_encode([$g['columns'], $g['rows']], JSON_UNESCAPED_UNICODE));
}

function s209res_rows(PDO $pdo, string $sql, array $params): array
{
    try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { return []; }
}

function s209res_clean(string $s): string { return trim((string)preg_replace('/\s+/', ' ', $s)); }

/**
 * Build the grid from the incident's current resources.
 * @return array grid (see top of file)
 */
function s209res_collect(PDO $pdo, int $incidentId): array
{
    // agency key => ['name' => display, 'add' => int, 'kinds' => [label => ['r' => int, 'p' => int]]]
    $agencies = [];
    $kindCount = [];
    $seenPeople = []; $seenRes = [];
    $touch = function (string $agency) use (&$agencies): string {
        $agency = s209res_clean($agency);
        if ($agency === '') { $agency = 'Agency not recorded'; }
        $key = mb_strtolower($agency);
        if (!isset($agencies[$key])) { $agencies[$key] = ['name' => $agency, 'add' => 0, 'kinds' => []]; }
        return $key;
    };
    $addResource = function (string $key, string $label, int $personnel) use (&$agencies, &$kindCount): void {
        $label = s209res_clean($label);
        if ($label === '') { $label = 'Other'; }
        $agencies[$key]['kinds'][$label]['r'] = ($agencies[$key]['kinds'][$label]['r'] ?? 0) + 1;
        $agencies[$key]['kinds'][$label]['p'] = ($agencies[$key]['kinds'][$label]['p'] ?? 0) + $personnel;
        $kindCount[$label] = ($kindCount[$label] ?? 0) + 1;
    };

    // 1. ICS 211 check-in entries (not released in the demob tracker).
    $entries = s209res_rows($pdo, "SELECT e.id, e.user_id, e.category, e.kind, e.type_st_tf, e.person_name, e.home_agency, e.total_personnel, e.resource_identifier
        FROM form_211_entries e
        INNER JOIN form_211_headers h ON h.id = e.header_id
        INNER JOIN form_records fr ON fr.id = h.form_record_id
        LEFT JOIN resource_unit_intake i ON i.ics211_entry_id = e.id AND i.incident_id = fr.incident_id
        LEFT JOIN resource_demob_tracker d ON d.resource_unit_intake_id = i.id
        WHERE fr.incident_id = ? AND fr.is_deleted = 0 AND e.deleted_at IS NULL
          AND (d.id IS NULL OR d.demob_status <> 'released')
        GROUP BY e.id
        ORDER BY e.id", [$incidentId]);
    foreach ($entries as $e) {
        $agency = (string)($e['home_agency'] ?: '');
        $kind = s209res_clean((string)$e['kind']);
        $key = $touch($agency);
        if ($kind !== '') {
            $ident = mb_strtolower(s209res_clean((string)$e['resource_identifier']));
            $dedupe = $ident !== '' ? $key . '|' . mb_strtolower($kind) . '|' . $ident : 'e' . $e['id'];
            if (isset($seenRes[$dedupe])) { continue; }
            $seenRes[$dedupe] = true;
            $addResource($key, $kind . (s209res_clean((string)$e['type_st_tf']) !== '' ? ' ' . s209res_clean((string)$e['type_st_tf']) : ''), max(0, (int)$e['total_personnel']));
        } elseif (s209res_clean((string)$e['person_name']) !== '') {
            $dedupe = !empty($e['user_id']) ? 'u' . $e['user_id'] : mb_strtolower(s209res_clean((string)$e['person_name'])) . '|' . $key;
            if (isset($seenPeople[$dedupe])) { continue; }
            $seenPeople[$dedupe] = true;
            $agencies[$key]['add']++;
        }
    }

    // 2. ICS 218 vehicles and equipment.
    $veh = s209res_rows($pdo, "SELECT e.id, e.equipment_description, e.kind, e.type, e.owner_agency, e.status
        FROM form_218_entries e
        INNER JOIN form_218_headers h ON h.id = e.header_id
        INNER JOIN form_records fr ON fr.id = h.form_record_id
        WHERE fr.incident_id = ? AND fr.is_deleted = 0 AND e.deleted_at IS NULL AND e.status <> 'released'
        ORDER BY e.id", [$incidentId]);
    foreach ($veh as $v) {
        $key = $touch((string)($v['owner_agency'] ?: ''));
        $kind = s209res_clean((string)$v['kind']);
        $label = $kind !== '' ? $kind . (s209res_clean((string)$v['type']) !== '' ? ' ' . s209res_clean((string)$v['type']) : '') : s209res_clean((string)$v['equipment_description']);
        // The same item can also be checked in on ICS 211 (an entry linked from the intake record). 218 rows are separate items.
        $addResource($key, $label, 0);
    }

    // Columns: the most common kinds first; the 21st column collects anything beyond 20 kinds.
    arsort($kindCount);
    $labels = array_keys($kindCount);
    $columns = [];
    $overflow = count($labels) > S209RES_COLS;
    foreach ($labels as $i => $label) {
        if ($overflow && $i >= S209RES_COLS - 1) { break; }
        $columns[] = $label;
    }
    if ($overflow) { $columns[] = 'Other'; }
    $colIndex = array_flip($columns);

    // Rows: the largest agencies first; agencies beyond 17 are merged into the last row and named in block 53.
    uasort($agencies, function ($a, $b) {
        $ta = $a['add']; foreach ($a['kinds'] as $k) { $ta += $k['p'] + $k['r']; }
        $tb = $b['add']; foreach ($b['kinds'] as $k) { $tb += $k['p'] + $k['r']; }
        return $tb <=> $ta ?: strcmp($a['name'], $b['name']);
    });
    $agencies = array_values($agencies);
    $g = s209res_empty_grid();
    foreach ($columns as $i => $label) { $g['columns'][$i + 1] = $label; }
    $overflowNames = [];
    foreach ($agencies as $i => $a) {
        $r = min($i + 1, S209RES_ROWS);
        if ($i + 1 > S209RES_ROWS) {
            $overflowNames[] = $a['name'];
            $g['rows'][$r]['agency'] = 'Other agencies (see block 53)';
        } else {
            $g['rows'][$r]['agency'] = $a['name'];
        }
        $g['rows'][$r]['add'] = (string)((int)$g['rows'][$r]['add'] + $a['add']);
        foreach ($a['kinds'] as $label => $k) {
            $c = isset($colIndex[$label]) ? $colIndex[$label] + 1 : S209RES_COLS;
            $cur = $g['rows'][$r]['cells'][$c] ?? ['r' => '', 'p' => ''];
            $g['rows'][$r]['cells'][$c] = ['r' => (string)((int)$cur['r'] + $k['r']), 'p' => (string)((int)$cur['p'] + $k['p'])];
        }
        if ($g['rows'][$r]['add'] === '0') { $g['rows'][$r]['add'] = ''; }
    }
    if ($overflowNames) { $g['coop'] = 'Agencies combined in the last row: ' . implode('; ', $overflowNames); }
    return $g;
}

function s209res_table_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) { return $ready; }
    try { $pdo->query('SELECT 1 FROM form_209_resources LIMIT 1'); return $ready = true; }
    catch (Throwable $e) {
        try {
            $pdo->exec(s209res_create_sql());
            return $ready = true;
        } catch (Throwable $e2) { return $ready = false; }
    }
}

function s209res_create_sql(): string
{
    return 'CREATE TABLE IF NOT EXISTS form_209_resources (
        record_id INT NOT NULL PRIMARY KEY,
        grid_json LONGTEXT NOT NULL,
        source VARCHAR(10) NOT NULL DEFAULT \'auto\',
        auto_hash CHAR(32) DEFAULT NULL,
        updated_at DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
}

/** @return array{grid: array, source: string, auto_hash: string, updated_at: string}|null */
function s209res_load(PDO $pdo, int $recordId): ?array
{
    if ($recordId <= 0 || !s209res_table_ready($pdo)) { return null; }
    $rows = s209res_rows($pdo, 'SELECT grid_json, source, auto_hash, updated_at FROM form_209_resources WHERE record_id = ?', [$recordId]);
    if (!$rows) { return null; }
    $decoded = json_decode((string)$rows[0]['grid_json'], true);
    return ['grid' => s209res_normalize(is_array($decoded) ? $decoded : null), 'source' => (string)$rows[0]['source'],
        'auto_hash' => (string)($rows[0]['auto_hash'] ?? ''), 'updated_at' => (string)($rows[0]['updated_at'] ?? '')];
}

function s209res_save(PDO $pdo, int $recordId, array $grid, string $source, string $autoHash): void
{
    if (!s209res_table_ready($pdo)) { throw new RuntimeException('The resource summary table is missing. The database is out of date: run update.sh (see docs/UPDATING.md).'); }
    $pdo->prepare('INSERT INTO form_209_resources (record_id, grid_json, source, auto_hash, updated_at) VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE grid_json = VALUES(grid_json), source = VALUES(source), auto_hash = VALUES(auto_hash), updated_at = NOW()')
        ->execute([$recordId, json_encode($grid, JSON_UNESCAPED_UNICODE), $source, $autoHash]);
}

/** Resource grid for PDF export: the saved grid, or the live resources when none was saved. */
function s209res_for_pdf(PDO $pdo, int $incidentId, int $recordId): array
{
    $stored = s209res_load($pdo, $recordId);
    return $stored ? $stored['grid'] : s209res_collect($pdo, $incidentId);
}

/** Editable (or read-only) HTML for blocks 48-53. Inputs have no name; the page script posts one JSON field instead. */
function s209res_render(array $grid, bool $editable, string $note, bool $stale): void
{
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $usedCols = 0; $usedRows = 0;
    for ($c = 1; $c <= S209RES_COLS; $c++) {
        $has = $grid['columns'][$c] !== '';
        foreach ($grid['rows'] as $row) { if (isset($row['cells'][$c])) { $has = true; } }
        if ($has) { $usedCols = $c; }
    }
    for ($r = 1; $r <= S209RES_ROWS; $r++) {
        if ($grid['rows'][$r]['agency'] !== '' || $grid['rows'][$r]['add'] !== '' || $grid['rows'][$r]['cells']) { $usedRows = $r; }
    }
    $showCols = min(S209RES_COLS, max($usedCols, 4) + ($editable ? 1 : 0));
    $showRows = min(S209RES_ROWS, max($usedRows, 3) + ($editable ? 1 : 0));
    $dis = $editable ? '' : ' disabled';
    ?>
    <div class="s209-cell" style="grid-column: span 12">
      <?php if ($note !== ''): ?><p class="small s209-src"><?= $h($note) ?></p><?php endif; ?>
      <?php if ($stale && $editable): ?><p class="small priority-high">The check-in / equipment records have changed since this table was built. Use "Refresh from current resources" to rebuild it (this replaces any edits made to the table).</p><?php endif; ?>
      <p class="small">Top number in each box = how many resources; bottom number = personnel with those resources. Block 50 is personnel not assigned to a resource. Totals calculate automatically. Every box can be edited.</p>
      <?php if ($editable): ?>
      <p class="no-print">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="s209res-refresh">Refresh from current resources</button>
        <input type="hidden" name="refresh_resources" id="s209res-refresh-flag" value="">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="s209res-addrow">Add agency row</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="s209res-addcol">Add resource column</button>
      </p>
      <?php endif; ?>
      <div style="overflow-x:auto">
      <table class="s209-table" id="s209res" style="min-width:<?= 260 + S209RES_COLS * 62 ?>px">
        <thead><tr><th style="min-width:160px">48. Agency or Organization</th>
          <?php for ($c = 1; $c <= S209RES_COLS; $c++): ?><th data-col="<?= $c ?>"<?= $c > $showCols ? ' style="display:none"' : '' ?>>
            <input type="text" class="res-label" data-col="<?= $c ?>" value="<?= $h($grid['columns'][$c]) ?>" placeholder="49. kind / type" maxlength="60" style="width:58px" title="Resource kind / type (PDF shows the first 16 characters)"<?= $dis ?>></th><?php endfor; ?>
          <th style="min-width:70px">50. Additional personnel</th><th style="min-width:70px">51. Total personnel</th></tr></thead>
        <tbody>
        <?php for ($r = 1; $r <= S209RES_ROWS; $r++): $row = $grid['rows'][$r]; ?>
          <tr data-row="<?= $r ?>"<?= $r > $showRows ? ' style="display:none"' : '' ?>>
            <td><input type="text" class="res-agency" value="<?= $h($row['agency']) ?>" maxlength="120" style="width:100%" placeholder="Agency / organization"<?= $dis ?>></td>
            <?php for ($c = 1; $c <= S209RES_COLS; $c++): $cell = $row['cells'][$c] ?? ['r' => '', 'p' => '']; ?>
              <td data-col="<?= $c ?>"<?= $c > $showCols ? ' style="display:none"' : '' ?>>
                <input type="text" inputmode="numeric" class="res-r" data-col="<?= $c ?>" value="<?= $h($cell['r']) ?>" title="Resources" style="width:46px;display:block"<?= $dis ?>>
                <input type="text" inputmode="numeric" class="res-p" data-col="<?= $c ?>" value="<?= $h($cell['p']) ?>" title="Personnel" style="width:46px;display:block;margin-top:2px;background:#f6f6f6"<?= $dis ?>>
              </td><?php endfor; ?>
            <td><input type="text" inputmode="numeric" class="res-add" value="<?= $h($row['add']) ?>" style="width:60px"<?= $dis ?>></td>
            <td><strong class="res-rowtotal">0</strong></td>
          </tr>
        <?php endfor; ?>
        </tbody>
        <tfoot><tr><th>52. Total resources</th>
          <?php for ($c = 1; $c <= S209RES_COLS; $c++): ?><th data-col="<?= $c ?>"<?= $c > $showCols ? ' style="display:none"' : '' ?>><span class="res-coltotal" data-col="<?= $c ?>">0</span></th><?php endfor; ?>
          <th><span id="res-grand-r">0</span> resources</th><th><span id="res-grand-p">0</span> personnel</th></tr></tfoot>
      </table></div>
      <label for="res_coop" style="display:block;font-weight:600;margin-top:10px">53. Additional cooperating and assisting organizations not listed above</label>
      <textarea id="res_coop" rows="3" style="width:100%;box-sizing:border-box"<?= $dis ?>><?= $h($grid['coop']) ?></textarea>
      <input type="hidden" name="res209_grid_json" id="res209_grid_json" value="">
    </div>
    <script>
    (function () {
      var table = document.getElementById('s209res'); if (!table) { return; }
      var hidden = document.getElementById('res209_grid_json'), coop = document.getElementById('res_coop');
      var COLS = <?= S209RES_COLS ?>;
      function n(v) { v = String(v || '').replace(/[^0-9]/g, ''); return v === '' ? '' : String(parseInt(v, 10)); }
      function build() {
        var g = {columns: {}, rows: {}, coop: coop.value}, totals = {rows: {}, cols: {}, r: 0, p: 0};
        table.querySelectorAll('.res-label').forEach(function (i) { g.columns[i.dataset.col] = i.value; totals.cols[i.dataset.col] = 0; });
        table.querySelectorAll('tbody tr').forEach(function (tr) {
          var r = tr.dataset.row, row = {agency: tr.querySelector('.res-agency').value, add: n(tr.querySelector('.res-add').value), cells: {}}, tp = parseInt(row.add || '0', 10);
          tr.querySelectorAll('.res-r').forEach(function (ri) {
            var c = ri.dataset.col, pi = tr.querySelector('.res-p[data-col="' + c + '"]'), rv = n(ri.value), pv = n(pi.value);
            if (rv !== '' || pv !== '') { row.cells[c] = {r: rv, p: pv}; }
            totals.cols[c] += parseInt(rv || '0', 10); totals.r += parseInt(rv || '0', 10); tp += parseInt(pv || '0', 10);
          });
          totals.rows[r] = tp; totals.p += tp; g.rows[r] = row;
          tr.querySelector('.res-rowtotal').textContent = tp;
        });
        table.querySelectorAll('.res-coltotal').forEach(function (s) { s.textContent = totals.cols[s.dataset.col] || 0; });
        document.getElementById('res-grand-r').textContent = totals.r; document.getElementById('res-grand-p').textContent = totals.p;
        hidden.value = JSON.stringify(g);
      }
      table.addEventListener('input', build); coop.addEventListener('input', build); build();
      var form = table.closest('form'); if (form) { form.addEventListener('submit', build); }
      var ar = document.getElementById('s209res-addrow'), ac = document.getElementById('s209res-addcol'), rf = document.getElementById('s209res-refresh');
      function isHidden(el) { return el.style.display === 'none'; }
      if (ar) { ar.addEventListener('click', function () {
        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr')).filter(isHidden);
        if (rows.length) { rows[0].style.display = ''; }
      }); }
      if (ac) { ac.addEventListener('click', function () {
        var heads = Array.prototype.slice.call(table.querySelectorAll('thead th[data-col]')).filter(isHidden);
        if (!heads.length) { return; }
        var col = heads[0].getAttribute('data-col');
        table.querySelectorAll('th[data-col="' + col + '"], td[data-col="' + col + '"]').forEach(function (el) { el.style.display = ''; });
      }); }
      if (rf) { rf.addEventListener('click', function () {
        if (!confirm('Rebuild blocks 48-52 from the current check-in, equipment and demobilization records? Edits made to this table will be replaced.')) { return; }
        document.getElementById('s209res-refresh-flag').value = '1'; build(); form.submit();
      }); }
    })();
    </script>
    <?php
}
