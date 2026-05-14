<?php
// ============================================================
// includes/api_prolegal.php
// Helper para todas las llamadas a la API de Prolegal
//
// Endpoints usados:
//   POST /login          → autentica, devuelve token
//   GET  /profile        → perfil completo con profile_photo  (data directo)
//   GET  /me             → complementario, trae estudio.nombre
//   GET  /users          → lista todos los usuarios (paginada, data.users[])
//   GET  /users/{id}     → usuario por ID (data objeto)
//   GET  /embajadores/catalogos → lista de estudios jurídicos
//   POST /logout         → invalida el token server-side
// ============================================================

// TTL del cache de usuarios en sesión (segundos). 10 minutos.
define('SESSION_USERS_TTL', 600);

/**
 * Helper interno: ejecuta un request GET o POST a la API de Prolegal.
 * Usa cURL si está disponible; si no, cae en file_get_contents (útil en local sin cURL).
 */
function _prolegalRequest(string $endpoint, string $method, ?array $payload, string $token = ''): array {
    $url     = PROLEGAL_API . $endpoint;
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    // ── Vía cURL (producción y local con cURL habilitado) ─────────────────────
    if (function_exists('curl_init')) {
        $ch   = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $payload ? json_encode($payload) : '{}';
        } else {
            $opts[CURLOPT_HTTPGET] = true;
        }
        curl_setopt_array($ch, $opts);
        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr || !$raw) {
            return ['http_code' => 0, 'body' => null];
        }
        return ['http_code' => $httpCode, 'body' => json_decode($raw, true)];
    }

    // ── Fallback: file_get_contents (local sin cURL, requiere allow_url_fopen=On) ──
    $contextOpts = [
        'http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'timeout'       => 10,
            'ignore_errors' => true, // obtener body aunque HTTP sea 4xx/5xx
        ],
        'ssl'  => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ];
    if ($method === 'POST') {
        $contextOpts['http']['content'] = $payload ? json_encode($payload) : '{}';
    }

    $raw = @file_get_contents($url, false, stream_context_create($contextOpts));

    if ($raw === false) {
        return ['http_code' => 0, 'body' => null];
    }

    // Extraer código HTTP de los headers de respuesta
    $httpCode = 0;
    if (!empty($http_response_header)) {
        if (preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
            $httpCode = (int)$m[1];
        }
    }

    return ['http_code' => $httpCode, 'body' => json_decode($raw, true)];
}

// ── Login ─────────────────────────────────────────────────────────────────────

/**
 * POST /login — Autenticación con username o email.
 *
 * @return array ['success'=>bool, 'token'=>string, 'user'=>array, 'error'=>string]
 */
function prolegalLogin(string $username, string $password): array {
    $r = _prolegalRequest('/login', 'POST', [
        'username' => $username,
        'password' => $password,
    ]);

    if ($r['http_code'] === 0) {
        return ['success' => false, 'error' => 'No se pudo conectar con Prolegal. Verificá tu conexión.'];
    }

    $body = $r['body'];

    if ($r['http_code'] === 200 && !empty($body['success'])) {
        return [
            'success' => true,
            'token'   => $body['data']['token'],
            'user'    => $body['data']['user'],
        ];
    }

    $msgs = [
        401 => 'Usuario o contraseña incorrectos.',
        403 => 'Tu usuario está inactivo. Contactá al administrador.',
        422 => 'Datos mal formateados. Revisá usuario y contraseña.',
        429 => 'Demasiados intentos. Esperá unos minutos.',
    ];
    return [
        'success' => false,
        'error'   => $msgs[$r['http_code']] ?? ($body['message'] ?? 'Error al iniciar sesión.'),
    ];
}

// ── Perfil completo (con profile_photo) ───────────────────────────────────────

/**
 * GET /profile — Perfil completo del usuario autenticado.
 * Incluye profile_photo.
 *
 * @return array|null Datos normalizados del usuario, o null si falla.
 */
