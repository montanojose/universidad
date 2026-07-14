<?php
// =====================================================
// registrar_incidente.php
// Registro de incidentes durante el viaje
// - acceso para CHOFER y ADMIN
// - CHOFER: solo puede registrar incidentes en sus viajes
// - ADMIN: puede probar cualquier viaje
// - genera nro_incidente automático por viaje
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'CHOFER']);

$titulo_pagina = 'Registrar Incidente';

$mensaje = '';
$tipo_mensaje = '';

$rol_actual = $_SESSION['usuario_rol'] ?? '';

$patente = trim($_GET['patente'] ?? '');
$fecha_salida = trim($_GET['fecha_salida'] ?? '');

$legajo_chofer_sesion = '';
$legajo_chofer_filtro = trim($_GET['legajo_chofer'] ?? '');
$buscar = trim($_GET['buscar'] ?? '');

$fecha_hora_incidente = date('Y-m-d H:i:s');
$cod_tipo_incidente = '';
$descripcion = '';

$chofer_actual = null;
$choferes = [];
$tipos_incidente = [];

$viaje = null;
$incidentes = [];
$viajes_en_curso = [];

$puede_registrar = false;
$motivo_no_registrar = '';


// -----------------------------------------------------
// FUNCIONES AUXILIARES
// -----------------------------------------------------

function obtenerLegajoChoferSesionIncidente(): string
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

function normalizarFechaDatetimeLocalIncidente(string $valor): string
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

function fechaIncidenteParaInput(?string $fecha): string
{
    if (!$fecha) {
        return '';
    }

    return date('Y-m-d\TH:i', strtotime($fecha));
}

function obtenerProximoNumeroIncidente(PDO $pdo, string $patente, string $fecha_salida): int
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

function esEstadoViajeEnCursoIncidente(?string $codigo, ?string $nombre): bool
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

    $legajo_chofer_sesion = obtenerLegajoChoferSesionIncidente();

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
// 2. CARGAR CATÁLOGOS
// -----------------------------------------------------

