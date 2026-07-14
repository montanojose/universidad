<?php
// =====================================================
// detalle_envio.php
// Detalle completo de un envío
// - acceso para CLIENTE y ADMIN
// - CLIENTE: puede ver un envío si es remitente o destinatario
// - ADMIN: puede ver cualquier envío
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'CLIENTE']);

$titulo_pagina = 'Detalle del Envío';

$mensaje = '';
$tipo_mensaje = '';

$rol_actual = $_SESSION['usuario_rol'] ?? '';
$tracking = trim($_GET['tracking'] ?? '');

$dni_cliente_sesion = '';
$cliente_actual = null;

$envio = null;
$paquetes = [];
$historial_reciente = [];
$autorizados = [];
$disponibilidad_retiro = null;
$retiro_realizado = null;


// -----------------------------------------------------
// FUNCIONES AUXILIARES
// -----------------------------------------------------

function obtenerDniClienteSesionDetalleEnvio(): string
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

    $dni_cliente_sesion = obtenerDniClienteSesionDetalleEnvio();

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
    $mensaje = 'No se recibió un tracking válido para consultar el detalle.';
    $tipo_mensaje = 'warning';
}


// -----------------------------------------------------
// 3. CARGAR DATOS GENERALES DEL ENVÍO
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
                cr.telefono AS telefono_remitente,
                cr.email AS email_remitente,
                cr.direccion AS direccion_remitente,

                cd.nombre AS nombre_destinatario,
                cd.apellido AS apellido_destinatario,
                cd.telefono AS telefono_destinatario,
                cd.email AS email_destinatario,
                cd.direccion AS direccion_destinatario,

                so.nombre AS nombre_sucursal_origen,
                so.direccion AS direccion_sucursal_origen,
                sd.nombre AS nombre_sucursal_destino,
                sd.direccion AS direccion_sucursal_destino,

                he.cod_estado_envio AS cod_estado_actual,
                ee.nombre AS nombre_estado_actual,
                he.fecha_hora AS fecha_estado_actual,
                hs.nombre AS nombre_sucursal_estado_actual,
                he.observaciones AS observaciones_estado_actual,

                COALESCE(pkg.cantidad_paquetes, 0) AS cantidad_paquetes,
                COALESCE(pkg.peso_total_kg, 0) AS peso_total_kg
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
                    h1.cod_sucursal_actual,
                    h1.observaciones
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

            LEFT JOIN (
                SELECT
                    nro_tracking,
                    COUNT(*) AS cantidad_paquetes,
                    COALESCE(SUM(peso_kg), 0) AS peso_total_kg
                FROM Paquete
                GROUP BY nro_tracking
            ) pkg
                ON e.nro_tracking = pkg.nro_tracking

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
            $mensaje = 'No tenés permiso para ver el detalle de ese envío.';
            $tipo_mensaje = 'error';
        }

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar el detalle del envío.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 4. CARGAR PAQUETES
// -----------------------------------------------------

if ($envio) {

    try {

        $sqlPaquetes = "
            SELECT
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
            ':nro_tracking' => $tracking
        ]);

        $paquetes = $stmtPaquetes->fetchAll();

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar los paquetes del envío.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 5. CARGAR DISPONIBILIDAD DE RETIRO
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
                s.nombre AS nombre_sucursal_retiro,
                s.direccion AS direccion_sucursal_retiro
            FROM Disponibilidad_Retiro dr
            INNER JOIN Sucursal s
                ON dr.cod_sucursal_retiro = s.cod_sucursal
            WHERE dr.nro_tracking = :nro_tracking
            LIMIT 1
        ";

        $stmtDisponibilidad = $pdo->prepare($sqlDisponibilidad);
        $stmtDisponibilidad->execute([
            ':nro_tracking' => $tracking
        ]);

        $disponibilidad_retiro = $stmtDisponibilidad->fetch();

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar la disponibilidad de retiro.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 6. CARGAR RETIRO REALIZADO
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
                s.direccion AS direccion_sucursal_retiro,

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
            ':nro_tracking' => $tracking
        ]);

        $retiro_realizado = $stmtRetiro->fetch();

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar el retiro registrado.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 7. CARGAR AUTORIZADOS
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
            ':nro_tracking' => $tracking
        ]);

        $autorizados = $stmtAutorizados->fetchAll();

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar los autorizados del envío.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 8. CARGAR HISTORIAL RECIENTE
// -----------------------------------------------------

