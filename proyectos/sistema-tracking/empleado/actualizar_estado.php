<?php
// =====================================================
// actualizar_estado.php
// Registro de nuevos movimientos del envío
// - acceso para EMPLEADO_SUCURSAL y ADMIN
// - busca un tracking
// - registra un nuevo movimiento en Historial_Estado
// - opcionalmente asocia un viaje ya vinculado al envío
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'EMPLEADO_SUCURSAL']);

$titulo_pagina = 'Actualizar Estado de Envío';

$mensaje = '';
$tipo_mensaje = '';

$rol_actual = $_SESSION['usuario_rol'] ?? '';

$tracking_buscado = trim($_GET['tracking'] ?? '');
$fecha_hora_estado = date('Y-m-d H:i:s');
$cod_estado_envio_nuevo = '';
$observaciones = '';
$viaje_seleccionado = '';

$cod_sucursal_empleado = '';
$nombre_sucursal_empleado = '';
$cod_sucursal_actual = '';

$envio = null;
$estados_envio = [];
$sucursales = [];
$viajes_asociados = [];
$historial = [];
$envios_recientes = [];


// -----------------------------------------------------
// FUNCIONES AUXILIARES
// -----------------------------------------------------

function obtenerLegajoEmpleadoSesionEstado(): string
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

function normalizarFechaDatetimeLocalEstado(string $valor): string
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

function fechaEstadoParaInput(?string $fecha): string
{
    if (!$fecha) {
        return '';
    }

    return date('Y-m-d\TH:i', strtotime($fecha));
}


// -----------------------------------------------------
// 1. OBTENER SUCURSAL DEL EMPLEADO SI CORRESPONDE
// -----------------------------------------------------

