<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();
$user        = currentUser();
$isAdminUser = isAdmin($user['id']);

// Solo admins
if (!$isAdminUser) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// ── Cargar todos los usuarios activos con sus tareas fijas ─────────────────
// Traer todos los usuarios activos
$usersStmt = $pdo->query("
    SELECT prolegal_id, cached_name, cached_photo
    FROM app_users
    WHERE is_active = 1
    ORDER BY cached_name ASC
");
$allUsers = $usersStmt->fetchAll();

// Traer todas las tareas fijas de todos los usuarios
$tasksStmt = $pdo->query("
    SELECT prolegal_id, id, task, task_order
    FROM fixed_tasks
    ORDER BY prolegal_id ASC, task_order ASC, id ASC
");

// Indexar por prolegal_id
$tasksByUser = [];
foreach ($tasksStmt->fetchAll() as $row) {
    $pid = (int)$row['prolegal_id'];
    $tasksByUser[$pid][] = $row;
}

// Solo mostrar usuarios que tienen al menos 1 tarea o todos (según necesidad)
// Mostramos TODOS los usuarios activos
$totalUsers = count($allUsers);
$totalTasks = array_sum(array_map('count', $tasksByUser));
$usersWithTasks = count(array_filter($allUsers, fn($u) => !empty($tasksByUser[(int)$u['prolegal_id']])));

$pageTitle = 'Tareas Fijas — Vista de Administrador';
include __DIR__ . '/includes/layout.php';
?>

<style>
    .col-user {
        /* el ancho lo maneja el grid de 4 columnas */
    }
    .col-header {
        background: linear-gradient(135deg, #162259, #0f1a42);
        border-radius: 14px 14px 0 0;
        padding: 16px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .col-body {
        border: 1.5px solid #e5e7eb;
        border-top: none;
        border-radius: 0 0 14px 14px;
        background: white;
        min-height: 80px;
    }
    .task-item {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        padding: 9px 14px;
        border-bottom: 1px solid #f3f4f6;
        font-size: 13px;
        color: #374151;
        line-height: 1.4;
    }
    .task-item:last-child { border-bottom: none; }
    .task-item i { color: #0099cd; font-size: 9px; margin-top: 4px; flex-shrink: 0; }
    .empty-col {
        padding: 20px 14px;
        text-align: center;
        color: #d1d5db;
        font-size: 12px;
    }
    .scroll-wrapper {
        /* sin scroll horizontal — el grid se adapta */
    }
    .cols-container {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
    }
    @media (max-width: 1100px) { .cols-container { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 768px)  { .cols-container { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 480px)  { .cols-container { grid-template-columns: 1fr; } }
    /* Filtro */
    .filter-bar {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }
    #filter-input {
        border: 1.5px solid #e5e7eb;
        border-radius: 10px;
        padding: 8px 14px 8px 36px;
        font-size: 13px;
        outline: none;
        background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='M21 21l-4.35-4.35'/%3E%3C/svg%3E") no-repeat 10px center / 16px;
        width: 220px;
        transition: all .18s;
    }
    #filter-input:focus { border-color: #0099cd; box-shadow: 0 0 0 3px rgba(0,153,205,0.1); }
</style>

<!-- ── Encabezado ─────────────────────────────────────────────────────────── -->
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="font-jakarta font-bold text-gray-900 text-xl">Tareas Fijas — Vista de Administrador</h2>
        <p class="text-gray-400 text-sm mt-0.5">Todas las tareas fijas de los usuarios del sistema</p>
    </div>
    <a href="<?= BASE_URL ?>/mis-tareas-fijas.php" class="btn-ghost" style="font-size:13px;padding:8px 14px">
        <i class="fa-solid fa-thumbtack"></i> Mis tareas fijas
    </a>
</div>

<!-- ── Stats ─────────────────────────────────────────────────────────────── -->
<div class="grid grid-cols-3 gap-4 mb-6" style="max-width:480px">
    <div class="card p-4 text-center">
        <p class="font-jakarta font-extrabold text-gray-900" style="font-size:28px;line-height:1"><?= $totalUsers ?></p>
        <p class="text-gray-400 text-xs mt-1">Usuarios activos</p>
    </div>
    <div class="card p-4 text-center">
        <p class="font-jakarta font-extrabold text-gray-900" style="font-size:28px;line-height:1"><?= $usersWithTasks ?></p>
        <p class="text-gray-400 text-xs mt-1">Con tareas cargadas</p>
    </div>
    <div class="card p-4 text-center">
        <p class="font-jakarta font-extrabold" style="font-size:28px;line-height:1;color:#0099cd"><?= $totalTasks ?></p>
        <p class="text-gray-400 text-xs mt-1">Tareas totales</p>
    </div>
</div>

<!-- ── Filtro ─────────────────────────────────────────────────────────────── -->
<div class="filter-bar">
    <input type="text" id="filter-input" placeholder="Filtrar por usuario…" oninput="filterColumns(this.value)">
    <label class="flex items-center gap-2 text-sm text-gray-500 cursor-pointer select-none">
        <input type="checkbox" id="only-with-tasks" checked onchange="filterColumns(document.getElementById('filter-input').value)"
               class="rounded">
        Solo usuarios con tareas
    </label>
</div>

<!-- ── Columnas por usuario ───────────────────────────────────────────────── -->
<?php if (empty($allUsers)): ?>
<div class="card p-16 text-center">
    <i class="fa-solid fa-users text-4xl mb-3" style="color:#e5e7eb"></i>
    <p class="font-semibold text-gray-500">No hay usuarios activos en el sistema.</p>
</div>
<?php else: ?>

<div class="scroll-wrapper">
    <div class="cols-container" id="cols-container">

        <?php foreach ($allUsers as $u):
            $pid   = (int)$u['prolegal_id'];
            $tasks = $tasksByUser[$pid] ?? [];
            $name  = $u['cached_name'] ?: 'Usuario #' . $pid;
            $photo = userPhotoUrl($u['cached_photo'] ?? '');
            $hasTasks = !empty($tasks);
        ?>
        <div class="col-user" data-name="<?= htmlspecialchars(strtolower($name)) ?>" data-has-tasks="<?= $hasTasks ? '1' : '0' ?>">

            <!-- Cabecera del usuario -->
            <div class="col-header">
                <img src="<?= htmlspecialchars($photo) ?>"
                     alt="<?= htmlspecialchars($name) ?>"
                     class="rounded-full object-cover flex-shrink-0"
                     style="width:34px;height:34px;border:2px solid rgba(0,153,205,0.5)"
                     onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
                <div style="min-width:0;flex:1">
                    <p class="text-white font-semibold truncate" style="font-size:13px"><?= htmlspecialchars($name) ?></p>
                    <p style="color:rgba(255,255,255,0.45);font-size:11px">
                        <?= count($tasks) ?> tarea<?= count($tasks) !== 1 ? 's' : '' ?> fija<?= count($tasks) !== 1 ? 's' : '' ?>
                    </p>
                </div>
                <?php if ($hasTasks): ?>
                <span style="background:rgba(0,153,205,0.3);color:#7dd3f8;font-size:10px;font-weight:700;
                             border-radius:6px;padding:2px 7px;flex-shrink:0">
                    <?= count($tasks) ?>
                </span>
                <?php endif; ?>
            </div>

            <!-- Tareas de este usuario -->
            <div class="col-body">
                <?php if (empty($tasks)): ?>
                <div class="empty-col">
                    <i class="fa-solid fa-thumbtack mb-1.5" style="font-size:20px;color:#e5e7eb;display:block"></i>
                    Sin tareas fijas
                </div>
                <?php else: ?>
                    <?php foreach ($tasks as $t): ?>
                    <div class="task-item">
                        <i class="fa-solid fa-circle-small"></i>
                        <span><?= htmlspecialchars($t['task']) ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
        <?php endforeach; ?>

    </div>
</div>

<?php endif; ?>

<script>
function filterColumns(text) {
    const onlyWithTasks = document.getElementById('only-with-tasks').checked;
    const q = text.toLowerCase().trim();
    document.querySelectorAll('#cols-container .col-user').forEach(col => {
        const name     = col.dataset.name;
        const hasTasks = col.dataset.hasTasks === '1';
        const matchName = !q || name.includes(q);
        const matchTask = !onlyWithTasks || hasTasks;
        col.style.display = (matchName && matchTask) ? '' : 'none';
    });
}
// Aplicar filtro inicial (checkbox viene marcado por defecto)
document.addEventListener('DOMContentLoaded', () => filterColumns(''));
</script>

<?php include __DIR__ . '/includes/layout_end.php'; ?>
