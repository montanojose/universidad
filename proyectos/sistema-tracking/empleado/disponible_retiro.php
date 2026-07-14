<?php
// =====================================================
// disponible_retiro.php
// Marca un envío como disponible para retiro
// - acceso para EMPLEADO_SUCURSAL y ADMIN
// - EMPLEADO: solo opera en su sucursal
// - ADMIN: puede probar cualquier envío
// - inserta en Disponibilidad_Retiro
// - registra estado en Historial_Estado
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'EMPLEADO_SUCURSAL']);

$titulo_pagina = 'Marcar Disponible para Retiro';

$mensaje = '';
$tipo_mensaje = '';

$rol_actual = $_SESSION['usuario_rol'] ?? '';

$tracking = trim($_GET['tracking'] ?? '');

$cod_sucursal_empleado = '';
$nombre_sucursal_empleado = '';

$fecha_disponible = date('Y-m-d H:i:s');
$dias_plazo_aplicado = '7';
$observaciones = '';
$fecha_hora_incidente = date('Y-m-d H:i:s');
$cod_tipo_incidente = '';
$descripcion_incidente = '';

$envio = null;
$disponibilidad_actual = null;
$retiro_realizado = null;
$envios_elegibles = [];
$tipos_incidente = [];

$puede_marcar = false;
$motivo_no_marcar = '';


// -----------------------------------------------------
// FUNCIONES AUXILIARES
// -----------------------------------------------------

function obtenerLegajoEmpleadoSesionDisponible(): string
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

function normalizarFechaDatetimeLocalDisponible(string $valor): string
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

function fechaDisponibleParaInput(?string $fecha): string
{
    if (!$fecha) {
        return '';
    }

    return date('Y-m-d\TH:i', strtotime($fecha));
}

function calcularFechaLimiteRetiro(string $fecha_disponible, int $dias_plazo): string
{
    $fecha = new DateTime($fecha_disponible);
    $fecha->modify('+' . $dias_plazo . ' day');

    return $fecha->format('Y-m-d H:i:s');
}

function buscarCodigoCatalogoDisponible(PDO $pdo, string $tabla, string $columna_codigo, string $columna_nombre, array $candidatos): ?string
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

function obtenerEstadoDisponibleRetiro(PDO $pdo): ?string
{
    return buscarCodigoCatalogoDisponible(
        $pdo,
        'Estado_Envio',
        'cod_estado_envio',
        'nombre',
        ['DISPONIBLE_PARA_RETIRO', 'DISPONIBLE_RETIRO', 'DISPONIBLE']
    );
}

function obtenerEstadoIncidenteDisponible(PDO $pdo): ?string
{
    return buscarCodigoCatalogoDisponible(
        $pdo,
        'Estado_Envio',
        'cod_estado_envio',
        'nombre',
        ['INCIDENTE_EN_TRANSITO', 'INCIDENTE', 'PAQUETE_DANADO']
    );
}

function obtenerProximoNumeroIncidenteDisponible(PDO $pdo, string $patente, string $fecha_salida): int
{
    $sql = "
        SELECT COALESCE(MAX(nro_incidente), 0) AS max_incidente
        FROM Incidente
        WHERE patente = :patente
          AND fecha_salida = :fecha_salida
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':patente' => $patente,
        ':fecha_salida' => $fecha_salida
    ]);

    $fila = $stmt->fetch();

    return ((int) ($fila['max_incidente'] ?? 0)) + 1;
}

function esEstadoElegibleParaDisponible(?string $codigo, ?string $nombre): bool
{
    $codigo_normalizado = strtoupper(str_replace(' ', '_', (string) $codigo));
    $nombre_normalizado = strtoupper(str_replace(' ', '_', (string) $nombre));

    $permitidos = [
        'ARRIBADO_A_SUCURSAL_DESTINO',
        'RECIBIDO_EN_SUCURSAL_DESTINO',
        'EN_SUCURSAL_DESTINO',
        'ARRIBADO_A_DESTINO',
        'LLEGADO_A_SUCURSAL_DESTINO',
        'RECIBIDO_EN_SUCURSAL'
    ];

    return in_array($codigo_normalizado, $permitidos, true) || in_array($nombre_normalizado, $permitidos, true);
}


