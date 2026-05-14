<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();
if (!isAdmin()) { header('Location: ' . BASE_URL . '/index.php'); exit; }
$user = currentUser();

// ══════════════════════════════════════════════════════════════════════════════
// DATOS — Vista por Sector
// Sector → Grupos → Tareas
// ══════════════════════════════════════════════════════════════════════════════
$rowsBySector = $pdo->query("
    SELECT
        bc.id    AS sector_id,
        bc.name  AS sector_name,
        bc.color AS sector_color,
        b.id     AS board_id,
        b.name   AS board_name,
        b.color  AS board_color,
        n.id     AS note_id,
        n.title  AS note_title,
        n.status AS note_status,
        n.note_order,
        au.cached_name AS assigned_name,
        au.cached_photo AS assigned_photo
    FROM board_categories bc
    LEFT JOIN boards b ON b.category_id = bc.id AND b.is_archived = 0
    LEFT JOIN notes n  ON n.board_id = b.id
    LEFT JOIN app_users au ON au.prolegal_id = n.assigned_to
    ORDER BY bc.name ASC, b.name ASC, n.note_order ASC, n.created_at ASC
")->fetchAll();

// Estructurar: sectors[sector_id][boards][board_id][notes][]
$sectors = [];
foreach ($rowsBySector as $r) {
    $sid = $r['sector_id'];
    if (!isset($sectors[$sid])) {
        $sectors[$sid] = [
            'id'     => $sid,
            'name'   => $r['sector_name'],
            'color'  => $r['sector_color'],
            'boards' => [],
        ];
    }
    if (!$r['board_id']) continue;
    $bid = $r['board_id'];
    if (!isset($sectors[$sid]['boards'][$bid])) {
        $sectors[$sid]['boards'][$bid] = [
            'id'    => $bid,
            'name'  => $r['board_name'],
            'color' => $r['board_color'],
            'notes' => [],
        ];
    }
    if ($r['note_id']) {
        $sectors[$sid]['boards'][$bid]['notes'][] = [
            'id'            => $r['note_id'],
            'title'         => $r['note_title'],
            'status'        => $r['note_status'],
            'assigned_name' => $r['assigned_name'],
            'assigned_photo'=> $r['assigned_photo'],
        ];
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// DATOS — Vista por Usuario
// Usuario → Sectores (asignados) → Grupos → Tareas
// ══════════════════════════════════════════════════════════════════════════════
$rowsByUser = $pdo->query("
    SELECT
        au.prolegal_id  AS user_id,
        au.cached_name  AS user_name,
        au.cached_photo AS user_photo,
        bc.id    AS sector_id,
        bc.name  AS sector_name,
        bc.color AS sector_color,
        b.id     AS board_id,
        b.name   AS board_name,
        b.color  AS board_color,
        n.id     AS note_id,
        n.title  AS note_title,
        n.status AS note_status,
        n.note_order
    FROM app_users au
    JOIN category_users cu ON cu.prolegal_id = au.prolegal_id
    JOIN board_categories bc ON bc.id = cu.category_id
    LEFT JOIN boards b ON b.category_id = bc.id AND b.is_archived = 0
    LEFT JOIN notes n  ON n.board_id = b.id
    WHERE au.is_active = 1
    ORDER BY au.cached_name ASC, bc.name ASC, b.name ASC, n.note_order ASC, n.created_at ASC
")->fetchAll();

// Estructurar: usersData[user_id][sectors][sector_id][boards][board_id][notes][]
$usersData = [];
foreach ($rowsByUser as $r) {
    $uid = $r['user_id'];
    if (!isset($usersData[$uid])) {
        $usersData[$uid] = [
            'id'      => $uid,
            'name'    => $r['user_name'] ?: 'Usuario #' . $uid,
            'photo'   => $r['user_photo'],
            'sectors' => [],
        ];
    }
    $sid = $r['sector_id'];
    if (!isset($usersData[$uid]['sectors'][$sid])) {
        $usersData[$uid]['sectors'][$sid] = [
            'id'     => $sid,
            'name'   => $r['sector_name'],
            'color'  => $r['sector_color'],
            'boards' => [],
        ];
    }
    if (!$r['board_id']) continue;
    $bid = $r['board_id'];
    if (!isset($usersData[$uid]['sectors'][$sid]['boards'][$bid])) {
        $usersData[$uid]['sectors'][$sid]['boards'][$bid] = [
            'id'    => $bid,
            'name'  => $r['board_name'],
            'color' => $r['board_color'],
            'notes' => [],
        ];
    }
    if ($r['note_id']) {
        $usersData[$uid]['sectors'][$sid]['boards'][$bid]['notes'][] = [
            'id'     => $r['note_id'],
            'title'  => $r['note_title'],
            'status' => $r['note_status'],
        ];
    }
}

// ── Helpers PHP ───────────────────────────────────────────────────────────────
function statusBadge(string $status): string {
    $map = [
        'pending'     => ['bg'=>'#fef3c7','color'=>'#92400e','icon'=>'fa-hourglass-half', 'label'=>'Pendiente'],
        'in_progress' => ['bg'=>'#dbeafe','color'=>'#1e40af','icon'=>'fa-spinner',         'label'=>'En proceso'],
        'completed'   => ['bg'=>'#d1fae5','color'=>'#065f46','icon'=>'fa-circle-check',    'label'=>'Completada'],
    ];
    $s = $map[$status] ?? $map['pending'];
    return "<span style=\"display:inline-flex;align-items:center;gap:4px;background:{$s['bg']};
            color:{$s['color']};border-radius:5px;padding:2px 7px;font-size:10px;font-weight:600\">
            <i class=\"fa-solid {$s['icon']}\" style=\"font-size:9px\"></i>{$s['label']}</span>";
}

function countByStatus(array $notes): array {
    $r = ['pending'=>0,'in_progress'=>0,'completed'=>0,'total'=>0];
    foreach ($notes as $n) {
        $r[$n['status']] = ($r[$n['status']] ?? 0) + 1;
        $r['total']++;
    }
    return $r;
}

$pageTitle = 'Sectores y Tareas';
include __DIR__ . '/includes/layout.php';
?>

<style>
/* ── Tabs ── */
.stab { padding:8px 18px;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;border:none;background:transparent;color:#6b7280; }
.stab.active { background:white;color:#162259;box-shadow:0 1px 6px rgba(22,34,89,0.12); }

/* ── Acordeón ── */
.acc-header {
    display:flex;align-items:center;gap:10px;
    padding:12px 16px;cursor:pointer;user-select:none;
    border-radius:12px;transition:background .15s;
}
.acc-header:hover { background:rgba(0,0,0,0.02); }
.acc-chevron { transition:transform .22s;font-size:12px;color:#9ca3af;flex-shrink:0; }
.acc-body { overflow:hidden;transition:max-height .28s ease; }

/* ── Board block ── */
.board-block {
    border:1.5px solid #e5e7eb;border-radius:12px;
    overflow:hidden;margin-bottom:10px;
}
.board-header {
    display:flex;align-items:center;gap:8px;
    padding:9px 14px;background:#fafafa;
    cursor:pointer;user-select:none;
    border-bottom:1px solid #e5e7eb;
    transition:background .15s;
}
.board-header:hover { background:#f3f4f6; }
.board-body { padding:0; }

/* ── Tarea row ── */
.note-row {
    display:flex;align-items:flex-start;gap:10px;
    padding:9px 14px;border-bottom:1px solid #f3f4f6;
    font-size:13px;color:#374151;
    transition:background .12s;
}
.note-row:hover { background:#f9fafb; }
.note-row:last-child { border-bottom:none; }

/* ── Status filter ── */
.sf-btn {
    padding:5px 12px;border-radius:7px;font-size:12px;font-weight:600;
    border:1.5px solid #e5e7eb;background:white;color:#6b7280;cursor:pointer;
    transition:all .15s;
}
.sf-btn.active { border-color:#0099cd;color:#0099cd;background:rgba(0,153,205,0.07); }

/* ── Counters ── */
.cnt { font-size:10px;font-weight:700;border-radius:5px;padding:2px 7px;flex-shrink:0; }
.cnt-navy  { background:rgba(22,34,89,0.1); color:#162259; }
.cnt-amber { background:#fef3c7; color:#92400e; }
.cnt-blue  { background:#dbeafe; color:#1e40af; }
.cnt-green { background:#d1fae5; color:#065f46; }

.empty-board { padding:14px;text-align:center;color:#d1d5db;font-size:12px; }

/* Ocultar completadas */
.hide-completed .note-row[data-status="completed"] { display:none; }
</style>

<!-- ── Encabezado ─────────────────────────────────────────────────────────── -->
<div class="flex items-center justify-between mb-5">
    <div>
        <h2 class="font-jakarta font-bold text-gray-900 text-xl">Sectores y Tareas</h2>
        <p class="text-gray-400 text-sm mt-0.5">Vista de control general para el administrador</p>
    </div>
</div>

<!-- ── Tabs + filtro ──────────────────────────────────────────────────────── -->
<div class="flex items-center justify-between mb-5 flex-wrap gap-3">
    <!-- Tabs -->
    <div style="background:#f3f4f6;border-radius:11px;padding:3px;display:inline-flex;gap:2px">
        <button class="stab active" id="tab-sector"  onclick="switchTab('sector')">
            <i class="fa-solid fa-tags mr-1.5"></i>Por Sector
        </button>
        <button class="stab"        id="tab-usuario" onclick="switchTab('usuario')">
            <i class="fa-solid fa-user mr-1.5"></i>Por Usuario
        </button>
    </div>

    <!-- Filtro de estado -->
    <div class="flex items-center gap-2 flex-wrap">
        <span class="text-xs text-gray-400 font-medium">Mostrar:</span>
        <button class="sf-btn active" onclick="setFilter('all',this)">Todas</button>
        <button class="sf-btn"        onclick="setFilter('pending',this)">Pendientes</button>
        <button class="sf-btn"        onclick="setFilter('in_progress',this)">En proceso</button>
        <button class="sf-btn"        onclick="setFilter('completed',this)">Completadas</button>
        <button class="sf-btn"        onclick="setFilter('not_completed',this)">Sin completar</button>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- VISTA POR SECTOR                                                         -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div id="view-sector">
<?php if (empty($sectors)): ?>
    <div class="card p-16 text-center">
        <i class="fa-solid fa-tags text-4xl mb-3" style="color:#e5e7eb"></i>
        <p class="font-semibold text-gray-500">No hay sectores creados.</p>
    </div>
<?php else: foreach ($sectors as $sector):
    // Juntar todas las notas del sector para el conteo
    $allSectorNotes = [];
    foreach ($sector['boards'] as $b) $allSectorNotes = array_merge($allSectorNotes, $b['notes']);
    $sc = countByStatus($allSectorNotes);
?>
<div class="card mb-4 overflow-hidden sector-block">
    <!-- Cabecera del sector (acordeón) -->
    <div class="acc-header" onclick="toggleAcc(this)">
        <div style="width:14px;height:14px;border-radius:4px;background:<?= htmlspecialchars($sector['color']) ?>;flex-shrink:0"></div>
        <h3 class="font-jakarta font-bold text-gray-900 flex-1" style="font-size:15px"><?= htmlspecialchars($sector['name']) ?></h3>
        <!-- Contadores -->
        <div class="hidden sm:flex items-center gap-2">
            <span class="cnt cnt-navy"><?= count($sector['boards']) ?> grupo<?= count($sector['boards'])!=1?'s':'' ?></span>
            <?php if ($sc['pending']): ?>
            <span class="cnt cnt-amber"><?= $sc['pending'] ?> pend.</span>
            <?php endif; ?>
            <?php if ($sc['in_progress']): ?>
            <span class="cnt cnt-blue"><?= $sc['in_progress'] ?> proc.</span>
            <?php endif; ?>
            <?php if ($sc['completed']): ?>
            <span class="cnt cnt-green"><?= $sc['completed'] ?> comp.</span>
            <?php endif; ?>
        </div>
        <i class="fa-solid fa-chevron-down acc-chevron"></i>
    </div>
    <!-- Cuerpo del sector -->
    <div class="acc-body" style="max-height:9999px">
        <div style="padding:6px 14px 14px">
            <?php if (empty($sector['boards'])): ?>
            <p class="text-sm text-gray-400 py-4 text-center">Este sector no tiene grupos de tareas.</p>
            <?php else: foreach ($sector['boards'] as $board):
                $bc = countByStatus($board['notes']);
            ?>
            <div class="board-block">
                <!-- Cabecera del grupo -->
                <div class="board-header" onclick="toggleBoard(this)">
                    <div style="width:10px;height:10px;border-radius:3px;background:<?= htmlspecialchars($board['color']) ?>;flex-shrink:0"></div>
                    <span class="font-semibold text-gray-800 flex-1" style="font-size:13px"><?= htmlspecialchars($board['name']) ?></span>
                    <div class="flex items-center gap-1.5">
                        <span class="cnt cnt-navy"><?= $bc['total'] ?> tarea<?= $bc['total']!=1?'s':'' ?></span>
                        <?php if ($bc['pending']): ?>
                        <span class="cnt cnt-amber"><?= $bc['pending'] ?></span>
                        <?php endif; ?>
                        <?php if ($bc['in_progress']): ?>
                        <span class="cnt cnt-blue"><?= $bc['in_progress'] ?></span>
                        <?php endif; ?>
                        <?php if ($bc['completed']): ?>
                        <span class="cnt cnt-green"><?= $bc['completed'] ?></span>
                        <?php endif; ?>
                    </div>
                    <i class="fa-solid fa-chevron-down acc-chevron ml-2"></i>
                </div>
                <!-- Tareas del grupo -->
                <div class="board-body notes-container" style="display:block">
                    <?php if (empty($board['notes'])): ?>
                    <div class="empty-board">Sin tareas en este grupo</div>
                    <?php else: foreach ($board['notes'] as $note): ?>
                    <div class="note-row" data-status="<?= $note['status'] ?>">
                        <div style="flex:1;min-width:0">
                            <p class="text-gray-800" style="font-size:13px;line-height:1.4"><?= htmlspecialchars($note['title']) ?></p>
                        </div>
                        <?= statusBadge($note['status']) ?>
                        <?php if ($note['assigned_name']): ?>
                        <div class="flex items-center gap-1.5 flex-shrink-0" style="max-width:140px">
                            <img src="<?= htmlspecialchars(userPhotoUrl($note['assigned_photo'] ?? '')) ?>"
                                 style="width:20px;height:20px;border-radius:50%;object-fit:cover;flex-shrink:0;border:1px solid #e5e7eb"
                                 onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
                            <span class="text-xs text-gray-400 truncate"><?= htmlspecialchars($note['assigned_name']) ?></span>
                        </div>
                        <?php else: ?>
                        <span class="text-xs text-gray-300 flex-shrink-0" style="width:100px;text-align:right">Sin asignar</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; endif; ?>
</div>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- VISTA POR USUARIO                                                        -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div id="view-usuario" style="display:none">
<?php if (empty($usersData)): ?>
    <div class="card p-16 text-center">
        <i class="fa-solid fa-users text-4xl mb-3" style="color:#e5e7eb"></i>
        <p class="font-semibold text-gray-500">No hay usuarios con sectores asignados.</p>
    </div>
<?php else: foreach ($usersData as $udata):
    // Contar todas las notas del usuario
    $allUserNotes = [];
    foreach ($udata['sectors'] as $sec) {
        foreach ($sec['boards'] as $b) {
            $allUserNotes = array_merge($allUserNotes, $b['notes']);
        }
    }
    $uc = countByStatus($allUserNotes);
?>
<div class="card mb-4 overflow-hidden">
    <!-- Cabecera del usuario -->
    <div class="acc-header" onclick="toggleAcc(this)">
        <img src="<?= htmlspecialchars(userPhotoUrl($udata['photo'] ?? '')) ?>"
             style="width:34px;height:34px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid #e5e7eb"
             onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
        <h3 class="font-jakarta font-bold text-gray-900 flex-1" style="font-size:15px"><?= htmlspecialchars($udata['name']) ?></h3>
        <div class="hidden sm:flex items-center gap-2">
            <span class="cnt cnt-navy"><?= count($udata['sectors']) ?> sector<?= count($udata['sectors'])!=1?'es':'' ?></span>
            <?php if ($uc['pending']): ?>
            <span class="cnt cnt-amber"><?= $uc['pending'] ?> pend.</span>
            <?php endif; ?>
            <?php if ($uc['in_progress']): ?>
            <span class="cnt cnt-blue"><?= $uc['in_progress'] ?> proc.</span>
            <?php endif; ?>
            <?php if ($uc['completed']): ?>
            <span class="cnt cnt-green"><?= $uc['completed'] ?> comp.</span>
            <?php endif; ?>
        </div>
        <i class="fa-solid fa-chevron-down acc-chevron"></i>
    </div>

    <!-- Cuerpo del usuario -->
    <div class="acc-body" style="max-height:9999px">
        <div style="padding:6px 14px 14px">
        <?php foreach ($udata['sectors'] as $usec): ?>
            <!-- Sector dentro del usuario -->
            <div style="margin-bottom:14px">
                <div class="flex items-center gap-2 mb-2 px-1">
                    <div style="width:11px;height:11px;border-radius:3px;background:<?= htmlspecialchars($usec['color']) ?>;flex-shrink:0"></div>
                    <span class="font-semibold text-gray-600" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px"><?= htmlspecialchars($usec['name']) ?></span>
                </div>

                <?php if (empty($usec['boards'])): ?>
                <p class="text-xs text-gray-400 px-2 mb-2">Este sector no tiene grupos de tareas.</p>
                <?php else: foreach ($usec['boards'] as $ub):
                    $ubc = countByStatus($ub['notes']);
                ?>
                <div class="board-block">
                    <div class="board-header" onclick="toggleBoard(this)">
                        <div style="width:9px;height:9px;border-radius:2px;background:<?= htmlspecialchars($ub['color']) ?>;flex-shrink:0"></div>
                        <span class="font-semibold text-gray-800 flex-1" style="font-size:13px"><?= htmlspecialchars($ub['name']) ?></span>
                        <div class="flex items-center gap-1.5">
                            <span class="cnt cnt-navy"><?= $ubc['total'] ?> tarea<?= $ubc['total']!=1?'s':'' ?></span>
                            <?php if ($ubc['pending']): ?>
                            <span class="cnt cnt-amber"><?= $ubc['pending'] ?></span>
                            <?php endif; ?>
                            <?php if ($ubc['in_progress']): ?>
                            <span class="cnt cnt-blue"><?= $ubc['in_progress'] ?></span>
                            <?php endif; ?>
                            <?php if ($ubc['completed']): ?>
                            <span class="cnt cnt-green"><?= $ubc['completed'] ?></span>
                            <?php endif; ?>
                        </div>
                        <i class="fa-solid fa-chevron-down acc-chevron ml-2"></i>
                    </div>
                    <div class="board-body notes-container" style="display:block">
                        <?php if (empty($ub['notes'])): ?>
                        <div class="empty-board">Sin tareas en este grupo</div>
                        <?php else: foreach ($ub['notes'] as $un): ?>
                        <div class="note-row" data-status="<?= $un['status'] ?>">
                            <div style="flex:1;min-width:0">
                                <p class="text-gray-800" style="font-size:13px;line-height:1.4"><?= htmlspecialchars($un['title']) ?></p>
                            </div>
                            <?= statusBadge($un['status']) ?>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endforeach; endif; ?>
</div>

<script>
const BASE_URL = '<?= BASE_URL ?>';

// ── Tabs ──────────────────────────────────────────────────────────────────────
function switchTab(tab) {
    document.getElementById('view-sector').style.display  = tab === 'sector'  ? '' : 'none';
    document.getElementById('view-usuario').style.display = tab === 'usuario' ? '' : 'none';
    document.getElementById('tab-sector').classList.toggle('active',  tab === 'sector');
    document.getElementById('tab-usuario').classList.toggle('active', tab === 'usuario');
}

// ── Acordeón de sector/usuario ────────────────────────────────────────────────
function toggleAcc(header) {
    const body    = header.nextElementSibling;
    const chevron = header.querySelector('.acc-chevron');
    const open    = body.style.maxHeight !== '0px' && body.style.maxHeight !== '';
    if (open) {
        body.style.maxHeight = '0px';
        chevron.style.transform = 'rotate(-90deg)';
    } else {
        body.style.maxHeight = body.scrollHeight + 'px';
        chevron.style.transform = 'rotate(0deg)';
        // Al abrir, recalcular por si tiene hijos que se abrieron mientras estaba cerrado
        setTimeout(() => { body.style.maxHeight = '9999px'; }, 300);
    }
}

// ── Toggle de grupo de tareas (board) ────────────────────────────────────────
function toggleBoard(header) {
    const body    = header.nextElementSibling;
    const chevron = header.querySelector('.acc-chevron');
    const open    = body.style.display !== 'none';
    body.style.display   = open ? 'none' : 'block';
    chevron.style.transform = open ? 'rotate(-90deg)' : 'rotate(0deg)';
}

// ── Filtro de estado ──────────────────────────────────────────────────────────
let currentFilter = 'all';

function setFilter(filter, btn) {
    currentFilter = filter;
    document.querySelectorAll('.sf-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyFilter();
}

function applyFilter() {
    document.querySelectorAll('.note-row').forEach(row => {
        const st = row.dataset.status;
        let show = true;
        if (currentFilter === 'pending')       show = st === 'pending';
        else if (currentFilter === 'in_progress') show = st === 'in_progress';
        else if (currentFilter === 'completed')    show = st === 'completed';
        else if (currentFilter === 'not_completed') show = st !== 'completed';
        row.style.display = show ? '' : 'none';
    });
}
</script>

<?php include __DIR__ . '/includes/layout_end.php'; ?>