if ($rol_actual === 'EMPLEADO_SUCURSAL') {

    $legajo_empleado_sesion = obtenerLegajoEmpleadoSesionEstado();

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
                $cod_sucursal_actual = $cod_sucursal_empleado;
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
// 2. CARGAR CATÁLOGOS
// -----------------------------------------------------

try {

    $sqlEstados = "
        SELECT
            cod_estado_envio,
            nombre
        FROM Estado_Envio
        ORDER BY nombre ASC
    ";

    $estados_envio = $pdo->query($sqlEstados)->fetchAll();

    if ($rol_actual === 'ADMIN') {
        $sqlSucursales = "
            SELECT cod_sucursal, nombre
            FROM Sucursal
            ORDER BY nombre ASC
        ";
        $sucursales = $pdo->query($sqlSucursales)->fetchAll();
    }

} catch (PDOException $e) {

    $mensaje = 'No se pudieron cargar los catálogos del formulario.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 3. PROCESAR ACTUALIZACIÓN DE ESTADO
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {

    $tracking_buscado = trim($_POST['tracking'] ?? '');
    $fecha_hora_estado = normalizarFechaDatetimeLocalEstado($_POST['fecha_hora_estado'] ?? '');
    $cod_estado_envio_nuevo = trim($_POST['cod_estado_envio_nuevo'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');
    $viaje_seleccionado = trim($_POST['viaje_seleccionado'] ?? '');

    if ($rol_actual === 'ADMIN') {
        $cod_sucursal_actual = trim($_POST['cod_sucursal_actual'] ?? '');
    } else {
        $cod_sucursal_actual = $cod_sucursal_empleado;
    }

    if (
        $tracking_buscado === '' ||
        $fecha_hora_estado === '' ||
        $cod_estado_envio_nuevo === '' ||
        $cod_sucursal_actual === ''
    ) {

        $mensaje = 'Completá todos los campos obligatorios.';
        $tipo_mensaje = 'error';

    } else {

        try {

            $sqlEnvioValidacion = "
                SELECT
                    e.nro_tracking,
                    e.cod_sucursal_origen,
                    e.cod_sucursal_destino,

                    he.cod_estado_envio AS cod_estado_actual,
                    he.nro_movimiento AS nro_movimiento_actual,
                    he.cod_sucursal_actual AS cod_sucursal_ultimo_movimiento,
                    ee.nombre AS nombre_estado_actual
                FROM Envio e
                LEFT JOIN (
                    SELECT h1.nro_tracking, h1.cod_estado_envio, h1.nro_movimiento, h1.cod_sucursal_actual
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

            $stmtEnvioValidacion = $pdo->prepare($sqlEnvioValidacion);
            $stmtEnvioValidacion->execute([
                ':nro_tracking' => $tracking_buscado
            ]);

            $envioValidacion = $stmtEnvioValidacion->fetch();

            if (!$envioValidacion) {

                $mensaje = 'No se encontró el envío indicado.';
                $tipo_mensaje = 'error';

            } elseif (
                $rol_actual === 'EMPLEADO_SUCURSAL' &&
                !empty($envioValidacion['cod_sucursal_ultimo_movimiento']) &&
                $envioValidacion['cod_sucursal_ultimo_movimiento'] !== $cod_sucursal_empleado
            ) {

                $mensaje = 'No podés actualizar este envío porque el último movimiento pertenece a otra sucursal.';
                $tipo_mensaje = 'error';

            } elseif (
                $rol_actual === 'EMPLEADO_SUCURSAL' &&
                empty($envioValidacion['cod_sucursal_ultimo_movimiento']) &&
                $envioValidacion['cod_sucursal_origen'] !== $cod_sucursal_empleado
            ) {

                $mensaje = 'No podés actualizar este envío porque pertenece a otra sucursal de origen.';
                $tipo_mensaje = 'error';

            } elseif (
                !empty($envioValidacion['cod_estado_actual']) &&
                $envioValidacion['cod_estado_actual'] === $cod_estado_envio_nuevo
            ) {

                $mensaje = 'El nuevo estado no puede ser igual al estado actual.';
                $tipo_mensaje = 'warning';

            } else {

                $patente_viaje = null;
                $fecha_salida_viaje = null;

                if ($viaje_seleccionado !== '') {

                    $partes_viaje = explode('||', $viaje_seleccionado);

                    if (count($partes_viaje) === 2) {
                        $patente_viaje = $partes_viaje[0];
                        $fecha_salida_viaje = $partes_viaje[1];

                        $sqlValidarViaje = "
                            SELECT COUNT(*) 
                            FROM Viaje_Envio
                            WHERE nro_tracking = :nro_tracking
                              AND patente = :patente
                              AND fecha_salida = :fecha_salida
                        ";

                        $stmtValidarViaje = $pdo->prepare($sqlValidarViaje);
                        $stmtValidarViaje->execute([
                            ':nro_tracking' => $tracking_buscado,
                            ':patente' => $patente_viaje,
                            ':fecha_salida' => $fecha_salida_viaje
                        ]);

                        if ((int) $stmtValidarViaje->fetchColumn() === 0) {
                            $mensaje = 'El viaje seleccionado no está asociado a ese envío.';
                            $tipo_mensaje = 'error';
                        }
                    } else {
                        $mensaje = 'El viaje seleccionado no es válido.';
                        $tipo_mensaje = 'error';
                    }
                }

                if ($mensaje === '') {

                    $sqlInsertarHistorial = "
                        CALL sp_registrar_movimiento_envio(
                            :nro_tracking,
                            :cod_estado_envio,
                            :fecha_hora,
                            :cod_sucursal_actual,
                            :patente,
                            :fecha_salida,
                            :observaciones
                        )
                    ";

                    $stmtInsertarHistorial = $pdo->prepare($sqlInsertarHistorial);

                    $stmtInsertarHistorial->execute([
                        ':nro_tracking' => $tracking_buscado,
                        ':cod_estado_envio' => $cod_estado_envio_nuevo,
                        ':fecha_hora' => $fecha_hora_estado,
                        ':cod_sucursal_actual' => $cod_sucursal_actual,
                        ':patente' => $patente_viaje,
                        ':fecha_salida' => $fecha_salida_viaje,
                        ':observaciones' => ($observaciones !== '' ? $observaciones : null)
                    ]);
                    $stmtInsertarHistorial->closeCursor();

                    $mensaje = 'Estado actualizado correctamente.';
                    $tipo_mensaje = 'success';

                    $cod_estado_envio_nuevo = '';
                    $observaciones = '';
                    $viaje_seleccionado = '';
                    $fecha_hora_estado = date('Y-m-d H:i:s');
                }
            }

        } catch (PDOException $e) {

            $mensaje = 'Ocurrió un error al registrar el nuevo movimiento del envío.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 4. CARGAR DETALLE DEL TRACKING
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
                he.nro_movimiento AS nro_movimiento_actual,
                he.fecha_hora AS fecha_estado_actual,
                he.cod_sucursal_actual AS cod_sucursal_ultimo_movimiento,
                su.nombre AS nombre_sucursal_ultimo_movimiento,
                ee.nombre AS nombre_estado_actual
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
                SELECT h1.nro_tracking, h1.cod_estado_envio, h1.nro_movimiento, h1.fecha_hora, h1.cod_sucursal_actual
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

            LEFT JOIN Sucursal su
                ON he.cod_sucursal_actual = su.cod_sucursal

            WHERE e.nro_tracking = :nro_tracking
            LIMIT 1
        ";

        $stmtEnvio = $pdo->prepare($sqlEnvio);
        $stmtEnvio->execute([
            ':nro_tracking' => $tracking_buscado
        ]);

        $envio = $stmtEnvio->fetch();

        if ($envio) {

            $sqlViajes = "
                SELECT
                    ve.patente,
                    ve.fecha_salida,
                    ve.fecha_asignacion,
                    ch.nombre AS nombre_chofer,
                    ch.apellido AS apellido_chofer,
                    so.nombre AS nombre_origen,
                    sd.nombre AS nombre_destino
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
                WHERE ve.nro_tracking = :nro_tracking
                ORDER BY ve.fecha_asignacion ASC
            ";

            $stmtViajes = $pdo->prepare($sqlViajes);
            $stmtViajes->execute([
                ':nro_tracking' => $tracking_buscado
            ]);

            $viajes_asociados = $stmtViajes->fetchAll();


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
            ";

            $stmtHistorial = $pdo->prepare($sqlHistorial);
            $stmtHistorial->execute([
                ':nro_tracking' => $tracking_buscado
            ]);

            $historial = $stmtHistorial->fetchAll();

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
// 5. CARGAR LISTADO RECIENTE
// -----------------------------------------------------

try {

    $sqlRecientes = "
        SELECT
            e.nro_tracking,
            e.fecha_recepcion,
            cd.nombre AS nombre_destinatario,
            cd.apellido AS apellido_destinatario,
            so.nombre AS nombre_origen,
            sd.nombre AS nombre_destino,

            he.cod_estado_envio AS cod_estado_actual,
            ee.nombre AS nombre_estado_actual
        FROM Envio e
        INNER JOIN vista_cliente cd
            ON e.dni_destinatario = cd.dni
        INNER JOIN Sucursal so
            ON e.cod_sucursal_origen = so.cod_sucursal
        INNER JOIN Sucursal sd
            ON e.cod_sucursal_destino = sd.cod_sucursal

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

        WHERE 1 = 1
    ";

    $params_recientes = [];

    if ($rol_actual === 'EMPLEADO_SUCURSAL' && $cod_sucursal_empleado !== '') {
        $sqlRecientes .= " AND e.cod_sucursal_origen = :cod_sucursal_origen ";
        $params_recientes[':cod_sucursal_origen'] = $cod_sucursal_empleado;
    }

    $sqlRecientes .= "
        ORDER BY e.fecha_recepcion DESC, e.nro_tracking DESC
        LIMIT 20
    ";

    $stmtRecientes = $pdo->prepare($sqlRecientes);
    $stmtRecientes->execute($params_recientes);

    $envios_recientes = $stmtRecientes->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'Ocurrió un error al cargar los envíos recientes.';
    $tipo_mensaje = 'error';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Actualizar Estado de Envío</h1>
        <p class="page-subtitle">
            Registrá nuevos movimientos del envío dentro del circuito operativo del sistema.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Buscar tracking</h3>

        <?php if ($rol_actual === 'EMPLEADO_SUCURSAL' && $cod_sucursal_empleado !== ''): ?>
            <p class="field-note" style="margin-bottom: 14px;">
                Estás operando como sucursal:
                <strong><?php echo htmlspecialchars($cod_sucursal_empleado . ' - ' . $nombre_sucursal_empleado); ?></strong>
            </p>
        <?php endif; ?>

        <form method="GET" action="actualizar_estado.php">

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

                    <a href="actualizar_estado.php" class="btn-public-secondary">
                        Limpiar
                    </a>
                </div>

            </div>

        </form>

    </section>


    <?php if ($envio): ?>

        <section class="dashboard-grid" style="margin-bottom: 24px;">

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Resumen del envío</h3>

                <p><strong>Tracking:</strong> <?php echo htmlspecialchars($envio['nro_tracking']); ?></p>
                <p><strong>Remitente:</strong> <?php echo htmlspecialchars($envio['apellido_remitente'] . ', ' . $envio['nombre_remitente']); ?></p>
                <p><strong>Destinatario:</strong> <?php echo htmlspecialchars($envio['apellido_destinatario'] . ', ' . $envio['nombre_destinatario']); ?></p>
                <p><strong>Origen:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_origen']); ?></p>
                <p><strong>Destino:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_destino']); ?></p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Estado actual</h3>

                <p><strong>Estado:</strong> <?php echo htmlspecialchars($envio['nombre_estado_actual'] ?? 'Sin historial'); ?></p>
                <p><strong>Movimiento:</strong> <?php echo htmlspecialchars($envio['nro_movimiento_actual'] ?? ''); ?></p>
                <p><strong>Fecha:</strong> <?php echo htmlspecialchars($envio['fecha_estado_actual'] ?? ''); ?></p>
                <p><strong>Sucursal actual:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_ultimo_movimiento'] ?? ''); ?></p>
            </article>

        </section>


        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Registrar nuevo movimiento</h3>

            <form method="POST" action="actualizar_estado.php?tracking=<?php echo urlencode($tracking_buscado); ?>">

                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="tracking" value="<?php echo htmlspecialchars($tracking_buscado); ?>">

                <div class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">

                    <div>

                        <div class="form-group">
                            <label for="fecha_hora_estado">Fecha y hora del movimiento</label>
                            <input
                                type="datetime-local"
                                id="fecha_hora_estado"
                                name="fecha_hora_estado"
                                class="form-control"
                                value="<?php echo htmlspecialchars(fechaEstadoParaInput($fecha_hora_estado)); ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="cod_estado_envio_nuevo">Nuevo estado</label>
                            <select id="cod_estado_envio_nuevo" name="cod_estado_envio_nuevo" class="form-control" required>
                                <option value="">Seleccione un estado</option>

                                <?php foreach ($estados_envio as $estado): ?>
                                    <option
                                        value="<?php echo htmlspecialchars($estado['cod_estado_envio']); ?>"
                                        <?php echo ($cod_estado_envio_nuevo === $estado['cod_estado_envio']) ? 'selected' : ''; ?>
                                    >
                                        <?php echo htmlspecialchars($estado['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($rol_actual === 'ADMIN'): ?>

                            <div class="form-group">
                                <label for="cod_sucursal_actual">Sucursal actual del movimiento</label>
                                <select id="cod_sucursal_actual" name="cod_sucursal_actual" class="form-control" required>
                                    <option value="">Seleccione una sucursal</option>

                                    <?php foreach ($sucursales as $sucursal): ?>
                                        <option
                                            value="<?php echo htmlspecialchars($sucursal['cod_sucursal']); ?>"
                                            <?php
                                                $seleccion = $cod_sucursal_actual !== ''
                                                    ? $cod_sucursal_actual
                                                    : ($envio['cod_sucursal_ultimo_movimiento'] ?? $envio['cod_sucursal_origen']);
                                                echo ($seleccion === $sucursal['cod_sucursal']) ? 'selected' : '';
                                            ?>
                                        >
                                            <?php echo htmlspecialchars($sucursal['cod_sucursal'] . ' - ' . $sucursal['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                        <?php else: ?>

                            <div class="form-group">
                                <label for="sucursal_actual_mostrar">Sucursal actual del movimiento</label>
                                <input
                                    type="text"
                                    id="sucursal_actual_mostrar"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($cod_sucursal_empleado . ' - ' . $nombre_sucursal_empleado); ?>"
                                    readonly
                                >
                            </div>

                        <?php endif; ?>

                    </div>

                    <div>

                        <div class="form-group">
                            <label for="viaje_seleccionado">Viaje asociado (opcional)</label>
                            <select id="viaje_seleccionado" name="viaje_seleccionado" class="form-control">
                                <option value="">Sin viaje asociado</option>

                                <?php foreach ($viajes_asociados as $viaje): ?>
                                    <?php $valor_viaje = $viaje['patente'] . '||' . $viaje['fecha_salida']; ?>
                                    <option
                                        value="<?php echo htmlspecialchars($valor_viaje); ?>"
                                        <?php echo ($viaje_seleccionado === $valor_viaje) ? 'selected' : ''; ?>
                                    >
                                        <?php
                                            echo htmlspecialchars(
                                                $viaje['patente'] . ' | ' .
                                                $viaje['fecha_salida'] . ' | ' .
                                                $viaje['nombre_origen'] . ' → ' . $viaje['nombre_destino'] . ' | ' .
                                                $viaje['apellido_chofer'] . ', ' . $viaje['nombre_chofer']
                                            );
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="observaciones">Observaciones</label>
                            <textarea
                                id="observaciones"
                                name="observaciones"
                                class="form-control"
                                rows="5"
                            ><?php echo htmlspecialchars($observaciones); ?></textarea>
                        </div>

                        <div style="display: flex; gap: 12px; margin-top: 22px; flex-wrap: wrap;">
                            <button type="submit" class="btn-primary" style="width: auto;">
                                Registrar movimiento
                            </button>
                        </div>

                    </div>

                </div>

            </form>

        </section>


        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Historial reciente del envío</h3>

            <div style="overflow-x: auto;">

                <table style="width: 100%; border-collapse: collapse; min-width: 1350px;">

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
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border); max-width: 260px;">
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


    <section class="dashboard-card">

        <h3 style="margin-top: 0; margin-bottom: 18px;">
            <?php echo $rol_actual === 'ADMIN' ? 'Envíos recientes del sistema' : 'Envíos recientes de mi sucursal'; ?>
        </h3>

        <div style="overflow-x: auto;">

            <table style="width: 100%; border-collapse: collapse; min-width: 1200px;">

                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tracking</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destinatario</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Origen</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destino</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Estado actual</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Acción</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($envios_recientes)): ?>

                        <tr>
                            <td colspan="7" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                No hay envíos recientes para mostrar.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($envios_recientes as $item): ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($item['nro_tracking']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($item['fecha_recepcion']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($item['apellido_destinatario'] . ', ' . $item['nombre_destinatario']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($item['nombre_origen']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($item['nombre_destino']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($item['nombre_estado_actual'] ?? 'Sin historial'); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border); white-space: nowrap;">
                                    <a href="actualizar_estado.php?tracking=<?php echo urlencode($item['nro_tracking']); ?>" class="btn-public-secondary">
                                        Ver y actualizar
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
