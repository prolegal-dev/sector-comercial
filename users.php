<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();
$user = currentUser();
if (!isAdmin()) { header('Location: ' . BASE_URL . '/index.php'); exit; }

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['_action'] ?? '';

    if ($a === 'add_user') {
        $prolegalId = intval($_POST['prolegal_id'] ?? 0);
        $isAdm      = intval($_POST['is_admin']    ?? 0);
        if (!$prolegalId) {
            $error = 'ID inválido.';
        } else {
            $exists = $pdo->prepare("SELECT id, is_active FROM app_users WHERE prolegal_id=?");
            $exists->execute([$prolegalId]);
            $existing = $exists->fetch();

            if ($existing && $existing['is_active']) {
                $error = "El usuario #$prolegalId ya está habilitado.";
            } else {
                // Obtener datos frescos desde la API para rellenar el caché
                $apiUserData = prolegalGetUserById($prolegalId, $user['api_token']);

                if ($existing) {
                    $pdo->prepare("UPDATE app_users SET is_active=1, is_admin=?, added_by=? WHERE prolegal_id=?")
                        ->execute([$isAdm, $user['id'], $prolegalId]);
                } else {
                    $pdo->prepare("INSERT INTO app_users (prolegal_id, is_admin, added_by) VALUES (?,?,?)")
                        ->execute([$prolegalId, $isAdm, $user['id']]);
                }

                // Actualizar caché con datos de la API
                if ($apiUserData) {
                    $pdo->prepare("UPDATE app_users SET cached_name=?, cached_email=?, cached_photo=? WHERE prolegal_id=?")
                        ->execute([$apiUserData['name'], $apiUserData['email'], $apiUserData['profile_photo'], $prolegalId]);
                    $success = 'Usuario ' . htmlspecialchars($apiUserData['name']) . ($existing ? ' re-habilitado.' : ' agregado.');
                } else {
                    $success = "Usuario #$prolegalId " . ($existing ? 're-habilitado' : 'agregado') . '. Sus datos se completarán en su próximo login.';
                }
            }
        }

    } elseif ($a === 'toggle_user') {
        $prolegalId = intval($_POST['prolegal_id'] ?? 0);
        $active     = intval($_POST['active']      ?? 0);
        if ($prolegalId && $prolegalId !== $user['id']) {
            $pdo->prepare("UPDATE app_users SET is_active=? WHERE prolegal_id=?")->execute([$active, $prolegalId]);
            $success = 'Usuario ' . ($active ? 'habilitado' : 'deshabilitado') . '.';
        } else { $error = 'No podés modificar tu propia cuenta.'; }

    } elseif ($a === 'toggle_admin') {
        $prolegalId = intval($_POST['prolegal_id'] ?? 0);
        $adm        = intval($_POST['is_admin']    ?? 0);
        if ($prolegalId && $prolegalId !== $user['id']) {
            $pdo->prepare("UPDATE app_users SET is_admin=? WHERE prolegal_id=?")->execute([$adm, $prolegalId]);
            $success = 'Rol actualizado.';
        }
    }
}

// Cargar lista de usuarios habilitados
$appUsers = getAllowedUsers();

// Cargar lista completa de usuarios desde la API de Prolegal para el buscador
// Se usa la versión con caché de sesión (10 min) para evitar 35+ requests HTTP en cada carga
$prolegalUsers     = prolegalListUsersCached($user['api_token']);
$prolegalUsersById = [];
foreach ($prolegalUsers as $pu) {
    $prolegalUsersById[$pu['id']] = $pu;
}

// Enriquecer appUsers con datos frescos de la API (si están disponibles)
foreach ($appUsers as &$au) {
    if (isset($prolegalUsersById[$au['id']])) {
        $fresh = $prolegalUsersById[$au['id']];
        // Si el caché está vacío, usar datos de la API
        if (empty($au['name'])  && !empty($fresh['name']))  $au['name']          = $fresh['name'];
        if (empty($au['email']) && !empty($fresh['email'])) $au['email']         = $fresh['email'];
        if (empty($au['profile_photo']) && !empty($fresh['profile_photo'])) $au['profile_photo'] = $fresh['profile_photo'];
    }
}
unset($au);

// IDs ya habilitados (para excluirlos del buscador)
$enabledIds = array_column($appUsers, 'id');

$pageTitle = 'Usuarios';
include __DIR__ . '/includes/layout.php';
?>

