<?php
/**
 * Nexo Fiscal dentro de UNA carpeta de una biblioteca que ya se usaba, con
 * permiso solo sobre esa carpeta.
 *
 * Es lo que se pidió el 27/09/2026: no crear una biblioteca nueva sino darle a
 * la aplicación una carpeta de la que la empresa ya tiene —con
 * Files.SelectedOperations.Selected, el administrador le da acceso a esa
 * carpeta y a nada más—. Con ese permiso, la raíz de la biblioteca, la
 * biblioteca misma y todo lo de afuera le contestan que no. Se comprueba:
 *
 *   - que todo se dirige DESDE la carpeta (/items/{id}:/…:) y nunca desde la
 *     raíz, y que la ruta que guarda la base es relativa a la carpeta;
 *   - que sin el id de la carpeta y sin permiso para buscarla por su ruta,
 *     el error dice qué poner en local.graph.php;
 *   - que el almacén archiva, mueve, copia, lista y borra ahí adentro;
 *   - y lo que hace la tarea que vigila la biblioteca: un documento movido
 *     dentro de la carpeta se sigue; uno sacado de la carpeta cuenta como
 *     borrado —y vuelve si lo devuelven—; renombrar la carpeta de Nexo Fiscal
 *     no rompe nada; y si SharePoint no da el enlace de cambios de la
 *     carpeta, la revisión por id se entera igual.
 *
 * La primera mitad no usa la base. La segunda corre contra la base aislada y
 * la deja como la encontró.
 *
 * Uso: php tests/SoloUnaCarpetaTest.php
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/ReconciliadorGraph.php';
require_once __DIR__ . '/soporte/GraphEnMemoria.php';

$fallas = [];

function paso($condicion, $mensaje)
{
    global $fallas;
    if (!$condicion) {
        $fallas[] = $mensaje;
    }
}

function lanzaGraph(callable $f)
{
    try {
        $f();
    } catch (Throwable $e) {
        return $e;
    }
    return null;
}

const CARPETA = 'Contabilidad/Nexo Fiscal';

/** Una biblioteca que ya se usaba, con la carpeta de Nexo Fiscal y el permiso solo sobre ella. */
function bibliotecaConCarpeta()
{
    $b = new GraphEnMemoria();
    $b->crearCarpetaAMano('Contabilidad/Cierres 2025');
    $b->crearCarpetaAMano('Contabilidad/Otras');
    $b->crearCarpetaAMano(CARPETA);
    $b->soloCarpeta = CARPETA;
    return $b;
}

function clienteEn(GraphEnMemoria $b, array $extra = [])
{
    return new ClienteGraph(
        ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'd', 'carpeta' => CARPETA]
            + $extra,
        $b, ['carpeta_estado' => null, 'registro' => null]);
}

$temporales = [];
function local($contenido)
{
    global $temporales;
    $ruta = tempnam(sys_get_temp_dir(), 'carpeta_');
    file_put_contents($ruta, $contenido);
    $temporales[] = $ruta;
    return $ruta;
}

$raizTemporales = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'solo_carpeta_' . bin2hex(random_bytes(4));
mkdir($raizTemporales, 0777, true);
AlmacenDocumentos::fijarCarpetaTemporales($raizTemporales);

