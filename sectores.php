<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();
if (!isAdmin()) { header('Location: ' . BASE_URL . '/index.php'); exit; }
$user    = currentUser();
$success = $error = '';

// ── Helper: asegurar que un usuario habilitado exista en app_users ─────────
function ensureUserInAppUsers(int $prolegalId, string $apiToken, int $addedBy): void {
    global $pdo;
    $stmt = $pdo->prepare("SELECT prolegal_id, is_active FROM app_users WHERE prolegal_id = ? LIMIT 1");
    $stmt->execute([$prolegalId]);
    $existing = $stmt->fetch();

    if (!$existing) {
        $apiData = prolegalGetUserById($prolegalId, $apiToken);
        $pdo->prepare(
            "INSERT IGNORE INTO app_users (prolegal_id, is_active, is_admin, added_by, cached_name, cached_email, cached_photo)
             VALUES (?, 1, 0, ?, ?, ?, ?)"
        )->execute([
            $prolegalId, $addedBy,
            $apiData['name'] ?? '', $apiData['email'] ?? '', $apiData['profile_photo'] ?? '',
        ]);
    } elseif (!$existing['is_active']) {
        $pdo->prepare("UPDATE app_users SET is_active = 1 WHERE prolegal_id = ?")->execute([$prolegalId]);
    }
}

// ── Acciones POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['_action'] ?? '';

    if ($a === 'create') {
        $name  = trim($_POST['name']  ?? '');
        $color = $_POST['color'] ?? '#162259';
        if ($name) {
            $pdo->prepare("INSERT INTO board_categories (name, color) VALUES (?,?)")->execute([$name, $color]);
            $newCatId = $pdo->lastInsertId();
            $selectedUsers = $_POST['user_ids'] ?? [];
            foreach ($selectedUsers as $uid) {
                $uid = intval($uid);
                if ($uid) {
                    $pdo->prepare("INSERT IGNORE INTO category_users (category_id, prolegal_id, added_by) VALUES (?,?,?)")
                        ->execute([$newCatId, $uid, $user['id']]);
                }
            }
            $success = 'Sector creado correctamente.';
        } else {
            $error = 'El nombre es obligatorio.';
        }

    } elseif ($a === 'edit') {
        $id    = intval($_POST['id']    ?? 0);
        $name  = trim($_POST['name']   ?? '');
        $color = $_POST['color'] ?? '#162259';
        if ($id && $name) {
            $pdo->prepare("UPDATE board_categories SET name=?, color=? WHERE id=?")->execute([$name, $color, $id]);
            $success = 'Sector actualizado correctamente.';
        } else {
            $error = 'Datos inválidos.';
        }

    } elseif ($a === 'add_user') {
        $catId      = intval($_POST['category_id'] ?? 0);
        $prolegalId = intval($_POST['prolegal_id']  ?? 0);
        if ($catId && $prolegalId) {
            // Verificar que el usuario existe en app_users y está activo
            $checkUser = $pdo->prepare("SELECT prolegal_id FROM app_users WHERE prolegal_id=? AND is_active=1 LIMIT 1");
            $checkUser->execute([$prolegalId]);
            if (!$checkUser->fetch()) {
                $error = 'El usuario no está habilitado en el sistema.';
            } else {
                $pdo->prepare("INSERT IGNORE INTO category_users (category_id, prolegal_id, added_by) VALUES (?,?,?)")
                    ->execute([$catId, $prolegalId, $user['id']]);
                // Auto-agregar a todos los grupos activos del sector
                $boardsInSector = $pdo->prepare("SELECT id FROM boards WHERE category_id = ? AND is_archived = 0");
                $boardsInSector->execute([$catId]);
                $addMember = $pdo->prepare("INSERT IGNORE INTO board_members (board_id, prolegal_id, added_by) VALUES (?,?,?)");
                foreach ($boardsInSector->fetchAll(PDO::FETCH_COLUMN) as $bid) {
                    $addMember->execute([$bid, $prolegalId, $user['id']]);
                }
                $success = 'Usuario asociado al sector.';
            }
        }

    } elseif ($a === 'remove_user') {
        $catId      = intval($_POST['category_id'] ?? 0);
        $prolegalId = intval($_POST['prolegal_id']  ?? 0);
        if ($catId && $prolegalId) {
            $pdo->prepare("DELETE FROM category_users WHERE category_id=? AND prolegal_id=?")->execute([$catId, $prolegalId]);
            $success = 'Usuario desvinculado del sector.';
        }

    } elseif ($a === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        // Verificar si tiene grupos de tareas activos
        $boardsStmt = $pdo->prepare("SELECT name FROM boards WHERE category_id=? AND is_archived=0");
        $boardsStmt->execute([$id]);
        $activeBoards = $boardsStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($activeBoards)) {
            $listHtml = implode(', ', array_map('htmlspecialchars', $activeBoards));
            $error = 'No se puede eliminar este sector porque tiene grupos de tareas activos: <strong>' . $listHtml . '</strong>. Eliminá o archivá esos grupos primero.';
        } else {
            $pdo->prepare("DELETE FROM board_categories WHERE id=?")->execute([$id]);
            $success = 'Sector eliminado.';
        }
    }
}

