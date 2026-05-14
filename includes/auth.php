<?php
// ============================================================
// includes/auth.php
// ============================================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api_prolegal.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0, // sesión de navegador (la persistencia la maneja remember_token)
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── Helpers de sesión ────────────────────────────────────────────────────────

function currentUser(): array {
    return [
        'id'       => $_SESSION['user_id']       ?? 0,
        'name'     => $_SESSION['user_name']      ?? '',
        'email'    => $_SESSION['user_email']     ?? '',
        'photo'    => $_SESSION['user_photo']     ?? '',
        'api_token'=> $_SESSION['api_token']      ?? '',
    ];
}

function isAdmin(?int $prolegalId = null): bool {
    global $pdo;
    $id = $prolegalId ?? ($_SESSION['user_id'] ?? 0);
    if (!$id) return false;
    $stmt = $pdo->prepare("SELECT is_admin FROM app_users WHERE prolegal_id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row && (int)$row['is_admin'] === 1;
}

function isUserAllowed(int $prolegalId): bool {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id FROM app_users WHERE prolegal_id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$prolegalId]);
    return (bool)$stmt->fetch();
}

/**
 * Construye la URL completa de la foto del usuario.
 *
 * Delega en prolegalPhotoUrl() definida en api_prolegal.php para
 * mantener un único lugar donde se construye la URL del storage.
 * Si no hay foto, devuelve el avatar SVG por defecto.
 */
function userPhotoUrl(string $photo): string {
    $photo = trim($photo);
    if (empty($photo)) {
        return BASE_URL . '/assets/img/avatar-default.svg';
    }
    // prolegalPhotoUrl() maneja path relativo y URL completa
    return prolegalPhotoUrl($photo);
}

// ── Login / Logout ───────────────────────────────────────────────────────────

/**
 * Login: setea sesión con los datos del usuario y el token de la API.
 *
 * La foto se extrae intentando múltiples nombres de campo porque la API
 * puede devolverla como 'profile_photo', 'photo', 'avatar', etc.
 * Se guarda en caché local para usarla sin depender del token.
 */
function loginUser(array $apiUser, string $apiToken): void {
    // Extraer foto: /profile devuelve profile_photo en data.data.profile_photo
    // prolegalPhotoUrl() construye la URL completa del storage de Prolegal
    $rawPhoto = $apiUser['profile_photo']
             ?? $apiUser['photo']
             ?? $apiUser['avatar']
             ?? '';
    $photo = $rawPhoto; // Guardamos el path relativo en caché, userPhotoUrl() construye la URL

    $_SESSION['user_id']          = $apiUser['id'];
    $_SESSION['user_name']        = $apiUser['name']  ?? $apiUser['username'] ?? '';
    $_SESSION['user_email']       = $apiUser['email'] ?? '';
    $_SESSION['user_photo']       = $photo;
    $_SESSION['api_token']        = $apiToken;
    $_SESSION['token_expires_at'] = time() + (24 * 3600); // 24hs
    $_SESSION['logged_in']        = true;
    session_regenerate_id(true);

    // Persistir en caché local (se usa cuando el token no está disponible)
    global $pdo;
    $pdo->prepare("
        UPDATE app_users
        SET cached_name  = ?,
            cached_email = ?,
            cached_photo = ?
        WHERE prolegal_id = ?
    ")->execute([
        $_SESSION['user_name'],
        $_SESSION['user_email'],
        $photo,
        $apiUser['id'],
    ]);
}

/**
 * Destruye la sesión y opcionalmente invalida el token en la API
 */
