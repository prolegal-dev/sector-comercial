<?php
// ============================================================
// includes/db.php — Conexión BD LOCAL únicamente
// La autenticación es vía API Prolegal (no conexión directa)
//
// INSTRUCCIONES DE CONFIGURACIÓN:
//   1. Copiá este archivo: cp includes/db.example.php includes/db.php
//   2. Completá los valores de producción con tus credenciales reales
//   3. NUNCA subas includes/db.php al repositorio
// ============================================================

$isLocal = (
    isset($_SERVER['SERVER_NAME']) && (
        $_SERVER['SERVER_NAME'] === 'localhost' ||
        strpos($_SERVER['SERVER_NAME'], '127.0.0.1') !== false ||
        strpos($_SERVER['DOCUMENT_ROOT'] ?? '', 'xampp') !== false ||
        strpos($_SERVER['DOCUMENT_ROOT'] ?? '', 'C:/') !== false
    )
);

if ($isLocal) {
    define('DB_HOST',    'localhost');
    define('DB_NAME',    'nico_comercial');       // Nombre de tu BD local
    define('DB_USER',    'root');                 // Usuario local (por defecto: root)
    define('DB_PASS',    '');                     // Contraseña local (por defecto: vacía en XAMPP)
    define('BASE_URL',   'http://localhost/nico-comercial');
    define('DEBUG_MODE', true);
} else {
    define('DB_HOST',    'localhost');
    define('DB_NAME',    'tu_bd_produccion');     // Reemplazar con el nombre real de la BD
    define('DB_USER',    'tu_usuario_produccion'); // Reemplazar con el usuario real
    define('DB_PASS',    'tu_password_produccion'); // Reemplazar con la contraseña real
    define('BASE_URL',   'https://tudominio.com/sector-comercial'); // Reemplazar con la URL real
    define('DEBUG_MODE', false);
}

// API Prolegal
define('PROLEGAL_API', 'https://prolegal.com.ar/api/v1');

// Conexión local
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    $msg = DEBUG_MODE ? $e->getMessage() : 'Error de conexión a la base de datos.';
    // Si es una llamada API, devolver JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'error' => $msg]));
    }
    die('<h2 style="font-family:sans-serif;color:#dc2626;padding:40px">' . htmlspecialchars($msg) . '</h2>');
}

function getClientIP(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}
