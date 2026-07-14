<?php
require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'EMPLEADO_SUCURSAL']);

$titulo_pagina = 'Dashboard Empleado';

$rol_actual = $_SESSION['usuario_rol'] ?? '';

function obtenerLegajoEmpleadoSesionDashboard(): string
{
    if (!empty($_SESSION['legajo_empleado'])) {
        return (string) $_SESSION['legajo_empleado'];
    }
    if (!empty($_SESSION['usuario_legajo_empleado'])) {
        return (string) $_SESSION['usuario_legajo_empleado'];
    }
    if (!empty($_SESSION['empleado_legajo'])) {
        return (string) $_SESSION['empleado_legajo'];
    }
    return '';
}

$cod_sucursal = '';
$nombre_sucursal = '';

$stats = [
    'envios_destino' => 0,
    'pendientes_retiro' => 0,
    'vencidos' => 0,
    'viajes_origen' => 0,
];

try {
    if ($rol_actual === 'EMPLEADO_SUCURSAL') {
        $legajo = obtenerLegajoEmpleadoSesionDashboard();

        if ($legajo !== '') {
            $sqlSucursal = "
                SELECT e.cod_sucursal, s.nombre
                FROM vista_empleado_sucursal e
                INNER JOIN Sucursal s ON e.cod_sucursal = s.cod_sucursal
                WHERE e.legajo_empleado = :legajo
                LIMIT 1
            ";
            $stmtSucursal = $pdo->prepare($sqlSucursal);
            $stmtSucursal->execute([':legajo' => $legajo]);
            $filaSucursal = $stmtSucursal->fetch();

            if ($filaSucursal) {
                $cod_sucursal = $filaSucursal['cod_sucursal'];
                $nombre_sucursal = $filaSucursal['nombre'];
            }
        }
    }

    if ($cod_sucursal !== '') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Envio WHERE cod_sucursal_destino = :sucursal");
        $stmt->execute([':sucursal' => $cod_sucursal]);
        $stats['envios_destino'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM Disponibilidad_Retiro dr
            LEFT JOIN Retiro_Envio re ON dr.nro_tracking = re.nro_tracking
            WHERE dr.cod_sucursal_retiro = :sucursal
              AND re.nro_tracking IS NULL
        ");
        $stmt->execute([':sucursal' => $cod_sucursal]);
        $stats['pendientes_retiro'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM Disponibilidad_Retiro dr
            LEFT JOIN Retiro_Envio re ON dr.nro_tracking = re.nro_tracking
            WHERE dr.cod_sucursal_retiro = :sucursal
              AND re.nro_tracking IS NULL
              AND dr.fecha_limite_retiro < NOW()
              AND dr.fecha_vencimiento_procesado IS NULL
        ");
        $stmt->execute([':sucursal' => $cod_sucursal]);
        $stats['vencidos'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Viaje WHERE cod_sucursal_origen = :sucursal");
        $stmt->execute([':sucursal' => $cod_sucursal]);
        $stats['viajes_origen'] = (int) $stmt->fetchColumn();

    } else {
        $stats['envios_destino'] = (int) $pdo->query("SELECT COUNT(*) FROM Envio")->fetchColumn();
        $stats['pendientes_retiro'] = (int) $pdo->query("
            SELECT COUNT(*)
            FROM Disponibilidad_Retiro dr
            LEFT JOIN Retiro_Envio re ON dr.nro_tracking = re.nro_tracking
            WHERE re.nro_tracking IS NULL
        ")->fetchColumn();
        $stats['vencidos'] = (int) $pdo->query("
            SELECT COUNT(*)
            FROM Disponibilidad_Retiro dr
            LEFT JOIN Retiro_Envio re ON dr.nro_tracking = re.nro_tracking
            WHERE re.nro_tracking IS NULL
              AND dr.fecha_limite_retiro < NOW()
              AND dr.fecha_vencimiento_procesado IS NULL
        ")->fetchColumn();
        $stats['viajes_origen'] = (int) $pdo->query("SELECT COUNT(*) FROM Viaje")->fetchColumn();
    }
} catch (PDOException $e) {
    // no cortar dashboard
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Panel de Empleado</h1>
        <p class="page-subtitle">
            Operaciones de recepción, seguimiento, retiro y devoluciones.
        </p>
    </section>

    <?php if ($cod_sucursal !== ''): ?>
        <section class="dashboard-card" style="margin-bottom: 24px;">
            <p style="margin: 0;">
                <strong>Sucursal actual:</strong>
                <?php echo htmlspecialchars($cod_sucursal . ' - ' . $nombre_sucursal); ?>
            </p>
        </section>
    <?php endif; ?>

    <section class="dashboard-grid" style="margin-bottom: 24px;">
        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Envíos destino</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['envios_destino']; ?></p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Pendientes retiro</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['pendientes_retiro']; ?></p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Vencidos</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['vencidos']; ?></p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Viajes origen</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['viajes_origen']; ?></p>
        </article>
    </section>

    <section class="dashboard-card" style="margin-bottom: 24px;">
        <h3 style="margin-top: 0; margin-bottom: 18px;">Operaciones principales</h3>

        <div class="dashboard-grid">
            <article class="dashboard-card">
                <h4>Buscar tracking</h4>
                <p>Consulta rápida de estado y ubicación del envío.</p>
                <a href="buscar_tracking.php" class="btn-primary">Abrir</a>
            </article>

            <article class="dashboard-card">
                <h4>Recepcionar envío</h4>
                <p>Registrar recepción física del paquete en sucursal.</p>
                <a href="recepcionar_envio.php" class="btn-primary">Abrir</a>
            </article>

            <article class="dashboard-card">
                <h4>Actualizar estado</h4>
                <p>Registrar nuevos movimientos del historial del envío.</p>
                <a href="actualizar_estado.php" class="btn-primary">Abrir</a>
            </article>

            <article class="dashboard-card">
                <h4>Asignar viaje</h4>
                <p>Asignar envíos a viajes y controlar carga operativa.</p>
                <a href="asignar_viaje.php" class="btn-primary">Abrir</a>
            </article>

            <article class="dashboard-card">
                <h4>Disponible para retiro</h4>
                <p>Marcar un envío listo para ser retirado en sucursal.</p>
                <a href="disponible_retiro.php" class="btn-primary">Abrir</a>
            </article>

            <article class="dashboard-card">
                <h4>Registrar retiro</h4>
                <p>Registrar quién retiró realmente el envío.</p>
                <a href="registrar_retiro.php" class="btn-primary">Abrir</a>
            </article>

            <article class="dashboard-card">
                <h4>Devoluciones</h4>
                <p>Procesar retornos al origen por vencimiento del plazo.</p>
                <a href="devoluciones.php" class="btn-primary">Abrir</a>
            </article>
        </div>
    </section>

    <section class="dashboard-card">
        <h3 style="margin-top: 0; margin-bottom: 18px;">Accesos rápidos</h3>

        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
            <a href="recepcionar_envio.php" class="btn-public-secondary">Recepcionar</a>
            <a href="asignar_viaje.php" class="btn-public-secondary">Asignar viaje</a>
            <a href="disponible_retiro.php" class="btn-public-secondary">Disponible retiro</a>
            <a href="registrar_retiro.php" class="btn-public-secondary">Registrar retiro</a>
            <a href="devoluciones.php" class="btn-public-secondary">Procesar devolución</a>
        </div>
    </section>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>