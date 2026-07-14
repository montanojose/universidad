<?php
// =====================================================
// procesar_login.php
// Procesa el inicio de sesión
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

session_start();

require_once __DIR__ . '/../config/db.php';


// -----------------------------------------------------
// Verificar que el formulario llegue por POST
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Location: login.php');

    exit;
}


// -----------------------------------------------------
// Captura y limpieza de datos del formulario
// -----------------------------------------------------

$username = trim($_POST['username'] ?? '');

$password = trim($_POST['password'] ?? '');


// -----------------------------------------------------
// Validación básica
// -----------------------------------------------------

if ($username === '' || $password === '') {

    header('Location: login.php?error=campos');

    exit;
}


// -----------------------------------------------------
// Buscar usuario en la base de datos
// -----------------------------------------------------

try {

    $sql = "
        SELECT
            u.username,
            u.password_hash,
            u.cod_rol,
            u.dni_persona,
            u.activo,
            p.nombre AS persona_nombre,
            p.apellido AS persona_apellido,
            ch.legajo AS legajo_chofer,
            c.dni AS dni_cliente,
            e.legajo_empleado
        FROM usuario u
        LEFT JOIN persona p
            ON u.dni_persona = p.dni
        LEFT JOIN vista_chofer ch
            ON u.dni_persona = ch.dni
        LEFT JOIN vista_cliente c
            ON u.dni_persona = c.dni
        LEFT JOIN vista_empleado_sucursal e
            ON u.dni_persona = e.dni
        WHERE u.username = :username
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':username' => $username
    ]);

    $usuario = $stmt->fetch();

} catch (PDOException $e) {

    header('Location: login.php?error=db');

    exit;
}


// -----------------------------------------------------
// Validar existencia del usuario
// -----------------------------------------------------

if (!$usuario) {

    header('Location: login.php?error=credenciales');

    exit;
}


// -----------------------------------------------------
// Validar si el usuario está activo
// -----------------------------------------------------

if ((int)$usuario['activo'] !== 1) {

    header('Location: login.php?error=inactivo');

    exit;
}


// -----------------------------------------------------
// Verificar contraseña
// -----------------------------------------------------

if (!password_verify($password, $usuario['password_hash'])) {

    header('Location: login.php?error=credenciales');

    exit;
}


// -----------------------------------------------------
// Login correcto
// Regenerar ID de sesión por seguridad
// -----------------------------------------------------

session_regenerate_id(true);


// -----------------------------------------------------
// Guardar datos importantes en $_SESSION
// -----------------------------------------------------

$_SESSION['username'] = $usuario['username'];

$_SESSION['usuario_rol'] = $usuario['cod_rol'];

$_SESSION['dni_persona'] = $usuario['dni_persona'];

$_SESSION['legajo_chofer'] = $usuario['legajo_chofer'];

$_SESSION['dni_cliente'] = $usuario['dni_cliente'];

$_SESSION['legajo_empleado'] = $usuario['legajo_empleado'];

$_SESSION['usuario_legajo_chofer'] = $usuario['legajo_chofer'];

$_SESSION['usuario_dni_cliente'] = $usuario['dni_cliente'];

$_SESSION['usuario_legajo_empleado'] = $usuario['legajo_empleado'];

$nombre_persona = trim(($usuario['persona_nombre'] ?? '') . ' ' . ($usuario['persona_apellido'] ?? ''));

$_SESSION['usuario_nombre'] = ($nombre_persona !== '') ? $nombre_persona : $usuario['username'];

$_SESSION['ultimo_acceso'] = time();


// -----------------------------------------------------
// Redirigir según el rol
// -----------------------------------------------------

$destino = match ($usuario['cod_rol']) {

    'ADMIN' => '../admin/dashboard.php',

    'EMPLEADO_SUCURSAL' => '../empleado/dashboard.php',

    'CHOFER' => '../chofer/dashboard.php',

    'CLIENTE' => '../cliente/dashboard.php',

    default => 'login.php?error=permiso'

};

header('Location: ' . $destino);

exit;
