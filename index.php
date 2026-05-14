<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();
$user        = currentUser();
$isAdminUser = isAdmin($user['id']);

// ── Stats globales ────────────────────────────────────────────────────────────
if ($isAdminUser) {
    $totalSectors    = $pdo->query("SELECT COUNT(*) FROM board_categories")->fetchColumn();
    $totalGroups     = $pdo->query("SELECT COUNT(*) FROM boards WHERE is_archived=0")->fetchColumn();
    $totalTasks      = $pdo->query("SELECT COUNT(*) FROM notes")->fetchColumn();
    $cntPending      = $pdo->query("SELECT COUNT(*) FROM notes WHERE status='pending'")->fetchColumn();
    $cntInProgress   = $pdo->query("SELECT COUNT(*) FROM notes WHERE status='in_progress'")->fetchColumn();
    $cntCompleted    = $pdo->query("SELECT COUNT(*) FROM notes WHERE status='completed'")->fetchColumn();
} else {
    // Stats personales: basadas en sectores y grupos del usuario
    $myStats = $pdo->prepare("
        SELECT
            COUNT(DISTINCT bc.id)                      AS sectors,
            COUNT(DISTINCT b.id)                       AS groups_count,
            COUNT(DISTINCT n.id)                       AS tasks,
            COALESCE(SUM(n.status='pending'),0)        AS pending,
            COALESCE(SUM(n.status='in_progress'),0)    AS in_progress,
            COALESCE(SUM(n.status='completed'),0)      AS completed
        FROM category_users cu
        JOIN board_categories bc ON bc.id = cu.category_id
        LEFT JOIN boards b  ON b.category_id = bc.id AND b.is_archived = 0
        LEFT JOIN notes n   ON n.board_id = b.id
        WHERE cu.prolegal_id = ?
    ");
    $myStats->execute([$user['id']]);
    $ms = $myStats->fetch();
    $totalSectors  = $ms['sectors'];
    $totalGroups   = $ms['groups_count'];
    $totalTasks    = $ms['tasks'];
    $cntPending    = $ms['pending'];
    $cntInProgress = $ms['in_progress'];
    $cntCompleted  = $ms['completed'];
}

// ── Tareas de HOY ─────────────────────────────────────────────────────────────
if ($isAdminUser) {
    $todayNotes = $pdo->query("
        SELECT n.id, n.title, n.description, n.status, n.created_at,
               DATE_FORMAT(n.created_at,'%H:%i') AS created_time,
               b.id AS board_id, b.name AS board_name,
               bc.name AS cat_name, bc.color AS cat_color,
               au.cached_name  AS creator_name,
               au.cached_photo AS creator_photo
        FROM notes n
        JOIN boards b ON b.id = n.board_id
        LEFT JOIN board_categories bc ON bc.id = b.category_id
        LEFT JOIN app_users au ON au.prolegal_id = n.created_by
        WHERE DATE(n.created_at) = CURDATE()
        ORDER BY n.created_at DESC
        LIMIT 30
    ")->fetchAll();
} else {
    // Para usuarios normales: tareas de sus grupos accesibles
    $todayNotes = $pdo->prepare("
        SELECT n.id, n.title, n.description, n.status, n.created_at,
               DATE_FORMAT(n.created_at,'%H:%i') AS created_time,
               b.id AS board_id, b.name AS board_name,
               bc.name AS cat_name, bc.color AS cat_color,
               au.cached_name  AS creator_name,
               au.cached_photo AS creator_photo
        FROM notes n
        JOIN boards b ON b.id = n.board_id
        LEFT JOIN board_categories bc ON bc.id = b.category_id
        LEFT JOIN app_users au ON au.prolegal_id = n.created_by
        WHERE DATE(n.created_at) = CURDATE()
          AND (
              b.created_by = ?
              OR EXISTS (SELECT 1 FROM board_members bm WHERE bm.board_id = b.id AND bm.prolegal_id = ?)
              OR EXISTS (SELECT 1 FROM category_users cu WHERE cu.prolegal_id = ? AND cu.category_id = b.category_id)
          )
        ORDER BY n.created_at DESC
        LIMIT 30
    ");
    $todayNotes->execute([$user['id'], $user['id'], $user['id']]);
    $todayNotes = $todayNotes->fetchAll();
}

// ── Usuarios etiquetados para las tareas de hoy ───────────────────────────────
$todayTaggedMap = [];
if (!empty($todayNotes)) {
    $todayIds = implode(',', array_map('intval', array_column($todayNotes, 'id')));
    try {
        $tuRows = $pdo->query("
            SELECT nu.note_id, nu.prolegal_id, au.cached_name AS name, au.cached_photo AS photo
            FROM note_users nu
            JOIN app_users au ON au.prolegal_id = nu.prolegal_id
            WHERE nu.note_id IN ($todayIds)
            ORDER BY au.cached_name ASC
        ")->fetchAll();
        foreach ($tuRows as $row) {
            $todayTaggedMap[$row['note_id']][] = $row;
        }
    } catch (Exception $e) {
        $todayTaggedMap = [];
    }
}

$statusLabel = ['pending'=>'Pendiente','in_progress'=>'En proceso','completed'=>'Completado'];
$statusBadge = ['pending'=>'badge-amber','in_progress'=>'badge-cyan','completed'=>'badge-green'];
$statusColor = ['pending'=>'#f59e0b','in_progress'=>'#0099cd','completed'=>'#10b981'];

$pageTitle = 'Dashboard';
include __DIR__ . '/includes/layout.php';
?>

<!-- ── Bienvenida ─────────────────────────────────────────────────────────── -->
<div class="card p-5 mb-6" style="background:linear-gradient(135deg,#162259 0%,#0d1a3e 100%);border:none">
    <div class="flex items-center gap-4">
        <div style="position:relative;flex-shrink:0">
            <img src="<?= htmlspecialchars(userPhotoUrl($user['photo'])) ?>"
                 alt="<?= htmlspecialchars($user['name']) ?>"
                 class="rounded-full object-cover"
                 style="width:60px;height:60px;border:2.5px solid rgba(0,153,205,0.5)"
                 onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
            <div style="position:absolute;bottom:1px;right:1px;width:12px;height:12px;background:#10b981;border-radius:50%;border:2px solid #162259"></div>
        </div>
        <div style="flex:1;min-width:0">
            <p class="text-white/50 text-xs mb-0.5">Bienvenido de vuelta</p>
            <h2 class="font-jakarta font-bold text-white text-lg truncate"><?= htmlspecialchars($user['name']) ?></h2>
            <?php if ($isAdminUser): ?>
            <span class="badge" style="background:rgba(0,153,205,0.25);color:#7dd3fc;font-size:10px;margin-top:4px">
                <i class="fa-solid fa-shield-halved" style="font-size:8px"></i> Administrador
            </span>
            <?php endif; ?>
        </div>
        <div class="hidden sm:block text-right flex-shrink-0">
            <p class="text-white/40 text-xs">Hoy</p>
            <p class="text-white font-semibold text-sm"><?= date('d/m/Y') ?></p>
            <p class="text-white/40 text-xs"><?= date('H:i') ?></p>
        </div>
    </div>
</div>

<!-- ── Estadísticas ─────────────────────────────────────────────────────────── -->
<div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4 mb-8">
    <?php
    $stats = [
        ['icon'=>'fa-tags',         'color'=>'#162259', 'bg'=>'rgba(22,34,89,0.08)',   'val'=>$totalSectors,  'label'=>'Sectores'],
        ['icon'=>'fa-layer-group',  'color'=>'#0099cd', 'bg'=>'rgba(0,153,205,0.1)',   'val'=>$totalGroups,   'label'=>'Grupos de tareas'],
        ['icon'=>'fa-list-check',   'color'=>'#6b7280', 'bg'=>'rgba(107,114,128,0.1)','val'=>$totalTasks,    'label'=>'Tareas en total'],
        ['icon'=>'fa-hourglass-half','color'=>'#f59e0b','bg'=>'rgba(245,158,11,0.1)', 'val'=>$cntPending,    'label'=>'Pendientes'],
        ['icon'=>'fa-spinner',      'color'=>'#0099cd', 'bg'=>'rgba(0,153,205,0.1)',   'val'=>$cntInProgress, 'label'=>'En proceso'],
        ['icon'=>'fa-circle-check', 'color'=>'#10b981', 'bg'=>'rgba(16,185,129,0.1)', 'val'=>$cntCompleted,  'label'=>'Completadas'],
    ];
    foreach ($stats as $s): ?>
    <div class="card p-4">
        <div style="background:<?= $s['bg'] ?>;border-radius:10px;padding:9px;width:38px;height:38px;display:flex;align-items:center;justify-content:center;margin-bottom:8px">
            <i class="fa-solid <?= $s['icon'] ?>" style="color:<?= $s['color'] ?>;font-size:14px"></i>
        </div>
        <p class="font-jakarta font-extrabold text-gray-900" style="font-size:24px;line-height:1"><?= $s['val'] ?></p>
        <p class="text-gray-500 text-xs mt-1"><?= $s['label'] ?></p>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── HOY ────────────────────────────────────────────────────────────────── -->
<div class="flex items-center gap-3 mb-4">
    <div style="background:linear-gradient(135deg,#162259,#0099cd);border-radius:10px;padding:8px 14px;display:inline-flex;align-items:center;gap:8px">
        <i class="fa-solid fa-calendar-day text-white text-sm"></i>
        <span class="font-jakarta font-bold text-white text-sm">HOY</span>
        <span style="background:rgba(255,255,255,0.2);color:white;border-radius:99px;padding:1px 8px;font-size:11px;font-weight:700"><?= count($todayNotes) ?></span>
    </div>
    <p class="text-gray-400 text-sm">Tareas creadas el <?= date('d/m/Y') ?></p>
</div>

<?php if (empty($todayNotes)): ?>
<div class="card p-12 text-center">
    <i class="fa-solid fa-sun text-4xl mb-3" style="color:#fbbf24"></i>
    <p class="font-jakarta font-bold text-gray-600">Sin tareas nuevas hoy</p>
    <p class="text-gray-400 text-sm mt-1">Las tareas que se creen hoy aparecerán aquí.</p>
</div>
<?php else: ?>
<div class="space-y-3">
    <?php foreach ($todayNotes as $n):
        $tags = $todayTaggedMap[$n['id']] ?? [];
        $sc   = $statusColor[$n['status']] ?? '#6b7280';
    ?>
    <a href="<?= BASE_URL ?>/board.php?id=<?= $n['board_id'] ?>"
       class="card block"
       style="padding:14px 18px;border-left:4px solid <?= htmlspecialchars($sc) ?>;text-decoration:none;transition:all .18s"
       onmouseenter="this.style.transform='translateY(-1px)';this.style.boxShadow='0 4px 16px rgba(0,0,0,0.08)'"
       onmouseleave="this.style.transform='';this.style.boxShadow=''">
        <div class="flex items-start gap-3">
            <!-- Hora -->
            <div style="flex-shrink:0;text-align:center;min-width:44px">
                <p class="font-jakarta font-bold" style="color:<?= htmlspecialchars($sc) ?>;font-size:13px"><?= $n['created_time'] ?></p>
                <p class="text-xs text-gray-400">hs</p>
            </div>
            <div style="flex:1;min-width:0">
                <!-- Título + estado -->
                <div class="flex items-start justify-between gap-2 mb-1">
                    <h4 class="font-jakarta font-bold text-gray-900 text-sm leading-snug truncate"><?= htmlspecialchars($n['title']) ?></h4>
                    <span class="badge <?= $statusBadge[$n['status']] ?> flex-shrink-0 text-xs"><?= $statusLabel[$n['status']] ?></span>
                </div>
                <!-- Descripción si existe -->
                <?php if ($n['description']): ?>
                <p class="text-gray-500 text-xs leading-relaxed mb-1.5 line-clamp-1"><?= htmlspecialchars($n['description']) ?></p>
                <?php endif; ?>
                <!-- Meta: sector / grupo / creador / etiquetados -->
                <div class="flex items-center gap-3 flex-wrap text-xs text-gray-400">
                    <?php if ($n['cat_name']): ?>
                    <span style="color:<?= htmlspecialchars($n['cat_color']) ?>;font-weight:600">
                        <i class="fa-solid fa-tags" style="font-size:9px;margin-right:3px"></i><?= htmlspecialchars($n['cat_name']) ?>
                    </span>
                    <span>→</span>
                    <?php endif; ?>
                    <span style="color:#374151;font-weight:500">
                        <i class="fa-solid fa-layer-group" style="font-size:9px;margin-right:3px;color:#0099cd"></i><?= htmlspecialchars($n['board_name']) ?>
                    </span>
                    <span class="text-gray-300">·</span>
                    <!-- Creador -->
                    <div class="flex items-center gap-1.5">
                        <img src="<?= htmlspecialchars(userPhotoUrl($n['creator_photo']??'')) ?>"
                             style="width:16px;height:16px;border-radius:50%;object-fit:cover;flex-shrink:0"
                             onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
                        <span><?= htmlspecialchars($n['creator_name']??'—') ?></span>
                    </div>
                    <?php if (!empty($tags)): ?>
                    <span class="text-gray-300">·</span>
                    <div class="flex items-center gap-1">
                        <i class="fa-solid fa-user-tag" style="font-size:9px;color:#0099cd"></i>
                        <div class="flex -space-x-0.5">
                            <?php foreach (array_slice($tags, 0, 3) as $tu): ?>
                            <img src="<?= htmlspecialchars(userPhotoUrl($tu['photo']??'')) ?>"
                                 style="width:16px;height:16px;border-radius:50%;object-fit:cover;border:1.5px solid white"
                                 title="<?= htmlspecialchars($tu['name']) ?>"
                                 onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
                            <?php endforeach; ?>
                            <?php if (count($tags) > 3): ?>
                            <span class="ml-1">+<?= count($tags)-3 ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>const BASE_URL = '<?= BASE_URL ?>';</script>
<?php include __DIR__ . '/includes/layout_end.php'; ?>