try {
    // ── 1. Cómo se nombra cada cosa ──────────────────────────────────────
    $b = bibliotecaConCarpeta();
    $id = $b->buscar(CARPETA);
    $graph = clienteEn($b, ['carpeta_id' => $id]);
    paso($graph->carpeta() === CARPETA, 'la carpeta se lee de la configuración');
    paso($graph->raizApi() === '/drives/d/items/' . $id, 'la raíz de Nexo Fiscal es la carpeta: ' . $graph->raizApi());
    paso($graph->rutaItem('SISTEMA/EMPRESA Ñ/FE 1.xml') === '/drives/d/items/' . $id . ':/SISTEMA/EMPRESA%20%C3%91/FE%201.xml:',
        'un documento se nombra desde la carpeta, tramo a tramo: ' . $graph->rutaItem('SISTEMA/EMPRESA Ñ/FE 1.xml'));
    paso($graph->rutaItem('') === $graph->raizApi(), 'la ruta vacía es la carpeta misma');
    paso($graph->relativaALaCarpeta('Contabilidad/Nexo Fiscal/SISTEMA/a.xml') === 'SISTEMA/a.xml'
        && $graph->relativaALaCarpeta('contabilidad/NEXO FISCAL/SISTEMA/a.xml') === 'SISTEMA/a.xml',
        'lo que SharePoint dice desde la raíz se pasa a relativo a la carpeta, sin importar mayúsculas');
    paso($graph->relativaALaCarpeta('Contabilidad/Otras/a.xml') === null
        && $graph->relativaALaCarpeta('Contabilidad/Nexo Fiscal 2/a.xml') === null,
        'lo de afuera —también una carpeta vecina que empieza igual— no es de Nexo Fiscal');
    paso($graph->relativaALaCarpeta('Contabilidad/Nexo Fiscal') === '', 'y la carpeta misma es la raíz');
    paso($graph->alcance() === 'd#' . CARPETA, 'el alcance del enlace de cambios lleva la carpeta');
    $sinCarpeta = new ClienteGraph(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'd'],
        $b, ['carpeta_estado' => null, 'registro' => null]);
    paso($sinCarpeta->raizApi() === '/drives/d/root' && $sinCarpeta->rutaItem('A/b.xml') === '/drives/d/root:/A/b.xml:'
        && $sinCarpeta->alcance() === 'd',
        'sin carpeta todo sigue como antes: la biblioteca entera');

    // ── 2. El id de la carpeta ───────────────────────────────────────────
    $e = lanzaGraph(function () use ($b) { clienteEn($b)->idCarpeta(); });
    paso($e instanceof ErrorGraph && $e->codigoHttp() === 403 && strpos($e->getMessage(), 'carpeta_id') !== false,
        'con permiso solo sobre la carpeta y sin su id, el error dice qué poner: '
        . ($e ? $e->getMessage() : 'no lanzó'));
    $b->soloCarpeta = null;
    $antes = $b->llamadas;
    paso(clienteEn($b)->idCarpeta() === $id && $b->llamadas - $antes === 2,
        'con permiso sobre el sitio, se averigua por su ruta (token y una llamada)');
    $e = lanzaGraph(function () use ($b) {
        (new ClienteGraph(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'd',
            'carpeta' => 'Contabilidad/No existe'], $b, ['carpeta_estado' => null, 'registro' => null]))->idCarpeta();
    });
    paso($e instanceof ErrorGraph && $e->codigoHttp() === 404 && strpos($e->getMessage(), 'No existe la carpeta') !== false,
        'una carpeta que no está se dice con su nombre');

    // El id averiguado se recuerda entre peticiones. Si alguien borra la
    // carpeta y crea otra con el mismo nombre, lo recordado no sirve: la
    // próxima verificación lo nota, lo olvida y encuentra la nueva.
    $estado = $raizTemporales . DIRECTORY_SEPARATOR . 'estado';
    mkdir($estado, 0777, true);
    $recordando = function () use ($b, $estado) {
        return new ClienteGraph(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'd',
            'carpeta' => CARPETA], $b, ['carpeta_estado' => $estado, 'registro' => null]);
    };
    paso($recordando()->idCarpeta() === $id, 'el id se averigua y se recuerda');
    $antes = $b->llamadas;
    $recordando()->idCarpeta();
    paso($b->llamadas === $antes, 'y la petición siguiente no lo vuelve a preguntar');
    $b->borrarAMano(CARPETA);
    $idNueva = $b->crearCarpetaAMano(CARPETA);
    $otraPeticion = $recordando();
    $vista = $otraPeticion->verificarCarpeta();
    paso(($vista['id'] ?? '') === $idNueva && $otraPeticion->idCarpeta() === $idNueva
        && $recordando()->idCarpeta() === $idNueva,
        'con la carpeta borrada y vuelta a crear, lo recordado se corrige solo');
    foreach (glob($estado . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($estado);
    $id = $idNueva;
    $graph = clienteEn($b, ['carpeta_id' => $id]);
    $b->soloCarpeta = CARPETA;

    // ── 3. El almacén, adentro ───────────────────────────────────────────
    $desde = count($b->registro);
    $almacen = new AlmacenEnGraph($graph);
    paso($almacen->probar() === 'carpeta "' . CARPETA . '"', 'probar() dice dónde: ' . $almacen->probar());
    paso($almacen->permiteEscritura(), 'y se llega sin preguntarle nada a la biblioteca');

    $r = $almacen->escribir('SISTEMA/EMPRESA/2026/07 JULIO/Facturas/FE_1.xml', local('<FE>1</FE>'));
    paso($r['ruta'] === 'sharepoint:SISTEMA/EMPRESA/2026/07 JULIO/Facturas/FE_1.xml' && $r['creado'],
        'la ruta que va a la base es relativa a la carpeta, como siempre: ' . $r['ruta']);
    paso($b->contenido(CARPETA . '/SISTEMA/EMPRESA/2026/07 JULIO/Facturas/FE_1.xml') === '<FE>1</FE>'
        && $b->buscar('SISTEMA') === null,
        'y el archivo está dentro de la carpeta, no suelto en la biblioteca');
    $mismo = $almacen->escribir('SISTEMA/EMPRESA/2026/07 JULIO/Facturas/FE_1.xml', local('<FE>1</FE>'));
    paso($mismo['creado'] === false, 'archivar lo mismo otra vez no duplica');
    $almacen->mover('SISTEMA/EMPRESA/2026/07 JULIO/Facturas/FE_1.xml', 'SISTEMA/EMPRESA/2026/08 AGOSTO/Facturas/FE_1.xml');
    paso($almacen->identidad('SISTEMA/EMPRESA/2026/08 AGOSTO/Facturas/FE_1.xml')['item_id'] === $r['item_id'],
        'mover conserva el id');
    $almacen->copiar('SISTEMA/EMPRESA/2026/08 AGOSTO/Facturas/FE_1.xml', 'PAGOS/Pago 1/FE_1.xml');
    paso(array_column($almacen->listarCarpeta('PAGOS/Pago 1'), 'nombre') === ['FE_1.xml'],
        'copiar y listar, adentro de la carpeta');
    paso(array_column($almacen->listarCarpeta(''), 'nombre') === ['SISTEMA', 'PAGOS'],
        'listar la raíz de Nexo Fiscal es listar la carpeta: ' . json_encode(array_column($almacen->listarCarpeta(''), 'nombre')));
    $almacen->asegurarCarpeta('_TRABAJO/BANDEJA');
    paso($b->buscar(CARPETA . '/_TRABAJO/BANDEJA') !== null, 'una carpeta vacía se crea adentro');
    $almacen->borrar('PAGOS/Pago 1/FE_1.xml');
    paso($almacen->existe('PAGOS/Pago 1/FE_1.xml') === false, 'y borrar, también');
    $delAlmacen = array_slice($b->registro, $desde);
    paso(!preg_grep('~/drives/d/root|^\w+ /drives/d(\?|$)~', $delAlmacen),
        'en todo esto, nada se dirigió a la raíz ni a la biblioteca: ' . json_encode(array_values(preg_grep('~/root~', $delAlmacen))));
} catch (Throwable $e) {
    $fallas[] = 'se cortó la primera parte: ' . get_class($e) . ': ' . $e->getMessage()
        . ' en ' . basename($e->getFile()) . ':' . $e->getLine();
}

// ── 4. La tarea que vigila la biblioteca, contra la base aislada ─────────
$config = require __DIR__ . '/../app/config/database.php';
$pdo = null;
try {
    $pdo = new PDO($config['dsn'], $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) {
    $fallas[] = 'sin base de datos no se puede probar la tarea que vigila la carpeta';
}
$saltar = '';
if ($pdo !== null) {
    if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                            AND TABLE_NAME = 'facturas_xml' AND COLUMN_NAME = 'almacen'")->fetchColumn()) {
        $saltar = 'la base aislada no tiene database/migration_almacen_graph.sql';
    } elseif ((int) $pdo->query('SELECT MIN(id) FROM proveedores')->fetchColumn() <= 0) {
        $saltar = 'sin proveedores en la base aislada';
    } elseif ((int) $pdo->query('SELECT COUNT(*) FROM facturas_xml WHERE almacen IS NOT NULL')->fetchColumn() > 0) {
        // La revisión rotativa pasa por TODAS las filas de SharePoint.
        $saltar = 'la base aislada ya tiene documentos en SharePoint de otra prueba';
    }
}
if ($pdo !== null && $saltar !== '') {
    echo "SKIP: SoloUnaCarpeta, la tarea que la vigila ({$saltar})\n";
    $pdo = null;
}

if ($pdo !== null) {
    $proveedorId = (int) $pdo->query('SELECT MIN(id) FROM proveedores')->fetchColumn();
    $deltaAntes = $pdo->query("SELECT * FROM almacen_delta WHERE almacen = 'sharepoint'")->fetch();
    $marca = strtoupper(bin2hex(random_bytes(3)));
    $mes = 'SISTEMA/CARPETA ' . $marca . '/2026/06 JUNIO/Facturas';
    $facturaIds = [];
    try {
        $b = bibliotecaConCarpeta();
        $idCarpeta = $b->buscar(CARPETA);
        $vigente = null;
        $nuevo = function () use ($b, $idCarpeta, &$vigente) {
            $vigente = new AlmacenEnGraph(clienteEn($b, ['carpeta_id' => $idCarpeta]));
            return $vigente;
        };
        AlmacenDocumentos::olvidar();
        AlmacenDocumentos::registrar('sharepoint', function () use (&$vigente) { return $vigente; });
        $vuelta = function () use ($nuevo) {
            Model::olvidarMemoria();
            return (new ReconciliadorGraph($nuevo()))->ejecutar(false);
        };
        $fila = function ($id) use ($pdo) {
            return $pdo->query('SELECT * FROM facturas_xml WHERE id = ' . (int) $id)->fetch() ?: [];
        };

        // Tres facturas archivadas en la carpeta.
        $almacen = $nuevo();
        $docs = [];
        foreach (['A', 'B', 'C'] as $letra) {
            $par = [];
            foreach (['xml' => '<FE>' . $letra . $marca . '</FE>', 'pdf' => '%PDF ' . $letra . $marca] as $ext => $contenido) {
                $par[$ext] = $almacen->escribir($mes . '/FE_' . $letra . '.' . $ext, local($contenido));
            }
            $pdo->prepare("INSERT INTO facturas_xml
                    (consecutivo_completo, numero_factura_asistente, proveedor_id, fecha_emision, tipo_documento,
                     ruta_xml, archivo_xml, ruta_pdf, archivo_pdf, hash_xml, hash_pdf,
                     almacen, item_xml_id, item_pdf_id, etag_xml, etag_pdf)
                    VALUES (?, ?, ?, '2026-06-07', 'FE', ?, ?, ?, ?, ?, ?, 'sharepoint', ?, ?, ?, ?)")
                ->execute(['CAR' . $marca . $letra, substr($letra . $marca, 0, 8), $proveedorId,
                    $par['xml']['ruta'], 'FE_' . $letra . '.xml', $par['pdf']['ruta'], 'FE_' . $letra . '.pdf',
                    $par['xml']['hash'], $par['pdf']['hash'],
                    $par['xml']['item_id'], $par['pdf']['item_id'], $par['xml']['etag'], $par['pdf']['etag']]);
            $docs[$letra] = (int) $pdo->lastInsertId();
            $facturaIds[] = $docs[$letra];
        }

        $r = $vuelta();
        paso($r['delta_iniciado'] && !$r['sin_enlace_de_cambios'] && $r['documentos_revisados'] === 3
            && $r['marcados_sin_archivo'] === 0 && $r['errores'] === 0,
            'la primera vuelta toma el enlace de cambios DE LA CARPETA y no encuentra nada raro: '
            . json_encode(array_diff_key($r, ['operaciones' => 1])));
        paso(!preg_grep('~/drives/d/root~', $b->registro), 'sin preguntarle nada a la raíz de la biblioteca');

        // Movido dentro de la carpeta: se sigue.
        $b->moverAMano(CARPETA . "/$mes/FE_A.xml", CARPETA . '/SISTEMA/REVISAR');
        $r = $vuelta();
        paso($fila($docs['A'])['ruta_xml'] === 'sharepoint:SISTEMA/REVISAR/FE_A.xml' && $r['rutas_actualizadas'] === 1,
            'un documento movido dentro de la carpeta se sigue, con la ruta relativa a ella: '
            . $fila($docs['A'])['ruta_xml']);

        // Sacado de la carpeta: para Nexo Fiscal es como si lo hubieran borrado.
        $b->moverAMano(CARPETA . "/$mes/FE_B.xml", 'Contabilidad/Otras');
        $r = $vuelta();
        paso(!empty($fila($docs['B'])['archivo_faltante_en']),
            'uno que sacaron de la carpeta queda sin archivo: ' . json_encode(array_diff_key($r, ['operaciones' => 1])));
        $b->moverAMano('Contabilidad/Otras/FE_B.xml', CARPETA . '/' . $mes);
        $r = $vuelta();
        paso(empty($fila($docs['B'])['archivo_faltante_en']) && $r['desmarcados'] === 1,
            'y si lo devuelven, vuelve: ' . json_encode(array_diff_key($r, ['operaciones' => 1])));

        // Con permiso sobre el sitio, afuera de la carpeta se ve —y tampoco cuenta—.
        $b->soloCarpeta = null;
        $b->moverAMano(CARPETA . "/$mes/FE_C.xml", 'Contabilidad/Cierres 2025');
        $r = $vuelta();
        paso(!empty($fila($docs['C'])['archivo_faltante_en'])
            && $fila($docs['C'])['ruta_xml'] === "sharepoint:$mes/FE_C.xml",
            'con permiso sobre todo el sitio, fuera de la carpeta tampoco es de Nexo Fiscal, y la ruta no se toca');
        $b->moverAMano('Contabilidad/Cierres 2025/FE_C.xml', CARPETA . '/' . $mes);
        $vuelta();
        $b->soloCarpeta = CARPETA;

        // Renombrar la carpeta de Nexo Fiscal no rompe nada: el id es el mismo
        // y las rutas son relativas a ella.
        $antes = array_map(function ($id) use ($fila) { return $fila($id)['ruta_xml']; }, $docs);
        $b->renombrarAMano(CARPETA, 'Nexo Fiscal (no tocar)');
        $r = $vuelta();
        $despues = array_map(function ($id) use ($fila) { return $fila($id)['ruta_xml']; }, $docs);
        paso($despues === $antes && $r['marcados_sin_archivo'] === 0 && $r['rutas_actualizadas'] === 0,
            'renombrar la carpeta de Nexo Fiscal no mueve ninguna fila ni las da por perdidas: '
            . json_encode(array_diff_key($r, ['operaciones' => 1])));
        $fp = RutaDocumento::abrirParaLeer((new Factura())->setSociedad(0)->findById($docs['C'])['ruta_xml']);
        paso($fp !== false && stream_get_contents($fp) === '<FE>C' . $marca . '</FE>',
            'y los documentos se siguen abriendo');
        if (is_resource($fp)) {
            fclose($fp);
        }
        $b->renombrarAMano('Contabilidad/Nexo Fiscal (no tocar)', 'Nexo Fiscal');

        // Sin el enlace de cambios de la carpeta, la revisión por id se entera igual.
        $b->cambiosDeCarpeta = false;
        $pdo->exec("DELETE FROM almacen_delta WHERE almacen = 'sharepoint'");
        $b->moverAMano(CARPETA . '/SISTEMA/REVISAR/FE_A.xml', CARPETA . '/SISTEMA/REVISADO');
        $r = $vuelta();
        paso($r['sin_enlace_de_cambios'] && $r['errores'] === 0
            && $fila($docs['A'])['ruta_xml'] === 'sharepoint:SISTEMA/REVISADO/FE_A.xml',
            'si SharePoint no da el enlace de cambios de la carpeta, la revisión por id sigue el movimiento: '
            . json_encode(array_diff_key($r, ['operaciones' => 1])));
        paso(!preg_grep('~/drives/d/root~', $b->registro), 'y en ningún momento se fue a la raíz');
    } catch (Throwable $e) {
        $fallas[] = 'se cortó la segunda parte: ' . get_class($e) . ': ' . $e->getMessage()
            . ' en ' . basename($e->getFile()) . ':' . $e->getLine();
    } finally {
        foreach ($facturaIds as $id) {
            $pdo->exec('DELETE FROM facturas_xml WHERE id = ' . (int) $id);
        }
        $pdo->exec("DELETE FROM almacen_delta WHERE almacen = 'sharepoint'");
        if ($deltaAntes) {
            $pdo->prepare('INSERT INTO almacen_delta (' . implode(',', array_keys($deltaAntes)) . ') VALUES ('
                . implode(',', array_fill(0, count($deltaAntes), '?')) . ')')->execute(array_values($deltaAntes));
        }
    }
}

AlmacenDocumentos::olvidar();
AlmacenDocumentos::fijarCarpetaTemporales(null);
foreach ($temporales as $t) {
    @unlink($t);
}
foreach (glob($raizTemporales . DIRECTORY_SEPARATOR . '*') ?: [] as $t) {
    @unlink($t);
}
@rmdir($raizTemporales);

if ($fallas) {
    foreach ($fallas as $falla) {
        fwrite(STDERR, "FAIL: {$falla}\n");
    }
    exit(1);
}

echo "OK: con permiso sobre una sola carpeta, Nexo Fiscal trabaja adentro y nunca toca la raíz\n";
