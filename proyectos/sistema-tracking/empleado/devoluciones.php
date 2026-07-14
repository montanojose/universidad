<?php
// =====================================================
// devoluciones.php
// Procesamiento simple de devolución al origen por plazo vencido
// - acceso para EMPLEADO_SUCURSAL y ADMIN
// - EMPLEADO: solo opera en su sucursal actual de retiro
// - ADMIN: puede probar cualquier envío
// - plazo: 20 días corridos
// - no usa estados nuevos todavía
// - mueve la disponibilidad de retiro al origen
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'EMPLEADO_SUCURSAL']);

$titulo_pagina = 'Procesar Devoluciones';

$mensaje = '';
$tipo_mensaje = '';

$rol_actual = $_SESSION['usuario_rol'] ?? '';

$tracking = trim($_GET['tracking'] ?? '');

$cod_sucursal_empleado = '';
$nombre_sucursal_empleado = '';

$fecha_reingreso_origen = date('Y-m-d H:i:s');
$observaciones = '';

$envio = null;
$disponibilidad_actual = null;
$retiro_realizado = null;
$vencidos = [];

$puede_devolver = false;
$motivo_no_devolver = '';


// -----------------------------------------------------
// FUNCIONES AUXILIARES
// -----------------------------------------------------

function obtenerLegajoEmpleadoSesionDevolucion(): string
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

function normalizarFechaDatetimeLocalDevolucion(string $valor): string
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

function fechaDevolucionParaInput(?string $fecha): string
{
    if (!$fecha) {
        return '';
    }

    return date('Y-m-d\TH:i', strtotime($fecha));
}

function calcularFechaLimiteNueva(string $fecha_base, int $dias): string
{
    $fecha = new DateTime($fecha_base);
    $fecha->modify('+' . $dias . ' day');

    return $fecha->format('Y-m-d H:i:s');
}

function buscarCodigoCatalogoDevolucion(PDO $pdo, string $tabla, string $columna_codigo, string $columna_nombre, array $candidatos): ?string
{
    foreach ($candidatos as $candidato) {

        $sql = "
            SELECT {$columna_codigo} AS codigo
            FROM {$tabla}
            WHERE UPPER({$columna_codigo}) = :candidato
               OR UPPER(REPLACE({$columna_nombre}, ' ', '_')) = :candidato
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':candidato' => strtoupper($candidato)
        ]);

        $fila = $stmt->fetch();

        if ($fila && !empty($fila['codigo'])) {
            return $fila['codigo'];
        }
    }

    return null;
}

function obtenerEstadoRetornoOrigen(PDO $pdo): ?string
{
    return buscarCodigoCatalogoDevolucion(
        $pdo,
        'Estado_Envio',
        'cod_estado_envio',
        'nombre',
        [
            'DISPONIBLE_PARA_RETIRO',
            'DISPONIBLE_RETIRO',
            'DISPONIBLE',
            'RECIBIDO_EN_SUCURSAL'
        ]
    );
}

function estaVencidoParaDevolucion(?string $fecha_limite): bool
{
    if (!$fecha_limite) {
        return false;
    }

    return strtotime($fecha_limite) < time();
}


// -----------------------------------------------------
// 1. OBTENER SUCURSAL DEL EMPLEADO SI CORRESPONDE
// -----------------------------------------------------

