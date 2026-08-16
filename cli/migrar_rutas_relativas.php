<?php
/**
 * Pasa la base de datos a rutas relativas y saca del proyecto los documentos
 * que todavía viven dentro de él.
 *
 * Uso: php cli/migrar_rutas_relativas.php [--aplicar] [--raiz=RUTA]
 *
 * Sin --aplicar solo informa: no mueve ni un archivo ni cambia ni una fila.
 * Córrelo así primero, revisa el resumen, y solo entonces vuelve a correrlo
 * con --aplicar.
 *
 * Qué hace, en orden:
 *   1. Traslada a la carpeta compartida lo que hoy está en storage/correo/
 *      (bandeja), public/uploads/xml_queue/ (cola) y storage/devoluciones/.
 *      Son documentos que la base referencia, así que dentro del proyecto solo
 *      existen para quien los bajó: cualquier otra computadora ve la fila y no
 *      encuentra el archivo.
 *   2. Convierte a relativas todas las rutas que ya apuntan dentro de la
 *      carpeta compartida.
 *   3. Reporta lo que quedó fuera, con nombre y apellido, para revisarlo a
 *      mano — nunca inventa una ubicación.
 *
 * Es repetible: correrlo dos veces no hace daño ni duplica nada. Antes de
 * tocar la base deja un respaldo de las rutas anteriores en
 * storage/backups/rutas_antes_<fecha>.csv.
 */
if (PHP_SAPI !== 'cli') { exit("Solo CLI.\n"); }

require_once __DIR__ . '/../app/helpers/RutaDocumento.php';
require_once __DIR__ . '/../app/helpers/DocumentoArchivo.php';

$aplicar = in_array('--aplicar', $argv, true);
foreach ($argv as $arg) {
    if (strpos($arg, '--raiz=') === 0) {
        RutaDocumento::fijarRaiz(substr($arg, 7));
    }
}

$raiz = RutaDocumento::raiz();
if ($raiz === '') {
    exit("No hay carpeta compartida configurada.\n"
        . "Configúrala en Correo → Carpeta de documentos, o pásala con --raiz=\"C:\\...\"\n");
}
if (!is_dir($raiz)) {
    exit("La carpeta compartida no existe en esta computadora:\n  {$raiz}\n"
        . "Si es la carpeta sincronizada de SharePoint, espera a que termine de sincronizar.\n");
}

