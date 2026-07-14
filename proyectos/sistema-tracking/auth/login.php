<?php
// =====================================================
// login.php
// Vista de inicio de sesión
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

session_start();

// -----------------------------------------------------
// Si el usuario ya inició sesión, lo mandamos a su panel
// -----------------------------------------------------

if (isset($_SESSION['usuario_rol'])) {

    if ($_SESSION['usuario_rol'] === 'ADMIN') {
        header('Location: ../admin/dashboard.php');
        exit;
    }

    if ($_SESSION['usuario_rol'] === 'EMPLEADO_SUCURSAL') {
        header('Location: ../empleado/dashboard.php');
        exit;
    }

    if ($_SESSION['usuario_rol'] === 'CHOFER') {
        header('Location: ../chofer/dashboard.php');
        exit;
    }

    if ($_SESSION['usuario_rol'] === 'CLIENTE') {
        header('Location: ../cliente/dashboard.php');
        exit;
    }
}

// -----------------------------------------------------
// Mensajes de error recibidos por GET
// -----------------------------------------------------

$error = $_GET['error'] ?? '';
$registro = $_GET['registro'] ?? '';


// -----------------------------------------------------
// Carga del CSS con control de versión
// Así siempre toma la última versión del archivo main.css
// -----------------------------------------------------

$ruta_css = '../assets/css/main.css';
$ruta_css_fisica = __DIR__ . '/../assets/css/main.css';

$version_css = file_exists($ruta_css_fisica) ? filemtime($ruta_css_fisica) : time();

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">

    <title>Login - LogiTrack</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="<?php echo $ruta_css . '?v=' . $version_css; ?>">
</head>
<body class="login-body">

    <main class="login-container">

        <section class="login-card">

            <div class="login-brand">
                <div class="login-logo">
                    📦
                </div>

                <div>
                    <h1>LogiTrack</h1>
                    <p>Sistema de tracking y gestión logística</p>
                </div>
            </div>

            <?php if ($error !== ''): ?>

                <div class="alert alert-error">

                    <?php if ($error === 'campos'): ?>

                        Completá usuario y contraseña.

                    <?php elseif ($error === 'credenciales'): ?>

                        Usuario o contraseña incorrectos.

                    <?php elseif ($error === 'inactivo'): ?>

                        El usuario se encuentra inactivo.

                    <?php elseif ($error === 'permiso'): ?>

                        No tenés permiso para acceder a esa sección.

                    <?php elseif ($error === 'sesion'): ?>

                        Primero debés iniciar sesión.

                    <?php elseif ($error === 'timeout'): ?>

                        La sesión expiró por inactividad.

                    <?php else: ?>

                        Ocurrió un error al iniciar sesión.

                    <?php endif; ?>

                </div>

            <?php endif; ?>

            <?php if ($registro === 'ok'): ?>
                <div class="alert alert-success">
                    Cuenta creada correctamente. Ya podes iniciar sesion.
                </div>
            <?php endif; ?>

            <form action="procesar_login.php" method="POST" class="login-form">

                <div class="form-group">
                    <label for="username">Usuario</label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-control"
                        placeholder="Ej: admin"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="password">Contraseña</label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        placeholder="Ingresá tu contraseña"
                        required
                    >
                </div>

                <div style="display: flex; flex-direction: column; align-items: center; gap: 12px; margin-top: 6px;">
                <button type="submit" class="btn-primary" style="width: min(100%, 280px);">
                    Iniciar sesión
                </button>

                <a href="registro_cliente.php" class="btn-public-secondary" style="width: min(100%, 280px); text-align: center;">
                    Crear cuenta de cliente
                </a>

                <a href="../index.php" class="btn-public-secondary" style="width: min(100%, 280px); text-align: center;">
                    Volver al inicio
                </a>
                </div>

            </form>

        </section>

    </main>

</body>
</html>
