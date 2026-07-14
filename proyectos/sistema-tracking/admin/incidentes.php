<?php
// =====================================================
// incidentes.php
// CRUD funcional de incidentes
// - alta
// - edición
// - eliminación física
// - nro_incidente automático por viaje
// - buscador y filtro por tipo
// - clave compuesta: patente + fecha_salida + nro_incidente
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN']);

$titulo_pagina = 'Gestión de Incidentes';

$mensaje = '';
$tipo_mensaje = '';

$incidentes = [];
$tipos_incidente = [];
$viajes = [];

$modo_edicion = false;

$patente_original = '';
$fecha_salida_original = '';
$nro_incidente_original = '';

$viaje_seleccionado = '';
$patente = '';
$fecha_salida = '';
$nro_incidente = '';
$cod_tipo_incidente = '';
$descripcion = '';
$fecha_hora = date('Y-m-d H:i:s');

$buscar = trim($_GET['buscar'] ?? '');
$filtro_tipo = trim($_GET['filtro_tipo'] ?? '');


// -----------------------------------------------------
// FUNCIONES AUXILIARES
// -----------------------------------------------------

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

function generarNumeroIncidente(PDO $pdo, string $patente, string $fecha_salida): int
{
    $sql = "
        SELECT COALESCE(MAX(nro_incidente), 0) AS max_nro
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

    return ((int) ($fila['max_nro'] ?? 0)) + 1;
}


