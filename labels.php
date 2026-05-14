<?php
// labels.php
require_once __DIR__ . '/includes/auth.php';
requireAuth();
if (!isAdmin()) { header('Location: ' . BASE_URL . '/index.php'); exit; }
$user = currentUser();
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['_action'] ?? '';
    if ($a === 'create') {
        $name = trim($_POST['name']??''); $color = $_POST['color']??'#162259';
        if ($name) { $pdo->prepare("INSERT INTO labels (name,color) VALUES (?,?)")->execute([$name,$color]); $success='Etiqueta creada.'; }
    } elseif ($a === 'delete') {
        $id = intval($_POST['id']??0);
        $pdo->prepare("UPDATE notes SET label_id=NULL WHERE label_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM labels WHERE id=?")->execute([$id]);
        $success='Etiqueta eliminada.';
    }
}
$labels = $pdo->query("SELECT l.*, COUNT(n.id) AS note_count FROM labels l LEFT JOIN notes n ON n.label_id=l.id GROUP BY l.id ORDER BY l.name")->fetchAll();
$pageTitle = 'Etiquetas';
include __DIR__ . '/includes/layout.php';
?>
<?php if ($success): ?><div class="mb-4 p-4 rounded-xl text-sm" style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 card overflow-hidden">
        <div style="padding:18px 20px;border-bottom:1.5px solid #f3f4f6">
            <h3 class="font-jakarta font-bold text-gray-900">Etiquetas disponibles</h3>
        </div>
        <?php if (empty($labels)): ?>
        <div class="p-10 text-center text-gray-400">Sin etiquetas creadas</div>
        <?php else: ?>
        <div class="divide-y divide-gray-100">
            <?php foreach ($labels as $l): ?>
            <div class="flex items-center justify-between p-4">
                <div class="flex items-center gap-3">
                    <span class="badge" style="background:<?= htmlspecialchars($l['color']) ?>20;color:<?= htmlspecialchars($l['color']) ?>"><?= htmlspecialchars($l['name']) ?></span>
                    <span class="badge badge-gray"><?= $l['note_count'] ?> nota<?= $l['note_count']!=1?'s':'' ?></span>
                </div>
                <form method="POST" onsubmit="return confirm('¿Eliminar esta etiqueta?')">
                    <input type="hidden" name="_action" value="delete">
                    <input type="hidden" name="id" value="<?= $l['id'] ?>">
                    <button type="submit" class="btn-danger text-xs" style="padding:5px 10px"><i class="fa-solid fa-trash"></i></button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="card p-6">
        <h3 class="font-jakarta font-bold text-gray-900 mb-4">Nueva etiqueta</h3>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="_action" value="create">
            <div><label class="form-label">Nombre *</label><input type="text" name="name" class="form-input" required maxlength="50"></div>
            <div><label class="form-label">Color</label><input type="color" name="color" value="#0099cd" class="form-input" style="padding:4px;height:42px"></div>
            <button type="submit" class="btn-primary w-full"><i class="fa-solid fa-plus"></i> Crear etiqueta</button>
        </form>
    </div>
</div>
<script>const BASE_URL = '<?= BASE_URL ?>';</script>
<?php include __DIR__ . '/includes/layout_end.php'; ?>
