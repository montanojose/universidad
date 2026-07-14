<?php
// =====================================================
// logout.php
// Cierre completo de sesión
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

session_start();


// -----------------------------------------------------
// Vaciar todas las variables de sesión
// -----------------------------------------------------

$_SESSION = [];


// -----------------------------------------------------
// Borrar la cookie de sesión del navegador
// -----------------------------------------------------

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


// -----------------------------------------------------
// Destruir la sesión en el servidor
// -----------------------------------------------------

session_destroy();


// -----------------------------------------------------
// Evitar que el navegador muestre páginas protegidas
// desde la caché después del logout
// -----------------------------------------------------

header('Cache-Control: no-store, no-cache, must-revalidate');

header('Pragma: no-cache');


// -----------------------------------------------------
// Redirigir al login
// -----------------------------------------------------

header('Location: login.php');

exit;