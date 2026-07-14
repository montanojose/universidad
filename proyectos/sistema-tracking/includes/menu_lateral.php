<?php
// =====================================================
// menu_lateral.php
// Menú lateral dinámico por rol
// Sistema Tracking
// =====================================================

$rol_menu = strtoupper(
    (string) (
        $_SESSION['usuario_rol']
        ?? $_SESSION['cod_rol']
        ?? ''
    )
);

$nombre_usuario_menu = (string) (
    $_SESSION['usuario_nombre']
    ?? $_SESSION['nombre_usuario']
    ?? $_SESSION['username']
    ?? 'Usuario'
);

// -----------------------------------------------------
// Detectar automáticamente la raíz web del proyecto
//
// Ejemplo:
// /sistema_tracking/admin/dashboard.php
// raíz detectada: /sistema_tracking
// -----------------------------------------------------

$script_name_menu = str_replace(
    '\\',
    '/',
    $_SERVER['SCRIPT_NAME'] ?? ''
);

$ruta_actual_menu = parse_url(
    $_SERVER['REQUEST_URI'] ?? '',
    PHP_URL_PATH
);

$ruta_actual_menu = str_replace(
    '\\',
    '/',
    (string) $ruta_actual_menu
);

$base_url_menu = str_replace(
    '\\',
    '/',
    dirname(dirname($script_name_menu))
);

if (
    $base_url_menu === '/' ||
    $base_url_menu === '.' ||
    $base_url_menu === '\\'
) {
    $base_url_menu = '';
}

$base_url_menu = rtrim($base_url_menu, '/');

$raiz_fisica_menu = dirname(__DIR__);


// -----------------------------------------------------
// FUNCIONES DEL MENÚ
// -----------------------------------------------------

function menuUrl(string $ruta): string
{
    global $base_url_menu;

    return $base_url_menu . '/' . ltrim($ruta, '/');
}

function menuRutaFisica(string $ruta): string
{
    global $raiz_fisica_menu;

    return $raiz_fisica_menu . '/' . ltrim($ruta, '/');
}

function menuExiste(string $ruta): bool
{
    return file_exists(menuRutaFisica($ruta));
}

function menuActivo(string $ruta): bool
{
    global $ruta_actual_menu;

    return rtrim($ruta_actual_menu, '/') === rtrim(menuUrl($ruta), '/');
}

function menuGrupoActivo(string $carpeta): bool
{
    global $ruta_actual_menu, $base_url_menu;

    $inicio = $base_url_menu . '/' . trim($carpeta, '/') . '/';

    return str_starts_with($ruta_actual_menu, $inicio);
}

function imprimirEnlaceMenu(
    string $ruta,
    string $texto,
    string $icono = '•'
): void {
    if (!menuExiste($ruta)) {
        return;
    }

    $activo = menuActivo($ruta);
    ?>

    <a
        href="<?php echo htmlspecialchars(menuUrl($ruta)); ?>"
        class="sidebar-link <?php echo $activo ? 'is-active' : ''; ?>"
        <?php echo $activo ? 'aria-current="page"' : ''; ?>
    >
        <span class="sidebar-link-icon" aria-hidden="true">
            <?php echo htmlspecialchars($icono); ?>
        </span>

        <span class="sidebar-link-text">
            <?php echo htmlspecialchars($texto); ?>
        </span>
    </a>

    <?php
}

function nombreRolMenu(string $rol): string
{
    return match ($rol) {
        'ADMIN' => 'Administrador',
        'EMPLEADO_SUCURSAL' => 'Empleado de sucursal',
        'CHOFER' => 'Chofer',
        'CLIENTE' => 'Cliente',
        default => 'Usuario'
    };
}
?>

