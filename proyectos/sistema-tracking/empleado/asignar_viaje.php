<?php
// =====================================================
// asignar_viaje.php
// Creación de viaje y asignación de envíos
// - acceso para EMPLEADO_SUCURSAL y ADMIN
// - sucursal origen fija para empleado
// - admin puede elegir sucursal origen
// - muestra vehículos disponibles
// - muestra choferes de la sucursal
// - muestra envíos elegibles por origen/destino
// - controla capacidad máxima por peso
// - crea Viaje, Viaje_Envio e Historial_Estado
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'EMPLEADO_SUCURSAL']);

$titulo_pagina = 'Asignar Envío a Viaje';

$mensaje = '';
$tipo_mensaje = '';
$detalle_error_bd = '';

if (($_GET['asignado'] ?? '') === '1') {
    $mensaje = 'Viaje creado y envio asignado correctamente.';
    $tipo_mensaje = 'success';
}

$rol_actual = $_SESSION['usuario_rol'] ?? '';

$cod_sucursal_empleado = '';
$nombre_sucursal_empleado = '';

$cod_sucursal_origen = '';
$cod_sucursal_destino = '';
$patente = '';
$legajo_chofer = '';
$fecha_salida = date('Y-m-d H:i:s');
$fecha_llegada_estimada = date('Y-m-d H:i:s', strtotime('+4 hours'));

$sucursales = [];
$sucursales_destino = [];
$vehiculos = [];
$choferes = [];
$envios_elegibles = [];
$envios_pendientes = [];
$viajes_recientes = [];

$envios_seleccionados = [];
$tracking_seleccionado = '';
$envio_seleccionado = null;

$vehiculo_actual = null;


// -----------------------------------------------------
// FUNCIONES AUXILIARES
// -----------------------------------------------------

function obtenerLegajoEmpleadoSesionAsignacion(): string
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

function normalizarFechaDatetimeLocalAsignacion(string $valor): string
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

function fechaAsignacionParaInput(?string $fecha): string
{
    if (!$fecha) {
        return '';
    }

    return date('Y-m-d\TH:i', strtotime($fecha));
}

function buscarCodigoCatalogo(PDO $pdo, string $tabla, string $columna_codigo, string $columna_nombre, array $candidatos): ?string
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

function obtenerEstadoInicialViaje(PDO $pdo): ?string
{
    return buscarCodigoCatalogo(
        $pdo,
        'Estado_Viaje',
        'cod_estado_viaje',
        'nombre',
        ['PROGRAMADO', 'PLANIFICADO', 'ASIGNADO', 'CREADO']
    );
}

function obtenerEstadoEnvioAsignado(PDO $pdo): ?string
{
    return buscarCodigoCatalogo(
        $pdo,
        'Estado_Envio',
        'cod_estado_envio',
        'nombre',
        ['ASIGNADO_A_VIAJE', 'ASIGNADO']
    );
}

function obtenerEstadoVehiculoAsignado(PDO $pdo): ?string
{
    return buscarCodigoCatalogo(
        $pdo,
        'Estado_Vehiculo',
        'cod_estado_vehiculo',
        'nombre',
        ['EN_RUTA', 'ASIGNADO', 'OCUPADO', 'EN_VIAJE', 'NO_DISPONIBLE']
    );
}


// -----------------------------------------------------
// 1. OBTENER SUCURSAL DEL EMPLEADO SI CORRESPONDE
// -----------------------------------------------------

if ($rol_actual === 'EMPLEADO_SUCURSAL') {

    $legajo_empleado_sesion = obtenerLegajoEmpleadoSesionAsignacion();

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
                $cod_sucursal_origen = $cod_sucursal_empleado;
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
// 2. TOMAR CONTEXTO DESDE GET O POST
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear_viaje') {

    if ($rol_actual === 'ADMIN') {
        $cod_sucursal_origen = trim($_POST['cod_sucursal_origen'] ?? '');
    }

    $cod_sucursal_destino = trim($_POST['cod_sucursal_destino'] ?? '');
    $patente = trim($_POST['patente'] ?? '');
    $legajo_chofer = trim($_POST['legajo_chofer'] ?? '');
    $fecha_salida = normalizarFechaDatetimeLocalAsignacion($_POST['fecha_salida'] ?? '');
    $fecha_llegada_estimada = normalizarFechaDatetimeLocalAsignacion($_POST['fecha_llegada_estimada'] ?? '');
    $envios_seleccionados = $_POST['envios_seleccionados'] ?? [];
    $tracking_seleccionado = trim($_POST['tracking'] ?? '');

} else {

    if ($rol_actual === 'ADMIN') {
        $cod_sucursal_origen = trim($_GET['cod_sucursal_origen'] ?? '');
    }

    $cod_sucursal_destino = trim($_GET['cod_sucursal_destino'] ?? '');
    $patente = trim($_GET['patente'] ?? '');
    $legajo_chofer = trim($_GET['legajo_chofer'] ?? '');
    $fecha_salida = normalizarFechaDatetimeLocalAsignacion($_GET['fecha_salida'] ?? $fecha_salida);
    $fecha_llegada_estimada = normalizarFechaDatetimeLocalAsignacion($_GET['fecha_llegada_estimada'] ?? $fecha_llegada_estimada);
    $tracking_seleccionado = trim($_GET['tracking'] ?? '');
}


// -----------------------------------------------------
// 3. CARGAR ENVIO SELECCIONADO DESDE LA LISTA DE PENDIENTES
// -----------------------------------------------------

