<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();
$user        = currentUser();
$isAdminUser = isAdmin($user['id']);

// ── Cargar tareas con fecha del usuario (de todos los grupos con acceso) ──
if ($isAdminUser) {
    $stmt = $pdo->prepare("
        SELECT n.id, n.title AS task, n.scheduled_date AS date, n.scheduled_time AS time,
               n.status, n.board_id, b.name AS board_name
        FROM notes n
        JOIN boards b ON b.id = n.board_id AND b.is_archived = 0
        WHERE n.scheduled_date IS NOT NULL
        ORDER BY n.scheduled_date ASC, n.scheduled_time ASC
    ");
    $stmt->execute();
} else {
    $stmt = $pdo->prepare("
        SELECT n.id, n.title AS task, n.scheduled_date AS date, n.scheduled_time AS time,
               n.status, n.board_id, b.name AS board_name
        FROM notes n
        JOIN boards b ON b.id = n.board_id AND b.is_archived = 0
        WHERE n.scheduled_date IS NOT NULL
          AND (
              b.created_by = ?
              OR EXISTS (SELECT 1 FROM board_members bm WHERE bm.board_id = n.board_id AND bm.prolegal_id = ?)
              OR EXISTS (SELECT 1 FROM category_users cu WHERE cu.category_id = b.category_id AND cu.prolegal_id = ?)
          )
        ORDER BY n.scheduled_date ASC, n.scheduled_time ASC
    ");
    $stmt->execute([$user['id'], $user['id'], $user['id']]);
}
$scheduledTasks = $stmt->fetchAll();

// Preparar para JS
$tasksJson = json_encode(array_map(fn($t) => [
    'id'         => (int)$t['id'],
    'task'       => $t['task'],
    'date'       => $t['date'],
    'time'       => $t['time'] ? substr($t['time'], 0, 5) : null,
    'status'     => $t['status'],
    'board_id'   => (int)$t['board_id'],
    'board_name' => $t['board_name'],
], $scheduledTasks));

$pageTitle = 'Calendario';
include __DIR__ . '/includes/layout.php';
?>

<style>
.cal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
}
.cal-month-title {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 22px;
    font-weight: 800;
    color: #111827;
    text-transform: capitalize;
    letter-spacing: -0.3px;
}
.cal-nav-group { display: flex; align-items: center; gap: 8px; }
.cal-nav-btn {
    background: white; border: 1.5px solid #e5e7eb; border-radius: 10px;
    padding: 8px 14px; font-size: 13px; font-weight: 600; color: #374151;
    cursor: pointer; transition: all .15s;
}
.cal-nav-btn:hover { border-color: #0099cd; color: #0099cd; background: rgba(0,153,205,0.04); }
.cal-today-btn {
    background: #0099cd; color: white; border: none; border-radius: 10px;
    padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background .15s;
}
.cal-today-btn:hover { background: #0077aa; }

/* ── Grid ─────────────────────────────────────────────────────────────────── */
.cal-card {
    background: white; border-radius: 16px;
    box-shadow: 0 1px 4px rgba(22,34,89,0.06), 0 4px 16px rgba(0,0,0,0.04);
    overflow: hidden; flex: 1; min-width: 0;
}
.cal-weekdays {
    display: grid; grid-template-columns: repeat(7, 1fr);
    background: #f8fafc; border-bottom: 1.5px solid #e5e7eb;
}
.cal-weekday {
    text-align: center; padding: 12px 0; font-size: 12px; font-weight: 700;
    color: #9ca3af; text-transform: uppercase; letter-spacing: 0.05em;
}
.cal-weekday.weekend { color: #cbd5e1; }
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); }
.cal-cell {
    min-height: 110px; border-right: 1px solid #f0f2f5; border-bottom: 1px solid #f0f2f5;
    padding: 8px; position: relative; transition: background .12s;
}
.cal-cell:nth-child(7n) { border-right: none; }
.cal-cell:hover { background: #fafbfc; }
.cal-cell.other-month { background: #fafafa; }
.cal-cell.other-month .cal-day-num { color: #d1d5db; }
.cal-cell.today { background: rgba(0,153,205,0.03); }
.cal-cell.today::after {
    content: ''; position: absolute; top: 0; left: 0; right: 0;
    height: 3px; background: #0099cd; border-radius: 0 0 3px 3px;
}
.cal-cell.weekend-cell { background: #fafafa; }
.cal-day-num {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 50%;
    font-size: 13px; font-weight: 700; color: #4b5563; margin-bottom: 5px;
}
.cal-cell.today .cal-day-num { background: #0099cd; color: white; }

.cal-task-pill {
    display: flex; align-items: flex-start; gap: 3px;
    border-radius: 0 5px 5px 0; padding: 3px 6px 3px 5px; margin-bottom: 3px;
    font-size: 10.5px; cursor: pointer; transition: filter .12s; line-height: 1.4;
    border-left: 3px solid #0099cd; background: #e0f4fb; color: #004f73;
}
.cal-task-pill:hover { filter: brightness(0.95); }
.cal-task-pill.status-completed { border-color: #10b981; background: #d1fae5; color: #065f46; }
.pill-time { font-weight: 700; white-space: nowrap; flex-shrink: 0; font-size: 10px; margin-top: 1px; color: #0077aa; }
.pill-text { overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }

/* ── Panel lateral ────────────────────────────────────────────────────────── */
.month-panel {
    background: white; border-radius: 16px;
    box-shadow: 0 1px 4px rgba(22,34,89,0.06), 0 4px 16px rgba(0,0,0,0.04);
    padding: 20px; width: 260px; flex-shrink: 0;
}
.panel-title {
    font-size: 12px; font-weight: 700; color: #6b7280;
    text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 14px;
}
.month-task-item {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 10px 0; border-bottom: 1px solid #f3f4f6; cursor: pointer;
}
.month-task-item:last-child { border-bottom: none; }
.month-task-item:hover .mti-text { color: #0099cd; }
.mti-day {
    background: #e0f4fb; color: #0077aa; border-radius: 8px;
    padding: 4px 8px; font-size: 12px; font-weight: 700; flex-shrink: 0;
    min-width: 38px; text-align: center;
}
.mti-day.completed { background: #d1fae5; color: #065f46; }
.mti-text { font-size: 13px; color: #374151; line-height: 1.4; transition: color .15s; }
.mti-board { font-size: 10.5px; color: #9ca3af; margin-top: 2px; }
.mti-time { font-size: 11px; color: #0099cd; font-weight: 600; margin-top: 2px; }
.no-tasks-msg { text-align: center; padding: 24px 0; color: #9ca3af; font-size: 13px; }

/* ── Modal ────────────────────────────────────────────────────────────────── */
.task-modal-overlay {
    display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4);
    z-index: 200; align-items: center; justify-content: center; padding: 16px; backdrop-filter: blur(3px);
}
.task-modal-overlay.open { display: flex; }
.task-modal-box {
    background: white; border-radius: 16px; padding: 24px; max-width: 440px;
    width: 100%; box-shadow: 0 24px 64px rgba(0,0,0,0.16);
}
</style>

<!-- ── Encabezado ─────────────────────────────────────────────────────────── -->
<div class="cal-header">
    <div>
        <h2 class="font-jakarta font-bold text-gray-900 text-xl">Calendario</h2>
        <p class="text-gray-400 text-sm mt-0.5">Tareas agendadas de todos tus grupos</p>
    </div>
    <div class="cal-nav-group">
        <button class="cal-today-btn" onclick="goToday()">Hoy</button>
        <button class="cal-nav-btn" onclick="changeMonth(-1)"><i class="fa-solid fa-chevron-left"></i></button>
        <span class="cal-month-title" id="cal-month-label" style="min-width:200px;text-align:center"></span>
        <button class="cal-nav-btn" onclick="changeMonth(1)"><i class="fa-solid fa-chevron-right"></i></button>
    </div>
</div>

<!-- ── Cuerpo ─────────────────────────────────────────────────────────────── -->
<div style="display:flex;gap:20px;align-items:flex-start">

    <!-- Calendario -->
    <div class="cal-card">
        <div class="cal-weekdays">
            <div class="cal-weekday">Lun</div>
            <div class="cal-weekday">Mar</div>
            <div class="cal-weekday">Mié</div>
            <div class="cal-weekday">Jue</div>
            <div class="cal-weekday">Vie</div>
            <div class="cal-weekday weekend">Sáb</div>
            <div class="cal-weekday weekend">Dom</div>
        </div>
        <div class="cal-grid" id="cal-grid"></div>
    </div>

    <!-- Panel lateral -->
    <div class="month-panel">
        <p class="panel-title"><i class="fa-regular fa-calendar-check mr-1"></i><span id="panel-label">Este mes</span></p>
        <div id="month-task-list"><p class="no-tasks-msg">Cargando…</p></div>
    </div>

</div>

<!-- ── Modal detalle ──────────────────────────────────────────────────────── -->
<div class="task-modal-overlay" id="task-modal" onclick="if(event.target===this)closeTaskModal()">
    <div class="task-modal-box">
        <div id="modal-content"></div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;padding-top:16px;border-top:1px solid #f3f4f6">
            <button onclick="closeTaskModal()" style="background:#f3f4f6;border:none;border-radius:10px;padding:9px 18px;font-size:13px;font-weight:600;color:#374151;cursor:pointer">Cerrar</button>
            <a id="modal-board-link" href="#" style="background:linear-gradient(135deg,#0099cd,#0077a8);color:white;border:none;border-radius:10px;padding:9px 18px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;text-decoration:none">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Ir al grupo
            </a>
        </div>
    </div>
</div>

<script>
let TASKS_DATA = <?= $tasksJson ?>;

const MONTHS_ES    = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                      'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
const SHORT_MONTHS = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
const STATUS_ES    = { pending: 'Pendiente', in_progress: 'En proceso', completed: 'Completada' };

const todayDate = new Date();
let calYear  = todayDate.getFullYear();
let calMonth = todayDate.getMonth();

function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Modal ─────────────────────────────────────────────────────────────────────
function openTaskModal(t) {
    const [y, m, d] = t.date.split('-').map(Number);
    const dayName = new Date(y, m-1, d).toLocaleDateString('es-AR', { weekday: 'long' });
    const dateStr = `${dayName.charAt(0).toUpperCase()+dayName.slice(1)}, ${d} de ${MONTHS_ES[m-1]} de ${y}`;

    const statusColor = { pending: '#f59e0b', in_progress: '#0099cd', completed: '#10b981' };
    const statusBg    = { pending: '#fffbeb', in_progress: '#f0f9ff', completed: '#f0fdf4' };

    document.getElementById('modal-content').innerHTML = `
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
            <span style="background:${statusBg[t.status]};color:${statusColor[t.status]};border-radius:8px;padding:4px 10px;font-size:12px;font-weight:700">${STATUS_ES[t.status]||t.status}</span>
            <span style="font-size:12px;color:#9ca3af">${escHtml(t.board_name)}</span>
        </div>
        <h3 style="font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:17px;color:#111827;line-height:1.3;margin-bottom:12px">${escHtml(t.task)}</h3>
        <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:#6b7280;margin-bottom:${t.time?'8px':'0'}">
            <i class="fa-regular fa-calendar" style="color:#0099cd"></i>${escHtml(dateStr)}
        </div>
        ${t.time ? `<div style="display:inline-flex;align-items:center;gap:6px;background:#f0f9ff;color:#0077aa;font-size:13px;font-weight:700;padding:5px 12px;border-radius:20px">
            <i class="fa-regular fa-clock"></i>${t.time} hs
        </div>` : ''}`;

    document.getElementById('modal-board-link').href = '<?= BASE_URL ?>/board.php?id=' + t.board_id;
    document.getElementById('task-modal').classList.add('open');
}
function closeTaskModal() {
    document.getElementById('task-modal').classList.remove('open');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeTaskModal(); });

// ── Navegación ────────────────────────────────────────────────────────────────
function changeMonth(d) {
    calMonth += d;
    if (calMonth > 11) { calMonth = 0; calYear++; }
    if (calMonth < 0)  { calMonth = 11; calYear--; }
    renderCalendar();
}
function goToday() {
    calYear  = todayDate.getFullYear();
    calMonth = todayDate.getMonth();
    renderCalendar();
}

// ── Renderizado ───────────────────────────────────────────────────────────────
function renderCalendar() {
    document.getElementById('cal-month-label').textContent = `${MONTHS_ES[calMonth]} ${calYear}`;
    document.getElementById('panel-label').textContent = MONTHS_ES[calMonth];

    // Agrupar por fecha
    const byDate = {};
    TASKS_DATA.forEach(t => {
        if (!byDate[t.date]) byDate[t.date] = [];
        byDate[t.date].push(t);
    });
    Object.values(byDate).forEach(arr =>
        arr.sort((a, b) => (!a.time ? 1 : !b.time ? -1 : a.time.localeCompare(b.time)))
    );

    // ── Grid ──────────────────────────────────────────────────────────────────
    const grid = document.getElementById('cal-grid');
    grid.innerHTML = '';

    const firstDow    = new Date(calYear, calMonth, 1).getDay();
    const startOffset = firstDow === 0 ? 6 : firstDow - 1;
    const daysInMonth = new Date(calYear, calMonth + 1, 0).getDate();
    const prevDays    = new Date(calYear, calMonth, 0).getDate();
    const totalCells  = Math.ceil((startOffset + daysInMonth) / 7) * 7;

    for (let i = 0; i < totalCells; i++) {
        const cell = document.createElement('div');
        cell.className = 'cal-cell';

        let dayNum, isThisMonth;
        if (i < startOffset) {
            dayNum = prevDays - startOffset + i + 1; isThisMonth = false; cell.classList.add('other-month');
        } else if (i >= startOffset + daysInMonth) {
            dayNum = i - startOffset - daysInMonth + 1; isThisMonth = false; cell.classList.add('other-month');
        } else {
            dayNum = i - startOffset + 1; isThisMonth = true;
        }

        const col = i % 7;
        if (col >= 5) cell.classList.add('weekend-cell');

        if (isThisMonth && calYear === todayDate.getFullYear() && calMonth === todayDate.getMonth() && dayNum === todayDate.getDate())
            cell.classList.add('today');

        const numEl = document.createElement('div');
        numEl.className   = 'cal-day-num';
        numEl.textContent = dayNum;
        cell.appendChild(numEl);

        if (isThisMonth) {
            const mm  = String(calMonth + 1).padStart(2, '0');
            const dd  = String(dayNum).padStart(2, '0');
            const key = `${calYear}-${mm}-${dd}`;
            (byDate[key] || []).forEach(t => {
                const pill = document.createElement('div');
                pill.className = `cal-task-pill${t.status === 'completed' ? ' status-completed' : ''}`;
                pill.title     = `${t.task} — ${t.board_name}`;
                const timeHtml = t.time ? `<span class="pill-time">${t.time}</span>` : '';
                const txt      = t.task.length > 22 ? t.task.slice(0, 22) + '…' : t.task;
                pill.innerHTML = `${timeHtml}<span class="pill-text">${escHtml(txt)}</span>`;
                pill.onclick   = () => openTaskModal(t);
                cell.appendChild(pill);
            });
        }

        grid.appendChild(cell);
    }

    // ── Panel lateral ──────────────────────────────────────────────────────────
    const mm = String(calMonth + 1).padStart(2, '0');
    const monthTasks = [];
    Object.entries(byDate).forEach(([date, tasks]) => {
        if (date.startsWith(`${calYear}-${mm}`)) tasks.forEach(t => monthTasks.push(t));
    });
    monthTasks.sort((a, b) => {
        if (a.date !== b.date) return a.date.localeCompare(b.date);
        if (!a.time && !b.time) return 0;
        return !a.time ? 1 : !b.time ? -1 : a.time.localeCompare(b.time);
    });

    const list = document.getElementById('month-task-list');
    if (!monthTasks.length) {
        list.innerHTML = `<p class="no-tasks-msg">Sin tareas en ${MONTHS_ES[calMonth].toLowerCase()}</p>`;
        return;
    }
    list.innerHTML = monthTasks.map(t => {
        const d = parseInt(t.date.split('-')[2]);
        return `
        <div class="month-task-item" onclick='openTaskModal(${JSON.stringify(t)})'>
            <div class="mti-day${t.status==='completed'?' completed':''}">${d} ${SHORT_MONTHS[calMonth]}</div>
            <div>
                <div class="mti-text">${escHtml(t.task)}</div>
                <div class="mti-board"><i class="fa-solid fa-folder-open mr-1" style="color:#d1d5db"></i>${escHtml(t.board_name)}</div>
                ${t.time ? `<div class="mti-time"><i class="fa-regular fa-clock mr-1"></i>${t.time} hs</div>` : ''}
            </div>
        </div>`;
    }).join('');
}

renderCalendar();
</script>

<?php include __DIR__ . '/includes/layout_end.php'; ?>
