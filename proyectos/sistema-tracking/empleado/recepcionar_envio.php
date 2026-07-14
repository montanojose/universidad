<?php
// =====================================================
// recepcionar_envio.php
// Recepción física de un envío en sucursal de origen
// - acceso para EMPLEADO_SUCURSAL y ADMIN
// - valida paquetes cargados
// - valida sucursal de origen del empleado
// - registra movimiento RECIBIDO_EN_SUCURSAL
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'EMPLEADO_SUCURSAL']);

$titulo_pagina = 'Recepcionar Envío';

$mensaje = '';
$tipo_mensaje = '';

$rol_actual = $_SESSION['usuario_rol'] ?? '';

$tracking_buscado = trim($_GET['tracking'] ?? '');
$fecha_hora_recepcion = date('Y-m-d H:i:s');
$observaciones = '';

$cod_sucursal_empleado = '';
$nombre_sucursal_empleado = '';

$envio = null;
$paquetes = [];
$pendientes_recepcion = [];

$puede_recepcionar = false;
$motivo_no_recepcionar = '';


// -----------------------------------------------------
// FUNCIONES AUXILIARES
// -----------------------------------------------------

function obtenerLegajoEmpleadoSesionRecepcion(): string
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

function normalizarFechaDatetimeLocalRecepcion(string $valor): string
{
    $valor = trim($valor);

    if ($valor === '') {
        return '';
    }

    $valor = str_replace('T', ' ', $valor);

    if (strlen($valor) === 16) {
        $valor .= ':00';
    }

    return $valor;
}

function fechaRecepcionParaInput(?string $fecha): string
{
    if (!$fecha) {
        return '';
    }

    return date('Y-m-d\TH:i', strtotime($fecha));
}

function obtenerEstadoRecepcion(PDO $pdo): ?string
{
    $posibles_estados = [
        'RECIBIDO_EN_SUCURSAL',
        'RECIBIDO'
    ];

    foreach ($posibles_estados as $estado) {

        $sql = "
            SELECT cod_estado_envio
            FROM Estado_Envio
            WHERE cod_estado_envio = :estado
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':estado' => $estado
        ]);

        $fila = $stmt->fetch();

        if ($fila) {
            return $fila['cod_estado_envio'];
        }
    }

    return null;
}


// -----------------------------------------------------
// 1. OBTENER SUCURSAL DEL EMPLEADO SI CORRESPONDE
// -----------------------------------------------------

