<?php
// =====================================================
// index.php
// Página pública principal del negocio
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

session_start();

require_once __DIR__ . '/config/db.php';


// -----------------------------------------------------
// Carga del CSS con control de versión
// -----------------------------------------------------

$ruta_css = 'assets/css/main.css';

$ruta_css_fisica = __DIR__ . '/assets/css/main.css';

$version_css = file_exists($ruta_css_fisica) ? filemtime($ruta_css_fisica) : time();

// -----------------------------------------------------
// Consulta publica de tracking
// -----------------------------------------------------

$tracking_buscado = trim($_GET['tracking'] ?? '');
$resultado_tracking = null;
$mensaje_tracking = '';
$tipo_mensaje_tracking = '';

if ($tracking_buscado !== '') {
    try {
        $sqlTracking = "
            SELECT
                nro_tracking,
                fecha_recepcion,
                estado_actual,
                fecha_ultimo_movimiento,
                sucursal_origen,
                sucursal_destino,
                sucursal_actual,
                destinatario,
                observaciones
            FROM vista_tracking_publico
            WHERE nro_tracking = :nro_tracking
            LIMIT 1
        ";

        $stmtTracking = $pdo->prepare($sqlTracking);
        $stmtTracking->execute([
            ':nro_tracking' => $tracking_buscado
        ]);

        $resultado_tracking = $stmtTracking->fetch();

        if (!$resultado_tracking) {
            $mensaje_tracking = 'No encontramos un envio con ese codigo de tracking.';
            $tipo_mensaje_tracking = 'warning';
        }
    } catch (PDOException $e) {
        $mensaje_tracking = 'No pudimos consultar el tracking en este momento.';
        $tipo_mensaje_tracking = 'error';
    }
}


// -----------------------------------------------------
// Detectar si hay sesión iniciada
// -----------------------------------------------------

$usuario_logueado = isset($_SESSION['username']) && isset($_SESSION['usuario_rol']);

$panel_destino = 'auth/login.php';

