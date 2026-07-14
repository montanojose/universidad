<?php
// =====================================================
// detalle_viaje.php
// Detalle completo de un viaje
// - acceso para CHOFER y ADMIN
// - CHOFER: solo puede ver sus viajes
// - ADMIN: puede ver cualquier viaje
// - muestra vehículo, chofer, carga, envíos e incidentes
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'CHOFER']);

$titulo_pagina = 'Detalle del Viaje';

$mensaje = '';
$tipo_mensaje = '';

$rol_actual = $_SESSION['usuario_rol'] ?? '';

$patente = trim($_GET['patente'] ?? '');
$fecha_salida = trim($_GET['fecha_salida'] ?? '');

$legajo_chofer_sesion = '';

$viaje = null;
$envios_viaje = [];
$incidentes = [];

$resumen = [
    'cantidad_envios' => 0,
    'cantidad_paquetes' => 0,
    'peso_total_kg' => 0
];


// -----------------------------------------------------
// FUNCIONES AUXILIARES
// -----------------------------------------------------

function obtenerLegajoChoferSesionDetalle(): string
{
    if (!empty($_SESSION['legajo_chofer'])) {
        return (string) $_SESSION['legajo_chofer'];
    }

    if (!empty($_SESSION['usuario_legajo_chofer'])) {
        return (string) $_SESSION['usuario_legajo_chofer'];
    }

    if (!empty($_SESSION['chofer_legajo'])) {
        return (string) $_SESSION['chofer_legajo'];
    }

    return '';
}


// -----------------------------------------------------
// 1. IDENTIFICAR CHOFER SI ENTRA COMO CHOFER
// -----------------------------------------------------

