<?php
/**
 * La tarjeta "Archivo de documentos" de Configuración, desde que los XML y
 * PDF viven solo en SharePoint (27/09/2026).
 *
 * Antes era donde se elegía la carpeta compartida —un campo, «Examinar» y un
 * explorador de carpetas—, y cada empresa elegía entre esa carpeta y
 * SharePoint. Ya no hay nada que elegir; lo que la tarjeta tiene que hacer es
 * decir la verdad:
 *
 *   - con credenciales: a qué biblioteca y a qué carpeta apunta esta PC;
 *   - sin ellas: que nada de documentos funciona aquí, y cómo se arregla;
 *   - lo que cada empresa todavía tiene en la carpeta compartida de antes,
 *     con el comando que lo migra.
 *
 * Y que no quede nada de lo de antes: ni el campo de la carpeta, ni el
 * selector por empresa, ni las llamadas a lo que ya no existe.
 *
 * Uso: php tests/ConfiguracionArchivoSharePointTest.php
 */

require_once __DIR__ . '/../app/core/Permisos.php';

$fallos = 0;
function verificaArchivo($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        $fallos++;
    }
}

/** La pantalla, como la dibuja ConfiguracionController, con este SharePoint y estas empresas. */
function pantallaConfiguracion(array $sharepoint, array $sociedades = [])
{
    if (!defined('APP_URL')) {
        define('APP_URL', '/xmlconcilia/public');
    }
    $esAdmin         = true;
    $puede           = ['usuarios' => true, 'roles' => true, 'bitacora' => true, 'respaldo' => true,
                        'empresas' => true, 'correo' => true, 'archivo' => true];
    $imapDisponible  = true;
    $configLocal     = ['cuenta_id' => 0];
    $cuentas         = [];
    $cuentaActivaId  = 0;
    $sociedadActiva  = null;
    $sociedadEnUsoId = 0;
    $pagos           = [];
    $pagoActivo      = 0;
    $usuarios        = [];
    $roles           = [];
    $actividad       = [];
    $actividadEstado = null;
    $modulosPermisos = [];
    $respaldo        = ['estado' => null, 'archivos' => [], 'automatico' => ['activo' => false, 'hora' => '']];
    $seccionInicial  = 'archivo';

    ob_start();
    include __DIR__ . '/../app/views/configuracion/index.php';
    return (string) ob_get_clean();
}

/** Solo la tarjeta de archivo. */
function tarjetaArchivo($html)
{
    $desde = strpos($html, 'id="archivo"');
    $hasta = strpos($html, 'id="correo"', (int) $desde);
    return $desde === false || $hasta === false ? '' : substr($html, $desde, $hasta - $desde);
}

$conCredenciales = ['disponible' => true, 'archivo_existe' => true,
    'drive_id' => 'b!NnyKq_n2XkW-Mdd64xfvLheELxQzlERMlVS5j6iv95L', 'carpeta' => 'Contabilidad/Nexo Fiscal',
    'carpeta_anterior' => ''];

// ── Con credenciales y una carpeta ───────────────────────────────────────
$html = pantallaConfiguracion($conCredenciales);
$tarjeta = tarjetaArchivo($html);
verificaArchivo($tarjeta !== '', 'la tarjeta de archivo se dibuja');
verificaArchivo(strpos($tarjeta, 'Con credenciales') !== false
    && strpos($tarjeta, 'Contabilidad/Nexo Fiscal') !== false,
    'con credenciales dice a qué carpeta apunta esta PC');
verificaArchivo(strpos($tarjeta, 'b!NnyKq_n2XkW-Mdd6') !== false
    && strpos($tarjeta, 'xfvLheELxQzlERMlVS5j6iv95L<') === false,
    'y la biblioteca, abreviada (el id entero queda en el title)');
verificaArchivo(strpos($tarjeta, 'Sin credenciales') === false, 'y no dice que falten');
verificaArchivo(strpos($tarjeta, 'Todavía en la carpeta compartida') === false,
    'sin documentos viejos, no aparece el aviso de migrar');

// Nada de lo de antes.
foreach (['id="cfg-carpeta"', 'id="cfg-examinar"', 'id="cfg-explorador"', 'Guardar carpeta',
          '/correo/config', '/correo/carpetas', '/correo/selector', '/sociedades/almacen/'] as $viejo) {
    verificaArchivo(strpos($html, $viejo) === false, "ya no está «{$viejo}»");
}

// ── Sin credenciales ────────────────────────────────────────────────────
$html = pantallaConfiguracion(['disponible' => false, 'archivo_existe' => false, 'drive_id' => '',
    'carpeta' => '', 'carpeta_anterior' => '']);
$tarjeta = tarjetaArchivo($html);
verificaArchivo(strpos($tarjeta, 'Sin credenciales') !== false
    && strpos($tarjeta, 'graph.ejemplo.php') !== false
    && strpos($tarjeta, 'probar_sharepoint.php') !== false,
    'sin credenciales lo dice, con los pasos para ponerlas');
verificaArchivo(strpos($tarjeta, 'Correo, Facturas XML') !== false,
    'y qué deja de funcionar mientras tanto');

// ── Con documentos todavía en la carpeta compartida ─────────────────────
$sociedades = [
    ['id' => 272, 'nombre' => 'GRUPO BM SP S.A', 'cedula' => '3101639680', 'activa' => 1, 'documentos_en_disco' => 31],
    ['id' => 273, 'nombre' => 'OTRA', 'cedula' => '3101000000', 'activa' => 0, 'documentos_en_disco' => 0],
];
$html = pantallaConfiguracion($conCredenciales, $sociedades);
$tarjeta = tarjetaArchivo($html);
verificaArchivo(strpos($tarjeta, 'Todavía en la carpeta compartida') !== false
    && strpos($tarjeta, '--sociedad=272 --todo') !== false,
    'lo que queda por migrar se dice en la tarjeta, con el comando de esa empresa');
verificaArchivo(strpos($tarjeta, '--sociedad=273') === false, 'y solo de las empresas a las que les queda algo');
verificaArchivo(substr_count($html, '--sociedad=272 --todo') === 2,
    'y también en la lista de empresas, junto a la que le corresponde');

if ($fallos) {
    fwrite(STDERR, "ConfiguracionArchivoSharePoint: {$fallos} fallas\n");
    exit(1);
}
echo "OK: la tarjeta de archivo dice dónde está SharePoint, qué falta y qué queda por migrar\n";
