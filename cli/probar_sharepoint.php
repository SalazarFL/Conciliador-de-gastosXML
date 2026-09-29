<?php
/**
 * Prueba de vida de SharePoint por Microsoft Graph.
 *
 * Uso: php cli/probar_sharepoint.php [--conservar] [--grande] [--almacen] [--grabar] [--carpeta=RUTA]
 *      php cli/probar_sharepoint.php --bibliotecas=https://<empresa>.sharepoint.com/sites/<sitio>
 *
 *   --bibliotecas=URL  solo lista las bibliotecas de ese sitio con su id
 *                —el drive_id que va en local.graph.php—. Sirve antes de
 *                tener el drive_id: basta con tenant, aplicación y secreto.
 *                Con Sites.Selected, el sitio tiene que estar autorizado.
 *                Con permiso solo sobre una carpeta no sirve: el drive_id y
 *                el id de la carpeta los da el administrador (docs/SHAREPOINT.md).
 *
 *   --carpeta=RUTA  prueba contra esa carpeta de la biblioteca en vez de la
 *                de local.graph.php, sin tocar el archivo. Para ensayar el
 *                paso a una carpeta antes de hacerlo.
 *
 *   --conservar  no borra lo que sube, para mirarlo en SharePoint.
 *   --grande     además sube un archivo de 5 MiB, que va por sesión de
 *                carga en trozos: el camino que casi nunca corre.
 *   --almacen    además recorre el almacén de la aplicación (AlmacenEnGraph)
 *                contra la biblioteca: archivar, reconocer lo ya archivado,
 *                frenar un conflicto, mover sin perder el id, copiar, listar y
 *                borrar. Dice también si la biblioteca tiene la columna
 *                HashSha256, que es un paso de la preparación.
 *   --grabar     guarda el intercambio en tests/fixtures/graph/, con los
 *                identificadores del tenant tapados, para las pruebas.
 *
 * Esto NO es parte del sistema: no toca la base, no toca los documentos de
 * nadie y nada de la aplicación lo llama. Contesta, en la máquina de verdad:
 *
 *     ¿puede este servidor escribir y leer en una biblioteca de SharePoint
 *     sin que haya ninguna persona con la sesión iniciada?
 *
 * Que es justo lo que la carpeta sincronizada de OneDrive no puede hacer: sus
 * archivos solo se hidratan para la sesión del usuario que sincroniza, y
 * Apache corre como LocalSystem.
 *
 * Y lo contesta con el mismo ClienteGraph que usa la aplicación —token
 * guardado, reintentos, registro en storage/logs/graph.log—, así que si esto
 * pasa, lo que falla después no es la conexión.
 *
 * Las credenciales van en app/config/local.graph.php, que git ignora. El
 * repositorio es público: el secreto no entra acá ni en ningún commit.
 *
 * Devuelve 0 si todo pasó y 1 si algo falló.
 */
if (PHP_SAPI !== 'cli') { exit("Solo CLI.\n"); }

require_once __DIR__ . '/../app/helpers/ClienteGraph.php';

$conservar = in_array('--conservar', $argv, true);
$grande = in_array('--grande', $argv, true);
$grabar = in_array('--grabar', $argv, true);
$probarAlmacen = in_array('--almacen', $argv, true);

// ── Configuración ─────────────────────────────────────────────────────────

$sitio = '';
$otraCarpeta = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--bibliotecas=') === 0) {
        $sitio = trim(substr($arg, strlen('--bibliotecas=')));
    }
    if (strpos($arg, '--carpeta=') === 0) {
        $otraCarpeta = trim(substr($arg, strlen('--carpeta=')));
    }
}