// -----------------------------------------------------
// 1. ELIMINAR INCIDENTE
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {

    $patente_eliminar = trim($_POST['patente'] ?? '');
    $fecha_salida_eliminar = trim($_POST['fecha_salida'] ?? '');
    $nro_incidente_eliminar = trim($_POST['nro_incidente'] ?? '');

    if ($patente_eliminar === '' || $fecha_salida_eliminar === '' || $nro_incidente_eliminar === '') {

        $mensaje = 'No se recibieron correctamente los datos del incidente a eliminar.';
        $tipo_mensaje = 'error';

    } else {

        try {

            $sqlEliminar = "
                DELETE FROM Incidente
                WHERE patente = :patente
                  AND fecha_salida = :fecha_salida
                  AND nro_incidente = :nro_incidente
            ";

            $stmtEliminar = $pdo->prepare($sqlEliminar);

            $stmtEliminar->execute([
                ':patente' => $patente_eliminar,
                ':fecha_salida' => $fecha_salida_eliminar,
                ':nro_incidente' => $nro_incidente_eliminar
            ]);

            if ($stmtEliminar->rowCount() > 0) {
                $mensaje = 'Incidente eliminado correctamente.';
                $tipo_mensaje = 'success';
            } else {
                $mensaje = 'No se encontró el incidente seleccionado.';
                $tipo_mensaje = 'warning';
            }

        } catch (PDOException $e) {

            $mensaje = 'No se pudo eliminar el incidente.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 2. ALTA O EDICIÓN
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {

    $modo_formulario = trim($_POST['modo_formulario'] ?? 'alta');
    $modo_edicion = ($modo_formulario === 'edicion');

    $patente_original = trim($_POST['patente_original'] ?? '');
    $fecha_salida_original = trim($_POST['fecha_salida_original'] ?? '');
    $nro_incidente_original = trim($_POST['nro_incidente_original'] ?? '');

    $cod_tipo_incidente = trim($_POST['cod_tipo_incidente'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $fecha_hora = normalizarFechaDatetimeLocalIncidente($_POST['fecha_hora'] ?? '');

    if ($modo_edicion) {

        $patente = trim($_POST['patente'] ?? '');
        $fecha_salida = trim($_POST['fecha_salida'] ?? '');
        $nro_incidente = trim($_POST['nro_incidente'] ?? '');

    } else {

        $viaje_seleccionado = trim($_POST['viaje_seleccionado'] ?? '');

        if ($viaje_seleccionado !== '') {
            $partes = explode('||', $viaje_seleccionado);

            if (count($partes) === 2) {
                $patente = $partes[0];
                $fecha_salida = $partes[1];
            }
        }
    }

    if (
        $patente === '' ||
        $fecha_salida === '' ||
        $cod_tipo_incidente === '' ||
        $descripcion === '' ||
        $fecha_hora === ''
    ) {

        $mensaje = 'Completá todos los campos obligatorios.';
        $tipo_mensaje = 'error';

    } else {

        try {

            if ($modo_edicion) {

                $sqlActualizar = "
                    UPDATE Incidente
                    SET
                        cod_tipo_incidente = :cod_tipo_incidente,
                        descripcion = :descripcion,
                        fecha_hora = :fecha_hora
                    WHERE patente = :patente_original
                      AND fecha_salida = :fecha_salida_original
                      AND nro_incidente = :nro_incidente_original
                ";

                $stmtActualizar = $pdo->prepare($sqlActualizar);

                $stmtActualizar->execute([
                    ':cod_tipo_incidente' => $cod_tipo_incidente,
                    ':descripcion' => $descripcion,
                    ':fecha_hora' => $fecha_hora,
                    ':patente_original' => $patente_original,
                    ':fecha_salida_original' => $fecha_salida_original,
                    ':nro_incidente_original' => $nro_incidente_original
                ]);

                $mensaje = 'Incidente actualizado correctamente.';
                $tipo_mensaje = 'success';

                $modo_edicion = false;
                $patente_original = '';
                $fecha_salida_original = '';
                $nro_incidente_original = '';
                $viaje_seleccionado = '';
                $patente = '';
                $fecha_salida = '';
                $nro_incidente = '';
                $cod_tipo_incidente = '';
                $descripcion = '';
                $fecha_hora = date('Y-m-d H:i:s');

            } else {

                $nro_incidente = generarNumeroIncidente($pdo, $patente, $fecha_salida);

                $sqlInsertar = "
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

                $stmtInsertar = $pdo->prepare($sqlInsertar);

                $stmtInsertar->execute([
                    ':patente' => $patente,
                    ':fecha_salida' => $fecha_salida,
                    ':nro_incidente' => $nro_incidente,
                    ':cod_tipo_incidente' => $cod_tipo_incidente,
                    ':descripcion' => $descripcion,
                    ':fecha_hora' => $fecha_hora
                ]);

                $mensaje = 'Incidente registrado correctamente.';
                $tipo_mensaje = 'success';

                $viaje_seleccionado = '';
                $patente = '';
                $fecha_salida = '';
                $nro_incidente = '';
                $cod_tipo_incidente = '';
                $descripcion = '';
                $fecha_hora = date('Y-m-d H:i:s');
            }

        } catch (PDOException $e) {

            $mensaje = 'Ocurrió un error al guardar el incidente.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 3. CARGAR INCIDENTE PARA EDICIÓN
// -----------------------------------------------------

if (
    $_SERVER['REQUEST_METHOD'] === 'GET' &&
    isset($_GET['editar_patente']) &&
    isset($_GET['editar_fecha']) &&
    isset($_GET['editar_nro'])
) {
    $patente_editar = trim($_GET['editar_patente']);
    $fecha_editar = trim($_GET['editar_fecha']);
    $nro_editar = trim($_GET['editar_nro']);

    if ($patente_editar !== '' && $fecha_editar !== '' && $nro_editar !== '') {

        try {

            $sqlEditar = "
                SELECT
                    patente,
                    fecha_salida,
                    nro_incidente,
                    cod_tipo_incidente,
                    descripcion,
                    fecha_hora
                FROM Incidente
                WHERE patente = :patente
                  AND fecha_salida = :fecha_salida
                  AND nro_incidente = :nro_incidente
                LIMIT 1
            ";

            $stmtEditar = $pdo->prepare($sqlEditar);

            $stmtEditar->execute([
                ':patente' => $patente_editar,
                ':fecha_salida' => $fecha_editar,
                ':nro_incidente' => $nro_editar
            ]);

            $filaEditar = $stmtEditar->fetch();

            if ($filaEditar) {
                $modo_edicion = true;

                $patente_original = $filaEditar['patente'];
                $fecha_salida_original = $filaEditar['fecha_salida'];
                $nro_incidente_original = $filaEditar['nro_incidente'];

                $patente = $filaEditar['patente'];
                $fecha_salida = $filaEditar['fecha_salida'];
                $nro_incidente = $filaEditar['nro_incidente'];
                $cod_tipo_incidente = $filaEditar['cod_tipo_incidente'];
                $descripcion = $filaEditar['descripcion'];
                $fecha_hora = $filaEditar['fecha_hora'];
            }

        } catch (PDOException $e) {

            $mensaje = 'No se pudo cargar el incidente para edición.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 4. CARGAR DATOS AUXILIARES
// -----------------------------------------------------

try {

    $tipos_incidente = $pdo->query("
        SELECT cod_tipo_incidente, nombre
        FROM Tipo_Incidente
        ORDER BY nombre ASC
    ")->fetchAll();

    $viajes = $pdo->query("
        SELECT
            v.patente,
            v.fecha_salida,
            ch.apellido AS apellido_chofer,
            ch.nombre AS nombre_chofer,
            so.nombre AS nombre_origen,
            sd.nombre AS nombre_destino
        FROM Viaje v
        INNER JOIN vista_chofer ch
            ON v.legajo_chofer = ch.legajo
        INNER JOIN Sucursal so
            ON v.cod_sucursal_origen = so.cod_sucursal
        INNER JOIN Sucursal sd
            ON v.cod_sucursal_destino = sd.cod_sucursal
        ORDER BY v.fecha_salida DESC, v.patente ASC
    ")->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'No se pudieron cargar los datos auxiliares del formulario.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 5. LISTADO DE INCIDENTES
// -----------------------------------------------------

try {

    $sqlListado = "
        SELECT
            i.patente,
            i.fecha_salida,
            i.nro_incidente,
            i.cod_tipo_incidente,
            i.descripcion,
            i.fecha_hora,
            ti.nombre AS nombre_tipo_incidente,
            ch.legajo AS legajo_chofer,
            ch.nombre AS nombre_chofer,
            ch.apellido AS apellido_chofer,
            so.nombre AS nombre_origen,
            sd.nombre AS nombre_destino
        FROM Incidente i
        INNER JOIN Tipo_Incidente ti
            ON i.cod_tipo_incidente = ti.cod_tipo_incidente
        INNER JOIN Viaje v
            ON i.patente = v.patente
           AND i.fecha_salida = v.fecha_salida
        INNER JOIN vista_chofer ch
            ON v.legajo_chofer = ch.legajo
        INNER JOIN Sucursal so
            ON v.cod_sucursal_origen = so.cod_sucursal
        INNER JOIN Sucursal sd
            ON v.cod_sucursal_destino = sd.cod_sucursal
        WHERE 1 = 1
    ";

    $params = [];

    if ($buscar !== '') {
        $sqlListado .= "
            AND (
                i.patente LIKE :buscar
                OR ch.legajo LIKE :buscar
                OR ch.nombre LIKE :buscar
                OR ch.apellido LIKE :buscar
                OR i.descripcion LIKE :buscar
            )
        ";
        $params[':buscar'] = '%' . $buscar . '%';
    }

    if ($filtro_tipo !== '') {
        $sqlListado .= " AND i.cod_tipo_incidente = :filtro_tipo ";
        $params[':filtro_tipo'] = $filtro_tipo;
    }

    $sqlListado .= " ORDER BY i.fecha_hora DESC, i.patente ASC, i.nro_incidente ASC ";

    $stmtListado = $pdo->prepare($sqlListado);
    $stmtListado->execute($params);

    $incidentes = $stmtListado->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'Ocurrió un error al consultar los incidentes.';
    $tipo_mensaje = 'error';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Gestión de Incidentes</h1>
        <p class="page-subtitle">
            En esta pantalla podés registrar, editar, consultar y eliminar incidentes asociados a los viajes.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>

    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">
            <?php echo $modo_edicion ? 'Editar incidente' : 'Registrar nuevo incidente'; ?>
        </h3>

        <form method="POST" action="incidentes.php">

            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="modo_formulario" value="<?php echo $modo_edicion ? 'edicion' : 'alta'; ?>">

            <input type="hidden" name="patente_original" value="<?php echo htmlspecialchars($patente_original); ?>">
            <input type="hidden" name="fecha_salida_original" value="<?php echo htmlspecialchars($fecha_salida_original); ?>">
            <input type="hidden" name="nro_incidente_original" value="<?php echo htmlspecialchars($nro_incidente_original); ?>">

            <div class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">

                <div>

                    <?php if ($modo_edicion): ?>

                        <div class="form-group">
                            <label for="patente">Patente</label>
                            <input
                                type="text"
                                id="patente"
                                name="patente"
                                class="form-control"
                                value="<?php echo htmlspecialchars($patente); ?>"
                                readonly
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="fecha_salida">Fecha de salida</label>
                            <input
                                type="text"
                                id="fecha_salida"
                                name="fecha_salida"
                                class="form-control"
                                value="<?php echo htmlspecialchars($fecha_salida); ?>"
                                readonly
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="nro_incidente">Número de incidente</label>
                            <input
                                type="text"
                                id="nro_incidente"
                                name="nro_incidente"
                                class="form-control"
                                value="<?php echo htmlspecialchars($nro_incidente); ?>"
                                readonly
                                required
                            >
                        </div>

                    <?php else: ?>

                        <div class="form-group">
                            <label for="viaje_seleccionado">Viaje</label>
                            <select id="viaje_seleccionado" name="viaje_seleccionado" class="form-control" required>
                                <option value="">Seleccione un viaje</option>

                                <?php foreach ($viajes as $viaje): ?>
                                    <?php
                                        $valor_viaje = $viaje['patente'] . '||' . $viaje['fecha_salida'];
                                    ?>
                                    <option
                                        value="<?php echo htmlspecialchars($valor_viaje); ?>"
                                        <?php echo ($viaje_seleccionado === $valor_viaje) ? 'selected' : ''; ?>
                                    >
                                        <?php
                                            echo htmlspecialchars(
                                                $viaje['patente'] . ' | ' .
                                                $viaje['fecha_salida'] . ' | ' .
                                                $viaje['nombre_origen'] . ' → ' .
                                                $viaje['nombre_destino'] . ' | ' .
                                                $viaje['apellido_chofer'] . ', ' . $viaje['nombre_chofer']
                                            );
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    <?php endif; ?>

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
                        <label for="fecha_hora">Fecha y hora del incidente</label>
                        <input
                            type="datetime-local"
                            id="fecha_hora"
                            name="fecha_hora"
                            class="form-control"
                            value="<?php echo htmlspecialchars(fechaIncidenteParaInput($fecha_hora)); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="descripcion">Descripción</label>
                        <textarea
                            id="descripcion"
                            name="descripcion"
                            class="form-control"
                            rows="5"
                            required
                        ><?php echo htmlspecialchars($descripcion); ?></textarea>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 30px; flex-wrap: wrap;">
                        <button type="submit" class="btn-primary" style="width: auto;">
                            <?php echo $modo_edicion ? 'Guardar cambios' : 'Registrar incidente'; ?>
                        </button>

                        <?php if ($modo_edicion): ?>
                            <a href="incidentes.php" class="btn-public-secondary">
                                Cancelar edición
                            </a>
                        <?php endif; ?>
                    </div>

                </div>

            </div>

        </form>

    </section>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Buscar y filtrar incidentes</h3>

        <form method="GET" action="incidentes.php">

            <div class="dashboard-grid" style="grid-template-columns: 2fr 1fr 1fr;">

                <div class="form-group">
                    <label for="buscar">Buscar por patente, chofer o descripción</label>
                    <input
                        type="text"
                        id="buscar"
                        name="buscar"
                        class="form-control"
                        value="<?php echo htmlspecialchars($buscar); ?>"
                        placeholder="Ej: AA123BB, Pérez, choque..."
                    >
                </div>

                <div class="form-group">
                    <label for="filtro_tipo">Filtrar por tipo</label>
                    <select id="filtro_tipo" name="filtro_tipo" class="form-control">
                        <option value="">Todos</option>

                        <?php foreach ($tipos_incidente as $tipo): ?>
                            <option
                                value="<?php echo htmlspecialchars($tipo['cod_tipo_incidente']); ?>"
                                <?php echo ($filtro_tipo === $tipo['cod_tipo_incidente']) ? 'selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars($tipo['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="display: flex; align-items: flex-end; gap: 12px;">
                    <button type="submit" class="btn-primary" style="width: auto;">
                        Buscar
                    </button>

                    <a href="incidentes.php" class="btn-public-secondary">
                        Limpiar
                    </a>
                </div>

            </div>

        </form>

    </section>


    <section class="dashboard-card">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Listado de incidentes</h3>

        <div style="overflow-x: auto;">

            <table style="width: 100%; border-collapse: collapse; min-width: 1450px;">

                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Patente</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha salida</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">N° incidente</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tipo</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha y hora</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Chofer</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Trayecto</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Descripción</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($incidentes)): ?>

                        <tr>
                            <td colspan="9" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                No hay incidentes registrados con esos criterios.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($incidentes as $incidente): ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($incidente['patente']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($incidente['fecha_salida']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($incidente['nro_incidente']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($incidente['nombre_tipo_incidente']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($incidente['fecha_hora']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($incidente['legajo_chofer'] . ' - ' . $incidente['apellido_chofer'] . ', ' . $incidente['nombre_chofer']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($incidente['nombre_origen'] . ' → ' . $incidente['nombre_destino']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border); max-width: 260px;">
                                    <?php echo htmlspecialchars($incidente['descripcion']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border); white-space: nowrap;">

                                    <a href="incidentes.php?editar_patente=<?php echo urlencode($incidente['patente']); ?>&editar_fecha=<?php echo urlencode($incidente['fecha_salida']); ?>&editar_nro=<?php echo urlencode($incidente['nro_incidente']); ?>&buscar=<?php echo urlencode($buscar); ?>&filtro_tipo=<?php echo urlencode($filtro_tipo); ?>" class="btn-public-secondary" style="margin-right: 8px;">
                                        Editar
                                    </a>

                                    <form method="POST" action="incidentes.php" style="display: inline;" onsubmit="return confirm('¿Seguro que querés eliminar este incidente?');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="patente" value="<?php echo htmlspecialchars($incidente['patente']); ?>">
                                        <input type="hidden" name="fecha_salida" value="<?php echo htmlspecialchars($incidente['fecha_salida']); ?>">
                                        <input type="hidden" name="nro_incidente" value="<?php echo htmlspecialchars($incidente['nro_incidente']); ?>">
                                        <button type="submit" class="btn-public-secondary" style="border-color: #f0b6b6; color: #a32626;">
                                            Eliminar
                                        </button>
                                    </form>

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
?>|