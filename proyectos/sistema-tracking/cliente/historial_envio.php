<?php
// =====================================================
// historial_envio.php
// Historial completo de un envío
// - acceso para CLIENTE y ADMIN
// - CLIENTE: puede ver el historial si es remitente o destinatario
// - ADMIN: puede ver cualquier envío
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'CLIENTE']);

$titulo_pagina = 'Historial del Envío';

$mensaje = '';
$tipo_mensaje = '';

$rol_actual = $_SESSION['usuario_rol'] ?? '';
$tracking = trim($_GET['tracking'] ?? '');

$dni_cliente_sesion = '';
$cliente_actual = null;

$envio = null;
$historial = [];

$resumen = [
    'total_movimientos' => 0,
    'primer_movimiento' => '',
    'ultimo_movimiento' => '',
    'estado_actual' => ''
];


// -----------------------------------------------------
// FUNCIONES AUXILIARES
// -----------------------------------------------------

function obtenerDniClienteSesionHistorial(): string
{
    if (!empty($_SESSION['dni_cliente'])) {
        return (string) $_SESSION['dni_cliente'];
    }

    if (!empty($_SESSION['usuario_dni_cliente'])) {
        return (string) $_SESSION['usuario_dni_cliente'];
    }

    if (!empty($_SESSION['cliente_dni'])) {
        return (string) $_SESSION['cliente_dni'];
    }

    return '';
}


// -----------------------------------------------------
// 1. IDENTIFICAR CLIENTE SI ENTRA COMO CLIENTE
// -----------------------------------------------------