<?php if ($error):   ?><div class="mb-5 flex gap-3 p-4 rounded-xl text-sm" style="background:#fef2f2;border:1px solid #fecaca;color:#dc2626"><i class="fa-solid fa-circle-exclamation mt-0.5 flex-shrink-0"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="mb-5 flex gap-3 p-4 rounded-xl text-sm" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46"><i class="fa-solid fa-circle-check mt-0.5 flex-shrink-0"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Lista de usuarios habilitados -->
    <div class="lg:col-span-2 card overflow-hidden">
        <div style="padding:18px 20px;border-bottom:1.5px solid #f3f4f6">
            <h3 class="font-jakarta font-bold text-gray-900">Usuarios habilitados</h3>
            <p class="text-sm text-gray-500 mt-0.5"><?= count($appUsers) ?> en el sistema</p>
        </div>
        <div class="divide-y divide-gray-100">
            <?php foreach ($appUsers as $u): ?>
            <div class="flex items-center gap-4 p-4">
                <div class="relative flex-shrink-0">
                    <img src="<?= htmlspecialchars(userPhotoUrl($u['profile_photo']??'')) ?>"
                         class="w-11 h-11 rounded-full object-cover"
                         style="border:2.5px solid <?= $u['is_active']?'#10b981':'#e5e7eb' ?>"
                         onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
                </div>
                <div style="flex:1;min-width:0">
                    <p class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars($u['name'] ?: 'Usuario #'.$u['id']) ?></p>
                    <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($u['email']??'') ?></p>
                    <div class="flex gap-2 mt-1.5 flex-wrap">
                        <span class="badge <?= $u['is_active']?'badge-green':'badge-gray' ?>"><?= $u['is_active']?'Activo':'Inactivo' ?></span>
                        <?php if ($u['is_admin']): ?><span class="badge badge-navy">Admin</span><?php endif; ?>
                        <span class="badge badge-gray">ID #<?= $u['id'] ?></span>
                    </div>
                </div>
                <?php if ($u['id'] != $user['id']): ?>
                <div class="flex gap-2 flex-shrink-0">
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="_action" value="toggle_admin">
                        <input type="hidden" name="prolegal_id" value="<?= $u['id'] ?>">
                        <input type="hidden" name="is_admin"    value="<?= $u['is_admin']?0:1 ?>">
                        <button type="submit" class="btn-ghost text-xs" style="padding:6px 10px" title="<?= $u['is_admin']?'Quitar admin':'Hacer admin' ?>">
                            <i class="fa-solid fa-<?= $u['is_admin']?'user-minus':'user-shield' ?>"></i>
                        </button>
                    </form>
                    <form method="POST" style="display:inline" onsubmit="return confirm('<?= $u['is_active']?'¿Deshabilitar?':'¿Habilitar?' ?>')">
                        <input type="hidden" name="_action" value="toggle_user">
                        <input type="hidden" name="prolegal_id" value="<?= $u['id'] ?>">
                        <input type="hidden" name="active"      value="<?= $u['is_active']?0:1 ?>">
                        <button type="submit" class="<?= $u['is_active']?'btn-danger':'btn-primary' ?> text-xs" style="padding:6px 12px">
                            <i class="fa-solid fa-<?= $u['is_active']?'ban':'check' ?>"></i>
                            <?= $u['is_active']?'Deshabilitar':'Habilitar' ?>
                        </button>
                    </form>
                </div>
                <?php else: ?>
                <span class="badge badge-cyan flex-shrink-0">Vos</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Panel agregar usuario -->
    <div class="space-y-4">
        <div class="card p-6">
            <h3 class="font-jakarta font-bold text-gray-900 mb-1">Agregar usuario</h3>
            <p class="text-xs text-gray-500 mb-4">Buscá por nombre o email en Prolegal</p>

            <!-- Buscador en tiempo real contra la lista de la API -->
            <div class="mb-4">
                <label class="form-label">Buscar usuario</label>
                <div class="relative">
                    <input type="text" id="user-search" class="form-input" placeholder="Nombre o email..."
                           autocomplete="off" style="padding-left:36px" oninput="filterUsers(this.value)">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                </div>
                <div id="search-results" class="mt-1 rounded-xl border border-gray-200 overflow-hidden hidden"
                     style="max-height:240px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,0.1)">
                </div>
            </div>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="_action" value="add_user">
                <div>
                    <label class="form-label">ID en Prolegal *</label>
                    <input type="number" name="prolegal_id" id="selected-id" class="form-input" placeholder="Ej: 528" min="1" required>
                    <!-- Preview del usuario seleccionado -->
                    <div id="user-preview" class="hidden mt-2 p-3 rounded-xl flex items-center gap-3"
                         style="background:#f0f9ff;border:1px solid #bae6fd">
                        <img id="preview-photo" src="" class="w-8 h-8 rounded-full object-cover flex-shrink-0"
                             onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
                        <div style="min-width:0">
                            <p id="preview-name"  class="text-sm font-semibold text-gray-800 truncate"></p>
                            <p id="preview-email" class="text-xs text-gray-500 truncate"></p>
                        </div>
                    </div>
                </div>
                <label class="flex items-center gap-3 cursor-pointer p-3 rounded-xl hover:bg-gray-50">
                    <input type="checkbox" name="is_admin" value="1" style="accent-color:#162259;width:16px;height:16px">
                    <div>
                        <span class="text-sm font-medium text-gray-700">Rol de administrador</span>
                        <p class="text-xs text-gray-400">Crea tableros, sectores y gestiona usuarios</p>
                    </div>
                </label>
                <button type="submit" class="btn-primary w-full">
                    <i class="fa-solid fa-user-plus"></i> Agregar al sistema
                </button>
            </form>
        </div>

        <!-- Info -->
        <div class="card p-4" style="background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border:1px solid #bae6fd">
            <p class="text-xs font-bold mb-1" style="color:#0369a1">
                <i class="fa-solid fa-users mr-1"></i><?= count($prolegalUsers) ?> usuarios en Prolegal
            </p>
            <p class="text-xs leading-relaxed" style="color:#0369a1">
                Los datos (nombre, foto) se sincronizan automáticamente al agregar y en cada login.
            </p>
        </div>
    </div>
