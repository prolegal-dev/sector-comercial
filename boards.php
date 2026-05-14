<?php
require_once __DIR__ . '/includes/auth.php';
requireAuth();
$user        = currentUser();
$isAdminUser = isAdmin($user['id']);
if (!$isAdminUser) { header('Location: ' . BASE_URL . '/index.php'); exit; }

$error = $success = '';

// Crear espacio de trabajo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'create') {
    $name   = trim($_POST['name']        ?? '');
    $desc   = trim($_POST['description'] ?? '') ?: null;
    $catId  = intval($_POST['category_id'] ?? 0) ?: null;
    $color  = $_POST['color'] ?? '#162259';
    if ($name) {
        $pdo->prepare(
            "INSERT INTO boards (name, description, category_id, color, created_by) VALUES (?,?,?,?,?)"
        )->execute([$name, $desc, $catId, $color, $user['id']]);
        $newId = $pdo->lastInsertId();

        // Agregar al creador como miembro
        $pdo->prepare("INSERT IGNORE INTO board_members (board_id, prolegal_id, added_by) VALUES (?,?,?)")
            ->execute([$newId, $user['id'], $user['id']]);

        // Si el tablero tiene sector, agregar automáticamente todos sus usuarios como miembros
        if ($catId) {
            $sectorUsers = $pdo->prepare(
                "SELECT prolegal_id FROM category_users WHERE category_id = ?"
            );
            $sectorUsers->execute([$catId]);
            $addStmt = $pdo->prepare(
                "INSERT IGNORE INTO board_members (board_id, prolegal_id, added_by) VALUES (?,?,?)"
            );
            foreach ($sectorUsers->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $addStmt->execute([$newId, $pid, $user['id']]);
            }
        }

        header('Location: ' . BASE_URL . '/board.php?id=' . $newId . '&created=1');
        exit;
    } else { $error = 'El nombre es obligatorio.'; }
}

// Archivar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'archive') {
    $id = intval($_POST['board_id'] ?? 0);
    $pdo->prepare("UPDATE boards SET is_archived=1 WHERE id=?")->execute([$id]);
    $success = 'Espacio archivado.';
}

$categories = $pdo->query("SELECT * FROM board_categories ORDER BY name")->fetchAll();
$boards     = $pdo->query("
    SELECT b.*, bc.name AS cat_name, bc.color AS cat_color,
           COUNT(DISTINCT n.id)  AS note_count,
           COUNT(DISTINCT bm.prolegal_id) AS member_count
    FROM boards b
    LEFT JOIN board_categories bc ON bc.id = b.category_id
    LEFT JOIN notes n             ON n.board_id = b.id
    LEFT JOIN board_members bm    ON bm.board_id = b.id
    WHERE b.is_archived = 0
    GROUP BY b.id
    ORDER BY b.created_at DESC
")->fetchAll();

$showModal = isset($_GET['new']);
$pageTitle = 'Espacios de trabajo';
include __DIR__ . '/includes/layout.php';
?>

<div class="flex items-center justify-between mb-6">
    <p class="text-gray-500 text-sm"><?= count($boards) ?> espacio<?= count($boards)!=1?'s':'' ?></p>
    <button onclick="openModal('modal-board')" class="btn-primary">
        <i class="fa-solid fa-plus"></i> Nuevo espacio
    </button>
</div>

<?php if ($error):   ?><div class="mb-4 p-4 rounded-xl text-sm" style="background:#fef2f2;border:1px solid #fecaca;color:#dc2626"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="mb-4 p-4 rounded-xl text-sm" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<?php if (empty($boards)): ?>
<div class="card p-20 text-center">
    <i class="fa-solid fa-table-columns text-6xl mb-5" style="color:#e5e7eb"></i>
    <p class="font-jakarta font-bold text-gray-700 text-xl">Sin espacios de trabajo</p>
    <p class="text-gray-400 text-sm mt-2 mb-6">Creá el primero para organizar las notas del equipo</p>
    <button onclick="openModal('modal-board')" class="btn-primary">
        <i class="fa-solid fa-plus"></i> Crear primer espacio
    </button>
</div>
<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">
    <?php foreach ($boards as $b): ?>
    <div class="card overflow-hidden" style="border-top:4px solid <?= htmlspecialchars($b['color']) ?>">
        <div class="p-5">
            <div class="flex items-start justify-between">
                <div style="flex:1;min-width:0">
                    <h3 class="font-jakarta font-bold text-gray-900 text-base truncate"><?= htmlspecialchars($b['name']) ?></h3>
                    <?php if ($b['cat_name']): ?>
                    <span class="badge mt-1.5" style="background:<?= htmlspecialchars($b['cat_color']) ?>20;color:<?= htmlspecialchars($b['cat_color']) ?>"><?= htmlspecialchars($b['cat_name']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="flex gap-2 ml-2">
                    <a href="<?= BASE_URL ?>/board.php?id=<?= $b['id'] ?>" class="btn-primary text-xs" style="padding:7px 12px">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    </a>
                    <form method="POST" style="display:inline" onsubmit="return confirm('¿Archivar este espacio?')">
                        <input type="hidden" name="_action" value="archive">
                        <input type="hidden" name="board_id" value="<?= $b['id'] ?>">
                        <button type="submit" class="btn-ghost text-xs" style="padding:7px 12px">
                            <i class="fa-solid fa-box-archive"></i>
                        </button>
                    </form>
                </div>
            </div>
            <?php if ($b['description']): ?>
            <p class="text-gray-500 text-sm mt-3 line-clamp-2"><?= htmlspecialchars($b['description']) ?></p>
            <?php endif; ?>
            <div class="flex gap-4 mt-4 text-sm text-gray-500">
                <span><i class="fa-solid fa-sticky-note mr-1 text-xs" style="color:#0099cd"></i><strong class="text-gray-800"><?= $b['note_count'] ?></strong> nota<?= $b['note_count']!=1?'s':'' ?></span>
                <span><i class="fa-solid fa-users mr-1 text-xs" style="color:#162259"></i><strong class="text-gray-800"><?= $b['member_count'] ?></strong> miembro<?= $b['member_count']!=1?'s':'' ?></span>
            </div>
        </div>
        <div style="padding:9px 20px;background:#fafafa;border-top:1px solid #f3f4f6">
            <span class="text-xs text-gray-400">Creado <?= date('d/m/Y', strtotime($b['created_at'])) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Modal nuevo espacio -->
<div id="modal-board" class="modal-overlay" style="display:none">
    <div class="modal-box">
        <div class="flex items-center justify-between mb-5">
            <h3 class="font-jakarta font-bold text-gray-900 text-lg">Nuevo espacio de trabajo</h3>
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
                    <label class="form-label">Color identificador</label>
                    <input type="color" name="color" value="#162259" class="form-input" style="padding:4px;height:42px">
                </div>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeModal('modal-board')" class="btn-ghost flex-1">Cancelar</button>
                <button type="submit" class="btn-primary flex-1"><i class="fa-solid fa-plus"></i> Crear espacio</button>
            </div>
        </form>
    </div>
</div>

<script>
const BASE_URL = '<?= BASE_URL ?>';
<?php if ($showModal): ?>openModal('modal-board');<?php endif; ?>
</script>
<?php include __DIR__ . '/includes/layout_end.php'; ?>
