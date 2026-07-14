// =====================================================
// main.js
// Funciones JS generales del sistema
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

document.addEventListener('DOMContentLoaded', function () {
    inicializarMenuLateral();
    inicializarCamposRolUsuario();
    inicializarCamposRetiro();
    inicializarValidacionesVisuales();
});


// -----------------------------------------------------
// Menú lateral responsive
// -----------------------------------------------------

function inicializarMenuLateral() {
    const botonMenu = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');

    if (botonMenu && sidebar) {
        botonMenu.addEventListener('click', function () {
            sidebar.classList.toggle('sidebar-open');
        });
    }
}


// -----------------------------------------------------
// Mostrar / ocultar campos asociados según rol
// Solo afecta usuarios.php
// -----------------------------------------------------

function inicializarCamposRolUsuario() {
    const selectRol = document.getElementById('cod_rol');

    if (!selectRol) {
        return;
    }

    const grupoChofer = document.getElementById('grupoChofer');
    const grupoCliente = document.getElementById('grupoCliente');
    const grupoEmpleado = document.getElementById('grupoEmpleado');

    const selectChofer = document.getElementById('legajo_chofer');
    const selectCliente = document.getElementById('dni_cliente');
    const selectEmpleado = document.getElementById('legajo_empleado');

    function actualizarCamposRol(limpiarOcultos = false) {
        const rol = selectRol.value;

        actualizarGrupoCampo(grupoChofer, selectChofer, rol === 'CHOFER', limpiarOcultos);
        actualizarGrupoCampo(grupoCliente, selectCliente, rol === 'CLIENTE', limpiarOcultos);
        actualizarGrupoCampo(grupoEmpleado, selectEmpleado, rol === 'EMPLEADO_SUCURSAL', limpiarOcultos);
    }

    selectRol.addEventListener('change', function () {
        actualizarCamposRol(true);
    });

    actualizarCamposRol(false);
}

function actualizarGrupoCampo(grupo, campo, visible, limpiarOcultos) {
    if (!grupo || !campo) {
        return;
    }

    grupo.classList.toggle('is-visible', visible);
    campo.disabled = !visible;
    campo.required = visible;

    if (!visible) {
        campo.classList.remove('is-valid', 'is-invalid');

        if (limpiarOcultos) {
            campo.value = '';
        }
    }
}


// -----------------------------------------------------
// Mostrar / ocultar autorizado en retiros.php
// -----------------------------------------------------

function inicializarCamposRetiro() {
    const tipoRetirante = document.getElementById('tipo_retirante');
    const grupoAutorizado = document.getElementById('grupo_autorizado');
    const campoAutorizado = document.getElementById('dni_autorizado');

    if (!tipoRetirante || !grupoAutorizado || !campoAutorizado) {
        return;
    }

    function actualizarRetiro(limpiar = false) {
        const visible = tipoRetirante.value === 'AUTORIZADO';

        grupoAutorizado.style.display = visible ? 'block' : 'none';
        campoAutorizado.disabled = !visible;
        campoAutorizado.required = visible;

        if (!visible) {
            campoAutorizado.classList.remove('is-valid', 'is-invalid');

            if (limpiar) {
                campoAutorizado.value = '';
            }
        }
    }

    tipoRetirante.addEventListener('change', function () {
        actualizarRetiro(true);
    });

    actualizarRetiro(false);
}


// -----------------------------------------------------
// Validaciones visuales básicas
// No reemplaza validación de PHP.
// Solo pinta campos válidos / inválidos.
// -----------------------------------------------------

function inicializarValidacionesVisuales() {
    const formularios = document.querySelectorAll('form');

    formularios.forEach(function (formulario) {
        const campos = formulario.querySelectorAll('input, select, textarea');

        campos.forEach(function (campo) {
            campo.addEventListener('blur', function () {
                validarCampo(campo);
            });

            campo.addEventListener('input', function () {
                validarCampo(campo);
            });

            campo.addEventListener('change', function () {
                validarCampo(campo);
            });
        });

        formulario.addEventListener('submit', function () {
            campos.forEach(function (campo) {
                validarCampo(campo);
            });
        });
    });
}

function validarCampo(campo) {
    if (!campo || campo.type === 'hidden' || campo.disabled) {
        return true;
    }

    const valor = (campo.value || '').trim();
    const obligatorio = campo.required;

    if (!obligatorio && valor === '') {
        campo.classList.remove('is-valid', 'is-invalid');
        return true;
    }

    const valido = campo.checkValidity();

    campo.classList.remove('is-valid', 'is-invalid');
    campo.classList.add(valido ? 'is-valid' : 'is-invalid');

    return valido;
}