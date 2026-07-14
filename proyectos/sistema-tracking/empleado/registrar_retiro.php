<?php
// =====================================================
// registrar_retiro.php
// Registro real del retiro en sucursal
// - acceso para EMPLEADO_SUCURSAL y ADMIN
// - EMPLEADO: solo opera en su sucursal
// - ADMIN: puede probar cualquier sucursal
// - soporta retiro por:
//   * DESTINATARIO (flujo normal)
//   * AUTORIZADO   (flujo normal)
//   * REMITENTE    (cuando volvió al origen por devolución)
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'EMPLEADO_SUCURSAL']);

$titulo_pagina = 'Registrar Retiro';

$mensaje = '';
$tipo_mensaje = '';

$rol_actual = $_SESSION['usuario_rol'] ?? '';

$tracking = trim($_GET['tracking'] ?? '');

$cod_sucursal_empleado = '';
$nombre_sucursal_empleado = '';

$fecha_hora_retiro = date('Y-m-d H:i:s');
$tipo_retirante = 'DESTINATARIO';
$observaciones = '';

$dni_autorizado = '';
$nombre_autorizado = '';
$apellido_autorizado = '';
$telefono_autorizado = '';
$vinculo_autorizado = '';

$envio = null;
$retiro_realizado = null;
$pendientes_retiro = [];

$puede_registrar = false;
$motivo_no_registrar = '';
$es_retorno_origen = false;


// -----------------------------------------------------
// FUNCIONES AUXILIARES
// -----------------------------------------------------

function obtenerLegajoEmpleadoSesionRetiro(): string
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

function normalizarFechaDatetimeLocalRetiro(string $valor): string
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

function fechaRetiroParaInput(?string $fecha): string
{
    if (!$fecha) {
        return '';
    }

    return date('Y-m-d\TH:i', strtotime($fecha));
}

function buscarCodigoCatalogoRetiro(PDO $pdo, string $tabla, string $columna_codigo, string $columna_nombre, array $candidatos): ?string
{
    foreach ($candidatos as $candidato) {

        $sql = "
            SELECT {$columna_codigo} AS codigo
            FROM {$tabla}
            WHERE UPPER({$columna_codigo}) = :candidato_codigo
               OR UPPER(REPLACE({$columna_nombre}, ' ', '_')) = :candidato_nombre
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':candidato_codigo' => strtoupper($candidato),
            ':candidato_nombre' => strtoupper($candidato)
        ]);

        $fila = $stmt->fetch();

        if ($fila && !empty($fila['codigo'])) {
            return $fila['codigo'];
        }
    }

    return null;
}

function obtenerEstadoEnvioRetirado(PDO $pdo): ?string
{
    return buscarCodigoCatalogoRetiro(
        $pdo,
        'Estado_Envio',
        'cod_estado_envio',
        'nombre',
        ['RETIRADO', 'ENTREGADO', 'RETIRADO_EN_SUCURSAL']
    );
}


// -----------------------------------------------------
// 1. OBTENER SUCURSAL DEL EMPLEADO SI CORRESPONDE
// -----------------------------------------------------

