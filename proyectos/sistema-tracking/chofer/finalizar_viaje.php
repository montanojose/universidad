<?php
// =====================================================
// finalizar_viaje.php
// Cierre operativo de un viaje
// - acceso para CHOFER y ADMIN
// - CHOFER: solo puede finalizar sus viajes
// - ADMIN: puede probar cualquier viaje
// - actualiza estado del viaje
// - guarda fecha_llegada_real
// - actualiza estado del vehículo
// - registra historial de llegada para cada envío
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'CHOFER']);

$titulo_pagina = 'Finalizar Viaje';

$mensaje = '';
$tipo_mensaje = '';

$rol_actual = $_SESSION['usuario_rol'] ?? '';

$patente = trim($_GET['patente'] ?? '');
$fecha_salida = trim($_GET['fecha_salida'] ?? '');

$legajo_chofer_sesion = '';
$legajo_chofer_filtro = trim($_GET['legajo_chofer'] ?? '');
$buscar = trim($_GET['buscar'] ?? '');

$fecha_hora_llegada = date('Y-m-d H:i:s');
$observaciones = '';

$chofer_actual = null;
$choferes = [];

$viaje = null;
$envios_viaje = [];
$viajes_en_curso = [];

$resumen = [
    'cantidad_envios' => 0,
    'cantidad_paquetes' => 0,
    'peso_total_kg' => 0
];

$puede_finalizar = false;
$motivo_no_finalizar = '';


// -----------------------------------------------------
// FUNCIONES AUXILIARES
// -----------------------------------------------------

function obtenerLegajoChoferSesionFinalizar(): string
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

function normalizarFechaDatetimeLocalFinalizar(string $valor): string
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

function fechaFinalizarParaInput(?string $fecha): string
{
    if (!$fecha) {
        return '';
    }

    return date('Y-m-d\TH:i', strtotime($fecha));
}

function buscarCodigoCatalogoFinalizar(PDO $pdo, string $tabla, string $columna_codigo, string $columna_nombre, array $candidatos): ?string
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

function obtenerEstadoViajeFinalizado(PDO $pdo): ?string
{
    return buscarCodigoCatalogoFinalizar(
        $pdo,
        'Estado_Viaje',
        'cod_estado_viaje',
        'nombre',
        ['FINALIZADO', 'COMPLETADO', 'CERRADO']
    );
}

function obtenerEstadoVehiculoDisponible(PDO $pdo): ?string
{
    return buscarCodigoCatalogoFinalizar(
        $pdo,
        'Estado_Vehiculo',
        'cod_estado_vehiculo',
        'nombre',
        ['DISPONIBLE', 'LIBRE', 'ACTIVO']
    );
}

function obtenerEstadoEnvioLlegada(PDO $pdo): ?string
{
    return buscarCodigoCatalogoFinalizar(
        $pdo,
        'Estado_Envio',
        'cod_estado_envio',
        'nombre',
        [
            'ARRIBADO_A_SUCURSAL_DESTINO',
            'RECIBIDO_EN_SUCURSAL_DESTINO',
            'EN_SUCURSAL_DESTINO',
            'ARRIBADO_A_DESTINO',
            'LLEGADO_A_SUCURSAL_DESTINO',
            'RECIBIDO_EN_SUCURSAL'
        ]
    );
}

function esEstadoEnCurso(?string $codigo, ?string $nombre): bool
{
    $codigo_normalizado = strtoupper(str_replace(' ', '_', (string) $codigo));
    $nombre_normalizado = strtoupper(str_replace(' ', '_', (string) $nombre));

    $permitidos = [
        'EN_TRANSITO',
        'EN_CURSO',
        'INICIADO',
        'EN_PROGRESO'
    ];

    return in_array($codigo_normalizado, $permitidos, true) || in_array($nombre_normalizado, $permitidos, true);
}


// -----------------------------------------------------
// 1. IDENTIFICAR CHOFER SI ENTRA COMO CHOFER
// -----------------------------------------------------