if ($sitio !== '') {
    // Todavía no hay drive_id: es justo lo que se busca. Se lee el archivo
    // aparte, sin la exigencia de que esté completo.
    $archivo = __DIR__ . '/../app/config/local.graph.php';
    $crudo = is_file($archivo) ? require $archivo : null;
    $faltan = [];
    foreach (['tenant_id', 'client_id', 'client_secret'] as $clave) {
        $valor = trim((string) ($crudo[$clave] ?? ''));
        if ($valor === '' || strpos($valor, 'PEGA-') === 0) {
            $faltan[] = $clave;
        }
    }
    if ($faltan) {
        fwrite(STDERR, 'Para listar bibliotecas hacen falta en app/config/local.graph.php: '
            . implode(', ', $faltan) . "\n");
        exit(1);
    }
    $partes = parse_url($sitio);
    if (empty($partes['host'])) {
        fwrite(STDERR, "La URL del sitio tiene que ser como https://empresa.sharepoint.com/sites/Cuentas\n");
        exit(1);
    }
    // El sitio raíz (https://empresa.sharepoint.com) no lleva ruta.
    $camino = rtrim((string) ($partes['path'] ?? ''), '/');
    $graph = new ClienteGraph(['drive_id' => 'sin-definir'] + $crudo, new TransporteCurl());
    try {
        $sitioJson = $graph->pedir('GET', '/sites/' . $partes['host'] . ($camino !== '' ? ':' . $camino : '')
            . '?$select=id,displayName')['json'];
        $bibliotecas = $graph->pedir('GET', '/sites/' . rawurlencode($sitioJson['id']) . '/drives?$select=id,name,webUrl')['json']['value'] ?? [];
    } catch (Throwable $e) {
        fwrite(STDERR, 'No se pudieron listar: ' . $e->getMessage() . "\n"
            . "Si dice 403, el administrador todavía no autorizó la aplicación sobre ese sitio (Sites.Selected).\n");
        exit(1);
    }
    echo "\nBibliotecas de \"" . ($sitioJson['displayName'] ?? $sitio) . "\":\n\n";
    foreach ($bibliotecas as $b) {
        echo '  ' . str_pad((string) $b['name'], 40) . ' drive_id: ' . $b['id'] . "\n";
    }
    echo "\nCopia el drive_id de la biblioteca de documentos en app/config/local.graph.php.\n";
    exit(0);
}

$cfg = ClienteGraph::configuracion();
if ($cfg === null) {
    fwrite(STDERR, "Falta app/config/local.graph.php, o está sin completar.\n"
        . "Ahí van tenant_id, client_id, client_secret y drive_id. Ese archivo\n"
        . "no se versiona: git lo ignora por la regla app/config/local.*.php\n");
    exit(1);
}

if ($otraCarpeta !== null) {
    $cfg['carpeta'] = $otraCarpeta;
    unset($cfg['carpeta_id']);
}

$transporte = new TransporteCurl();
if ($grabar) {
    $transporte = new TransporteQueGraba($transporte);
}
$graph = new ClienteGraph($cfg, $transporte);

// ── Utilidades ────────────────────────────────────────────────────────────

$paso = 0;
$anunciar = function ($texto) use (&$paso) {
    $paso++;
    echo "\n[{$paso}] {$texto}\n";
};
$bien = function ($texto) { echo "    OK  {$texto}\n"; };
$aviso = function ($texto) { echo "    AVISO  {$texto}\n"; };
$morir = function ($texto, ?Throwable $e = null) {
    echo "    FALLA  {$texto}\n";
    if ($e !== null) {
        echo '           ' . $e->getMessage() . "\n";
        if ($e instanceof ErrorGraph && $e->detalle() !== '') {
            echo '           Microsoft: ' . $e->codigoGraph() . ' ' . $e->detalle() . "\n";
        }
    }
    echo "\nLa prueba se detuvo acá. El detalle de cada llamada está en storage/logs/graph.log\n";
    exit(1);
};

/** Baja un documento y devuelve su contenido. */
$bajar = function ($idItem) use ($graph) {
    $destino = fopen('php://temp', 'w+b');
    $esta = $graph->bajar($graph->unidad() . '/items/' . rawurlencode($idItem), $destino);
    rewind($destino);
    $contenido = $esta ? stream_get_contents($destino) : null;
    fclose($destino);
    return $contenido;
};

echo "\nNexo Fiscal · prueba de SharePoint por Microsoft Graph\n";
echo str_repeat('-', 72), "\n";

// ── 1. Token y biblioteca ─────────────────────────────────────────────────