if ($rol_actual === 'EMPLEADO_SUCURSAL') {

    $legajo_empleado_sesion = obtenerLegajoEmpleadoSesionRetiro();

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
// 2. PROCESAR REGISTRO DE RETIRO
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'registrar_retiro') {

    $tracking = trim($_POST['tracking'] ?? '');
    $fecha_hora_retiro = normalizarFechaDatetimeLocalRetiro($_POST['fecha_hora_retiro'] ?? '');
    $tipo_retirante = trim($_POST['tipo_retirante'] ?? 'DESTINATARIO');
    $observaciones = trim($_POST['observaciones'] ?? '');

    $dni_autorizado = trim($_POST['dni_autorizado'] ?? '');
    $nombre_autorizado = trim($_POST['nombre_autorizado'] ?? '');
    $apellido_autorizado = trim($_POST['apellido_autorizado'] ?? '');
    $telefono_autorizado = trim($_POST['telefono_autorizado'] ?? '');
    $vinculo_autorizado = trim($_POST['vinculo_autorizado'] ?? '');

    if ($tracking === '' || $fecha_hora_retiro === '' || $tipo_retirante === '') {

        $mensaje = 'Completá los datos obligatorios para registrar el retiro.';
        $tipo_mensaje = 'error';

    } elseif (!in_array($tipo_retirante, ['DESTINATARIO', 'AUTORIZADO', 'REMITENTE'], true)) {

        $mensaje = 'El tipo de retirante no es válido.';
        $tipo_mensaje = 'error';

    } elseif (
        $tipo_retirante === 'AUTORIZADO' &&
        (
            $dni_autorizado === '' ||
            $nombre_autorizado === '' ||
            $apellido_autorizado === ''
        )
    ) {

        $mensaje = 'Si retira un autorizado, debés completar al menos DNI, nombre y apellido.';
        $tipo_mensaje = 'error';

    } else {

        try {

            $sqlEnvioValidacion = "
                SELECT
                    e.nro_tracking,
                    e.dni_remitente,
                    e.dni_destinatario,
                    e.cod_sucursal_origen,
                    e.cod_sucursal_destino,

                    cr.nombre AS nombre_remitente,
                    cr.apellido AS apellido_remitente,
                    cd.nombre AS nombre_destinatario,
                    cd.apellido AS apellido_destinatario,

                    dr.cod_sucursal_retiro,
                    dr.fecha_disponible,
                    dr.fecha_limite_retiro,
                    dr.dias_plazo_aplicado,
                    dr.observaciones AS observaciones_disponibilidad,
                    s.nombre AS nombre_sucursal_retiro,

                    re.nro_tracking AS retiro_existente
                FROM Envio e
                INNER JOIN vista_cliente cr
                    ON e.dni_remitente = cr.dni
                INNER JOIN vista_cliente cd
                    ON e.dni_destinatario = cd.dni
                INNER JOIN Disponibilidad_Retiro dr
                    ON e.nro_tracking = dr.nro_tracking
                INNER JOIN Sucursal s
                    ON dr.cod_sucursal_retiro = s.cod_sucursal
                LEFT JOIN Retiro_Envio re
                    ON e.nro_tracking = re.nro_tracking
                WHERE e.nro_tracking = :nro_tracking
                LIMIT 1
            ";

            $stmtEnvioValidacion = $pdo->prepare($sqlEnvioValidacion);
            $stmtEnvioValidacion->execute([
                ':nro_tracking' => $tracking
            ]);

            $envioValidacion = $stmtEnvioValidacion->fetch();

            if (!$envioValidacion) {

                $mensaje = 'No se encontró un envío disponible para retiro con ese tracking.';
                $tipo_mensaje = 'error';

            } elseif (!empty($envioValidacion['retiro_existente'])) {

                $mensaje = 'Este envío ya fue retirado previamente.';
                $tipo_mensaje = 'warning';

            } elseif (
                $rol_actual === 'EMPLEADO_SUCURSAL' &&
                $envioValidacion['cod_sucursal_retiro'] !== $cod_sucursal_empleado
            ) {

                $mensaje = 'No podés registrar el retiro porque pertenece a otra sucursal.';
                $tipo_mensaje = 'error';

            } elseif (strtotime($fecha_hora_retiro) < strtotime($envioValidacion['fecha_disponible'])) {

                $mensaje = 'La fecha de retiro no puede ser anterior a la fecha en que quedó disponible.';
                $tipo_mensaje = 'error';

            } else {

                $es_retorno_validacion = ($envioValidacion['cod_sucursal_retiro'] === $envioValidacion['cod_sucursal_origen']);

                if ($es_retorno_validacion && $tipo_retirante !== 'REMITENTE') {
                    $mensaje = 'Como el envío volvió al origen, el retiro debe registrarse como REMITENTE.';
                    $tipo_mensaje = 'error';
                } elseif (!$es_retorno_validacion && $tipo_retirante === 'REMITENTE') {
                    $mensaje = 'El tipo REMITENTE solo corresponde a envíos devueltos al origen.';
                    $tipo_mensaje = 'error';
                } else {
                    $estado_retirado = obtenerEstadoEnvioRetirado($pdo);

                    if ($estado_retirado === null) {
                        $mensaje = 'No se encontró un estado válido para registrar el retiro del envío.';
                        $tipo_mensaje = 'error';
                    }
                }
            }

            if ($mensaje === '') {

                $pdo->beginTransaction();

                if ($tipo_retirante === 'AUTORIZADO') {

                    $sqlInsertarAutorizado = "
                        INSERT INTO Autorizado_Retiro (
                            nro_tracking,
                            dni_autorizado,
                            nombre,
                            apellido,
                            telefono,
                            vinculo,
                            fecha_autorizacion
                        )
                        VALUES (
                            :nro_tracking,
                            :dni_autorizado,
                            :nombre,
                            :apellido,
                            :telefono,
                            :vinculo,
                            :fecha_autorizacion
                        )
                        ON DUPLICATE KEY UPDATE
                            nombre = VALUES(nombre),
                            apellido = VALUES(apellido),
                            telefono = VALUES(telefono),
                            vinculo = VALUES(vinculo),
                            fecha_autorizacion = VALUES(fecha_autorizacion)
                    ";

                    $stmtInsertarAutorizado = $pdo->prepare($sqlInsertarAutorizado);
                    $stmtInsertarAutorizado->execute([
                        ':nro_tracking' => $tracking,
                        ':dni_autorizado' => $dni_autorizado,
                        ':nombre' => $nombre_autorizado,
                        ':apellido' => $apellido_autorizado,
                        ':telefono' => ($telefono_autorizado !== '' ? $telefono_autorizado : null),
                        ':vinculo' => ($vinculo_autorizado !== '' ? $vinculo_autorizado : null),
                        ':fecha_autorizacion' => $fecha_hora_retiro
                    ]);

                    $sqlInsertarRetiro = "
                        INSERT INTO Retiro_Envio (
                            nro_tracking,
                            fecha_hora_retiro,
                            cod_sucursal_retiro,
                            tipo_retirante,
                            dni_cliente_retirante,
                            dni_autorizado,
                            observaciones
                        )
                        VALUES (
                            :nro_tracking,
                            :fecha_hora_retiro,
                            :cod_sucursal_retiro,
                            'AUTORIZADO',
                            NULL,
                            :dni_autorizado,
                            :observaciones
                        )
                    ";

                    $stmtInsertarRetiro = $pdo->prepare($sqlInsertarRetiro);
                    $stmtInsertarRetiro->execute([
                        ':nro_tracking' => $tracking,
                        ':fecha_hora_retiro' => $fecha_hora_retiro,
                        ':cod_sucursal_retiro' => $envioValidacion['cod_sucursal_retiro'],
                        ':dni_autorizado' => $dni_autorizado,
                        ':observaciones' => ($observaciones !== '' ? $observaciones : null)
                    ]);

                    $observacion_historial = 'Envío retirado por autorizado.';

                } elseif ($tipo_retirante === 'REMITENTE') {

                    // IMPORTANTE:
                    // por la restricción actual de la base, guardamos en dni_cliente_retirante
                    // y tipo_retirante = DESTINATARIO, pero lo mostramos como REMITENTE.
                    $sqlInsertarRetiro = "
                        INSERT INTO Retiro_Envio (
                            nro_tracking,
                            fecha_hora_retiro,
                            cod_sucursal_retiro,
                            tipo_retirante,
                            dni_cliente_retirante,
                            dni_autorizado,
                            observaciones
                        )
                        VALUES (
                            :nro_tracking,
                            :fecha_hora_retiro,
                            :cod_sucursal_retiro,
                            'DESTINATARIO',
                            :dni_cliente_retirante,
                            NULL,
                            :observaciones
                        )
                    ";

                    $obs_remitente = 'Retiro realizado por remitente tras devolución al origen.';
                    if ($observaciones !== '') {
                        $obs_remitente .= ' ' . $observaciones;
                    }

                    $stmtInsertarRetiro = $pdo->prepare($sqlInsertarRetiro);
                    $stmtInsertarRetiro->execute([
                        ':nro_tracking' => $tracking,
                        ':fecha_hora_retiro' => $fecha_hora_retiro,
                        ':cod_sucursal_retiro' => $envioValidacion['cod_sucursal_retiro'],
                        ':dni_cliente_retirante' => $envioValidacion['dni_remitente'],
                        ':observaciones' => $obs_remitente
                    ]);

                    $observacion_historial = 'Envío retirado por remitente luego de devolución al origen.';

                } else {

                    $sqlInsertarRetiro = "
                        INSERT INTO Retiro_Envio (
                            nro_tracking,
                            fecha_hora_retiro,
                            cod_sucursal_retiro,
                            tipo_retirante,
                            dni_cliente_retirante,
                            dni_autorizado,
                            observaciones
                        )
                        VALUES (
                            :nro_tracking,
                            :fecha_hora_retiro,
                            :cod_sucursal_retiro,
                            'DESTINATARIO',
                            :dni_cliente_retirante,
                            NULL,
                            :observaciones
                        )
                    ";

                    $stmtInsertarRetiro = $pdo->prepare($sqlInsertarRetiro);
                    $stmtInsertarRetiro->execute([
                        ':nro_tracking' => $tracking,
                        ':fecha_hora_retiro' => $fecha_hora_retiro,
                        ':cod_sucursal_retiro' => $envioValidacion['cod_sucursal_retiro'],
                        ':dni_cliente_retirante' => $envioValidacion['dni_destinatario'],
                        ':observaciones' => ($observaciones !== '' ? $observaciones : null)
                    ]);

                    $observacion_historial = 'Envío retirado por destinatario.';
                }

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
                    ':cod_estado_envio' => $estado_retirado,
                    ':fecha_hora' => $fecha_hora_retiro,
                    ':cod_sucursal_actual' => $envioValidacion['cod_sucursal_retiro'],
                    ':observaciones' => $observacion_historial
                ]);
                $stmtInsertarHistorial->closeCursor();

                $pdo->commit();

                $mensaje = 'Retiro registrado correctamente.';
                $tipo_mensaje = 'success';

                $tipo_retirante = 'DESTINATARIO';
                $observaciones = '';
                $dni_autorizado = '';
                $nombre_autorizado = '';
                $apellido_autorizado = '';
                $telefono_autorizado = '';
                $vinculo_autorizado = '';
                $fecha_hora_retiro = date('Y-m-d H:i:s');
            }

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $mensaje = 'Ocurrió un error al registrar el retiro del envío.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 3. CARGAR DETALLE DEL TRACKING SELECCIONADO
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

                dr.cod_sucursal_retiro,
                dr.fecha_disponible,
                dr.fecha_limite_retiro,
                dr.dias_plazo_aplicado,
                dr.observaciones AS observaciones_disponibilidad,
                sr.nombre AS nombre_sucursal_retiro,
                sr.direccion AS direccion_sucursal_retiro,

                he.cod_estado_envio AS cod_estado_actual,
                ee.nombre AS nombre_estado_actual,
                he.fecha_hora AS fecha_estado_actual,

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
            INNER JOIN Disponibilidad_Retiro dr
                ON e.nro_tracking = dr.nro_tracking
            INNER JOIN Sucursal sr
                ON dr.cod_sucursal_retiro = sr.cod_sucursal

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

            $es_retorno_origen = ($envio['cod_sucursal_retiro'] === $envio['cod_sucursal_origen']);

            if ($es_retorno_origen) {
                $tipo_retirante = 'REMITENTE';
            } elseif ($tipo_retirante === 'REMITENTE') {
                $tipo_retirante = 'DESTINATARIO';
            }

            if (
                $rol_actual === 'EMPLEADO_SUCURSAL' &&
                $envio['cod_sucursal_retiro'] !== $cod_sucursal_empleado
            ) {
                $envio = null;
                $mensaje = 'No podés operar este tracking porque pertenece a otra sucursal.';
                $tipo_mensaje = 'error';
            } else {

                if (!empty($envio['retiro_existente'])) {
                    $puede_registrar = false;
                    $motivo_no_registrar = 'Este envío ya fue retirado.';
                } else {
                    $puede_registrar = true;
                    $motivo_no_registrar = '';
                }

                $sqlRetiroRealizado = "
                    SELECT
                        re.nro_tracking,
                        re.fecha_hora_retiro,
                        re.cod_sucursal_retiro,
                        re.tipo_retirante,
                        re.dni_cliente_retirante,
                        re.dni_autorizado,
                        re.observaciones,

                        c.nombre AS nombre_cliente_retirante,
                        c.apellido AS apellido_cliente_retirante,

                        ar.nombre AS nombre_autorizado,
                        ar.apellido AS apellido_autorizado,
                        ar.telefono AS telefono_autorizado,
                        ar.vinculo AS vinculo_autorizado
                    FROM Retiro_Envio re
                    LEFT JOIN vista_cliente c
                        ON re.dni_cliente_retirante = c.dni
                    LEFT JOIN Autorizado_Retiro ar
                        ON re.nro_tracking = ar.nro_tracking
                       AND re.dni_autorizado = ar.dni_autorizado
                    WHERE re.nro_tracking = :nro_tracking
                    LIMIT 1
                ";

                $stmtRetiroRealizado = $pdo->prepare($sqlRetiroRealizado);
                $stmtRetiroRealizado->execute([
                    ':nro_tracking' => $tracking
                ]);

                $retiro_realizado = $stmtRetiroRealizado->fetch();
            }

        } else {
            if ($mensaje === '') {
                $mensaje = 'No se encontró un envío disponible para retiro con ese tracking.';
                $tipo_mensaje = 'warning';
            }
        }

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar el detalle del envío.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 4. CARGAR LISTADO DE PENDIENTES DE RETIRO
// -----------------------------------------------------

