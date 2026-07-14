<?php
// =====================================================
// header.php
// Encabezado HTML reutilizable para pantallas internas
// Sistema: LogiTrack / Sistema Tracking
// =====================================================


// -----------------------------------------------------
// Título de la página
// Si una vista define $titulo_pagina, se usa ese valor.
// Si no, se usa un título general.
// -----------------------------------------------------

if (!isset($titulo_pagina)) {

    $titulo_pagina = 'Panel del sistema';

}


// -----------------------------------------------------
// Carga del CSS con control de versión
// Esto permite que el navegador cargue siempre
// la última versión del archivo main.css
// -----------------------------------------------------

$ruta_css = '../assets/css/main.css';

$ruta_css_fisica = __DIR__ . '/../assets/css/main.css';

$version_css = file_exists($ruta_css_fisica) ? filemtime($ruta_css_fisica) : time();

?>

<!DOCTYPE html>
<html lang="es">
<head>

    <meta charset="UTF-8">

    <title><?php echo htmlspecialchars($titulo_pagina); ?> - LogiTrack</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link 
        rel="stylesheet" 
        href="<?php echo $ruta_css . '?v=' . $version_css; ?>"
    >

</head>
<body class="app-body">