if ($graph->carpeta() === '') {
    $anunciar('Pidiendo permiso a Entra ID y preguntando por la biblioteca');
    try {
        $unidad = $graph->pedir('GET', $graph->unidad())['json'];
    } catch (Throwable $e) {
        $morir('No se llegó a la biblioteca.', $e);
    }
    $bien('Token recibido sin que nadie iniciara sesión (queda guardado en storage/cache).');
    $bien('"' . ($unidad['name'] ?? '?') . '" · ' . ($unidad['driveType'] ?? '?')
        . ' · ocupado ' . number_format(($unidad['quota']['used'] ?? 0) / 1048576, 1) . ' MB');
    $bien('Nexo Fiscal usa la biblioteca entera.');
} else {
    // Con permiso solo sobre la carpeta, preguntar por la biblioteca da 403
    // aunque todo esté bien: se pregunta por la carpeta.
    $anunciar('Pidiendo permiso a Entra ID y preguntando por la carpeta "' . $graph->carpeta() . '"');
    try {
        $idCarpeta = $graph->idCarpeta();
        $laCarpeta = $graph->pedir('GET', $graph->raizApi() . '?$select=id,name,webUrl,folder')['json'];
    } catch (Throwable $e) {
        $morir('No se llegó a la carpeta.', $e);
    }
    $bien('Token recibido sin que nadie iniciara sesión (queda guardado en storage/cache).');
    $bien('Carpeta "' . ($laCarpeta['name'] ?? '?') . '" · id ' . $idCarpeta
        . ' · ' . (int) ($laCarpeta['folder']['childCount'] ?? 0) . ' elemento(s) adentro');
    $bien('Se ve en: ' . ($laCarpeta['webUrl'] ?? '?'));
    try {
        $graph->pedir('GET', $graph->unidad() . '?$select=id');
        $aviso('La aplicación ve la biblioteca entera, no solo esta carpeta. Funciona igual; si se quiere '
            . 'que vea solo la carpeta, el permiso tiene que ser Files.SelectedOperations.Selected sobre ella '
            . '(docs/SHAREPOINT.md).');
    } catch (ErrorGraph $e) {
        $e->codigoHttp() === 403
            ? $bien('La aplicación ve solo esta carpeta: la biblioteca le contesta 403, como debe ser.')
            : $aviso('La biblioteca contestó ' . $e->codigoHttp() . ': ' . $e->getMessage());
    }
    try {
        $graph->pedir('GET', $graph->raizApi() . '/delta?token=latest');
        $bien('SharePoint da el enlace de cambios de la carpeta: los movimientos se ven en minutos.');
    } catch (ErrorGraph $e) {
        $aviso('SharePoint no da el enlace de cambios de la carpeta (' . $e->codigoHttp() . '). Funciona igual: '
            . 'la tarea programada lo nota repasando los documentos por su id, algo más tarde.');
    }
}

// ── 2. Subir ──────────────────────────────────────────────────────────────

$carpeta = '_prueba_nexo_fiscal';
$ruta = $carpeta . '/prueba_' . date('Ymd_His') . '.xml';
$contenido = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
    . '<Prueba><Origen>Nexo Fiscal</Origen><Equipo>' . php_uname('n') . '</Equipo>'
    . '<Momento>' . date('c') . '</Momento></Prueba>';
$local = tempnam(sys_get_temp_dir(), 'nexo');
file_put_contents($local, $contenido);

$anunciar('Subiendo ' . $ruta . ' (' . strlen($contenido) . ' bytes)');
try {
    $item = $graph->subir($ruta, $local);
} catch (Throwable $e) {
    $morir('No dejó subir. Si dice que no hay permiso, la aplicación quedó con rol de '
        . 'lectura: el permiso sobre el sitio tiene que ser "write".', $e);
}
$idItem = (string) ($item['id'] ?? '');
$bien('Subido. La carpeta se creó sola.');
$bien('Id en SharePoint: ' . $idItem . ' (no cambia aunque alguien lo mueva)');
$bien('Se ve en: ' . ($item['webUrl'] ?? '?'));

// ── 3. Bajar y comparar ───────────────────────────────────────────────────

$anunciar('Bajándolo de vuelta para comparar');
try {
    $bajado = $bajar($idItem);
} catch (Throwable $e) {
    $morir('Subió pero no se pudo leer.', $e);
}
if ($bajado !== $contenido) {
    $morir('Lo que bajó no es igual a lo que subió (' . strlen((string) $bajado) . ' contra '
        . strlen($contenido) . ' bytes).');
}
$bien('Idéntico, ' . strlen($bajado) . ' bytes. Esto es lo que hoy falla con OneDrive.');

// ── 4. El duplicado ───────────────────────────────────────────────────────

