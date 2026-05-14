<?php
// includes/layout.php
$user        = currentUser();
$isAdminUser = isAdmin($user['id']);
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Organizador Comercial') ?> — Sector Comercial</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --navy: #162259;
            --cyan: #0099cd;
        }
        * { font-family: 'DM Sans', sans-serif; box-sizing: border-box; }
        .font-jakarta { font-family: 'Plus Jakarta Sans', sans-serif; }

        /* ── Sidebar ── */
        #sidebar {
            background: linear-gradient(180deg, #162259 0%, #0f1a42 100%);
            width: 248px; min-height: 100vh;
            position: fixed; left: 0; top: 0;
            z-index: 40; transition: transform .3s;
            display: flex; flex-direction: column;
        }
        .nav-link {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 16px; border-radius: 10px;
            color: rgba(255,255,255,0.6); font-size: 14px; font-weight: 500;
            transition: all .18s; text-decoration: none; margin: 0 8px;
        }
        .nav-link:hover  { background: rgba(255,255,255,0.08); color: white; }
        .nav-link.active {
            background: rgba(0,153,205,0.22); color: white;
            border-left: 3px solid #0099cd; padding-left: 13px;
        }
        .nav-icon { width: 20px; text-align: center; font-size: 14px; flex-shrink: 0; }
        .nav-section {
            color: rgba(255,255,255,0.28); font-size: 10px;
            font-weight: 700; letter-spacing: 1px;
            padding: 16px 24px 6px; text-transform: uppercase;
        }

        /* ── Main ── */
        #main-content { margin-left: 248px; min-height: 100vh; background: #f0f4f8; }

        /* ── Topbar ── */
        #topbar {
            background: white; border-bottom: 1px solid #e5e7eb;
            height: 62px; display: flex; align-items: center;
            padding: 0 24px; position: sticky; top: 0; z-index: 30;
            gap: 16px;
        }

        /* ── Cards ── */
        .card { background: white; border-radius: 16px; box-shadow: 0 1px 4px rgba(22,34,89,0.06), 0 4px 16px rgba(0,0,0,0.04); }
        .card-hover { transition: all .18s; }
        .card-hover:hover { transform: translateY(-2px); box-shadow: 0 4px 20px rgba(22,34,89,0.1), 0 8px 32px rgba(0,0,0,0.06); }

        /* ── Badges ── */
        .badge { border-radius: 6px; padding: 2px 8px; font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
        .badge-navy  { background: rgba(22,34,89,0.1);  color: #162259; }
        .badge-cyan  { background: rgba(0,153,205,0.12);color: #0077a8; }
        .badge-gray  { background: #f3f4f6; color: #6b7280; }
        .badge-green { background: #d1fae5; color: #065f46; }
        .badge-amber { background: #fef3c7; color: #92400e; }
        .badge-red   { background: #fee2e2; color: #dc2626; }

        /* ── Botones ── */
        .btn-primary { background: linear-gradient(135deg,#0099cd,#0077a8); color:white; border:none; cursor:pointer; border-radius:10px; padding:9px 18px; font-size:14px; font-weight:600; transition:all .18s; display:inline-flex; align-items:center; gap:7px; }
        .btn-primary:hover { background:linear-gradient(135deg,#0077a8,#005a82); transform:translateY(-1px); box-shadow:0 4px 14px rgba(0,153,205,.3); }
        .btn-navy { background:linear-gradient(135deg,#162259,#0f1a42); color:white; border:none; cursor:pointer; border-radius:10px; padding:9px 18px; font-size:14px; font-weight:600; transition:all .18s; display:inline-flex; align-items:center; gap:7px; }
        .btn-navy:hover { background:linear-gradient(135deg,#0f1a42,#08112b); transform:translateY(-1px); }
        .btn-ghost { background:transparent; color:#6b7280; border:1.5px solid #e5e7eb; cursor:pointer; border-radius:10px; padding:9px 18px; font-size:14px; font-weight:500; transition:all .18s; display:inline-flex; align-items:center; gap:7px; }
        .btn-ghost:hover { border-color:#0099cd; color:#0099cd; }
        .btn-danger { background:transparent; color:#dc2626; border:1.5px solid #fecaca; cursor:pointer; border-radius:10px; padding:9px 18px; font-size:14px; font-weight:500; transition:all .18s; display:inline-flex; align-items:center; gap:7px; }
        .btn-danger:hover { background:#fef2f2; border-color:#dc2626; }

        /* ── Modal ── */
        .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:100; display:flex; align-items:center; justify-content:center; padding:16px; backdrop-filter:blur(3px); }
        .modal-box { background:white; border-radius:20px; padding:28px; width:100%; max-width:480px; box-shadow:0 24px 64px rgba(0,0,0,0.16); max-height:90vh; overflow-y:auto; }

        /* ── Formularios ── */
        .form-input  { border:1.5px solid #e5e7eb; border-radius:10px; padding:10px 14px; width:100%; font-size:14px; transition:all .18s; outline:none; background:#fafafa; }
        .form-input:focus  { border-color:#0099cd; background:white; box-shadow:0 0 0 3px rgba(0,153,205,0.1); }
        .form-select { border:1.5px solid #e5e7eb; border-radius:10px; padding:10px 14px; width:100%; font-size:14px; outline:none; background:#fafafa; cursor:pointer; }
        .form-select:focus { border-color:#0099cd; background:white; box-shadow:0 0 0 3px rgba(0,153,205,0.1); }
        .form-label  { display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:5px; }

        /* ── Toast ── */
        #toast-container { position:fixed; bottom:24px; right:24px; z-index:999; display:flex; flex-direction:column; gap:8px; pointer-events:none; }
        .toast { background:white; border-radius:12px; padding:12px 18px; box-shadow:0 4px 20px rgba(0,0,0,0.12); border-left:4px solid #0099cd; font-size:14px; display:flex; align-items:center; gap:10px; min-width:260px; animation:slideIn .28s ease; }
        .toast.success { border-color:#10b981; }
        .toast.error   { border-color:#ef4444; }
        .toast.warning { border-color:#f59e0b; }
        @keyframes slideIn { from{transform:translateX(110%);opacity:0} to{transform:translateX(0);opacity:1} }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            #sidebar { transform: translateX(-100%); }
            #sidebar.open { transform: translateX(0); }
            #main-content { margin-left: 0; }
        }
    </style>
    <?php if (isset($extraHead)) echo $extraHead; ?>
</head>
<body>

<div id="toast-container"></div>

<!-- Sidebar -->
<aside id="sidebar">
    <div style="padding:20px 16px 12px">
        <!-- Logo -->
        <div class="flex items-center gap-3 mb-8">
            <div style="background:rgba(0,153,205,0.2);border-radius:10px;padding:9px;flex-shrink:0">
                <i class="fa-solid fa-briefcase" style="color:#0099cd;font-size:17px"></i>
            </div>
            <div>
                <p class="font-jakarta text-white font-bold text-sm leading-tight">Organizador</p>
                <p style="color:rgba(255,255,255,0.4);font-size:11px">Sector Comercial</p>
            </div>
        </div>

        <!-- Nav -->
        <?php $isUser484 = ($user['id'] === 484); ?>
        <nav class="flex flex-col gap-0.5">
            <p class="nav-section">Principal</p>
            <a href="<?= BASE_URL ?>/" class="nav-link <?= $currentPage==='index'?'active':'' ?>">
                <span class="nav-icon"><i class="fa-solid fa-gauge-high"></i></span> Dashboard
            </a>
            <a href="<?= BASE_URL ?>/semanal.php" class="nav-link <?= $currentPage==='semanal'?'active':'' ?>">
                <span class="nav-icon"><i class="fa-solid fa-calendar-week"></i></span> Semanal
            </a>
            <a href="<?= BASE_URL ?>/grupos.php" class="nav-link <?= $currentPage==='grupos'?'active':'' ?>">
                <span class="nav-icon"><i class="fa-solid fa-list-check"></i></span> Grupo de tareas
            </a>

            <?php if (!$isUser484): ?>
            <a href="<?= BASE_URL ?>/mis-sectores.php" class="nav-link <?= $currentPage==='mis-sectores'?'active':'' ?>">
                <span class="nav-icon"><i class="fa-solid fa-layer-group"></i></span> Mis Sectores
            </a>
            <a href="<?= BASE_URL ?>/mis-tareas-fijas.php" class="nav-link <?= $currentPage==='mis-tareas-fijas'?'active':'' ?>">
                <span class="nav-icon"><i class="fa-solid fa-thumbtack"></i></span> Mis Tareas Fijas
            </a>
            <a href="<?= BASE_URL ?>/calendario.php" class="nav-link <?= $currentPage==='calendario'?'active':'' ?>">
                <span class="nav-icon"><i class="fa-regular fa-calendar-days"></i></span> Calendario
            </a>
            <?php endif; ?>

            <?php if ($isAdminUser): ?>
            <a href="<?= BASE_URL ?>/sectores-y-tareas.php" class="nav-link <?= $currentPage==='sectores-y-tareas'?'active':'' ?>">
                <span class="nav-icon"><i class="fa-solid fa-sitemap"></i></span> Sectores y Tareas
            </a>
            <a href="<?= BASE_URL ?>/admin-tareas-fijas.php" class="nav-link <?= $currentPage==='admin-tareas-fijas'?'active':'' ?>">
                <span class="nav-icon"><i class="fa-solid fa-list-ul"></i></span> Tareas Fijas
            </a>
            <?php endif; ?>

            <?php if ($isAdminUser): ?>
            <p class="nav-section" style="margin-top:8px">Administración</p>
            <a href="<?= BASE_URL ?>/users.php" class="nav-link <?= $currentPage==='users'?'active':'' ?>">
                <span class="nav-icon"><i class="fa-solid fa-users"></i></span> Usuarios
            </a>
            <a href="<?= BASE_URL ?>/sectores.php" class="nav-link <?= $currentPage==='sectores'?'active':'' ?>">
                <span class="nav-icon"><i class="fa-solid fa-tags"></i></span> Sectores
            </a>
            <a href="<?= BASE_URL ?>/labels.php" class="nav-link <?= $currentPage==='labels'?'active':'' ?>">
                <span class="nav-icon"><i class="fa-solid fa-bookmark"></i></span> Etiquetas
            </a>
            <?php endif; ?>
        </nav>
    </div>

    <!-- Usuario en el fondo -->
    <div style="margin-top:auto;padding:16px;border-top:1px solid rgba(255,255,255,0.08)">
        <div class="flex items-center gap-3">
            <div style="position:relative;flex-shrink:0">
                <img src="<?= htmlspecialchars(userPhotoUrl($user['photo'])) ?>"
                     alt="<?= htmlspecialchars($user['name']) ?>"
                     class="rounded-full object-cover"
                     style="width:42px;height:42px;border:2px solid rgba(0,153,205,0.6)"
                     onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
                <div style="position:absolute;bottom:1px;right:1px;width:10px;height:10px;background:#10b981;border-radius:50%;border:2px solid #162259"></div>
            </div>
            <div style="flex:1;min-width:0">
                <p class="text-white text-sm font-semibold truncate"><?= htmlspecialchars($user['name']) ?></p>
                <p style="color:rgba(255,255,255,0.4);font-size:11px"><?= $isAdminUser ? 'Administrador' : 'Miembro' ?></p>
            </div>
            <a href="<?= BASE_URL ?>/logout.php" title="Cerrar sesión"
               style="color:rgba(255,255,255,0.35)" class="hover:text-white transition-colors flex-shrink-0">
                <i class="fa-solid fa-arrow-right-from-bracket text-sm"></i>
            </a>
        </div>
    </div>
</aside>

<!-- Main -->
<div id="main-content">
    <!-- Topbar -->
    <div id="topbar">
        <button onclick="toggleSidebar()" class="md:hidden text-gray-500 hover:text-gray-700 flex-shrink-0">
            <i class="fa-solid fa-bars text-lg"></i>
        </button>
        <div style="flex:1">
            <h1 class="font-jakarta font-bold text-gray-900" style="font-size:17px">
                <?= htmlspecialchars($pageTitle ?? 'Dashboard') ?>
            </h1>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xs text-gray-400 hidden sm:block"><?= date('d/m/Y H:i') ?></span>
            <?php if ($isAdminUser): ?>
            <span class="badge badge-navy" style="font-size:10px">Admin</span>
            <?php endif; ?>
            <!-- Foto + nombre en topbar -->
            <div class="flex items-center gap-2.5 cursor-pointer" onclick="window.location='<?= BASE_URL ?>/index.php'" title="Ir al dashboard">
                <div style="position:relative;flex-shrink:0">
                    <img src="<?= htmlspecialchars(userPhotoUrl($user['photo'])) ?>"
                         class="rounded-full object-cover"
                         style="width:38px;height:38px;border:2px solid #e5e7eb"
                         onerror="this.src='<?= BASE_URL ?>/assets/img/avatar-default.svg'">
                    <div style="position:absolute;bottom:0;right:0;width:10px;height:10px;background:#10b981;border-radius:50%;border:2px solid white"></div>
                </div>
                <div class="hidden md:block" style="line-height:1.2">
                    <p class="text-sm font-semibold text-gray-800 truncate" style="max-width:120px"><?= htmlspecialchars($user['name']) ?></p>
                    <p class="text-xs text-gray-400">En línea</p>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/logout.php" class="btn-ghost text-xs hidden md:flex" style="padding:6px 12px" title="Cerrar sesión">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
            </a>
        </div>
    </div>

    <!-- Contenido de la página -->
    <div style="padding:24px">