<style>
    /*
    Estas reglas solo dan formato al contenido interno.
    No modifican la posición general de tu sidebar.
    */

    .sidebar-user-card {
        padding: 14px;
        margin: 12px;
        border: 1px solid var(--color-border, #e4dcf0);
        border-radius: 16px;
        background:
            linear-gradient(
                135deg,
                rgba(123, 44, 191, 0.10),
                rgba(181, 108, 255, 0.06)
            );
    }

    .sidebar-user-name {
        display: block;
        margin-bottom: 4px;
        color: var(--color-text, #1f1b2e);
        font-weight: 700;
        word-break: break-word;
    }

    .sidebar-user-role {
        display: block;
        color: var(--color-muted, #756d83);
        font-size: 13px;
    }

    .sidebar-nav {
        display: flex;
        flex-direction: column;
        gap: 5px;
        padding: 8px 12px 20px;
    }

    .sidebar-section {
        margin-top: 8px;
    }

    .sidebar-section-title {
        padding: 10px 12px 6px;
        color: var(--color-muted, #756d83);
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.06em;
        text-transform: uppercase;
    }

    .sidebar-link {
        display: flex;
        align-items: center;
        gap: 10px;
        width: 100%;
        padding: 11px 12px;
        border: 1px solid transparent;
        border-radius: 13px;
        color: var(--color-text, #1f1b2e);
        text-decoration: none;
        transition:
            background-color 0.18s ease,
            border-color 0.18s ease,
            transform 0.18s ease;
    }

    .sidebar-link:hover {
        border-color: var(--color-border, #e4dcf0);
        background-color: var(--color-surface-soft, #f8f4fc);
        transform: translateX(2px);
    }

    .sidebar-link.is-active {
        border-color: rgba(123, 44, 191, 0.20);
        background:
            linear-gradient(
                135deg,
                rgba(91, 22, 163, 0.15),
                rgba(181, 108, 255, 0.10)
            );
        color: var(--color-primary, #5b16a3);
        font-weight: 700;
    }

    .sidebar-link-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 25px;
        min-width: 25px;
        height: 25px;
        border-radius: 8px;
        background-color: rgba(123, 44, 191, 0.09);
        font-size: 14px;
    }

    .sidebar-link-text {
        min-width: 0;
        line-height: 1.25;
    }

    .sidebar-details {
        margin-top: 7px;
        border: 1px solid var(--color-border, #e4dcf0);
        border-radius: 15px;
        overflow: hidden;
        background-color: rgba(255, 255, 255, 0.35);
    }

    .sidebar-details summary {
        padding: 12px 14px;
        color: var(--color-text, #1f1b2e);
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        user-select: none;
        list-style-position: inside;
    }

    .sidebar-details summary:hover {
        background-color: var(--color-surface-soft, #f8f4fc);
    }

    .sidebar-details-content {
        display: flex;
        flex-direction: column;
        gap: 4px;
        padding: 5px 7px 8px;
        border-top: 1px solid var(--color-border, #e4dcf0);
    }

    .sidebar-details-content .sidebar-link {
        font-size: 14px;
    }

    .sidebar-divider {
        height: 1px;
        margin: 13px 12px;
        background-color: var(--color-border, #e4dcf0);
    }
</style>

<aside class="sidebar app-sidebar">

    <div class="sidebar-user-card">
        <span class="sidebar-user-name">
            <?php echo htmlspecialchars($nombre_usuario_menu); ?>
        </span>

        <span class="sidebar-user-role">
            <?php echo htmlspecialchars(nombreRolMenu($rol_menu)); ?>
        </span>
    </div>

    <nav class="sidebar-nav" aria-label="Menú principal">

        <section class="sidebar-section">
            <div class="sidebar-section-title">
                Cuenta
            </div>

            <?php
            imprimirEnlaceMenu(
                'cuenta/perfil.php',
                'Mi cuenta',
                'U'
            );
            ?>
        </section>

        <?php if ($rol_menu === 'ADMIN'): ?>

            <!-- =========================================
                 MENÚ ADMINISTRADOR
            ========================================== -->

            <section class="sidebar-section">
                <div class="sidebar-section-title">
                    Administración
                </div>

                <?php
                imprimirEnlaceMenu(
                    'admin/dashboard.php',
                    'Panel principal',
                    '⌂'
                );

                imprimirEnlaceMenu(
                    'admin/sucursales.php',
                    'Sucursales',
                    'S'
                );

                imprimirEnlaceMenu(
                    'admin/empleados.php',
                    'Empleados',
                    'E'
                );

                imprimirEnlaceMenu(
                    'admin/choferes.php',
                    'Choferes',
                    'C'
                );

                imprimirEnlaceMenu(
                    'admin/clientes.php',
                    'Clientes',
                    'CL'
                );

                imprimirEnlaceMenu(
                    'admin/vehiculos.php',
                    'Vehículos',
                    'V'
                );

                imprimirEnlaceMenu(
                    'admin/viajes.php',
                    'Viajes',
                    'R'
                );

                imprimirEnlaceMenu(
                    'admin/usuarios.php',
                    'Usuarios y accesos',
                    'U'
                );
                ?>
            </section>

            <div class="sidebar-divider"></div>

            <details
                class="sidebar-details"
                <?php echo menuGrupoActivo('empleado') ? 'open' : ''; ?>
            >
                <summary>Operaciones de sucursal</summary>

                <div class="sidebar-details-content">
                    <?php
                    imprimirEnlaceMenu(
                        'empleado/dashboard.php',
                        'Panel empleado',
                        '⌂'
                    );

                    imprimirEnlaceMenu(
                        'empleado/buscar_tracking.php',
                        'Buscar tracking',
                        '⌕'
                    );

                    imprimirEnlaceMenu(
                        'empleado/recepcionar_envio.php',
                        'Recepcionar envío',
                        'R'
                    );

                    imprimirEnlaceMenu(
                        'empleado/actualizar_estado.php',
                        'Actualizar estado',
                        'A'
                    );

                    imprimirEnlaceMenu(
                        'empleado/asignar_viaje.php',
                        'Asignar viaje',
                        'V'
                    );

                    imprimirEnlaceMenu(
                        'empleado/disponible_retiro.php',
                        'Disponible para retiro',
                        'D'
                    );

                    imprimirEnlaceMenu(
                        'empleado/registrar_retiro.php',
                        'Registrar retiro',
                        '✓'
                    );

                    imprimirEnlaceMenu(
                        'empleado/devoluciones.php',
                        'Devoluciones',
                        '↩'
                    );
                    ?>
                </div>
            </details>

            <details
                class="sidebar-details"
                <?php echo menuGrupoActivo('chofer') ? 'open' : ''; ?>
            >
                <summary>Operaciones del chofer</summary>

                <div class="sidebar-details-content">
                    <?php
                    imprimirEnlaceMenu(
                        'chofer/dashboard.php',
                        'Panel chofer',
                        '⌂'
                    );

                    imprimirEnlaceMenu(
                        'chofer/mis_viajes.php',
                        'Viajes asignados',
                        'V'
                    );

                    imprimirEnlaceMenu(
                        'chofer/iniciar_viaje.php',
                        'Iniciar viaje',
                        '▶'
                    );

                    imprimirEnlaceMenu(
                        'chofer/finalizar_viaje.php',
                        'Finalizar viaje',
                        '■'
                    );

                    imprimirEnlaceMenu(
                        'chofer/registrar_incidente.php',
                        'Registrar incidente',
                        '!'
                    );
                    ?>
                </div>
            </details>

            <details
                class="sidebar-details"
                <?php echo menuGrupoActivo('cliente') ? 'open' : ''; ?>
            >
                <summary>Vista del cliente</summary>

                <div class="sidebar-details-content">
                    <?php
                    imprimirEnlaceMenu(
                        'cliente/dashboard.php',
                        'Panel cliente',
                        '⌂'
                    );

                    imprimirEnlaceMenu(
                        'cliente/crear_solicitud_envio.php',
                        'Crear solicitud',
                        '+'
                    );

                    imprimirEnlaceMenu(
                        'cliente/mis_envios.php',
                        'Envíos enviados',
                        '↑'
                    );

                    imprimirEnlaceMenu(
                        'cliente/envios_recibir.php',
                        'Envíos a recibir',
                        '↓'
                    );
                    ?>
                </div>
            </details>


        <?php elseif ($rol_menu === 'EMPLEADO_SUCURSAL'): ?>

            <!-- =========================================
                 MENÚ EMPLEADO
            ========================================== -->

            <section class="sidebar-section">
                <div class="sidebar-section-title">
                    Mi sucursal
                </div>

                <?php
                imprimirEnlaceMenu(
                    'empleado/dashboard.php',
                    'Panel principal',
                    '⌂'
                );

                imprimirEnlaceMenu(
                    'empleado/buscar_tracking.php',
                    'Buscar tracking',
                    '⌕'
                );

                imprimirEnlaceMenu(
                    'empleado/recepcionar_envio.php',
                    'Recepcionar envío',
                    'R'
                );

                imprimirEnlaceMenu(
                    'empleado/actualizar_estado.php',
                    'Actualizar estado',
                    'A'
                );

                imprimirEnlaceMenu(
                    'empleado/asignar_viaje.php',
                    'Asignar a viaje',
                    'V'
                );
                ?>
            </section>

            <section class="sidebar-section">
                <div class="sidebar-section-title">
                    Retiros
                </div>

                <?php
                imprimirEnlaceMenu(
                    'empleado/disponible_retiro.php',
                    'Disponible para retiro',
                    'D'
                );

                imprimirEnlaceMenu(
                    'empleado/registrar_retiro.php',
                    'Registrar retiro',
                    '✓'
                );

                imprimirEnlaceMenu(
                    'empleado/devoluciones.php',
                    'Procesar devoluciones',
                    '↩'
                );
                ?>
            </section>


        <?php elseif ($rol_menu === 'CHOFER'): ?>

            <!-- =========================================
                 MENÚ CHOFER
            ========================================== -->

            <section class="sidebar-section">
                <div class="sidebar-section-title">
                    Mis operaciones
                </div>

                <?php
                imprimirEnlaceMenu(
                    'chofer/dashboard.php',
                    'Panel principal',
                    '⌂'
                );

                imprimirEnlaceMenu(
                    'chofer/mis_viajes.php',
                    'Mis viajes',
                    'V'
                );

                imprimirEnlaceMenu(
                    'chofer/iniciar_viaje.php',
                    'Iniciar viaje',
                    '▶'
                );

                imprimirEnlaceMenu(
                    'chofer/finalizar_viaje.php',
                    'Finalizar viaje',
                    '■'
                );

                imprimirEnlaceMenu(
                    'chofer/registrar_incidente.php',
                    'Registrar incidente',
                    '!'
                );
                ?>
            </section>


        <?php elseif ($rol_menu === 'CLIENTE'): ?>

            <!-- =========================================
                 MENÚ CLIENTE
            ========================================== -->

            <section class="sidebar-section">
                <div class="sidebar-section-title">
                    Mis envíos
                </div>

                <?php
                imprimirEnlaceMenu(
                    'cliente/dashboard.php',
                    'Panel principal',
                    '⌂'
                );

                imprimirEnlaceMenu(
                    'cliente/crear_solicitud_envio.php',
                    'Crear solicitud',
                    '+'
                );

                imprimirEnlaceMenu(
                    'cliente/mis_envios.php',
                    'Envíos enviados',
                    '↑'
                );

                imprimirEnlaceMenu(
                    'cliente/envios_recibir.php',
                    'Envíos a recibir',
                    '↓'
                );
                ?>
            </section>

        <?php else: ?>

            <section class="sidebar-section">
                <div class="sidebar-section-title">
                    Sistema
                </div>

                <div
                    class="alert alert-warning"
                    style="margin: 8px 0;"
                >
                    No se pudo identificar el rol del usuario.
                </div>
            </section>

        <?php endif; ?>

    </nav>

</aside>