function prolegalGetProfile(string $apiToken): ?array {
    $r = _prolegalRequest('/profile', 'GET', null, $apiToken);

    if ($r['http_code'] !== 200 || !$r['body']) return null;

    $p = $r['body']['data'] ?? null;
    if (!is_array($p) || empty($p['id'])) return null;

    $photo = _normalizePhotoPath($p['profile_photo'] ?? '');

    return [
        'id'            => $p['id'],
        'name'          => $p['name']       ?? '',
        'username'      => $p['username']   ?? '',
        'email'         => $p['email']      ?? '',
        'estudio_id'    => $p['estudio_id'] ?? null,
        'profile_photo' => $photo,
    ];
}

// ── Me (complementario: nombre del estudio) ───────────────────────────────────

/**
 * GET /me — Datos del usuario con objeto estudio anidado.
 *
 * @return array|null ['estudio_nombre' => string]
 */
function prolegalGetMe(string $apiToken): ?array {
    $r = _prolegalRequest('/me', 'GET', null, $apiToken);

    if ($r['http_code'] !== 200 || !$r['body']) return null;

    $d = $r['body']['data']['data'] ?? $r['body']['data'] ?? null;
    if (!is_array($d)) return null;

    return [
        'estudio_nombre' => $d['estudio']['nombre'] ?? null,
    ];
}

// ── URL de foto ───────────────────────────────────────────────────────────────

/**
 * Construye la URL completa de la foto de perfil.
 */
function prolegalPhotoUrl(?string $photoPath): string {
    if (!$photoPath || trim($photoPath) === '') return '';

    $photoPath = _normalizePhotoPath($photoPath);
    if (!$photoPath) return '';

    if (str_starts_with($photoPath, 'http://') || str_starts_with($photoPath, 'https://')) {
        return $photoPath;
    }

    return 'https://prolegal.com.ar/storage/' . $photoPath;
}

/**
 * Normaliza el path de foto que llega de la API con barras invertidas.
 * profiles\/2\/foto.jpg → profiles/2/foto.jpg
 */
function _normalizePhotoPath(?string $photo): string {
    if (!$photo) return '';
    $photo = str_replace(['\\/', '\\/','\\'], '/', trim($photo));
    return ltrim($photo, '/');
}

// ── Logout ────────────────────────────────────────────────────────────────────

/**
 * POST /logout — Invalida el token en el servidor de Prolegal.
 */
function prolegalLogout(string $apiToken): bool {
    $r = _prolegalRequest('/logout', 'POST', null, $apiToken);
    return $r['http_code'] === 200;
}

// ── Validación local del token ─────────────────────────────────────────────────

/**
 * Verifica localmente si el token sigue dentro del plazo de 24hs.
 */
function prolegalTokenValid(): bool {
    $token     = $_SESSION['api_token']        ?? '';
    $expiresAt = $_SESSION['token_expires_at'] ?? 0;
    if (!$token) return false;
    if ($expiresAt && time() > $expiresAt) return false;
    return true;
}

// ── Usuarios ─────────────────────────────────────────────────────────────────

/**
 * GET /users — Lista TODOS los usuarios paginando automáticamente.
 *
 * ⚠️  PERFORMANCE: Esta función hace entre 20 y 50 requests HTTP en serie.
 *     Usar prolegalListUsersCached() siempre que sea posible, que guarda
 *     el resultado en sesión por SESSION_USERS_TTL segundos.
 *
 * @return array Lista completa de usuarios normalizados.
 */
