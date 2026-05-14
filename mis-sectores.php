<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();
$user        = currentUser();
$isAdminUser = isAdmin($user['id']);

// ── Stats globales del usuario ────────────────────────────────────────────────
$statsRow = $pdo->prepare("
    SELECT
        COUNT(DISTINCT bc.id)                          AS sector_count,
        COUNT(DISTINCT b.id)                           AS board_count,
        COUNT(DISTINCT n.id)                           AS note_count,
        COALESCE(SUM(n.status = 'pending'),     0)     AS pending,
        COALESCE(SUM(n.status = 'in_progress'), 0)     AS in_progress,
        COALESCE(SUM(n.status = 'completed'),   0)     AS completed
    FROM category_users cu
    JOIN board_categories bc ON bc.id = cu.category_id
    LEFT JOIN boards b  ON b.category_id = bc.id AND b.is_archived = 0
    LEFT JOIN notes n   ON n.board_id = b.id
    WHERE cu.prolegal_id = ?
");
$statsRow->execute([$user['id']]);
$stats = $statsRow->fetch();

// ── Sectores + grupos del usuario ─────────────────────────────────────────────
$rows = $pdo->prepare("
    SELECT
        bc.id    AS cat_id,
        bc.name  AS cat_name,
        bc.color AS cat_color,
        b.id          AS board_id,
        b.name        AS board_name,
        b.color       AS board_color,
        b.description AS board_desc,
        COALESCE(SUM(n.status = 'pending'),     0) AS pending,
        COALESCE(SUM(n.status = 'in_progress'), 0) AS in_progress,
        COALESCE(SUM(n.status = 'completed'),   0) AS completed,
        COUNT(DISTINCT n.id)              AS note_count,
        COUNT(DISTINCT bm.prolegal_id)    AS member_count
    FROM category_users cu
    JOIN board_categories bc ON bc.id = cu.category_id
    LEFT JOIN boards b   ON b.category_id = bc.id AND b.is_archived = 0
    LEFT JOIN notes n    ON n.board_id = b.id
    LEFT JOIN board_members bm ON bm.board_id = b.id
    WHERE cu.prolegal_id = ?
    GROUP BY bc.id, b.id
    ORDER BY bc.name ASC, b.name ASC
");
$rows->execute([$user['id']]);

// Agrupar por sector
$sectors = [];
foreach ($rows->fetchAll() as $row) {
    $cid = $row['cat_id'];
    if (!isset($sectors[$cid])) {
        $sectors[$cid] = [
            'id'     => $cid,
            'name'   => $row['cat_name'],
            'color'  => $row['cat_color'],
            'boards' => [],
        ];
    }
    if ($row['board_id']) {
        $sectors[$cid]['boards'][] = $row;
    }
}

$pageTitle = 'Mis Sectores';
include __DIR__ . '/includes/layout.php';
?>

<!-- ── Encabezado ─────────────────────────────────────────────────────────── -->
<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="font-jakarta font-bold text-gray-900 text-xl">Mis Sectores</h2>
        <p class="text-gray-400 text-sm mt-0.5">Tus sectores y grupos de tareas asignados</p>
    </div>
</div>

<?php if (empty($sectors)): ?>
<!-- Estado vacío -->
<div class="card p-20 text-center">
    <i class="fa-solid fa-layer-group text-5xl mb-4" style="color:#e5e7eb"></i>
    <p class="font-jakarta font-bold text-gray-600 text-lg">Sin sectores asignados</p>
    <p class="text-gray-400 text-sm mt-1">Un administrador debe asignarte a un sector para que aparezca aquí.</p>
</div>
<?php else: ?>

<!-- ── Dashboard estadístico ─────────────────────────────────────────────── -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
    <?php
    $statCards = [
        ['icon'=>'fa-layer-group',  'color'=>'#162259', 'bg'=>'rgba(22,34,89,0.08)',   'val'=>$stats['sector_count'], 'label'=>'Sectores'],
        ['icon'=>'fa-list-check',   'color'=>'#0099cd', 'bg'=>'rgba(0,153,205,0.1)',   'val'=>$stats['board_count'],  'label'=>'Grupos de tareas'],
        ['icon'=>'fa-clipboard',    'color'=>'#6b7280', 'bg'=>'rgba(107,114,128,0.1)','val'=>$stats['note_count'],   'label'=>'Tareas totales'],
        ['icon'=>'fa-hourglass-half','color'=>'#f59e0b','bg'=>'rgba(245,158,11,0.1)', 'val'=>$stats['pending'],      'label'=>'Pendientes'],
        ['icon'=>'fa-circle-check', 'color'=>'#10b981', 'bg'=>'rgba(16,185,129,0.1)', 'val'=>$stats['completed'],    'label'=>'Completadas'],
    ];
    foreach ($statCards as $s): ?>
    <div class="card p-5">
        <div style="background:<?= $s['bg'] ?>;border-radius:10px;padding:10px;width:40px;height:40px;
                    display:flex;align-items:center;justify-content:center;margin-bottom:10px">
            <i class="fa-solid <?= $s['icon'] ?>" style="color:<?= $s['color'] ?>;font-size:16px"></i>
        </div>
        <p class="font-jakarta font-extrabold text-gray-900" style="font-size:26px;line-height:1">
            <?= $s['val'] ?>
        </p>
        <p class="text-gray-500 text-xs mt-1"><?= $s['label'] ?></p>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Sectores y sus grupos de tareas ───────────────────────────────────── -->
<div class="space-y-8">
<?php foreach ($sectors as $sector):
    $totalSectorNotes = array_sum(array_column($sector['boards'], 'note_count'));
    $totalPending     = array_sum(array_column($sector['boards'], 'pending'));
    $totalCompleted   = array_sum(array_column($sector['boards'], 'completed'));
?>

    <!-- Encabezado del sector -->
    <div>
        <div class="flex items-center gap-3 mb-4">
            <div style="width:14px;height:14px;border-radius:4px;background:<?= htmlspecialchars($sector['color']) ?>;flex-shrink:0"></div>
            <h3 class="font-jakarta font-bold text-gray-800 text-lg"><?= htmlspecialchars($sector['name']) ?></h3>
            <span class="badge" style="background:<?= htmlspecialchars($sector['color']) ?>20;color:<?= htmlspecialchars($sector['color']) ?>">
                <?= count($sector['boards']) ?> grupo<?= count($sector['boards']) != 1 ? 's' : '' ?>
            </span>
            <div class="hidden sm:flex items-center gap-3 ml-auto text-xs text-gray-500">
                <span><strong class="text-amber-500"><?= $totalPending ?></strong> pendientes</span>
                <span><strong class="text-emerald-600"><?= $totalCompleted ?></strong> completadas</span>
                <span><strong class="text-gray-700"><?= $totalSectorNotes ?></strong> tareas total</span>
            </div>
        </div>

        <?php if (empty($sector['boards'])): ?>
        <div class="card p-8 text-center" style="border-left:4px solid <?= htmlspecialchars($sector['color']) ?>">
            <i class="fa-solid fa-triangle-exclamation text-2xl mb-2" style="color:#fbbf24"></i>
            <p class="font-semibold text-gray-600 text-sm mb-1">Este sector aún no tiene grupos de tareas</p>
            <p class="text-gray-400 text-xs mb-4">Creá el primer grupo para empezar a organizar las tareas.</p>
            <a href="<?= BASE_URL ?>/grupos.php?new=1" class="btn-primary" style="font-size:13px;padding:8px 16px">
                <i class="fa-solid fa-plus"></i> Crear grupo de tareas
            </a>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
            <?php foreach ($sector['boards'] as $b):
                $total = max((int)$b['note_count'], 1);
                $pct_p = round($b['pending']     / $total * 100);
                $pct_i = round($b['in_progress'] / $total * 100);
                $pct_c = round($b['completed']   / $total * 100);
            ?>
            <a href="<?= BASE_URL ?>/board.php?id=<?= $b['board_id'] ?>"
               class="card card-hover p-0 overflow-hidden block"
               style="border-top:4px solid <?= htmlspecialchars($b['board_color']) ?>;text-decoration:none">
                <div class="p-5">
                    <div class="flex items-start justify-between mb-3">
                        <div style="flex:1;min-width:0">
                            <h4 class="font-jakarta font-bold text-gray-900 text-sm truncate">
                                <?= htmlspecialchars($b['board_name']) ?>
                            </h4>
                            <?php if ($b['board_desc']): ?>
                            <p class="text-gray-400 text-xs mt-0.5 line-clamp-1"><?= htmlspecialchars($b['board_desc']) ?></p>
                            <?php endif; ?>
                        </div>
                        <i class="fa-solid fa-arrow-up-right-from-square text-gray-300 text-xs ml-2 mt-0.5 flex-shrink-0"></i>
                    </div>

                    <!-- Barra de progreso -->
                    <div style="display:flex;height:5px;border-radius:99px;overflow:hidden;background:#f3f4f6;margin-bottom:8px">
                        <?php if ($b['pending']     > 0): ?><div style="width:<?= $pct_p ?>%;background:#f59e0b"></div><?php endif; ?>
                        <?php if ($b['in_progress'] > 0): ?><div style="width:<?= $pct_i ?>%;background:#0099cd"></div><?php endif; ?>
                        <?php if ($b['completed']   > 0): ?><div style="width:<?= $pct_c ?>%;background:#10b981"></div><?php endif; ?>
                    </div>

                    <div class="flex gap-3 text-xs text-gray-500">
                        <span><strong class="text-amber-500"><?= $b['pending'] ?></strong> pend.</span>
                        <span><strong style="color:#0099cd"><?= $b['in_progress'] ?></strong> proceso</span>
                        <span><strong class="text-emerald-600"><?= $b['completed'] ?></strong> listas</span>
                    </div>
                </div>
                <div style="border-top:1px solid #f3f4f6;padding:8px 16px;background:#fafafa;
                            display:flex;justify-content:space-between;align-items:center">
                    <span class="text-xs text-gray-400">
                        <i class="fa-solid fa-list-check mr-1" style="color:#0099cd"></i><?= $b['note_count'] ?> tarea<?= $b['note_count'] != 1 ? 's' : '' ?>
                    </span>
                    <span class="text-xs text-gray-400">
                        <i class="fa-solid fa-users mr-1"></i><?= $b['member_count'] ?> miembro<?= $b['member_count'] != 1 ? 's' : '' ?>
                    </span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

<?php endforeach; ?>
</div>

<?php endif; ?>

<?php include __DIR__ . '/includes/layout_end.php'; ?>