if ($rol_actual === 'CHOFER') {
    $legajo_chofer_sesion = obtenerLegajoChoferSesionDetalle();

    if ($legajo_chofer_sesion === '') {
        $mensaje = 'No se pudo identificar el legajo del chofer en la sesión.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 2. VALIDAR PARÁMETROS
// -----------------------------------------------------

if ($patente === '' || $fecha_salida === '') {
    if ($mensaje === '') {
        $mensaje = 'Faltan datos para consultar el detalle del viaje.';
        $tipo_mensaje = 'warning';
    }
}


// -----------------------------------------------------
// 3. CARGAR VIAJE
// -----------------------------------------------------

if ($mensaje === '' && $patente !== '' && $fecha_salida !== '') {

    try {

        $sqlViaje = "
            SELECT
                v.patente,
                v.fecha_salida,
                v.fecha_llegada_estimada,
                v.fecha_llegada_real,
                v.legajo_chofer,
                v.cod_sucursal_origen,
                v.cod_sucursal_destino,
                v.cod_estado_viaje,

                ch.nombre AS nombre_chofer,
                ch.apellido AS apellido_chofer,
                ch.telefono AS telefono_chofer,
                ch.nro_licencia,
                ch.fecha_vencimiento_licencia,

                so.nombre AS nombre_origen,
                sd.nombre AS nombre_destino,
                ev.nombre AS nombre_estado_viaje,

                ve.marca,
                ve.modelo,
                ve.cod_estado_vehiculo,
                tv.nombre AS tipo_vehiculo,
                tv.capacidad_kg_max,
                tv.capacidad_volumen,
                evh.nombre AS nombre_estado_vehiculo
            FROM Viaje v
            INNER JOIN vista_chofer ch
                ON v.legajo_chofer = ch.legajo
            INNER JOIN Sucursal so
                ON v.cod_sucursal_origen = so.cod_sucursal
            INNER JOIN Sucursal sd
                ON v.cod_sucursal_destino = sd.cod_sucursal
            INNER JOIN Estado_Viaje ev
                ON v.cod_estado_viaje = ev.cod_estado_viaje
            INNER JOIN Vehiculo ve
                ON v.patente = ve.patente
            INNER JOIN Tipo_Vehiculo tv
                ON ve.cod_tipo_vehiculo = tv.cod_tipo_vehiculo
            INNER JOIN Estado_Vehiculo evh
                ON ve.cod_estado_vehiculo = evh.cod_estado_vehiculo
            WHERE v.patente = :patente
              AND v.fecha_salida = :fecha_salida
            LIMIT 1
        ";

        $stmtViaje = $pdo->prepare($sqlViaje);
        $stmtViaje->execute([
            ':patente' => $patente,
            ':fecha_salida' => $fecha_salida
        ]);

        $viaje = $stmtViaje->fetch();

        if (!$viaje) {
            $mensaje = 'No se encontró el viaje seleccionado.';
            $tipo_mensaje = 'warning';
        } elseif ($rol_actual === 'CHOFER' && $viaje['legajo_chofer'] !== $legajo_chofer_sesion) {
            $viaje = null;
            $mensaje = 'No tenés permiso para ver ese viaje.';
            $tipo_mensaje = 'error';
        }

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar el viaje.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 4. CARGAR ENVÍOS DEL VIAJE
// -----------------------------------------------------

if ($viaje) {

    try {

        $sqlEnviosViaje = "
            SELECT
                ve.nro_tracking,
                ve.fecha_asignacion,

                e.fecha_recepcion,
                e.dni_remitente,
                e.dni_destinatario,

                cr.nombre AS nombre_remitente,
                cr.apellido AS apellido_remitente,
                cd.nombre AS nombre_destinatario,
                cd.apellido AS apellido_destinatario,

                so.nombre AS nombre_origen_envio,
                sd.nombre AS nombre_destino_envio,

                COALESCE(pkg.cantidad_paquetes, 0) AS cantidad_paquetes,
                COALESCE(pkg.peso_total_kg, 0) AS peso_total_kg,

                he.cod_estado_envio AS cod_estado_actual,
                ee.nombre AS nombre_estado_actual
            FROM Viaje_Envio ve
            INNER JOIN Envio e
                ON ve.nro_tracking = e.nro_tracking
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
                    nro_tracking,
                    COUNT(*) AS cantidad_paquetes,
                    COALESCE(SUM(peso_kg), 0) AS peso_total_kg
                FROM Paquete
                GROUP BY nro_tracking
            ) pkg
                ON e.nro_tracking = pkg.nro_tracking

            LEFT JOIN (
                SELECT h1.nro_tracking, h1.cod_estado_envio
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

            WHERE ve.patente = :patente
              AND ve.fecha_salida = :fecha_salida
            ORDER BY ve.fecha_asignacion ASC, ve.nro_tracking ASC
        ";

        $stmtEnviosViaje = $pdo->prepare($sqlEnviosViaje);
        $stmtEnviosViaje->execute([
            ':patente' => $patente,
            ':fecha_salida' => $fecha_salida
        ]);

        $envios_viaje = $stmtEnviosViaje->fetchAll();

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar los envíos del viaje.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 5. RESUMEN DE CARGA
// -----------------------------------------------------

if (!empty($envios_viaje)) {

    $resumen['cantidad_envios'] = count($envios_viaje);

    foreach ($envios_viaje as $envio_item) {
        $resumen['cantidad_paquetes'] += (int) $envio_item['cantidad_paquetes'];
        $resumen['peso_total_kg'] += (float) $envio_item['peso_total_kg'];
    }
}


// -----------------------------------------------------
// 6. CARGAR INCIDENTES DEL VIAJE
// -----------------------------------------------------

if ($viaje) {

    try {

        $sqlIncidentes = "
            SELECT
                i.nro_incidente,
                i.cod_tipo_incidente,
                i.descripcion,
                i.fecha_hora,
                ti.nombre AS nombre_tipo_incidente
            FROM Incidente i
            INNER JOIN Tipo_Incidente ti
                ON i.cod_tipo_incidente = ti.cod_tipo_incidente
            WHERE i.patente = :patente
              AND i.fecha_salida = :fecha_salida
            ORDER BY i.fecha_hora DESC, i.nro_incidente DESC
        ";

        $stmtIncidentes = $pdo->prepare($sqlIncidentes);
        $stmtIncidentes->execute([
            ':patente' => $patente,
            ':fecha_salida' => $fecha_salida
        ]);

        $incidentes = $stmtIncidentes->fetchAll();

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar los incidentes del viaje.';
        $tipo_mensaje = 'error';
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Detalle del Viaje</h1>
        <p class="page-subtitle">
            Consultá toda la información operativa del viaje seleccionado.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>

    <section style="margin-bottom: 24px;">
        <a href="mis_viajes.php" class="btn-public-secondary">
            ← Volver a mis viajes
        </a>
    </section>

    <?php if ($viaje): ?>

        <section class="dashboard-grid" style="margin-bottom: 24px;">

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Datos generales</h3>

                <p><strong>Patente:</strong> <?php echo htmlspecialchars($viaje['patente']); ?></p>
                <p><strong>Fecha salida:</strong> <?php echo htmlspecialchars($viaje['fecha_salida']); ?></p>
                <p><strong>Llegada estimada:</strong> <?php echo htmlspecialchars($viaje['fecha_llegada_estimada']); ?></p>
                <p><strong>Llegada real:</strong> <?php echo htmlspecialchars($viaje['fecha_llegada_real'] ?? ''); ?></p>
                <p><strong>Estado:</strong> <?php echo htmlspecialchars($viaje['nombre_estado_viaje']); ?></p>
                <p><strong>Trayecto:</strong> <?php echo htmlspecialchars($viaje['nombre_origen'] . ' → ' . $viaje['nombre_destino']); ?></p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Chofer asignado</h3>

                <p><strong>Legajo:</strong> <?php echo htmlspecialchars($viaje['legajo_chofer']); ?></p>
                <p><strong>Nombre:</strong> <?php echo htmlspecialchars($viaje['apellido_chofer'] . ', ' . $viaje['nombre_chofer']); ?></p>
                <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($viaje['telefono_chofer'] ?? ''); ?></p>
                <p><strong>Licencia:</strong> <?php echo htmlspecialchars($viaje['nro_licencia']); ?></p>
                <p><strong>Vencimiento:</strong> <?php echo htmlspecialchars($viaje['fecha_vencimiento_licencia']); ?></p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Vehículo</h3>

                <p><strong>Marca / Modelo:</strong> <?php echo htmlspecialchars($viaje['marca'] . ' ' . $viaje['modelo']); ?></p>
                <p><strong>Tipo:</strong> <?php echo htmlspecialchars($viaje['tipo_vehiculo']); ?></p>
                <p><strong>Capacidad máxima:</strong> <?php echo htmlspecialchars((string) $viaje['capacidad_kg_max']); ?> kg</p>
                <p><strong>Capacidad volumen:</strong> <?php echo htmlspecialchars((string) $viaje['capacidad_volumen']); ?></p>
                <p><strong>Estado vehículo:</strong> <?php echo htmlspecialchars($viaje['nombre_estado_vehiculo']); ?></p>
            </article>

        </section>


        <section class="dashboard-grid" style="margin-bottom: 24px;">

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Cantidad de envíos</h3>
                <p style="font-size: 28px; margin: 0; font-weight: 700;">
                    <?php echo htmlspecialchars((string) $resumen['cantidad_envios']); ?>
                </p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Cantidad de paquetes</h3>
                <p style="font-size: 28px; margin: 0; font-weight: 700;">
                    <?php echo htmlspecialchars((string) $resumen['cantidad_paquetes']); ?>
                </p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Peso total cargado</h3>
                <p style="font-size: 28px; margin: 0; font-weight: 700;">
                    <?php echo htmlspecialchars(number_format((float) $resumen['peso_total_kg'], 2, '.', '')); ?> kg
                </p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Capacidad restante</h3>
                <p style="font-size: 28px; margin: 0; font-weight: 700;">
                    <?php echo htmlspecialchars(number_format(((float) $viaje['capacidad_kg_max'] - (float) $resumen['peso_total_kg']), 2, '.', '')); ?> kg
                </p>
            </article>

        </section>


        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Envíos asignados al viaje</h3>

            <div style="overflow-x: auto;">

                <table style="width: 100%; border-collapse: collapse; min-width: 1500px;">

                    <thead>
                        <tr style="background-color: var(--color-surface-soft);">
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tracking</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha asignación</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha solicitud</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Remitente</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destinatario</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Origen envío</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destino envío</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Paquetes</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Peso total</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Estado actual</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($envios_viaje)): ?>

                            <tr>
                                <td colspan="10" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                    Este viaje todavía no tiene envíos asignados.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($envios_viaje as $envio_item): ?>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($envio_item['nro_tracking']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($envio_item['fecha_asignacion']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($envio_item['fecha_recepcion']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($envio_item['apellido_remitente'] . ', ' . $envio_item['nombre_remitente']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($envio_item['apellido_destinatario'] . ', ' . $envio_item['nombre_destinatario']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($envio_item['nombre_origen_envio']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($envio_item['nombre_destino_envio']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars((string) $envio_item['cantidad_paquetes']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars(number_format((float) $envio_item['peso_total_kg'], 2, '.', '')); ?> kg
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($envio_item['nombre_estado_actual'] ?? ''); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                        <?php endif; ?>
                    </tbody>

                </table>

            </div>

        </section>


        <section class="dashboard-card">

            <h3 style="margin-top: 0; margin-top: 0; margin-bottom: 18px;">Incidentes del viaje</h3>

            <div style="overflow-x: auto;">

                <table style="width: 100%; border-collapse: collapse; min-width: 1000px;">

                    <thead>
                        <tr style="background-color: var(--color-surface-soft);">
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">N° incidente</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tipo</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha y hora</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Descripción</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($incidentes)): ?>

                            <tr>
                                <td colspan="4" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                    No hay incidentes registrados para este viaje.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($incidentes as $incidente): ?>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($incidente['nro_incidente']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($incidente['nombre_tipo_incidente']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($incidente['fecha_hora']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border); max-width: 400px;">
                                        <?php echo htmlspecialchars($incidente['descripcion']); ?>
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