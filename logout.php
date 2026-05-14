<?php
require_once __DIR__ . '/includes/auth.php';
logoutUser(true); // true = también invalida el token en la API de Prolegal
header('Location: ' . BASE_URL . '/login.php');
exit;
