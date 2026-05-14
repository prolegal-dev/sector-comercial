<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();
$user        = currentUser();
$isAdminUser = isAdmin($user['id']);

$boardId = intval($_GET['id'] ?? 0);
if (!$boardId) { header('Location: ' . BASE_URL . '/grupos.php'); exit; }

// ── Acciones POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['_action'] ?? '';

    // Archivar / Eliminar (solo admin)
    if ($isAdminUser) {
        if ($a === 'archive_board') {
            $pdo->prepare("UPDATE boards SET is_archived=1 WHERE id=?")->execute([$boardId]);
            header('Location: ' . BASE_URL . '/grupos.php?archived=1');
            exit;
        }
        if ($a === 'delete_board') {
            $pdo->prepare("DELETE FROM boards WHERE id=?")->execute([$boardId]);
            header('Location: ' . BASE_URL . '/grupos.php?deleted=1');
            exit;
        }
    }

    // Editar grupo (admin o creador del grupo)
    if ($a === 'edit_board') {
        $bName  = trim($_POST['name']        ?? '');
        $bDesc  = trim($_POST['description'] ?? '') ?: null;
        $bCatId = intval($_POST['category_id'] ?? 0) ?: null;
        $bColor = $_POST['color'] ?? '#162259';

        // Recargar board para verificar creador
        $bRow = $pdo->prepare("SELECT created_by, category_id FROM boards WHERE id=? AND is_archived=0");
        $bRow->execute([$boardId]);
        $bRow = $bRow->fetch();

        $canEdit = $isAdminUser || ($bRow && $bRow['created_by'] == $user['id']);
        if ($canEdit && $bRow && $bName) {
            $oldCat = $bRow['category_id'];
            $pdo->prepare("UPDATE boards SET name=?, description=?, category_id=?, color=? WHERE id=?")
                ->execute([$bName, $bDesc, $bCatId, $bColor, $boardId]);
            // Si cambió el sector, auto-agregar nuevos usuarios
            if ($bCatId && $bCatId != $oldCat) {
                $su = $pdo->prepare("SELECT prolegal_id FROM category_users WHERE category_id=?");
                $su->execute([$bCatId]);
                $am = $pdo->prepare("INSERT IGNORE INTO board_members (board_id, prolegal_id, added_by) VALUES (?,?,?)");
                foreach ($su->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                    $am->execute([$boardId, $pid, $user['id']]);
                }
            }
            header('Location: ' . BASE_URL . '/board.php?id=' . $boardId . '&edited=1');
            exit;
        }
    }
}

// ── Cargar tablero ───────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT b.*, bc.name AS cat_name, bc.color AS cat_color, bc.id AS cat_id
    FROM boards b
    LEFT JOIN board_categories bc ON bc.id = b.category_id
    WHERE b.id = ? AND b.is_archived = 0
");
$stmt->execute([$boardId]);
$board = $stmt->fetch();
if (!$board) { header('Location: ' . BASE_URL . '/grupos.php'); exit; }

$canEditBoard = $isAdminUser || ($board['created_by'] == $user['id']);

// ── Verificar acceso ─────────────────────────────────────────────────────────
if (!$isAdminUser) {
    $isMember = $pdo->prepare("SELECT 1 FROM board_members WHERE board_id=? AND prolegal_id=? LIMIT 1");
    $isMember->execute([$boardId, $user['id']]);
    $isCreator = ($board['created_by'] == $user['id']);
    $isSectorMember = false;
    if ($board['category_id']) {
        $sc = $pdo->prepare("SELECT 1 FROM category_users WHERE category_id=? AND prolegal_id=? LIMIT 1");
        $sc->execute([$board['category_id'], $user['id']]);
        $isSectorMember = (bool)$sc->fetch();
    }
    if (!$isMember->fetch() && !$isCreator && !$isSectorMember) {
        header('Location: ' . BASE_URL . '/index.php'); exit;
    }
}