$anunciar('Subiendo el MISMO nombre otra vez, a ver si se defiende');
try {
    $graph->subir($ruta, $local);
    $aviso('Lo aceptó en vez de rechazarlo. Hay que revisar con qué conflictBehavior se sube '
        . 'antes de llevar esto a producción.');
} catch (AlmacenConflicto $e) {
    $bien('Rechazado, como debe ser. No hay "prueba (1).xml" que valga.');
} catch (Throwable $e) {
    $aviso('Respuesta inesperada: ' . $e->getMessage());
}
@unlink($local);

// ── 5. Archivo grande (opcional) ──────────────────────────────────────────

$idGrande = '';
if ($grande) {
    $anunciar('Subiendo 5 MiB por sesión de carga, en trozos');
    $localGrande = tempnam(sys_get_temp_dir(), 'nexo');
    file_put_contents($localGrande, random_bytes(5 * 1048576));
    $hash = hash_file('sha256', $localGrande);
    try {
        $inicio = microtime(true);
        $itemGrande = $graph->subir($carpeta . '/grande_' . date('Ymd_His') . '.bin', $localGrande);
        $idGrande = (string) ($itemGrande['id'] ?? '');
        $bien('Subido en ' . number_format(microtime(true) - $inicio, 1) . ' s. Id: ' . $idGrande);
        $bajadoGrande = $bajar($idGrande);
        if ($bajadoGrande === null || hash('sha256', $bajadoGrande) !== $hash) {
            $morir('El archivo grande no volvió igual.');
        }
        $bien('Bajado y comparado por SHA-256: idéntico.');
    } catch (Throwable $e) {
        $morir('La subida por partes falló.', $e);
    } finally {
        @unlink($localGrande);
    }
}

// ── 6. Varias preguntas en una ────────────────────────────────────────────

$anunciar('Preguntando por el documento y su carpeta en un solo lote ($batch)');
try {
    $lote = $graph->lote([
        'documento' => ['url' => $graph->unidad() . '/items/' . rawurlencode($idItem)],
        'carpeta' => ['url' => $graph->rutaItem($carpeta)],
    ]);
    if (($lote['documento']['codigo'] ?? 0) === 200 && ($lote['carpeta']['codigo'] ?? 0) === 200) {
        $bien('Dos respuestas en una ida y vuelta. Así se verifica una migración en horas y no en días.');
    } else {
        $aviso('El lote volvió con ' . json_encode(array_map(function ($r) { return $r['codigo']; }, $lote)));
    }
} catch (Throwable $e) {
    $aviso('El lote falló: ' . $e->getMessage());
}

// ── El almacén de la aplicación (opcional) ────────────────────────────────

