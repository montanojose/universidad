<?php
// =====================================================
// retiros.php
// CRUD funcional de retiros
// - alta
// - edición
// - eliminación física
// - buscador y filtros
// - usa Disponibilidad_Retiro y Autorizado_Retiro
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN']);

$titulo_pagina = 'Gestión de Retiros';

$mensaje = '';
$tipo_mensaje = '';

$retiros = [];
$disponibilidades = [];
$autorizados = [];
$sucursales = [];

$modo_edicion = false;

$nro_tracking = '';
$fecha_hora_retiro = date('Y-m-d H:i:s');
$tipo_retirante = 'DESTINATARIO';
$dni_autorizado = '';
$observaciones = '';

$buscar = trim($_GET['buscar'] ?? '');
$filtro_sucursal = trim($_GET['filtro_sucursal'] ?? '');
$filtro_tipo = trim($_GET['filtro_tipo'] ?? '');
$buscar_es_tracking = $buscar !== '' && preg_match('/^(TRK)?[0-9]+$/i', preg_replace('/[^a-zA-Z0-9]/', '', $buscar));


// -----------------------------------------------------
// FUNCIONES AUXILIARES
// -----------------------------------------------------

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


// -----------------------------------------------------
// 1. ELIMINAR RETIRO
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {

    $tracking_eliminar = trim($_POST['nro_tracking'] ?? '');

    if ($tracking_eliminar === '') {

        $mensaje = 'No se recibió el retiro a eliminar.';
        $tipo_mensaje = 'error';

    } else {

        try {

            $sqlEliminar = "
                DELETE FROM Retiro_Envio
                WHERE nro_tracking = :nro_tracking
            ";

            $stmtEliminar = $pdo->prepare($sqlEliminar);

            $stmtEliminar->execute([
                ':nro_tracking' => $tracking_eliminar
            ]);

            if ($stmtEliminar->rowCount() > 0) {
                $mensaje = 'Retiro eliminado correctamente.';
                $tipo_mensaje = 'success';
            } else {
                $mensaje = 'No se encontró el retiro seleccionado.';
                $tipo_mensaje = 'warning';
            }

        } catch (PDOException $e) {

            $mensaje = 'No se pudo eliminar el retiro.';
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

    $nro_tracking = trim($_POST['nro_tracking'] ?? '');
    $fecha_hora_retiro = normalizarFechaDatetimeLocalRetiro($_POST['fecha_hora_retiro'] ?? '');
    $tipo_retirante = trim($_POST['tipo_retirante'] ?? 'DESTINATARIO');
    $dni_autorizado = trim($_POST['dni_autorizado'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');

    if (
        $nro_tracking === '' ||
        $fecha_hora_retiro === '' ||
        !in_array($tipo_retirante, ['DESTINATARIO', 'AUTORIZADO'], true)
    ) {

        $mensaje = 'Completá todos los campos obligatorios.';
        $tipo_mensaje = 'error';

    } else {

        try {

            $sqlDisponibilidad = "
                SELECT
                    dr.nro_tracking,
                    dr.cod_sucursal_retiro,
                    e.dni_destinatario
                FROM Disponibilidad_Retiro dr
                INNER JOIN Envio e
                    ON dr.nro_tracking = e.nro_tracking
                WHERE dr.nro_tracking = :nro_tracking
                LIMIT 1
            ";

            $stmtDisponibilidad = $pdo->prepare($sqlDisponibilidad);

            $stmtDisponibilidad->execute([
                ':nro_tracking' => $nro_tracking
            ]);

            $disponibilidad = $stmtDisponibilidad->fetch();

            if (!$disponibilidad) {

                $mensaje = 'El tracking seleccionado no tiene disponibilidad de retiro registrada.';
                $tipo_mensaje = 'error';

            } else {

                $cod_sucursal_retiro = $disponibilidad['cod_sucursal_retiro'];
                $dni_cliente_retirante = null;
                $dni_autorizado_final = null;

                if ($tipo_retirante === 'DESTINATARIO') {

                    $dni_cliente_retirante = $disponibilidad['dni_destinatario'];
                    $dni_autorizado_final = null;

                } else {

                    if ($dni_autorizado === '') {

                        $mensaje = 'Debés seleccionar una persona autorizada.';
                        $tipo_mensaje = 'error';

                    } else {

                        $sqlAutorizado = "
                            SELECT nro_tracking, dni_autorizado
                            FROM Autorizado_Retiro
                            WHERE nro_tracking = :nro_tracking
                              AND dni_autorizado = :dni_autorizado
                            LIMIT 1
                        ";

                        $stmtAutorizado = $pdo->prepare($sqlAutorizado);

                        $stmtAutorizado->execute([
                            ':nro_tracking' => $nro_tracking,
                            ':dni_autorizado' => $dni_autorizado
                        ]);

                        $autorizadoValido = $stmtAutorizado->fetch();

                        if (!$autorizadoValido) {
                            $mensaje = 'La persona autorizada seleccionada no corresponde a ese envío.';
                            $tipo_mensaje = 'error';
                        } else {
                            $dni_cliente_retirante = null;
                            $dni_autorizado_final = $dni_autorizado;
                        }
                    }
                }

                if ($mensaje === '') {

                    if ($modo_edicion) {

                        $sqlActualizar = "
                            UPDATE Retiro_Envio
                            SET
                                fecha_hora_retiro = :fecha_hora_retiro,
                                cod_sucursal_retiro = :cod_sucursal_retiro,
                                tipo_retirante = :tipo_retirante,
                                dni_cliente_retirante = :dni_cliente_retirante,
                                dni_autorizado = :dni_autorizado,
                                observaciones = :observaciones
                            WHERE nro_tracking = :nro_tracking
                        ";

                        $stmtActualizar = $pdo->prepare($sqlActualizar);

                        $stmtActualizar->execute([
                            ':fecha_hora_retiro' => $fecha_hora_retiro,
                            ':cod_sucursal_retiro' => $cod_sucursal_retiro,
                            ':tipo_retirante' => $tipo_retirante,
                            ':dni_cliente_retirante' => $dni_cliente_retirante,
                            ':dni_autorizado' => $dni_autorizado_final,
                            ':observaciones' => ($observaciones !== '' ? $observaciones : null),
                            ':nro_tracking' => $nro_tracking
                        ]);

                        $mensaje = 'Retiro actualizado correctamente.';
                        $tipo_mensaje = 'success';

                    } else {

                        $sqlInsertar = "
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
                                :tipo_retirante,
                                :dni_cliente_retirante,
                                :dni_autorizado,
                                :observaciones
                            )
                        ";

                        $stmtInsertar = $pdo->prepare($sqlInsertar);

                        $stmtInsertar->execute([
                            ':nro_tracking' => $nro_tracking,
                            ':fecha_hora_retiro' => $fecha_hora_retiro,
                            ':cod_sucursal_retiro' => $cod_sucursal_retiro,
                            ':tipo_retirante' => $tipo_retirante,
                            ':dni_cliente_retirante' => $dni_cliente_retirante,
                            ':dni_autorizado' => $dni_autorizado_final,
                            ':observaciones' => ($observaciones !== '' ? $observaciones : null)
                        ]);

                        $mensaje = 'Retiro registrado correctamente.';
                        $tipo_mensaje = 'success';
                    }

                    $modo_edicion = false;
                    $nro_tracking = '';
                    $fecha_hora_retiro = date('Y-m-d H:i:s');
                    $tipo_retirante = 'DESTINATARIO';
                    $dni_autorizado = '';
                    $observaciones = '';
                }
            }

        } catch (PDOException $e) {

            $mensaje = 'Ocurrió un error al guardar el retiro.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 3. CARGAR RETIRO PARA EDICIÓN
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['editar'])) {

    $tracking_editar = trim($_GET['editar']);

    if ($tracking_editar !== '') {

        try {

            $sqlEditar = "
                SELECT
                    nro_tracking,
                    fecha_hora_retiro,
                    tipo_retirante,
                    dni_autorizado,
                    observaciones
                FROM Retiro_Envio
                WHERE nro_tracking = :nro_tracking
                LIMIT 1
            ";

            $stmtEditar = $pdo->prepare($sqlEditar);

            $stmtEditar->execute([
                ':nro_tracking' => $tracking_editar
            ]);

            $filaEditar = $stmtEditar->fetch();

            if ($filaEditar) {
                $modo_edicion = true;
                $nro_tracking = $filaEditar['nro_tracking'];
                $fecha_hora_retiro = $filaEditar['fecha_hora_retiro'];
                $tipo_retirante = $filaEditar['tipo_retirante'];
                $dni_autorizado = $filaEditar['dni_autorizado'] ?? '';
                $observaciones = $filaEditar['observaciones'] ?? '';
            }

        } catch (PDOException $e) {

            $mensaje = 'No se pudo cargar el retiro para edición.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 4. CARGAR DATOS AUXILIARES
// -----------------------------------------------------

try {

    $sucursales = $pdo->query("
        SELECT cod_sucursal, nombre
        FROM Sucursal
        ORDER BY nombre ASC
    ")->fetchAll();

    $sqlDisponibilidades = "
        SELECT
            dr.nro_tracking,
            dr.cod_sucursal_retiro,
            dr.fecha_disponible,
            dr.fecha_limite_retiro,
            s.nombre AS nombre_sucursal,
            e.dni_destinatario,
            c.nombre AS nombre_destinatario,
            c.apellido AS apellido_destinatario,
            re.nro_tracking AS ya_retirado
        FROM Disponibilidad_Retiro dr
        INNER JOIN Sucursal s
            ON dr.cod_sucursal_retiro = s.cod_sucursal
        INNER JOIN Envio e
            ON dr.nro_tracking = e.nro_tracking
        INNER JOIN vista_cliente c
            ON e.dni_destinatario = c.dni
        LEFT JOIN Retiro_Envio re
            ON dr.nro_tracking = re.nro_tracking
        ORDER BY dr.fecha_disponible DESC, dr.nro_tracking ASC
    ";

    $disponibilidades = $pdo->query($sqlDisponibilidades)->fetchAll();

    $sqlAutorizados = "
        SELECT
            ar.nro_tracking,
            ar.dni_autorizado,
            ar.nombre,
            ar.apellido,
            ar.vinculo
        FROM Autorizado_Retiro ar
        ORDER BY ar.nro_tracking ASC, ar.apellido ASC, ar.nombre ASC
    ";

    $autorizados = $pdo->query($sqlAutorizados)->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'No se pudieron cargar los datos auxiliares del formulario.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 5. LISTADO DE RETIROS
// -----------------------------------------------------

try {

    $sqlListado = "
        SELECT
            re.nro_tracking,
            re.fecha_hora_retiro,
            re.cod_sucursal_retiro,
            re.tipo_retirante,
            re.dni_cliente_retirante,
            re.dni_autorizado,
            re.observaciones,
            s.nombre AS nombre_sucursal,
            e.dni_destinatario,
            cd.nombre AS nombre_destinatario,
            cd.apellido AS apellido_destinatario,
            ar.nombre AS nombre_autorizado,
            ar.apellido AS apellido_autorizado
        FROM Retiro_Envio re
        INNER JOIN Disponibilidad_Retiro dr
            ON re.nro_tracking = dr.nro_tracking
        INNER JOIN Sucursal s
            ON re.cod_sucursal_retiro = s.cod_sucursal
        INNER JOIN Envio e
            ON re.nro_tracking = e.nro_tracking
        INNER JOIN vista_cliente cd
            ON e.dni_destinatario = cd.dni
        LEFT JOIN Autorizado_Retiro ar
            ON re.nro_tracking = ar.nro_tracking
           AND re.dni_autorizado = ar.dni_autorizado
        WHERE 1 = 1
    ";

    $params = [];

    if ($buscar_es_tracking) {
        $sqlListado .= " AND re.nro_tracking LIKE :buscar ";
        $params[':buscar'] = '%' . $buscar . '%';
    } elseif ($buscar !== '') {
        $sqlListado .= "
            AND (
                re.nro_tracking LIKE :buscar
                OR e.dni_destinatario LIKE :buscar
                OR cd.nombre LIKE :buscar
                OR cd.apellido LIKE :buscar
                OR re.dni_autorizado LIKE :buscar
            )
        ";
        $params[':buscar'] = '%' . $buscar . '%';
    }

    if (!$buscar_es_tracking && $filtro_sucursal !== '') {
        $sqlListado .= " AND re.cod_sucursal_retiro = :filtro_sucursal ";
        $params[':filtro_sucursal'] = $filtro_sucursal;
    }

    if (!$buscar_es_tracking && $filtro_tipo !== '') {
        $sqlListado .= " AND re.tipo_retirante = :filtro_tipo ";
        $params[':filtro_tipo'] = $filtro_tipo;
    }

    $sqlListado .= " ORDER BY re.fecha_hora_retiro DESC, re.nro_tracking ASC ";

    $stmtListado = $pdo->prepare($sqlListado);
    $stmtListado->execute($params);

    $retiros = $stmtListado->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'Ocurrió un error al consultar los retiros.';
    $tipo_mensaje = 'error';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Gestión de Retiros</h1>
        <p class="page-subtitle">
            En esta pantalla podés registrar, editar, consultar y eliminar retiros de envíos.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">
            <?php echo $modo_edicion ? 'Editar retiro' : 'Registrar nuevo retiro'; ?>
        </h3>

        <form method="POST" action="retiros.php">

            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="modo_formulario" value="<?php echo $modo_edicion ? 'edicion' : 'alta'; ?>">

            <div class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">

                <div>

                    <div class="form-group">
                        <label for="nro_tracking">Tracking</label>

                        <?php if ($modo_edicion): ?>
                            <input
                                type="text"
                                id="nro_tracking"
                                name="nro_tracking"
                                class="form-control"
                                value="<?php echo htmlspecialchars($nro_tracking); ?>"
                                readonly
                                required
                            >
                        <?php else: ?>
                            <select id="nro_tracking" name="nro_tracking" class="form-control" required>
                                <option value="">Seleccione un envío disponible</option>

                                <?php foreach ($disponibilidades as $disp): ?>
                                    <?php
                                        $permitir = empty($disp['ya_retirado']);
                                    ?>
                                    <?php if ($permitir): ?>
                                        <option
                                            value="<?php echo htmlspecialchars($disp['nro_tracking']); ?>"
                                            <?php echo ($nro_tracking === $disp['nro_tracking']) ? 'selected' : ''; ?>
                                        >
                                            <?php
                                                echo htmlspecialchars(
                                                    $disp['nro_tracking'] . ' | ' .
                                                    $disp['apellido_destinatario'] . ', ' . $disp['nombre_destinatario'] . ' | ' .
                                                    $disp['nombre_sucursal'] . ' | límite: ' . $disp['fecha_limite_retiro']
                                                );
                                            ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="fecha_hora_retiro">Fecha y hora de retiro</label>
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
                        <label for="tipo_retirante">Tipo de retirante</label>
                        <select id="tipo_retirante" name="tipo_retirante" class="form-control" required>
                            <option value="DESTINATARIO" <?php echo $tipo_retirante === 'DESTINATARIO' ? 'selected' : ''; ?>>DESTINATARIO</option>
                            <option value="AUTORIZADO" <?php echo $tipo_retirante === 'AUTORIZADO' ? 'selected' : ''; ?>>AUTORIZADO</option>
                        </select>
                    </div>

                </div>

                <div>

                    <div class="form-group" id="grupo_autorizado">
                        <label for="dni_autorizado">Persona autorizada</label>
                        <select id="dni_autorizado" name="dni_autorizado" class="form-control">
                            <option value="">Seleccione un autorizado</option>

                            <?php foreach ($autorizados as $autorizado): ?>
                                <option
                                    value="<?php echo htmlspecialchars($autorizado['dni_autorizado']); ?>"
                                    <?php echo ($dni_autorizado === $autorizado['dni_autorizado']) ? 'selected' : ''; ?>
                                >
                                    <?php
                                        echo htmlspecialchars(
                                            $autorizado['nro_tracking'] . ' | ' .
                                            $autorizado['dni_autorizado'] . ' | ' .
                                            $autorizado['apellido'] . ', ' . $autorizado['nombre'] .
                                            ($autorizado['vinculo'] ? ' | ' . $autorizado['vinculo'] : '')
                                        );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <p style="margin-top: 6px; color: var(--color-muted); font-size: 13px;">
                            Debe corresponder al tracking seleccionado.
                        </p>
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

                    <p style="margin-top: 6px; color: var(--color-muted); font-size: 13px; line-height: 1.5;">
                        Si el retiro es del destinatario, el sistema tomará automáticamente el DNI del destinatario del envío.
                    </p>

                    <div style="display: flex; gap: 12px; margin-top: 22px; flex-wrap: wrap;">
                        <button type="submit" class="btn-primary" style="width: auto;">
                            <?php echo $modo_edicion ? 'Guardar cambios' : 'Registrar retiro'; ?>
                        </button>

                        <?php if ($modo_edicion): ?>
                            <a href="retiros.php" class="btn-public-secondary">
                                Cancelar edición
                            </a>
                        <?php endif; ?>
                    </div>

                </div>

            </div>

        </form>

    </section>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Buscar y filtrar retiros</h3>

        <form method="GET" action="retiros.php">

            <div class="dashboard-grid" style="grid-template-columns: 2fr 1fr 1fr 1fr;">

                <div class="form-group">
                    <label for="buscar">Buscar por tracking, destinatario o autorizado</label>
                    <input
                        type="text"
                        id="buscar"
                        name="buscar"
                        class="form-control"
                        value="<?php echo htmlspecialchars($buscar); ?>"
                        placeholder="Ej: TRK000001, Pérez, 30111222..."
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
                    <label for="filtro_tipo">Filtrar por tipo</label>
                    <select id="filtro_tipo" name="filtro_tipo" class="form-control">
                        <option value="">Todos</option>
                        <option value="DESTINATARIO" <?php echo $filtro_tipo === 'DESTINATARIO' ? 'selected' : ''; ?>>DESTINATARIO</option>
                        <option value="AUTORIZADO" <?php echo $filtro_tipo === 'AUTORIZADO' ? 'selected' : ''; ?>>AUTORIZADO</option>
                    </select>
                </div>

                <div class="form-group" style="display: flex; align-items: flex-end; gap: 12px;">
                    <button type="submit" class="btn-primary" style="width: auto;">
                        Buscar
                    </button>

                    <a href="retiros.php" class="btn-public-secondary">
                        Limpiar
                    </a>
                </div>

            </div>

        </form>

    </section>


    <section class="dashboard-card">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Listado de retiros</h3>

        <div style="overflow-x: auto;">

            <table style="width: 100%; border-collapse: collapse; min-width: 1400px;">

                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tracking</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha y hora</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Sucursal</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tipo</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Retirante</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Observaciones</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($retiros)): ?>

                        <tr>
                            <td colspan="7" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                No hay retiros registrados con esos criterios.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($retiros as $retiro): ?>

                            <?php
                                $retirante = '';

                                if ($retiro['tipo_retirante'] === 'DESTINATARIO') {
                                    $retirante = $retiro['dni_destinatario'] . ' - ' . $retiro['apellido_destinatario'] . ', ' . $retiro['nombre_destinatario'];
                                } else {
                                    $retirante = ($retiro['dni_autorizado'] ?? '') . ' - ' . ($retiro['apellido_autorizado'] ?? '') . ', ' . ($retiro['nombre_autorizado'] ?? '');
                                }
                            ?>

                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($retiro['nro_tracking']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($retiro['fecha_hora_retiro']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($retiro['nombre_sucursal']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($retiro['tipo_retirante']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($retirante); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border); max-width: 260px;">
                                    <?php echo htmlspecialchars($retiro['observaciones'] ?? ''); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border); white-space: nowrap;">
                                    <a href="retiros.php?editar=<?php echo urlencode($retiro['nro_tracking']); ?>&buscar=<?php echo urlencode($buscar); ?>&filtro_sucursal=<?php echo urlencode($filtro_sucursal); ?>&filtro_tipo=<?php echo urlencode($filtro_tipo); ?>" class="btn-public-secondary" style="margin-right: 8px;">
                                        Editar
                                    </a>

                                    <form method="POST" action="retiros.php" style="display: inline;" onsubmit="return confirm('¿Seguro que querés eliminar este retiro?');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="nro_tracking" value="<?php echo htmlspecialchars($retiro['nro_tracking']); ?>">
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tipoRetirante = document.getElementById('tipo_retirante');
    const grupoAutorizado = document.getElementById('grupo_autorizado');

    function actualizarVistaAutorizado() {
        if (!tipoRetirante || !grupoAutorizado) {
            return;
        }

        if (tipoRetirante.value === 'AUTORIZADO') {
            grupoAutorizado.style.display = 'block';
        } else {
            grupoAutorizado.style.display = 'none';
        }
    }

    if (tipoRetirante) {
        tipoRetirante.addEventListener('change', actualizarVistaAutorizado);
        actualizarVistaAutorizado();
    }
});
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