if ($rol_actual === 'EMPLEADO_SUCURSAL') {

    $legajo_empleado_sesion = obtenerLegajoEmpleadoSesionRecepcion();

    if ($legajo_empleado_sesion === '') {

        $mensaje = 'No se pudo identificar el legajo del empleado en la sesión.';
        $tipo_mensaje = 'error';

    } else {

        try {

            $sqlEmpleado = "
                SELECT
                    e.cod_sucursal,
                    s.nombre AS nombre_sucursal
                FROM vista_empleado_sucursal e
                INNER JOIN Sucursal s
                    ON e.cod_sucursal = s.cod_sucursal
                WHERE e.legajo_empleado = :legajo_empleado
                LIMIT 1
            ";

            $stmtEmpleado = $pdo->prepare($sqlEmpleado);
            $stmtEmpleado->execute([
                ':legajo_empleado' => $legajo_empleado_sesion
            ]);

            $empleado = $stmtEmpleado->fetch();

            if ($empleado) {
                $cod_sucursal_empleado = $empleado['cod_sucursal'];
                $nombre_sucursal_empleado = $empleado['nombre_sucursal'];
            } else {
                $mensaje = 'No se encontró el empleado asociado a la sesión actual.';
                $tipo_mensaje = 'error';
            }

        } catch (PDOException $e) {

            $mensaje = 'Ocurrió un error al obtener la sucursal del empleado.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 2. PROCESAR RECEPCIÓN
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'recepcionar') {

    $tracking_buscado = trim($_POST['tracking'] ?? '');
    $fecha_hora_recepcion = normalizarFechaDatetimeLocalRecepcion($_POST['fecha_hora_recepcion'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');

    if ($tracking_buscado === '' || $fecha_hora_recepcion === '') {

        $mensaje = 'Completá los datos obligatorios para registrar la recepción.';
        $tipo_mensaje = 'error';

    } else {

        try {

            $sqlEnvioRecepcion = "
                SELECT
                    e.nro_tracking,
                    e.fecha_recepcion,
                    e.dni_remitente,
                    e.dni_destinatario,
                    e.cod_sucursal_origen,
                    e.cod_sucursal_destino,
                    so.nombre AS nombre_sucursal_origen,
                    sd.nombre AS nombre_sucursal_destino,

                    he.cod_estado_envio AS cod_estado_actual,
                    ee.nombre AS nombre_estado_actual,
                    he.nro_movimiento AS nro_movimiento_actual,
                    he.fecha_hora AS fecha_estado_actual,

                    COALESCE(pkg.cantidad_paquetes, 0) AS cantidad_paquetes,
                    COALESCE(pkg.peso_total, 0) AS peso_total
                FROM Envio e
                INNER JOIN Sucursal so
                    ON e.cod_sucursal_origen = so.cod_sucursal
                INNER JOIN Sucursal sd
                    ON e.cod_sucursal_destino = sd.cod_sucursal

                LEFT JOIN (
                    SELECT h1.nro_tracking, h1.cod_estado_envio, h1.nro_movimiento, h1.fecha_hora
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

                LEFT JOIN (
                    SELECT nro_tracking, COUNT(*) AS cantidad_paquetes, SUM(peso_kg) AS peso_total
                    FROM Paquete
                    GROUP BY nro_tracking
                ) pkg
                    ON e.nro_tracking = pkg.nro_tracking

                WHERE e.nro_tracking = :nro_tracking
                LIMIT 1
            ";

            $stmtEnvioRecepcion = $pdo->prepare($sqlEnvioRecepcion);
            $stmtEnvioRecepcion->execute([
                ':nro_tracking' => $tracking_buscado
            ]);

            $envioRecepcion = $stmtEnvioRecepcion->fetch();

            if (!$envioRecepcion) {

                $mensaje = 'No se encontró el envío indicado.';
                $tipo_mensaje = 'error';

            } elseif ($rol_actual === 'EMPLEADO_SUCURSAL' && $envioRecepcion['cod_sucursal_origen'] !== $cod_sucursal_empleado) {

                $mensaje = 'No podés recepcionar este envío porque pertenece a otra sucursal de origen.';
                $tipo_mensaje = 'error';

            } elseif ((int) $envioRecepcion['cantidad_paquetes'] === 0) {

                $mensaje = 'No se puede recepcionar el envío porque todavía no tiene paquetes cargados.';
                $tipo_mensaje = 'error';

            } else {

                $estado_actual = $envioRecepcion['cod_estado_actual'] ?? null;

                if ($estado_actual !== null && $estado_actual !== '' && $estado_actual !== 'SOLICITUD_CREADA') {

                    $mensaje = 'Este envío ya fue recepcionado o ya avanzó a una etapa posterior del circuito.';
                    $tipo_mensaje = 'warning';

                } else {

                    $estado_recepcion = obtenerEstadoRecepcion($pdo);

                    if ($estado_recepcion === null) {

                        $mensaje = 'No existe un estado válido para registrar la recepción en sucursal.';
                        $tipo_mensaje = 'error';

                    } else {

                        $pdo->beginTransaction();

                        $sqlInsertarHistorial = "
                            CALL sp_registrar_movimiento_envio(
                                :nro_tracking,
                                :cod_estado_envio,
                                :fecha_hora,
                                :cod_sucursal_actual,
                                NULL,
                                NULL,
                                :observaciones
                            )
                        ";

                        $stmtInsertarHistorial = $pdo->prepare($sqlInsertarHistorial);

                        $stmtInsertarHistorial->execute([
                            ':nro_tracking' => $tracking_buscado,
                            ':cod_estado_envio' => $estado_recepcion,
                            ':fecha_hora' => $fecha_hora_recepcion,
                            ':cod_sucursal_actual' => $envioRecepcion['cod_sucursal_origen'],
                            ':observaciones' => ($observaciones !== '' ? $observaciones : 'Recepción física del envío en sucursal de origen')
                        ]);
                        $stmtInsertarHistorial->closeCursor();

                        $pdo->commit();

                        $mensaje = 'Recepción registrada correctamente.';
                        $tipo_mensaje = 'success';
                    }
                }
            }

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $mensaje = 'Ocurrió un error al registrar la recepción del envío.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 3. CARGAR DETALLE DEL TRACKING BUSCADO
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
                cd.nombre AS nombre_destinatario,
                cd.apellido AS apellido_destinatario,

                so.nombre AS nombre_sucursal_origen,
                sd.nombre AS nombre_sucursal_destino,

                he.cod_estado_envio AS cod_estado_actual,
                ee.nombre AS nombre_estado_actual,
                he.nro_movimiento AS nro_movimiento_actual,
                he.fecha_hora AS fecha_estado_actual,

                COALESCE(pkg.cantidad_paquetes, 0) AS cantidad_paquetes,
                COALESCE(pkg.peso_total, 0) AS peso_total
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
                SELECT h1.nro_tracking, h1.cod_estado_envio, h1.nro_movimiento, h1.fecha_hora
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

            LEFT JOIN (
                SELECT nro_tracking, COUNT(*) AS cantidad_paquetes, SUM(peso_kg) AS peso_total
                FROM Paquete
                GROUP BY nro_tracking
            ) pkg
                ON e.nro_tracking = pkg.nro_tracking

            WHERE e.nro_tracking = :nro_tracking
            LIMIT 1
        ";

        $stmtEnvio = $pdo->prepare($sqlEnvio);
        $stmtEnvio->execute([
            ':nro_tracking' => $tracking_buscado
        ]);

        $envio = $stmtEnvio->fetch();

        if ($envio) {

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
                ':nro_tracking' => $tracking_buscado
            ]);

            $paquetes = $stmtPaquetes->fetchAll();


            $puede_recepcionar = true;
            $motivo_no_recepcionar = '';

            if ($rol_actual === 'EMPLEADO_SUCURSAL' && $envio['cod_sucursal_origen'] !== $cod_sucursal_empleado) {
                $puede_recepcionar = false;
                $motivo_no_recepcionar = 'Este envío pertenece a otra sucursal de origen.';
            } elseif ((int) $envio['cantidad_paquetes'] === 0) {
                $puede_recepcionar = false;
                $motivo_no_recepcionar = 'Todavía no tiene paquetes cargados.';
            } elseif (!empty($envio['cod_estado_actual']) && $envio['cod_estado_actual'] !== 'SOLICITUD_CREADA') {
                $puede_recepcionar = false;
                $motivo_no_recepcionar = 'El envío ya fue recepcionado o ya avanzó a una etapa posterior.';
            }

        } else {
            if ($mensaje === '') {
                $mensaje = 'No se encontró ningún envío con ese tracking.';
                $tipo_mensaje = 'warning';
            }
        }

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar el detalle del envío.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 4. CARGAR PENDIENTES DE RECEPCIÓN
// -----------------------------------------------------

try {

    $sqlPendientes = "
        SELECT
            e.nro_tracking,
            e.fecha_recepcion,
            e.cod_sucursal_origen,
            so.nombre AS nombre_sucursal_origen,
            sd.nombre AS nombre_sucursal_destino,
            cd.nombre AS nombre_destinatario,
            cd.apellido AS apellido_destinatario,

            he.cod_estado_envio AS cod_estado_actual,
            ee.nombre AS nombre_estado_actual,

            COALESCE(pkg.cantidad_paquetes, 0) AS cantidad_paquetes
        FROM Envio e
        INNER JOIN Sucursal so
            ON e.cod_sucursal_origen = so.cod_sucursal
        INNER JOIN Sucursal sd
            ON e.cod_sucursal_destino = sd.cod_sucursal
        INNER JOIN vista_cliente cd
            ON e.dni_destinatario = cd.dni

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

        LEFT JOIN (
            SELECT nro_tracking, COUNT(*) AS cantidad_paquetes
            FROM Paquete
            GROUP BY nro_tracking
        ) pkg
            ON e.nro_tracking = pkg.nro_tracking

        WHERE 1 = 1
    ";

    $params_pendientes = [];

    if ($rol_actual === 'EMPLEADO_SUCURSAL' && $cod_sucursal_empleado !== '') {
        $sqlPendientes .= " AND e.cod_sucursal_origen = :cod_sucursal_origen ";
        $params_pendientes[':cod_sucursal_origen'] = $cod_sucursal_empleado;
    }

    $sqlPendientes .= "
        AND (
            he.cod_estado_envio IS NULL
            OR he.cod_estado_envio = 'SOLICITUD_CREADA'
        )
        ORDER BY e.fecha_recepcion DESC, e.nro_tracking DESC
        LIMIT 20
    ";

    $stmtPendientes = $pdo->prepare($sqlPendientes);
    $stmtPendientes->execute($params_pendientes);

    $pendientes_recepcion = $stmtPendientes->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'Ocurrió un error al cargar los envíos pendientes de recepción.';
    $tipo_mensaje = 'error';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Recepcionar Envío</h1>
        <p class="page-subtitle">
            Confirmá la recepción física del envío en la sucursal de origen para ingresarlo al circuito operativo.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Buscar tracking a recepcionar</h3>

        <?php if ($rol_actual === 'EMPLEADO_SUCURSAL' && $cod_sucursal_empleado !== ''): ?>
            <p class="field-note" style="margin-bottom: 14px;">
                Estás operando como sucursal:
                <strong><?php echo htmlspecialchars($cod_sucursal_empleado . ' - ' . $nombre_sucursal_empleado); ?></strong>
            </p>
        <?php endif; ?>

        <form method="GET" action="recepcionar_envio.php">

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

                    <a href="recepcionar_envio.php" class="btn-public-secondary">
                        Limpiar
                    </a>
                </div>

            </div>

        </form>

    </section>


    <?php if ($envio): ?>

        <section class="dashboard-grid" style="margin-bottom: 24px;">

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Datos del envío</h3>

                <p><strong>Tracking:</strong> <?php echo htmlspecialchars($envio['nro_tracking']); ?></p>
                <p><strong>Fecha solicitud:</strong> <?php echo htmlspecialchars($envio['fecha_recepcion']); ?></p>
                <p><strong>Origen:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_origen']); ?></p>
                <p><strong>Destino:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_destino']); ?></p>
                <p><strong>Estado actual:</strong> <?php echo htmlspecialchars($envio['nombre_estado_actual'] ?? 'Sin historial'); ?></p>
                <p><strong>Paquetes cargados:</strong> <?php echo htmlspecialchars((string) $envio['cantidad_paquetes']); ?></p>
                <p><strong>Peso total declarado:</strong> <?php echo htmlspecialchars((string) $envio['peso_total']); ?> kg</p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Personas</h3>

                <p>
                    <strong>Remitente:</strong>
                    <?php echo htmlspecialchars($envio['dni_remitente'] . ' - ' . $envio['apellido_remitente'] . ', ' . $envio['nombre_remitente']); ?>
                </p>

                <p>
                    <strong>Destinatario:</strong>
                    <?php echo htmlspecialchars($envio['dni_destinatario'] . ' - ' . $envio['apellido_destinatario'] . ', ' . $envio['nombre_destinatario']); ?>
                </p>
            </article>

        </section>


        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Paquetes declarados</h3>

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
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($paquete['nro_paquete']); ?></td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($paquete['peso_kg']); ?> kg</td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($paquete['largo_cm']); ?> cm</td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($paquete['ancho_cm']); ?> cm</td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($paquete['alto_cm']); ?> cm</td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);"><?php echo ((int) $paquete['fragil'] === 1) ? 'Sí' : 'No'; ?></td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($paquete['tipo_contenido']); ?></td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($paquete['categoria_paquete']); ?></td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border); max-width: 220px;"><?php echo htmlspecialchars($paquete['descripcion'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>

                        <?php endif; ?>
                    </tbody>

                </table>

            </div>

        </section>


        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Registrar recepción física</h3>

            <?php if ($puede_recepcionar): ?>

                <form method="POST" action="recepcionar_envio.php?tracking=<?php echo urlencode($tracking_buscado); ?>">

                    <input type="hidden" name="accion" value="recepcionar">
                    <input type="hidden" name="tracking" value="<?php echo htmlspecialchars($tracking_buscado); ?>">

                    <div class="dashboard-grid" style="grid-template-columns: 1fr 1fr;">

                        <div class="form-group">
                            <label for="fecha_hora_recepcion">Fecha y hora de recepción</label>
                            <input
                                type="datetime-local"
                                id="fecha_hora_recepcion"
                                name="fecha_hora_recepcion"
                                class="form-control"
                                value="<?php echo htmlspecialchars(fechaRecepcionParaInput($fecha_hora_recepcion)); ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="observaciones">Observaciones</label>
                            <textarea
                                id="observaciones"
                                name="observaciones"
                                class="form-control"
                                rows="4"
                            ><?php echo htmlspecialchars($observaciones); ?></textarea>
                        </div>

                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 14px; flex-wrap: wrap;">
                        <button type="submit" class="btn-primary" style="width: auto;">
                            Confirmar recepción
                        </button>
                    </div>

                </form>

            <?php else: ?>

                <div class="alert alert-warning" style="margin: 0;">
                    <?php echo htmlspecialchars($motivo_no_recepcionar !== '' ? $motivo_no_recepcionar : 'Este envío no puede ser recepcionado desde esta pantalla.'); ?>
                </div>

            <?php endif; ?>

        </section>

    <?php endif; ?>


    <section class="dashboard-card">

        <h3 style="margin-top: 0; margin-bottom: 18px;">
            <?php echo $rol_actual === 'ADMIN' ? 'Solicitudes pendientes de recepción' : 'Solicitudes pendientes de recepción en mi sucursal'; ?>
        </h3>

        <div style="overflow-x: auto;">

            <table style="width: 100%; border-collapse: collapse; min-width: 1200px;">

                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tracking</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha solicitud</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destinatario</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Origen</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destino</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Estado actual</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Paquetes</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Acción</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($pendientes_recepcion)): ?>

                        <tr>
                            <td colspan="8" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                No hay envíos pendientes de recepción en este momento.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($pendientes_recepcion as $pendiente): ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['nro_tracking']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['fecha_recepcion']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['apellido_destinatario'] . ', ' . $pendiente['nombre_destinatario']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['nombre_sucursal_origen']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['nombre_sucursal_destino']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['nombre_estado_actual'] ?? 'Sin historial'); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars((string) $pendiente['cantidad_paquetes']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border); white-space: nowrap;">
                                    <a href="recepcionar_envio.php?tracking=<?php echo urlencode($pendiente['nro_tracking']); ?>" class="btn-public-secondary">
                                        Ver y recepcionar
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php endif; ?>
                </tbody>

            </table>

        </div>

    </section>

</main>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