if ($rol_actual === 'EMPLEADO_SUCURSAL') {

    $legajo_empleado_sesion = obtenerLegajoEmpleadoSesionDevolucion();

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
// 2. PROCESAR DEVOLUCIÓN AL ORIGEN
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'procesar_devolucion') {

    $tracking = trim($_POST['tracking'] ?? '');
    $fecha_reingreso_origen = normalizarFechaDatetimeLocalDevolucion($_POST['fecha_reingreso_origen'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');

    if ($tracking === '' || $fecha_reingreso_origen === '') {

        $mensaje = 'Completá los datos obligatorios para procesar la devolución.';
        $tipo_mensaje = 'error';

    } else {

        try {

            $sqlValidacion = "
                SELECT
                    e.nro_tracking,
                    e.cod_sucursal_origen,
                    e.cod_sucursal_destino,

                    so.nombre AS nombre_sucursal_origen,
                    sd.nombre AS nombre_sucursal_destino,

                    dr.cod_sucursal_retiro,
                    dr.fecha_disponible,
                    dr.fecha_limite_retiro,
                    dr.dias_plazo_aplicado,
                    dr.fecha_vencimiento_procesado,
                    dr.observaciones AS observaciones_disponibilidad,

                    re.nro_tracking AS retiro_existente
                FROM Envio e
                INNER JOIN Sucursal so
                    ON e.cod_sucursal_origen = so.cod_sucursal
                INNER JOIN Sucursal sd
                    ON e.cod_sucursal_destino = sd.cod_sucursal
                INNER JOIN Disponibilidad_Retiro dr
                    ON e.nro_tracking = dr.nro_tracking
                LEFT JOIN Retiro_Envio re
                    ON e.nro_tracking = re.nro_tracking
                WHERE e.nro_tracking = :nro_tracking
                LIMIT 1
            ";

            $stmtValidacion = $pdo->prepare($sqlValidacion);
            $stmtValidacion->execute([
                ':nro_tracking' => $tracking
            ]);

            $validacion = $stmtValidacion->fetch();

            if (!$validacion) {

                $mensaje = 'No se encontró un envío con disponibilidad de retiro para ese tracking.';
                $tipo_mensaje = 'error';

            } elseif (!empty($validacion['retiro_existente'])) {

                $mensaje = 'Ese envío ya fue retirado y no puede devolverse.';
                $tipo_mensaje = 'warning';

            } elseif (!estaVencidoParaDevolucion($validacion['fecha_limite_retiro'])) {

                $mensaje = 'Todavía no venció el plazo de retiro de ese envío.';
                $tipo_mensaje = 'warning';

            } elseif (!empty($validacion['fecha_vencimiento_procesado'])) {

                $mensaje = 'La devolución de ese envío ya fue procesada anteriormente.';
                $tipo_mensaje = 'warning';

            } elseif ($validacion['cod_sucursal_retiro'] === $validacion['cod_sucursal_origen']) {

                $mensaje = 'Ese envío ya fue reubicado al origen.';
                $tipo_mensaje = 'warning';

            } elseif (
                $rol_actual === 'EMPLEADO_SUCURSAL' &&
                $validacion['cod_sucursal_retiro'] !== $cod_sucursal_empleado
            ) {

                $mensaje = 'No podés procesar esta devolución porque corresponde a otra sucursal.';
                $tipo_mensaje = 'error';

            } else {

                $estado_retorno = obtenerEstadoRetornoOrigen($pdo);

                if ($estado_retorno === null) {
                    $mensaje = 'No se encontró un estado utilizable para registrar el retorno al origen.';
                    $tipo_mensaje = 'error';
                }
            }

            if ($mensaje === '') {

                $nueva_fecha_limite = calcularFechaLimiteNueva($fecha_reingreso_origen, 20);

                $pdo->beginTransaction();

                $observacion_final = 'Devolución al origen por vencimiento del plazo de retiro. Disponible para retiro del remitente.';
                if ($observaciones !== '') {
                    $observacion_final .= ' ' . $observaciones;
                }

                $sqlActualizarDisponibilidad = "
                    UPDATE Disponibilidad_Retiro
                    SET
                        cod_sucursal_retiro = :cod_sucursal_retiro,
                        fecha_disponible = :fecha_disponible,
                        fecha_limite_retiro = :fecha_limite_retiro,
                        dias_plazo_aplicado = 20,
                        fecha_vencimiento_procesado = NOW(),
                        observaciones = :observaciones
                    WHERE nro_tracking = :nro_tracking
                ";

                $stmtActualizarDisponibilidad = $pdo->prepare($sqlActualizarDisponibilidad);
                $stmtActualizarDisponibilidad->execute([
                    ':cod_sucursal_retiro' => $validacion['cod_sucursal_origen'],
                    ':fecha_disponible' => $fecha_reingreso_origen,
                    ':fecha_limite_retiro' => $nueva_fecha_limite,
                    ':observaciones' => $observacion_final,
                    ':nro_tracking' => $tracking
                ]);

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
                    ':nro_tracking' => $tracking,
                    ':cod_estado_envio' => $estado_retorno,
                    ':fecha_hora' => $fecha_reingreso_origen,
                    ':cod_sucursal_actual' => $validacion['cod_sucursal_origen'],
                    ':observaciones' => $observacion_final
                ]);
                $stmtInsertarHistorial->closeCursor();

                $pdo->commit();

                $mensaje = 'La devolución al origen fue procesada correctamente.';
                $tipo_mensaje = 'success';

                $fecha_reingreso_origen = date('Y-m-d H:i:s');
                $observaciones = '';
            }

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $mensaje = 'Ocurrió un error al procesar la devolución.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 3. CARGAR DETALLE DEL TRACKING
// -----------------------------------------------------

if ($tracking !== '') {

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

                dr.cod_sucursal_retiro,
                dr.fecha_disponible,
                dr.fecha_limite_retiro,
                dr.dias_plazo_aplicado,
                dr.fecha_vencimiento_procesado,
                dr.observaciones AS observaciones_disponibilidad,
                sr.nombre AS nombre_sucursal_retiro,
                sr.direccion AS direccion_sucursal_retiro,

                re.nro_tracking AS retiro_existente
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

            LEFT JOIN Disponibilidad_Retiro dr
                ON e.nro_tracking = dr.nro_tracking

            LEFT JOIN Sucursal sr
                ON dr.cod_sucursal_retiro = sr.cod_sucursal

            LEFT JOIN Retiro_Envio re
                ON e.nro_tracking = re.nro_tracking

            WHERE e.nro_tracking = :nro_tracking
            LIMIT 1
        ";

        $stmtEnvio = $pdo->prepare($sqlEnvio);
        $stmtEnvio->execute([
            ':nro_tracking' => $tracking
        ]);

        $envio = $stmtEnvio->fetch();

        if ($envio) {

            if (!$envio['cod_sucursal_retiro']) {
                $puede_devolver = false;
                $motivo_no_devolver = 'Este envío todavía no tiene disponibilidad de retiro registrada.';
            } elseif (
                $rol_actual === 'EMPLEADO_SUCURSAL' &&
                $envio['cod_sucursal_retiro'] !== $cod_sucursal_empleado
            ) {
                $envio = null;
                $mensaje = 'No podés operar ese tracking porque pertenece a otra sucursal.';
                $tipo_mensaje = 'error';
            } elseif (!empty($envio['retiro_existente'])) {
                $puede_devolver = false;
                $motivo_no_devolver = 'Este envío ya fue retirado.';
            } elseif (!estaVencidoParaDevolucion($envio['fecha_limite_retiro'])) {
                $puede_devolver = false;
                $motivo_no_devolver = 'El plazo de retiro todavía no venció.';
            } elseif (!empty($envio['fecha_vencimiento_procesado'])) {
                $puede_devolver = false;
                $motivo_no_devolver = 'La devolución ya fue procesada anteriormente.';
            } elseif ($envio['cod_sucursal_retiro'] === $envio['cod_sucursal_origen']) {
                $puede_devolver = false;
                $motivo_no_devolver = 'El envío ya se encuentra nuevamente en origen.';
            } else {
                $puede_devolver = true;
                $motivo_no_devolver = '';
            }
        } else {
            if ($mensaje === '') {
                $mensaje = 'No se encontró el envío indicado.';
                $tipo_mensaje = 'warning';
            }
        }

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar el detalle del envío.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 4. CARGAR VENCIDOS PENDIENTES
// -----------------------------------------------------

try {

    $sqlVencidos = "
        SELECT
            e.nro_tracking,
            e.fecha_recepcion,
            e.cod_sucursal_origen,
            e.cod_sucursal_destino,
            e.dni_destinatario,

            cd.nombre AS nombre_destinatario,
            cd.apellido AS apellido_destinatario,

            so.nombre AS nombre_sucursal_origen,
            sr.nombre AS nombre_sucursal_retiro,

            dr.fecha_disponible,
            dr.fecha_limite_retiro,
            dr.cod_sucursal_retiro,
            dr.fecha_vencimiento_procesado,

            re.nro_tracking AS retiro_existente
        FROM Envio e
        INNER JOIN vista_cliente cd
            ON e.dni_destinatario = cd.dni
        INNER JOIN Disponibilidad_Retiro dr
            ON e.nro_tracking = dr.nro_tracking
        INNER JOIN Sucursal so
            ON e.cod_sucursal_origen = so.cod_sucursal
        INNER JOIN Sucursal sr
            ON dr.cod_sucursal_retiro = sr.cod_sucursal
        LEFT JOIN Retiro_Envio re
            ON e.nro_tracking = re.nro_tracking
        WHERE re.nro_tracking IS NULL
          AND dr.fecha_vencimiento_procesado IS NULL
    ";

    $paramsVencidos = [];

    if ($rol_actual === 'EMPLEADO_SUCURSAL' && $cod_sucursal_empleado !== '') {
        $sqlVencidos .= " AND dr.cod_sucursal_retiro = :cod_sucursal_retiro ";
        $paramsVencidos[':cod_sucursal_retiro'] = $cod_sucursal_empleado;
    }

    $sqlVencidos .= "
        ORDER BY dr.fecha_limite_retiro ASC, e.nro_tracking ASC
    ";

    $stmtVencidos = $pdo->prepare($sqlVencidos);
    $stmtVencidos->execute($paramsVencidos);

    $todos = $stmtVencidos->fetchAll();

    foreach ($todos as $item) {
        if (
            estaVencidoParaDevolucion($item['fecha_limite_retiro']) &&
            $item['cod_sucursal_retiro'] !== $item['cod_sucursal_origen']
        ) {
            $vencidos[] = $item;
        }
    }

} catch (PDOException $e) {

    $mensaje = 'Ocurrió un error al cargar los envíos vencidos para devolución.';
    $tipo_mensaje = 'error';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Procesar Devoluciones</h1>
        <p class="page-subtitle">
            Si un envío vence en sucursal destino sin ser retirado, se reubica al origen con un nuevo plazo de 20 días corridos.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>

    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Buscar tracking para procesar devolución</h3>

        <?php if ($rol_actual === 'EMPLEADO_SUCURSAL' && $cod_sucursal_empleado !== ''): ?>
            <p class="field-note" style="margin-bottom: 14px;">
                Estás operando como sucursal:
                <strong><?php echo htmlspecialchars($cod_sucursal_empleado . ' - ' . $nombre_sucursal_empleado); ?></strong>
            </p>
        <?php endif; ?>

        <form method="GET" action="devoluciones.php">

            <div class="dashboard-grid" style="grid-template-columns: 2fr 1fr;">

                <div class="form-group">
                    <label for="tracking">Número de tracking</label>
                    <input
                        type="text"
                        id="tracking"
                        name="tracking"
                        class="form-control"
                        value="<?php echo htmlspecialchars($tracking); ?>"
                        placeholder="Ej: TRK000001"
                        required
                    >
                </div>

                <div class="form-group" style="display: flex; align-items: flex-end; gap: 12px;">
                    <button type="submit" class="btn-primary" style="width: auto;">
                        Buscar
                    </button>

                    <a href="devoluciones.php" class="btn-public-secondary">
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
                <p><strong>Fecha:</strong> <?php echo htmlspecialchars($envio['fecha_recepcion']); ?></p>
                <p><strong>Remitente:</strong> <?php echo htmlspecialchars($envio['apellido_remitente'] . ', ' . $envio['nombre_remitente']); ?></p>
                <p><strong>Destinatario:</strong> <?php echo htmlspecialchars($envio['apellido_destinatario'] . ', ' . $envio['nombre_destinatario']); ?></p>
                <p><strong>Origen original:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_origen']); ?></p>
                <p><strong>Destino original:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_destino']); ?></p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Situación actual</h3>

                <p><strong>Estado actual:</strong> <?php echo htmlspecialchars($envio['nombre_estado_actual'] ?? ''); ?></p>
                <p><strong>Fecha estado:</strong> <?php echo htmlspecialchars($envio['fecha_estado_actual'] ?? ''); ?></p>
                <p><strong>Sucursal retiro actual:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_retiro'] ?? ''); ?></p>
                <p><strong>Fecha disponible:</strong> <?php echo htmlspecialchars($envio['fecha_disponible'] ?? ''); ?></p>
                <p><strong>Fecha límite:</strong> <?php echo htmlspecialchars($envio['fecha_limite_retiro'] ?? ''); ?></p>
            </article>

        </section>


        <?php if (!empty($envio['observaciones_disponibilidad'])): ?>
            <section class="dashboard-card" style="margin-bottom: 24px;">
                <h3 style="margin-top: 0; margin-bottom: 12px;">Observaciones actuales</h3>
                <p style="margin: 0;"><?php echo htmlspecialchars($envio['observaciones_disponibilidad']); ?></p>
            </section>
        <?php endif; ?>


        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Procesar devolución al origen</h3>

            <?php if ($puede_devolver): ?>

                <form method="POST" action="devoluciones.php?tracking=<?php echo urlencode($tracking); ?>">

                    <input type="hidden" name="accion" value="procesar_devolucion">
                    <input type="hidden" name="tracking" value="<?php echo htmlspecialchars($tracking); ?>">

                    <div class="dashboard-grid" style="grid-template-columns: 1fr 1fr;">

                        <div class="form-group">
                            <label for="fecha_reingreso_origen">Fecha y hora de reingreso en origen</label>
                            <input
                                type="datetime-local"
                                id="fecha_reingreso_origen"
                                name="fecha_reingreso_origen"
                                class="form-control"
                                value="<?php echo htmlspecialchars(fechaDevolucionParaInput($fecha_reingreso_origen)); ?>"
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

                    <div class="alert alert-warning" style="margin-bottom: 18px;">
                        Esta acción moverá la disponibilidad de retiro hacia la sucursal de origen y reiniciará el plazo en 20 días corridos.
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 10px; flex-wrap: wrap;">
                        <button type="submit" class="btn-primary" style="width: auto;">
                            Procesar devolución
                        </button>
                    </div>

                </form>

            <?php else: ?>

                <div class="alert alert-warning" style="margin: 0;">
                    <?php echo htmlspecialchars($motivo_no_devolver !== '' ? $motivo_no_devolver : 'Este envío no se puede devolver desde esta pantalla.'); ?>
                </div>

            <?php endif; ?>

        </section>

    <?php endif; ?>


    <section class="dashboard-card">

        <h3 style="margin-top: 0; margin-bottom: 18px;">
            <?php echo $rol_actual === 'ADMIN' ? 'Envíos vencidos para devolución' : 'Envíos vencidos en mi sucursal'; ?>
        </h3>

        <div style="overflow-x: auto;">

            <table style="width: 100%; border-collapse: collapse; min-width: 1450px;">

                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tracking</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha envío</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destinatario</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Sucursal retiro actual</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Origen</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Disponible desde</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha límite</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Acción</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($vencidos)): ?>

                        <tr>
                            <td colspan="8" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                No hay envíos vencidos pendientes de devolución.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($vencidos as $item): ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($item['nro_tracking']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($item['fecha_recepcion']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($item['dni_destinatario'] . ' - ' . $item['apellido_destinatario'] . ', ' . $item['nombre_destinatario']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($item['nombre_sucursal_retiro']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($item['nombre_sucursal_origen']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($item['fecha_disponible']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($item['fecha_limite_retiro']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border); white-space: nowrap;">
                                    <a href="devoluciones.php?tracking=<?php echo urlencode($item['nro_tracking']); ?>" class="btn-public-secondary">
                                        Ver y devolver
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
