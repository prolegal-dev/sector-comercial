<?php
/**
 * api/search_users.php
 * Búsqueda de usuarios para los buscadores del sistema.
 *
 * Estrategia de caché (en orden de prioridad):
 *   1. Archivo en disco  → lista completa de Prolegal, fast read
 *   2. Sesión PHP        → idem, por SESSION_USERS_TTL segundos
 *   3. BD local (app_users) → fallback inmediato cuando aún no hay caché;
 *      evita lanzar 35+ requests HTTP en contexto AJAX.
 *
 * El caché completo de Prolegal se construye al visitar users.php o categories.php.
 */
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

if (!tryAutoLogin()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$user = currentUser();
$q    = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode(['success' => true, 'users' => []]);
    exit;
}

// ── Obtener fuente de usuarios ────────────────────────────────────────────────

$cacheFile = __DIR__ . '/../cache/prolegal_users.json';
$fileTTL   = 1800;
$allUsers  = [];
$fromCache = false;

// 1. Caché de archivo
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $fileTTL) {
    $data = json_decode(file_get_contents($cacheFile), true);
    if (!empty($data)) {
        $allUsers  = $data;
        $fromCache = true;
    }
}

// 2. Caché de sesión
if (!$fromCache
    && !empty($_SESSION['prolegal_users_cache'])
    && !empty($_SESSION['prolegal_users_cache_at'])
    && (time() - $_SESSION['prolegal_users_cache_at']) < SESSION_USERS_TTL
) {
    $allUsers  = $_SESSION['prolegal_users_cache'];
    $fromCache = true;
}

// 3. Fallback: BD local — respuesta inmediata sin tocar la API
//    Cubre todos los usuarios que ya están registrados en app_users.
//    El caché completo se construye en users.php / categories.php.
if (!$fromCache) {
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $stmt = $pdo->prepare("
        SELECT prolegal_id AS id,
               cached_name  AS name,
               cached_email AS email,
               cached_photo AS profile_photo,
               ''           AS username,
               1            AS status
        FROM app_users
        WHERE is_active = 1
          AND (cached_name LIKE ? OR cached_email LIKE ?)
        ORDER BY cached_name ASC
        LIMIT 20
    ");
    $stmt->execute([$like, $like]);
    $localRows = $stmt->fetchAll();

    // Excluir miembros actuales del tablero
    $excludeBoardId = intval($_GET['exclude_members'] ?? 0);
    if ($excludeBoardId && !empty($localRows)) {
        $mStmt = $pdo->prepare("SELECT prolegal_id FROM board_members WHERE board_id = ?");
        $mStmt->execute([$excludeBoardId]);
        $memberIds = $mStmt->fetchAll(PDO::FETCH_COLUMN);
        $localRows = array_values(array_filter($localRows, fn($u) => !in_array($u['id'], $memberIds)));
    }

    // Excluir ya asociados a un sector
    $excludeCatId = intval($_GET['exclude_category'] ?? 0);
    if ($excludeCatId && !empty($localRows)) {
        $cStmt = $pdo->prepare("SELECT prolegal_id FROM category_users WHERE category_id = ?");
        $cStmt->execute([$excludeCatId]);
        $catIds = $cStmt->fetchAll(PDO::FETCH_COLUMN);
        $localRows = array_values(array_filter($localRows, fn($u) => !in_array($u['id'], $catIds)));
    }

    $results = array_map(fn($u) => [
        'id'            => (int)$u['id'],
        'name'          => $u['name']          ?? '',
        'email'         => $u['email']         ?? '',
        'username'      => '',
        'profile_photo' => $u['profile_photo'] ?? '',
    ], $localRows);

    echo json_encode(['success' => true, 'users' => array_values($results)]);
    exit;
}

// ── Filtrar lista completa de Prolegal ────────────────────────────────────────
$qLower  = mb_strtolower($q);
$results = [];

foreach ($allUsers as $u) {
    if (isset($u['status']) && (int)$u['status'] !== 1) continue;

    if (
        str_contains(mb_strtolower($u['name']     ?? ''), $qLower) ||
        str_contains(mb_strtolower($u['email']    ?? ''), $qLower) ||
        str_contains(mb_strtolower($u['username'] ?? ''), $qLower)
    ) {
        $results[] = [
            'id'            => (int)$u['id'],
            'name'          => $u['name']          ?? '',
            'email'         => $u['email']         ?? '',
            'username'      => $u['username']      ?? '',
            'profile_photo' => $u['profile_photo'] ?? '',
        ];
    }
    if (count($results) >= 20) break;
}

// ── Excluir miembros actuales del tablero ─────────────────────────────────────
$excludeBoardId = intval($_GET['exclude_members'] ?? 0);
if ($excludeBoardId && !empty($results)) {
    $stmt = $pdo->prepare("SELECT prolegal_id FROM board_members WHERE board_id = ?");
    $stmt->execute([$excludeBoardId]);
    $memberIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($memberIds)) {
        $results = array_values(array_filter($results, fn($u) => !in_array($u['id'], $memberIds)));
    }
}

// ── Excluir usuarios ya asociados a un sector ─────────────────────────────────
$excludeCatId = intval($_GET['exclude_category'] ?? 0);
if ($excludeCatId && !empty($results)) {
    $stmt = $pdo->prepare("SELECT prolegal_id FROM category_users WHERE category_id = ?");
    $stmt->execute([$excludeCatId]);
    $catMemberIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($catMemberIds)) {
        $results = array_values(array_filter($results, fn($u) => !in_array($u['id'], $catMemberIds)));
    }
}

echo json_encode(['success' => true, 'users' => array_values($results)]);
