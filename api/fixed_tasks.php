<?php
// api/fixed_tasks.php — CRUD de tareas fijas
require_once __DIR__ . '/../includes/auth.php';
requireAuth();

header('Content-Type: application/json');

$user  = currentUser();
$myId  = $user['id'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

// ── Helper ────────────────────────────────────────────────────────────────────
function jsonOk(array $data = [])  { echo json_encode(['success' => true]  + $data); exit; }
function jsonErr(string $msg)      { echo json_encode(['success' => false, 'error' => $msg]); exit; }

// ── Acciones ──────────────────────────────────────────────────────────────────
switch ($action) {

    // ── Agregar tarea ─────────────────────────────────────────────────────────
    case 'add':
        $task = trim($input['task'] ?? '');
        if ($task === '') jsonErr('La tarea no puede estar vacía.');
        if (mb_strlen($task) > 500) jsonErr('La tarea es demasiado larga (máx 500 caracteres).');

        // Obtener el próximo orden para este usuario
        $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(task_order), -1) + 1 FROM fixed_tasks WHERE prolegal_id = ?");
        $maxOrder->execute([$myId]);
        $nextOrder = (int)$maxOrder->fetchColumn();

        $ins = $pdo->prepare("INSERT INTO fixed_tasks (prolegal_id, task, task_order) VALUES (?, ?, ?)");
        $ins->execute([$myId, $task, $nextOrder]);
        $newId = (int)$pdo->lastInsertId();

        jsonOk(['id' => $newId, 'task' => htmlspecialchars($task), 'task_order' => $nextOrder]);

    // ── Eliminar tarea ────────────────────────────────────────────────────────
    case 'delete':
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) jsonErr('ID inválido.');

        $check = $pdo->prepare("SELECT prolegal_id FROM fixed_tasks WHERE id = ?");
        $check->execute([$id]);
        $row = $check->fetch();
        if (!$row) jsonErr('Tarea no encontrada.');
        if ((int)$row['prolegal_id'] !== $myId && !isAdmin($myId)) jsonErr('Sin permiso.');

        $del = $pdo->prepare("DELETE FROM fixed_tasks WHERE id = ?");
        $del->execute([$id]);
        jsonOk();

    // ── Editar texto de una tarea ─────────────────────────────────────────────
    case 'edit':
        $id   = (int)($input['id'] ?? 0);
        $task = trim($input['task'] ?? '');
        if ($id <= 0) jsonErr('ID inválido.');
        if ($task === '') jsonErr('La tarea no puede estar vacía.');
        if (mb_strlen($task) > 500) jsonErr('La tarea es demasiado larga (máx 500 caracteres).');

        $check = $pdo->prepare("SELECT prolegal_id FROM fixed_tasks WHERE id = ?");
        $check->execute([$id]);
        $row = $check->fetch();
        if (!$row) jsonErr('Tarea no encontrada.');
        if ((int)$row['prolegal_id'] !== $myId && !isAdmin($myId)) jsonErr('Sin permiso.');

        $upd = $pdo->prepare("UPDATE fixed_tasks SET task = ? WHERE id = ?");
        $upd->execute([$task, $id]);
        jsonOk(['task' => htmlspecialchars($task)]);

    // ── Agendar tarea (fecha + hora opcional) ─────────────────────────────────
    case 'schedule':
        $id   = (int)($input['id'] ?? 0);
        $date = isset($input['scheduled_date']) ? trim($input['scheduled_date']) : null;
        $time = isset($input['scheduled_time']) ? trim($input['scheduled_time']) : null;

        if ($id <= 0) jsonErr('ID inválido.');

        $check = $pdo->prepare("SELECT prolegal_id FROM fixed_tasks WHERE id = ?");
        $check->execute([$id]);
        $row = $check->fetch();
        if (!$row) jsonErr('Tarea no encontrada.');
        if ((int)$row['prolegal_id'] !== $myId && !isAdmin($myId)) jsonErr('Sin permiso.');

        // Validar formato de fecha
        if ($date !== null && $date !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jsonErr('Formato de fecha inválido.');
        } else {
            $date = null;
        }

        // Validar y normalizar hora (HH:MM)
        if ($time !== null && $time !== '') {
            if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) jsonErr('Formato de hora inválido.');
            $time = substr($time, 0, 5); // Guardar solo HH:MM
        } else {
            $time = null;
        }

        $upd = $pdo->prepare("UPDATE fixed_tasks SET scheduled_date = ?, scheduled_time = ? WHERE id = ?");
        $upd->execute([$date, $time, $id]);

        jsonOk(['scheduled_date' => $date, 'scheduled_time' => $time]);

    // ── Reordenar tareas ──────────────────────────────────────────────────────
    case 'reorder':
        $ids = $input['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) jsonErr('Lista de IDs inválida.');

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $verify = $pdo->prepare("SELECT id, prolegal_id FROM fixed_tasks WHERE id IN ($placeholders)");
        $verify->execute($ids);
        foreach ($verify->fetchAll() as $r) {
            if ((int)$r['prolegal_id'] !== $myId && !isAdmin($myId)) jsonErr('Sin permiso en tarea ' . $r['id'] . '.');
        }

        $upd = $pdo->prepare("UPDATE fixed_tasks SET task_order = ? WHERE id = ?");
        foreach (array_values($ids) as $order => $id) {
            $upd->execute([$order, (int)$id]);
        }
        jsonOk();

    default:
        jsonErr('Acción desconocida.');
}