// ── Datos ─────────────────────────────────────────────────────────────────────
$cats = $pdo->query("
    SELECT bc.*,
           COUNT(DISTINCT b.id) AS board_count
    FROM board_categories bc
    LEFT JOIN boards b ON b.category_id = bc.id AND b.is_archived = 0
    GROUP BY bc.id
    ORDER BY bc.name ASC
")->fetchAll();

// Usuarios de cada sector
$catUsers = [];
$cuRows = $pdo->query("
    SELECT cu.category_id, cu.prolegal_id,
           au.cached_name AS name, au.cached_photo AS photo, au.cached_email AS email
    FROM category_users cu
    JOIN app_users au ON au.prolegal_id = cu.prolegal_id
    ORDER BY au.cached_name ASC
")->fetchAll();
foreach ($cuRows as $row) {
    $catUsers[$row['category_id']][] = $row;
}

// SOLO usuarios habilitados en el sistema (app_users con is_active=1)
// No se usa la API de Prolegal para evitar mostrar usuarios no habilitados
$allUsers = getInvitableUsers();

$pageTitle = 'Sectores';
include __DIR__ . '/includes/layout.php';
?>

<?php if ($error):   ?>
<div class="mb-5 flex gap-3 p-4 rounded-xl text-sm" style="background:#fef2f2;border:1px solid #fecaca;color:#dc2626">
    <i class="fa-solid fa-circle-exclamation mt-0.5 flex-shrink-0"></i>
    <span><?= $error ?></span>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="mb-5 flex gap-3 p-4 rounded-xl text-sm" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46">
    <i class="fa-solid fa-circle-check mt-0.5 flex-shrink-0"></i>
    <?= htmlspecialchars($success) ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

    <!-- Lista de sectores -->
    <div class="xl:col-span-2 space-y-4">
        <?php if (empty($cats)): ?>
        <div class="card p-16 text-center">
            <i class="fa-solid fa-tags text-5xl mb-4" style="color:#e5e7eb"></i>
            <p class="text-gray-400">Sin sectores creados</p>
        </div>
        <?php endif; ?>

        <?php foreach ($cats as $c):
            $users = $catUsers[$c['id']] ?? [];
            $assignedIds    = array_column($users, 'prolegal_id');
            $availableUsers = array_filter($allUsers, fn($u) => !in_array($u['id'], $assignedIds));
            $hasNoBoards    = ($c['board_count'] == 0);
        ?>
        <div class="card" style="border-left:4px solid <?= htmlspecialchars($c['color']) ?>;overflow:visible">
            <!-- Warning: sector sin grupos -->
            <?php if ($hasNoBoards): ?>
            <div style="background:#fffbeb;border-bottom:1px solid #fde68a;padding:9px 20px;display:flex;align-items:center;gap:8px;border-radius:0">
                <i class="fa-solid fa-triangle-exclamation" style="color:#d97706;font-size:13px;flex-shrink:0"></i>
                <p class="text-xs" style="color:#92400e">
                    Este sector no tiene grupos de tareas.
                    <a href="<?= BASE_URL ?>/grupos.php?new=1" style="color:#0099cd;font-weight:600;text-decoration:underline">Crear un grupo ahora</a>
                </p>
            </div>
            <?php endif; ?>
            <!-- Header del sector -->
            <div class="flex items-center justify-between p-5 pb-4">
                <div class="flex items-center gap-3">
                    <div style="width:18px;height:18px;border-radius:5px;background:<?= htmlspecialchars($c['color']) ?>;flex-shrink:0"></div>
                    <div>
                        <h3 class="font-jakarta font-bold text-gray-900"><?= htmlspecialchars($c['name']) ?></h3>
                        <p class="text-xs text-gray-400 mt-0.5">
                            <?= $c['board_count'] ?> grupo<?= $c['board_count']!=1?'s':'' ?> ·
                            <?= count($users) ?> usuario<?= count($users)!=1?'s':'' ?>
                        </p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <!-- Editar sector -->
                    <button onclick="openEditModal(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name'])) ?>', '<?= htmlspecialchars($c['color']) ?>')"
                            class="btn-ghost text-xs" style="padding:5px 10px" title="Editar sector">
                        <i class="fa-solid fa-pen"></i>
                    </button>
                    <!-- Eliminar sector (siempre visible, pero con validación) -->
                    <form method="POST" onsubmit="return confirm('¿Eliminar el sector \'<?= htmlspecialchars(addslashes($c['name'])) ?>\'? Esta acción no se puede deshacer.')">
                        <input type="hidden" name="_action" value="delete">
                        <input type="hidden" name="id"      value="<?= $c['id'] ?>">
                        <button type="submit" class="btn-danger text-xs" style="padding:5px 10px" title="Eliminar sector">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Usuarios asociados -->
            <?php if (!empty($users)): ?>
            <div style="padding:0 20px 14px">
                <p class="form-label mb-3" style="font-size:11px;color:#9ca3af;letter-spacing:.5px">USUARIOS ASOCIADOS</p>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($users as $u): ?>
                    <div class="flex items-center gap-2 pl-1 pr-2 py-1 rounded-full"
                         style="background:#f8fafc;border:1.5px solid #e5e7eb">
                        <img src="<?= htmlspecialchars(userPhotoUrl($u['photo']??'')) ?>"
                             class="w-7 h-7 rounded-full object-cover flex-shrink-0"
                             title="<?= htmlspecialchars($u['email']??'') ?>"
                             onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
                        <span class="text-xs font-medium text-gray-700"><?= htmlspecialchars($u['name'] ?: 'ID #'.$u['prolegal_id']) ?></span>
                        <form method="POST" style="display:inline;margin:0">
                            <input type="hidden" name="_action"     value="remove_user">
                            <input type="hidden" name="category_id" value="<?= $c['id'] ?>">
                            <input type="hidden" name="prolegal_id" value="<?= $u['prolegal_id'] ?>">
                            <button type="submit" title="Desvincular"
                                    style="background:none;border:none;cursor:pointer;color:#d1d5db;padding:0 2px;line-height:1"
                                    class="hover:text-red-400 transition-colors"
                                    onclick="return confirm('¿Desvincular a <?= htmlspecialchars(addslashes($u['name']??'este usuario')) ?> del sector?')">
                                <i class="fa-solid fa-xmark text-xs"></i>
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Agregar usuario al sector (solo usuarios habilitados) -->
            <?php if (!empty($availableUsers)): ?>
            <div style="border-top:1px solid #f3f4f6;padding:12px 20px;background:#fafafa;position:relative">
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs" style="pointer-events:none"></i>
                    <input type="text"
                           class="form-input text-xs"
                           style="padding:7px 10px 7px 30px"
                           placeholder="Buscar usuario para asociar..."
                           autocomplete="off"
                           oninput="filterSectorUsers(this, <?= $c['id'] ?>)"
                           data-assigned="<?= htmlspecialchars(json_encode(array_values($assignedIds))) ?>">
                    <div id="sector-results-<?= $c['id'] ?>"
                         style="display:none;position:absolute;left:0;right:0;top:calc(100% + 4px);
                                background:white;border:1.5px solid #e5e7eb;border-radius:12px;
                                box-shadow:0 8px 24px rgba(0,0,0,0.12);
                                max-height:220px;overflow-y:auto;z-index:200">
                    </div>
                </div>
                <form method="POST" id="add-user-form-<?= $c['id'] ?>" style="display:none">
                    <input type="hidden" name="_action"     value="add_user">
                    <input type="hidden" name="category_id" value="<?= $c['id'] ?>">
                    <input type="hidden" name="prolegal_id" id="add-user-pid-<?= $c['id'] ?>" value="">
                </form>
            </div>
            <?php else: ?>
            <div style="border-top:1px solid #f3f4f6;padding:10px 20px;background:#fafafa">
                <p class="text-xs text-gray-400">Todos los usuarios habilitados ya están asociados a este sector.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Panel: nuevo sector -->
    <div>
        <div class="card p-6">
            <h3 class="font-jakarta font-bold text-gray-900 mb-1">Nuevo sector</h3>
            <p class="text-xs text-gray-500 mb-5">Podés asociar usuarios desde el inicio</p>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="_action" value="create">
                <div>
                    <label class="form-label">Nombre *</label>
                    <input type="text" name="name" class="form-input" required maxlength="100" placeholder="Ej: Ventas">
                </div>
                <div>
                    <label class="form-label">Color identificador</label>
                    <input type="color" name="color" value="#162259" class="form-input" style="padding:4px;height:42px">
                </div>
                <div>
                    <label class="form-label">Usuarios asociados</label>
                    <p class="text-xs text-gray-400 mb-2">Solo aparecen usuarios habilitados en el sistema</p>
                    <div class="relative mb-2">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                        <input type="text" class="form-input text-sm"
                               style="padding:8px 12px 8px 32px"
                               placeholder="Filtrar por nombre o email..."
                               oninput="filterNewSectorUsers(this.value)">
                    </div>
                    <div id="new-sector-users-list"
                         class="space-y-1 overflow-y-auto rounded-xl"
                         style="border:1.5px solid #e5e7eb;padding:8px;max-height:220px">
                        <?php foreach ($allUsers as $u): ?>
                        <label class="new-sector-item flex items-center gap-3 cursor-pointer p-2 rounded-lg hover:bg-gray-50 transition-colors"
                               data-search="<?= htmlspecialchars(strtolower(($u['name'] ?? '') . ' ' . ($u['email'] ?? ''))) ?>">
                            <input type="checkbox" name="user_ids[]" value="<?= $u['id'] ?>"
                                   style="accent-color:#162259;width:15px;height:15px;flex-shrink:0">
                            <img src="<?= htmlspecialchars(userPhotoUrl($u['profile_photo']??'')) ?>"
                                 class="w-7 h-7 rounded-full object-cover flex-shrink-0"
                                 onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
                            <div style="min-width:0">
                                <p class="text-sm font-medium text-gray-800 truncate"><?= htmlspecialchars($u['name'] ?: 'Usuario #'.$u['id']) ?></p>
                                <p class="text-xs text-gray-400 truncate"><?= htmlspecialchars($u['email']??'') ?></p>
                            </div>
                        </label>
                        <?php endforeach; ?>
                        <?php if (empty($allUsers)): ?>
                        <p class="text-xs text-gray-400 p-2">No hay usuarios habilitados aún. Habilitá usuarios en la sección Usuarios primero.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="submit" class="btn-primary w-full">
                    <i class="fa-solid fa-plus"></i> Crear sector
                </button>
            </form>
        </div>

        <div class="card p-4 mt-4" style="background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border:1px solid #bae6fd">
            <p class="text-xs font-bold mb-1" style="color:#0369a1"><i class="fa-solid fa-circle-info mr-1"></i>¿Cómo funciona?</p>
            <p class="text-xs leading-relaxed" style="color:#0369a1">
                Jerarquía: <strong>Sector → Grupo de tareas → Tarea</strong>. Al asociar un usuario a un sector, ese sector y sus grupos de tareas aparecen directamente en su vista.
            </p>
        </div>
    </div>
</div>

<!-- Modal: Editar sector -->
<div id="modal-edit-sector" class="modal-overlay" style="display:none">
    <div class="modal-box" style="max-width:400px">
        <div class="flex items-center justify-between mb-5">
            <h3 class="font-jakarta font-bold text-gray-900 text-lg">Editar sector</h3>
            <button onclick="closeModal('modal-edit-sector')" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="_action" value="edit">
            <input type="hidden" name="id" id="edit-sector-id" value="">
            <div>
                <label class="form-label">Nombre *</label>
                <input type="text" name="name" id="edit-sector-name" class="form-input" required maxlength="100">
            </div>
            <div>
                <label class="form-label">Color identificador</label>
                <input type="color" name="color" id="edit-sector-color" value="#162259" class="form-input" style="padding:4px;height:42px">
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeModal('modal-edit-sector')" class="btn-ghost flex-1">Cancelar</button>
                <button type="submit" class="btn-primary flex-1"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
const BASE_URL = '<?= BASE_URL ?>';

// Lista de usuarios habilitados (solo app_users activos)
const ALL_USERS = <?= json_encode(array_values($allUsers)) ?>;

// ── Filtro para checkboxes en "Nuevo sector" ──────────────────────────────────
function filterNewSectorUsers(q) {
    q = q.trim().toLowerCase();
    document.querySelectorAll('#new-sector-users-list .new-sector-item').forEach(el => {
        const text = el.dataset.search || '';
        el.style.display = (!q || text.includes(q)) ? '' : 'none';
    });
}

// ── Buscador para agregar usuario a sector existente ─────────────────────────
let sectorSearchTimers = {};

function filterSectorUsers(input, catId) {
    clearTimeout(sectorSearchTimers[catId]);
    const q         = input.value.trim().toLowerCase();
    const container = document.getElementById('sector-results-' + catId);
    const assigned  = JSON.parse(input.dataset.assigned || '[]').map(Number);

    if (q.length < 2) { container.style.display = 'none'; return; }

    sectorSearchTimers[catId] = setTimeout(() => {
        const matches = ALL_USERS.filter(u =>
            !assigned.includes(Number(u.id)) &&
            ((u.name  && u.name.toLowerCase().includes(q)) ||
             (u.email && u.email.toLowerCase().includes(q)))
        ).slice(0, 12);

        if (!matches.length) {
            container.innerHTML = '<div style="padding:12px;text-align:center;font-size:12px;color:#9ca3af">Sin resultados para "' + escHtml(q) + '"</div>';
            container.style.display = 'block';
            return;
        }

        container.innerHTML = matches.map(u => `
            <div class="sector-result-item"
                 style="display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;border-bottom:1px solid #f3f4f6;transition:background .12s"
                 onmouseenter="this.style.background='#eff6ff'"
                 onmouseleave="this.style.background=''"
                 onclick="selectSectorUser(${catId}, ${u.id})">
                <img src="${u.profile_photo ? 'https://prolegal.com.ar/storage/' + u.profile_photo : BASE_URL + '/assets/img/avatar-default.svg'}"
                     style="width:30px;height:30px;border-radius:50%;object-fit:cover;flex-shrink:0"
                     onerror="this.src='${BASE_URL}/assets/img/avatar-default.svg'">
                <div style="min-width:0;flex:1">
                    <p style="font-size:13px;font-weight:600;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escHtml(u.name || 'Usuario #' + u.id)}</p>
                    <p style="font-size:11px;color:#9ca3af;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escHtml(u.email || '')}</p>
                </div>
                <span style="font-size:11px;color:#0099cd;font-weight:600;flex-shrink:0">Asociar</span>
            </div>
        `).join('');

        container.style.display = 'block';
    }, 200);
}

function selectSectorUser(catId, userId) {
    document.getElementById('add-user-pid-' + catId).value = userId;
    document.getElementById('add-user-form-' + catId).submit();
}

// ── Modal editar sector ───────────────────────────────────────────────────────
function openEditModal(id, name, color) {
    document.getElementById('edit-sector-id').value    = id;
    document.getElementById('edit-sector-name').value  = name;
    document.getElementById('edit-sector-color').value = color;
    openModal('modal-edit-sector');
}

// Cerrar dropdowns al hacer clic fuera
document.addEventListener('click', e => {
    if (!e.target.closest('[data-assigned]') && !e.target.closest('[id^="sector-results-"]')) {
        document.querySelectorAll('[id^="sector-results-"]').forEach(el => el.style.display = 'none');
    }
});

function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
<?php include __DIR__ . '/includes/layout_end.php'; ?>