</div>

<script>
const BASE_URL = '<?= BASE_URL ?>';

// Lista de usuarios de Prolegal disponibles (no habilitados aún)
const PROLEGAL_USERS = <?= json_encode(array_values(array_filter(
    $prolegalUsers,
    fn($u) => !in_array($u['id'], $enabledIds) && $u['status'] == 1
))) ?>;

// IDs ya habilitados
const ENABLED_IDS = <?= json_encode($enabledIds) ?>;

function filterUsers(q) {
    const container = document.getElementById('search-results');
    q = q.trim().toLowerCase();

    if (q.length < 2) {
        container.classList.add('hidden');
        return;
    }

    const matches = PROLEGAL_USERS.filter(u =>
        (u.name  && u.name.toLowerCase().includes(q)) ||
        (u.email && u.email.toLowerCase().includes(q))
    ).slice(0, 12);

    if (!matches.length) {
        container.innerHTML = '<div class="p-3 text-xs text-gray-400 text-center">Sin resultados</div>';
        container.classList.remove('hidden');
        return;
    }

    container.innerHTML = matches.map(u => `
        <div class="flex items-center gap-3 p-3 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0 transition-colors"
             onclick="selectUser(${u.id}, '${esc(u.name)}', '${esc(u.email)}', '${esc(u.profile_photo || '')}')">
            <img src="${u.profile_photo ? 'https://prolegal.com.ar/storage/' + u.profile_photo : BASE_URL + '/assets/img/avatar-default.svg'}"
                 class="w-8 h-8 rounded-full object-cover flex-shrink-0"
                 onerror="this.src='${BASE_URL}/assets/img/avatar-default.svg'">
            <div style="min-width:0">
                <p class="text-sm font-semibold text-gray-800 truncate">${u.name}</p>
                <p class="text-xs text-gray-500 truncate">${u.email || ''} · ID #${u.id}</p>
            </div>
        </div>
    `).join('');

    container.classList.remove('hidden');
}

function selectUser(id, name, email, photo) {
    document.getElementById('selected-id').value     = id;
    document.getElementById('user-search').value     = name;
    document.getElementById('search-results').classList.add('hidden');

    document.getElementById('preview-photo').src     = photo
        ? 'https://prolegal.com.ar/storage/' + photo
        : BASE_URL + '/assets/img/avatar-default.svg';
    document.getElementById('preview-name').textContent  = name;
    document.getElementById('preview-email').textContent = email + ' · ID #' + id;
    document.getElementById('user-preview').classList.remove('hidden');
}

function esc(s) { return String(s||'').replace(/'/g, "\\'").replace(/"/g, '\\"'); }

// Cerrar al clickear afuera
document.addEventListener('click', e => {
    if (!e.target.closest('#user-search') && !e.target.closest('#search-results')) {
        document.getElementById('search-results').classList.add('hidden');
    }
});
</script>
<?php include __DIR__ . '/includes/layout_end.php'; ?>
