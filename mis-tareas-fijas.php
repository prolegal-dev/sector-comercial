<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();
$user        = currentUser();
$isAdminUser = isAdmin($user['id']);

// ── Cargar tareas fijas del usuario actual ─────────────────────────────────
$stmt = $pdo->prepare("
    SELECT id, task, task_order
    FROM fixed_tasks
    WHERE prolegal_id = ?
    ORDER BY task_order ASC, id ASC
");
$stmt->execute([$user['id']]);
$myTasks = $stmt->fetchAll();

$pageTitle = 'Mis Tareas Fijas';
include __DIR__ . '/includes/layout.php';
?>

<style>
    .task-row {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 11px 14px;
        border-radius: 10px;
        border: 1.5px solid #e5e7eb;
        background: #fff;
        margin-bottom: 6px;
        transition: all .18s;
        cursor: grab;
    }
    .task-row:hover { border-color: #0099cd; box-shadow: 0 2px 8px rgba(0,153,205,0.08); }
    .task-row.dragging { opacity: 0.45; border-color: #0099cd; }
    .task-row.drag-over { border-color: #0099cd; background: rgba(0,153,205,0.04); }
    .task-text {
        flex: 1;
        font-size: 14px;
        color: #1f2937;
        outline: none;
        border: none;
        background: transparent;
        min-width: 0;
    }
    .task-text:focus { color: #111827; }
    .drag-handle {
        color: #d1d5db;
        font-size: 13px;
        cursor: grab;
        flex-shrink: 0;
        padding: 2px 4px;
    }
    .drag-handle:hover { color: #9ca3af; }
    .btn-icon {
        background: none;
        border: none;
        cursor: pointer;
        color: #d1d5db;
        font-size: 13px;
        padding: 4px 6px;
        border-radius: 6px;
        transition: all .15s;
        flex-shrink: 0;
        display: flex;
        align-items: center;
    }
    .btn-icon:hover { background: #fee2e2; color: #dc2626; }
    .btn-icon.save-btn:hover { background: #d1fae5; color: #059669; }
    .add-task-row {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 14px;
        border-radius: 10px;
        border: 1.5px dashed #d1d5db;
        background: #fafafa;
        margin-top: 8px;
        transition: all .18s;
    }
    .add-task-row:focus-within {
        border-color: #0099cd;
        background: white;
        box-shadow: 0 0 0 3px rgba(0,153,205,0.08);
    }
    .add-task-input {
        flex: 1;
        border: none;
        background: transparent;
        outline: none;
        font-size: 14px;
        color: #374151;
    }
    .add-task-input::placeholder { color: #9ca3af; }
    #empty-state { display: <?= empty($myTasks) ? 'block' : 'none' ?>; }
    #tasks-list  { display: <?= empty($myTasks) ? 'none' : 'block' ?>; }
</style>

<!-- ── Encabezado ─────────────────────────────────────────────────────────── -->
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="font-jakarta font-bold text-gray-900 text-xl">Mis Tareas Fijas</h2>
        <p class="text-gray-400 text-sm mt-0.5">Tus tareas recurrentes o recordatorios permanentes</p>
    </div>
    <?php if ($isAdminUser): ?>
    <a href="<?= BASE_URL ?>/admin-tareas-fijas.php" class="btn-ghost" style="font-size:13px;padding:8px 14px">
        <i class="fa-solid fa-users"></i> Ver todos los usuarios
    </a>
    <?php endif; ?>
</div>

<!-- ── Tarjeta principal ───────────────────────────────────────────────────── -->
<div class="card p-6" style="max-width:680px">

    <!-- Contador -->
    <div class="flex items-center justify-between mb-4">
        <p class="text-sm font-semibold text-gray-700">
            <i class="fa-solid fa-thumbtack mr-1.5" style="color:#0099cd"></i>
            <span id="task-count"><?= count($myTasks) ?></span>
            tarea<?= count($myTasks) !== 1 ? 's' : '' ?> fija<?= count($myTasks) !== 1 ? 's' : '' ?>
        </p>
        <p class="text-xs text-gray-400">Arrastrá para reordenar</p>
    </div>

    <!-- Estado vacío -->
    <div id="empty-state" class="py-10 text-center">
        <i class="fa-solid fa-thumbtack text-4xl mb-3" style="color:#e5e7eb"></i>
        <p class="font-semibold text-gray-500 text-sm">No tenés tareas fijas todavía</p>
        <p class="text-gray-400 text-xs mt-1">Usá el campo de abajo para agregar tu primera tarea.</p>
    </div>

    <!-- Lista de tareas -->
    <div id="tasks-list">
        <?php foreach ($myTasks as $t): ?>
        <div class="task-row" data-id="<?= $t['id'] ?>" draggable="true">
            <span class="drag-handle"><i class="fa-solid fa-grip-vertical"></i></span>
            <span class="task-text" contenteditable="true"
                  data-original="<?= htmlspecialchars($t['task']) ?>"
                  onkeydown="handleEditKey(event, this)"
                  onblur="saveEdit(this)"><?= htmlspecialchars($t['task']) ?></span>
            <button class="btn-icon save-btn" title="Guardar cambio"
                    onclick="saveEditBtn(this)" style="display:none">
                <i class="fa-solid fa-check"></i>
            </button>
            <button class="btn-icon" title="Eliminar tarea"
                    onclick="deleteTask(this)">
                <i class="fa-solid fa-trash-can"></i>
            </button>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Agregar nueva tarea -->
    <div class="add-task-row" id="add-row">
        <i class="fa-solid fa-plus" style="color:#9ca3af;font-size:13px;flex-shrink:0"></i>
        <input type="text" class="add-task-input" id="new-task-input"
               placeholder="Escribí una nueva tarea fija y presioná Enter…"
               maxlength="500"
               onkeydown="handleAddKey(event)">
        <button class="btn-primary" style="padding:6px 14px;font-size:13px" onclick="addTask()">
            Agregar
        </button>
    </div>

</div>

<script>
const API = '<?= BASE_URL ?>/api/fixed_tasks.php';

// ── Contar tareas ─────────────────────────────────────────────────────────────
function updateCount() {
    const rows   = document.querySelectorAll('#tasks-list .task-row');
    const count  = rows.length;
    const span   = document.getElementById('task-count');
    const suffix = count !== 1 ? 's' : '';
    span.textContent = count;
    span.nextSibling.textContent = ` tarea${suffix} fija${suffix}`;
    document.getElementById('empty-state').style.display = count === 0 ? 'block' : 'none';
    document.getElementById('tasks-list').style.display  = count === 0 ? 'none'  : 'block';
}

// ── Agregar tarea ─────────────────────────────────────────────────────────────
async function addTask() {
    const inp  = document.getElementById('new-task-input');
    const task = inp.value.trim();
    if (!task) { inp.focus(); return; }

    const res = await apiCall(API, { action: 'add', task });
    if (!res.success) { showToast(res.error || 'Error al agregar', 'error'); return; }

    appendTaskRow(res.id, res.task);
    inp.value = '';
    inp.focus();
    updateCount();
    showToast('Tarea agregada', 'success');
}

function handleAddKey(e) {
    if (e.key === 'Enter') { e.preventDefault(); addTask(); }
}

// ── Crear fila DOM ────────────────────────────────────────────────────────────
function appendTaskRow(id, task) {
    const list = document.getElementById('tasks-list');
    const div  = document.createElement('div');
    div.className = 'task-row';
    div.dataset.id = id;
    div.draggable  = true;
    div.innerHTML  = `
        <span class="drag-handle"><i class="fa-solid fa-grip-vertical"></i></span>
        <span class="task-text" contenteditable="true"
              data-original="${task}"
              onkeydown="handleEditKey(event,this)"
              onblur="saveEdit(this)">${task}</span>
        <button class="btn-icon save-btn" title="Guardar cambio"
                onclick="saveEditBtn(this)" style="display:none">
            <i class="fa-solid fa-check"></i>
        </button>
        <button class="btn-icon" title="Eliminar tarea"
                onclick="deleteTask(this)">
            <i class="fa-solid fa-trash-can"></i>
        </button>`;
    list.appendChild(div);
    initDragRow(div);
}

// ── Eliminar tarea ────────────────────────────────────────────────────────────
async function deleteTask(btn) {
    const row = btn.closest('.task-row');
    const id  = parseInt(row.dataset.id);

    const res = await apiCall(API, { action: 'delete', id });
    if (!res.success) { showToast(res.error || 'Error al eliminar', 'error'); return; }

    row.style.cssText += 'opacity:0;transform:translateX(20px);transition:all .25s';
    setTimeout(() => { row.remove(); updateCount(); }, 260);
    showToast('Tarea eliminada', 'success');
}

// ── Editar tarea inline ───────────────────────────────────────────────────────
function handleEditKey(e, el) {
    if (e.key === 'Enter') { e.preventDefault(); el.blur(); }
    if (e.key === 'Escape') {
        el.textContent = el.dataset.original;
        el.blur();
    }
    const saveBtn = el.closest('.task-row').querySelector('.save-btn');
    if (saveBtn) saveBtn.style.display = el.textContent.trim() !== el.dataset.original ? 'flex' : 'none';
}

async function saveEdit(el) {
    const row     = el.closest('.task-row');
    const id      = parseInt(row.dataset.id);
    const newTask = el.textContent.trim();
    const saveBtn = row.querySelector('.save-btn');

    if (newTask === el.dataset.original || newTask === '') {
        el.textContent = el.dataset.original;
        if (saveBtn) saveBtn.style.display = 'none';
        return;
    }

    const res = await apiCall(API, { action: 'edit', id, task: newTask });
    if (!res.success) {
        showToast(res.error || 'Error al guardar', 'error');
        el.textContent = el.dataset.original;
    } else {
        el.dataset.original = newTask;
        el.textContent = res.task;
        showToast('Tarea actualizada', 'success');
    }
    if (saveBtn) saveBtn.style.display = 'none';
}

function saveEditBtn(btn) {
    const el = btn.closest('.task-row').querySelector('.task-text');
    el.blur();
}

// ── Drag & Drop para reordenar ────────────────────────────────────────────────
let dragSrc = null;

function initDragRow(row) {
    row.addEventListener('dragstart', e => {
        dragSrc = row;
        row.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    });
    row.addEventListener('dragend', () => {
        row.classList.remove('dragging');
        document.querySelectorAll('.task-row').forEach(r => r.classList.remove('drag-over'));
        saveOrder();
    });
    row.addEventListener('dragover', e => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        document.querySelectorAll('.task-row').forEach(r => r.classList.remove('drag-over'));
        if (row !== dragSrc) row.classList.add('drag-over');
    });
    row.addEventListener('drop', e => {
        e.preventDefault();
        if (dragSrc && dragSrc !== row) {
            const list = document.getElementById('tasks-list');
            const rows = [...list.querySelectorAll('.task-row')];
            const fromIdx = rows.indexOf(dragSrc);
            const toIdx   = rows.indexOf(row);
            if (fromIdx < toIdx) row.after(dragSrc);
            else row.before(dragSrc);
        }
        row.classList.remove('drag-over');
    });
}

async function saveOrder() {
    const ids = [...document.querySelectorAll('#tasks-list .task-row')].map(r => parseInt(r.dataset.id));
    if (ids.length < 2) return;
    await apiCall(API, { action: 'reorder', ids });
}

document.querySelectorAll('.task-row').forEach(initDragRow);
</script>

<?php include __DIR__ . '/includes/layout_end.php'; ?>
