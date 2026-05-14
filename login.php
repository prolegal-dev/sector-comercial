<?php
require_once __DIR__ . '/includes/auth.php';

// Si ya tiene sesión válida, redirigir
if (!empty($_SESSION['logged_in']) && empty($_SESSION['token_expired'])) {
    if (tryAutoLogin()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

$error        = '';
$isExpired    = isset($_GET['expired']);
$rememberUser = isset($_COOKIE['remember_user']) ? htmlspecialchars($_COOKIE['remember_user']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input    = trim($_POST['usuario'] ?? '');
    $password = $_POST['password']    ?? '';
    $remember = !empty($_POST['remember']);

    if (!$input || !$password) {
        $error = 'Completá todos los campos.';
    } else {
        // 1. Llamar a la API de Prolegal
        $result = prolegalLogin($input, $password);

        if (!$result['success']) {
            $error = $result['error'];
        } else {
            // prolegalLogin() devuelve ['success', 'token', 'user'] directamente
            $apiUser  = $result['user']  ?? [];
            $apiToken = $result['token'] ?? '';

            if (empty($apiUser['id']) || empty($apiToken)) {
                // La API respondió OK pero sin los datos esperados
                $error = 'Respuesta inesperada de la API. Intentá de nuevo.';

            } elseif (!isUserAllowed((int)$apiUser['id'])) {
                // 2. Usuario no habilitado en el sistema local
                $error = 'Tu usuario no tiene acceso a este sistema. Contactá al administrador.';

            } else {
                // 3. Obtener perfil completo con profile_photo desde /profile
                $profileData = prolegalGetProfile($apiToken);
                if ($profileData && !empty($profileData['id'])) {
                    $apiUser = array_merge($apiUser, $profileData);
                }

                // Complementar con nombre del estudio desde /me (no bloquea si falla)
                $meData = prolegalGetMe($apiToken);
                if ($meData && !empty($meData['estudio_nombre'])) {
                    $apiUser['estudio_nombre'] = $meData['estudio_nombre'];
                }

                // 4. Setear sesión
                loginUser($apiUser, $apiToken);

                // 5. Remember me
                if ($remember) {
                    setRememberToken((int)$apiUser['id'], $apiToken);
                    setcookie('remember_user', $input, time() + (86400 * 30), '/');
                } else {
                    setcookie('remember_user', '', time() - 3600, '/');
                }

                unset($_SESSION['token_expired']);
                header('Location: ' . BASE_URL . '/index.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingresar — Organizador Comercial</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { font-family: 'DM Sans', sans-serif; box-sizing: border-box; }
        .font-jakarta { font-family: 'Plus Jakarta Sans', sans-serif; }

        body { background: #f0f4f8; min-height: 100vh; }

        .login-panel {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(22,34,89,0.12), 0 4px 16px rgba(0,153,205,0.08);
        }
        .side-panel {
            background: linear-gradient(145deg, #162259 0%, #0d1a3e 60%, #0a3f6b 100%);
            border-radius: 20px;
            position: relative;
            overflow: hidden;
        }
        .side-panel::before {
            content: ''; position: absolute;
            top: -80px; right: -80px;
            width: 300px; height: 300px;
            border-radius: 50%; background: rgba(0,153,205,0.15);
        }
        .side-panel::after {
            content: ''; position: absolute;
            bottom: -60px; left: -60px;
            width: 240px; height: 240px;
            border-radius: 50%; background: rgba(0,153,205,0.1);
        }
        .input-field {
            border: 1.5px solid #e5e7eb; border-radius: 10px;
            padding: 12px 16px 12px 44px; width: 100%;
            font-size: 14px; transition: all .2s;
            background: #fafafa; outline: none;
        }
        .input-field:focus {
            border-color: #0099cd; background: white;
            box-shadow: 0 0 0 3px rgba(0,153,205,0.1);
        }
        .btn-login {
            background: linear-gradient(135deg, #0099cd, #0077a8);
            color: white; border-radius: 10px; padding: 13px 24px;
            width: 100%; font-weight: 600; font-size: 15px;
            transition: all .2s; border: none; cursor: pointer;
        }
        .btn-login:hover:not(:disabled) {
            background: linear-gradient(135deg, #0077a8, #005a82);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(0,153,205,0.3);
        }
        .btn-login:disabled { opacity: .7; cursor: not-allowed; }
        .stat-card {
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 12px; padding: 14px 16px;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .fade-up   { animation: fadeUp .45s ease forwards; }
        .fade-up-2 { animation: fadeUp .45s .08s ease both; }
        .fade-up-3 { animation: fadeUp .45s .16s ease both; }
    </style>
</head>
<body class="flex items-center justify-center p-4 md:p-8" style="min-height:100vh">

<div class="login-panel w-full max-w-4xl flex overflow-hidden" style="min-height:560px">

    <!-- Panel lateral -->
    <div class="side-panel hidden md:flex flex-col justify-between p-10 w-5/12 flex-shrink-0">
        <div class="relative z-10">
            <div class="flex items-center gap-3 mb-10">
                <div style="background:rgba(0,153,205,0.2);border-radius:10px;padding:10px">
                    <i class="fa-solid fa-briefcase text-2xl" style="color:#0099cd"></i>
                </div>
                <div>
                    <p class="font-jakarta text-white font-bold text-base leading-tight">Organizador</p>
                    <p class="text-white/50 text-xs">Sector Comercial</p>
                </div>
            </div>
            <h1 class="font-jakarta text-white font-extrabold leading-tight mb-3" style="font-size:28px">
                Tu equipo.<br>Tus notas.<br>Todo claro.
            </h1>
            <p class="text-white/60 text-sm leading-relaxed">
                Gestioná espacios de trabajo, seguí el avance de cada nota y coordiná con tu equipo.
            </p>
        </div>
        <div class="relative z-10 space-y-3">
            <div class="stat-card flex items-center gap-3">
                <i class="fa-solid fa-table-columns text-lg" style="color:#0099cd"></i>
                <div>
                    <p class="text-white text-sm font-medium">Espacios de trabajo</p>
                    <p class="text-white/50 text-xs">Organizá por proyectos</p>
                </div>
            </div>
            <div class="stat-card flex items-center gap-3">
                <i class="fa-solid fa-users text-lg" style="color:#0099cd"></i>
                <div>
                    <p class="text-white text-sm font-medium">Trabajo en equipo</p>
                    <p class="text-white/50 text-xs">Invitá miembros a cada tablero</p>
                </div>
            </div>
            <div class="stat-card flex items-center gap-3">
                <i class="fa-solid fa-circle-nodes text-lg" style="color:#0099cd"></i>
                <div>
                    <p class="text-white text-sm font-medium">Estados en tiempo real</p>
                    <p class="text-white/50 text-xs">Pendiente · En proceso · Completado</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Formulario -->
    <div class="flex-1 flex flex-col justify-center px-8 md:px-12 py-10">
        <div class="flex md:hidden items-center gap-2 mb-8">
            <i class="fa-solid fa-briefcase text-xl" style="color:#162259"></i>
            <span class="font-jakarta font-bold text-gray-800">Organizador Comercial</span>
        </div>

        <div class="fade-up">
            <h2 class="font-jakarta font-extrabold text-gray-900 mb-1" style="font-size:26px">Bienvenido</h2>
            <p class="text-gray-500 text-sm mb-8">
                Ingresá con tu cuenta de
                <span class="font-semibold" style="color:#162259">Prolegal</span>
            </p>
        </div>

        <!-- Aviso token expirado -->
        <?php if ($isExpired): ?>
        <div class="fade-up mb-5 flex items-start gap-3 p-4 rounded-xl text-sm" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e">
            <i class="fa-solid fa-clock flex-shrink-0 mt-0.5"></i>
            <div>
                <p class="font-semibold">Tu sesión venció</p>
                <p class="text-xs mt-0.5 opacity-80">El acceso dura 24 horas por seguridad. Volvé a ingresar para continuar.</p>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="fade-up mb-5 flex items-center gap-3 p-4 rounded-xl text-sm" style="background:#fef2f2;border:1px solid #fecaca;color:#dc2626">
            <i class="fa-solid fa-circle-exclamation flex-shrink-0"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" id="loginForm" class="space-y-4 fade-up-2">
            <div class="relative">
                <i class="fa-solid fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="text" name="usuario" class="input-field"
                       placeholder="Usuario o email de Prolegal"
                       value="<?= htmlspecialchars($rememberUser) ?>"
                       required autofocus autocomplete="username">
            </div>
            <div class="relative">
                <i class="fa-solid fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="password" name="password" id="password" class="input-field"
                       placeholder="Contraseña" required autocomplete="current-password"
                       style="padding-right:44px">
                <button type="button" onclick="togglePass()"
                        class="absolute right-3.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors">
                    <i id="eye-icon" class="fa-regular fa-eye text-sm"></i>
                </button>
            </div>
            <div class="flex items-center gap-2.5">
                <input type="checkbox" name="remember" id="remember"
                       <?= $rememberUser ? 'checked' : '' ?>
                       class="w-4 h-4 rounded cursor-pointer" style="accent-color:#0099cd">
                <label for="remember" class="text-sm text-gray-500 cursor-pointer select-none">
                    Recordar usuario por 30 días
                </label>
            </div>
            <button type="submit" id="submitBtn" class="btn-login fade-up-3">
                <span id="btnText"><i class="fa-solid fa-arrow-right-to-bracket mr-2"></i>Ingresar</span>
                <span id="btnLoader" class="hidden"><i class="fa-solid fa-spinner fa-spin mr-2"></i>Verificando...</span>
            </button>
        </form>

        <p class="text-center text-xs text-gray-400 mt-8">
            Acceso restringido · Solo usuarios autorizados<br>
            <span style="color:#162259;font-weight:500">Prolegal — Sistema Interno</span>
        </p>
    </div>
</div>

<script>
function togglePass() {
    const inp = document.getElementById('password');
    const ico = document.getElementById('eye-icon');
    inp.type = inp.type === 'password' ? 'text' : 'password';
    ico.className = inp.type === 'text' ? 'fa-regular fa-eye-slash text-sm' : 'fa-regular fa-eye text-sm';
}
document.getElementById('loginForm').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    document.getElementById('btnText').classList.add('hidden');
    document.getElementById('btnLoader').classList.remove('hidden');
});
</script>
</body>
</html>