// ── Tareas activas ───────────────────────────────────────────────────────────
$activeNotes = $pdo->prepare("
    SELECT n.*, l.name AS label_name, l.color AS label_color,
           DATEDIFF(NOW(), n.created_at)  AS days_since,
           DATE_FORMAT(n.created_at, '%d/%m/%Y %H:%i') AS created_fmt,
           DATE_FORMAT(n.scheduled_date, '%d/%m/%Y') AS sched_date_fmt,
           TIME_FORMAT(n.scheduled_time, '%H:%i')    AS sched_time_fmt,
           au.cached_name  AS creator_name,
           au.cached_photo AS creator_photo
    FROM notes n
    LEFT JOIN labels l     ON l.id  = n.label_id
    LEFT JOIN app_users au ON au.prolegal_id = n.created_by
    WHERE n.board_id = ? AND n.status IN ('pending','in_progress')
    ORDER BY n.status ASC, n.created_at DESC
");
$activeNotes->execute([$boardId]);
$activeNotes = $activeNotes->fetchAll();

// ── Tareas completadas ───────────────────────────────────────────────────────
$completedNotes = $pdo->prepare("
    SELECT n.*, l.name AS label_name, l.color AS label_color,
           DATEDIFF(NOW(), n.created_at)  AS days_since,
           DATE_FORMAT(n.created_at, '%d/%m/%Y %H:%i') AS created_fmt,
           au.cached_name  AS creator_name,
           au.cached_photo AS creator_photo
    FROM notes n
    LEFT JOIN labels l     ON l.id  = n.label_id
    LEFT JOIN app_users au ON au.prolegal_id = n.created_by
    WHERE n.board_id = ? AND n.status = 'completed'
    ORDER BY n.updated_at DESC
");
$completedNotes->execute([$boardId]);
$completedNotes = $completedNotes->fetchAll();

// ── Usuarios etiquetados por tarea ───────────────────────────────────────────
$taggedUsersMap = [];
try {
    $tuStmt = $pdo->prepare("
        SELECT nu.note_id, nu.prolegal_id, au.cached_name AS name, au.cached_photo AS photo
        FROM note_users nu
        JOIN app_users au ON au.prolegal_id = nu.prolegal_id
        WHERE nu.note_id IN (SELECT id FROM notes WHERE board_id = ?)
        ORDER BY au.cached_name ASC
    ");
    $tuStmt->execute([$boardId]);
    foreach ($tuStmt->fetchAll() as $row) {
        $taggedUsersMap[$row['note_id']][] = $row;
    }
} catch (Exception $e) {
    // Tabla note_users puede no existir aún (requiere migration_001.sql)
    $taggedUsersMap = [];
}

// ── Miembros del grupo ───────────────────────────────────────────────────────
$members = $pdo->prepare("
    SELECT DISTINCT
        au.prolegal_id, au.cached_name AS name, au.cached_photo AS photo, au.is_admin,
        bm.added_at,
        CASE WHEN bm.prolegal_id IS NOT NULL THEN 1 ELSE 0 END AS is_direct_member
    FROM app_users au
    LEFT JOIN board_members bm ON bm.board_id = ? AND bm.prolegal_id = au.prolegal_id
    LEFT JOIN category_users cu ON cu.category_id = ? AND cu.prolegal_id = au.prolegal_id
    WHERE au.is_active = 1
      AND (bm.prolegal_id IS NOT NULL OR cu.prolegal_id IS NOT NULL)
    ORDER BY au.cached_name ASC
");
$members->execute([$boardId, $board['category_id']]);
$members = $members->fetchAll();
$memberIds = array_column($members, 'prolegal_id');

// ── Etiquetas ────────────────────────────────────────────────────────────────
$labels = $pdo->query("SELECT * FROM labels ORDER BY name")->fetchAll();

// ── Categorías (para modal de edición del grupo) ─────────────────────────────
$allCategories = $pdo->query("SELECT * FROM board_categories ORDER BY name")->fetchAll();

$pageTitle = htmlspecialchars($board['name']);
$extraHead = '<style>
body { overflow-x: hidden; }
.board-layout { display: flex; gap: 20px; align-items: flex-start; }
.col-active    { flex: 7; min-width: 0; }
.col-completed { flex: 3; min-width: 240px; }

/* Breadcrumb */
.board-breadcrumb {
    background: linear-gradient(135deg, #162259 0%, #0f1a42 100%);
    border-radius: 14px; padding: 14px 20px; margin-bottom: 18px;
    display: flex; align-items: center; gap: 12px;
}

/* Note cards */
.note-card {
    background: white; border-radius: 14px; padding: 14px 16px;
    border: 1.5px solid #e5e7eb; margin-bottom: 10px;
    transition: all .18s; position: relative;
}
.note-card:hover { border-color: #0099cd; box-shadow: 0 4px 16px rgba(0,153,205,0.1); }
.note-card.status-pending    { border-left: 4px solid #f59e0b; }
.note-card.status-in_progress{ border-left: 4px solid #0099cd; }
.note-card.status-completed  { border-left: 4px solid #10b981; background: #fafffe; }

/* Tareas completadas — visibles pero en gris (sin tachado) */
.note-card.status-completed .note-title    { color: #9ca3af; }
.note-card.status-completed .note-desc     { color: #b0b8c8; }

/* Status select compacto (en el header de la card) */
.status-select-compact {
    border: 1.5px solid #e5e7eb; border-radius: 7px;
    padding: 3px 6px; font-size: 11px; font-weight: 600;
    cursor: pointer; outline: none; transition: all .15s;
    background: white; max-width: 120px;
}
.status-select-compact:focus { box-shadow: 0 0 0 3px rgba(0,153,205,0.15); }
.status-select-compact.s-pending     { border-color:#f59e0b; color:#92400e; background:#fffbeb; }
.status-select-compact.s-in_progress { border-color:#0099cd; color:#0077a8; background:#f0f9ff; }
.status-select-compact.s-completed   { border-color:#10b981; color:#065f46; background:#f0fdf4; }

/* Col headers */
.col-header {
    border-radius: 12px; padding: 12px 16px; margin-bottom: 14px;
    display: flex; align-items: center; justify-content: space-between;
}
.col-header-active    { background:linear-gradient(135deg,#162259,#0f1a42); }
.col-header-completed { background:linear-gradient(135deg,#065f46,#047857); }

@media (max-width: 900px) {
    .board-layout { flex-direction: column; }
    .col-active, .col-completed { flex: none; width: 100%; }
}
</style>';

include __DIR__ . '/includes/layout.php';
?>

<!-- ── Breadcrumb / Jerarquía del grupo ──────────────────────────────────── -->
<div class="board-breadcrumb">
    <a href="<?= BASE_URL ?>/grupos.php" style="color:rgba(255,255,255,0.5);flex-shrink:0" class="hover:text-white transition-colors">
        <i class="fa-solid fa-arrow-left"></i>
    </a>
    <?php if ($board['cat_name']): ?>
    <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
        <div style="width:12px;height:12px;border-radius:3px;background:<?= htmlspecialchars($board['cat_color']) ?>;flex-shrink:0"></div>
        <span class="font-jakarta font-semibold" style="color:rgba(255,255,255,0.65);font-size:13px"><?= htmlspecialchars($board['cat_name']) ?></span>
    </div>
    <i class="fa-solid fa-chevron-right" style="color:rgba(255,255,255,0.3);font-size:10px;flex-shrink:0"></i>
    <?php endif; ?>
    <div style="flex:1;min-width:0">
        <div class="flex items-center gap-2">
            <div style="width:12px;height:12px;border-radius:50%;background:<?= htmlspecialchars($board['color']) ?>;flex-shrink:0"></div>
            <h2 class="font-jakarta font-bold text-white truncate" style="font-size:17px"><?= htmlspecialchars($board['name']) ?></h2>
        </div>
        <?php if ($board['cat_name']): ?>
        <p style="color:rgba(255,255,255,0.4);font-size:11px;margin-top:1px">Sector: <?= htmlspecialchars($board['cat_name']) ?></p>
        <?php endif; ?>
    </div>
    <!-- Acciones del grupo (admin) -->
    <div class="flex items-center gap-2 flex-shrink-0">
        <!-- Avatares de miembros -->
        <div class="flex -space-x-2 mr-1 hidden sm:flex">
            <?php foreach (array_slice($members, 0, 4) as $m): ?>
            <img src="<?= htmlspecialchars(userPhotoUrl($m['photo']??'')) ?>"
                 class="w-7 h-7 rounded-full object-cover"
                 style="border:2px solid rgba(255,255,255,0.3)"
                 title="<?= htmlspecialchars($m['name']) ?>"
                 onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
            <?php endforeach; ?>
            <?php if (count($members) > 4): ?>
            <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold" style="background:rgba(255,255,255,0.15);border:2px solid rgba(255,255,255,0.3);color:white">+<?= count($members)-4 ?></div>
            <?php endif; ?>
        </div>
        <?php if ($isAdminUser): ?>
        <button onclick="openMembersModal()" class="btn-ghost text-xs" style="padding:6px 10px;background:rgba(255,255,255,0.1);border-color:rgba(255,255,255,0.2);color:white">
            <i class="fa-solid fa-user-plus"></i>
        </button>
        <?php endif; ?>
        <?php if ($canEditBoard): ?>
        <!-- Menú opciones del grupo -->
        <div style="position:relative">
            <button onclick="toggleAdminMenu()" class="btn-ghost text-xs" style="padding:6px 10px;background:rgba(255,255,255,0.1);border-color:rgba(255,255,255,0.2);color:white" title="Opciones del grupo">
                <i class="fa-solid fa-ellipsis-vertical"></i>
            </button>
            <div id="admin-menu" style="display:none;position:absolute;right:0;top:calc(100% + 6px);background:white;border:1.5px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,0.12);min-width:190px;z-index:50">
                <button type="button"
                        onclick="toggleAdminMenu();openEditBoardModal()"
                        class="w-full text-left px-4 py-3 text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-3">
                    <i class="fa-solid fa-pen text-gray-400 w-4"></i> Editar grupo
                </button>
                <?php if ($isAdminUser): ?>
                <div style="height:1px;background:#f3f4f6"></div>
                <form method="POST" onsubmit="return confirm('¿Archivar este grupo de tareas?')">
                    <input type="hidden" name="_action" value="archive_board">
                    <button type="submit" class="w-full text-left px-4 py-3 text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-3">
                        <i class="fa-solid fa-box-archive text-gray-400 w-4"></i> Archivar grupo
                    </button>
                </form>
                <div style="height:1px;background:#f3f4f6"></div>
                <form method="POST" onsubmit="return confirm('⚠️ ¿Eliminar este grupo de tareas?\n\nSe eliminarán TODAS las tareas dentro de él. Esta acción no se puede deshacer.')">
                    <input type="hidden" name="_action" value="delete_board">
                    <button type="submit" class="w-full text-left px-4 py-3 text-sm hover:bg-red-50 flex items-center gap-3" style="color:#dc2626">
                        <i class="fa-solid fa-trash w-4"></i> Eliminar grupo y tareas
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <button onclick="openModal('modal-note')" class="btn-primary text-sm" style="background:rgba(0,153,205,0.9)">
            <i class="fa-solid fa-plus"></i> Nueva tarea
        </button>
    </div>
</div>

<!-- Layout 70/30 -->
<div class="board-layout">

    <!-- Columna 70% — Activas -->
    <div class="col-active">
        <div class="col-header col-header-active">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-list-check text-white/80 text-sm"></i>
                <span class="font-jakarta font-bold text-white text-sm">Activas</span>
                <span style="background:rgba(255,255,255,0.15);color:white;border-radius:20px;padding:1px 9px;font-size:12px;font-weight:700"><?= count($activeNotes) ?></span>
            </div>
            <div class="flex items-center gap-3 text-xs text-white/60">
                <span><span style="color:#f59e0b;font-weight:700"><?= count(array_filter($activeNotes, fn($n)=>$n['status']==='pending')) ?></span> pendientes</span>
                <span><span style="color:#7dd3fc;font-weight:700"><?= count(array_filter($activeNotes, fn($n)=>$n['status']==='in_progress')) ?></span> en proceso</span>
            </div>
        </div>

        <?php if (empty($activeNotes)): ?>
        <div style="background:white;border-radius:14px;padding:40px;text-align:center;border:2px dashed #e5e7eb">
            <i class="fa-solid fa-clipboard text-3xl mb-3" style="color:#d1d5db"></i>
            <p class="text-gray-400 text-sm">No hay tareas activas</p>
            <button onclick="openModal('modal-note')" class="btn-primary text-sm mt-4">
                <i class="fa-solid fa-plus"></i> Agregar primera tarea
            </button>
        </div>
        <?php else: ?>
        <?php foreach ($activeNotes as $note):
            $noteTags = $taggedUsersMap[$note['id']] ?? [];
        ?>
        <div class="note-card status-<?= $note['status'] ?>">
            <!-- Header: label, título, estado compacto, editar, eliminar -->
            <div class="flex items-start gap-2 mb-1.5">
                <div style="flex:1;min-width:0">
                    <?php if ($note['label_name']): ?>
                    <span class="badge mb-1" style="background:<?= htmlspecialchars($note['label_color']) ?>20;color:<?= htmlspecialchars($note['label_color']) ?>;font-size:10px"><?= htmlspecialchars($note['label_name']) ?></span>
                    <?php endif; ?>
                    <h4 class="note-title font-jakarta font-bold text-gray-900 text-sm leading-snug"><?= htmlspecialchars($note['title']) ?></h4>
                </div>
                <!-- Estado compacto + acciones -->
                <div class="flex items-center gap-1.5 flex-shrink-0 mt-0.5">
                    <select class="status-select-compact s-<?= $note['status'] ?>"
                            onchange="changeStatus(<?= $note['id'] ?>, this.value, this)"
                            title="Cambiar estado">
                        <option value="pending"     <?= $note['status']==='pending'     ?'selected':'' ?>>⏳ Pendiente</option>
                        <option value="in_progress" <?= $note['status']==='in_progress' ?'selected':'' ?>>🔄 En proceso</option>
                        <option value="completed"   <?= $note['status']==='completed'   ?'selected':'' ?>>✅ Listo</option>
                    </select>
                    <button onclick="editNote(<?= $note['id'] ?>)" class="text-gray-400 hover:text-gray-600 transition-colors" title="Editar" style="background:none;border:none;cursor:pointer;padding:3px">
                        <i class="fa-solid fa-pen text-xs"></i>
                    </button>
                    <?php if ($isAdminUser || $note['created_by'] == $user['id']): ?>
                    <button onclick="deleteNote(<?= $note['id'] ?>)" class="text-gray-400 hover:text-red-500 transition-colors" title="Eliminar" style="background:none;border:none;cursor:pointer;padding:3px">
                        <i class="fa-solid fa-trash text-xs"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($note['description']): ?>
            <p class="note-desc text-gray-500 text-xs leading-relaxed mb-2 line-clamp-3"><?= nl2br(htmlspecialchars($note['description'])) ?></p>
            <?php endif; ?>

            <!-- Footer: creador + usuarios etiquetados + fecha -->
            <div class="flex items-center justify-between" style="border-top:1px solid #f3f4f6;padding-top:8px;margin-top:6px">
                <div class="flex items-center gap-2 flex-wrap">
                    <img src="<?= htmlspecialchars(userPhotoUrl($note['creator_photo']??'')) ?>"
                         class="w-5 h-5 rounded-full object-cover flex-shrink-0"
                         onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'"
                         title="Creado por: <?= htmlspecialchars($note['creator_name']??'') ?>">
                    <span class="text-xs text-gray-500 truncate" style="max-width:80px"><?= htmlspecialchars($note['creator_name']??'—') ?></span>
                    <?php if (!empty($noteTags)): ?>
                    <span class="text-gray-300 text-xs">·</span>
                    <div class="flex items-center gap-1">
                        <i class="fa-solid fa-user-tag text-xs" style="color:#0099cd"></i>
                        <div class="flex -space-x-1">
                            <?php foreach (array_slice($noteTags, 0, 3) as $tu): ?>
                            <img src="<?= htmlspecialchars(userPhotoUrl($tu['photo']??'')) ?>"
                                 class="w-5 h-5 rounded-full object-cover"
                                 style="border:1.5px solid white"
                                 title="<?= htmlspecialchars($tu['name']) ?>"
                                 onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
                            <?php endforeach; ?>
                            <?php if (count($noteTags) > 3): ?>
                            <span class="text-xs text-gray-400 ml-1">+<?= count($noteTags)-3 ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-3 text-xs text-gray-400 flex-wrap">
                    <?php if (!empty($note['scheduled_date'])): ?>
                    <span style="display:inline-flex;align-items:center;gap:4px;background:#e0f4fb;color:#0077aa;border-radius:6px;padding:2px 8px;font-weight:600;font-size:10.5px">
                        <i class="fa-regular fa-calendar-check"></i>
                        <?= $note['sched_date_fmt'] ?>
                        <?= !empty($note['sched_time_fmt']) ? ' · ' . $note['sched_time_fmt'] : '' ?>
                    </span>
                    <?php endif; ?>
                    <span title="Fecha de carga"><i class="fa-regular fa-clock mr-1"></i><?= $note['created_fmt'] ?></span>
                    <span class="font-semibold" style="color:<?= $note['days_since']>7?'#f59e0b':($note['days_since']>3?'#6b7280':'#10b981') ?>">
                        <?= $note['days_since'] ?>d
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Columna 30% — Completadas -->
    <div class="col-completed">
        <div class="col-header col-header-completed">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-circle-check text-white/80 text-sm"></i>
                <span class="font-jakarta font-bold text-white text-sm">Completadas</span>
                <span style="background:rgba(255,255,255,0.15);color:white;border-radius:20px;padding:1px 9px;font-size:12px;font-weight:700"><?= count($completedNotes) ?></span>
            </div>
        </div>

        <?php if (empty($completedNotes)): ?>
        <div style="background:white;border-radius:14px;padding:30px;text-align:center;border:2px dashed #e5e7eb">
            <i class="fa-solid fa-circle-check text-2xl mb-2" style="color:#d1d5db"></i>
            <p class="text-gray-400 text-xs">Aún no hay tareas completadas</p>
        </div>
        <?php else: ?>
        <?php foreach ($completedNotes as $note):
            $noteTags   = $taggedUsersMap[$note['id']] ?? [];
            $canDelNote = $isAdminUser || $note['created_by'] == $user['id'];
            $noteJson   = htmlspecialchars(json_encode([
                'id'           => $note['id'],
                'title'        => $note['title'],
                'description'  => $note['description'] ?? '',
                'label_name'   => $note['label_name']  ?? '',
                'label_color'  => $note['label_color']  ?? '',
                'creator_name' => $note['creator_name'] ?? '',
                'creator_photo'=> $note['creator_photo'] ?? '',
                'created_fmt'  => $note['created_fmt'],
                'days_since'   => $note['days_since'],
                'tags'         => $noteTags,
                'can_delete'   => $canDelNote,
            ]), ENT_QUOTES);
        ?>
        <div class="note-card status-completed" style="cursor:pointer"
             onclick="openCompletedNote('<?= $noteJson ?>')"
             title="Click para ver detalles completos">
            <div class="flex items-start gap-2 mb-1.5">
                <div style="flex:1;min-width:0">
                    <?php if ($note['label_name']): ?>
                    <span class="badge mb-1" style="background:<?= htmlspecialchars($note['label_color']) ?>20;color:<?= htmlspecialchars($note['label_color']) ?>;font-size:10px;opacity:.7"><?= htmlspecialchars($note['label_name']) ?></span>
                    <?php endif; ?>
                    <h4 class="note-title font-jakarta font-bold text-xs leading-snug"><?= htmlspecialchars($note['title']) ?></h4>
                </div>
                <div class="flex items-center gap-1 flex-shrink-0 mt-0.5">
                    <button onclick="event.stopPropagation();changeStatusDirect(<?= $note['id'] ?>, 'in_progress')" title="Reabrir" style="background:none;border:none;cursor:pointer;padding:2px;color:#9ca3af" class="hover:text-amber-500 transition-colors">
                        <i class="fa-solid fa-rotate-left text-xs"></i>
                    </button>
                    <?php if ($canDelNote): ?>
                    <button onclick="event.stopPropagation();deleteNote(<?= $note['id'] ?>)" style="background:none;border:none;cursor:pointer;padding:2px;color:#9ca3af" class="hover:text-red-500 transition-colors">
                        <i class="fa-solid fa-trash text-xs"></i>
                    </button>
                    <?php endif; ?>
                    <i class="fa-solid fa-expand text-xs ml-1" style="color:#d1d5db" title="Ver detalles"></i>
                </div>
            </div>
            <?php if ($note['description']): ?>
            <p class="note-desc text-xs leading-relaxed mb-2 line-clamp-2"><?= nl2br(htmlspecialchars($note['description'])) ?></p>
            <?php endif; ?>
            <div class="flex items-center justify-between mt-2" style="border-top:1px solid #f0fdf4;padding-top:6px">
                <div class="flex items-center gap-1.5">
                    <img src="<?= htmlspecialchars(userPhotoUrl($note['creator_photo']??'')) ?>"
                         class="w-4 h-4 rounded-full object-cover"
                         onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
                    <span class="text-xs text-gray-400 truncate" style="max-width:70px"><?= htmlspecialchars($note['creator_name']??'—') ?></span>
                    <?php if (!empty($noteTags)): ?>
                    <div class="flex -space-x-0.5 ml-1">
                        <?php foreach (array_slice($noteTags, 0, 2) as $tu): ?>
                        <img src="<?= htmlspecialchars(userPhotoUrl($tu['photo']??'')) ?>"
                             class="w-4 h-4 rounded-full object-cover"
                             style="border:1px solid white"
                             title="<?= htmlspecialchars($tu['name']) ?>"
                             onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <span class="text-xs text-gray-400"><?= $note['days_since'] ?>d</span>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ══ MODAL: Nueva/Editar tarea ══════════════════════════════════════════════ -->
<div id="modal-note" class="modal-overlay" style="display:none">
    <div class="modal-box" style="max-width:520px">
        <div class="flex items-center justify-between mb-5">
            <h3 class="font-jakarta font-bold text-gray-900 text-lg" id="note-modal-title">Nueva tarea</h3>
            <button onclick="closeModal('modal-note')" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark text-xl"></i></button>
        </div>
        <div class="space-y-4">
            <input type="hidden" id="note-id" value="">
            <div>
                <label class="form-label">Título *</label>
                <input type="text" id="note-title" class="form-input" placeholder="Título de la tarea" maxlength="200">
            </div>
            <div>
                <label class="form-label">Descripción</label>
                <textarea id="note-desc" class="form-input" rows="3" placeholder="Descripción opcional..."></textarea>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Estado inicial</label>
                    <select id="note-status" class="form-select">
                        <option value="pending">⏳ Pendiente</option>
                        <option value="in_progress">🔄 En proceso</option>
                        <option value="completed">✅ Completado</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Etiqueta</label>
                    <select id="note-label" class="form-select" onchange="handleLabelChange(this.value)">
                        <option value="">Sin etiqueta</option>
                        <?php foreach ($labels as $l): ?>
                        <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['name']) ?></option>
                        <?php endforeach; ?>
                        <option value="__new__">+ Nueva etiqueta...</option>
                    </select>
                </div>
            </div>

            <!-- Formulario inline para nueva etiqueta -->
            <div id="new-label-form" style="display:none;background:#f8fafc;border:1.5px solid #e5e7eb;border-radius:10px;padding:12px" class="space-y-2">
                <p class="text-xs font-bold text-gray-600">Nueva etiqueta</p>
                <div class="flex gap-2">
                    <input type="text" id="new-label-name" class="form-input text-sm flex-1" placeholder="Nombre de la etiqueta" maxlength="50">
                    <input type="color" id="new-label-color" value="#0099cd" style="width:44px;height:42px;border-radius:8px;border:1.5px solid #e5e7eb;padding:3px;cursor:pointer;background:#fafafa;flex-shrink:0">
                </div>
            </div>

            <!-- Etiquetado de usuarios -->
            <div>
                <label class="form-label">Etiquetar usuarios <span class="text-gray-400 font-normal">(colaboradores)</span></label>
                <div class="relative mb-2">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs" style="pointer-events:none"></i>
                    <input type="text" id="tag-user-search" class="form-input text-sm"
                           style="padding:8px 10px 8px 30px"
                           placeholder="Buscar miembro para etiquetar..."
                           autocomplete="off"
                           oninput="filterTagUsers(this.value)">
                    <div id="tag-user-results"
                         style="display:none;position:absolute;left:0;right:0;top:calc(100% + 4px);background:white;border:1.5px solid #e5e7eb;border-radius:10px;overflow:hidden;box-shadow:0 8px 20px rgba(0,0,0,0.1);max-height:180px;overflow-y:auto;z-index:50">
                    </div>
                </div>
                <!-- Chips de usuarios etiquetados -->
                <div id="tagged-users-chips" class="flex flex-wrap gap-2 min-h-6"></div>
                <input type="hidden" id="tagged-users-ids" value="">
            </div>

            <!-- Fecha y hora -->
            <div style="background:#f0f7ff;border:1.5px solid #dbeafe;border-radius:10px;padding:12px 14px 14px">
                <div class="flex items-center gap-2 mb-3">
                    <i class="fa-regular fa-calendar-days" style="color:#0099cd;font-size:13px"></i>
                    <span style="font-size:12px;font-weight:700;color:#1e40af">Fecha en Calendario</span>
                    <span style="font-size:10px;color:#3b82f6;background:rgba(59,130,246,0.1);border-radius:5px;padding:1px 6px;font-weight:600">opcional</span>
                </div>
                <p style="font-size:11px;color:#6b7280;margin-bottom:10px;line-height:1.4">
                    <i class="fa-solid fa-circle-info" style="color:#93c5fd;margin-right:4px"></i>
                    La fecha que cargues acá va a aparecer en el <strong>Calendario</strong> y en la <strong>Vista Semanal</strong>.
                </p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="form-label" style="font-size:12px">Día</label>
                        <input type="date" id="note-date" class="form-input" style="font-size:13px">
                    </div>
                    <div>
                        <label class="form-label" style="font-size:12px">Hora <span class="text-gray-400 font-normal">(opc.)</span></label>
                        <input type="time" id="note-time" class="form-input" style="font-size:13px">
                    </div>
                </div>
            </div>

            <div class="flex gap-3 pt-2">
                <button onclick="closeModal('modal-note')" class="btn-ghost flex-1">Cancelar</button>
                <button onclick="saveNote()" class="btn-primary flex-1"><i class="fa-solid fa-floppy-disk"></i> Guardar tarea</button>
            </div>
        </div>
    </div>
</div>

<!-- ══ MODAL: Gestión de miembros ═══════════════════════════════════════════ -->
<!-- ══ MODAL: Editar grupo ════════════════════════════════════════════════════ -->
<?php if ($canEditBoard): ?>
<div id="modal-edit-board" class="modal-overlay" style="display:none">
    <div class="modal-box" style="max-width:460px">
        <div class="flex items-center justify-between mb-5">
            <h3 class="font-jakarta font-bold text-gray-900 text-lg">Editar grupo de tareas</h3>
            <button onclick="closeModal('modal-edit-board')" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="_action" value="edit_board">
            <div>
                <label class="form-label">Nombre *</label>
                <input type="text" name="name" id="eb-name" class="form-input"
                       value="<?= htmlspecialchars($board['name']) ?>" required maxlength="150">
            </div>
            <div>
                <label class="form-label">Descripción</label>
                <textarea name="description" id="eb-desc" class="form-input" rows="2"><?= htmlspecialchars($board['description'] ?? '') ?></textarea>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Sector</label>
                    <select name="category_id" id="eb-cat" class="form-select">
                        <option value="">Sin sector</option>
                        <?php foreach ($allCategories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"
                            <?= ($cat['id'] == $board['cat_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Color</label>
                    <input type="color" name="color" id="eb-color"
                           value="<?= htmlspecialchars($board['color']) ?>"
                           class="form-input" style="padding:4px;height:42px">
                </div>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeModal('modal-edit-board')" class="btn-ghost flex-1">Cancelar</button>
                <button type="submit" class="btn-primary flex-1">
                    <i class="fa-solid fa-floppy-disk"></i> Guardar cambios
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ══ MODAL: Detalle de tarea completada ════════════════════════════════════ -->
<div id="modal-completed-note" class="modal-overlay" style="display:none">
    <div class="modal-box" style="max-width:520px">
        <div class="flex items-center justify-between mb-5">
            <div class="flex items-center gap-2">
                <div style="background:#d1fae5;border-radius:8px;padding:6px 10px;display:flex;align-items:center;gap:6px">
                    <i class="fa-solid fa-circle-check" style="color:#10b981;font-size:13px"></i>
                    <span style="font-size:12px;font-weight:700;color:#065f46">Completada</span>
                </div>
            </div>
            <button onclick="closeModal('modal-completed-note')" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
        </div>
        <div id="completed-note-body"></div>
    </div>
</div>

<?php if ($isAdminUser): ?>
<div id="modal-members" class="modal-overlay" style="display:none">
    <div class="modal-box" style="max-width:520px">
        <div class="flex items-center justify-between mb-5">
            <h3 class="font-jakarta font-bold text-gray-900 text-lg">Miembros del grupo</h3>
            <button onclick="closeModal('modal-members')" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark text-xl"></i></button>
        </div>

        <p class="form-label mb-3">Miembros actuales (<?= count($members) ?>)</p>
        <div class="space-y-2 mb-5 max-h-48 overflow-y-auto">
            <?php foreach ($members as $m): ?>
            <div class="flex items-center justify-between p-3 rounded-xl" style="background:#f8fafc">
                <div class="flex items-center gap-3">
                    <img src="<?= htmlspecialchars(userPhotoUrl($m['photo']??'')) ?>"
                         class="w-8 h-8 rounded-full object-cover"
                         onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
                    <div>
                        <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($m['name']) ?></p>
                        <p class="text-xs text-gray-400">Agregado <?= date('d/m/Y', strtotime($m['added_at'])) ?></p>
                    </div>
                </div>
                <?php if ($m['prolegal_id'] == $board['created_by']): ?>
                <span class="badge badge-navy text-xs">Creador</span>
                <?php elseif ($m['is_direct_member']): ?>
                <button onclick="removeMember(<?= $m['prolegal_id'] ?>)" class="text-gray-400 hover:text-red-500 transition-colors text-xs" style="background:none;border:none;cursor:pointer" title="Quitar del grupo">
                    <i class="fa-solid fa-user-minus"></i>
                </button>
                <?php else: ?>
                <span class="badge badge-cyan text-xs" title="Accede por pertenecer al sector">Sector</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <p class="form-label mb-2">Agregar miembro</p>
        <div class="relative mb-1">
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
            <input type="text" id="member-search-input" class="form-input"
                   placeholder="Buscá por nombre o email..."
                   style="padding-left:36px" autocomplete="off"
                   oninput="memberSearch(this.value)">
            <span id="member-search-spinner" class="hidden absolute right-3 top-1/2 -translate-y-1/2">
                <i class="fa-solid fa-spinner fa-spin text-gray-400 text-sm"></i>
            </span>
        </div>
        <div id="member-search-results"
             class="rounded-xl overflow-hidden border border-gray-200"
             style="min-height:56px;max-height:260px;overflow-y:auto;display:none;box-shadow:0 4px 16px rgba(0,0,0,0.08)">
            <div class="p-4 text-center text-xs text-gray-400">
                <i class="fa-solid fa-magnifying-glass mr-1"></i>Escribí al menos 2 letras para buscar
            </div>
        </div>
        <div class="pt-5">
            <button onclick="closeModal('modal-members')" class="btn-ghost w-full">Cerrar</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const BASE_URL        = '<?= BASE_URL ?>';
const BOARD_ID        = <?= $boardId ?>;
const CURRENT_USER_ID = <?= $user['id'] ?>;

// Miembros del grupo (para etiquetado de usuarios)
const BOARD_MEMBERS = <?= json_encode(array_values($members)) ?>;

// ── Editar grupo ─────────────────────────────────────────────────────────────
function openEditBoardModal() {
    openModal('modal-edit-board');
    setTimeout(() => document.getElementById('eb-name').focus(), 100);
}

// ── Admin menu toggle ─────────────────────────────────────────────────────────
function toggleAdminMenu() {
    const m = document.getElementById('admin-menu');
    m.style.display = m.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', e => {
    if (!e.target.closest('[onclick="toggleAdminMenu()"]') && !e.target.closest('#admin-menu')) {
        const m = document.getElementById('admin-menu');
        if (m) m.style.display = 'none';
    }
});

// ── Nueva/Editar tarea ────────────────────────────────────────────────────────
let taggedUsers = []; // { id, name, photo }

function openNoteModal(noteId = null) {
    taggedUsers = [];
    renderTaggedChips();
    document.getElementById('note-id').value        = noteId || '';
    document.getElementById('note-modal-title').textContent = noteId ? 'Editar tarea' : 'Nueva tarea';
    document.getElementById('new-label-form').style.display = 'none';
    if (!noteId) {
        document.getElementById('note-title').value  = '';
        document.getElementById('note-desc').value   = '';
        document.getElementById('note-status').value = 'pending';
        document.getElementById('note-label').value  = '';
        document.getElementById('note-date').value   = '';
        document.getElementById('note-time').value   = '';
        document.getElementById('tag-user-search').value = '';
        document.getElementById('tag-user-results').style.display = 'none';
    }
    openModal('modal-note');
    setTimeout(() => document.getElementById('note-title').focus(), 100);
}

// Manejar cambio de etiqueta (incluye opción "nueva")
function handleLabelChange(val) {
    document.getElementById('new-label-form').style.display = val === '__new__' ? 'block' : 'none';
    if (val === '__new__') {
        document.getElementById('new-label-name').focus();
    }
}

async function saveNote() {
    const id     = document.getElementById('note-id').value;
    const title  = document.getElementById('note-title').value.trim();
    if (!title) { showToast('El título es obligatorio', 'error'); return; }

    let labelId = document.getElementById('note-label').value || null;

    // Si hay nueva etiqueta, crearla primero
    if (labelId === '__new__') {
        const lName  = document.getElementById('new-label-name').value.trim();
        const lColor = document.getElementById('new-label-color').value;
        if (!lName) { showToast('Ingresá un nombre para la etiqueta', 'error'); return; }
        const lr = await apiCall(BASE_URL + '/api/notes.php', {
            action: 'create_label', name: lName, color: lColor
        });
        if (!lr.success) { showToast(lr.error || 'Error al crear etiqueta', 'error'); return; }
        labelId = lr.label_id;
        // Agregar al select y seleccionarlo
        const sel = document.getElementById('note-label');
        const opt = document.createElement('option');
        opt.value = labelId; opt.textContent = lName;
        sel.insertBefore(opt, sel.lastElementChild);
        sel.value = labelId;
    }

    const taggedIds = taggedUsers.map(u => u.id);

    const r = await apiCall(BASE_URL + '/api/notes.php', {
        action:         id ? 'update' : 'create',
        note_id:        id || null,
        board_id:       BOARD_ID,
        title,
        description:    document.getElementById('note-desc').value.trim(),
        status:         document.getElementById('note-status').value,
        label_id:       labelId,
        scheduled_date: document.getElementById('note-date').value || '',
        scheduled_time: document.getElementById('note-time').value || '',
        tagged_users:   taggedIds,
    });
    if (r.success) {
        closeModal('modal-note');
        showToast(id ? 'Tarea actualizada' : 'Tarea creada');
        setTimeout(() => location.reload(), 500);
    } else {
        showToast(r.error || 'Error al guardar', 'error');
    }
}

function editNote(id) {
    taggedUsers = [];
    apiCall(BASE_URL + '/api/notes.php', { action: 'get', note_id: id }).then(r => {
        if (!r.success) return;
        const n = r.note;
        document.getElementById('note-id').value        = n.id;
        document.getElementById('note-title').value     = n.title;
        document.getElementById('note-desc').value      = n.description || '';
        document.getElementById('note-status').value    = n.status;
        document.getElementById('note-label').value     = n.label_id || '';
        document.getElementById('note-date').value      = n.scheduled_date_fmt || '';
        document.getElementById('note-time').value      = n.scheduled_time_fmt || '';
        document.getElementById('note-modal-title').textContent = 'Editar tarea';
        document.getElementById('new-label-form').style.display = 'none';
        document.getElementById('tag-user-search').value = '';
        document.getElementById('tag-user-results').style.display = 'none';
        // Restaurar usuarios etiquetados
        if (r.tagged_users && r.tagged_users.length) {
            taggedUsers = r.tagged_users.map(u => ({ id: u.prolegal_id, name: u.name, photo: u.photo }));
            renderTaggedChips();
        }
        openModal('modal-note');
    });
}

// ── Etiquetado de usuarios ────────────────────────────────────────────────────
function filterTagUsers(q) {
    const container = document.getElementById('tag-user-results');
    q = q.trim().toLowerCase();
    if (q.length < 2) { container.style.display = 'none'; return; }

    const taggedIds = taggedUsers.map(u => u.id);
    const matches = BOARD_MEMBERS.filter(m =>
        !taggedIds.includes(m.prolegal_id) &&
        ((m.name  && m.name.toLowerCase().includes(q)) ||
         (m.email && m.email.toLowerCase().includes(q)))
    ).slice(0, 8);

    if (!matches.length) {
        container.innerHTML = '<div style="padding:10px;text-align:center;font-size:12px;color:#9ca3af">Sin resultados</div>';
        container.style.display = 'block';
        return;
    }

    container.innerHTML = matches.map(m => `
        <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;cursor:pointer;border-bottom:1px solid #f3f4f6;transition:background .12s"
             onmouseenter="this.style.background='#eff6ff'"
             onmouseleave="this.style.background=''"
             onclick="tagUser(${m.prolegal_id}, '${escJs(m.name)}', '${escJs(m.photo || '')}')">
            <img src="${m.photo ? 'https://prolegal.com.ar/storage/' + m.photo : BASE_URL + '/assets/img/avatar-default.svg'}"
                 style="width:28px;height:28px;border-radius:50%;object-fit:cover;flex-shrink:0"
                 onerror="this.src='${BASE_URL}/assets/img/avatar-default.svg'">
            <span style="font-size:13px;font-weight:600;color:#111827">${escHtml(m.name || '#' + m.prolegal_id)}</span>
        </div>
    `).join('');

    container.style.display = 'block';
}

function tagUser(id, name, photo) {
    if (taggedUsers.find(u => u.id === id)) return;
    taggedUsers.push({ id, name, photo });
    renderTaggedChips();
    document.getElementById('tag-user-search').value = '';
    document.getElementById('tag-user-results').style.display = 'none';
}

function removeTaggedUser(id) {
    taggedUsers = taggedUsers.filter(u => u.id !== id);
    renderTaggedChips();
}

function renderTaggedChips() {
    const container = document.getElementById('tagged-users-chips');
    container.innerHTML = taggedUsers.map(u => `
        <div style="display:inline-flex;align-items:center;gap:6px;background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:99px;padding:3px 8px 3px 4px">
            <img src="${u.photo ? 'https://prolegal.com.ar/storage/' + u.photo : BASE_URL + '/assets/img/avatar-default.svg'}"
                 style="width:20px;height:20px;border-radius:50%;object-fit:cover"
                 onerror="this.src='${BASE_URL}/assets/img/avatar-default.svg'">
            <span style="font-size:12px;font-weight:600;color:#1d4ed8">${escHtml(u.name)}</span>
            <button onclick="removeTaggedUser(${u.id})"
                    style="background:none;border:none;cursor:pointer;color:#93c5fd;padding:0;line-height:1;font-size:12px"
                    class="hover:text-blue-600 transition-colors">✕</button>
        </div>
    `).join('');
    document.getElementById('tagged-users-ids').value = taggedUsers.map(u => u.id).join(',');
}

// Cerrar tag results al clickear afuera
document.addEventListener('click', e => {
    if (!e.target.closest('#tag-user-search') && !e.target.closest('#tag-user-results')) {
        const c = document.getElementById('tag-user-results');
        if (c) c.style.display = 'none';
    }
});

// ── Cambio de estado ──────────────────────────────────────────────────────────
async function changeStatus(noteId, newStatus, selectEl) {
    selectEl.className = `status-select-compact s-${newStatus}`;
    const r = await apiCall(BASE_URL + '/api/notes.php', {
        action: 'change_status', note_id: noteId, status: newStatus
    });
    if (r.success) {
        showToast('Estado actualizado');
        setTimeout(() => location.reload(), 600);
    } else {
        showToast(r.error || 'Error', 'error');
        location.reload();
    }
}

async function changeStatusDirect(noteId, newStatus) {
    const r = await apiCall(BASE_URL + '/api/notes.php', {
        action: 'change_status', note_id: noteId, status: newStatus
    });
    if (r.success) {
        showToast('Tarea reabierta');
        setTimeout(() => location.reload(), 500);
    } else {
        showToast(r.error || 'Error', 'error');
    }
}

// ── Eliminar tarea ────────────────────────────────────────────────────────────
async function deleteNote(noteId) {
    if (!confirm('¿Eliminar esta tarea?')) return;
    const r = await apiCall(BASE_URL + '/api/notes.php', { action: 'delete', note_id: noteId });
    if (r.success) { showToast('Tarea eliminada'); setTimeout(() => location.reload(), 500); }
    else showToast(r.error || 'Error', 'error');
}

// ── Buscador de miembros (para agregar al grupo) ──────────────────────────────
let memberSearchTimer = null;
function memberSearch(q) {
    clearTimeout(memberSearchTimer);
    const container = document.getElementById('member-search-results');
    const spinner   = document.getElementById('member-search-spinner');
    q = q.trim();
    if (q.length < 2) { container.style.display = 'none'; return; }
    container.style.display = 'block';
    spinner.classList.remove('hidden');

    memberSearchTimer = setTimeout(async () => {
        const url = BASE_URL + '/api/search_users.php?q=' + encodeURIComponent(q) + '&exclude_members=' + BOARD_ID;
        try {
            const res  = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await res.json();
            spinner.classList.add('hidden');
            if (!data.success || !data.users.length) {
                container.innerHTML = '<div class="p-4 text-center text-xs text-gray-400">Sin resultados para "' + escHtml(q) + '"</div>';
                return;
            }
            container.innerHTML = data.users.map(u => `
                <div class="flex items-center justify-between p-3 hover:bg-blue-50 transition-colors border-b border-gray-100 last:border-0">
                    <div class="flex items-center gap-3 min-w-0">
                        <img src="${u.profile_photo ? 'https://prolegal.com.ar/storage/' + u.profile_photo : BASE_URL + '/assets/img/avatar-default.svg'}"
                             class="w-8 h-8 rounded-full object-cover flex-shrink-0"
                             onerror="this.src='${BASE_URL}/assets/img/avatar-default.svg'">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-gray-800 truncate">${escHtml(u.name || '#' + u.id)}</p>
                            <p class="text-xs text-gray-400 truncate">${escHtml(u.email || '')}</p>
                        </div>
                    </div>
                    <button onclick="addMember(${u.id})" class="btn-primary text-xs flex-shrink-0 ml-2" style="padding:5px 12px">
                        <i class="fa-solid fa-user-plus"></i> Agregar
                    </button>
                </div>
            `).join('');
        } catch (e) {
            spinner.classList.add('hidden');
            container.innerHTML = '<div class="p-4 text-center text-xs text-red-400">Error al buscar.</div>';
        }
    }, 300);
}

document.addEventListener('click', e => {
    if (!e.target.closest('#member-search-input') && !e.target.closest('#member-search-results')) {
        const c = document.getElementById('member-search-results');
        if (c) c.style.display = 'none';
    }
});

function openMembersModal() {
    document.getElementById('member-search-input').value = '';
    document.getElementById('member-search-results').style.display = 'none';
    openModal('modal-members');
}
async function addMember(prolegalId) {
    const r = await apiCall(BASE_URL + '/api/members.php', {
        action: 'add', board_id: BOARD_ID, prolegal_id: prolegalId
    });
    if (r.success) {
        showToast('Miembro agregado');
        document.getElementById('member-search-input').value = '';
        document.getElementById('member-search-results').style.display = 'none';
        setTimeout(() => location.reload(), 500);
    } else showToast(r.error || 'Error', 'error');
}
async function removeMember(prolegalId) {
    if (!confirm('¿Quitar este miembro del grupo?')) return;
    const r = await apiCall(BASE_URL + '/api/members.php', {
        action: 'remove', board_id: BOARD_ID, prolegal_id: prolegalId
    });
    if (r.success) { showToast('Miembro eliminado'); setTimeout(() => location.reload(), 500); }
    else showToast(r.error || 'Error', 'error');
}

function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escJs(s) {
    return String(s || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"');
}

// ── Popup de tarea completada ─────────────────────────────────────────────────
function openCompletedNote(jsonStr) {
    let note;
    try { note = JSON.parse(jsonStr); } catch(e) { return; }

    const photoUrl = (p) => p
        ? (p.startsWith('http') ? p : 'https://prolegal.com.ar/storage/' + p)
        : BASE_URL + '/assets/img/avatar-default.svg';

    // Label
    const labelHtml = note.label_name
        ? `<span style="background:${note.label_color}20;color:${note.label_color};border-radius:6px;padding:2px 8px;font-size:11px;font-weight:600;display:inline-block;margin-bottom:10px">${escHtml(note.label_name)}</span>`
        : '';

    // Descripción
    const descHtml = note.description
        ? `<div style="background:#f8fafc;border:1.5px solid #e5e7eb;border-radius:10px;padding:12px 14px;margin-bottom:14px">
               <p style="font-size:13px;color:#374151;line-height:1.6;white-space:pre-wrap">${escHtml(note.description)}</p>
           </div>`
        : '<p style="font-size:13px;color:#9ca3af;margin-bottom:14px;font-style:italic">Sin descripción</p>';

    // Tags
    let tagsHtml = '';
    if (note.tags && note.tags.length) {
        tagsHtml = `<div style="margin-bottom:14px">
            <p style="font-size:11px;font-weight:700;color:#9ca3af;letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px">Colaboradores</p>
            <div style="display:flex;flex-wrap:wrap;gap:8px">` +
            note.tags.map(t => `
                <div style="display:inline-flex;align-items:center;gap:6px;background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:99px;padding:3px 10px 3px 4px">
                    <img src="${photoUrl(t.photo)}" style="width:20px;height:20px;border-radius:50%;object-fit:cover" onerror="this.src='${BASE_URL}/assets/img/avatar-default.svg'">
                    <span style="font-size:12px;font-weight:600;color:#1d4ed8">${escHtml(t.name)}</span>
                </div>`).join('') +
            `</div></div>`;
    }

    // Botones de acción
    const reopenBtn = `<button onclick="changeStatusDirect(${note.id},'in_progress');closeModal('modal-completed-note')" class="btn-ghost" style="font-size:13px">
        <i class="fa-solid fa-rotate-left"></i> Reabrir tarea
    </button>`;
    const deleteBtn = note.can_delete
        ? `<button onclick="deleteNote(${note.id});closeModal('modal-completed-note')" class="btn-danger" style="font-size:13px">
               <i class="fa-solid fa-trash"></i> Eliminar
           </button>`
        : '';

    document.getElementById('completed-note-body').innerHTML = `
        ${labelHtml}
        <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:17px;color:#111827;line-height:1.3;margin-bottom:12px">${escHtml(note.title)}</h3>
        ${descHtml}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
            <div style="background:#f0fdf4;border-radius:10px;padding:10px 12px">
                <p style="font-size:10px;font-weight:700;color:#065f46;letter-spacing:.5px;text-transform:uppercase;margin-bottom:4px">Creado por</p>
                <div style="display:flex;align-items:center;gap:7px">
                    <img src="${photoUrl(note.creator_photo)}" style="width:24px;height:24px;border-radius:50%;object-fit:cover" onerror="this.src='${BASE_URL}/assets/img/avatar-default.svg'">
                    <span style="font-size:13px;font-weight:600;color:#111827">${escHtml(note.creator_name || '—')}</span>
                </div>
            </div>
            <div style="background:#f0fdf4;border-radius:10px;padding:10px 12px">
                <p style="font-size:10px;font-weight:700;color:#065f46;letter-spacing:.5px;text-transform:uppercase;margin-bottom:4px">Fecha</p>
                <p style="font-size:13px;font-weight:600;color:#111827">${escHtml(note.created_fmt)}</p>
                <p style="font-size:11px;color:#6b7280">Hace ${note.days_since} día${note.days_since != 1 ? 's' : ''}</p>
            </div>
        </div>
        ${tagsHtml}
        <div style="display:flex;gap:10px;padding-top:4px;border-top:1px solid #f3f4f6;margin-top:4px">
            ${reopenBtn}
            ${deleteBtn}
            <button onclick="closeModal('modal-completed-note')" class="btn-ghost" style="font-size:13px;margin-left:auto">Cerrar</button>
        </div>
    `;
    openModal('modal-completed-note');
}

<?php if (isset($_GET['created'])): ?>showToast('¡Grupo de tareas creado!');<?php endif; ?>
<?php if (isset($_GET['edited'])): ?>showToast('Grupo actualizado correctamente');<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/layout_end.php'; ?>