function logoutUser(bool $callApi = true): void {
    $token = $_SESSION['api_token'] ?? '';
    if ($callApi && $token) {
        prolegalLogout($token);
    }
    // Limpiar remember token de la BD
    global $pdo;
    if (!empty($_COOKIE['remember_token'])) {
        $pdo->prepare("DELETE FROM remember_tokens WHERE token = ?")->execute([$_COOKIE['remember_token']]);
        setcookie('remember_token', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
    }
    setcookie('remember_user', '', time() - 3600, '/');
    session_unset();
    session_destroy();
}

// ── Remember me ──────────────────────────────────────────────────────────────

function setRememberToken(int $prolegalId, string $apiToken): void {
    global $pdo;
    $token     = bin2hex(random_bytes(32));
    $expiry    = date('Y-m-d H:i:s', strtotime('+30 days'));
    // Guardar API token encriptado con clave derivada del token de cookie
    $encrypted = encryptToken($apiToken, $token);
    $pdo->prepare("DELETE FROM remember_tokens WHERE prolegal_id = ?")->execute([$prolegalId]);
    $pdo->prepare("INSERT INTO remember_tokens (prolegal_id, token, api_token, expires_at) VALUES (?,?,?,?)")
        ->execute([$prolegalId, $token, $encrypted, $expiry]);
    setcookie('remember_token', $token, time() + (86400 * 30), '/', '', isset($_SERVER['HTTPS']), true);
}

function encryptToken(string $data, string $key): string {
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($data, 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

function decryptToken(string $data, string $key): string {
    $raw = base64_decode($data);
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    return openssl_decrypt($enc, 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv) ?: '';
}

// ── Auto login por remember_token ────────────────────────────────────────────

function tryAutoLogin(): bool {
    global $pdo;

    // Ya hay sesión activa
    if (!empty($_SESSION['logged_in']) && !empty($_SESSION['api_token'])) {
        // Verificar que el token no venció
        $expiresAt = $_SESSION['token_expires_at'] ?? 0;
        if ($expiresAt && time() > $expiresAt) {
            // Token vencido — no destruir sesión aquí, dejar que requireAuth() muestre el aviso
            return false;
        }
        return true;
    }

    // Intentar por cookie remember_token
    if (empty($_COOKIE['remember_token'])) return false;

    $cookieToken = $_COOKIE['remember_token'];
    $stmt = $pdo->prepare(
        "SELECT rt.prolegal_id, rt.api_token, au.is_active, au.cached_name, au.cached_email, au.cached_photo
         FROM remember_tokens rt
         JOIN app_users au ON au.prolegal_id = rt.prolegal_id
         WHERE rt.token = ? AND rt.expires_at > NOW()
         LIMIT 1"
    );
    $stmt->execute([$cookieToken]);
    $row = $stmt->fetch();

    if (!$row || !$row['is_active']) return false;

    $apiToken = decryptToken($row['api_token'], $cookieToken);
    if (!$apiToken) return false;

    // Reconstruir sesión con datos cacheados
    $_SESSION['user_id']          = $row['prolegal_id'];
    $_SESSION['user_name']        = $row['cached_name']  ?? '';
    $_SESSION['user_email']       = $row['cached_email'] ?? '';
    $_SESSION['user_photo']       = $row['cached_photo'] ?? '';
    $_SESSION['api_token']        = $apiToken;
    $_SESSION['token_expires_at'] = time() + (24 * 3600);
    $_SESSION['logged_in']        = true;
    session_regenerate_id(true);

    return true;
}

// ── Guard ────────────────────────────────────────────────────────────────────

function requireAuth(): void {
    // Sesión activa y token válido
    if (!empty($_SESSION['logged_in']) && !empty($_SESSION['api_token'])) {
        $expiresAt = $_SESSION['token_expires_at'] ?? 0;
        if (!$expiresAt || time() <= $expiresAt) return; // OK

        // Token vencido → mostrar aviso (no destruir sesión todavía, login.php lo hará)
        $_SESSION['token_expired'] = true;
        header('Location: ' . BASE_URL . '/login.php?expired=1');
        exit;
    }

    // Intentar auto-login por cookie
    if (tryAutoLogin()) return;

    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// ── Helpers de usuarios ──────────────────────────────────────────────────────

/**
 * Obtiene datos de un usuario desde la caché local
 */
function getCachedUser(int $prolegalId): ?array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT prolegal_id AS id, cached_name AS name, cached_email AS email,
                cached_photo AS profile_photo, is_admin, is_active
         FROM app_users WHERE prolegal_id = ? LIMIT 1"
    );
    $stmt->execute([$prolegalId]);
    return $stmt->fetch() ?: null;
}

/**
 * Lista todos los usuarios habilitados con datos cacheados
 */
function getAllowedUsers(): array {
    global $pdo;
    return $pdo->query(
        "SELECT prolegal_id AS id, cached_name AS name, cached_email AS email,
                cached_photo AS profile_photo, is_admin, is_active, added_at
         FROM app_users
         ORDER BY is_admin DESC, cached_name ASC"
    )->fetchAll();
}

/**
 * Lista usuarios que pueden ser invitados a tableros (activos en el sistema local)
 */
function getInvitableUsers(): array {
    global $pdo;
    return $pdo->query(
        "SELECT prolegal_id AS id, cached_name AS name, cached_email AS email,
                cached_photo AS profile_photo
         FROM app_users
         WHERE is_active = 1
         ORDER BY cached_name ASC"
    )->fetchAll();
}

/**
 * Actualiza el caché local de un usuario con datos frescos de la API.
 * Se llama cuando un admin agrega un usuario por ID y necesitamos sus datos.
 */
function syncUserCacheFromApi(int $prolegalId, string $apiToken): bool {
    global $pdo;
    $userData = prolegalGetUserById($prolegalId, $apiToken);
    if (!$userData) return false;

    $pdo->prepare(
        "UPDATE app_users SET cached_name=?, cached_email=?, cached_photo=? WHERE prolegal_id=?"
    )->execute([
        $userData['name'],
        $userData['email'],
        $userData['profile_photo'],
        $prolegalId,
    ]);
    return true;
}
