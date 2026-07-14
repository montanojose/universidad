<?php
// =====================================================
// footer.php
// Pie reutilizable para pantallas internas
// Sistema: LogiTrack / Sistema Tracking
// =====================================================


// -----------------------------------------------------
// Carga del JS con control de versión
// Esto permite que el navegador tome siempre
// la última versión del archivo main.js
// -----------------------------------------------------

$ruta_js = '../assets/js/main.js';

$ruta_js_fisica = __DIR__ . '/../assets/js/main.js';

$version_js = file_exists($ruta_js_fisica) ? filemtime($ruta_js_fisica) : time();

?>

    <footer class="app-footer">
        <p>LogiTrack - Sistema de tracking y gestión logística</p>
    </footer>

    <script src="<?php echo $ruta_js . '?v=' . $version_js; ?>"></script>

</body>
</html>