<?php
// =====================================================
// verificar_rol.php
// Controla permisos de acceso según el rol del usuario
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/verificar_sesion.php';


// -----------------------------------------------------
// Función para verificar roles permitidos
// -----------------------------------------------------

function verificarRol(array $roles_permitidos): void
{
    // -------------------------------------------------
    // Si no existe rol en sesión, enviamos al login
    // -------------------------------------------------

    if (!isset($_SESSION['usuario_rol'])) {

        header('Location: ../auth/login.php?error=sesion');

        exit;
    }


    // -------------------------------------------------
    // El administrador puede acceder a todo
    // -------------------------------------------------

    if ($_SESSION['usuario_rol'] === 'ADMIN') {

        return;
    }


    // -------------------------------------------------
    // Verificar si el rol actual está permitido
    // -------------------------------------------------

    if (!in_array($_SESSION['usuario_rol'], $roles_permitidos)) {

        header('Location: ../auth/login.php?error=permiso');

        exit;
    }
}