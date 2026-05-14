<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

if (!tryAutoLogin()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$user  = currentUser();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';
$ip    = getClientIP();

// Helper: sincronizar usuarios etiquetados en una nota
function syncTaggedUsers(int $noteId, array $taggedIds, int $addedBy): void {
    global $pdo;
    try {
        // Eliminar etiquetas previas
        $pdo->prepare("DELETE FROM note_users WHERE note_id = ?")->execute([$noteId]);
        // Insertar nuevas etiquetas (solo IDs válidos de app_users activos)
        if (!empty($taggedIds)) {
            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO note_users (note_id, prolegal_id, added_by) VALUES (?,?,?)"
            );
            foreach ($taggedIds as $uid) {
                $uid = intval($uid);
                if ($uid) {
                    // Verificar que el usuario está habilitado
                    $check = $pdo->prepare("SELECT prolegal_id FROM app_users WHERE prolegal_id=? AND is_active=1 LIMIT 1");
                    $check->execute([$uid]);
                    if ($check->fetch()) {
                        $stmt->execute([$noteId, $uid, $addedBy]);
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Tabla note_users puede no existir (requiere migration_001.sql)
    }
}

// Helper: obtener usuarios etiquetados de una nota
function getTaggedUsers(int $noteId): array {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT nu.prolegal_id, au.cached_name AS name, au.cached_photo AS photo
            FROM note_users nu
            JOIN app_users au ON au.prolegal_id = nu.prolegal_id
            WHERE nu.note_id = ?
            ORDER BY au.cached_name ASC
        ");
        $stmt->execute([$noteId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

try {
    switch ($action) {

        case 'create': {
            $boardId     = intval($input['board_id'] ?? 0);
            $title       = trim($input['title'] ?? '');
            $desc        = trim($input['description'] ?? '') ?: null;
            $status      = in_array($input['status']??'', ['pending','in_progress','completed'])
                           ? $input['status'] : 'pending';
            $labelId     = $input['label_id'] ? intval($input['label_id']) : null;
            $taggedUsers = $input['tagged_users'] ?? [];

            // Fecha y hora opcionales
            $schedDate = trim($input['scheduled_date'] ?? '');
            $schedTime = trim($input['scheduled_time'] ?? '');
            $schedDate = ($schedDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $schedDate)) ? $schedDate : null;
            $schedTime = ($schedTime && preg_match('/^\d{2}:\d{2}/', $schedTime)) ? substr($schedTime, 0, 5) : null;

            if (!$boardId || !$title) throw new Exception('Datos inválidos');

            $b = $pdo->prepare("SELECT id FROM boards WHERE id=? AND is_archived=0");
            $b->execute([$boardId]);
            if (!$b->fetch()) throw new Exception('Grupo no encontrado');

            $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(note_order),0)+1 FROM notes WHERE board_id=?");
            $maxOrder->execute([$boardId]);
            $order = $maxOrder->fetchColumn();

            $pdo->prepare(
                "INSERT INTO notes (board_id, title, description, status, label_id, scheduled_date, scheduled_time, created_by, created_ip, note_order)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            )->execute([$boardId, $title, $desc, $status, $labelId, $schedDate, $schedTime, $user['id'], $ip, $order]);
            $noteId = $pdo->lastInsertId();

            // Historial
            $pdo->prepare(
                "INSERT INTO note_history (note_id, from_status, to_status, changed_by, changed_ip, action)
                 VALUES (?, NULL, ?, ?, ?, 'created')"
            )->execute([$noteId, $status, $user['id'], $ip]);

            // Usuarios etiquetados
            if (!empty($taggedUsers)) {
                syncTaggedUsers($noteId, $taggedUsers, $user['id']);
            }

            echo json_encode(['success' => true, 'note_id' => $noteId]);
            break;
        }

        case 'update': {
            $noteId      = intval($input['note_id'] ?? 0);
            $title       = trim($input['title'] ?? '');
            $desc        = trim($input['description'] ?? '') ?: null;
            $status      = in_array($input['status']??'', ['pending','in_progress','completed'])
                           ? $input['status'] : 'pending';
            $labelId     = $input['label_id'] ? intval($input['label_id']) : null;
            $taggedUsers = $input['tagged_users'] ?? [];

            // Fecha y hora opcionales
            $schedDate = trim($input['scheduled_date'] ?? '');
            $schedTime = trim($input['scheduled_time'] ?? '');
            $schedDate = ($schedDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $schedDate)) ? $schedDate : null;
            $schedTime = ($schedTime && preg_match('/^\d{2}:\d{2}/', $schedTime)) ? substr($schedTime, 0, 5) : null;

            if (!$noteId || !$title) throw new Exception('Datos inválidos');

            $old = $pdo->prepare("SELECT status FROM notes WHERE id=?");
            $old->execute([$noteId]);
            $old = $old->fetch();
            if (!$old) throw new Exception('Tarea no encontrada');

            $pdo->prepare(
                "UPDATE notes SET title=?, description=?, status=?, label_id=?, scheduled_date=?, scheduled_time=? WHERE id=?"
            )->execute([$title, $desc, $status, $labelId, $schedDate, $schedTime, $noteId]);

            if ($status !== $old['status']) {
                $pdo->prepare(
                    "INSERT INTO note_history (note_id, from_status, to_status, changed_by, changed_ip, action)
                     VALUES (?,?,?,?,?,'status_changed')"
                )->execute([$noteId, $old['status'], $status, $user['id'], $ip]);
            } else {
                $pdo->prepare(
                    "INSERT INTO note_history (note_id, from_status, to_status, changed_by, changed_ip, action)
                     VALUES (?,?,?,?,?,'edited')"
                )->execute([$noteId, $status, $status, $user['id'], $ip]);
            }

            // Sincronizar usuarios etiquetados
            syncTaggedUsers($noteId, $taggedUsers, $user['id']);

            echo json_encode(['success' => true]);
            break;
        }

        case 'change_status': {
            $noteId    = intval($input['note_id'] ?? 0);
            $newStatus = $input['status'] ?? '';
            if (!$noteId || !in_array($newStatus, ['pending','in_progress','completed'])) {
                throw new Exception('Datos inválidos');
            }

            $old = $pdo->prepare("SELECT status FROM notes WHERE id=?");
            $old->execute([$noteId]);
            $old = $old->fetch();
            if (!$old) throw new Exception('Tarea no encontrada');

            $pdo->prepare("UPDATE notes SET status=? WHERE id=?")->execute([$newStatus, $noteId]);

            $pdo->prepare(
                "INSERT INTO note_history (note_id, from_status, to_status, changed_by, changed_ip, action)
                 VALUES (?,?,?,?,?,'status_changed')"
            )->execute([$noteId, $old['status'], $newStatus, $user['id'], $ip]);

            echo json_encode(['success' => true]);
            break;
        }

        case 'delete': {
            $noteId = intval($input['note_id'] ?? 0);
            if (!$noteId) throw new Exception('ID inválido');

            $note = $pdo->prepare("SELECT created_by FROM notes WHERE id=?");
            $note->execute([$noteId]);
            $note = $note->fetch();
            if (!$note) throw new Exception('Tarea no encontrada');
            if (!isAdmin() && $note['created_by'] != $user['id']) {
                throw new Exception('Sin permisos para eliminar esta tarea');
            }

            $pdo->prepare("DELETE FROM notes WHERE id=?")->execute([$noteId]);
            echo json_encode(['success' => true]);
            break;
        }

        case 'get': {
            $noteId = intval($input['note_id'] ?? 0);
            if (!$noteId) throw new Exception('ID inválido');

            $stmt = $pdo->prepare(
                "SELECT n.*, l.name AS label_name, l.color AS label_color,
                        DATE_FORMAT(n.created_at,'%d/%m/%Y %H:%i') AS created_fmt,
                        DATEDIFF(NOW(), n.created_at) AS days_since,
                        DATE_FORMAT(n.scheduled_date, '%Y-%m-%d') AS scheduled_date_fmt,
                        TIME_FORMAT(n.scheduled_time, '%H:%i') AS scheduled_time_fmt
                 FROM notes n
                 LEFT JOIN labels l ON l.id = n.label_id
                 WHERE n.id = ?"
            );
            $stmt->execute([$noteId]);
            $note = $stmt->fetch();
            if (!$note) throw new Exception('Tarea no encontrada');

            // Historial
            $h = $pdo->prepare(
                "SELECT nh.*, au.cached_name AS user_name,
                        DATE_FORMAT(nh.changed_at,'%d/%m/%Y %H:%i') AS changed_fmt
                 FROM note_history nh
                 LEFT JOIN app_users au ON au.prolegal_id = nh.changed_by
                 WHERE nh.note_id = ?
                 ORDER BY nh.changed_at DESC LIMIT 15"
            );
            $h->execute([$noteId]);
            $note['history'] = $h->fetchAll();

            // Usuarios etiquetados
            $note['tagged_users'] = getTaggedUsers($noteId);

            echo json_encode(['success' => true, 'note' => $note]);
            break;
        }

        case 'create_label': {
            // Solo admins pueden crear etiquetas
            if (!isAdmin()) throw new Exception('Sin permisos');
            $name  = trim($input['name']  ?? '');
            $color = $input['color'] ?? '#0099cd';
            if (!$name) throw new Exception('El nombre es obligatorio');
            if (strlen($name) > 50) throw new Exception('El nombre es demasiado largo');

            $pdo->prepare("INSERT INTO labels (name, color) VALUES (?,?)")->execute([$name, $color]);
            $labelId = $pdo->lastInsertId();

            echo json_encode(['success' => true, 'label_id' => $labelId, 'name' => $name, 'color' => $color]);
            break;
        }

        default:
            throw new Exception('Acción desconocida: ' . htmlspecialchars($action));
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
