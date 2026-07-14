<?php
// =====================================================
// buscar_tracking.php
// Consulta completa de un envío por tracking
// - acceso para EMPLEADO_SUCURSAL y ADMIN
// - muestra datos generales
// - muestra paquetes
// - muestra historial
// - muestra disponibilidad/retiro
// - muestra autorizados
// - muestra viajes asociados
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'EMPLEADO_SUCURSAL']);

$titulo_pagina = 'Buscar Envío por Tracking';

$mensaje = '';
$tipo_mensaje = '';

$tracking_buscado = trim($_GET['tracking'] ?? '');

$envio = null;
$estado_actual = null;
$paquetes = [];
$historial = [];
$disponibilidad = null;
$retiro = null;
$autorizados = [];
$viajes_asociados = [];


// -----------------------------------------------------
// 1. BUSCAR DATOS DEL ENVÍO
// -----------------------------------------------------

if ($tracking_buscado !== '') {

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
                cr.telefono AS telefono_remitente,
                cr.email AS email_remitente,
                cr.direccion AS direccion_remitente,

                cd.nombre AS nombre_destinatario,
                cd.apellido AS apellido_destinatario,
                cd.telefono AS telefono_destinatario,
                cd.email AS email_destinatario,
                cd.direccion AS direccion_destinatario,

                so.nombre AS nombre_sucursal_origen,
                sd.nombre AS nombre_sucursal_destino
            FROM Envio e
            INNER JOIN vista_cliente cr
                ON e.dni_remitente = cr.dni
            INNER JOIN vista_cliente cd
                ON e.dni_destinatario = cd.dni
            INNER JOIN Sucursal so
                ON e.cod_sucursal_origen = so.cod_sucursal
            INNER JOIN Sucursal sd
                ON e.cod_sucursal_destino = sd.cod_sucursal
            WHERE e.nro_tracking = :nro_tracking
            LIMIT 1
        ";

        $stmtEnvio = $pdo->prepare($sqlEnvio);
        $stmtEnvio->execute([
            ':nro_tracking' => $tracking_buscado
        ]);

        $envio = $stmtEnvio->fetch();

        if (!$envio) {
            $mensaje = 'No se encontró ningún envío con ese tracking.';
            $tipo_mensaje = 'warning';
        }

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al buscar el envío.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 2. ESTADO ACTUAL
// -----------------------------------------------------

if ($envio) {

    try {

        $sqlEstadoActual = "
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
            ORDER BY h.nro_movimiento DESC
            LIMIT 1
        ";

        $stmtEstadoActual = $pdo->prepare($sqlEstadoActual);
        $stmtEstadoActual->execute([
            ':nro_tracking' => $tracking_buscado
        ]);

        $estado_actual = $stmtEstadoActual->fetch();

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al obtener el estado actual.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 3. PAQUETES DEL ENVÍO
// -----------------------------------------------------

if ($envio) {

    try {

        $sqlPaquetes = "
            SELECT
                p.nro_tracking,
                p.nro_paquete,
                p.peso_kg,
                p.largo_cm,
                p.ancho_cm,
                p.alto_cm,
                p.fragil,
                p.descripcion,
                tc.nombre AS tipo_contenido,
                cp.nombre AS categoria_paquete
            FROM Paquete p
            INNER JOIN Tipo_Contenido tc
                ON p.cod_tipo_contenido = tc.cod_tipo_contenido
            INNER JOIN Categoria_Paquete cp
                ON p.cod_categoria_paquete = cp.cod_categoria_paquete
            WHERE p.nro_tracking = :nro_tracking
            ORDER BY p.nro_paquete ASC
        ";

        $stmtPaquetes = $pdo->prepare($sqlPaquetes);
        $stmtPaquetes->execute([
            ':nro_tracking' => $tracking_buscado
        ]);

        $paquetes = $stmtPaquetes->fetchAll();

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar los paquetes del envío.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 4. HISTORIAL COMPLETO
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
            ':nro_tracking' => $tracking_buscado
        ]);

        $historial = $stmtHistorial->fetchAll();

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar el historial del envío.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 5. DISPONIBILIDAD DE RETIRO
// -----------------------------------------------------

if ($envio) {

    try {

        $sqlDisponibilidad = "
            SELECT
                dr.nro_tracking,
                dr.cod_sucursal_retiro,
                dr.fecha_disponible,
                dr.fecha_limite_retiro,
                dr.dias_plazo_aplicado,
                dr.fecha_vencimiento_procesado,
                dr.observaciones,
                s.nombre AS nombre_sucursal_retiro
            FROM Disponibilidad_Retiro dr
            INNER JOIN Sucursal s
                ON dr.cod_sucursal_retiro = s.cod_sucursal
            WHERE dr.nro_tracking = :nro_tracking
            LIMIT 1
        ";

        $stmtDisponibilidad = $pdo->prepare($sqlDisponibilidad);
        $stmtDisponibilidad->execute([
            ':nro_tracking' => $tracking_buscado
        ]);

        $disponibilidad = $stmtDisponibilidad->fetch();

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar la disponibilidad de retiro.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 6. RETIRO REGISTRADO
// -----------------------------------------------------

if ($envio) {

    try {

        $sqlRetiro = "
            SELECT
                re.nro_tracking,
                re.fecha_hora_retiro,
                re.cod_sucursal_retiro,
                re.tipo_retirante,
                re.dni_cliente_retirante,
                re.dni_autorizado,
                re.observaciones,
                s.nombre AS nombre_sucursal_retiro,
                ar.nombre AS nombre_autorizado,
                ar.apellido AS apellido_autorizado
            FROM Retiro_Envio re
            INNER JOIN Sucursal s
                ON re.cod_sucursal_retiro = s.cod_sucursal
            LEFT JOIN Autorizado_Retiro ar
                ON re.nro_tracking = ar.nro_tracking
               AND re.dni_autorizado = ar.dni_autorizado
            WHERE re.nro_tracking = :nro_tracking
            LIMIT 1
        ";

        $stmtRetiro = $pdo->prepare($sqlRetiro);
        $stmtRetiro->execute([
            ':nro_tracking' => $tracking_buscado
        ]);

        $retiro = $stmtRetiro->fetch();

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar los datos del retiro.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 7. AUTORIZADOS PARA RETIRO
// -----------------------------------------------------

if ($envio) {

    try {

        $sqlAutorizados = "
            SELECT
                nro_tracking,
                dni_autorizado,
                nombre,
                apellido,
                telefono,
                vinculo,
                fecha_autorizacion
            FROM Autorizado_Retiro
            WHERE nro_tracking = :nro_tracking
            ORDER BY fecha_autorizacion DESC, apellido ASC, nombre ASC
        ";

        $stmtAutorizados = $pdo->prepare($sqlAutorizados);
        $stmtAutorizados->execute([
            ':nro_tracking' => $tracking_buscado
        ]);

        $autorizados = $stmtAutorizados->fetchAll();

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar los autorizados para retiro.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 8. VIAJES ASOCIADOS AL ENVÍO
// -----------------------------------------------------

if ($envio) {

    try {

        $sqlViajes = "
            SELECT
                ve.nro_tracking,
                ve.fecha_asignacion,
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
                so.nombre AS nombre_origen,
                sd.nombre AS nombre_destino,
                ev.nombre AS nombre_estado_viaje
            FROM Viaje_Envio ve
            INNER JOIN Viaje v
                ON ve.patente = v.patente
               AND ve.fecha_salida = v.fecha_salida
            INNER JOIN vista_chofer ch
                ON v.legajo_chofer = ch.legajo
            INNER JOIN Sucursal so
                ON v.cod_sucursal_origen = so.cod_sucursal
            INNER JOIN Sucursal sd
                ON v.cod_sucursal_destino = sd.cod_sucursal
            INNER JOIN Estado_Viaje ev
                ON v.cod_estado_viaje = ev.cod_estado_viaje
            WHERE ve.nro_tracking = :nro_tracking
            ORDER BY ve.fecha_asignacion ASC
        ";

        $stmtViajes = $pdo->prepare($sqlViajes);
        $stmtViajes->execute([
            ':nro_tracking' => $tracking_buscado
        ]);

        $viajes_asociados = $stmtViajes->fetchAll();

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar los viajes asociados.';
        $tipo_mensaje = 'error';
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Buscar Envío por Tracking</h1>
        <p class="page-subtitle">
            Consultá toda la información operativa de un envío a partir de su código de tracking.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'warning' ? 'alert-warning' : ($tipo_mensaje === 'success' ? 'alert-success' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Buscar tracking</h3>

        <form method="GET" action="buscar_tracking.php">

            <div class="dashboard-grid" style="grid-template-columns: 2fr 1fr;">

                <div class="form-group">
                    <label for="tracking">Número de tracking</label>
                    <input
                        type="text"
                        id="tracking"
                        name="tracking"
                        class="form-control"
                        value="<?php echo htmlspecialchars($tracking_buscado); ?>"
                        placeholder="Ej: TRK000001"
                        required
                    >
                </div>

                <div class="form-group" style="display: flex; align-items: flex-end; gap: 12px;">
                    <button type="submit" class="btn-primary" style="width: auto;">
                        Buscar
                    </button>

                    <a href="buscar_tracking.php" class="btn-public-secondary">
                        Limpiar
                    </a>
                </div>

            </div>

        </form>

    </section>


    <?php if ($envio): ?>

        <section class="dashboard-grid" style="margin-bottom: 24px;">

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Datos generales</h3>

                <p><strong>Tracking:</strong> <?php echo htmlspecialchars($envio['nro_tracking']); ?></p>
                <p><strong>Fecha de solicitud/recepción:</strong> <?php echo htmlspecialchars($envio['fecha_recepcion']); ?></p>
                <p><strong>Sucursal origen:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_origen']); ?></p>
                <p><strong>Sucursal destino:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_destino']); ?></p>

                <?php if ($estado_actual): ?>
                    <p><strong>Estado actual:</strong> <?php echo htmlspecialchars($estado_actual['nombre_estado']); ?></p>
                    <p><strong>Último movimiento:</strong> <?php echo htmlspecialchars($estado_actual['nro_movimiento']); ?></p>
                    <p><strong>Última sucursal:</strong> <?php echo htmlspecialchars($estado_actual['nombre_sucursal_actual']); ?></p>
                <?php else: ?>
                    <p><strong>Estado actual:</strong> Sin historial registrado</p>
                <?php endif; ?>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Remitente</h3>

                <p><strong>DNI:</strong> <?php echo htmlspecialchars($envio['dni_remitente']); ?></p>
                <p><strong>Nombre:</strong> <?php echo htmlspecialchars($envio['apellido_remitente'] . ', ' . $envio['nombre_remitente']); ?></p>
                <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($envio['telefono_remitente'] ?? ''); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($envio['email_remitente'] ?? ''); ?></p>
                <p><strong>Dirección:</strong> <?php echo htmlspecialchars($envio['direccion_remitente']); ?></p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Destinatario</h3>

                <p><strong>DNI:</strong> <?php echo htmlspecialchars($envio['dni_destinatario']); ?></p>
                <p><strong>Nombre:</strong> <?php echo htmlspecialchars($envio['apellido_destinatario'] . ', ' . $envio['nombre_destinatario']); ?></p>
                <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($envio['telefono_destinatario'] ?? ''); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($envio['email_destinatario'] ?? ''); ?></p>
                <p><strong>Dirección:</strong> <?php echo htmlspecialchars($envio['direccion_destinatario']); ?></p>
            </article>

        </section>


        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Paquetes del envío</h3>

            <div style="overflow-x: auto;">

                <table style="width: 100%; border-collapse: collapse; min-width: 1250px;">

                    <thead>
                        <tr style="background-color: var(--color-surface-soft);">
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">N° paquete</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Peso</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Largo</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Ancho</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Alto</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Frágil</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tipo contenido</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Categoría</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Descripción</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($paquetes)): ?>

                            <tr>
                                <td colspan="9" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                    No hay paquetes cargados para este envío.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($paquetes as $paquete): ?>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($paquete['nro_paquete']); ?>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($paquete['peso_kg']); ?> kg
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($paquete['largo_cm']); ?> cm
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($paquete['ancho_cm']); ?> cm
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($paquete['alto_cm']); ?> cm
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo ((int) $paquete['fragil'] === 1) ? 'Sí' : 'No'; ?>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($paquete['tipo_contenido']); ?>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($paquete['categoria_paquete']); ?>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border); max-width: 260px;">
                                        <?php echo htmlspecialchars($paquete['descripcion'] ?? ''); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                        <?php endif; ?>
                    </tbody>

                </table>

            </div>

        </section>


        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Historial del envío</h3>

            <?php if (empty($historial)): ?>

                <p style="margin: 0; color: var(--color-muted);">
                    Este envío todavía no tiene movimientos registrados en el historial.
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
                                    <strong>Viaje:</strong>
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


        <section class="dashboard-grid" style="margin-bottom: 24px;">

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Disponibilidad para retiro</h3>

                <?php if ($disponibilidad): ?>
                    <p><strong>Sucursal:</strong> <?php echo htmlspecialchars($disponibilidad['nombre_sucursal_retiro']); ?></p>
                    <p><strong>Fecha disponible:</strong> <?php echo htmlspecialchars($disponibilidad['fecha_disponible']); ?></p>
                    <p><strong>Fecha límite:</strong> <?php echo htmlspecialchars($disponibilidad['fecha_limite_retiro']); ?></p>
                    <p><strong>Días de plazo:</strong> <?php echo htmlspecialchars($disponibilidad['dias_plazo_aplicado']); ?></p>
                    <p><strong>Observaciones:</strong> <?php echo htmlspecialchars($disponibilidad['observaciones'] ?? ''); ?></p>
                <?php else: ?>
                    <p style="color: var(--color-muted); margin: 0;">
                        Este envío todavía no fue marcado como disponible para retiro.
                    </p>
                <?php endif; ?>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Retiro registrado</h3>

                <?php if ($retiro): ?>
                    <p><strong>Fecha y hora:</strong> <?php echo htmlspecialchars($retiro['fecha_hora_retiro']); ?></p>
                    <p><strong>Sucursal:</strong> <?php echo htmlspecialchars($retiro['nombre_sucursal_retiro']); ?></p>
                    <p><strong>Tipo de retirante:</strong> <?php echo htmlspecialchars($retiro['tipo_retirante']); ?></p>

                    <?php if ($retiro['tipo_retirante'] === 'DESTINATARIO'): ?>
                        <p><strong>Retirante:</strong> <?php echo htmlspecialchars($retiro['dni_cliente_retirante'] ?? ''); ?></p>
                    <?php else: ?>
                        <p>
                            <strong>Retirante:</strong>
                            <?php echo htmlspecialchars(($retiro['dni_autorizado'] ?? '') . ' - ' . ($retiro['apellido_autorizado'] ?? '') . ', ' . ($retiro['nombre_autorizado'] ?? '')); ?>
                        </p>
                    <?php endif; ?>

                    <p><strong>Observaciones:</strong> <?php echo htmlspecialchars($retiro['observaciones'] ?? ''); ?></p>
                <?php else: ?>
                    <p style="color: var(--color-muted); margin: 0;">
                        Este envío todavía no tiene retiro registrado.
                    </p>
                <?php endif; ?>
            </article>

        </section>


        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Autorizados para retiro</h3>

            <div style="overflow-x: auto;">

                <table style="width: 100%; border-collapse: collapse; min-width: 1100px;">

                    <thead>
                        <tr style="background-color: var(--color-surface-soft);">
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">DNI</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Nombre</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Teléfono</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Vínculo</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha autorización</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($autorizados)): ?>

                            <tr>
                                <td colspan="5" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                    No hay personas autorizadas registradas para este envío.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($autorizados as $autorizado): ?>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($autorizado['dni_autorizado']); ?>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($autorizado['apellido'] . ', ' . $autorizado['nombre']); ?>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($autorizado['telefono'] ?? ''); ?>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($autorizado['vinculo'] ?? ''); ?>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($autorizado['fecha_autorizacion']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                        <?php endif; ?>
                    </tbody>

                </table>

            </div>

        </section>


        <section class="dashboard-card">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Viajes asociados al envío</h3>

            <div style="overflow-x: auto;">

                <table style="width: 100%; border-collapse: collapse; min-width: 1300px;">

                    <thead>
                        <tr style="background-color: var(--color-surface-soft);">
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha asignación</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Patente</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha salida</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Llegada estimada</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Llegada real</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Chofer</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Trayecto</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Estado</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($viajes_asociados)): ?>

                            <tr>
                                <td colspan="8" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                    Este envío todavía no fue asignado a ningún viaje.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($viajes_asociados as $viaje): ?>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($viaje['fecha_asignacion']); ?>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($viaje['patente']); ?>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($viaje['fecha_salida']); ?>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($viaje['fecha_llegada_estimada']); ?>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($viaje['fecha_llegada_real'] ?? ''); ?>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($viaje['legajo_chofer'] . ' - ' . $viaje['apellido_chofer'] . ', ' . $viaje['nombre_chofer']); ?>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($viaje['nombre_origen'] . ' → ' . $viaje['nombre_destino']); ?>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($viaje['nombre_estado_viaje']); ?>
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