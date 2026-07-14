<?php
// =====================================================
// datos_retiro.php
// Datos de retiro y constancia de retiro real
// - acceso para CLIENTE y ADMIN
// - CLIENTE: puede ver si es remitente o destinatario
// - ADMIN: puede ver cualquier envío
// - NO muestra lista de autorizados
// - muestra disponibilidad para retiro
// - si ya fue retirado, muestra quién lo retiró realmente
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'CLIENTE']);

$titulo_pagina = 'Datos de Retiro';

$mensaje = '';
$tipo_mensaje = '';

$rol_actual = $_SESSION['usuario_rol'] ?? '';
$tracking = trim($_GET['tracking'] ?? '');

$dni_cliente_sesion = '';
$cliente_actual = null;

$envio = null;
$disponibilidad_retiro = null;
$retiro_realizado = null;


// -----------------------------------------------------
// FUNCIONES AUXILIARES
// -----------------------------------------------------

function obtenerDniClienteSesionDatosRetiro(): string
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

    $dni_cliente_sesion = obtenerDniClienteSesionDatosRetiro();

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
    $mensaje = 'No se recibió un tracking válido para consultar los datos de retiro.';
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
                cr.telefono AS telefono_remitente,
                cr.email AS email_remitente,

                cd.nombre AS nombre_destinatario,
                cd.apellido AS apellido_destinatario,
                cd.telefono AS telefono_destinatario,
                cd.email AS email_destinatario,

                so.nombre AS nombre_sucursal_origen,
                sd.nombre AS nombre_sucursal_destino,

                he.cod_estado_envio AS cod_estado_actual,
                ee.nombre AS nombre_estado_actual,
                he.fecha_hora AS fecha_estado_actual
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
                    h1.fecha_hora
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
            $mensaje = 'No tenés permiso para ver los datos de retiro de ese envío.';
            $tipo_mensaje = 'error';
        }

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar los datos del envío.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 4. CARGAR DISPONIBILIDAD DE RETIRO
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
                s.direccion AS direccion_sucursal_retiro,
                s.telefono AS telefono_sucursal_retiro
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
// 5. CARGAR CONSTANCIA DE RETIRO REAL
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
                s.telefono AS telefono_sucursal_retiro,

                c.nombre AS nombre_cliente_retirante,
                c.apellido AS apellido_cliente_retirante,

                ar.nombre AS nombre_autorizado,
                ar.apellido AS apellido_autorizado,
                ar.telefono AS telefono_autorizado,
                ar.vinculo AS vinculo_autorizado
            FROM Retiro_Envio re
            INNER JOIN Sucursal s
                ON re.cod_sucursal_retiro = s.cod_sucursal

            LEFT JOIN vista_cliente c
                ON re.dni_cliente_retirante = c.dni

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

        $mensaje = 'Ocurrió un error al cargar la constancia de retiro.';
        $tipo_mensaje = 'error';
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Datos de Retiro</h1>
        <p class="page-subtitle">
            Consultá la información de retiro del envío y, si corresponde, la constancia de quién lo retiró.
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
                <p><strong>Fecha:</strong> <?php echo htmlspecialchars($envio['fecha_recepcion']); ?></p>
                <p><strong>Remitente:</strong> <?php echo htmlspecialchars($envio['apellido_remitente'] . ', ' . $envio['nombre_remitente']); ?></p>
                <p><strong>Destinatario:</strong> <?php echo htmlspecialchars($envio['apellido_destinatario'] . ', ' . $envio['nombre_destinatario']); ?></p>
                <p><strong>Origen:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_origen']); ?></p>
                <p><strong>Destino:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_destino']); ?></p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Estado actual</h3>

                <p><strong>Estado:</strong> <?php echo htmlspecialchars($envio['nombre_estado_actual'] ?? 'Sin historial'); ?></p>
                <p><strong>Fecha estado:</strong> <?php echo htmlspecialchars($envio['fecha_estado_actual'] ?? ''); ?></p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Acciones rápidas</h3>

                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="detalle_envio.php?tracking=<?php echo urlencode($envio['nro_tracking']); ?>" class="btn-public-secondary">
                        Ver detalle
                    </a>

                    <a href="historial_envio.php?tracking=<?php echo urlencode($envio['nro_tracking']); ?>" class="btn-public-secondary">
                        Ver historial
                    </a>
                </div>
            </article>

        </section>


        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Información para el retiro</h3>

            <?php if ($disponibilidad_retiro): ?>

                <div class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">

                    <article>
                        <p><strong>Sucursal de retiro:</strong> <?php echo htmlspecialchars($disponibilidad_retiro['nombre_sucursal_retiro']); ?></p>
                        <p><strong>Dirección:</strong> <?php echo htmlspecialchars($disponibilidad_retiro['direccion_sucursal_retiro']); ?></p>
                        <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($disponibilidad_retiro['telefono_sucursal_retiro'] ?? ''); ?></p>
                    </article>

                    <article>
                        <p><strong>Fecha disponible:</strong> <?php echo htmlspecialchars($disponibilidad_retiro['fecha_disponible']); ?></p>
                        <p><strong>Fecha límite:</strong> <?php echo htmlspecialchars($disponibilidad_retiro['fecha_limite_retiro']); ?></p>
                        <p><strong>Plazo aplicado:</strong> <?php echo htmlspecialchars((string) $disponibilidad_retiro['dias_plazo_aplicado']); ?> días</p>
                        <p><strong>Observaciones:</strong> <?php echo htmlspecialchars($disponibilidad_retiro['observaciones'] ?? ''); ?></p>
                    </article>

                </div>

            <?php else: ?>

                <p style="margin: 0; color: var(--color-muted);">
                    Este envío todavía no está disponible para retiro.
                </p>

            <?php endif; ?>

        </section>


        <section class="dashboard-card">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Constancia de retiro</h3>

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

                <div class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">

                    <article>
                        <p><strong>Fecha y hora de retiro:</strong> <?php echo htmlspecialchars($retiro_realizado['fecha_hora_retiro']); ?></p>
                        <p><strong>Sucursal:</strong> <?php echo htmlspecialchars($retiro_realizado['nombre_sucursal_retiro']); ?></p>
                        <p><strong>Dirección:</strong> <?php echo htmlspecialchars($retiro_realizado['direccion_sucursal_retiro']); ?></p>
                        <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($retiro_realizado['telefono_sucursal_retiro'] ?? ''); ?></p>
                    </article>

                    <article>
                        <?php if ($retiro_realizado['tipo_retirante'] === 'AUTORIZADO'): ?>

                            <p><strong>Tipo de retirante:</strong> AUTORIZADO</p>
                            <p><strong>DNI autorizado:</strong> <?php echo htmlspecialchars($retiro_realizado['dni_autorizado'] ?? ''); ?></p>
                            <p>
                                <strong>Nombre autorizado:</strong>
                                <?php echo htmlspecialchars(($retiro_realizado['apellido_autorizado'] ?? '') . ', ' . ($retiro_realizado['nombre_autorizado'] ?? '')); ?>
                            </p>
                            <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($retiro_realizado['telefono_autorizado'] ?? ''); ?></p>
                            <p><strong>Vínculo:</strong> <?php echo htmlspecialchars($retiro_realizado['vinculo_autorizado'] ?? ''); ?></p>

                        <?php elseif ($es_remitente_real): ?>

                            <p><strong>Tipo de retirante:</strong> REMITENTE</p>
                            <p><strong>DNI remitente:</strong> <?php echo htmlspecialchars($envio['dni_remitente']); ?></p>
                            <p><strong>Nombre retirante:</strong> <?php echo htmlspecialchars($envio['apellido_remitente'] . ', ' . $envio['nombre_remitente']); ?></p>

                        <?php elseif ($es_destinatario_real): ?>

                            <p><strong>Tipo de retirante:</strong> DESTINATARIO</p>
                            <p><strong>DNI retirante:</strong> <?php echo htmlspecialchars($retiro_realizado['dni_cliente_retirante'] ?? ''); ?></p>
                            <p>
                                <strong>Nombre retirante:</strong>
                                <?php echo htmlspecialchars(($retiro_realizado['apellido_cliente_retirante'] ?? '') . ', ' . ($retiro_realizado['nombre_cliente_retirante'] ?? '')); ?>
                            </p>

                        <?php else: ?>

                            <p><strong>Tipo de retirante:</strong> CLIENTE</p>
                            <p><strong>DNI retirante:</strong> <?php echo htmlspecialchars($retiro_realizado['dni_cliente_retirante'] ?? ''); ?></p>

                        <?php endif; ?>

                        <p><strong>Observaciones:</strong> <?php echo htmlspecialchars($retiro_realizado['observaciones'] ?? ''); ?></p>
                    </article>

                </div>

            <?php else: ?>

                <p style="margin: 0; color: var(--color-muted);">
                    Todavía no hay constancia de retiro porque el envío aún no fue retirado.
                </p>

            <?php endif; ?>

        </section>

    <?php endif; ?>

</main>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>