// -----------------------------------------------------
// 1. OBTENER SUCURSAL DEL EMPLEADO SI CORRESPONDE
// -----------------------------------------------------

if ($rol_actual === 'EMPLEADO_SUCURSAL') {

    $legajo_empleado_sesion = obtenerLegajoEmpleadoSesionDisponible();

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


try {
    $tipos_incidente = $pdo->query("
        SELECT cod_tipo_incidente, nombre
        FROM Tipo_Incidente
        ORDER BY nombre ASC
    ")->fetchAll();
} catch (PDOException $e) {
    if ($mensaje === '') {
        $mensaje = 'No se pudieron cargar los tipos de incidente.';
        $tipo_mensaje = 'error';
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'registrar_incidente_llegada') {

    $tracking = trim($_POST['tracking'] ?? '');
    $fecha_hora_incidente = normalizarFechaDatetimeLocalDisponible($_POST['fecha_hora_incidente'] ?? '');
    $cod_tipo_incidente = trim($_POST['cod_tipo_incidente'] ?? '');
    $descripcion_incidente = trim($_POST['descripcion_incidente'] ?? '');

    if ($tracking === '' || $fecha_hora_incidente === '' || $cod_tipo_incidente === '' || $descripcion_incidente === '') {

        $mensaje = 'Completa todos los datos del incidente detectado.';
        $tipo_mensaje = 'error';

    } else {

        try {

            $sqlValidacionIncidente = "
                SELECT
                    e.nro_tracking,
                    e.cod_sucursal_destino,
                    he.cod_estado_envio AS cod_estado_actual,
                    ee.nombre AS nombre_estado_actual,
                    ve.patente,
                    ve.fecha_salida,
                    dr.nro_tracking AS ya_disponible,
                    re.nro_tracking AS ya_retirado
                FROM Envio e
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
                LEFT JOIN Viaje_Envio ve
                    ON e.nro_tracking = ve.nro_tracking
                LEFT JOIN Disponibilidad_Retiro dr
                    ON e.nro_tracking = dr.nro_tracking
                LEFT JOIN Retiro_Envio re
                    ON e.nro_tracking = re.nro_tracking
                WHERE e.nro_tracking = :nro_tracking
                LIMIT 1
            ";

            $stmtValidacionIncidente = $pdo->prepare($sqlValidacionIncidente);
            $stmtValidacionIncidente->execute([
                ':nro_tracking' => $tracking
            ]);

            $validacionIncidente = $stmtValidacionIncidente->fetch();

            if (!$validacionIncidente) {
                $mensaje = 'No se encontro el envio indicado.';
                $tipo_mensaje = 'error';
            } elseif (
                $rol_actual === 'EMPLEADO_SUCURSAL' &&
                $validacionIncidente['cod_sucursal_destino'] !== $cod_sucursal_empleado
            ) {
                $mensaje = 'No podes registrar incidentes de un envio de otra sucursal destino.';
                $tipo_mensaje = 'error';
            } elseif (!empty($validacionIncidente['ya_disponible'])) {
                $mensaje = 'Este envio ya fue marcado como disponible para retiro.';
                $tipo_mensaje = 'warning';
            } elseif (!empty($validacionIncidente['ya_retirado'])) {
                $mensaje = 'Este envio ya fue retirado.';
                $tipo_mensaje = 'warning';
            } elseif (empty($validacionIncidente['patente']) || empty($validacionIncidente['fecha_salida'])) {
                $mensaje = 'No se encontro el viaje asociado a este envio para registrar el incidente.';
                $tipo_mensaje = 'error';
            } elseif (!esEstadoElegibleParaDisponible($validacionIncidente['cod_estado_actual'], $validacionIncidente['nombre_estado_actual'])) {
                $mensaje = 'El envio todavia no llego a destino; no corresponde registrar incidente de recepcion.';
                $tipo_mensaje = 'error';
            } else {
                $estado_incidente = obtenerEstadoIncidenteDisponible($pdo);

                if ($estado_incidente === null) {
                    $mensaje = 'No se encontro un estado de incidente valido para el envio.';
                    $tipo_mensaje = 'error';
                }
            }

            if ($mensaje === '') {

                $pdo->beginTransaction();

                $nro_incidente = obtenerProximoNumeroIncidenteDisponible(
                    $pdo,
                    $validacionIncidente['patente'],
                    $validacionIncidente['fecha_salida']
                );

                $sqlInsertarIncidente = "
                    INSERT INTO Incidente (
                        patente,
                        fecha_salida,
                        nro_incidente,
                        cod_tipo_incidente,
                        descripcion,
                        fecha_hora
                    )
                    VALUES (
                        :patente,
                        :fecha_salida,
                        :nro_incidente,
                        :cod_tipo_incidente,
                        :descripcion,
                        :fecha_hora
                    )
                ";

                $stmtInsertarIncidente = $pdo->prepare($sqlInsertarIncidente);
                $stmtInsertarIncidente->execute([
                    ':patente' => $validacionIncidente['patente'],
                    ':fecha_salida' => $validacionIncidente['fecha_salida'],
                    ':nro_incidente' => $nro_incidente,
                    ':cod_tipo_incidente' => $cod_tipo_incidente,
                    ':descripcion' => $descripcion_incidente,
                    ':fecha_hora' => $fecha_hora_incidente
                ]);

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
                    ':nro_tracking' => $tracking,
                    ':cod_estado_envio' => $estado_incidente,
                    ':fecha_hora' => $fecha_hora_incidente,
                    ':cod_sucursal_actual' => $validacionIncidente['cod_sucursal_destino'],
                    ':patente' => $validacionIncidente['patente'],
                    ':fecha_salida' => $validacionIncidente['fecha_salida'],
                    ':observaciones' => 'Incidente detectado al verificar llegada: ' . $descripcion_incidente
                ]);
                $stmtInsertarHistorial->closeCursor();

                $pdo->commit();

                $mensaje = 'Incidente registrado correctamente. El envio no quedo disponible para retiro.';
                $tipo_mensaje = 'success';
                $cod_tipo_incidente = '';
                $descripcion_incidente = '';
                $fecha_hora_incidente = date('Y-m-d H:i:s');
            }

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $mensaje = 'Ocurrio un error al registrar el incidente detectado.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 2. PROCESAR MARCADO COMO DISPONIBLE
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'marcar_disponible') {

    $tracking = trim($_POST['tracking'] ?? '');
    $fecha_disponible = normalizarFechaDatetimeLocalDisponible($_POST['fecha_disponible'] ?? '');
    $dias_plazo_aplicado = trim($_POST['dias_plazo_aplicado'] ?? '7');
    $observaciones = trim($_POST['observaciones'] ?? '');
    $verificacion_ok = ($_POST['verificacion_ok'] ?? '') === '1';

    if ($tracking === '' || $fecha_disponible === '' || $dias_plazo_aplicado === '') {

        $mensaje = 'Completá los datos obligatorios para marcar el envío como disponible.';
        $tipo_mensaje = 'error';

    } elseif (!ctype_digit($dias_plazo_aplicado) || (int) $dias_plazo_aplicado <= 0) {

        $mensaje = 'El plazo aplicado debe ser un número entero mayor que cero.';
        $tipo_mensaje = 'error';

    } elseif (!$verificacion_ok) {

        $mensaje = 'Antes de marcar disponible, verifica que todos los paquetes llegaron sin roturas ni incidentes.';
        $tipo_mensaje = 'error';

    } else {

        try {

            $sqlValidacion = "
                SELECT
                    e.nro_tracking,
                    e.cod_sucursal_destino,

                    cd.nombre AS nombre_destinatario,
                    cd.apellido AS apellido_destinatario,

                    he.cod_estado_envio AS cod_estado_actual,
                    ee.nombre AS nombre_estado_actual,
                    he.fecha_hora AS fecha_estado_actual,

                    dr.nro_tracking AS ya_disponible,
                    re.nro_tracking AS ya_retirado
                FROM Envio e
                INNER JOIN vista_cliente cd
                    ON e.dni_destinatario = cd.dni

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

                $mensaje = 'No se encontró el envío indicado.';
                $tipo_mensaje = 'error';

            } elseif (
                $rol_actual === 'EMPLEADO_SUCURSAL' &&
                $validacion['cod_sucursal_destino'] !== $cod_sucursal_empleado
            ) {

                $mensaje = 'No podés operar este envío porque pertenece a otra sucursal destino.';
                $tipo_mensaje = 'error';

            } elseif (!empty($validacion['ya_disponible'])) {

                $mensaje = 'Este envío ya fue marcado previamente como disponible para retiro.';
                $tipo_mensaje = 'warning';

            } elseif (!empty($validacion['ya_retirado'])) {

                $mensaje = 'Este envío ya fue retirado.';
                $tipo_mensaje = 'warning';

            } elseif (!esEstadoElegibleParaDisponible($validacion['cod_estado_actual'], $validacion['nombre_estado_actual'])) {

                $mensaje = 'El envío todavía no está en una etapa válida para quedar disponible para retiro.';
                $tipo_mensaje = 'error';

            } else {

                $estado_disponible = obtenerEstadoDisponibleRetiro($pdo);

                if ($estado_disponible === null) {
                    $mensaje = 'No se encontró un estado válido para marcar el envío como disponible para retiro.';
                    $tipo_mensaje = 'error';
                }
            }

            if ($mensaje === '') {

                $fecha_limite_retiro = calcularFechaLimiteRetiro($fecha_disponible, (int) $dias_plazo_aplicado);

                $pdo->beginTransaction();

                $sqlInsertarDisponibilidad = "
                    INSERT INTO Disponibilidad_Retiro (
                        nro_tracking,
                        cod_sucursal_retiro,
                        fecha_disponible,
                        fecha_limite_retiro,
                        dias_plazo_aplicado,
                        fecha_vencimiento_procesado,
                        observaciones
                    )
                    VALUES (
                        :nro_tracking,
                        :cod_sucursal_retiro,
                        :fecha_disponible,
                        :fecha_limite_retiro,
                        :dias_plazo_aplicado,
                        NULL,
                        :observaciones
                    )
                ";

                $stmtInsertarDisponibilidad = $pdo->prepare($sqlInsertarDisponibilidad);
                $stmtInsertarDisponibilidad->execute([
                    ':nro_tracking' => $tracking,
                    ':cod_sucursal_retiro' => $validacion['cod_sucursal_destino'],
                    ':fecha_disponible' => $fecha_disponible,
                    ':fecha_limite_retiro' => $fecha_limite_retiro,
                    ':dias_plazo_aplicado' => (int) $dias_plazo_aplicado,
                    ':observaciones' => ($observaciones !== '' ? $observaciones : null)
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
                    ':cod_estado_envio' => $estado_disponible,
                    ':fecha_hora' => $fecha_disponible,
                    ':cod_sucursal_actual' => $validacion['cod_sucursal_destino'],
                    ':observaciones' => ($observaciones !== '' ? $observaciones : 'Envío disponible para retiro en sucursal destino')
                ]);
                $stmtInsertarHistorial->closeCursor();

                $pdo->commit();

                $mensaje = 'El envío fue marcado correctamente como disponible para retiro.';
                $tipo_mensaje = 'success';

                $fecha_disponible = date('Y-m-d H:i:s');
                $dias_plazo_aplicado = '7';
                $observaciones = '';
            }

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $mensaje = 'Ocurrió un error al marcar el envío como disponible para retiro.';
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

        if ($envio) {

            if (
                $rol_actual === 'EMPLEADO_SUCURSAL' &&
                $envio['cod_sucursal_destino'] !== $cod_sucursal_empleado
            ) {
                $envio = null;
                $mensaje = 'No podés operar este tracking porque pertenece a otra sucursal destino.';
                $tipo_mensaje = 'error';
            } else {

                $sqlDisponibilidadActual = "
                    SELECT
                        dr.nro_tracking,
                        dr.cod_sucursal_retiro,
                        dr.fecha_disponible,
                        dr.fecha_limite_retiro,
                        dr.dias_plazo_aplicado,
                        dr.observaciones,
                        s.nombre AS nombre_sucursal_retiro,
                        s.direccion AS direccion_sucursal_retiro
                    FROM Disponibilidad_Retiro dr
                    INNER JOIN Sucursal s
                        ON dr.cod_sucursal_retiro = s.cod_sucursal
                    WHERE dr.nro_tracking = :nro_tracking
                    LIMIT 1
                ";

                $stmtDisponibilidadActual = $pdo->prepare($sqlDisponibilidadActual);
                $stmtDisponibilidadActual->execute([
                    ':nro_tracking' => $tracking
                ]);

                $disponibilidad_actual = $stmtDisponibilidadActual->fetch();

                $sqlRetiroRealizado = "
                    SELECT
                        re.nro_tracking,
                        re.fecha_hora_retiro
                    FROM Retiro_Envio re
                    WHERE re.nro_tracking = :nro_tracking
                    LIMIT 1
                ";

                $stmtRetiroRealizado = $pdo->prepare($sqlRetiroRealizado);
                $stmtRetiroRealizado->execute([
                    ':nro_tracking' => $tracking
                ]);

                $retiro_realizado = $stmtRetiroRealizado->fetch();

                if ($retiro_realizado) {
                    $puede_marcar = false;
                    $motivo_no_marcar = 'Este envío ya fue retirado.';
                } elseif ($disponibilidad_actual) {
                    $puede_marcar = false;
                    $motivo_no_marcar = 'Este envío ya está marcado como disponible para retiro.';
                } elseif (!esEstadoElegibleParaDisponible($envio['cod_estado_actual'], $envio['nombre_estado_actual'])) {
                    $puede_marcar = false;
                    $motivo_no_marcar = 'El envío todavía no llegó a una etapa válida para retiro.';
                } else {
                    $puede_marcar = true;
                    $motivo_no_marcar = '';
                }
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
// 4. CARGAR ENVÍOS ELEGIBLES
// -----------------------------------------------------

try {

    $sqlElegibles = "
        SELECT
            e.nro_tracking,
            e.fecha_recepcion,
            e.dni_destinatario,
            cd.nombre AS nombre_destinatario,
            cd.apellido AS apellido_destinatario,
            sd.nombre AS nombre_sucursal_destino,
            he.cod_estado_envio AS cod_estado_actual,
            ee.nombre AS nombre_estado_actual
        FROM Envio e
        INNER JOIN vista_cliente cd
            ON e.dni_destinatario = cd.dni
        INNER JOIN Sucursal sd
            ON e.cod_sucursal_destino = sd.cod_sucursal

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

        LEFT JOIN Disponibilidad_Retiro dr
            ON e.nro_tracking = dr.nro_tracking

        LEFT JOIN Retiro_Envio re
            ON e.nro_tracking = re.nro_tracking

        WHERE dr.nro_tracking IS NULL
          AND re.nro_tracking IS NULL
    ";

    $paramsElegibles = [];

    if ($rol_actual === 'EMPLEADO_SUCURSAL' && $cod_sucursal_empleado !== '') {
        $sqlElegibles .= " AND e.cod_sucursal_destino = :cod_sucursal_destino ";
        $paramsElegibles[':cod_sucursal_destino'] = $cod_sucursal_empleado;
    }

    $sqlElegibles .= "
        ORDER BY e.fecha_recepcion DESC, e.nro_tracking DESC
    ";

    $stmtElegibles = $pdo->prepare($sqlElegibles);
    $stmtElegibles->execute($paramsElegibles);

    $todos_elegibles = $stmtElegibles->fetchAll();

    foreach ($todos_elegibles as $item) {
        if (esEstadoElegibleParaDisponible($item['cod_estado_actual'], $item['nombre_estado_actual'])) {
            $envios_elegibles[] = $item;
        }
    }

} catch (PDOException $e) {

    $mensaje = 'Ocurrió un error al cargar los envíos elegibles para retiro.';
    $tipo_mensaje = 'error';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Marcar Disponible para Retiro</h1>
        <p class="page-subtitle">
            Confirmá que el envío ya está listo en la sucursal destino para que luego pueda ser retirado.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>

    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Buscar tracking para marcar disponible</h3>

        <?php if ($rol_actual === 'EMPLEADO_SUCURSAL' && $cod_sucursal_empleado !== ''): ?>
            <p class="field-note" style="margin-bottom: 14px;">
                Estás operando como sucursal:
                <strong><?php echo htmlspecialchars($cod_sucursal_empleado . ' - ' . $nombre_sucursal_empleado); ?></strong>
            </p>
        <?php endif; ?>

        <form method="GET" action="disponible_retiro.php">

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

                    <a href="disponible_retiro.php" class="btn-public-secondary">
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
                <p><strong>Paquetes:</strong> <?php echo htmlspecialchars((string) $envio['cantidad_paquetes']); ?></p>
                <p><strong>Peso total:</strong> <?php echo htmlspecialchars(number_format((float) $envio['peso_total_kg'], 2, '.', '')); ?> kg</p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Situación actual</h3>

                <p><strong>Estado:</strong> <?php echo htmlspecialchars($envio['nombre_estado_actual'] ?? ''); ?></p>
                <p><strong>Fecha estado:</strong> <?php echo htmlspecialchars($envio['fecha_estado_actual'] ?? ''); ?></p>
                <p><strong>Sucursal destino:</strong> <?php echo htmlspecialchars($envio['nombre_sucursal_destino']); ?></p>
            </article>

        </section>


        <?php if ($disponibilidad_actual): ?>

            <section class="dashboard-card" style="margin-bottom: 24px;">
                <h3 style="margin-top: 0; margin-bottom: 18px;">Disponibilidad ya registrada</h3>

                <p><strong>Sucursal retiro:</strong> <?php echo htmlspecialchars($disponibilidad_actual['nombre_sucursal_retiro']); ?></p>
                <p><strong>Dirección:</strong> <?php echo htmlspecialchars($disponibilidad_actual['direccion_sucursal_retiro']); ?></p>
                <p><strong>Fecha disponible:</strong> <?php echo htmlspecialchars($disponibilidad_actual['fecha_disponible']); ?></p>
                <p><strong>Fecha límite:</strong> <?php echo htmlspecialchars($disponibilidad_actual['fecha_limite_retiro']); ?></p>
                <p><strong>Días de plazo:</strong> <?php echo htmlspecialchars((string) $disponibilidad_actual['dias_plazo_aplicado']); ?></p>
                <p><strong>Observaciones:</strong> <?php echo htmlspecialchars($disponibilidad_actual['observaciones'] ?? ''); ?></p>
            </section>

        <?php endif; ?>


        <?php if ($puede_marcar): ?>
            <section class="dashboard-card" style="margin-bottom: 24px;">
                <h3 style="margin-top: 0; margin-bottom: 18px;">Registrar accidente o incidente detectado</h3>

                <form method="POST" action="disponible_retiro.php?tracking=<?php echo urlencode($tracking); ?>">
                    <input type="hidden" name="accion" value="registrar_incidente_llegada">
                    <input type="hidden" name="tracking" value="<?php echo htmlspecialchars($tracking); ?>">

                    <div class="dashboard-grid" style="grid-template-columns: 1fr 1fr;">
                        <div class="form-group">
                            <label for="fecha_hora_incidente">Fecha y hora del incidente</label>
                            <input
                                type="datetime-local"
                                id="fecha_hora_incidente"
                                name="fecha_hora_incidente"
                                class="form-control"
                                value="<?php echo htmlspecialchars(fechaDisponibleParaInput($fecha_hora_incidente)); ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="cod_tipo_incidente">Tipo de incidente</label>
                            <select id="cod_tipo_incidente" name="cod_tipo_incidente" class="form-control" required>
                                <option value="">Seleccione un tipo</option>
                                <?php foreach ($tipos_incidente as $tipo): ?>
                                    <option
                                        value="<?php echo htmlspecialchars($tipo['cod_tipo_incidente']); ?>"
                                        <?php echo ($cod_tipo_incidente === $tipo['cod_tipo_incidente']) ? 'selected' : ''; ?>
                                    >
                                        <?php echo htmlspecialchars($tipo['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="descripcion_incidente">Descripcion del incidente</label>
                        <textarea
                            id="descripcion_incidente"
                            name="descripcion_incidente"
                            class="form-control"
                            rows="4"
                            required
                        ><?php echo htmlspecialchars($descripcion_incidente); ?></textarea>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 18px; flex-wrap: wrap;">
                        <button type="submit" class="btn-public-secondary" style="width: auto;">
                            Registrar incidente
                        </button>
                    </div>
                </form>
            </section>
        <?php endif; ?>


        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Registrar disponibilidad para retiro</h3>

            <?php if ($puede_marcar): ?>

                <form method="POST" action="disponible_retiro.php?tracking=<?php echo urlencode($tracking); ?>">

                    <input type="hidden" name="accion" value="marcar_disponible">
                    <input type="hidden" name="tracking" value="<?php echo htmlspecialchars($tracking); ?>">

                    <div class="dashboard-grid" style="grid-template-columns: 1fr 1fr;">

                        <div class="form-group">
                            <label for="fecha_disponible">Fecha y hora disponible</label>
                            <input
                                type="datetime-local"
                                id="fecha_disponible"
                                name="fecha_disponible"
                                class="form-control"
                                value="<?php echo htmlspecialchars(fechaDisponibleParaInput($fecha_disponible)); ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="dias_plazo_aplicado">Días de plazo para retirar</label>
                            <input
                                type="number"
                                min="1"
                                id="dias_plazo_aplicado"
                                name="dias_plazo_aplicado"
                                class="form-control"
                                value="<?php echo htmlspecialchars($dias_plazo_aplicado); ?>"
                                required
                            >
                        </div>

                    </div>

                    <div class="form-group">
                        <label style="display: flex; gap: 10px; align-items: flex-start;">
                            <input
                                type="checkbox"
                                name="verificacion_ok"
                                value="1"
                                required
                                style="margin-top: 4px;"
                            >
                            <span>
                                Verifique todos los paquetes: llegaron completos, sin roturas visibles y sin incidentes pendientes.
                            </span>
                        </label>
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

                    <div style="display: flex; gap: 12px; margin-top: 18px; flex-wrap: wrap;">
                        <button type="submit" class="btn-primary" style="width: auto;">
                            Marcar disponible para retiro
                        </button>
                    </div>

                </form>

            <?php else: ?>

                <div class="alert alert-warning" style="margin: 0;">
                    <?php echo htmlspecialchars($motivo_no_marcar !== '' ? $motivo_no_marcar : 'Este envío no se puede marcar desde esta pantalla.'); ?>
                </div>

            <?php endif; ?>

        </section>

    <?php endif; ?>


    <section class="dashboard-card">

        <h3 style="margin-top: 0; margin-bottom: 18px;">
            <?php echo $rol_actual === 'ADMIN' ? 'Envíos elegibles para retiro' : 'Envíos elegibles en mi sucursal'; ?>
        </h3>

        <div style="overflow-x: auto;">

            <table style="width: 100%; border-collapse: collapse; min-width: 1300px;">

                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tracking</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha envío</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destinatario</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Sucursal destino</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Estado actual</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Acción</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($envios_elegibles)): ?>

                        <tr>
                            <td colspan="6" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                No hay envíos elegibles para marcar como disponibles.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($envios_elegibles as $item): ?>
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
                                    <?php echo htmlspecialchars($item['nombre_sucursal_destino']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($item['nombre_estado_actual'] ?? ''); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border); white-space: nowrap;">
                                    <a href="disponible_retiro.php?tracking=<?php echo urlencode($item['nro_tracking']); ?>" class="btn-public-secondary">
                                        Ver y marcar
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
