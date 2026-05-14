<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();
$user        = currentUser();
$isAdminUser = isAdmin($user['id']);

// ── Semana con offset de navegación ──────────────────────────────────────────
$today      = new DateTime('today');
$weekOffset = intval($_GET['w'] ?? 0); // 0=esta semana, -1=anterior, +1=siguiente
$dowToday   = (int)$today->format('N'); // 1=Lunes … 7=Domingo

// Lunes de la semana actual + offset
$monday = clone $today;
$monday->modify('-' . ($dowToday - 1) . ' days');
if ($weekOffset !== 0) {
    $monday->modify(($weekOffset > 0 ? '+' : '') . ($weekOffset * 7) . ' days');
}

$days     = [];
$dayNames = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'];
for ($i = 0; $i < 5; $i++) {
    $d = clone $monday;
    $d->modify("+$i days");
    $days[] = $d;
}

$weekStart = $days[0]->format('Y-m-d');
$weekEnd   = $days[4]->format('Y-m-d');
$todayStr  = $today->format('Y-m-d');

// ── URLs de navegación ────────────────────────────────────────────────────────
$urlPrev    = '?w=' . ($weekOffset - 1);
$urlNext    = '?w=' . ($weekOffset + 1);
$urlCurrent = '?w=0';
$isCurrentWeek = ($weekOffset === 0);

// Etiqueta de la semana
if ($isCurrentWeek)       $weekLabel = 'Esta semana';
elseif ($weekOffset === -1) $weekLabel = 'Semana pasada';
elseif ($weekOffset === 1)  $weekLabel = 'Semana que viene';
elseif ($weekOffset < 0)    $weekLabel = 'Hace ' . abs($weekOffset) . ' semanas';
else                        $weekLabel = 'En ' . $weekOffset . ' semanas';

