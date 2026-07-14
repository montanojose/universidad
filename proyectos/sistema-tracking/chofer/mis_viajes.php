<?php
// =====================================================
// mis_viajes.php
// Listado de viajes asignados al chofer
// - acceso para CHOFER y ADMIN
// - CHOFER: ve solo sus viajes
// - ADMIN: puede elegir chofer o ver todos
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'CHOFER']);

$titulo_pagina = 'Mis Viajes Asignados';

$mensaje = '';
$tipo_mensaje = '';

$rol_actual = $_SESSION['usuario_rol'] ?? '';

$legajo_chofer_sesion = '';
$legajo_chofer_filtro = trim($_GET['legajo_chofer'] ?? '');
$buscar = trim($_GET['buscar'] ?? '');
$filtro_estado = trim($_GET['filtro_estado'] ?? '');

$chofer_actual = null;
$choferes = [];
$estados_viaje = [];
$viajes = [];

$resumen = [
    'total' => 0,
    'con_envios' => 0,
    'en_progreso' => 0,
    'finalizados' => 0
];


// -----------------------------------------------------
// FUNCIONES AUXILIARES
// -----------------------------------------------------

function obtenerLegajoChoferSesion(): string
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

function estadoViajeNormalizadoMisViajes(array $viaje): string
{
    return strtoupper(str_replace(' ', '_', (string) ($viaje['cod_estado_viaje'] ?? $viaje['nombre_estado_viaje'] ?? '')));
}

function viajePuedeIniciarseMisViajes(array $viaje): bool
{
    $estado = estadoViajeNormalizadoMisViajes($viaje);
    return in_array($estado, ['PROGRAMADO', 'PLANIFICADO', 'ASIGNADO', 'CREADO'], true);
}

function viajeEstaEnCursoMisViajes(array $viaje): bool
{
    $estado = estadoViajeNormalizadoMisViajes($viaje);
    return in_array($estado, ['EN_CURSO', 'EN_TRANSITO', 'INICIADO', 'EN_PROGRESO'], true);
}

function viajeEstaFinalizadoMisViajes(array $viaje): bool
{
    $estado = estadoViajeNormalizadoMisViajes($viaje);
    return in_array($estado, ['FINALIZADO', 'COMPLETADO', 'CERRADO'], true);
}


// -----------------------------------------------------
// 1. IDENTIFICAR CHOFER SI ENTRA COMO CHOFER
// -----------------------------------------------------

