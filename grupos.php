<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();
$user        = currentUser();
$isAdminUser = isAdmin($user['id']);

$error = $success = '';

// Sectores a los que pertenece el usuario (para el formulario de creación)
$userSectors = [];
if (!$isAdminUser) {
    $us = $pdo->prepare("
        SELECT bc.id, bc.name, bc.color
        FROM category_users cu
        JOIN board_categories bc ON bc.id = cu.category_id
        WHERE cu.prolegal_id = ?
        ORDER BY bc.name ASC
    ");
    $us->execute([$user['id']]);
    $userSectors = $us->fetchAll();
}

// Puede crear grupos: admin O miembro de al menos un sector
$canCreate = $isAdminUser || !empty($userSectors);

// ── Crear grupo de tareas ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'create' && $canCreate) {
    $name  = trim($_POST['name']        ?? '');
    $desc  = trim($_POST['description'] ?? '') ?: null;
    $catId = intval($_POST['category_id'] ?? 0) ?: null;
    $color = $_POST['color'] ?? '#162259';

    // Usuarios no-admin solo pueden crear en sus sectores asignados
    if (!$isAdminUser && $catId) {
        $allowed = array_column($userSectors, 'id');
        if (!in_array($catId, $allowed)) {
            $error = 'No tenés permiso para crear grupos en ese sector.';
            $catId = null;
        }
    }

    if ($name && !$error) {
        $pdo->prepare(
            "INSERT INTO boards (name, description, category_id, color, created_by) VALUES (?,?,?,?,?)"
        )->execute([$name, $desc, $catId, $color, $user['id']]);
        $newId = $pdo->lastInsertId();

        // Agregar al creador como miembro
        $pdo->prepare("INSERT IGNORE INTO board_members (board_id, prolegal_id, added_by) VALUES (?,?,?)")
            ->execute([$newId, $user['id'], $user['id']]);

        // Si tiene sector, agregar todos sus usuarios como miembros
        if ($catId) {
            $sectorUsers = $pdo->prepare("SELECT prolegal_id FROM category_users WHERE category_id = ?");
            $sectorUsers->execute([$catId]);
            $addStmt = $pdo->prepare("INSERT IGNORE INTO board_members (board_id, prolegal_id, added_by) VALUES (?,?,?)");
            foreach ($sectorUsers->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $addStmt->execute([$newId, $pid, $user['id']]);
            }
        }

        header('Location: ' . BASE_URL . '/board.php?id=' . $newId . '&created=1');
        exit;
    } elseif (!$name && !$error) {
        $error = 'El nombre es obligatorio.';
    }
}

// ── Editar grupo de tareas ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'edit') {
    $editId   = intval($_POST['board_id']     ?? 0);
    $name     = trim($_POST['name']           ?? '');
    $desc     = trim($_POST['description']    ?? '') ?: null;
    $catId    = intval($_POST['category_id']  ?? 0) ?: null;
    $color    = $_POST['color']               ?? '#162259';

    if ($editId && $name) {
        // Verificar permisos: admin o creador del grupo
        $boardCheck = $pdo->prepare("SELECT created_by, category_id FROM boards WHERE id = ? AND is_archived = 0");
        $boardCheck->execute([$editId]);
        $boardRow = $boardCheck->fetch();

        $canEdit = $isAdminUser || ($boardRow && $boardRow['created_by'] == $user['id']);

        if ($canEdit && $boardRow) {
            $oldCatId = $boardRow['category_id'];
            $pdo->prepare("UPDATE boards SET name=?, description=?, category_id=?, color=? WHERE id=?")
                ->execute([$name, $desc, $catId, $color, $editId]);

            // Si cambió el sector, agregar usuarios del nuevo sector al board
            if ($catId && $catId != $oldCatId) {
                $sectorUsers = $pdo->prepare("SELECT prolegal_id FROM category_users WHERE category_id = ?");
                $sectorUsers->execute([$catId]);
                $addStmt = $pdo->prepare("INSERT IGNORE INTO board_members (board_id, prolegal_id, added_by) VALUES (?,?,?)");
                foreach ($sectorUsers->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                    $addStmt->execute([$editId, $pid, $user['id']]);
                }
            }

            $success = 'Grupo actualizado correctamente.';
        } else {
            $error = 'No tenés permiso para editar este grupo.';
        }
    } else {
        $error = 'Datos inválidos.';
    }
}

