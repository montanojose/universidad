<?php
// =====================================================
// sucursales.php
// CRUD funcional de sucursales
// - código automático SUC001, SUC002, ...
// - sin campo responsable en el formulario
// - buscador por código o nombre
// - filtro por tipo de sucursal
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN']);

$titulo_pagina = 'Gestión de Sucursales';

$mensaje = '';
$tipo_mensaje = '';

$sucursales = [];
$tipos_sucursal = [];
$localidades = [];

$modo_edicion = false;

$cod_sucursal = '';
$nombre = '';
$direccion = '';
$telefono = '';
$provincia = '';
$nombre_localidad = '';
$cod_tipo_sucursal = '';

$buscar = trim($_GET['buscar'] ?? '');
$filtro_tipo = trim($_GET['filtro_tipo'] ?? '');


// -----------------------------------------------------
// FUNCIÓN: generar próximo código de sucursal
// Formato: SUC001, SUC002, SUC003...
// -----------------------------------------------------

function generarCodigoSucursal(PDO $pdo): string
{
    $sql = "
        SELECT cod_sucursal
        FROM Sucursal
        WHERE cod_sucursal REGEXP '^SUC[0-9]+$'
        ORDER BY CAST(SUBSTRING(cod_sucursal, 4) AS UNSIGNED) DESC
        LIMIT 1
    ";

    $stmt = $pdo->query($sql);

    $ultima = $stmt->fetch();

    if (!$ultima) {
        return 'SUC001';
    }

    $numero = (int) substr($ultima['cod_sucursal'], 3);
    $numero++;

    return 'SUC' . str_pad((string) $numero, 3, '0', STR_PAD_LEFT);
}