try {

    $sqlTipos = "
        SELECT
            cod_tipo_incidente,
            nombre
        FROM Tipo_Incidente
        ORDER BY nombre ASC
    ";

    $tipos_incidente = $pdo->query($sqlTipos)->fetchAll();

    if ($rol_actual === 'ADMIN') {

        $sqlChoferes = "
            SELECT
                legajo,
                nombre,
                apellido
            FROM vista_chofer
            ORDER BY apellido ASC, nombre ASC
        ";

        $choferes = $pdo->query($sqlChoferes)->fetchAll();
    }

} catch (PDOException $e) {

    $mensaje = 'No se pudieron cargar los catálogos del formulario.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 3. PROCESAR ALTA DE INCIDENTE
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'registrar_incidente') {

    $patente = trim($_POST['patente'] ?? '');
    $fecha_salida = trim($_POST['fecha_salida'] ?? '');
    $fecha_hora_incidente = normalizarFechaDatetimeLocalIncidente($_POST['fecha_hora_incidente'] ?? '');
    $cod_tipo_incidente = trim($_POST['cod_tipo_incidente'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');

    if (
        $patente === '' ||
        $fecha_salida === '' ||
        $fecha_hora_incidente === '' ||
        $cod_tipo_incidente === '' ||
        $descripcion === ''
    ) {

        $mensaje = 'Completá todos los campos obligatorios para registrar el incidente.';
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
                    v.cod_estado_viaje,
                    ev.nombre AS nombre_estado_viaje
                FROM Viaje v
                INNER JOIN Estado_Viaje ev
                    ON v.cod_estado_viaje = ev.cod_estado_viaje
                WHERE v.patente = :patente
                  AND v.fecha_salida = :fecha_salida
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

                $mensaje = 'No tenés permiso para registrar incidentes en ese viaje.';
                $tipo_mensaje = 'error';

            } elseif (!esEstadoViajeEnCursoIncidente($viajeValidacion['cod_estado_viaje'], $viajeValidacion['nombre_estado_viaje'])) {

                $mensaje = 'Solo se pueden registrar incidentes en viajes que estén en curso.';
                $tipo_mensaje = 'warning';

            } elseif (strtotime($fecha_hora_incidente) < strtotime($viajeValidacion['fecha_salida'])) {

                $mensaje = 'La fecha del incidente no puede ser anterior a la salida del viaje.';
                $tipo_mensaje = 'error';

            } elseif (
                !empty($viajeValidacion['fecha_llegada_real']) &&
                strtotime($fecha_hora_incidente) > strtotime($viajeValidacion['fecha_llegada_real'])
            ) {

                $mensaje = 'La fecha del incidente no puede ser posterior a la llegada real del viaje.';
                $tipo_mensaje = 'error';

            } else {

                $nro_incidente = obtenerProximoNumeroIncidente($pdo, $patente, $fecha_salida);

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
                    ':patente' => $patente,
                    ':fecha_salida' => $fecha_salida,
                    ':nro_incidente' => $nro_incidente,
                    ':cod_tipo_incidente' => $cod_tipo_incidente,
                    ':descripcion' => $descripcion,
                    ':fecha_hora' => $fecha_hora_incidente
                ]);

                $mensaje = 'Incidente registrado correctamente.';
                $tipo_mensaje = 'success';

                $fecha_hora_incidente = date('Y-m-d H:i:s');
                $cod_tipo_incidente = '';
                $descripcion = '';
            }

        } catch (PDOException $e) {

            $mensaje = 'Ocurrió un error al registrar el incidente.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 4. CARGAR DETALLE DEL VIAJE
// -----------------------------------------------------

if ($patente !== '' && $fecha_salida !== '') {

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

                if (!esEstadoViajeEnCursoIncidente($viaje['cod_estado_viaje'], $viaje['nombre_estado_viaje'])) {
                    $puede_registrar = false;
                    $motivo_no_registrar = 'El viaje no está en curso.';
                } else {
                    $puede_registrar = true;
                    $motivo_no_registrar = '';
                }

                $sqlIncidentes = "
                    SELECT
                        i.nro_incidente,
                        i.cod_tipo_incidente,
                        i.descripcion,
                        i.fecha_hora,
                        ti.nombre AS nombre_tipo_incidente
                    FROM Incidente i
                    INNER JOIN Tipo_Incidente ti
                        ON i.cod_tipo_incidente = ti.cod_tipo_incidente
                    WHERE i.patente = :patente
                      AND i.fecha_salida = :fecha_salida
                    ORDER BY i.fecha_hora DESC, i.nro_incidente DESC
                ";

                $stmtIncidentes = $pdo->prepare($sqlIncidentes);
                $stmtIncidentes->execute([
                    ':patente' => $patente,
                    ':fecha_salida' => $fecha_salida
                ]);

                $incidentes = $stmtIncidentes->fetchAll();
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
        if (esEstadoViajeEnCursoIncidente($viaje_item['cod_estado_viaje'], $viaje_item['nombre_estado_viaje'])) {
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
        <h1 class="page-title">Registrar Incidente</h1>
        <p class="page-subtitle">
            Registrá incidentes ocurridos durante el viaje para dejar trazabilidad operativa del traslado.
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

        <form method="GET" action="registrar_incidente.php">

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

                    <a href="registrar_incidente.php" class="btn-public-secondary">
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


        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Registrar nuevo incidente</h3>

            <?php if ($puede_registrar): ?>

                <form method="POST" action="registrar_incidente.php?patente=<?php echo urlencode($patente); ?>&fecha_salida=<?php echo urlencode($fecha_salida); ?>">

                    <input type="hidden" name="accion" value="registrar_incidente">
                    <input type="hidden" name="patente" value="<?php echo htmlspecialchars($patente); ?>">
                    <input type="hidden" name="fecha_salida" value="<?php echo htmlspecialchars($fecha_salida); ?>">

                    <div class="dashboard-grid" style="grid-template-columns: 1fr 1fr;">

                        <div>

                            <div class="form-group">
                                <label for="fecha_hora_incidente">Fecha y hora del incidente</label>
                                <input
                                    type="datetime-local"
                                    id="fecha_hora_incidente"
                                    name="fecha_hora_incidente"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars(fechaIncidenteParaInput($fecha_hora_incidente)); ?>"
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

                        <div>

                            <div class="form-group">
                                <label for="descripcion">Descripción del incidente</label>
                                <textarea
                                    id="descripcion"
                                    name="descripcion"
                                    class="form-control"
                                    rows="6"
                                    required
                                ><?php echo htmlspecialchars($descripcion); ?></textarea>
                            </div>

                        </div>

                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 14px; flex-wrap: wrap;">
                        <button type="submit" class="btn-primary" style="width: auto;">
                            Registrar incidente
                        </button>
                    </div>

                </form>

            <?php else: ?>

                <div class="alert alert-warning" style="margin: 0;">
                    <?php echo htmlspecialchars($motivo_no_registrar !== '' ? $motivo_no_registrar : 'No se pueden registrar incidentes para este viaje.'); ?>
                </div>

            <?php endif; ?>

        </section>


        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 18px;">Incidentes registrados en el viaje</h3>

            <div style="overflow-x: auto;">

                <table style="width: 100%; border-collapse: collapse; min-width: 1050px;">

                    <thead>
                        <tr style="background-color: var(--color-surface-soft);">
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">N° incidente</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tipo</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha y hora</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Descripción</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($incidentes)): ?>

                            <tr>
                                <td colspan="4" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                    Todavía no hay incidentes registrados para este viaje.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($incidentes as $incidente): ?>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($incidente['nro_incidente']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($incidente['nombre_tipo_incidente']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($incidente['fecha_hora']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border); max-width: 420px;">
                                        <?php echo htmlspecialchars($incidente['descripcion']); ?>
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
                                    <a href="registrar_incidente.php?patente=<?php echo urlencode($viaje_item['patente']); ?>&fecha_salida=<?php echo urlencode($viaje_item['fecha_salida']); ?><?php echo $rol_actual === 'ADMIN' && $legajo_chofer_filtro !== '' ? '&legajo_chofer=' . urlencode($legajo_chofer_filtro) : ''; ?>" class="btn-public-secondary">
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

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