if ($rol_actual === 'CLIENTE') {

    $dni_cliente_sesion = obtenerDniClienteSesionHistorial();

    if ($dni_cliente_sesion === '') {

        $mensaje = 'No se pudo identificar el cliente en la sesión.';
        $tipo_mensaje = 'error';

    } else {

        try {

            $sqlClienteActual = "
                SELECT
                    dni,
                    nombre,
                    apellido,
                    telefono,
                    email
                FROM vista_cliente
                WHERE dni = :dni
                LIMIT 1
            ";

            $stmtClienteActual = $pdo->prepare($sqlClienteActual);
            $stmtClienteActual->execute([
                ':dni' => $dni_cliente_sesion
            ]);

            $cliente_actual = $stmtClienteActual->fetch();

            if (!$cliente_actual) {
                $mensaje = 'No se encontró el cliente asociado a la sesión actual.';
                $tipo_mensaje = 'error';
            }

        } catch (PDOException $e) {

            $mensaje = 'Ocurrió un error al cargar los datos del cliente actual.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 2. VALIDAR TRACKING
// -----------------------------------------------------

if ($tracking === '' && $mensaje === '') {
    $mensaje = 'No se recibió un tracking válido para consultar el historial.';
    $tipo_mensaje = 'warning';
}


// -----------------------------------------------------
// 3. CARGAR DATOS DEL ENVÍO
// -----------------------------------------------------

if ($tracking !== '' && $mensaje === '') {

    try {

        $sqlEnvio = "
            SELECT
                e.nro_tracking,
                e.fecha_recepcion,
                e.dni_remitente,
                e.dni_destinatario,
                e.cod_sucursal_origen,
                e.cod_sucursal_destino,

                cr.nombre AS nombre_remitente,
                cr.apellido AS apellido_remitente,
                cd.nombre AS nombre_destinatario,
                cd.apellido AS apellido_destinatario,

                so.nombre AS nombre_sucursal_origen,
                sd.nombre AS nombre_sucursal_destino,

                he.cod_estado_envio AS cod_estado_actual,
                ee.nombre AS nombre_estado_actual,
                he.fecha_hora AS fecha_estado_actual,
                hs.nombre AS nombre_sucursal_estado_actual
            FROM Envio e
            INNER JOIN vista_cliente cr
                ON e.dni_remitente = cr.dni
            INNER JOIN vista_cliente cd
                ON e.dni_destinatario = cd.dni
            INNER JOIN Sucursal so
                ON e.cod_sucursal_origen = so.cod_sucursal
            INNER JOIN Sucursal sd
                ON e.cod_sucursal_destino = sd.cod_sucursal

            LEFT JOIN (
                SELECT
                    h1.nro_tracking,
                    h1.cod_estado_envio,
                    h1.fecha_hora,
                    h1.cod_sucursal_actual
                FROM Historial_Estado h1
                INNER JOIN (
                    SELECT nro_tracking, MAX(nro_movimiento) AS max_mov
                    FROM Historial_Estado
                    GROUP BY nro_tracking
                ) hm
                    ON h1.nro_tracking = hm.nro_tracking
                   AND h1.nro_movimiento = hm.max_mov
            ) he
                ON e.nro_tracking = he.nro_tracking

            LEFT JOIN Estado_Envio ee
                ON he.cod_estado_envio = ee.cod_estado_envio

            LEFT JOIN Sucursal hs
                ON he.cod_sucursal_actual = hs.cod_sucursal

            WHERE e.nro_tracking = :nro_tracking
            LIMIT 1
        ";

        $stmtEnvio = $pdo->prepare($sqlEnvio);
        $stmtEnvio->execute([
            ':nro_tracking' => $tracking
        ]);

        $envio = $stmtEnvio->fetch();

        if (!$envio) {

            $mensaje = 'No se encontró ningún envío con ese tracking.';
            $tipo_mensaje = 'warning';

        } elseif (
            $rol_actual === 'CLIENTE' &&
            $dni_cliente_sesion !== $envio['dni_remitente'] &&
            $dni_cliente_sesion !== $envio['dni_destinatario']
        ) {

            $envio = null;
            $mensaje = 'No tenés permiso para ver el historial de ese envío.';
            $tipo_mensaje = 'error';
        }

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar los datos del envío.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 4. CARGAR HISTORIAL COMPLETO
// -----------------------------------------------------

if ($envio) {

    try {

        $sqlHistorial = "
            SELECT
                h.nro_tracking,
                h.nro_movimiento,
                h.cod_estado_envio,
                h.fecha_hora,
                h.cod_sucursal_actual,
                h.patente,
                h.fecha_salida,
                h.observaciones,
                ee.nombre AS nombre_estado,
                s.nombre AS nombre_sucursal_actual
            FROM Historial_Estado h
            INNER JOIN Estado_Envio ee
                ON h.cod_estado_envio = ee.cod_estado_envio
            INNER JOIN Sucursal s
                ON h.cod_sucursal_actual = s.cod_sucursal
            WHERE h.nro_tracking = :nro_tracking
            ORDER BY h.nro_movimiento ASC
        ";

        $stmtHistorial = $pdo->prepare($sqlHistorial);
        $stmtHistorial->execute([
            ':nro_tracking' => $tracking
        ]);

        $historial = $stmtHistorial->fetchAll();

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar el historial del envío.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 5. ARMAR RESUMEN
// -----------------------------------------------------

if (!empty($historial)) {

    $resumen['total_movimientos'] = count($historial);
    $resumen['primer_movimiento'] = $historial[0]['fecha_hora'] ?? '';
    $resumen['ultimo_movimiento'] = $historial[count($historial) - 1]['fecha_hora'] ?? '';
    $resumen['estado_actual'] = $historial[count($historial) - 1]['nombre_estado'] ?? '';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Historial del Envío</h1>
        <p class="page-subtitle">
            Consultá todos los movimientos registrados para el envío seleccionado.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>

    <section style="margin-bottom: 24px;">
        <a href="javascript:history.back()" class="btn-public-secondary">
            ← Volver
        </a>
    </section>

    <?php if ($envio): ?>

        <section class="dashboard-grid" style="margin-bottom: 24px;">

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Datos del envío</h3>

                <p><strong>Tracking:</strong> <?php echo htmlspecialchars($envio['nro_tracking']); ?></p>
                <p><strong>Fecha de solicitud/recepción:</strong> <?php echo htmlspecialchars($envio['fecha_recepcion']); ?></p>
                <p><strong>Remitente:</strong> <?php echo htmlspecialchars($envio['apellido_remitente'] . ', ' . $envio['nombre_remitente']); ?></p>
                <p><strong>Destinatario:</strong> <?php echo htmlspecialchars($envio['apellido_destinatario'] . ', ' . $envio['nombre_destinatario']); ?></p>
                <p><strong>Origen:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_origen']); ?></p>
                <p><strong>Destino:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_destino']); ?></p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Estado actual</h3>

                <p><strong>Estado:</strong> <?php echo htmlspecialchars($envio['nombre_estado_actual'] ?? 'Sin historial'); ?></p>
                <p><strong>Fecha:</strong> <?php echo htmlspecialchars($envio['fecha_estado_actual'] ?? ''); ?></p>
                <p><strong>Sucursal actual:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_estado_actual'] ?? ''); ?></p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Acciones rápidas</h3>

                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="detalle_envio.php?tracking=<?php echo urlencode($envio['nro_tracking']); ?>" class="btn-public-secondary">
                        Ver detalle
                    </a>

                    <?php if ($dni_cliente_sesion === $envio['dni_destinatario'] || $rol_actual === 'ADMIN'): ?>
                        <a href="datos_retiro.php?tracking=<?php echo urlencode($envio['nro_tracking']); ?>" class="btn-public-secondary">
                            Datos retiro
                        </a>

                        <a href="autorizar_retiro.php?tracking=<?php echo urlencode($envio['nro_tracking']); ?>" class="btn-public-secondary">
                            Autorizar retiro
                        </a>
                    <?php endif; ?>
                </div>
            </article>

        </section>


        <section class="dashboard-grid" style="margin-bottom: 24px;">

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Total movimientos</h3>
                <p style="font-size: 28px; margin: 0; font-weight: 700;">
                    <?php echo htmlspecialchars((string) $resumen['total_movimientos']); ?>
                </p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Primer movimiento</h3>
                <p style="font-size: 18px; margin: 0; font-weight: 700;">
                    <?php echo htmlspecialchars($resumen['primer_movimiento']); ?>
                </p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Último movimiento</h3>
                <p style="font-size: 18px; margin: 0; font-weight: 700;">
                    <?php echo htmlspecialchars($resumen['ultimo_movimiento']); ?>
                </p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Último estado</h3>
                <p style="font-size: 18px; margin: 0; font-weight: 700;">
                    <?php echo htmlspecialchars($resumen['estado_actual']); ?>
                </p>
            </article>

        </section>


        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Línea de tiempo del envío</h3>

            <?php if (empty($historial)): ?>

                <p style="margin: 0; color: var(--color-muted);">
                    Este envío todavía no tiene movimientos registrados.
                </p>

            <?php else: ?>

                <div style="display: flex; flex-direction: column; gap: 14px;">

                    <?php foreach ($historial as $movimiento): ?>
                        <div style="border: 1px solid var(--color-border); border-radius: 18px; padding: 16px; background-color: var(--color-white);">
                            <div style="display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 8px;">
                                <strong style="color: var(--color-primary);">
                                    Movimiento <?php echo htmlspecialchars($movimiento['nro_movimiento']); ?> - <?php echo htmlspecialchars($movimiento['nombre_estado']); ?>
                                </strong>

                                <span style="color: var(--color-muted);">
                                    <?php echo htmlspecialchars($movimiento['fecha_hora']); ?>
                                </span>
                            </div>

                            <div style="margin-bottom: 6px;">
                                <strong>Sucursal:</strong>
                                <?php echo htmlspecialchars($movimiento['nombre_sucursal_actual']); ?>
                            </div>

                            <?php if (!empty($movimiento['patente'])): ?>
                                <div style="margin-bottom: 6px;">
                                    <strong>Viaje asociado:</strong>
                                    <?php echo htmlspecialchars($movimiento['patente']); ?>

                                    <?php if (!empty($movimiento['fecha_salida'])): ?>
                                        | salida: <?php echo htmlspecialchars($movimiento['fecha_salida']); ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($movimiento['observaciones'])): ?>
                                <div>
                                    <strong>Observaciones:</strong>
                                    <?php echo htmlspecialchars($movimiento['observaciones']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </section>


        <section class="dashboard-card">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Historial en tabla</h3>

            <div style="overflow-x: auto;">

                <table style="width: 100%; border-collapse: collapse; min-width: 1250px;">

                    <thead>
                        <tr style="background-color: var(--color-surface-soft);">
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Movimiento</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Estado</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha y hora</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Sucursal</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Patente</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha salida viaje</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Observaciones</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($historial)): ?>

                            <tr>
                                <td colspan="7" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                    Este envío todavía no tiene movimientos registrados.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($historial as $movimiento): ?>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($movimiento['nro_movimiento']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($movimiento['nombre_estado']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($movimiento['fecha_hora']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($movimiento['nombre_sucursal_actual']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($movimiento['patente'] ?? ''); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($movimiento['fecha_salida'] ?? ''); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border); max-width: 280px;">
                                        <?php echo htmlspecialchars($movimiento['observaciones'] ?? ''); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                        <?php endif; ?>
                    </tbody>

                </table>

            </div>

        </section>

    <?php endif; ?>

</main>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>