// -----------------------------------------------------
// 1. ELIMINAR SUCURSAL
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {

    $cod_eliminar = trim($_POST['cod_sucursal'] ?? '');

    if ($cod_eliminar === '') {

        $mensaje = 'No se recibió la sucursal a eliminar.';
        $tipo_mensaje = 'error';

    } else {

        try {

            $sqlEliminar = "
                DELETE FROM Sucursal
                WHERE cod_sucursal = :cod_sucursal
            ";

            $stmtEliminar = $pdo->prepare($sqlEliminar);

            $stmtEliminar->execute([
                ':cod_sucursal' => $cod_eliminar
            ]);

            if ($stmtEliminar->rowCount() > 0) {
                $mensaje = 'Sucursal eliminada correctamente.';
                $tipo_mensaje = 'success';
            } else {
                $mensaje = 'No se encontró la sucursal seleccionada.';
                $tipo_mensaje = 'warning';
            }

        } catch (PDOException $e) {

            $mensaje = 'No se puede eliminar la sucursal porque está siendo utilizada por otros registros del sistema.';
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

    $cod_sucursal = trim($_POST['cod_sucursal'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $cod_tipo_sucursal = trim($_POST['cod_tipo_sucursal'] ?? '');

    $localidad_seleccionada = trim($_POST['localidad_seleccionada'] ?? '');

    if ($localidad_seleccionada !== '') {

        $partes = explode('||', $localidad_seleccionada);

        if (count($partes) === 2) {
            $provincia = $partes[0];
            $nombre_localidad = $partes[1];
        }
    }

    if (
        $cod_sucursal === '' ||
        $nombre === '' ||
        $direccion === '' ||
        $provincia === '' ||
        $nombre_localidad === '' ||
        $cod_tipo_sucursal === ''
    ) {

        $mensaje = 'Completá todos los campos obligatorios.';
        $tipo_mensaje = 'error';

    } else {

        try {

            if ($modo_edicion) {

                $sqlActualizar = "
                    UPDATE Sucursal
                    SET
                        nombre = :nombre,
                        direccion = :direccion,
                        telefono = :telefono,
                        provincia = :provincia,
                        nombre_localidad = :nombre_localidad,
                        cod_tipo_sucursal = :cod_tipo_sucursal
                    WHERE cod_sucursal = :cod_sucursal
                ";

                $stmtActualizar = $pdo->prepare($sqlActualizar);

                $stmtActualizar->execute([
                    ':nombre' => $nombre,
                    ':direccion' => $direccion,
                    ':telefono' => ($telefono !== '' ? $telefono : null),
                    ':provincia' => $provincia,
                    ':nombre_localidad' => $nombre_localidad,
                    ':cod_tipo_sucursal' => $cod_tipo_sucursal,
                    ':cod_sucursal' => $cod_sucursal
                ]);

                $mensaje = 'Sucursal actualizada correctamente.';
                $tipo_mensaje = 'success';

                $modo_edicion = false;
                $cod_sucursal = '';
                $nombre = '';
                $direccion = '';
                $telefono = '';
                $provincia = '';
                $nombre_localidad = '';
                $cod_tipo_sucursal = '';

            } else {

                $sqlInsertar = "
                    INSERT INTO Sucursal (
                        cod_sucursal,
                        nombre,
                        direccion,
                        telefono,
                        responsable,
                        provincia,
                        nombre_localidad,
                        cod_tipo_sucursal
                    )
                    VALUES (
                        :cod_sucursal,
                        :nombre,
                        :direccion,
                        :telefono,
                        NULL,
                        :provincia,
                        :nombre_localidad,
                        :cod_tipo_sucursal
                    )
                ";

                $stmtInsertar = $pdo->prepare($sqlInsertar);

                $stmtInsertar->execute([
                    ':cod_sucursal' => $cod_sucursal,
                    ':nombre' => $nombre,
                    ':direccion' => $direccion,
                    ':telefono' => ($telefono !== '' ? $telefono : null),
                    ':provincia' => $provincia,
                    ':nombre_localidad' => $nombre_localidad,
                    ':cod_tipo_sucursal' => $cod_tipo_sucursal
                ]);

                $mensaje = 'Sucursal registrada correctamente.';
                $tipo_mensaje = 'success';

                $cod_sucursal = '';
                $nombre = '';
                $direccion = '';
                $telefono = '';
                $provincia = '';
                $nombre_localidad = '';
                $cod_tipo_sucursal = '';
            }

        } catch (PDOException $e) {

            $mensaje = 'Ocurrió un error al guardar la sucursal. Verificá que el código no esté repetido y que los datos sean válidos.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 3. CARGAR SUCURSAL A EDITAR
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['editar'])) {

    $cod_editar = trim($_GET['editar']);

    if ($cod_editar !== '') {

        try {

            $sqlEditar = "
                SELECT
                    cod_sucursal,
                    nombre,
                    direccion,
                    telefono,
                    provincia,
                    nombre_localidad,
                    cod_tipo_sucursal
                FROM Sucursal
                WHERE cod_sucursal = :cod_sucursal
                LIMIT 1
            ";

            $stmtEditar = $pdo->prepare($sqlEditar);

            $stmtEditar->execute([
                ':cod_sucursal' => $cod_editar
            ]);

            $filaEditar = $stmtEditar->fetch();

            if ($filaEditar) {
                $modo_edicion = true;
                $cod_sucursal = $filaEditar['cod_sucursal'];
                $nombre = $filaEditar['nombre'];
                $direccion = $filaEditar['direccion'];
                $telefono = $filaEditar['telefono'] ?? '';
                $provincia = $filaEditar['provincia'];
                $nombre_localidad = $filaEditar['nombre_localidad'];
                $cod_tipo_sucursal = $filaEditar['cod_tipo_sucursal'];
            }

        } catch (PDOException $e) {

            $mensaje = 'No se pudo cargar la sucursal para edición.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 4. CARGAR TIPOS DE SUCURSAL
// -----------------------------------------------------

try {

    $sqlTipos = "
        SELECT
            cod_tipo_sucursal,
            nombre
        FROM Tipo_Sucursal
        ORDER BY nombre ASC
    ";

    $stmtTipos = $pdo->query($sqlTipos);
    $tipos_sucursal = $stmtTipos->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'No se pudieron cargar los tipos de sucursal.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 5. CARGAR LOCALIDADES
// -----------------------------------------------------

try {

    $sqlLocalidades = "
        SELECT
            provincia,
            nombre_localidad
        FROM Localidad
        ORDER BY provincia ASC, nombre_localidad ASC
    ";

    $stmtLocalidades = $pdo->query($sqlLocalidades);
    $localidades = $stmtLocalidades->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'No se pudieron cargar las localidades.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 6. CÓDIGO AUTOMÁTICO PARA ALTA
// -----------------------------------------------------

if (!$modo_edicion && $cod_sucursal === '') {

    try {
        $cod_sucursal = generarCodigoSucursal($pdo);
    } catch (PDOException $e) {
        $cod_sucursal = 'SUC001';
    }
}


// -----------------------------------------------------
// 7. LISTADO CON BÚSQUEDA Y FILTRO
// -----------------------------------------------------

try {

    $sqlListado = "
        SELECT
            s.cod_sucursal,
            s.nombre,
            s.direccion,
            s.telefono,
            s.provincia,
            s.nombre_localidad,
            ts.nombre AS tipo_sucursal,
            s.cod_tipo_sucursal
        FROM Sucursal s
        INNER JOIN Tipo_Sucursal ts
            ON s.cod_tipo_sucursal = ts.cod_tipo_sucursal
        WHERE 1 = 1
    ";

    $params = [];

    if ($buscar !== '') {
        $sqlListado .= "
            AND (
                s.cod_sucursal LIKE :buscar
                OR s.nombre LIKE :buscar
            )
        ";
        $params[':buscar'] = '%' . $buscar . '%';
    }

    if ($filtro_tipo !== '') {
        $sqlListado .= " AND s.cod_tipo_sucursal = :filtro_tipo ";
        $params[':filtro_tipo'] = $filtro_tipo;
    }

    $sqlListado .= " ORDER BY s.nombre ASC ";

    $stmtListado = $pdo->prepare($sqlListado);
    $stmtListado->execute($params);

    $sucursales = $stmtListado->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'Ocurrió un error al consultar las sucursales.';
    $tipo_mensaje = 'error';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">

        <h1 class="page-title">Gestión de Sucursales</h1>

        <p class="page-subtitle">
            En esta pantalla podés registrar, editar, consultar y eliminar sucursales del sistema.
        </p>

    </section>


    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">
            <?php echo $modo_edicion ? 'Editar sucursal' : 'Registrar nueva sucursal'; ?>
        </h3>

        <form method="POST" action="sucursales.php">

            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="modo_formulario" value="<?php echo $modo_edicion ? 'edicion' : 'alta'; ?>">

            <div class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">

                <div>

                    <div class="form-group">
                        <label for="cod_sucursal">Código de sucursal</label>
                        <input
                            type="text"
                            id="cod_sucursal"
                            name="cod_sucursal"
                            class="form-control"
                            value="<?php echo htmlspecialchars($cod_sucursal); ?>"
                            readonly
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="nombre">Nombre</label>
                        <input
                            type="text"
                            id="nombre"
                            name="nombre"
                            class="form-control"
                            value="<?php echo htmlspecialchars($nombre); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="direccion">Dirección</label>
                        <input
                            type="text"
                            id="direccion"
                            name="direccion"
                            class="form-control"
                            value="<?php echo htmlspecialchars($direccion); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="telefono">Teléfono</label>
                        <input
                            type="text"
                            id="telefono"
                            name="telefono"
                            class="form-control"
                            value="<?php echo htmlspecialchars($telefono); ?>"
                        >
                    </div>

                </div>

                <div>

                    <div class="form-group">
                        <label for="localidad_seleccionada">Localidad</label>
                        <select id="localidad_seleccionada" name="localidad_seleccionada" class="form-control" required>
                            <option value="">Seleccione una localidad</option>

                            <?php foreach ($localidades as $localidad): ?>
                                <?php
                                    $valor_localidad = $localidad['provincia'] . '||' . $localidad['nombre_localidad'];
                                    $selected_localidad = ($provincia === $localidad['provincia'] && $nombre_localidad === $localidad['nombre_localidad']) ? 'selected' : '';
                                ?>
                                <option value="<?php echo htmlspecialchars($valor_localidad); ?>" <?php echo $selected_localidad; ?>>
                                    <?php echo htmlspecialchars($localidad['provincia'] . ' - ' . $localidad['nombre_localidad']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="cod_tipo_sucursal">Tipo de sucursal</label>
                        <select id="cod_tipo_sucursal" name="cod_tipo_sucursal" class="form-control" required>
                            <option value="">Seleccione un tipo</option>

                            <?php foreach ($tipos_sucursal as $tipo): ?>
                                <option
                                    value="<?php echo htmlspecialchars($tipo['cod_tipo_sucursal']); ?>"
                                    <?php echo ($cod_tipo_sucursal === $tipo['cod_tipo_sucursal']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($tipo['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 30px; flex-wrap: wrap;">
                        <button type="submit" class="btn-primary" style="width: auto;">
                            <?php echo $modo_edicion ? 'Guardar cambios' : 'Registrar sucursal'; ?>
                        </button>

                        <?php if ($modo_edicion): ?>
                            <a href="sucursales.php" class="btn-public-secondary">
                                Cancelar edición
                            </a>
                        <?php endif; ?>
                    </div>

                </div>

            </div>

        </form>

    </section>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Buscar y filtrar sucursales</h3>

        <form method="GET" action="sucursales.php">

            <div class="dashboard-grid" style="grid-template-columns: 2fr 1fr 1fr;">

                <div class="form-group">
                    <label for="buscar">Buscar por código o nombre</label>
                    <input
                        type="text"
                        id="buscar"
                        name="buscar"
                        class="form-control"
                        value="<?php echo htmlspecialchars($buscar); ?>"
                        placeholder="Ej: SUC001 o Casa Central"
                    >
                </div>

                <div class="form-group">
                    <label for="filtro_tipo">Filtrar por tipo</label>
                    <select id="filtro_tipo" name="filtro_tipo" class="form-control">
                        <option value="">Todos los tipos</option>

                        <?php foreach ($tipos_sucursal as $tipo): ?>
                            <option
                                value="<?php echo htmlspecialchars($tipo['cod_tipo_sucursal']); ?>"
                                <?php echo ($filtro_tipo === $tipo['cod_tipo_sucursal']) ? 'selected' : ''; ?>
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

                    <a href="sucursales.php" class="btn-public-secondary">
                        Limpiar
                    </a>
                </div>

            </div>

        </form>

    </section>


    <section class="dashboard-card">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Listado de sucursales</h3>

        <div style="overflow-x: auto;">

            <table style="width: 100%; border-collapse: collapse; min-width: 1050px;">

                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Código</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Nombre</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Dirección</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Teléfono</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Provincia</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Localidad</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tipo</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($sucursales)): ?>

                        <tr>
                            <td colspan="8" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                No hay sucursales registradas con esos criterios.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($sucursales as $sucursal): ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($sucursal['cod_sucursal']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($sucursal['nombre']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($sucursal['direccion']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($sucursal['telefono'] ?? ''); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($sucursal['provincia']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($sucursal['nombre_localidad']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($sucursal['tipo_sucursal']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border); white-space: nowrap;">
                                    <a href="sucursales.php?editar=<?php echo urlencode($sucursal['cod_sucursal']); ?>&buscar=<?php echo urlencode($buscar); ?>&filtro_tipo=<?php echo urlencode($filtro_tipo); ?>" class="btn-public-secondary" style="margin-right: 8px;">
                                        Editar
                                    </a>

                                    <form method="POST" action="sucursales.php" style="display: inline;" onsubmit="return confirm('¿Seguro que querés eliminar esta sucursal?');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="cod_sucursal" value="<?php echo htmlspecialchars($sucursal['cod_sucursal']); ?>">
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
?>