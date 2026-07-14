<?php
// =====================================================
// verificar_sesion.php
// Controla que el usuario haya iniciado sesión
// y que la sesión no haya expirado
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

session_start();


// -----------------------------------------------------
// Verificar si existe una sesión iniciada
// -----------------------------------------------------

if (!isset($_SESSION['username']) || !isset($_SESSION['usuario_rol'])) {

    header('Location: ../auth/login.php?error=sesion');

    exit;
}


// -----------------------------------------------------
// Configuración del tiempo máximo de inactividad
// -----------------------------------------------------

$TIEMPO_MAXIMO_INACTIVIDAD = 30 * 60;


// -----------------------------------------------------
// Verificar si la sesión expiró por inactividad
// -----------------------------------------------------

if (isset($_SESSION['ultimo_acceso'])) {

    $tiempo_inactivo = time() - $_SESSION['ultimo_acceso'];

    if ($tiempo_inactivo > $TIEMPO_MAXIMO_INACTIVIDAD) {

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {

            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        header('Location: ../auth/login.php?error=timeout');

        exit;
    }
}


// -----------------------------------------------------
// Actualizar el último acceso
// -----------------------------------------------------

$_SESSION['ultimo_acceso'] = time();