function prolegalListUsers(string $apiToken, int $maxPages = 50): array {
    $allUsers = [];
    $page     = 1;
    $lastPage = 1;

    do {
        $r = _prolegalRequest('/users?page=' . $page, 'GET', null, $apiToken);

        if ($r['http_code'] !== 200 || empty($r['body']['data'])) break;

        $data       = $r['body']['data'];
        $pageUsers  = $data['users']      ?? [];
        $pagination = $data['pagination'] ?? [];
        $lastPage   = (int)($pagination['last_page'] ?? 1);

        foreach ($pageUsers as $u) {
            if (empty($u['id'])) continue;
            $allUsers[] = _normalizeUser($u);
        }

        $page++;
    } while ($page <= $lastPage && $page <= $maxPages);

    usort($allUsers, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $allUsers;
}

/**
 * Versión con caché en archivo + sesión de prolegalListUsers().
 *
 * Prioridad de caché:
 *   1. Archivo en disco (persiste entre sesiones, TTL 30 min)
 *   2. Sesión PHP (TTL SESSION_USERS_TTL segundos)
 *   3. API de Prolegal (fallback, hace los 35+ requests)
 *
 * El caché en archivo evita que cada sesión nueva dispare la carga
 * lenta contra la API (que con 682 usuarios supera el tiempo límite de PHP).
 *
 * @return array Lista completa de usuarios normalizados.
 */
function prolegalListUsersCached(string $apiToken): array {
    $cacheFile = __DIR__ . '/../cache/prolegal_users.json';
    $fileTTL   = 1800; // 30 minutos en disco
    $cacheKey  = 'prolegal_users_cache';
    $cacheTime = 'prolegal_users_cache_at';

    // 1. Caché en archivo (más rápido, persiste entre sesiones)
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $fileTTL) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if (!empty($data)) {
            // Repoblar sesión con los datos del archivo
            $_SESSION[$cacheKey]  = $data;
            $_SESSION[$cacheTime] = time();
            return $data;
        }
    }

    // 2. Caché en sesión
    if (
        !empty($_SESSION[$cacheKey]) &&
        !empty($_SESSION[$cacheTime]) &&
        (time() - $_SESSION[$cacheTime]) < SESSION_USERS_TTL
    ) {
        return $_SESSION[$cacheKey];
    }

    // 3. Llamada real a la API — dar tiempo extra para los 35+ requests
    set_time_limit(120);
    $users = prolegalListUsers($apiToken);

    if (!empty($users)) {
        // Guardar en archivo
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($cacheFile, json_encode($users));

        // Guardar en sesión
        $_SESSION[$cacheKey]  = $users;
        $_SESSION[$cacheTime] = time();
    }

    return $users;
}

/**
 * Fuerza la invalidación del cache de usuarios en sesión.
 * Útil después de agregar/modificar usuarios.
 */
function prolegalClearUsersCache(): void {
    unset($_SESSION['prolegal_users_cache'], $_SESSION['prolegal_users_cache_at']);
}

/**
 * GET /users/{id} — Obtiene un usuario específico por su ID.
 *
 * @return array|null Usuario normalizado, o null si no existe.
 */
function prolegalGetUserById(int $userId, string $apiToken): ?array {
    $r = _prolegalRequest('/users/' . $userId, 'GET', null, $apiToken);

    if ($r['http_code'] !== 200 || empty($r['body']['data'])) return null;

    $u = $r['body']['data'];
    if (empty($u['id'])) return null;

    return _normalizeUser($u);
}

/**
 * Normaliza un usuario crudo de la API a la estructura interna.
 */
function _normalizeUser(array $u): array {
    return [
        'id'            => (int)$u['id'],
        'name'          => $u['name']       ?? '',
        'username'      => $u['username']   ?? '',
        'email'         => $u['email']      ?? '',
        'profile_photo' => _normalizePhotoPath($u['profile_photo'] ?? ''),
        'estudio_id'    => $u['estudio_id'] ?? null,
        'status'        => (int)($u['status'] ?? 1),
    ];
}

/**
 * GET /embajadores/catalogos — Obtiene catálogos incluyendo lista de estudios.
 *
 * @return array ['estudios' => [], 'empleados' => []]
 */
function prolegalGetCatalogos(string $apiToken): array {
    $r = _prolegalRequest('/embajadores/catalogos', 'GET', null, $apiToken);

    if ($r['http_code'] !== 200 || empty($r['body']['data'])) {
        return ['estudios' => [], 'empleados' => []];
    }

    $data = $r['body']['data'];
    return [
        'estudios'  => $data['estudios']  ?? [],
        'empleados' => $data['empleados'] ?? [],
    ];
}