if ($rol_actual === 'CHOFER') {

    $legajo_chofer_sesion = obtenerLegajoChoferSesionFinalizar();

    if ($legajo_chofer_sesion === '') {

        $mensaje = 'No se pudo identificar el legajo del chofer en la sesión.';
        $tipo_mensaje = 'error';

    } else {

        $legajo_chofer_filtro = $legajo_chofer_sesion;

        try {

            $sqlChoferActual = "
                SELECT
                    legajo,
                    dni,
                    nombre,
                    apellido,
                    telefono,
                    nro_licencia,
                    fecha_vencimiento_licencia
                FROM vista_chofer
                WHERE legajo = :legajo
                LIMIT 1
            ";

            $stmtChoferActual = $pdo->prepare($sqlChoferActual);
            $stmtChoferActual->execute([
                ':legajo' => $legajo_chofer_sesion
            ]);

            $chofer_actual = $stmtChoferActual->fetch();

            if (!$chofer_actual) {
                $mensaje = 'No se encontró el chofer asociado a la sesión actual.';
                $tipo_mensaje = 'error';
            }

        } catch (PDOException $e) {

            $mensaje = 'Ocurrió un error al cargar los datos del chofer actual.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 2. CARGAR CHOFERES SI ES ADMIN
// -----------------------------------------------------

if ($rol_actual === 'ADMIN') {

    try {

        $sqlChoferes = "
            SELECT
                legajo,
                nombre,
                apellido
            FROM vista_chofer
            ORDER BY apellido ASC, nombre ASC
        ";

        $choferes = $pdo->query($sqlChoferes)->fetchAll();

    } catch (PDOException $e) {

        $mensaje = 'No se pudieron cargar los choferes.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 3. PROCESAR FINALIZACIÓN DEL VIAJE
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'finalizar_viaje') {

    $patente = trim($_POST['patente'] ?? '');
    $fecha_salida = trim($_POST['fecha_salida'] ?? '');
    $fecha_hora_llegada = normalizarFechaDatetimeLocalFinalizar($_POST['fecha_hora_llegada'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');

    if ($patente === '' || $fecha_salida === '' || $fecha_hora_llegada === '') {

        $mensaje = 'Faltan datos para finalizar el viaje.';
        $tipo_mensaje = 'error';

    } else {

        try {

            $sqlViajeValidacion = "
                SELECT
                    v.patente,
                    v.fecha_salida,
                    v.fecha_llegada_estimada,
                    v.fecha_llegada_real,
                    v.legajo_chofer,
                    v.cod_sucursal_origen,
                    v.cod_sucursal_destino,
                    v.cod_estado_viaje,
                    ev.nombre AS nombre_estado_viaje,
                    COUNT(DISTINCT ve.nro_tracking) AS cantidad_envios
                FROM Viaje v
                INNER JOIN Estado_Viaje ev
                    ON v.cod_estado_viaje = ev.cod_estado_viaje
                LEFT JOIN Viaje_Envio ve
                    ON v.patente = ve.patente
                   AND v.fecha_salida = ve.fecha_salida
                WHERE v.patente = :patente
                  AND v.fecha_salida = :fecha_salida
                GROUP BY
                    v.patente,
                    v.fecha_salida,
                    v.fecha_llegada_estimada,
                    v.fecha_llegada_real,
                    v.legajo_chofer,
                    v.cod_sucursal_origen,
                    v.cod_sucursal_destino,
                    v.cod_estado_viaje,
                    ev.nombre
                LIMIT 1
            ";

            $stmtViajeValidacion = $pdo->prepare($sqlViajeValidacion);
            $stmtViajeValidacion->execute([
                ':patente' => $patente,
                ':fecha_salida' => $fecha_salida
            ]);

            $viajeValidacion = $stmtViajeValidacion->fetch();

            if (!$viajeValidacion) {

                $mensaje = 'No se encontró el viaje seleccionado.';
                $tipo_mensaje = 'error';

            } elseif ($rol_actual === 'CHOFER' && $viajeValidacion['legajo_chofer'] !== $legajo_chofer_sesion) {

                $mensaje = 'No tenés permiso para finalizar ese viaje.';
                $tipo_mensaje = 'error';

            } elseif ((int) $viajeValidacion['cantidad_envios'] === 0) {

                $mensaje = 'No se puede finalizar un viaje sin envíos asignados.';
                $tipo_mensaje = 'error';

            } elseif (!esEstadoEnCurso($viajeValidacion['cod_estado_viaje'], $viajeValidacion['nombre_estado_viaje'])) {

                $mensaje = 'El viaje no está en un estado válido para ser finalizado.';
                $tipo_mensaje = 'warning';

            } elseif (!empty($viajeValidacion['fecha_llegada_real'])) {

                $mensaje = 'Este viaje ya tiene una fecha de llegada real registrada.';
                $tipo_mensaje = 'warning';

            } elseif (strtotime($fecha_hora_llegada) < strtotime($viajeValidacion['fecha_salida'])) {

                $mensaje = 'La fecha real de llegada no puede ser anterior a la fecha de salida.';
                $tipo_mensaje = 'error';

            } else {

                $estado_viaje_finalizado = obtenerEstadoViajeFinalizado($pdo);
                $estado_vehiculo_disponible = obtenerEstadoVehiculoDisponible($pdo);
                $estado_envio_llegada = obtenerEstadoEnvioLlegada($pdo);

                if ($estado_viaje_finalizado === null) {

                    $mensaje = 'No se encontró un estado válido para finalizar el viaje.';
                    $tipo_mensaje = 'error';

                } elseif ($estado_envio_llegada === null) {

                    $mensaje = 'No se encontró un estado válido para marcar la llegada de los envíos.';
                    $tipo_mensaje = 'error';
                }
            }

            if ($mensaje === '') {

                $pdo->beginTransaction();

                $sqlActualizarViaje = "
                    UPDATE Viaje
                    SET
                        cod_estado_viaje = :cod_estado_viaje,
                        fecha_llegada_real = :fecha_llegada_real
                    WHERE patente = :patente
                      AND fecha_salida = :fecha_salida
                ";

                $stmtActualizarViaje = $pdo->prepare($sqlActualizarViaje);
                $stmtActualizarViaje->execute([
                    ':cod_estado_viaje' => $estado_viaje_finalizado,
                    ':fecha_llegada_real' => $fecha_hora_llegada,
                    ':patente' => $patente,
                    ':fecha_salida' => $fecha_salida
                ]);

                if ($estado_vehiculo_disponible !== null) {

                    $sqlActualizarVehiculo = "
                        UPDATE Vehiculo
                        SET cod_estado_vehiculo = :cod_estado_vehiculo
                        WHERE patente = :patente
                    ";

                    $stmtActualizarVehiculo = $pdo->prepare($sqlActualizarVehiculo);
                    $stmtActualizarVehiculo->execute([
                        ':cod_estado_vehiculo' => $estado_vehiculo_disponible,
                        ':patente' => $patente
                    ]);
                    $stmtInsertarHistorial->closeCursor();
                }

                $sqlEnviosViaje = "
                    SELECT
                        ve.nro_tracking,
                        v.cod_sucursal_destino
                    FROM Viaje_Envio ve
                    INNER JOIN Viaje v
                        ON ve.patente = v.patente
                       AND ve.fecha_salida = v.fecha_salida
                    WHERE ve.patente = :patente
                      AND ve.fecha_salida = :fecha_salida
                ";

                $stmtEnviosViaje = $pdo->prepare($sqlEnviosViaje);
                $stmtEnviosViaje->execute([
                    ':patente' => $patente,
                    ':fecha_salida' => $fecha_salida
                ]);

                $enviosFinalizar = $stmtEnviosViaje->fetchAll();

                foreach ($enviosFinalizar as $envioFinalizar) {

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
                        ':nro_tracking' => $envioFinalizar['nro_tracking'],
                        ':cod_estado_envio' => $estado_envio_llegada,
                        ':fecha_hora' => $fecha_hora_llegada,
                        ':cod_sucursal_actual' => $envioFinalizar['cod_sucursal_destino'],
                        ':patente' => $patente,
                        ':fecha_salida' => $fecha_salida,
                        ':observaciones' => ($observaciones !== '' ? $observaciones : 'Viaje finalizado y envío arribado a sucursal destino')
                    ]);
                }

                $pdo->commit();

                $mensaje = 'Viaje finalizado correctamente.';
                $tipo_mensaje = 'success';
                $observaciones = '';
                $fecha_hora_llegada = date('Y-m-d H:i:s');
            }

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $mensaje = 'Ocurrió un error al finalizar el viaje.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 4. CARGAR DETALLE DEL VIAJE SELECCIONADO
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

                so.nombre AS nombre_origen,
                sd.nombre AS nombre_destino,
                ev.nombre AS nombre_estado_viaje,

                ve.marca,
                ve.modelo,
                tv.nombre AS tipo_vehiculo,
                tv.capacidad_kg_max,
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

        if ($viaje) {

            if ($rol_actual === 'CHOFER' && $viaje['legajo_chofer'] !== $legajo_chofer_sesion) {
                $viaje = null;
                $mensaje = 'No tenés permiso para ver ese viaje.';
                $tipo_mensaje = 'error';
            } else {

                $sqlEnvios = "
                    SELECT
                        ve.nro_tracking,
                        ve.fecha_asignacion,

                        e.fecha_recepcion,
                        cd.nombre AS nombre_destinatario,
                        cd.apellido AS apellido_destinatario,

                        COALESCE(pkg.cantidad_paquetes, 0) AS cantidad_paquetes,
                        COALESCE(pkg.peso_total_kg, 0) AS peso_total_kg,

                        he.cod_estado_envio AS cod_estado_actual,
                        ee.nombre AS nombre_estado_actual
                    FROM Viaje_Envio ve
                    INNER JOIN Envio e
                        ON ve.nro_tracking = e.nro_tracking
                    INNER JOIN vista_cliente cd
                        ON e.dni_destinatario = cd.dni

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

                $stmtEnvios = $pdo->prepare($sqlEnvios);
                $stmtEnvios->execute([
                    ':patente' => $patente,
                    ':fecha_salida' => $fecha_salida
                ]);

                $envios_viaje = $stmtEnvios->fetchAll();

                $resumen['cantidad_envios'] = count($envios_viaje);

                foreach ($envios_viaje as $envio_item) {
                    $resumen['cantidad_paquetes'] += (int) $envio_item['cantidad_paquetes'];
                    $resumen['peso_total_kg'] += (float) $envio_item['peso_total_kg'];
                }

                if ((int) $resumen['cantidad_envios'] === 0) {
                    $puede_finalizar = false;
                    $motivo_no_finalizar = 'Este viaje no tiene envíos asignados.';
                } elseif (!esEstadoEnCurso($viaje['cod_estado_viaje'], $viaje['nombre_estado_viaje'])) {
                    $puede_finalizar = false;
                    $motivo_no_finalizar = 'El viaje no está en un estado válido para finalizarse.';
                } elseif (!empty($viaje['fecha_llegada_real'])) {
                    $puede_finalizar = false;
                    $motivo_no_finalizar = 'Este viaje ya tiene registrada su llegada real.';
                } else {
                    $puede_finalizar = true;
                    $motivo_no_finalizar = '';
                }
            }

        } else {
            if ($mensaje === '') {
                $mensaje = 'No se encontró el viaje seleccionado.';
                $tipo_mensaje = 'warning';
            }
        }

    } catch (PDOException $e) {

        $mensaje = 'Ocurrió un error al cargar el detalle del viaje.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 5. CARGAR VIAJES EN CURSO
// -----------------------------------------------------

try {

    $sqlViajesCurso = "
        SELECT
            v.patente,
            v.fecha_salida,
            v.fecha_llegada_estimada,
            v.fecha_llegada_real,
            v.legajo_chofer,
            v.cod_estado_viaje,

            ch.nombre AS nombre_chofer,
            ch.apellido AS apellido_chofer,
            so.nombre AS nombre_origen,
            sd.nombre AS nombre_destino,
            ev.nombre AS nombre_estado_viaje,

            COUNT(DISTINCT ve.nro_tracking) AS cantidad_envios,
            COUNT(p.nro_paquete) AS cantidad_paquetes,
            COALESCE(SUM(p.peso_kg), 0) AS peso_total_kg
        FROM Viaje v
        INNER JOIN vista_chofer ch
            ON v.legajo_chofer = ch.legajo
        INNER JOIN Sucursal so
            ON v.cod_sucursal_origen = so.cod_sucursal
        INNER JOIN Sucursal sd
            ON v.cod_sucursal_destino = sd.cod_sucursal
        INNER JOIN Estado_Viaje ev
            ON v.cod_estado_viaje = ev.cod_estado_viaje
        LEFT JOIN Viaje_Envio ve
            ON v.patente = ve.patente
           AND v.fecha_salida = ve.fecha_salida
        LEFT JOIN Paquete p
            ON ve.nro_tracking = p.nro_tracking
        WHERE 1 = 1
    ";

    $paramsCurso = [];

    if ($legajo_chofer_filtro !== '') {
        $sqlViajesCurso .= " AND v.legajo_chofer = :legajo_chofer ";
        $paramsCurso[':legajo_chofer'] = $legajo_chofer_filtro;
    }

    if ($buscar !== '') {
        $sqlViajesCurso .= "
            AND (
                v.patente LIKE :buscar
                OR so.nombre LIKE :buscar
                OR sd.nombre LIKE :buscar
                OR ch.nombre LIKE :buscar
                OR ch.apellido LIKE :buscar
            )
        ";
        $paramsCurso[':buscar'] = '%' . $buscar . '%';
    }

    $sqlViajesCurso .= "
        GROUP BY
            v.patente,
            v.fecha_salida,
            v.fecha_llegada_estimada,
            v.fecha_llegada_real,
            v.legajo_chofer,
            v.cod_estado_viaje,
            ch.nombre,
            ch.apellido,
            so.nombre,
            sd.nombre,
            ev.nombre
        ORDER BY
            v.fecha_salida DESC,
            v.patente ASC
    ";

    $stmtViajesCurso = $pdo->prepare($sqlViajesCurso);
    $stmtViajesCurso->execute($paramsCurso);

    $todos_los_viajes = $stmtViajesCurso->fetchAll();

    foreach ($todos_los_viajes as $viaje_item) {
        if (esEstadoEnCurso($viaje_item['cod_estado_viaje'], $viaje_item['nombre_estado_viaje'])) {
            $viajes_en_curso[] = $viaje_item;
        }
    }

} catch (PDOException $e) {

    $mensaje = 'Ocurrió un error al cargar los viajes en curso.';
    $tipo_mensaje = 'error';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Finalizar Viaje</h1>
        <p class="page-subtitle">
            Confirmá la llegada del viaje para cerrar el traslado y registrar el arribo de los envíos al destino.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>


    <?php if ($rol_actual === 'CHOFER' && $chofer_actual): ?>
        <section class="dashboard-card" style="margin-bottom: 24px;">
            <h3 style="margin-top: 0; margin-bottom: 12px;">Chofer actual</h3>

            <div class="dashboard-grid" style="grid-template-columns: repeat(3, 1fr);">
                <article>
                    <p><strong>Legajo:</strong> <?php echo htmlspecialchars($chofer_actual['legajo']); ?></p>
                    <p><strong>DNI:</strong> <?php echo htmlspecialchars($chofer_actual['dni']); ?></p>
                </article>

                <article>
                    <p><strong>Nombre:</strong> <?php echo htmlspecialchars($chofer_actual['apellido'] . ', ' . $chofer_actual['nombre']); ?></p>
                    <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($chofer_actual['telefono'] ?? ''); ?></p>
                </article>

                <article>
                    <p><strong>Licencia:</strong> <?php echo htmlspecialchars($chofer_actual['nro_licencia']); ?></p>
                    <p><strong>Vencimiento:</strong> <?php echo htmlspecialchars($chofer_actual['fecha_vencimiento_licencia']); ?></p>
                </article>
            </div>
        </section>
    <?php endif; ?>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Buscar viaje en curso</h3>

        <form method="GET" action="finalizar_viaje.php">

            <div class="dashboard-grid" style="grid-template-columns: <?php echo $rol_actual === 'ADMIN' ? '1fr 2fr 1fr' : '2fr 1fr'; ?>;">

                <?php if ($rol_actual === 'ADMIN'): ?>
                    <div class="form-group">
                        <label for="legajo_chofer">Chofer</label>
                        <select id="legajo_chofer" name="legajo_chofer" class="form-control">
                            <option value="">Todos los choferes</option>

                            <?php foreach ($choferes as $chofer): ?>
                                <option
                                    value="<?php echo htmlspecialchars($chofer['legajo']); ?>"
                                    <?php echo ($legajo_chofer_filtro === $chofer['legajo']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($chofer['legajo'] . ' - ' . $chofer['apellido'] . ', ' . $chofer['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="buscar">Buscar por patente, origen, destino o chofer</label>
                    <input
                        type="text"
                        id="buscar"
                        name="buscar"
                        class="form-control"
                        value="<?php echo htmlspecialchars($buscar); ?>"
                        placeholder="Ej: AB123CD, Mendoza..."
                    >
                </div>

                <div class="form-group" style="display: flex; align-items: flex-end; gap: 12px;">
                    <button type="submit" class="btn-primary" style="width: auto;">
                        Buscar
                    </button>

                    <a href="finalizar_viaje.php" class="btn-public-secondary">
                        Limpiar
                    </a>
                </div>

            </div>

        </form>

    </section>


    <?php if ($viaje): ?>

        <section class="dashboard-grid" style="margin-bottom: 24px;">

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Datos del viaje</h3>

                <p><strong>Patente:</strong> <?php echo htmlspecialchars($viaje['patente']); ?></p>
                <p><strong>Fecha salida:</strong> <?php echo htmlspecialchars($viaje['fecha_salida']); ?></p>
                <p><strong>Llegada estimada:</strong> <?php echo htmlspecialchars($viaje['fecha_llegada_estimada']); ?></p>
                <p><strong>Trayecto:</strong> <?php echo htmlspecialchars($viaje['nombre_origen'] . ' → ' . $viaje['nombre_destino']); ?></p>
                <p><strong>Estado actual:</strong> <?php echo htmlspecialchars($viaje['nombre_estado_viaje']); ?></p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Chofer</h3>

                <p><strong>Legajo:</strong> <?php echo htmlspecialchars($viaje['legajo_chofer']); ?></p>
                <p><strong>Nombre:</strong> <?php echo htmlspecialchars($viaje['apellido_chofer'] . ', ' . $viaje['nombre_chofer']); ?></p>
                <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($viaje['telefono_chofer'] ?? ''); ?></p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Vehículo</h3>

                <p><strong>Marca / Modelo:</strong> <?php echo htmlspecialchars($viaje['marca'] . ' ' . $viaje['modelo']); ?></p>
                <p><strong>Tipo:</strong> <?php echo htmlspecialchars($viaje['tipo_vehiculo']); ?></p>
                <p><strong>Capacidad máxima:</strong> <?php echo htmlspecialchars((string) $viaje['capacidad_kg_max']); ?> kg</p>
                <p><strong>Estado vehículo:</strong> <?php echo htmlspecialchars($viaje['nombre_estado_vehiculo']); ?></p>
            </article>

        </section>


        <section class="dashboard-grid" style="margin-bottom: 24px;">

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Envíos cargados</h3>
                <p style="font-size: 28px; margin: 0; font-weight: 700;">
                    <?php echo htmlspecialchars((string) $resumen['cantidad_envios']); ?>
                </p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Paquetes cargados</h3>
                <p style="font-size: 28px; margin: 0; font-weight: 700;">
                    <?php echo htmlspecialchars((string) $resumen['cantidad_paquetes']); ?>
                </p>
            </article>

            <article class="dashboard-card">
                <h3 style="margin-top: 0;">Peso total</h3>
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

            <h3 style="margin-top: 0; margin-bottom: 18px;">Confirmar finalización del viaje</h3>

            <?php if ($puede_finalizar): ?>

                <div class="alert alert-warning" style="margin-bottom: 18px;">
                    Si hubo accidente, rotura, demora o cualquier percance, registralo antes de finalizar el viaje.
                    <div style="margin-top: 12px;">
                        <a
                            href="registrar_incidente.php?patente=<?php echo urlencode($patente); ?>&fecha_salida=<?php echo urlencode($fecha_salida); ?>"
                            class="btn-public-secondary"
                        >
                            Registrar accidente/incidente
                        </a>
                    </div>
                </div>

                <form method="POST" action="finalizar_viaje.php?patente=<?php echo urlencode($patente); ?>&fecha_salida=<?php echo urlencode($fecha_salida); ?>">

                    <input type="hidden" name="accion" value="finalizar_viaje">
                    <input type="hidden" name="patente" value="<?php echo htmlspecialchars($patente); ?>">
                    <input type="hidden" name="fecha_salida" value="<?php echo htmlspecialchars($fecha_salida); ?>">

                    <div class="dashboard-grid" style="grid-template-columns: 1fr 1fr;">

                        <div class="form-group">
                            <label for="fecha_hora_llegada">Fecha y hora real de llegada</label>
                            <input
                                type="datetime-local"
                                id="fecha_hora_llegada"
                                name="fecha_hora_llegada"
                                class="form-control"
                                value="<?php echo htmlspecialchars(fechaFinalizarParaInput($fecha_hora_llegada)); ?>"
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
                            Finalizar viaje
                        </button>
                    </div>

                </form>

            <?php else: ?>

                <div class="alert alert-warning" style="margin: 0;">
                    <?php echo htmlspecialchars($motivo_no_finalizar !== '' ? $motivo_no_finalizar : 'Este viaje no se puede finalizar desde esta pantalla.'); ?>
                </div>

            <?php endif; ?>

        </section>


        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Envíos del viaje</h3>

            <div style="overflow-x: auto;">

                <table style="width: 100%; border-collapse: collapse; min-width: 1300px;">

                    <thead>
                        <tr style="background-color: var(--color-surface-soft);">
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tracking</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha asignación</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha solicitud</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destinatario</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Paquetes</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Peso total</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Estado actual</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($envios_viaje)): ?>

                            <tr>
                                <td colspan="7" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                    Este viaje no tiene envíos cargados.
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
                                        <?php echo htmlspecialchars($envio_item['apellido_destinatario'] . ', ' . $envio_item['nombre_destinatario']); ?>
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

    <?php endif; ?>


    <section class="dashboard-card">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Viajes en curso</h3>

        <div style="overflow-x: auto;">

            <table style="width: 100%; border-collapse: collapse; min-width: 1450px;">

                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Patente</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha salida</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Llegada estimada</th>
                        <?php if ($rol_actual === 'ADMIN'): ?>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Chofer</th>
                        <?php endif; ?>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Origen</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destino</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Estado</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Envíos</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Paquetes</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Peso total</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Acción</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($viajes_en_curso)): ?>

                        <tr>
                            <td colspan="<?php echo $rol_actual === 'ADMIN' ? '11' : '10'; ?>" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                No hay viajes en curso con esos criterios.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($viajes_en_curso as $viaje_item): ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($viaje_item['patente']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($viaje_item['fecha_salida']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($viaje_item['fecha_llegada_estimada']); ?>
                                </td>

                                <?php if ($rol_actual === 'ADMIN'): ?>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($viaje_item['legajo_chofer'] . ' - ' . $viaje_item['apellido_chofer'] . ', ' . $viaje_item['nombre_chofer']); ?>
                                    </td>
                                <?php endif; ?>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($viaje_item['nombre_origen']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($viaje_item['nombre_destino']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($viaje_item['nombre_estado_viaje']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars((string) $viaje_item['cantidad_envios']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars((string) $viaje_item['cantidad_paquetes']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars(number_format((float) $viaje_item['peso_total_kg'], 2, '.', '')); ?> kg
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border); white-space: nowrap;">
                                    <a href="finalizar_viaje.php?patente=<?php echo urlencode($viaje_item['patente']); ?>&fecha_salida=<?php echo urlencode($viaje_item['fecha_salida']); ?><?php echo $rol_actual === 'ADMIN' && $legajo_chofer_filtro !== '' ? '&legajo_chofer=' . urlencode($legajo_chofer_filtro) : ''; ?>" class="btn-public-secondary">
                                        Ver y finalizar
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