if ($usuario_logueado) {

    if ($_SESSION['usuario_rol'] === 'ADMIN') {
        $panel_destino = 'admin/dashboard.php';
    }

    if ($_SESSION['usuario_rol'] === 'EMPLEADO_SUCURSAL') {
        $panel_destino = 'empleado/dashboard.php';
    }

    if ($_SESSION['usuario_rol'] === 'CHOFER') {
        $panel_destino = 'chofer/dashboard.php';
    }

    if ($_SESSION['usuario_rol'] === 'CLIENTE') {
        $panel_destino = 'cliente/dashboard.php';
    }
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">

    <title>LogiTrack - Sistema de Tracking</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="<?php echo $ruta_css . '?v=' . $version_css; ?>">
</head>
<body>

    <header class="public-header">

        <div class="public-brand">
            <div class="public-logo">
                📦
            </div>

            <div>
                <strong>LogiTrack</strong>
                <span>Sistema de tracking sucursal a sucursal</span>
            </div>
        </div>

        <nav class="public-nav">
            <a href="#servicio">Servicio</a>
            <a href="#seguimiento">Seguimiento</a>
            <a href="<?php echo $panel_destino; ?>" class="public-login-link">
                <?php echo $usuario_logueado ? 'Ir a mi panel' : 'Iniciar sesión'; ?>
            </a>
        </nav>

    </header>


    <main class="public-main">

        <section class="public-hero">

            <div class="public-hero-content">

                <span class="public-kicker">
                    Gestión logística simple y ordenada
                </span>

                <h1>
                    Seguimiento de envíos entre sucursales
                </h1>

                <p>
                    LogiTrack permite registrar solicitudes de envío, controlar paquetes,
                    gestionar viajes entre sucursales y consultar el estado de cada envío
                    desde su recepción hasta el retiro final.
                </p>

                <div class="public-actions">
                    <a href="<?php echo $panel_destino; ?>" class="btn-public-primary">
                        <?php echo $usuario_logueado ? 'Entrar al sistema' : 'Acceder al sistema'; ?>
                    </a>

                    <a href="#seguimiento" class="btn-public-secondary">
                        Consultar tracking
                    </a>
                </div>

            </div>

            <div class="public-hero-card">

                <h2>Estado del envío</h2>

                <div class="tracking-preview">

                    <div class="tracking-step active">
                        <span></span>
                        <p>Solicitud creada</p>
                    </div>

                    <div class="tracking-step active">
                        <span></span>
                        <p>Recibido en sucursal</p>
                    </div>

                    <div class="tracking-step">
                        <span></span>
                        <p>En tránsito</p>
                    </div>

                    <div class="tracking-step">
                        <span></span>
                        <p>Disponible para retiro</p>
                    </div>

                </div>

            </div>

        </section>


        <section class="public-section" id="servicio">

            <h2>¿Qué permite hacer el sistema?</h2>

            <div class="public-grid">

                <article class="public-card">
                    <h3>Registrar envíos</h3>
                    <p>
                        El cliente puede iniciar una solicitud de envío y cargar
                        los paquetes asociados al mismo código de tracking.
                    </p>
                </article>

                <article class="public-card">
                    <h3>Gestionar viajes</h3>
                    <p>
                        El empleado puede asignar envíos a viajes entre sucursales,
                        considerando destino, capacidad y prioridad operativa.
                    </p>
                </article>

                <article class="public-card">
                    <h3>Controlar retiros</h3>
                    <p>
                        El sistema permite registrar disponibilidad para retiro,
                        autorizados y devolución al origen si vence el plazo.
                    </p>
                </article>

            </div>

        </section>


        <section class="public-section" id="seguimiento">

            <h2>Consultar envío</h2>

            <p class="public-section-text">
                Ingresá el código de tracking para consultar el estado del envío.
                Esta función se programará más adelante.
            </p>

            <form class="public-tracking-form" action="#seguimiento" method="GET">

                <input
                    type="text"
                    name="tracking"
                    placeholder="Ej: TRKET100012"
                    class="form-control"
                    value="<?php echo htmlspecialchars($tracking_buscado); ?>"
                    required
                >

                <button type="submit" class="btn-public-primary">
                    Buscar
                </button>

            </form>

            <?php if ($mensaje_tracking !== ''): ?>
                <div
                    class="<?php echo $tipo_mensaje_tracking === 'error' ? 'alert alert-error' : 'alert alert-warning'; ?>"
                    style="margin-top: 18px;"
                >
                    <?php echo htmlspecialchars($mensaje_tracking); ?>
                </div>
            <?php endif; ?>

            <?php if ($resultado_tracking): ?>
                <article class="public-card" style="margin-top: 24px; text-align: left;">
                    <h3 style="margin-top: 0;">
                        Tracking <?php echo htmlspecialchars($resultado_tracking['nro_tracking']); ?>
                    </h3>

                    <div class="public-grid" style="grid-template-columns: repeat(2, 1fr); gap: 16px;">
                        <p>
                            <strong>Estado actual:</strong><br>
                            <?php echo htmlspecialchars($resultado_tracking['estado_actual']); ?>
                        </p>

                        <p>
                            <strong>Ultimo movimiento:</strong><br>
                            <?php echo htmlspecialchars($resultado_tracking['fecha_ultimo_movimiento']); ?>
                        </p>

                        <p>
                            <strong>Origen:</strong><br>
                            <?php echo htmlspecialchars($resultado_tracking['sucursal_origen']); ?>
                        </p>

                        <p>
                            <strong>Destino:</strong><br>
                            <?php echo htmlspecialchars($resultado_tracking['sucursal_destino']); ?>
                        </p>

                        <p>
                            <strong>Sucursal actual:</strong><br>
                            <?php echo htmlspecialchars($resultado_tracking['sucursal_actual']); ?>
                        </p>

                        <p>
                            <strong>Destinatario:</strong><br>
                            <?php echo htmlspecialchars($resultado_tracking['destinatario']); ?>
                        </p>
                    </div>

                    <?php if (!empty($resultado_tracking['observaciones'])): ?>
                        <p style="margin-bottom: 0;">
                            <strong>Observaciones:</strong><br>
                            <?php echo htmlspecialchars($resultado_tracking['observaciones']); ?>
                        </p>
                    <?php endif; ?>
                </article>
            <?php endif; ?>

        </section>

    </main>


    <footer class="public-footer">

        <p>
            LogiTrack - Sistema de tracking y gestión logística
        </p>

    </footer>

</body>
</html>