// ── Eliminar grupo de tareas ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    $delId = intval($_POST['board_id'] ?? 0);
    if ($delId) {
        $boardCheck = $pdo->prepare("SELECT created_by FROM boards WHERE id = ? AND is_archived = 0");
        $boardCheck->execute([$delId]);
        $boardRow = $boardCheck->fetch();

        $canDel = $isAdminUser || ($boardRow && $boardRow['created_by'] == $user['id']);
        if ($canDel) {
            $pdo->prepare("DELETE FROM boards WHERE id = ?")->execute([$delId]);
            header('Location: ' . BASE_URL . '/grupos.php?deleted=1');
            exit;
        } else {
            $error = 'No tenés permiso para eliminar este grupo.';
        }
    }
}

// ── Todos los sectores (para admin) o los del usuario ────────────────────────
$categories = $isAdminUser
    ? $pdo->query("SELECT * FROM board_categories ORDER BY name")->fetchAll()
    : $userSectors;

// ── Cargar grupos según el rol del usuario ───────────────────────────────────
if ($isAdminUser) {
    $boards = $pdo->query("
        SELECT b.*, bc.name AS cat_name, bc.color AS cat_color,
               COUNT(DISTINCT n.id)           AS note_count,
               COUNT(DISTINCT bm.prolegal_id) AS member_count
        FROM boards b
        LEFT JOIN board_categories bc ON bc.id = b.category_id
        LEFT JOIN notes n             ON n.board_id = b.id
        LEFT JOIN board_members bm    ON bm.board_id = b.id
        WHERE b.is_archived = 0
        GROUP BY b.id
        ORDER BY b.created_at DESC
    ")->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT b.*, bc.name AS cat_name, bc.color AS cat_color,
               COUNT(DISTINCT n.id)            AS note_count,
               COUNT(DISTINCT bm2.prolegal_id) AS member_count
        FROM boards b
        LEFT JOIN board_categories bc ON bc.id = b.category_id
        LEFT JOIN notes n             ON n.board_id = b.id
        LEFT JOIN board_members bm2   ON bm2.board_id = b.id
        WHERE b.is_archived = 0
          AND (
              b.created_by = ?
              OR EXISTS (SELECT 1 FROM board_members bm WHERE bm.board_id = b.id AND bm.prolegal_id = ?)
              OR EXISTS (SELECT 1 FROM category_users cu WHERE cu.prolegal_id = ? AND cu.category_id = b.category_id)
          )
        GROUP BY b.id
        ORDER BY b.created_at DESC
    ");
    $stmt->execute([$user['id'], $user['id'], $user['id']]);
    $boards = $stmt->fetchAll();
}

$showModal = isset($_GET['new']);
$pageTitle = 'Grupo de tareas';
include __DIR__ . '/includes/layout.php';
?>

<div class="flex items-center justify-between mb-6">
    <p class="text-gray-500 text-sm"><?= count($boards) ?> grupo<?= count($boards)!=1?'s':'' ?> de tareas</p>
    <?php if ($canCreate): ?>
    <button onclick="openModal('modal-board')" class="btn-primary">
        <i class="fa-solid fa-plus"></i> Nuevo grupo
    </button>
    <?php endif; ?>
</div>

<?php if ($error):   ?><div class="mb-4 p-4 rounded-xl text-sm" style="background:#fef2f2;border:1px solid #fecaca;color:#dc2626"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="mb-4 p-4 rounded-xl text-sm" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="mb-4 p-4 rounded-xl text-sm" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46">Grupo de tareas eliminado correctamente.</div><?php endif; ?>
<?php if (isset($_GET['archived'])): ?><div class="mb-4 p-4 rounded-xl text-sm" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46">Grupo de tareas archivado.</div><?php endif; ?>

<?php if (empty($boards)): ?>
<div class="card p-20 text-center">
    <i class="fa-solid fa-layer-group text-6xl mb-5" style="color:#e5e7eb"></i>
    <p class="font-jakarta font-bold text-gray-700 text-xl">Sin grupos de tareas</p>
    <p class="text-gray-400 text-sm mt-2 mb-6">
        <?php if ($isAdminUser): ?>
            Creá el primero para empezar a organizar las tareas del equipo.
        <?php elseif ($canCreate): ?>
            Todavía no hay grupos en tus sectores. ¡Podés crear el primero!
        <?php else: ?>
            Aún no tenés sectores asignados. Contactá a un administrador.
        <?php endif; ?>
    </p>
    <?php if ($canCreate): ?>
    <button onclick="openModal('modal-board')" class="btn-primary">
        <i class="fa-solid fa-plus"></i> Crear primer grupo
    </button>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">
    <?php foreach ($boards as $b):
        $canEditBoard = $isAdminUser || ($b['created_by'] == $user['id']);
    ?>
    <div class="card overflow-hidden" style="border-top:4px solid <?= htmlspecialchars($b['color']) ?>">
        <div class="p-5">
            <div class="flex items-start justify-between mb-3">
                <div style="flex:1;min-width:0">
                    <h3 class="font-jakarta font-bold text-gray-900 text-base truncate"><?= htmlspecialchars($b['name']) ?></h3>
                    <?php if ($b['cat_name']): ?>
                    <span class="badge mt-1.5" style="background:<?= htmlspecialchars($b['cat_color']) ?>20;color:<?= htmlspecialchars($b['cat_color']) ?>">
                        <i class="fa-solid fa-layer-group" style="font-size:9px"></i> <?= htmlspecialchars($b['cat_name']) ?>
                    </span>
                    <?php else: ?>
                    <span class="badge mt-1.5" style="background:#fef3c7;color:#92400e">
                        <i class="fa-solid fa-triangle-exclamation" style="font-size:9px"></i> Sin sector
                    </span>
                    <?php endif; ?>
                </div>
                <?php if ($canEditBoard): ?>
                <div class="flex gap-1 flex-shrink-0 ml-2">
                    <button onclick="openEditBoard(<?= $b['id'] ?>, '<?= htmlspecialchars(addslashes($b['name'])) ?>', '<?= htmlspecialchars(addslashes($b['description'] ?? '')) ?>', '<?= $b['category_id'] ?? '' ?>', '<?= htmlspecialchars($b['color']) ?>')"
                            class="btn-ghost text-xs" style="padding:5px 9px" title="Editar grupo">
                        <i class="fa-solid fa-pen"></i>
                    </button>
                    <form method="POST" style="display:inline;margin:0"
                          onsubmit="return confirm('¿Eliminar el grupo \'<?= htmlspecialchars(addslashes($b['name'])) ?>\'?\n\nSe eliminarán TODAS las tareas dentro de él. Esta acción no se puede deshacer.')">
                        <input type="hidden" name="_action"  value="delete">
                        <input type="hidden" name="board_id" value="<?= $b['id'] ?>">
                        <button type="submit" class="btn-danger text-xs" style="padding:5px 9px" title="Eliminar grupo">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($b['description']): ?>
            <p class="text-gray-500 text-sm mb-3 line-clamp-2"><?= htmlspecialchars($b['description']) ?></p>
            <?php endif; ?>

            <div class="flex gap-4 mb-4 text-sm text-gray-500">
                <span><i class="fa-solid fa-list-check mr-1 text-xs" style="color:#0099cd"></i><strong class="text-gray-800"><?= $b['note_count'] ?></strong> tarea<?= $b['note_count']!=1?'s':'' ?></span>
                <span><i class="fa-solid fa-users mr-1 text-xs" style="color:#162259"></i><strong class="text-gray-800"><?= $b['member_count'] ?></strong> miembro<?= $b['member_count']!=1?'s':'' ?></span>
            </div>

            <a href="<?= BASE_URL ?>/board.php?id=<?= $b['id'] ?>" class="btn-primary w-full justify-center" style="font-size:15px;padding:11px">
                <i class="fa-solid fa-arrow-right-to-bracket"></i> Ver tareas
            </a>
        </div>
        <div style="padding:8px 20px;background:#fafafa;border-top:1px solid #f3f4f6">
            <span class="text-xs text-gray-400">Creado <?= date('d/m/Y', strtotime($b['created_at'])) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Modal: Nuevo grupo -->
<?php if ($canCreate): ?>
<div id="modal-board" class="modal-overlay" style="display:none">
    <div class="modal-box">
        <div class="flex items-center justify-between mb-5">
            <h3 class="font-jakarta font-bold text-gray-900 text-lg">Nuevo grupo de tareas</h3>
            <button onclick="closeModal('modal-board')" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="_action" value="create">
            <div>
                <label class="form-label">Nombre *</label>
                <input type="text" name="name" class="form-input" placeholder="Ej: Seguimiento de clientes" required maxlength="150">
            </div>
            <div>
                <label class="form-label">Descripción</label>
                <textarea name="description" class="form-input" rows="2" placeholder="Descripción breve..."></textarea>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Sector</label>
                    <select name="category_id" class="form-select">
                        <option value="">Sin sector</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Color</label>
                    <input type="color" name="color" value="#162259" class="form-input" style="padding:4px;height:42px">
                </div>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeModal('modal-board')" class="btn-ghost flex-1">Cancelar</button>
                <button type="submit" class="btn-primary flex-1"><i class="fa-solid fa-plus"></i> Crear grupo</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Editar grupo -->
<div id="modal-edit-board" class="modal-overlay" style="display:none">
    <div class="modal-box">
        <div class="flex items-center justify-between mb-5">
            <h3 class="font-jakarta font-bold text-gray-900 text-lg">Editar grupo de tareas</h3>
            <button onclick="closeModal('modal-edit-board')" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="_action" value="edit">
            <input type="hidden" name="board_id" id="edit-board-id" value="">
            <div>
                <label class="form-label">Nombre *</label>
                <input type="text" name="name" id="edit-board-name" class="form-input" required maxlength="150">
            </div>
            <div>
                <label class="form-label">Descripción</label>
                <textarea name="description" id="edit-board-desc" class="form-input" rows="2"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Sector</label>
                    <select name="category_id" id="edit-board-cat" class="form-select">
                        <option value="">Sin sector</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Color</label>
                    <input type="color" name="color" id="edit-board-color" value="#162259" class="form-input" style="padding:4px;height:42px">
                </div>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeModal('modal-edit-board')" class="btn-ghost flex-1">Cancelar</button>
                <button type="submit" class="btn-primary flex-1"><i class="fa-solid fa-floppy-disk"></i> Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
const BASE_URL = '<?= BASE_URL ?>';

<?php if ($showModal && $canCreate): ?>openModal('modal-board');<?php endif; ?>

function openEditBoard(id, name, desc, catId, color) {
    document.getElementById('edit-board-id').value    = id;
    document.getElementById('edit-board-name').value  = name;
    document.getElementById('edit-board-desc').value  = desc;
    document.getElementById('edit-board-color').value = color;
    const sel = document.getElementById('edit-board-cat');
    sel.value = catId || '';
    openModal('modal-edit-board');
}
</script>
<?php include __DIR__ . '/includes/layout_end.php'; ?>