if ($rol_actual === 'CHOFER') {

    $legajo_chofer_sesion = obtenerLegajoChoferSesion();

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
                    fecha_vencimiento_licencia,
                    cod_sucursal
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
// 2. CARGAR CATÁLOGOS Y CHOFERES SI ES ADMIN
// -----------------------------------------------------

try {

    $sqlEstados = "
        SELECT cod_estado_viaje, nombre
        FROM Estado_Viaje
        ORDER BY nombre ASC
    ";

    $estados_viaje = $pdo->query($sqlEstados)->fetchAll();

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

    $mensaje = 'No se pudieron cargar los datos auxiliares.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 3. LISTADO DE VIAJES
// -----------------------------------------------------

try {

    $sqlViajes = "
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

            so.nombre AS nombre_origen,
            sd.nombre AS nombre_destino,
            ev.nombre AS nombre_estado_viaje,

            COALESCE(stats.cantidad_envios, 0) AS cantidad_envios,
            COALESCE(stats.cantidad_paquetes, 0) AS cantidad_paquetes,
            COALESCE(stats.peso_total_kg, 0) AS peso_total_kg
        FROM Viaje v
        INNER JOIN vista_chofer ch
            ON v.legajo_chofer = ch.legajo
        INNER JOIN Sucursal so
            ON v.cod_sucursal_origen = so.cod_sucursal
        INNER JOIN Sucursal sd
            ON v.cod_sucursal_destino = sd.cod_sucursal
        INNER JOIN Estado_Viaje ev
            ON v.cod_estado_viaje = ev.cod_estado_viaje

        LEFT JOIN (
            SELECT
                ve.patente,
                ve.fecha_salida,
                COUNT(DISTINCT ve.nro_tracking) AS cantidad_envios,
                COUNT(p.nro_paquete) AS cantidad_paquetes,
                COALESCE(SUM(p.peso_kg), 0) AS peso_total_kg
            FROM Viaje_Envio ve
            LEFT JOIN Paquete p
                ON ve.nro_tracking = p.nro_tracking
            GROUP BY ve.patente, ve.fecha_salida
        ) stats
            ON v.patente = stats.patente
           AND v.fecha_salida = stats.fecha_salida

        WHERE 1 = 1
    ";

    $params = [];

    if ($legajo_chofer_filtro !== '') {
        $sqlViajes .= " AND v.legajo_chofer = :legajo_chofer ";
        $params[':legajo_chofer'] = $legajo_chofer_filtro;
    }

    if ($buscar !== '') {
        $sqlViajes .= "
            AND (
                v.patente LIKE :buscar
                OR so.nombre LIKE :buscar
                OR sd.nombre LIKE :buscar
                OR ch.nombre LIKE :buscar
                OR ch.apellido LIKE :buscar
            )
        ";
        $params[':buscar'] = '%' . $buscar . '%';
    }

    if ($filtro_estado !== '') {
        $sqlViajes .= " AND v.cod_estado_viaje = :filtro_estado ";
        $params[':filtro_estado'] = $filtro_estado;
    }

    $sqlViajes .= "
        ORDER BY
            v.fecha_salida DESC,
            v.patente ASC
    ";

    $stmtViajes = $pdo->prepare($sqlViajes);
    $stmtViajes->execute($params);

    $viajes = $stmtViajes->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'Ocurrió un error al cargar los viajes asignados.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 4. RESUMEN
// -----------------------------------------------------

if (!empty($viajes)) {

    $resumen['total'] = count($viajes);

    foreach ($viajes as $viaje) {

        if ((int) $viaje['cantidad_envios'] > 0) {
            $resumen['con_envios']++;
        }

        if (viajeEstaEnCursoMisViajes($viaje)) {
            $resumen['en_progreso']++;
        }

        if (viajeEstaFinalizadoMisViajes($viaje)) {
            $resumen['finalizados']++;
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Mis Viajes Asignados</h1>
        <p class="page-subtitle">
            Consultá los viajes que tenés asignados, su estado actual y la carga asociada.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>


    <?php if ($rol_actual === 'CHOFER' && $chofer_actual): ?>
        <section class="dashboard-card" style="margin-bottom: 24px;">
            <h3 style="margin-top: 0; margin-bottom: 12px;">Datos del chofer</h3>

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


    <section class="dashboard-grid" style="margin-bottom: 24px;">

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Total viajes</h3>
            <p style="font-size: 28px; margin: 0; font-weight: 700;">
                <?php echo htmlspecialchars((string) $resumen['total']); ?>
            </p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Con envíos cargados</h3>
            <p style="font-size: 28px; margin: 0; font-weight: 700;">
                <?php echo htmlspecialchars((string) $resumen['con_envios']); ?>
            </p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">En progreso</h3>
            <p style="font-size: 28px; margin: 0; font-weight: 700;">
                <?php echo htmlspecialchars((string) $resumen['en_progreso']); ?>
            </p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Finalizados</h3>
            <p style="font-size: 28px; margin: 0; font-weight: 700;">
                <?php echo htmlspecialchars((string) $resumen['finalizados']); ?>
            </p>
        </article>

    </section>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Buscar y filtrar viajes</h3>

        <form method="GET" action="mis_viajes.php">

            <div class="dashboard-grid" style="grid-template-columns: <?php echo $rol_actual === 'ADMIN' ? '1fr 2fr 1fr 1fr' : '2fr 1fr 1fr'; ?>;">

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
                        placeholder="Ej: AA123BB, Mendoza..."
                    >
                </div>

                <div class="form-group">
                    <label for="filtro_estado">Estado del viaje</label>
                    <select id="filtro_estado" name="filtro_estado" class="form-control">
                        <option value="">Todos</option>

                        <?php foreach ($estados_viaje as $estado): ?>
                            <option
                                value="<?php echo htmlspecialchars($estado['cod_estado_viaje']); ?>"
                                <?php echo ($filtro_estado === $estado['cod_estado_viaje']) ? 'selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars($estado['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="display: flex; align-items: flex-end; gap: 12px;">
                    <button type="submit" class="btn-primary" style="width: auto;">
                        Buscar
                    </button>

                    <a href="mis_viajes.php" class="btn-public-secondary">
                        Limpiar
                    </a>
                </div>

            </div>

        </form>

    </section>


    <section class="dashboard-card">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Listado de viajes asignados</h3>

        <div style="overflow-x: auto;">

            <table style="width: 100%; border-collapse: collapse; min-width: 1450px;">

                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Patente</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha salida</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Llegada estimada</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Llegada real</th>
                        <?php if ($rol_actual === 'ADMIN'): ?>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Chofer</th>
                        <?php endif; ?>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Origen</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destino</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Estado</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Cant. envíos</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Cant. paquetes</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Peso total</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($viajes)): ?>

                        <tr>
                            <td colspan="<?php echo $rol_actual === 'ADMIN' ? '12' : '11'; ?>" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                No hay viajes asignados con esos criterios.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($viajes as $viaje): ?>
                            <?php
                                $url_viaje = 'patente=' . urlencode($viaje['patente']) . '&fecha_salida=' . urlencode($viaje['fecha_salida']);
                                $puede_iniciar_viaje = viajePuedeIniciarseMisViajes($viaje) && (int) $viaje['cantidad_envios'] > 0;
                                $viaje_en_curso = viajeEstaEnCursoMisViajes($viaje);
                            ?>
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
                                    <?php echo htmlspecialchars($viaje['fecha_llegada_real'] ?? ''); ?>
                                </td>

                                <?php if ($rol_actual === 'ADMIN'): ?>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($viaje['legajo_chofer'] . ' - ' . $viaje['apellido_chofer'] . ', ' . $viaje['nombre_chofer']); ?>
                                    </td>
                                <?php endif; ?>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($viaje['nombre_origen']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($viaje['nombre_destino']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($viaje['nombre_estado_viaje']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars((string) $viaje['cantidad_envios']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars((string) $viaje['cantidad_paquetes']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars(number_format((float) $viaje['peso_total_kg'], 2, '.', '')); ?> kg
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border); white-space: nowrap;">
                                    <a href="detalle_viaje.php?<?php echo $url_viaje; ?>" class="btn-public-secondary">
                                        Ver detalle
                                    </a>

                                    <?php if ($puede_iniciar_viaje): ?>
                                        <a href="iniciar_viaje.php?<?php echo $url_viaje; ?>" class="btn-public-secondary">
                                            Iniciar
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($viaje_en_curso): ?>
                                        <a href="finalizar_viaje.php?<?php echo $url_viaje; ?>" class="btn-public-secondary">
                                            Finalizar
                                        </a>

                                        <a href="registrar_incidente.php?<?php echo $url_viaje; ?>" class="btn-public-secondary">
                                            Incidente
                                        </a>
                                    <?php endif; ?>
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