try {

    $sqlPendientes = "
        SELECT
            e.nro_tracking,
            e.fecha_recepcion,
            e.dni_destinatario,
            e.dni_remitente,
            e.cod_sucursal_origen,

            cd.nombre AS nombre_destinatario,
            cd.apellido AS apellido_destinatario,
            cr.nombre AS nombre_remitente,
            cr.apellido AS apellido_remitente,

            dr.fecha_disponible,
            dr.fecha_limite_retiro,
            dr.cod_sucursal_retiro,
            sr.nombre AS nombre_sucursal_retiro,

            he.cod_estado_envio AS cod_estado_actual,
            ee.nombre AS nombre_estado_actual
        FROM Envio e
        INNER JOIN vista_cliente cd
            ON e.dni_destinatario = cd.dni
        INNER JOIN vista_cliente cr
            ON e.dni_remitente = cr.dni
        INNER JOIN Disponibilidad_Retiro dr
            ON e.nro_tracking = dr.nro_tracking
        INNER JOIN Sucursal sr
            ON dr.cod_sucursal_retiro = sr.cod_sucursal
        LEFT JOIN Retiro_Envio re
            ON e.nro_tracking = re.nro_tracking

        LEFT JOIN (
            SELECT
                h1.nro_tracking,
                h1.cod_estado_envio
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

        WHERE re.nro_tracking IS NULL
    ";

    $paramsPendientes = [];

    if ($rol_actual === 'EMPLEADO_SUCURSAL' && $cod_sucursal_empleado !== '') {
        $sqlPendientes .= " AND dr.cod_sucursal_retiro = :cod_sucursal_retiro ";
        $paramsPendientes[':cod_sucursal_retiro'] = $cod_sucursal_empleado;
    }

    $sqlPendientes .= "
        ORDER BY dr.fecha_disponible DESC, e.nro_tracking DESC
        LIMIT 25
    ";

    $stmtPendientes = $pdo->prepare($sqlPendientes);
    $stmtPendientes->execute($paramsPendientes);

    $pendientes_retiro = $stmtPendientes->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'Ocurrió un error al cargar los envíos pendientes de retiro.';
    $tipo_mensaje = 'error';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Registrar Retiro</h1>
        <p class="page-subtitle">
            Registrá quién retiró realmente el envío al momento de la entrega en sucursal.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>

    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Buscar tracking para registrar retiro</h3>

        <?php if ($rol_actual === 'EMPLEADO_SUCURSAL' && $cod_sucursal_empleado !== ''): ?>
            <p class="field-note" style="margin-bottom: 14px;">
                Estás operando como sucursal:
                <strong><?php echo htmlspecialchars($cod_sucursal_empleado . ' - ' . $nombre_sucursal_empleado); ?></strong>
            </p>
        <?php endif; ?>

        <form method="GET" action="registrar_retiro.php">

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

                    <a href="registrar_retiro.php" class="btn-public-secondary">
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
                <p><strong>Estado actual:</strong> <?php echo htmlspecialchars($envio['nombre_estado_actual'] ?? ''); ?></p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Datos del retiro</h3>

                <p><strong>Sucursal de retiro:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_retiro']); ?></p>
                <p><strong>Dirección:</strong> <?php echo htmlspecialchars($envio['direccion_sucursal_retiro']); ?></p>
                <p><strong>Fecha disponible:</strong> <?php echo htmlspecialchars($envio['fecha_disponible']); ?></p>
                <p><strong>Fecha límite:</strong> <?php echo htmlspecialchars($envio['fecha_limite_retiro']); ?></p>
                <p><strong>Observaciones:</strong> <?php echo htmlspecialchars($envio['observaciones_disponibilidad'] ?? ''); ?></p>

                <?php if ($es_retorno_origen): ?>
                    <p><strong>Modo de retiro esperado:</strong> Remitente (envío devuelto al origen)</p>
                <?php else: ?>
                    <p><strong>Modo de retiro esperado:</strong> Destinatario o autorizado</p>
                <?php endif; ?>
            </article>

        </section>


        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Registrar retiro real</h3>

            <?php if ($puede_registrar): ?>

                <form method="POST" action="registrar_retiro.php?tracking=<?php echo urlencode($tracking); ?>">

                    <input type="hidden" name="accion" value="registrar_retiro">
                    <input type="hidden" name="tracking" value="<?php echo htmlspecialchars($tracking); ?>">

                    <div class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">

                        <div>

                            <div class="form-group">
                                <label for="fecha_hora_retiro">Fecha y hora del retiro</label>
                                <input
                                    type="datetime-local"
                                    id="fecha_hora_retiro"
                                    name="fecha_hora_retiro"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars(fechaRetiroParaInput($fecha_hora_retiro)); ?>"
                                    required
                                >
                            </div>

                            <div class="form-group">
                                <label for="tipo_retirante">¿Quién retira?</label>
                                <select id="tipo_retirante" name="tipo_retirante" class="form-control" required>

                                    <?php if ($es_retorno_origen): ?>

                                        <option value="REMITENTE" <?php echo $tipo_retirante === 'REMITENTE' ? 'selected' : ''; ?>>
                                            Remitente
                                        </option>

                                    <?php else: ?>

                                        <option value="DESTINATARIO" <?php echo $tipo_retirante === 'DESTINATARIO' ? 'selected' : ''; ?>>
                                            Destinatario
                                        </option>
                                        <option value="AUTORIZADO" <?php echo $tipo_retirante === 'AUTORIZADO' ? 'selected' : ''; ?>>
                                            Autorizado
                                        </option>

                                    <?php endif; ?>

                                </select>
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

                        <div id="bloque_autorizado">

                            <div class="form-group">
                                <label for="dni_autorizado">DNI del autorizado</label>
                                <input
                                    type="text"
                                    id="dni_autorizado"
                                    name="dni_autorizado"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($dni_autorizado); ?>"
                                >
                            </div>

                            <div class="form-group">
                                <label for="nombre_autorizado">Nombre</label>
                                <input
                                    type="text"
                                    id="nombre_autorizado"
                                    name="nombre_autorizado"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($nombre_autorizado); ?>"
                                >
                            </div>

                            <div class="form-group">
                                <label for="apellido_autorizado">Apellido</label>
                                <input
                                    type="text"
                                    id="apellido_autorizado"
                                    name="apellido_autorizado"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($apellido_autorizado); ?>"
                                >
                            </div>

                            <div class="form-group">
                                <label for="telefono_autorizado">Teléfono</label>
                                <input
                                    type="text"
                                    id="telefono_autorizado"
                                    name="telefono_autorizado"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($telefono_autorizado); ?>"
                                >
                            </div>

                            <div class="form-group">
                                <label for="vinculo_autorizado">Vínculo</label>
                                <input
                                    type="text"
                                    id="vinculo_autorizado"
                                    name="vinculo_autorizado"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($vinculo_autorizado); ?>"
                                >
                            </div>

                        </div>

                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 18px; flex-wrap: wrap;">
                        <button type="submit" class="btn-primary" style="width: auto;">
                            Confirmar retiro
                        </button>
                    </div>

                </form>

            <?php else: ?>

                <div class="alert alert-warning" style="margin: 0;">
                    <?php echo htmlspecialchars($motivo_no_registrar !== '' ? $motivo_no_registrar : 'Este envío no se puede registrar desde esta pantalla.'); ?>
                </div>

            <?php endif; ?>

        </section>


        <?php if ($retiro_realizado): ?>

            <section class="dashboard-card" style="margin-bottom: 24px;">

                <h3 style="margin-top: 0; margin-bottom: 18px;">Constancia de retiro</h3>

                <div class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">

                    <article>
                        <p><strong>Fecha y hora:</strong> <?php echo htmlspecialchars($retiro_realizado['fecha_hora_retiro']); ?></p>
                    </article>

                    <article>
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

                        <?php if ($retiro_realizado['tipo_retirante'] === 'AUTORIZADO'): ?>

                            <p><strong>Tipo de retirante:</strong> AUTORIZADO</p>
                            <p><strong>DNI autorizado:</strong> <?php echo htmlspecialchars($retiro_realizado['dni_autorizado'] ?? ''); ?></p>
                            <p>
                                <strong>Nombre:</strong>
                                <?php echo htmlspecialchars(($retiro_realizado['apellido_autorizado'] ?? '') . ', ' . ($retiro_realizado['nombre_autorizado'] ?? '')); ?>
                            </p>
                            <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($retiro_realizado['telefono_autorizado'] ?? ''); ?></p>
                            <p><strong>Vínculo:</strong> <?php echo htmlspecialchars($retiro_realizado['vinculo_autorizado'] ?? ''); ?></p>

                        <?php elseif ($es_remitente_real): ?>

                            <p><strong>Tipo de retirante:</strong> REMITENTE</p>
                            <p><strong>DNI remitente:</strong> <?php echo htmlspecialchars($envio['dni_remitente']); ?></p>
                            <p><strong>Nombre:</strong> <?php echo htmlspecialchars($envio['apellido_remitente'] . ', ' . $envio['nombre_remitente']); ?></p>

                        <?php elseif ($es_destinatario_real): ?>

                            <p><strong>Tipo de retirante:</strong> DESTINATARIO</p>
                            <p><strong>DNI retirante:</strong> <?php echo htmlspecialchars($retiro_realizado['dni_cliente_retirante'] ?? ''); ?></p>
                            <p>
                                <strong>Nombre:</strong>
                                <?php echo htmlspecialchars(($retiro_realizado['apellido_cliente_retirante'] ?? '') . ', ' . ($retiro_realizado['nombre_cliente_retirante'] ?? '')); ?>
                            </p>

                        <?php else: ?>

                            <p><strong>Tipo de retirante:</strong> CLIENTE</p>
                            <p><strong>DNI retirante:</strong> <?php echo htmlspecialchars($retiro_realizado['dni_cliente_retirante'] ?? ''); ?></p>

                        <?php endif; ?>

                        <p><strong>Observaciones:</strong> <?php echo htmlspecialchars($retiro_realizado['observaciones'] ?? ''); ?></p>
                    </article>

                </div>

            </section>

        <?php endif; ?>

    <?php endif; ?>


    <section class="dashboard-card">

        <h3 style="margin-top: 0; margin-bottom: 18px;">
            <?php echo $rol_actual === 'ADMIN' ? 'Envíos pendientes de retiro' : 'Envíos pendientes de retiro en mi sucursal'; ?>
        </h3>

        <div style="overflow-x: auto;">

            <table style="width: 100%; border-collapse: collapse; min-width: 1500px;">

                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tracking</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha envío</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Persona esperada</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Sucursal retiro</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Disponible desde</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha límite</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Estado actual</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Acción</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($pendientes_retiro)): ?>

                        <tr>
                            <td colspan="8" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                No hay envíos pendientes de retiro en este momento.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($pendientes_retiro as $pendiente): ?>
                            <?php $es_retorno_pendiente = ($pendiente['cod_sucursal_retiro'] === $pendiente['cod_sucursal_origen']); ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['nro_tracking']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['fecha_recepcion']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php
                                    if ($es_retorno_pendiente) {
                                        echo htmlspecialchars($pendiente['dni_remitente'] . ' - ' . $pendiente['apellido_remitente'] . ', ' . $pendiente['nombre_remitente'] . ' (REMITENTE)');
                                    } else {
                                        echo htmlspecialchars($pendiente['dni_destinatario'] . ' - ' . $pendiente['apellido_destinatario'] . ', ' . $pendiente['nombre_destinatario'] . ' (DESTINATARIO)');
                                    }
                                    ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['nombre_sucursal_retiro']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['fecha_disponible']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['fecha_limite_retiro']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['nombre_estado_actual'] ?? ''); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border); white-space: nowrap;">
                                    <a href="registrar_retiro.php?tracking=<?php echo urlencode($pendiente['nro_tracking']); ?>" class="btn-public-secondary">
                                        Ver y registrar
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tipoRetirante = document.getElementById('tipo_retirante');
    const bloqueAutorizado = document.getElementById('bloque_autorizado');

    function actualizarFormularioRetiro() {
        if (!tipoRetirante || !bloqueAutorizado) {
            return;
        }

        if (tipoRetirante.value === 'AUTORIZADO') {
            bloqueAutorizado.style.display = 'block';
        } else {
            bloqueAutorizado.style.display = 'none';
        }
    }

    if (tipoRetirante) {
        tipoRetirante.addEventListener('change', actualizarFormularioRetiro);
        actualizarFormularioRetiro();
    }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
