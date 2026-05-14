<?php
/**
 * api/members.php
 * Gestión de miembros de tableros vía AJAX.
 */
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

if (!tryAutoLogin()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$user = currentUser();
if (!isAdmin()) {
    echo json_encode(['success' => false, 'error' => 'Solo administradores pueden gestionar miembros']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$action  = $input['action']  ?? '';
$boardId = intval($input['board_id'] ?? 0);

try {
    switch ($action) {

        case 'add': {
            $prolegalId = intval($input['prolegal_id'] ?? 0);
            if (!$boardId || !$prolegalId) throw new Exception('Datos inválidos');

            // Verificar si el usuario está en app_users como activo
            $stmt = $pdo->prepare("SELECT prolegal_id, is_active FROM app_users WHERE prolegal_id = ? LIMIT 1");
            $stmt->execute([$prolegalId]);
            $appUser = $stmt->fetch();

            if (!$appUser) {
                // Usuario de Prolegal que aún no está en el sistema local.
                // Lo agregamos automáticamente para poder invitarlo.
                $apiData = prolegalGetUserById($prolegalId, $user['api_token']);
                if (!$apiData) {
                    throw new Exception('Usuario no encontrado en Prolegal');
                }
                $pdo->prepare(
                    "INSERT INTO app_users (prolegal_id, is_active, is_admin, added_by, cached_name, cached_email, cached_photo)
                     VALUES (?, 1, 0, ?, ?, ?, ?)"
                )->execute([
                    $prolegalId,
                    $user['id'],
                    $apiData['name'],
                    $apiData['email'],
                    $apiData['profile_photo'],
                ]);
            } elseif (!$appUser['is_active']) {
                // Estaba deshabilitado — reactivarlo
                $pdo->prepare("UPDATE app_users SET is_active = 1 WHERE prolegal_id = ?")
                    ->execute([$prolegalId]);
            }

            $pdo->prepare(
                "INSERT IGNORE INTO board_members (board_id, prolegal_id, added_by) VALUES (?, ?, ?)"
            )->execute([$boardId, $prolegalId, $user['id']]);

            echo json_encode(['success' => true]);
            break;
        }

        case 'remove': {
            $prolegalId = intval($input['prolegal_id'] ?? 0);
            if (!$boardId || !$prolegalId) throw new Exception('Datos inválidos');

            // No se puede quitar al creador del tablero
            $b = $pdo->prepare("SELECT created_by FROM boards WHERE id = ?");
            $b->execute([$boardId]);
            $board = $b->fetch();
            if ($board && $board['created_by'] == $prolegalId) {
                throw new Exception('No se puede quitar al creador del espacio');
            }

            $pdo->prepare(
                "DELETE FROM board_members WHERE board_id = ? AND prolegal_id = ?"
            )->execute([$boardId, $prolegalId]);

            echo json_encode(['success' => true]);
            break;
        }

        case 'list': {
            if (!$boardId) throw new Exception('Board ID inválido');
            $stmt = $pdo->prepare(
                "SELECT bm.prolegal_id AS id, au.cached_name AS name,
                        au.cached_email AS email, au.cached_photo AS photo
                 FROM board_members bm
                 JOIN app_users au ON au.prolegal_id = bm.prolegal_id
                 WHERE bm.board_id = ?
                 ORDER BY au.cached_name ASC"
            );
            $stmt->execute([$boardId]);
            echo json_encode(['success' => true, 'members' => $stmt->fetchAll()]);
            break;
        }

        default:
            throw new Exception('Acción desconocida');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