if ($probarAlmacen) {
    require_once __DIR__ . '/../app/helpers/AlmacenEnGraph.php';
    $anunciar('Recorriendo el almacén de la aplicación contra la biblioteca');
    $almacen = new AlmacenEnGraph($graph, $cfg);
    // Esto es lo que se corre después de agregar la columna HashSha256: se
    // pregunta de nuevo en vez de creer lo que se recordaba.
    $almacen->olvidarColumnaHash();
    $base = $carpeta . '/almacen_' . date('Ymd_His');
    $uno = tempnam(sys_get_temp_dir(), 'nexo');
    $otro = tempnam(sys_get_temp_dir(), 'nexo');
    file_put_contents($uno, '<FacturaElectronica>uno</FacturaElectronica>');
    file_put_contents($otro, '<FacturaElectronica>otro</FacturaElectronica>');
    try {
        $r = $almacen->escribir($base . '/Facturas/FE_1.xml', $uno);
        $bien('Archivado: ' . $r['ruta'] . ' (id ' . $r['item_id'] . ')');

        $columna = $almacen->tieneColumnaHash();
        if ($columna) {
            $bien('La biblioteca tiene la columna HashSha256 y quedó declarado el hash.');
        } else {
            $aviso('La biblioteca NO tiene la columna HashSha256. Funciona igual, pero para saber si '
                . 'algo ya está archivado hay que bajarlo. Agrégala: Configuración de la biblioteca → '
                . 'Crear columna → "Una línea de texto", nombre exacto HashSha256.');
        }

        $otraVez = (new AlmacenEnGraph($graph, $cfg))->escribir($base . '/Facturas/FE_1.xml', $uno);
        $otraVez['creado'] === false
            ? $bien('Archivar lo mismo otra vez se reconoce como lo mismo, sin duplicar.')
            : $morir('Archivar lo mismo otra vez lo duplicó.');

        try {
            $almacen->escribir($base . '/Facturas/FE_1.xml', $otro);
            $morir('Otro contenido con el mismo nombre se aceptó encima.');
        } catch (AlmacenConflicto $e) {
            $bien('Otro contenido con el mismo nombre se frena'
                . ($e->hashExistente() !== '' ? ', sabiendo el hash de lo que estaba.' : '.'));
        }

        $almacen->mover($base . '/Facturas/FE_1.xml', $base . '/Movidas/FE_1.xml');
        $movido = $almacen->identidad($base . '/Movidas/FE_1.xml');
        ($movido !== null && $movido['item_id'] === $r['item_id'])
            ? $bien('Movido de carpeta sin perder el id.')
            : $morir('Al moverlo cambió el id o no quedó donde debía.');

        $almacen->copiar($base . '/Movidas/FE_1.xml', $base . '/PAGOS/Pago 1/FE_1.xml');
        $nombres = array_map(function ($i) { return $i['nombre']; }, $almacen->listarCarpeta($base . '/PAGOS/Pago 1'));
        $nombres === ['FE_1.xml']
            ? $bien('Copiado a la carpeta del pago y listado.')
            : $morir('La carpeta del pago no tiene lo esperado: ' . json_encode($nombres));

        $local = $almacen->rutaLocal($base . '/PAGOS/Pago 1/FE_1.xml');
        ($local !== null && file_get_contents($local) === '<FacturaElectronica>uno</FacturaElectronica>')
            ? $bien('Bajado a un temporal para lo que necesita un archivo (ZIP, pdftotext), y devuelto.')
            : $morir('No se pudo bajar a un temporal.');
        $almacen->soltarLocal($local);
    } catch (Throwable $e) {
        $morir('El almacén falló.', $e);
    } finally {
        @unlink($uno);
        @unlink($otro);
        if (!$conservar) {
            try {
                $almacen->borrar($base);
            } catch (Throwable $e) {
                $aviso('No se pudo borrar ' . $base . ': bórrala a mano.');
            }
        }
    }
}

// ── 7. Limpiar ────────────────────────────────────────────────────────────

if ($conservar) {
    $anunciar('Se conserva lo subido (--conservar). Míralo en SharePoint desde el navegador.');
} else {
    $anunciar('Borrando lo subido');
    foreach (array_filter([$idItem, $idGrande]) as $id) {
        try {
            $graph->pedir('DELETE', $graph->unidad() . '/items/' . rawurlencode($id));
            $bien('Borrado ' . $id . '. Queda en la papelera de SharePoint 93 días.');
        } catch (Throwable $e) {
            $aviso('No se pudo borrar ' . $id . ': ' . $e->getMessage() . ' Bórralo a mano desde SharePoint.');
        }
    }
}

// ── Grabar ────────────────────────────────────────────────────────────────

if ($grabar) {
    // Lo que identifica al tenant se tapa: el repositorio es público.
    $tapar = [
        $cfg['tenant_id'] => 'TENANT', $cfg['client_id'] => 'CLIENTE',
        $cfg['drive_id'] => 'UNIDAD', rawurlencode($cfg['drive_id']) => 'UNIDAD',
    ];
    $json = json_encode($transporte->intercambios(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $json = strtr($json, $tapar);
    $json = preg_replace('~[a-z0-9-]+\.sharepoint\.com~i', 'contoso.sharepoint.com', $json);
    $json = preg_replace('~[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}~', 'alguien@contoso.com', $json);
    $destino = __DIR__ . '/../tests/fixtures/graph/probar_sharepoint.json';
    if (!is_dir(dirname($destino))) {
        mkdir(dirname($destino), 0777, true);
    }
    file_put_contents($destino, $json . "\n");
    echo "\nGrabado en tests/fixtures/graph/probar_sharepoint.json.\n";
    echo "Revísalo antes de hacer commit: se taparon tenant, aplicación, biblioteca,\n";
    echo "dominio y correos, pero los nombres de archivos y carpetas quedan tal cual.\n";
}

echo "\n", str_repeat('-', 72), "\n";
echo "Todo pasó. Este servidor puede leer y escribir en SharePoint sin que\n";
echo "haya nadie con la sesión iniciada, con el mismo cliente que usa la aplicación.\n\n";
exit(0);