if ($tracking_seleccionado !== '') {

    try {

        $sqlEnvioSeleccionado = "
            SELECT
                e.nro_tracking,
                e.fecha_recepcion,
                e.dni_remitente,
                e.dni_destinatario,
                e.cod_sucursal_origen,
                e.cod_sucursal_destino,
                so.nombre AS nombre_origen,
                sd.nombre AS nombre_destino,
                cr.nombre AS nombre_remitente,
                cr.apellido AS apellido_remitente,
                cd.nombre AS nombre_destinatario,
                cd.apellido AS apellido_destinatario,
                COALESCE(pkg.cantidad_paquetes, 0) AS cantidad_paquetes,
                COALESCE(pkg.peso_total, 0) AS peso_total,
                he.cod_estado_envio AS cod_estado_actual,
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
                SELECT
                    nro_tracking,
                    COUNT(*) AS cantidad_paquetes,
                    SUM(peso_kg) AS peso_total
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
            LEFT JOIN Viaje_Envio ve
                ON e.nro_tracking = ve.nro_tracking
            WHERE e.nro_tracking = :nro_tracking
              AND ve.nro_tracking IS NULL
              AND COALESCE(pkg.cantidad_paquetes, 0) > 0
        ";

        $paramsEnvioSeleccionado = [
            ':nro_tracking' => $tracking_seleccionado
        ];

        if ($rol_actual === 'EMPLEADO_SUCURSAL') {
            $sqlEnvioSeleccionado .= " AND e.cod_sucursal_origen = :cod_sucursal_empleado ";
            $paramsEnvioSeleccionado[':cod_sucursal_empleado'] = $cod_sucursal_empleado;
        }

        $sqlEnvioSeleccionado .= " LIMIT 1 ";

        $stmtEnvioSeleccionado = $pdo->prepare($sqlEnvioSeleccionado);
        $stmtEnvioSeleccionado->execute($paramsEnvioSeleccionado);

        $envio_seleccionado = $stmtEnvioSeleccionado->fetch();

        if ($envio_seleccionado) {
            $cod_sucursal_origen = $envio_seleccionado['cod_sucursal_origen'];
            $cod_sucursal_destino = $envio_seleccionado['cod_sucursal_destino'];
            $envios_seleccionados = [$tracking_seleccionado];
        } elseif ($mensaje === '') {
            $mensaje = 'El envio seleccionado ya no esta pendiente para asignar o no pertenece a tu sucursal.';
            $tipo_mensaje = 'warning';
            $tracking_seleccionado = '';
        }

    } catch (PDOException $e) {

        $mensaje = 'No se pudo cargar el envio seleccionado.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 4. CARGAR SUCURSALES
// -----------------------------------------------------

try {

    if ($rol_actual === 'ADMIN') {
        $sucursales = $pdo->query("
            SELECT cod_sucursal, nombre
            FROM Sucursal
            ORDER BY nombre ASC
        ")->fetchAll();
    }

    if ($cod_sucursal_origen !== '') {

        $sqlSucursalesDestino = "
            SELECT
                cod_sucursal,
                nombre
            FROM Sucursal
            WHERE cod_sucursal <> :cod_sucursal_origen
            ORDER BY nombre ASC
        ";

        $stmtSucursalesDestino = $pdo->prepare($sqlSucursalesDestino);
        $stmtSucursalesDestino->execute([
            ':cod_sucursal_origen' => $cod_sucursal_origen
        ]);

        $sucursales_destino = $stmtSucursalesDestino->fetchAll();
    }

} catch (PDOException $e) {

    $mensaje = 'No se pudieron cargar las sucursales.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 4. CARGAR VEHÍCULOS DISPONIBLES Y CHOFERES
// -----------------------------------------------------

try {

    $sqlEnviosPendientes = "
        SELECT
            e.nro_tracking,
            e.fecha_recepcion,
            e.cod_sucursal_origen,
            e.cod_sucursal_destino,
            so.nombre AS nombre_origen,
            sd.nombre AS nombre_destino,
            cr.nombre AS nombre_remitente,
            cr.apellido AS apellido_remitente,
            cd.nombre AS nombre_destinatario,
            cd.apellido AS apellido_destinatario,
            COALESCE(pkg.cantidad_paquetes, 0) AS cantidad_paquetes,
            COALESCE(pkg.peso_total, 0) AS peso_total,
            he.cod_estado_envio AS cod_estado_actual,
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
            SELECT
                nro_tracking,
                COUNT(*) AS cantidad_paquetes,
                SUM(peso_kg) AS peso_total
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
        LEFT JOIN Viaje_Envio ve
            ON e.nro_tracking = ve.nro_tracking
        WHERE ve.nro_tracking IS NULL
          AND COALESCE(pkg.cantidad_paquetes, 0) > 0
          AND (
                UPPER(COALESCE(he.cod_estado_envio, '')) IN ('RECIBIDO_EN_SUCURSAL', 'CLASIFICADO')
                OR UPPER(REPLACE(COALESCE(ee.nombre, ''), ' ', '_')) IN ('RECIBIDO_EN_SUCURSAL', 'CLASIFICADO')
              )
    ";

    $paramsEnviosPendientes = [];

    if ($rol_actual === 'EMPLEADO_SUCURSAL') {
        $sqlEnviosPendientes .= " AND e.cod_sucursal_origen = :cod_sucursal_empleado ";
        $paramsEnviosPendientes[':cod_sucursal_empleado'] = $cod_sucursal_empleado;
    }

    $sqlEnviosPendientes .= " ORDER BY e.fecha_recepcion ASC, e.nro_tracking ASC ";

    $stmtEnviosPendientes = $pdo->prepare($sqlEnviosPendientes);
    $stmtEnviosPendientes->execute($paramsEnviosPendientes);

    $envios_pendientes = $stmtEnviosPendientes->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'No se pudieron cargar los envios pendientes de asignar.';
    $tipo_mensaje = 'error';
}


if ($cod_sucursal_origen !== '') {

    try {

        $sqlVehiculos = "
            SELECT
                v.patente,
                v.marca,
                v.modelo,
                v.cod_estado_vehiculo,
                tv.nombre AS tipo_vehiculo,
                tv.capacidad_kg_max,
                ev.nombre AS nombre_estado_vehiculo
            FROM Vehiculo v
            INNER JOIN Tipo_Vehiculo tv
                ON v.cod_tipo_vehiculo = tv.cod_tipo_vehiculo
            INNER JOIN Estado_Vehiculo ev
                ON v.cod_estado_vehiculo = ev.cod_estado_vehiculo
            WHERE v.cod_sucursal = :cod_sucursal
              AND (
                    UPPER(v.cod_estado_vehiculo) LIKE '%DISPONIBLE%'
                    OR UPPER(REPLACE(ev.nombre, ' ', '_')) LIKE '%DISPONIBLE%'
                  )
            ORDER BY v.patente ASC
        ";

        $stmtVehiculos = $pdo->prepare($sqlVehiculos);
        $stmtVehiculos->execute([
            ':cod_sucursal' => $cod_sucursal_origen
        ]);

        $vehiculos = $stmtVehiculos->fetchAll();

        if (empty($vehiculos)) {
            $sqlVehiculosFallback = "
                SELECT
                    v.patente,
                    v.marca,
                    v.modelo,
                    v.cod_estado_vehiculo,
                    tv.nombre AS tipo_vehiculo,
                    tv.capacidad_kg_max,
                    ev.nombre AS nombre_estado_vehiculo
                FROM Vehiculo v
                INNER JOIN Tipo_Vehiculo tv
                    ON v.cod_tipo_vehiculo = tv.cod_tipo_vehiculo
                INNER JOIN Estado_Vehiculo ev
                    ON v.cod_estado_vehiculo = ev.cod_estado_vehiculo
                WHERE v.cod_sucursal = :cod_sucursal
                ORDER BY v.patente ASC
            ";

            $stmtVehiculosFallback = $pdo->prepare($sqlVehiculosFallback);
            $stmtVehiculosFallback->execute([
                ':cod_sucursal' => $cod_sucursal_origen
            ]);

            $vehiculos = $stmtVehiculosFallback->fetchAll();
        }

        $sqlChoferes = "
            SELECT
                legajo,
                nombre,
                apellido,
                nro_licencia,
                fecha_vencimiento_licencia
            FROM vista_chofer
            WHERE cod_sucursal = :cod_sucursal
            ORDER BY apellido ASC, nombre ASC
        ";

        $stmtChoferes = $pdo->prepare($sqlChoferes);
        $stmtChoferes->execute([
            ':cod_sucursal' => $cod_sucursal_origen
        ]);

        $choferes = $stmtChoferes->fetchAll();

        foreach ($vehiculos as $vehiculo) {
            if ($vehiculo['patente'] === $patente) {
                $vehiculo_actual = $vehiculo;
                break;
            }
        }

    } catch (PDOException $e) {

        $mensaje = 'No se pudieron cargar los vehículos o choferes.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 5. CARGAR ENVÍOS ELEGIBLES PARA LA RUTA
// -----------------------------------------------------

if ($cod_sucursal_origen !== '' && $cod_sucursal_destino !== '') {

    try {

        $sqlEnviosElegibles = "
            SELECT
                e.nro_tracking,
                e.fecha_recepcion,
                e.dni_remitente,
                e.dni_destinatario,
                cd.nombre AS nombre_destinatario,
                cd.apellido AS apellido_destinatario,
                COALESCE(pkg.cantidad_paquetes, 0) AS cantidad_paquetes,
                COALESCE(pkg.peso_total, 0) AS peso_total,
                he.cod_estado_envio AS cod_estado_actual,
                ee.nombre AS nombre_estado_actual
            FROM Envio e
            INNER JOIN vista_cliente cd
                ON e.dni_destinatario = cd.dni

            LEFT JOIN (
                SELECT
                    nro_tracking,
                    COUNT(*) AS cantidad_paquetes,
                    SUM(peso_kg) AS peso_total
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
            LEFT JOIN Viaje_Envio ve
                ON e.nro_tracking = ve.nro_tracking

            WHERE e.cod_sucursal_origen = :cod_sucursal_origen
              AND e.cod_sucursal_destino = :cod_sucursal_destino
              AND ve.nro_tracking IS NULL
              AND COALESCE(pkg.cantidad_paquetes, 0) > 0
              AND (
                    UPPER(COALESCE(he.cod_estado_envio, '')) IN ('RECIBIDO_EN_SUCURSAL', 'CLASIFICADO')
                    OR UPPER(REPLACE(COALESCE(ee.nombre, ''), ' ', '_')) IN ('RECIBIDO_EN_SUCURSAL', 'CLASIFICADO')
                  )
            ORDER BY e.fecha_recepcion ASC, e.nro_tracking ASC
        ";

        $stmtEnviosElegibles = $pdo->prepare($sqlEnviosElegibles);
        $stmtEnviosElegibles->execute([
            ':cod_sucursal_origen' => $cod_sucursal_origen,
            ':cod_sucursal_destino' => $cod_sucursal_destino
        ]);

        $envios_elegibles = $stmtEnviosElegibles->fetchAll();

    } catch (PDOException $e) {

        $mensaje = 'No se pudieron cargar los envíos elegibles para el viaje.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 6. PROCESAR CREACIÓN DEL VIAJE
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear_viaje') {

    if (
        $cod_sucursal_origen === '' ||
        $cod_sucursal_destino === '' ||
        $patente === '' ||
        $legajo_chofer === '' ||
        $fecha_salida === '' ||
        $fecha_llegada_estimada === ''
    ) {

        $mensaje = 'Completá todos los datos del viaje.';
        $tipo_mensaje = 'error';

    } elseif (strtotime($fecha_llegada_estimada) <= strtotime($fecha_salida)) {

        $mensaje = 'La fecha estimada de llegada debe ser posterior a la fecha de salida.';
        $tipo_mensaje = 'error';

    } elseif (empty($envios_seleccionados)) {

        $mensaje = 'Debés seleccionar al menos un envío para crear el viaje.';
        $tipo_mensaje = 'error';

    } else {

        try {

            // Validar vehículo elegido
            $sqlVehiculoValidacion = "
                SELECT
                    v.patente,
                    v.cod_sucursal,
                    v.cod_estado_vehiculo,
                    tv.capacidad_kg_max,
                    ev.nombre AS nombre_estado_vehiculo
                FROM Vehiculo v
                INNER JOIN Tipo_Vehiculo tv
                    ON v.cod_tipo_vehiculo = tv.cod_tipo_vehiculo
                INNER JOIN Estado_Vehiculo ev
                    ON v.cod_estado_vehiculo = ev.cod_estado_vehiculo
                WHERE v.patente = :patente
                LIMIT 1
            ";

            $stmtVehiculoValidacion = $pdo->prepare($sqlVehiculoValidacion);
            $stmtVehiculoValidacion->execute([
                ':patente' => $patente
            ]);

            $vehiculoValidacion = $stmtVehiculoValidacion->fetch();

            if (!$vehiculoValidacion) {

                $mensaje = 'No se encontró el vehículo seleccionado.';
                $tipo_mensaje = 'error';

            } elseif ($vehiculoValidacion['cod_sucursal'] !== $cod_sucursal_origen) {

                $mensaje = 'El vehículo seleccionado no pertenece a la sucursal de origen.';
                $tipo_mensaje = 'error';

            } else {

                // Validar chofer elegido
                $sqlChoferValidacion = "
                    SELECT
                        legajo,
                        cod_sucursal
                    FROM vista_chofer
                    WHERE legajo = :legajo
                    LIMIT 1
                ";

                $stmtChoferValidacion = $pdo->prepare($sqlChoferValidacion);
                $stmtChoferValidacion->execute([
                    ':legajo' => $legajo_chofer
                ]);

                $choferValidacion = $stmtChoferValidacion->fetch();

                if (!$choferValidacion) {

                    $mensaje = 'No se encontró el chofer seleccionado.';
                    $tipo_mensaje = 'error';

                } elseif ($choferValidacion['cod_sucursal'] !== $cod_sucursal_origen) {

                    $mensaje = 'El chofer seleccionado no pertenece a la sucursal de origen.';
                    $tipo_mensaje = 'error';
                }
            }

            // Validar envíos seleccionados
            $mapa_envios_elegibles = [];
            $peso_total_seleccionado = 0.0;

            foreach ($envios_elegibles as $envio_item) {
                $mapa_envios_elegibles[$envio_item['nro_tracking']] = $envio_item;
            }

            if ($mensaje === '') {

                foreach ($envios_seleccionados as $tracking_sel) {

                    $tracking_sel = trim((string) $tracking_sel);

                    if (!isset($mapa_envios_elegibles[$tracking_sel])) {
                        $mensaje = 'Uno o más envíos seleccionados ya no son válidos para este viaje.';
                        $tipo_mensaje = 'error';
                        break;
                    }

                    $peso_total_seleccionado += (float) $mapa_envios_elegibles[$tracking_sel]['peso_total'];
                }
            }

            if ($mensaje === '') {

                $capacidad_maxima = (float) $vehiculoValidacion['capacidad_kg_max'];

                if ($peso_total_seleccionado <= 0) {
                    $mensaje = 'El peso total del viaje no puede ser cero.';
                    $tipo_mensaje = 'error';
                } elseif ($peso_total_seleccionado > $capacidad_maxima) {
                    $mensaje = 'El peso total seleccionado supera la capacidad máxima del vehículo.';
                    $tipo_mensaje = 'error';
                }
            }

            if ($mensaje === '') {

                $estado_inicial_viaje = obtenerEstadoInicialViaje($pdo);
                $estado_envio_asignado = obtenerEstadoEnvioAsignado($pdo);
                $estado_vehiculo_asignado = obtenerEstadoVehiculoAsignado($pdo);

                if ($estado_inicial_viaje === null) {
                    $mensaje = 'No se encontró un estado inicial válido para crear el viaje.';
                    $tipo_mensaje = 'error';
                } elseif ($estado_envio_asignado === null) {
                    $mensaje = 'No se encontró un estado válido para asignar envíos al viaje.';
                    $tipo_mensaje = 'error';
                }
            }

            if ($mensaje === '') {

                $sqlViajeExistente = "
                    SELECT 1
                    FROM Viaje
                    WHERE patente = :patente
                      AND fecha_salida = :fecha_salida
                    LIMIT 1
                ";

                $stmtViajeExistente = $pdo->prepare($sqlViajeExistente);
                $stmtViajeExistente->execute([
                    ':patente' => $patente,
                    ':fecha_salida' => $fecha_salida
                ]);

                if ($stmtViajeExistente->fetch()) {
                    $mensaje = 'Ya existe un viaje con ese vehiculo y esa fecha de salida. Cambia la fecha o selecciona otro vehiculo.';
                    $tipo_mensaje = 'error';
                }
            }

            if ($mensaje === '') {

                $pdo->beginTransaction();

                $sqlInsertarViaje = "
                    INSERT INTO Viaje (
                        patente,
                        fecha_salida,
                        fecha_llegada_estimada,
                        fecha_llegada_real,
                        legajo_chofer,
                        cod_sucursal_origen,
                        cod_sucursal_destino,
                        cod_estado_viaje
                    )
                    VALUES (
                        :patente,
                        :fecha_salida,
                        :fecha_llegada_estimada,
                        NULL,
                        :legajo_chofer,
                        :cod_sucursal_origen,
                        :cod_sucursal_destino,
                        :cod_estado_viaje
                    )
                ";

                $stmtInsertarViaje = $pdo->prepare($sqlInsertarViaje);
                $stmtInsertarViaje->execute([
                    ':patente' => $patente,
                    ':fecha_salida' => $fecha_salida,
                    ':fecha_llegada_estimada' => $fecha_llegada_estimada,
                    ':legajo_chofer' => $legajo_chofer,
                    ':cod_sucursal_origen' => $cod_sucursal_origen,
                    ':cod_sucursal_destino' => $cod_sucursal_destino,
                    ':cod_estado_viaje' => $estado_inicial_viaje
                ]);

                foreach ($envios_seleccionados as $tracking_sel) {

                    $tracking_sel = trim((string) $tracking_sel);

                    $sqlInsertarViajeEnvio = "
                        INSERT INTO Viaje_Envio (
                            patente,
                            fecha_salida,
                            nro_tracking,
                            fecha_asignacion
                        )
                        VALUES (
                            :patente,
                            :fecha_salida,
                            :nro_tracking,
                            NOW()
                        )
                    ";

                    $stmtInsertarViajeEnvio = $pdo->prepare($sqlInsertarViajeEnvio);
                    $stmtInsertarViajeEnvio->execute([
                        ':patente' => $patente,
                        ':fecha_salida' => $fecha_salida,
                        ':nro_tracking' => $tracking_sel
                    ]);

                    $sqlInsertarHistorial = "
                        CALL sp_registrar_movimiento_envio(
                            :nro_tracking,
                            :cod_estado_envio,
                            NOW(),
                            :cod_sucursal_actual,
                            :patente,
                            :fecha_salida,
                            :observaciones
                        )
                    ";

                    $stmtInsertarHistorial = $pdo->prepare($sqlInsertarHistorial);
                    $stmtInsertarHistorial->execute([
                        ':nro_tracking' => $tracking_sel,
                        ':cod_estado_envio' => $estado_envio_asignado,
                        ':cod_sucursal_actual' => $cod_sucursal_origen,
                        ':patente' => $patente,
                        ':fecha_salida' => $fecha_salida,
                        ':observaciones' => 'Envío asignado al viaje'
                    ]);
                    $stmtInsertarHistorial->closeCursor();
                }

                if ($estado_vehiculo_asignado !== null) {
                    $sqlActualizarVehiculo = "
                        UPDATE Vehiculo
                        SET cod_estado_vehiculo = :cod_estado_vehiculo
                        WHERE patente = :patente
                    ";

                    $stmtActualizarVehiculo = $pdo->prepare($sqlActualizarVehiculo);
                    $stmtActualizarVehiculo->execute([
                        ':cod_estado_vehiculo' => $estado_vehiculo_asignado,
                        ':patente' => $patente
                    ]);
                }

                $pdo->commit();

                header('Location: asignar_viaje.php?asignado=1');
                exit;

                $mensaje = 'Viaje creado y envíos asignados correctamente.';
                $tipo_mensaje = 'success';

                $envios_seleccionados = [];
                $tracking_seleccionado = '';
                $envio_seleccionado = null;
                $patente = '';
                $legajo_chofer = '';
                $fecha_salida = date('Y-m-d H:i:s');
                $fecha_llegada_estimada = date('Y-m-d H:i:s', strtotime('+4 hours'));

            }

        } catch (PDOException $e) {

            $detalle_error_bd = $e->getMessage();

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $mensaje = 'Ocurrió un error al crear el viaje y asignar los envíos.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 7. RECARGAR VIAJES RECIENTES
// -----------------------------------------------------

if ($cod_sucursal_origen !== '') {

    try {

        $sqlViajesRecientes = "
            SELECT
                v.patente,
                v.fecha_salida,
                v.fecha_llegada_estimada,
                v.legajo_chofer,
                ch.nombre AS nombre_chofer,
                ch.apellido AS apellido_chofer,
                sd.nombre AS nombre_destino,
                ev.nombre AS nombre_estado,
                COUNT(ve.nro_tracking) AS cantidad_envios
            FROM Viaje v
            INNER JOIN vista_chofer ch
                ON v.legajo_chofer = ch.legajo
            INNER JOIN Sucursal sd
                ON v.cod_sucursal_destino = sd.cod_sucursal
            INNER JOIN Estado_Viaje ev
                ON v.cod_estado_viaje = ev.cod_estado_viaje
            LEFT JOIN Viaje_Envio ve
                ON v.patente = ve.patente
               AND v.fecha_salida = ve.fecha_salida
            WHERE v.cod_sucursal_origen = :cod_sucursal_origen
            GROUP BY
                v.patente,
                v.fecha_salida,
                v.fecha_llegada_estimada,
                v.legajo_chofer,
                ch.nombre,
                ch.apellido,
                sd.nombre,
                ev.nombre
            ORDER BY v.fecha_salida DESC
            LIMIT 10
        ";

        $stmtViajesRecientes = $pdo->prepare($sqlViajesRecientes);
        $stmtViajesRecientes->execute([
            ':cod_sucursal_origen' => $cod_sucursal_origen
        ]);

        $viajes_recientes = $stmtViajesRecientes->fetchAll();

    } catch (PDOException $e) {

        $mensaje = 'No se pudieron cargar los viajes recientes.';
        $tipo_mensaje = 'error';
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Asignar Envío a Viaje</h1>
        <p class="page-subtitle">
            Seleccioná la ruta, el vehículo, el chofer y luego cargá envíos hasta completar la capacidad permitida por peso.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
            <?php if ($detalle_error_bd !== ''): ?>
                <br><small><?php echo htmlspecialchars($detalle_error_bd); ?></small>
            <?php endif; ?>
        </div>
    <?php endif; ?>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Envios pendientes para asignar</h3>

        <?php if ($rol_actual === 'EMPLEADO_SUCURSAL' && $cod_sucursal_empleado !== ''): ?>
            <p class="field-note" style="margin-bottom: 14px;">
                Se muestran los paquetes registrados y pendientes de la sucursal:
                <strong><?php echo htmlspecialchars($cod_sucursal_empleado . ' - ' . $nombre_sucursal_empleado); ?></strong>
            </p>
        <?php endif; ?>

        <div style="overflow-x: auto;">

            <table style="width: 100%; border-collapse: collapse; min-width: 1250px;">

                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tracking</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Origen</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destino</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Remitente</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destinatario</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Paquetes</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Peso</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Estado</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Accion</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($envios_pendientes)): ?>

                        <tr>
                            <td colspan="10" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                No hay paquetes pendientes para asignar a viaje.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($envios_pendientes as $pendiente): ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['nro_tracking']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['fecha_recepcion']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['cod_sucursal_origen'] . ' - ' . $pendiente['nombre_origen']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['cod_sucursal_destino'] . ' - ' . $pendiente['nombre_destino']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['apellido_remitente'] . ', ' . $pendiente['nombre_remitente']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['apellido_destinatario'] . ', ' . $pendiente['nombre_destinatario']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars((string) $pendiente['cantidad_paquetes']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars((string) $pendiente['peso_total']); ?> kg
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($pendiente['nombre_estado_actual'] ?? ''); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <a
                                        href="asignar_viaje.php?tracking=<?php echo urlencode($pendiente['nro_tracking']); ?>"
                                        class="btn-public-secondary"
                                    >
                                        Asignar
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php endif; ?>
                </tbody>

            </table>

        </div>

    </section>


    <?php if ($tracking_seleccionado !== '' && $envio_seleccionado): ?>

    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Configuración del viaje</h3>

        <?php if ($rol_actual === 'EMPLEADO_SUCURSAL' && $cod_sucursal_empleado !== ''): ?>
            <p class="field-note" style="margin-bottom: 14px;">
                Estás operando como sucursal:
                <strong><?php echo htmlspecialchars($cod_sucursal_empleado . ' - ' . $nombre_sucursal_empleado); ?></strong>
            </p>
        <?php endif; ?>

        <form method="GET" action="asignar_viaje.php">

            <input type="hidden" name="tracking" value="<?php echo htmlspecialchars($tracking_seleccionado); ?>">
            <input type="hidden" name="cod_sucursal_origen" value="<?php echo htmlspecialchars($cod_sucursal_origen); ?>">
            <input type="hidden" name="cod_sucursal_destino" value="<?php echo htmlspecialchars($cod_sucursal_destino); ?>">

            <div class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">

                <div>

                    <?php if ($rol_actual === 'ADMIN'): ?>

                        <div class="form-group">
                            <label for="cod_sucursal_origen">Sucursal de origen</label>
                            <select id="cod_sucursal_origen" name="cod_sucursal_origen" class="form-control" required disabled>
                                <option value="">Seleccione una sucursal</option>

                                <?php foreach ($sucursales as $sucursal): ?>
                                    <option
                                        value="<?php echo htmlspecialchars($sucursal['cod_sucursal']); ?>"
                                        <?php echo ($cod_sucursal_origen === $sucursal['cod_sucursal']) ? 'selected' : ''; ?>
                                    >
                                        <?php echo htmlspecialchars($sucursal['cod_sucursal'] . ' - ' . $sucursal['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    <?php else: ?>

                        <div class="form-group">
                            <label for="sucursal_origen_mostrar">Sucursal de origen</label>
                            <input
                                type="text"
                                id="sucursal_origen_mostrar"
                                class="form-control"
                                value="<?php echo htmlspecialchars($cod_sucursal_origen . ' - ' . $nombre_sucursal_empleado); ?>"
                                readonly
                            >
                        </div>

                    <?php endif; ?>

                    <div class="form-group">
                        <label for="cod_sucursal_destino">Sucursal de destino</label>
                        <select id="cod_sucursal_destino" name="cod_sucursal_destino" class="form-control" required disabled>
                            <option value="">Seleccione una sucursal</option>

                            <?php foreach ($sucursales_destino as $sucursal): ?>
                                <option
                                    value="<?php echo htmlspecialchars($sucursal['cod_sucursal']); ?>"
                                    <?php echo ($cod_sucursal_destino === $sucursal['cod_sucursal']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($sucursal['cod_sucursal'] . ' - ' . $sucursal['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="patente">Vehículo disponible</label>
                        <select id="patente" name="patente" class="form-control" required>
                            <option value="">Seleccione un vehículo</option>

                            <?php foreach ($vehiculos as $vehiculo): ?>
                                <option
                                    value="<?php echo htmlspecialchars($vehiculo['patente']); ?>"
                                    data-capacidad="<?php echo htmlspecialchars((string) $vehiculo['capacidad_kg_max']); ?>"
                                    <?php echo ($patente === $vehiculo['patente']) ? 'selected' : ''; ?>
                                >
                                    <?php
                                        echo htmlspecialchars(
                                            $vehiculo['patente'] . ' | ' .
                                            $vehiculo['marca'] . ' ' . $vehiculo['modelo'] . ' | ' .
                                            $vehiculo['tipo_vehiculo'] . ' | cap: ' .
                                            $vehiculo['capacidad_kg_max'] . ' kg | estado: ' .
                                            $vehiculo['nombre_estado_vehiculo']
                                        );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>

                <div>

                    <div class="form-group">
                        <label for="legajo_chofer">Chofer</label>
                        <select id="legajo_chofer" name="legajo_chofer" class="form-control" required>
                            <option value="">Seleccione un chofer</option>

                            <?php foreach ($choferes as $chofer): ?>
                                <option
                                    value="<?php echo htmlspecialchars($chofer['legajo']); ?>"
                                    <?php echo ($legajo_chofer === $chofer['legajo']) ? 'selected' : ''; ?>
                                >
                                    <?php
                                        echo htmlspecialchars(
                                            $chofer['legajo'] . ' | ' .
                                            $chofer['apellido'] . ', ' . $chofer['nombre'] . ' | licencia: ' .
                                            $chofer['nro_licencia']
                                        );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="fecha_salida">Fecha de salida</label>
                        <input
                            type="datetime-local"
                            id="fecha_salida"
                            name="fecha_salida"
                            class="form-control"
                            value="<?php echo htmlspecialchars(fechaAsignacionParaInput($fecha_salida)); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="fecha_llegada_estimada">Fecha estimada de llegada</label>
                        <input
                            type="datetime-local"
                            id="fecha_llegada_estimada"
                            name="fecha_llegada_estimada"
                            class="form-control"
                            value="<?php echo htmlspecialchars(fechaAsignacionParaInput($fecha_llegada_estimada)); ?>"
                            required
                        >
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 22px; flex-wrap: wrap;">
                        <button type="submit" class="btn-primary" style="width: auto;">
                            Cargar envíos elegibles
                        </button>

                        <a href="asignar_viaje.php" class="btn-public-secondary">
                            Limpiar
                        </a>
                    </div>

                </div>

            </div>

        </form>

    </section>

    <?php endif; ?>


    <?php if ($cod_sucursal_origen !== '' && $cod_sucursal_destino !== '' && $patente !== '' && $legajo_chofer !== ''): ?>

        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Carga de envíos al viaje</h3>

            <?php if ($vehiculo_actual): ?>
                <div class="dashboard-grid" style="margin-bottom: 18px;">
                    <article class="dashboard-card">
                        <h3 style="margin-top: 0;">Vehículo seleccionado</h3>
                        <p><strong>Patente:</strong> <?php echo htmlspecialchars($vehiculo_actual['patente']); ?></p>
                        <p><strong>Tipo:</strong> <?php echo htmlspecialchars($vehiculo_actual['tipo_vehiculo']); ?></p>
                        <p><strong>Capacidad máxima:</strong> <?php echo htmlspecialchars((string) $vehiculo_actual['capacidad_kg_max']); ?> kg</p>
                        <p><strong>Estado actual:</strong> <?php echo htmlspecialchars($vehiculo_actual['nombre_estado_vehiculo']); ?></p>
                    </article>

                    <article class="dashboard-card">
                        <h3 style="margin-top: 0;">Control de carga</h3>
                        <p><strong>Envíos seleccionados:</strong> <span id="contadorEnvios">0</span></p>
                        <p><strong>Peso total seleccionado:</strong> <span id="pesoSeleccionado">0.00</span> kg</p>
                        <p><strong>Capacidad restante:</strong> <span id="pesoRestante"><?php echo htmlspecialchars(number_format((float) $vehiculo_actual['capacidad_kg_max'], 2, '.', '')); ?></span> kg</p>
                    </article>
                </div>
            <?php endif; ?>

            <form method="POST" action="asignar_viaje.php">

                <input type="hidden" name="accion" value="crear_viaje">
                <input type="hidden" name="tracking" value="<?php echo htmlspecialchars($tracking_seleccionado); ?>">
                <input type="hidden" name="cod_sucursal_origen" value="<?php echo htmlspecialchars($cod_sucursal_origen); ?>">
                <input type="hidden" name="cod_sucursal_destino" value="<?php echo htmlspecialchars($cod_sucursal_destino); ?>">
                <input type="hidden" name="patente" value="<?php echo htmlspecialchars($patente); ?>">
                <input type="hidden" name="legajo_chofer" value="<?php echo htmlspecialchars($legajo_chofer); ?>">
                <input type="hidden" name="fecha_salida" value="<?php echo htmlspecialchars($fecha_salida); ?>">
                <input type="hidden" name="fecha_llegada_estimada" value="<?php echo htmlspecialchars($fecha_llegada_estimada); ?>">

                <div style="overflow-x: auto;">

                    <table style="width: 100%; border-collapse: collapse; min-width: 1300px;">

                        <thead>
                            <tr style="background-color: var(--color-surface-soft);">
                                <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Seleccionar</th>
                                <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tracking</th>
                                <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha recepción</th>
                                <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destinatario</th>
                                <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Cantidad paquetes</th>
                                <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Peso total</th>
                                <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Estado actual</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($envios_elegibles)): ?>

                                <tr>
                                    <td colspan="7" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                        No hay envíos elegibles para esta ruta y este estado.
                                    </td>
                                </tr>

                            <?php else: ?>

                                <?php foreach ($envios_elegibles as $envio_item): ?>
                                    <tr>
                                        <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                            <input
                                                type="checkbox"
                                                name="envios_seleccionados[]"
                                                value="<?php echo htmlspecialchars($envio_item['nro_tracking']); ?>"
                                                data-peso="<?php echo htmlspecialchars((string) $envio_item['peso_total']); ?>"
                                                <?php echo in_array($envio_item['nro_tracking'], $envios_seleccionados, true) ? 'checked' : ''; ?>
                                            >
                                        </td>
                                        <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                            <?php echo htmlspecialchars($envio_item['nro_tracking']); ?>
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
                                            <?php echo htmlspecialchars((string) $envio_item['peso_total']); ?> kg
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

                <?php if (!empty($envios_elegibles)): ?>
                    <div style="display: flex; gap: 12px; margin-top: 20px; flex-wrap: wrap;">
                        <button type="submit" class="btn-primary" style="width: auto;">
                            Crear viaje y asignar envíos
                        </button>
                    </div>
                <?php endif; ?>

            </form>

        </section>

    <?php endif; ?>


    <section class="dashboard-card">

        <h3 style="margin-top: 0; margin-bottom: 18px;">
            <?php echo $cod_sucursal_origen !== '' ? 'Viajes recientes de la sucursal seleccionada' : 'Viajes recientes'; ?>
        </h3>

        <?php if ($cod_sucursal_origen === ''): ?>

            <p style="margin: 0; color: var(--color-muted);">
                Seleccioná una sucursal de origen para comenzar a planificar un viaje.
            </p>

        <?php else: ?>

            <div style="overflow-x: auto;">

                <table style="width: 100%; border-collapse: collapse; min-width: 1200px;">

                    <thead>
                        <tr style="background-color: var(--color-surface-soft);">
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Patente</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha salida</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Llegada estimada</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Chofer</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destino</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Estado</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Cant. envíos</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($viajes_recientes)): ?>

                            <tr>
                                <td colspan="7" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                    No hay viajes recientes para mostrar.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($viajes_recientes as $viaje): ?>
                                <tr>
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
                                        <?php echo htmlspecialchars($viaje['legajo_chofer'] . ' - ' . $viaje['apellido_chofer'] . ', ' . $viaje['nombre_chofer']); ?>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($viaje['nombre_destino']); ?>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($viaje['nombre_estado']); ?>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars((string) $viaje['cantidad_envios']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                        <?php endif; ?>
                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const checkboxes = document.querySelectorAll('input[name="envios_seleccionados[]"]');
    const contadorEnvios = document.getElementById('contadorEnvios');
    const pesoSeleccionado = document.getElementById('pesoSeleccionado');
    const pesoRestante = document.getElementById('pesoRestante');
    const vehiculoSelect = document.getElementById('patente');

    function obtenerCapacidadMaxima() {
        if (!vehiculoSelect) {
            return 0;
        }

        const opcionSeleccionada = vehiculoSelect.options[vehiculoSelect.selectedIndex];

        if (!opcionSeleccionada) {
            return 0;
        }

        return parseFloat(opcionSeleccionada.getAttribute('data-capacidad') || '0');
    }

    function recalcularCarga() {
        let cantidad = 0;
        let peso = 0;

        checkboxes.forEach(function (checkbox) {
            if (checkbox.checked) {
                cantidad++;
                peso += parseFloat(checkbox.getAttribute('data-peso') || '0');
            }
        });

        const capacidad = obtenerCapacidadMaxima();
        const restante = capacidad - peso;

        if (contadorEnvios) {
            contadorEnvios.textContent = String(cantidad);
        }

        if (pesoSeleccionado) {
            pesoSeleccionado.textContent = peso.toFixed(2);
        }

        if (pesoRestante) {
            pesoRestante.textContent = restante.toFixed(2);
        }
    }

    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', recalcularCarga);
    });

    if (vehiculoSelect) {
        vehiculoSelect.addEventListener('change', recalcularCarga);
    }

    recalcularCarga();
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