if ($envio) {

    try {

        $sqlHistorial = "
            SELECT
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
            LIMIT 10
        ";

        $stmtHistorial = $pdo->prepare($sqlHistorial);
        $stmtHistorial->execute([
            ':nro_tracking' => $tracking
        ]);

        $historial_reciente = $stmtHistorial->fetchAll();

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar el historial reciente del envío.';
        $tipo_mensaje = 'error';
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Detalle del Envío</h1>
        <p class="page-subtitle">
            Consultá toda la información relevante del envío seleccionado.
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
                <h3 style="margin-top: 0;">Datos generales</h3>

                <p><strong>Tracking:</strong> <?php echo htmlspecialchars($envio['nro_tracking']); ?></p>
                <p><strong>Fecha de solicitud/recepción:</strong> <?php echo htmlspecialchars($envio['fecha_recepcion']); ?></p>
                <p><strong>Origen:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_origen']); ?></p>
                <p><strong>Destino:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_destino']); ?></p>
                <p><strong>Paquetes:</strong> <?php echo htmlspecialchars((string) $envio['cantidad_paquetes']); ?></p>
                <p><strong>Peso total:</strong> <?php echo htmlspecialchars(number_format((float) $envio['peso_total_kg'], 2, '.', '')); ?> kg</p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Estado actual</h3>

                <p><strong>Estado:</strong> <?php echo htmlspecialchars($envio['nombre_estado_actual'] ?? 'Sin historial'); ?></p>
                <p><strong>Fecha del estado:</strong> <?php echo htmlspecialchars($envio['fecha_estado_actual'] ?? ''); ?></p>
                <p><strong>Sucursal actual:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_estado_actual'] ?? ''); ?></p>
                <p><strong>Observaciones:</strong> <?php echo htmlspecialchars($envio['observaciones_estado_actual'] ?? ''); ?></p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Acciones rápidas</h3>

                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="historial_envio.php?tracking=<?php echo urlencode($envio['nro_tracking']); ?>" class="btn-public-secondary">
                        Ver historial
                    </a>

                    <?php if ($disponibilidad_retiro): ?>
                        <a href="datos_retiro.php?tracking=<?php echo urlencode($envio['nro_tracking']); ?>" class="btn-public-secondary">
                            Datos retiro
                        </a>
                    <?php endif; ?>
                </div>
            </article>

        </section>


        <section class="dashboard-grid" style="margin-bottom: 24px;">

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

                <table style="width: 100%; border-collapse: collapse; min-width: 1300px;">

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


        <section class="dashboard-grid" style="margin-bottom: 24px;">

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Disponibilidad para retiro</h3>

                <?php if ($disponibilidad_retiro): ?>
                    <p><strong>Sucursal:</strong> <?php echo htmlspecialchars($disponibilidad_retiro['nombre_sucursal_retiro']); ?></p>
                    <p><strong>Dirección:</strong> <?php echo htmlspecialchars($disponibilidad_retiro['direccion_sucursal_retiro']); ?></p>
                    <p><strong>Fecha disponible:</strong> <?php echo htmlspecialchars($disponibilidad_retiro['fecha_disponible']); ?></p>
                    <p><strong>Fecha límite:</strong> <?php echo htmlspecialchars($disponibilidad_retiro['fecha_limite_retiro']); ?></p>
                    <p><strong>Plazo aplicado:</strong> <?php echo htmlspecialchars((string) $disponibilidad_retiro['dias_plazo_aplicado']); ?> días</p>
                    <p><strong>Observaciones:</strong> <?php echo htmlspecialchars($disponibilidad_retiro['observaciones'] ?? ''); ?></p>
                <?php else: ?>
                    <p style="margin: 0; color: var(--color-muted);">
                        Este envío todavía no fue marcado como disponible para retiro.
                    </p>
                <?php endif; ?>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Retiro realizado</h3>

                <?php if ($retiro_realizado): ?>

                    <?php
                    $es_remitente_real = (
                        !empty($retiro_realizado['dni_cliente_retirante']) &&
                        $retiro_realizado['dni_cliente_retirante'] === $envio['dni_remitente']
                    );

                    $es_destinatario_real = (
                        !empty($retiro_realizado['dni_cliente_retirante']) &&
                        $retiro_realizado['dni_cliente_retirante'] === $envio['dni_destinatario']
                    );
                    ?>

                    <p><strong>Fecha y hora:</strong> <?php echo htmlspecialchars($retiro_realizado['fecha_hora_retiro']); ?></p>
                    <p><strong>Sucursal:</strong> <?php echo htmlspecialchars($retiro_realizado['nombre_sucursal_retiro']); ?></p>
                    <p><strong>Dirección:</strong> <?php echo htmlspecialchars($retiro_realizado['direccion_sucursal_retiro']); ?></p>

                    <?php if ($retiro_realizado['tipo_retirante'] === 'AUTORIZADO'): ?>

                        <p><strong>Tipo de retirante:</strong> AUTORIZADO</p>
                        <p>
                            <strong>Autorizado:</strong>
                            <?php echo htmlspecialchars(($retiro_realizado['dni_autorizado'] ?? '') . ' - ' . ($retiro_realizado['apellido_autorizado'] ?? '') . ', ' . ($retiro_realizado['nombre_autorizado'] ?? '')); ?>
                        </p>

                    <?php elseif ($es_remitente_real): ?>

                        <p><strong>Tipo de retirante:</strong> REMITENTE</p>
                        <p><strong>DNI retirante:</strong> <?php echo htmlspecialchars($envio['dni_remitente']); ?></p>
                        <p><strong>Nombre retirante:</strong> <?php echo htmlspecialchars($envio['apellido_remitente'] . ', ' . $envio['nombre_remitente']); ?></p>

                    <?php elseif ($es_destinatario_real): ?>

                        <p><strong>Tipo de retirante:</strong> DESTINATARIO</p>
                        <p><strong>DNI retirante:</strong> <?php echo htmlspecialchars($retiro_realizado['dni_cliente_retirante'] ?? ''); ?></p>

                    <?php else: ?>

                        <p><strong>Tipo de retirante:</strong> CLIENTE</p>
                        <p><strong>DNI retirante:</strong> <?php echo htmlspecialchars($retiro_realizado['dni_cliente_retirante'] ?? ''); ?></p>

                    <?php endif; ?>

                    <p><strong>Observaciones:</strong> <?php echo htmlspecialchars($retiro_realizado['observaciones'] ?? ''); ?></p>
                <?php else: ?>
                    <p style="margin: 0; color: var(--color-muted);">
                        Este envío todavía no fue retirado.
                    </p>
                <?php endif; ?>
            </article>

        </section>


        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Personas autorizadas para retiro</h3>

            <div style="overflow-x: auto;">

                <table style="width: 100%; border-collapse: collapse; min-width: 1050px;">

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

            <h3 style="margin-top: 0; margin-bottom: 18px;">Últimos movimientos del envío</h3>

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
                        <?php if (empty($historial_reciente)): ?>

                            <tr>
                                <td colspan="7" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                    Este envío todavía no tiene movimientos registrados.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($historial_reciente as $movimiento): ?>
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