$config = require __DIR__ . '/../app/config/database.php';
$pdo = new PDO(
    $config['dsn'],
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$proyecto = dirname(__DIR__);

// Cada entrada: columna de la base ← carpeta del proyecto que se vacía → destino
// dentro de la carpeta compartida.
$columnas = [
    ['tabla' => 'facturas_xml',      'id' => 'id', 'campo' => 'ruta_xml',     'origen' => null, 'destino' => null],
    ['tabla' => 'facturas_xml',      'id' => 'id', 'campo' => 'ruta_pdf',     'origen' => null, 'destino' => null],
    ['tabla' => 'correo_bandeja',    'id' => 'id', 'campo' => 'archivo_xml',
     'origen' => $proyecto . '/storage/correo/xml',            'destino' => '_TRABAJO/BANDEJA/xml'],
    ['tabla' => 'correo_bandeja',    'id' => 'id', 'campo' => 'archivo_pdf',
     'origen' => $proyecto . '/storage/correo/pdf',            'destino' => '_TRABAJO/BANDEJA/pdf'],
    ['tabla' => 'devoluciones',      'id' => 'id', 'campo' => 'ruta_pdf',
     'origen' => $proyecto . '/storage/devoluciones',          'destino' => '_TRABAJO/DEVOLUCIONES'],
    ['tabla' => 'importacion_items', 'id' => 'id', 'campo' => 'ruta_archivo',
     'origen' => $proyecto . '/public/uploads/xml_queue',      'destino' => '_TRABAJO/IMPORTACIONES'],
    ['tabla' => 'importaciones',     'id' => 'id', 'campo' => 'ruta_archivo',
     'origen' => $proyecto . '/public/uploads/xml_queue',      'destino' => '_TRABAJO/IMPORTACIONES'],
];

$resumen = [
    'raiz' => $raiz,
    'modo' => $aplicar ? 'aplicar' : 'solo informar',
    'ya_relativas' => 0,
    'convertidas' => 0,
    'archivos_movidos' => 0,
    'archivos_ausentes' => 0,
    'fuera_de_raiz' => 0,
    'errores' => 0,
];
$fueraDeRaiz = [];
$errores = [];
$respaldo = [];
$porColumna = [];

/** Ruta bajo la carpeta compartida que corresponde a una ruta del proyecto. */
function destinoEnRaiz($rutaOrigen, $carpetaProyecto, $subdestino)
{
    $origen = str_replace('\\', '/', (string) $rutaOrigen);
    $base = rtrim(str_replace('\\', '/', (string) $carpetaProyecto), '/') . '/';
    if (stripos($origen, $base) !== 0) {
        return '';
    }
    $cola = substr($origen, strlen($base));
    return rtrim($subdestino, '/') . '/' . $cola;
}

foreach ($columnas as $col) {
    $tabla = $col['tabla'];
    $campo = $col['campo'];
    $filas = $pdo->query("SELECT {$col['id']} AS id, {$campo} AS ruta FROM {$tabla}
                          WHERE {$campo} IS NOT NULL AND {$campo} <> ''")->fetchAll();

    $etiqueta = "{$tabla}.{$campo}";
    $porColumna[$etiqueta] = ['filas' => count($filas), 'con_archivo' => 0, 'sin_archivo' => 0, 'fuera' => 0];

    foreach ($filas as $fila) {
        $id = (int) $fila['id'];
        $ruta = (string) $fila['ruta'];

        if (!RutaDocumento::esAbsoluta($ruta)) {
            $resumen['ya_relativas']++;
            continue;
        }

        $respaldo[] = [$tabla, $campo, $id, $ruta];

        // Caso 1: ya está dentro de la carpeta compartida → solo se acorta.
        if (RutaDocumento::dentroDeRaiz($ruta)) {
            $nueva = RutaDocumento::relativa($ruta);
            if ($aplicar) {
                $pdo->prepare("UPDATE {$tabla} SET {$campo} = ? WHERE {$col['id']} = ?")
                    ->execute([$nueva, $id]);
            }
            $porColumna[$etiqueta][is_file($ruta) ? 'con_archivo' : 'sin_archivo']++;
            $resumen['convertidas']++;
            continue;
        }

        // Caso 2: está dentro del proyecto → el archivo se traslada y la fila
        // se apunta al lugar nuevo.
        $relativaNueva = $col['origen'] !== null
            ? destinoEnRaiz($ruta, $col['origen'], $col['destino'])
            : '';

        if ($relativaNueva === '') {
            $resumen['fuera_de_raiz']++;
            $porColumna[$etiqueta]['fuera']++;
            $fueraDeRaiz[] = "{$tabla}#{$id} {$campo}: {$ruta}";
            continue;
        }

        $destinoAbs = $raiz . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativaNueva);

        // Una carpeta (importaciones) y un archivo se tratan distinto.
        $esDirectorio = is_dir($ruta);
        $existeOrigen = $esDirectorio || is_file($ruta);
        $yaEnDestino = $esDirectorio ? is_dir($destinoAbs) : is_file($destinoAbs);

        if (!$existeOrigen && !$yaEnDestino) {
            // El archivo ya no está en ninguna parte: la fila se apunta al
            // destino igual, para que quede consistente con las demás y para
            // que el diagnóstico lo liste como faltante en vez de esconderlo
            // detrás de una ruta de otra computadora.
            //
            // En correo_bandeja esto es lo NORMAL, no un problema: al importar
            // un XML su copia de la bandeja se borra, y la fila queda como
            // constancia de que ese correo ya se procesó.
            $resumen['archivos_ausentes']++;
            $porColumna[$etiqueta]['sin_archivo']++;
        } else {
            $porColumna[$etiqueta]['con_archivo']++;
        }

        if ($aplicar) {
            try {
                if ($existeOrigen && !$yaEnDestino) {
                    $padre = dirname($destinoAbs);
                    if (!is_dir($padre) && !mkdir($padre, 0777, true) && !is_dir($padre)) {
                        throw new RuntimeException('No se pudo crear ' . $padre);
                    }
                    if (!rename($ruta, $destinoAbs)) {
                        throw new RuntimeException('No se pudo mover a ' . $destinoAbs);
                    }
                    $resumen['archivos_movidos']++;
                }
                $pdo->prepare("UPDATE {$tabla} SET {$campo} = ? WHERE {$col['id']} = ?")
                    ->execute([$relativaNueva, $id]);
                $resumen['convertidas']++;
            } catch (Throwable $e) {
                $resumen['errores']++;
                $errores[] = "{$tabla}#{$id} {$campo}: " . $e->getMessage();
            }
        } else {
            if ($existeOrigen && !$yaEnDestino) {
                $resumen['archivos_movidos']++;
            }
            $resumen['convertidas']++;
        }
    }
}

// Barrido final por carpeta. Lo anterior mueve lo que la base referencia; esto
// se lleva lo que quedó suelto — un PDF huérfano que nunca llegó a tener fila,
// o restos de una fila ya borrada. Si no, seguirían dentro del proyecto para
// siempre, invisibles para las demás computadoras.
$barrido = [
    'storage/correo/xml'             => 'BANDEJA/xml',
    'storage/correo/pdf'             => 'BANDEJA/pdf',
    'storage/correo/sin_identificar' => 'BANDEJA/sin-identificar',
];
$sueltos = 0;
foreach ($barrido as $rel => $destinoSub) {
    $dir = $proyecto . '/' . $rel;
    if (!is_dir($dir)) {
        continue;
    }
    foreach (new DirectoryIterator($dir) as $item) {
        // Los marcadores tipo .gitkeep no son documentos.
        if ($item->isDot() || !$item->isFile() || strpos($item->getFilename(), '.') === 0) {
            continue;
        }
        $sueltos++;
        if (!$aplicar) {
            $resumen['archivos_movidos']++;
            continue;
        }
        $destino = RutaDocumento::carpetaTrabajo($destinoSub)
            . DIRECTORY_SEPARATOR . $item->getFilename();
        if (is_file($destino) || @rename($item->getPathname(), $destino)) {
            $resumen['archivos_movidos']++;
        } else {
            $resumen['errores']++;
            $errores[] = $rel . ': no se pudo mover ' . $item->getFilename();
        }
    }
}

if ($aplicar && $respaldo) {
    $dir = $proyecto . '/storage/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $csv = $dir . '/rutas_antes_' . date('Ymd_His') . '.csv';
    $fp = fopen($csv, 'w');
    if ($fp !== false) {
        fputcsv($fp, ['tabla', 'columna', 'id', 'ruta_anterior'], ',', '"', '\\');
        foreach ($respaldo as $linea) {
            fputcsv($fp, $linea, ',', '"', '\\');
        }
        fclose($fp);
        $resumen['respaldo'] = $csv;
    }
}

echo "\n";
foreach ($resumen as $clave => $valor) {
    printf("  %-18s %s\n", $clave, is_bool($valor) ? ($valor ? 'sí' : 'no') : $valor);
}
if ($sueltos > 0) {
    echo "  archivos sueltos en la bandeja: {$sueltos}\n";
}

printf("\n  %-30s %8s %10s %10s %8s\n", 'columna', 'filas', 'c/archivo', 's/archivo', 'fuera');
foreach ($porColumna as $etiqueta => $d) {
    printf("  %-30s %8d %10d %10d %8d\n",
        $etiqueta, $d['filas'], $d['con_archivo'], $d['sin_archivo'], $d['fuera']);
}
echo "\n  \"s/archivo\" en correo_bandeja es lo esperado: la copia de la bandeja\n"
   . "  se borra al importar y la fila queda como constancia del correo.\n";

if ($fueraDeRaiz) {
    echo "\nRutas que NO están en la carpeta compartida ni en el proyecto (revísalas a mano):\n";
    foreach (array_slice($fueraDeRaiz, 0, 25) as $linea) {
        echo "  - {$linea}\n";
    }
    if (count($fueraDeRaiz) > 25) {
        echo '  … y ' . (count($fueraDeRaiz) - 25) . " más\n";
    }
}

if ($errores) {
    echo "\nErrores:\n";
    foreach (array_slice($errores, 0, 25) as $linea) {
        echo "  - {$linea}\n";
    }
}

if (!$aplicar) {
    echo "\nNada se cambió. Vuelve a correrlo con --aplicar cuando el resumen te cuadre.\n";
} else {
    echo "\nListo. Ahora aplica los cambios de estructura:\n"
        . "  mysql -u root bd_xmlconcilia < database/migration_rutas_relativas.sql\n"
        . "y comprueba el resultado con:\n"
        . "  php cli/diagnostico.php\n";
}

exit($resumen['errores'] > 0 ? 1 : 0);
