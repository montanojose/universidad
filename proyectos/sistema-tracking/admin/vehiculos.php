<?php
// =====================================================
// vehiculos.php
// CRUD funcional de vehículos
// - alta
// - edición
// - eliminación física
// - buscador y filtros
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN']);

$titulo_pagina = 'Gestión de Vehículos';

$mensaje = '';
$tipo_mensaje = '';

$vehiculos = [];
$sucursales = [];
$tipos_vehiculo = [];
$estados_vehiculo = [];

$modo_edicion = false;

$patente_original = '';
$patente = '';
$marca = '';
$modelo = '';
$cod_tipo_vehiculo = '';
$cod_sucursal = '';
$cod_estado_vehiculo = '';

$buscar = trim($_GET['buscar'] ?? '');
$filtro_sucursal = trim($_GET['filtro_sucursal'] ?? '');
$filtro_estado = trim($_GET['filtro_estado'] ?? '');
$filtro_tipo = trim($_GET['filtro_tipo'] ?? '');


// -----------------------------------------------------
// 1. ELIMINAR VEHÍCULO
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {

    $patente_eliminar = trim($_POST['patente'] ?? '');

    if ($patente_eliminar === '') {

        $mensaje = 'No se recibió el vehículo a eliminar.';
        $tipo_mensaje = 'error';

    } else {

        try {

            $sqlEliminar = "
                DELETE FROM Vehiculo
                WHERE patente = :patente
            ";

            $stmtEliminar = $pdo->prepare($sqlEliminar);

            $stmtEliminar->execute([
                ':patente' => $patente_eliminar
            ]);

            if ($stmtEliminar->rowCount() > 0) {
                $mensaje = 'Vehículo eliminado correctamente.';
                $tipo_mensaje = 'success';
            } else {
                $mensaje = 'No se encontró el vehículo seleccionado.';
                $tipo_mensaje = 'warning';
            }

        } catch (PDOException $e) {

            $mensaje = 'No se puede eliminar el vehículo porque está siendo utilizado por otros registros del sistema.';
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
    $patente = strtoupper(trim($_POST['patente'] ?? ''));
    $marca = trim($_POST['marca'] ?? '');
    $modelo = trim($_POST['modelo'] ?? '');
    $cod_tipo_vehiculo = trim($_POST['cod_tipo_vehiculo'] ?? '');
    $cod_sucursal = trim($_POST['cod_sucursal'] ?? '');
    $cod_estado_vehiculo = trim($_POST['cod_estado_vehiculo'] ?? '');

    if (
        $patente === '' ||
        $marca === '' ||
        $modelo === '' ||
        $cod_tipo_vehiculo === '' ||
        $cod_sucursal === '' ||
        $cod_estado_vehiculo === ''
    ) {

        $mensaje = 'Completá todos los campos obligatorios.';
        $tipo_mensaje = 'error';

    } else {

        try {

            if ($modo_edicion) {

                $sqlActualizar = "
                    UPDATE Vehiculo
                    SET
                        patente = :patente_nueva,
                        marca = :marca,
                        modelo = :modelo,
                        cod_tipo_vehiculo = :cod_tipo_vehiculo,
                        cod_sucursal = :cod_sucursal,
                        cod_estado_vehiculo = :cod_estado_vehiculo
                    WHERE patente = :patente_original
                ";

                $stmtActualizar = $pdo->prepare($sqlActualizar);

                $stmtActualizar->execute([
                    ':patente_nueva' => $patente,
                    ':marca' => $marca,
                    ':modelo' => $modelo,
                    ':cod_tipo_vehiculo' => $cod_tipo_vehiculo,
                    ':cod_sucursal' => $cod_sucursal,
                    ':cod_estado_vehiculo' => $cod_estado_vehiculo,
                    ':patente_original' => $patente_original
                ]);

                $mensaje = 'Vehículo actualizado correctamente.';
                $tipo_mensaje = 'success';

                $modo_edicion = false;
                $patente_original = '';
                $patente = '';
                $marca = '';
                $modelo = '';
                $cod_tipo_vehiculo = '';
                $cod_sucursal = '';
                $cod_estado_vehiculo = '';

            } else {

                $sqlInsertar = "
                    INSERT INTO Vehiculo (
                        patente,
                        marca,
                        modelo,
                        cod_tipo_vehiculo,
                        cod_sucursal,
                        cod_estado_vehiculo
                    )
                    VALUES (
                        :patente,
                        :marca,
                        :modelo,
                        :cod_tipo_vehiculo,
                        :cod_sucursal,
                        :cod_estado_vehiculo
                    )
                ";

                $stmtInsertar = $pdo->prepare($sqlInsertar);

                $stmtInsertar->execute([
                    ':patente' => $patente,
                    ':marca' => $marca,
                    ':modelo' => $modelo,
                    ':cod_tipo_vehiculo' => $cod_tipo_vehiculo,
                    ':cod_sucursal' => $cod_sucursal,
                    ':cod_estado_vehiculo' => $cod_estado_vehiculo
                ]);

                $mensaje = 'Vehículo registrado correctamente.';
                $tipo_mensaje = 'success';

                $patente_original = '';
                $patente = '';
                $marca = '';
                $modelo = '';
                $cod_tipo_vehiculo = '';
                $cod_sucursal = '';
                $cod_estado_vehiculo = '';
            }

        } catch (PDOException $e) {

            $mensaje = 'Ocurrió un error al guardar el vehículo. Verificá que la patente no esté repetida.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 3. CARGAR VEHÍCULO PARA EDICIÓN
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['editar'])) {

    $patente_editar = trim($_GET['editar']);

    if ($patente_editar !== '') {

        try {

            $sqlEditar = "
                SELECT
                    patente,
                    marca,
                    modelo,
                    cod_tipo_vehiculo,
                    cod_sucursal,
                    cod_estado_vehiculo
                FROM Vehiculo
                WHERE patente = :patente
                LIMIT 1
            ";

            $stmtEditar = $pdo->prepare($sqlEditar);

            $stmtEditar->execute([
                ':patente' => $patente_editar
            ]);

            $filaEditar = $stmtEditar->fetch();

            if ($filaEditar) {
                $modo_edicion = true;
                $patente_original = $filaEditar['patente'];
                $patente = $filaEditar['patente'];
                $marca = $filaEditar['marca'];
                $modelo = $filaEditar['modelo'];
                $cod_tipo_vehiculo = $filaEditar['cod_tipo_vehiculo'];
                $cod_sucursal = $filaEditar['cod_sucursal'];
                $cod_estado_vehiculo = $filaEditar['cod_estado_vehiculo'];
            }

        } catch (PDOException $e) {

            $mensaje = 'No se pudo cargar el vehículo para edición.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 4. CARGAR SUCURSALES
// -----------------------------------------------------

try {

    $sqlSucursales = "
        SELECT
            cod_sucursal,
            nombre
        FROM Sucursal
        ORDER BY nombre ASC
    ";

    $stmtSucursales = $pdo->query($sqlSucursales);
    $sucursales = $stmtSucursales->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'No se pudieron cargar las sucursales.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 5. CARGAR TIPOS DE VEHÍCULO
// -----------------------------------------------------

try {

    $sqlTipos = "
        SELECT
            cod_tipo_vehiculo,
            nombre
        FROM Tipo_Vehiculo
        ORDER BY nombre ASC
    ";

    $stmtTipos = $pdo->query($sqlTipos);
    $tipos_vehiculo = $stmtTipos->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'No se pudieron cargar los tipos de vehículo.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 6. CARGAR ESTADOS DE VEHÍCULO
// -----------------------------------------------------

try {

    $sqlEstados = "
        SELECT
            cod_estado_vehiculo,
            nombre
        FROM Estado_Vehiculo
        ORDER BY nombre ASC
    ";

    $stmtEstados = $pdo->query($sqlEstados);
    $estados_vehiculo = $stmtEstados->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'No se pudieron cargar los estados de vehículo.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 7. LISTADO CON BÚSQUEDA Y FILTROS
// -----------------------------------------------------

try {

    $sqlListado = "
        SELECT
            v.patente,
            v.marca,
            v.modelo,
            v.cod_tipo_vehiculo,
            v.cod_sucursal,
            v.cod_estado_vehiculo,
            tv.nombre AS tipo_vehiculo,
            s.nombre AS nombre_sucursal,
            ev.nombre AS estado_vehiculo
        FROM Vehiculo v
        INNER JOIN Tipo_Vehiculo tv
            ON v.cod_tipo_vehiculo = tv.cod_tipo_vehiculo
        INNER JOIN Sucursal s
            ON v.cod_sucursal = s.cod_sucursal
        INNER JOIN Estado_Vehiculo ev
            ON v.cod_estado_vehiculo = ev.cod_estado_vehiculo
        WHERE 1 = 1
    ";

    $params = [];

    if ($buscar !== '') {
        $sqlListado .= "
            AND (
                v.patente LIKE :buscar
                OR v.marca LIKE :buscar
                OR v.modelo LIKE :buscar
            )
        ";
        $params[':buscar'] = '%' . $buscar . '%';
    }

    if ($filtro_sucursal !== '') {
        $sqlListado .= " AND v.cod_sucursal = :filtro_sucursal ";
        $params[':filtro_sucursal'] = $filtro_sucursal;
    }

    if ($filtro_estado !== '') {
        $sqlListado .= " AND v.cod_estado_vehiculo = :filtro_estado ";
        $params[':filtro_estado'] = $filtro_estado;
    }

    if ($filtro_tipo !== '') {
        $sqlListado .= " AND v.cod_tipo_vehiculo = :filtro_tipo ";
        $params[':filtro_tipo'] = $filtro_tipo;
    }

    $sqlListado .= " ORDER BY v.patente ASC ";

    $stmtListado = $pdo->prepare($sqlListado);
    $stmtListado->execute($params);

    $vehiculos = $stmtListado->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'Ocurrió un error al consultar los vehículos.';
    $tipo_mensaje = 'error';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">

        <h1 class="page-title">Gestión de Vehículos</h1>

        <p class="page-subtitle">
            En esta pantalla podés registrar, editar, consultar y eliminar vehículos del sistema.
        </p>

    </section>


    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">
            <?php echo $modo_edicion ? 'Editar vehículo' : 'Registrar nuevo vehículo'; ?>
        </h3>

        <form method="POST" action="vehiculos.php">

            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="modo_formulario" value="<?php echo $modo_edicion ? 'edicion' : 'alta'; ?>">
            <input type="hidden" name="patente_original" value="<?php echo htmlspecialchars($patente_original); ?>">

            <div class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">

                <div>

                    <div class="form-group">
                        <label for="patente">Patente</label>
                        <input
                            type="text"
                            id="patente"
                            name="patente"
                            class="form-control"
                            value="<?php echo htmlspecialchars($patente); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="marca">Marca</label>
                        <input
                            type="text"
                            id="marca"
                            name="marca"
                            class="form-control"
                            value="<?php echo htmlspecialchars($marca); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="modelo">Modelo</label>
                        <input
                            type="text"
                            id="modelo"
                            name="modelo"
                            class="form-control"
                            value="<?php echo htmlspecialchars($modelo); ?>"
                            required
                        >
                    </div>

                </div>

                <div>

                    <div class="form-group">
                        <label for="cod_tipo_vehiculo">Tipo de vehículo</label>
                        <select id="cod_tipo_vehiculo" name="cod_tipo_vehiculo" class="form-control" required>
                            <option value="">Seleccione un tipo</option>

                            <?php foreach ($tipos_vehiculo as $tipo): ?>
                                <option
                                    value="<?php echo htmlspecialchars($tipo['cod_tipo_vehiculo']); ?>"
                                    <?php echo ($cod_tipo_vehiculo === $tipo['cod_tipo_vehiculo']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($tipo['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="cod_sucursal">Sucursal</label>
                        <select id="cod_sucursal" name="cod_sucursal" class="form-control" required>
                            <option value="">Seleccione una sucursal</option>

                            <?php foreach ($sucursales as $sucursal): ?>
                                <option
                                    value="<?php echo htmlspecialchars($sucursal['cod_sucursal']); ?>"
                                    <?php echo ($cod_sucursal === $sucursal['cod_sucursal']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($sucursal['cod_sucursal'] . ' - ' . $sucursal['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="cod_estado_vehiculo">Estado del vehículo</label>
                        <select id="cod_estado_vehiculo" name="cod_estado_vehiculo" class="form-control" required>
                            <option value="">Seleccione un estado</option>

                            <?php foreach ($estados_vehiculo as $estado_item): ?>
                                <option
                                    value="<?php echo htmlspecialchars($estado_item['cod_estado_vehiculo']); ?>"
                                    <?php echo ($cod_estado_vehiculo === $estado_item['cod_estado_vehiculo']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($estado_item['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 30px; flex-wrap: wrap;">
                        <button type="submit" class="btn-primary" style="width: auto;">
                            <?php echo $modo_edicion ? 'Guardar cambios' : 'Registrar vehículo'; ?>
                        </button>

                        <?php if ($modo_edicion): ?>
                            <a href="vehiculos.php" class="btn-public-secondary">
                                Cancelar edición
                            </a>
                        <?php endif; ?>
                    </div>

                </div>

            </div>

        </form>

    </section>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Buscar y filtrar vehículos</h3>

        <form method="GET" action="vehiculos.php">

            <div class="dashboard-grid" style="grid-template-columns: 2fr 1fr 1fr 1fr 1fr;">

                <div class="form-group">
                    <label for="buscar">Buscar por patente, marca o modelo</label>
                    <input
                        type="text"
                        id="buscar"
                        name="buscar"
                        class="form-control"
                        value="<?php echo htmlspecialchars($buscar); ?>"
                        placeholder="Ej: AA123BB, Ford, Transit..."
                    >
                </div>

                <div class="form-group">
                    <label for="filtro_sucursal">Filtrar por sucursal</label>
                    <select id="filtro_sucursal" name="filtro_sucursal" class="form-control">
                        <option value="">Todas</option>

                        <?php foreach ($sucursales as $sucursal): ?>
                            <option
                                value="<?php echo htmlspecialchars($sucursal['cod_sucursal']); ?>"
                                <?php echo ($filtro_sucursal === $sucursal['cod_sucursal']) ? 'selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars($sucursal['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="filtro_estado">Filtrar por estado</label>
                    <select id="filtro_estado" name="filtro_estado" class="form-control">
                        <option value="">Todos</option>

                        <?php foreach ($estados_vehiculo as $estado_item): ?>
                            <option
                                value="<?php echo htmlspecialchars($estado_item['cod_estado_vehiculo']); ?>"
                                <?php echo ($filtro_estado === $estado_item['cod_estado_vehiculo']) ? 'selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars($estado_item['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="filtro_tipo">Filtrar por tipo</label>
                    <select id="filtro_tipo" name="filtro_tipo" class="form-control">
                        <option value="">Todos</option>

                        <?php foreach ($tipos_vehiculo as $tipo): ?>
                            <option
                                value="<?php echo htmlspecialchars($tipo['cod_tipo_vehiculo']); ?>"
                                <?php echo ($filtro_tipo === $tipo['cod_tipo_vehiculo']) ? 'selected' : ''; ?>
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

                    <a href="vehiculos.php" class="btn-public-secondary">
                        Limpiar
                    </a>
                </div>

            </div>

        </form>

    </section>


    <section class="dashboard-card">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Listado de vehículos</h3>

        <div style="overflow-x: auto;">

            <table style="width: 100%; border-collapse: collapse; min-width: 1250px;">

                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Patente</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Marca</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Modelo</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tipo</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Sucursal</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Estado</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($vehiculos)): ?>

                        <tr>
                            <td colspan="7" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                No hay vehículos registrados con esos criterios.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($vehiculos as $vehiculo): ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($vehiculo['patente']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($vehiculo['marca']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($vehiculo['modelo']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($vehiculo['tipo_vehiculo']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($vehiculo['nombre_sucursal']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($vehiculo['estado_vehiculo']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border); white-space: nowrap;">

                                    <a href="vehiculos.php?editar=<?php echo urlencode($vehiculo['patente']); ?>&buscar=<?php echo urlencode($buscar); ?>&filtro_sucursal=<?php echo urlencode($filtro_sucursal); ?>&filtro_estado=<?php echo urlencode($filtro_estado); ?>&filtro_tipo=<?php echo urlencode($filtro_tipo); ?>" class="btn-public-secondary" style="margin-right: 8px;">
                                        Editar
                                    </a>

                                    <form method="POST" action="vehiculos.php" style="display: inline;" onsubmit="return confirm('¿Seguro que querés eliminar este vehículo?');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="patente" value="<?php echo htmlspecialchars($vehiculo['patente']); ?>">
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