// ── Consulta de tareas ────────────────────────────────────────────────────────
if ($isAdminUser) {
    $stmt = $pdo->prepare("
        SELECT n.id, n.title, n.status, n.created_at,
               DATE(n.created_at)  AS created_date,
               n.scheduled_date,
               b.id   AS board_id,  b.name   AS board_name,
               bc.name AS cat_name, bc.color AS cat_color,
               au.cached_name  AS creator_name,
               au.cached_photo AS creator_photo
        FROM notes n
        JOIN boards b ON b.id = n.board_id AND b.is_archived = 0
        LEFT JOIN board_categories bc ON bc.id = b.category_id
        LEFT JOIN app_users au ON au.prolegal_id = n.created_by
        WHERE DATE(n.created_at) BETWEEN :ws AND :we
           OR (n.scheduled_date IS NOT NULL AND n.scheduled_date BETWEEN :ws2 AND :we2)
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([':ws'=>$weekStart,':we'=>$weekEnd,':ws2'=>$weekStart,':we2'=>$weekEnd]);
} else {
    $stmt = $pdo->prepare("
        SELECT n.id, n.title, n.status, n.created_at,
               DATE(n.created_at)  AS created_date,
               n.scheduled_date,
               b.id   AS board_id,  b.name   AS board_name,
               bc.name AS cat_name, bc.color AS cat_color,
               au.cached_name  AS creator_name,
               au.cached_photo AS creator_photo
        FROM notes n
        JOIN boards b ON b.id = n.board_id AND b.is_archived = 0
        LEFT JOIN board_categories bc ON bc.id = b.category_id
        LEFT JOIN app_users au ON au.prolegal_id = n.created_by
        WHERE (
            DATE(n.created_at) BETWEEN :ws AND :we
            OR (n.scheduled_date IS NOT NULL AND n.scheduled_date BETWEEN :ws2 AND :we2)
        )
        AND (
            b.created_by = :uid
            OR EXISTS (SELECT 1 FROM board_members bm WHERE bm.board_id = n.board_id AND bm.prolegal_id = :uid2)
            OR EXISTS (SELECT 1 FROM category_users cu WHERE cu.category_id = b.category_id AND cu.prolegal_id = :uid3)
        )
        ORDER BY n.created_at DESC
    ");
    $stmt->execute([
        ':ws'=>$weekStart,':we'=>$weekEnd,':ws2'=>$weekStart,':we2'=>$weekEnd,
        ':uid'=>$user['id'],':uid2'=>$user['id'],':uid3'=>$user['id']
    ]);
}
$allTasks = $stmt->fetchAll();

// ── Usuarios etiquetados ──────────────────────────────────────────────────────
$taggedMap = [];
if (!empty($allTasks)) {
    $ids = implode(',', array_map('intval', array_column($allTasks, 'id')));
    try {
        $rows = $pdo->query("
            SELECT nu.note_id, au.cached_name AS name, au.cached_photo AS photo
            FROM note_users nu
            JOIN app_users au ON au.prolegal_id = nu.prolegal_id
            WHERE nu.note_id IN ($ids)
            ORDER BY au.cached_name ASC
        ")->fetchAll();
        foreach ($rows as $r) {
            $taggedMap[$r['note_id']][] = $r;
        }
    } catch (Exception $e) {}
}

// ── Organizar tareas por día ──────────────────────────────────────────────────
// Una tarea puede aparecer en su día de creación Y en su día de calendario (si son distintos)
$tasksByDay = [];
foreach ($days as $d) {
    $tasksByDay[$d->format('Y-m-d')] = [];
}

foreach ($allTasks as $task) {
    $cd = $task['created_date'];   // fecha creación
    $sd = $task['scheduled_date']; // fecha calendario

    // Agregar al día de creación si cae en la semana
    if (isset($tasksByDay[$cd])) {
        $tasksByDay[$cd][$task['id']] = array_merge($task, ['col_type' => ($sd === $cd) ? 'both' : 'created']);
    }

    // Agregar al día de calendario si es distinto al de creación y cae en la semana
    if ($sd && $sd !== $cd && isset($tasksByDay[$sd])) {
        $tasksByDay[$sd][$task['id']] = array_merge($task, ['col_type' => 'scheduled']);
    }
}

// ── Colores de estado ─────────────────────────────────────────────────────────
$statusLabel = ['pending'=>'Pendiente','in_progress'=>'En proceso','completed'=>'Completado'];
$statusColor = ['pending'=>'#f59e0b','in_progress'=>'#0099cd','completed'=>'#10b981'];
$statusBg    = ['pending'=>'rgba(245,158,11,0.1)','in_progress'=>'rgba(0,153,205,0.1)','completed'=>'rgba(16,185,129,0.1)'];
$statusIcon  = ['pending'=>'fa-hourglass-half','in_progress'=>'fa-rotate','completed'=>'fa-circle-check'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semanal — Sector Comercial</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root { --navy:#162259; --cyan:#0099cd; }
        * { font-family:'DM Sans',sans-serif; box-sizing:border-box; }
        .font-jakarta { font-family:'Plus Jakarta Sans',sans-serif; }
        body { background:#f0f4f8; min-height:100vh; }

        /* ── Topbar ── */
        #topbar {
            background:white; border-bottom:1px solid #e5e7eb;
            height:58px; display:flex; align-items:center;
            padding:0 20px; position:sticky; top:0; z-index:30; gap:14px;
        }

        /* ── Columnas ── */
        .week-grid {
            display:grid;
            grid-template-columns:repeat(5,1fr);
            gap:12px;
            padding:18px 20px 24px;
            align-items:start;
        }
        .day-col {
            background:white;
            border-radius:14px;
            overflow:hidden;
            box-shadow:0 1px 4px rgba(22,34,89,0.06),0 4px 14px rgba(0,0,0,0.04);
            display:flex;
            flex-direction:column;
        }
        .day-header {
            padding:12px 14px 10px;
            border-bottom:1.5px solid #f0f2f5;
            background:white;
        }
        .day-header.is-today {
            background:linear-gradient(135deg,#162259 0%,#0d1a42 100%);
        }
        .day-header.is-today .day-name  { color:white; }
        .day-header.is-today .day-date  { color:rgba(255,255,255,0.55); }
        .day-header.is-today .day-count { background:rgba(255,255,255,0.2); color:white; }

        .day-name  { font-family:'Plus Jakarta Sans',sans-serif; font-size:13px; font-weight:800; color:#374151; text-transform:uppercase; letter-spacing:.5px; }
        .day-date  { font-size:11px; color:#9ca3af; margin-top:1px; }
        .day-count { display:inline-flex; align-items:center; justify-content:center; background:#f3f4f6; color:#6b7280; border-radius:99px; padding:1px 7px; font-size:10px; font-weight:700; margin-left:6px; }

        .day-body  { padding:10px 10px 12px; display:flex; flex-direction:column; gap:8px; }
        .day-empty { padding:20px 10px; text-align:center; color:#d1d5db; font-size:12px; }

        /* ── Tarjeta de tarea ── */
        .task-card {
            display:block; text-decoration:none;
            background:#fafafa; border:1.5px solid #f0f2f5;
            border-radius:10px; padding:9px 10px;
            transition:all .15s; cursor:pointer;
            position:relative;
        }
        .task-card:hover {
            background:white; border-color:#dde3ef;
            box-shadow:0 2px 10px rgba(22,34,89,0.08);
            transform:translateY(-1px);
        }
        .task-card.completed-card {
            opacity:.72;
            background:#f9fafb;
        }
        .task-card.completed-card .task-title {
            text-decoration:line-through;
            color:#9ca3af;
        }

        .task-title { font-size:12px; font-weight:600; color:#111827; line-height:1.35; margin-bottom:5px; }

        .task-meta  { display:flex; flex-direction:column; gap:3px; }
        .meta-row   { display:flex; align-items:center; gap:4px; font-size:10px; color:#9ca3af; }
        .meta-row i { font-size:8px; flex-shrink:0; }

        .status-dot {
            display:inline-block; width:7px; height:7px;
            border-radius:50%; flex-shrink:0;
        }

        /* Indicador de tipo (creado / calendario) */
        .col-badge {
            display:inline-flex; align-items:center; gap:3px;
            font-size:9px; font-weight:700; border-radius:5px; padding:1px 5px;
        }
        .col-badge.created   { background:rgba(22,34,89,0.08);  color:#162259; }
        .col-badge.scheduled { background:rgba(0,153,205,0.1);  color:#0077a8; }
        .col-badge.both      { background:rgba(16,185,129,0.1); color:#047857; }

        /* Avatar stack */
        .avatar-stack { display:flex; align-items:center; margin-left:2px; }
        .avatar-stack img { width:16px; height:16px; border-radius:50%; object-fit:cover; border:1.5px solid white; margin-left:-4px; flex-shrink:0; }
        .avatar-stack img:first-child { margin-left:0; }

        /* Completado overlay badge */
        .done-badge {
            position:absolute; top:7px; right:7px;
            background:#d1fae5; color:#065f46;
            border-radius:6px; padding:1px 5px;
            font-size:9px; font-weight:700;
            display:flex; align-items:center; gap:3px;
        }

        /* ── Botones ── */
        .btn-back {
            display:inline-flex; align-items:center; gap:7px;
            background:transparent; border:1.5px solid #e5e7eb; border-radius:10px;
            padding:7px 14px; font-size:13px; font-weight:600; color:#374151;
            cursor:pointer; transition:all .15s; text-decoration:none; flex-shrink:0;
        }
        .btn-back:hover { border-color:#162259; color:#162259; background:rgba(22,34,89,0.03); }

        .btn-nav {
            display:inline-flex; align-items:center; justify-content:center;
            width:32px; height:32px; border-radius:8px;
            background:transparent; border:1.5px solid #e5e7eb;
            color:#374151; text-decoration:none; transition:all .15s; flex-shrink:0;
        }
        .btn-nav:hover { border-color:#162259; color:#162259; background:rgba(22,34,89,0.05); }

        /* ── Toast ── */
        #toast-container { position:fixed; bottom:24px; right:24px; z-index:999; display:flex; flex-direction:column; gap:8px; pointer-events:none; }
        .toast { background:white; border-radius:12px; padding:12px 18px; box-shadow:0 4px 20px rgba(0,0,0,0.12); border-left:4px solid #0099cd; font-size:14px; display:flex; align-items:center; gap:10px; min-width:260px; animation:slideIn .28s ease; }
        @keyframes slideIn { from{transform:translateX(110%);opacity:0} to{transform:translateX(0);opacity:1} }

        @media (max-width:900px) {
            .week-grid { grid-template-columns:repeat(2,1fr); }
        }
        @media (max-width:560px) {
            .week-grid { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>

<div id="toast-container"></div>

<!-- ── Topbar ─────────────────────────────────────────────────────────────── -->
<div id="topbar">
    <a href="<?= BASE_URL ?>/index.php" class="btn-back">
        <i class="fa-solid fa-arrow-left text-xs"></i> Volver
    </a>

    <!-- Navegación de semanas -->
    <div class="flex items-center gap-2" style="flex:1">
        <!-- Ícono + título -->
        <div style="background:rgba(22,34,89,0.08);border-radius:8px;padding:6px 10px;display:flex;align-items:center;gap:7px;flex-shrink:0">
            <i class="fa-solid fa-calendar-week" style="color:#162259;font-size:14px"></i>
            <span class="font-jakarta font-bold text-gray-900 hidden sm:block" style="font-size:15px">Semanal</span>
        </div>

        <!-- Controles < label > -->
        <div class="flex items-center gap-1.5">
            <a href="<?= $urlPrev ?>" class="btn-nav" title="Semana anterior">
                <i class="fa-solid fa-chevron-left" style="font-size:11px"></i>
            </a>

            <div style="text-align:center;min-width:160px">
                <div class="font-jakarta font-bold text-gray-800" style="font-size:13px;line-height:1.2">
                    <?= $weekLabel ?>
                    <?php if (!$isCurrentWeek): ?>
                    <a href="<?= $urlCurrent ?>" style="font-size:10px;color:#0099cd;font-weight:600;margin-left:5px;text-decoration:none;vertical-align:middle" title="Volver a esta semana">
                        <i class="fa-solid fa-rotate-left" style="font-size:9px"></i> hoy
                    </a>
                    <?php endif; ?>
                </div>
                <div style="font-size:10px;color:#9ca3af;margin-top:1px">
                    <?= $days[0]->format('d/m') ?> — <?= $days[4]->format('d/m/Y') ?>
                </div>
            </div>

            <a href="<?= $urlNext ?>" class="btn-nav" title="Semana siguiente">
                <i class="fa-solid fa-chevron-right" style="font-size:11px"></i>
            </a>
        </div>
    </div>

    <!-- Usuario -->
    <div class="flex items-center gap-2.5 flex-shrink-0">
        <img src="<?= htmlspecialchars(userPhotoUrl($user['photo'])) ?>"
             class="rounded-full object-cover"
             style="width:36px;height:36px;border:2px solid #e5e7eb"
             onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
        <span class="text-sm font-semibold text-gray-700 hidden md:block" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($user['name']) ?></span>
    </div>
</div>

<!-- ── Semana ─────────────────────────────────────────────────────────────── -->
<div class="week-grid">
<?php foreach ($days as $i => $day):
    $dateStr  = $day->format('Y-m-d');
    $isToday  = ($dateStr === $todayStr);
    $tasks    = array_values($tasksByDay[$dateStr] ?? []);
    $count    = count($tasks);
?>
    <div class="day-col">
        <!-- Cabecera del día -->
        <div class="day-header <?= $isToday ? 'is-today' : '' ?>">
            <div class="flex items-center justify-between">
                <div>
                    <div class="day-name">
                        <?= $dayNames[$i] ?>
                        <?php if ($isToday): ?>
                        <span style="font-size:9px;background:rgba(0,153,205,0.3);color:#7dd3fc;border-radius:5px;padding:1px 5px;margin-left:4px;vertical-align:middle">HOY</span>
                        <?php endif; ?>
                    </div>
                    <div class="day-date"><?= $day->format('d/m/Y') ?></div>
                </div>
                <?php if ($count > 0): ?>
                <span class="day-count"><?= $count ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tareas del día -->
        <div class="day-body">
        <?php if ($count === 0): ?>
            <div class="day-empty">
                <i class="fa-regular fa-calendar text-2xl mb-2 block"></i>
                Sin tareas
            </div>
        <?php else:
            foreach ($tasks as $task):
                $isCompleted = ($task['status'] === 'completed');
                $sc   = $statusColor[$task['status']] ?? '#6b7280';
                $tags = $taggedMap[$task['id']] ?? [];
                $colType = $task['col_type'] ?? 'created';
                $colBadgeLabel = match($colType) {
                    'scheduled' => ['ico'=>'fa-calendar-check', 'txt'=>'Calendario'],
                    'both'      => ['ico'=>'fa-calendar-check', 'txt'=>'Creación + Cal.'],
                    default     => ['ico'=>'fa-clock',          'txt'=>'Creación'],
                };
        ?>
            <a href="<?= BASE_URL ?>/board.php?id=<?= $task['board_id'] ?>"
               class="task-card <?= $isCompleted ? 'completed-card' : '' ?>">

                <?php if ($isCompleted): ?>
                <div class="done-badge">
                    <i class="fa-solid fa-circle-check" style="font-size:8px"></i> Completado
                </div>
                <?php endif; ?>

                <!-- Título -->
                <div class="task-title" style="padding-right:<?= $isCompleted ? '72px' : '0' ?>">
                    <?= htmlspecialchars($task['title']) ?>
                </div>

                <div class="task-meta">
                    <!-- Estado -->
                    <div class="meta-row" style="margin-bottom:1px">
                        <span class="status-dot" style="background:<?= $sc ?>"></span>
                        <span style="color:<?= $sc ?>;font-weight:600"><?= $statusLabel[$task['status']] ?></span>
                        <span style="margin-left:auto">
                            <span class="col-badge <?= $colType ?>">
                                <i class="fa-solid <?= $colBadgeLabel['ico'] ?>"></i>
                                <?= $colBadgeLabel['txt'] ?>
                            </span>
                        </span>
                    </div>

                    <!-- Sector -->
                    <?php if ($task['cat_name']): ?>
                    <div class="meta-row">
                        <i class="fa-solid fa-tags" style="color:<?= htmlspecialchars($task['cat_color'] ?? '#9ca3af') ?>"></i>
                        <span style="color:<?= htmlspecialchars($task['cat_color'] ?? '#9ca3af') ?>;font-weight:600;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($task['cat_name']) ?></span>
                    </div>
                    <?php endif; ?>

                    <!-- Grupo -->
                    <div class="meta-row">
                        <i class="fa-solid fa-layer-group" style="color:#0099cd"></i>
                        <span style="color:#374151;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($task['board_name']) ?></span>
                    </div>

                    <!-- Usuarios etiquetados -->
                    <?php if (!empty($tags)): ?>
                    <div class="meta-row" style="gap:5px">
                        <i class="fa-solid fa-user-tag" style="color:#0099cd"></i>
                        <div class="avatar-stack">
                            <?php foreach (array_slice($tags, 0, 4) as $tu): ?>
                            <img src="<?= htmlspecialchars(userPhotoUrl($tu['photo'] ?? '')) ?>"
                                 title="<?= htmlspecialchars($tu['name']) ?>"
                                 onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($tags) === 1): ?>
                        <span style="font-size:10px;color:#6b7280"><?= htmlspecialchars($tags[0]['name']) ?></span>
                        <?php elseif (count($tags) > 4): ?>
                        <span style="font-size:10px;color:#6b7280;margin-left:4px">+<?= count($tags)-4 ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>

</body>
</html>
