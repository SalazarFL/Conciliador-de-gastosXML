<?php
/**
 * Reparte el archivo ya existente en una carpeta por empresa.
 *
 * El árbol nació cuando había una sola empresa:
 *
 *   SISTEMA/2026/07 JULIO/Facturas/FE_PROVEEDOR_010726_00000123.xml
 *
 * y desde que hay dos, los documentos de ambas caían revueltos en las mismas
 * carpetas de mes. Ahora la empresa va justo bajo SISTEMA:
 *
 *   SISTEMA/GRUPO BM SP SA/2026/07 JULIO/Facturas/FE_...xml
 *
 * Esto mueve lo que ya estaba y corrige la ruta guardada de cada documento. La
 * base guarda la ruta RELATIVA a la carpeta compartida, así que mover el
 * archivo sin corregir la fila lo deja marcado como perdido: las dos cosas van
 * juntas, documento por documento, y solo se escribe la fila cuando el archivo
 * llegó a su destino.
 *
 * Uso:
 *   php cli/separar_documentos_por_empresa.php            # simulación
 *   php cli/separar_documentos_por_empresa.php --aplicar  # mueve y corrige
 *
 * Se puede correr cuantas veces haga falta: lo que ya está en su carpeta no se
 * vuelve a tocar. Conviene con la aplicación quieta —nadie importando— y con
 * OneDrive al día, para que ningún archivo esté a medio sincronizar.
 */

if (PHP_SAPI !== 'cli') { exit("Solo CLI.\n"); }

$ROOT = dirname(__DIR__);
require_once $ROOT . '/app/config/config.php';
require_once $ROOT . '/app/core/Model.php';
require_once $ROOT . '/app/core/Esquema.php';
require_once $ROOT . '/app/core/AlcanceSociedad.php';
require_once $ROOT . '/app/helpers/RutaDocumento.php';
require_once $ROOT . '/app/helpers/DocumentoArchivo.php';
require_once $ROOT . '/app/models/Sociedad.php';

$aplicar = in_array('--aplicar', $argv, true);

/** Acceso directo a facturas_xml: hay que leer y escribir la ruta cruda. */
class DocumentosPorEmpresa extends Model
{
    protected $table = 'facturas_xml';

    public function __construct() {}

    /**
     * Los documentos archivados, con su empresa. Sin hidratar la ruta: aquí
     * hace falta el valor tal como está guardado, para reescribir el prefijo.
     */
    public function archivados()
    {
        return $this->fetchAll(
            "SELECT f.id, f.sociedad_id, s.nombre AS sociedad_nombre,
                    f.ruta_xml, f.ruta_pdf
               FROM facturas_xml f
               LEFT JOIN sociedades s ON s.id = f.sociedad_id
              WHERE COALESCE(f.ruta_xml, '') <> '' OR COALESCE(f.ruta_pdf, '') <> ''
              ORDER BY f.id ASC"
        ) ?: [];
    }

    public function corregirRutas($id, $rutaXml, $rutaPdf)
    {
        return $this->execute(
            'UPDATE facturas_xml SET ruta_xml = ?, ruta_pdf = ? WHERE id = ?',
            [$rutaXml !== '' ? $rutaXml : null, $rutaPdf !== '' ? $rutaPdf : null, (int) $id]
        );
    }
}

$raiz = DocumentoArchivo::raizConfigurada();
if ($raiz === '') {
    exit("No está configurada la carpeta raíz de XML y PDF (Correo → carpeta destino).\n");
}
$raiz = rtrim(str_replace('/', DIRECTORY_SEPARATOR, $raiz), '\\/');
if (!is_dir($raiz)) {
    exit("La carpeta raíz no existe en esta computadora:\n  {$raiz}\n");
}
RutaDocumento::fijarRaiz($raiz);

$SIS = DocumentoArchivo::CARPETA_SISTEMA;

echo "\nSeparar el archivo por empresa\n";
echo "Carpeta raíz: {$raiz}\n";
echo $aplicar ? "Modo: APLICAR (mueve archivos y corrige la base)\n" : "Modo: simulación (no se toca nada)\n";
echo str_repeat('-', 74), "\n";

$db = new DocumentosPorEmpresa();
$documentos = $db->archivados();
echo 'Documentos archivados: ', count($documentos), "\n\n";

/**
 * La ruta relativa con la empresa metida después de SISTEMA.
 * Devuelve '' cuando no hay nada que cambiar (ya está separada, o la ruta no
 * cuelga de SISTEMA y entonces no es de este árbol).
 */
$conEmpresa = function ($relativa, $carpetaEmpresa) use ($SIS) {
    $relativa = str_replace('\\', '/', trim((string) $relativa));
    if ($relativa === '' || $carpetaEmpresa === '') {
        return '';
    }
    $prefijo = $SIS . '/';
    if (strncasecmp($relativa, $prefijo, strlen($prefijo)) !== 0) {
        return '';   // fuera de SISTEMA: no es del árbol que se reparte
    }
    $resto = substr($relativa, strlen($prefijo));
    // Ya separada: el primer tramo es el nombre de alguna empresa.
    if (strncasecmp($resto, $carpetaEmpresa . '/', strlen($carpetaEmpresa) + 1) === 0) {
        return '';
    }
    return $prefijo . $carpetaEmpresa . '/' . $resto;
};

$stats = ['movidos' => 0, 'ya_estaban' => 0, 'sin_empresa' => 0,
          'no_estan' => 0, 'fuera' => 0, 'errores' => 0];
$porEmpresa = [];
$problemas = [];

foreach ($documentos as $d) {
    $nombre = trim((string) ($d['sociedad_nombre'] ?? ''));
    $carpeta = DocumentoArchivo::carpetaEmpresa(
        $nombre !== '' ? $nombre : DocumentoArchivo::EMPRESA_SIN_DEFINIR
    );
    if ($nombre === '') {
        $stats['sin_empresa']++;
    }

    $destinos = [];
    $algoFuera = false;
    foreach (['ruta_xml', 'ruta_pdf'] as $campo) {
        $rel = str_replace('\\', '/', trim((string) ($d[$campo] ?? '')));
        if ($rel === '') {
            $destinos[$campo] = '';
            continue;
        }
        $nueva = $conEmpresa($rel, $carpeta);
        if ($nueva === '') {
            $destinos[$campo] = $rel;      // se queda como está
            $prefijo = $SIS . '/';
            if (strncasecmp($rel, $prefijo, strlen($prefijo)) !== 0) {
                $algoFuera = true;
            }
            continue;
        }
        $destinos[$campo] = $nueva;
    }

    if ($algoFuera) {
        $stats['fuera']++;
    }

    $cambia = ($destinos['ruta_xml'] !== str_replace('\\', '/', (string) ($d['ruta_xml'] ?? '')))
           || ($destinos['ruta_pdf'] !== str_replace('\\', '/', (string) ($d['ruta_pdf'] ?? '')));
    if (!$cambia) {
        $stats['ya_estaban']++;
        continue;
    }

    // Los dos archivos se mueven juntos o no se mueve ninguno: la fila apunta
    // a los dos, y dejarla a medias es dejar un documento medio perdido.
    $mover = [];
    $falta = false;
    foreach (['ruta_xml', 'ruta_pdf'] as $campo) {
        $rel = str_replace('\\', '/', trim((string) ($d[$campo] ?? '')));
        if ($rel === '' || $destinos[$campo] === $rel) {
            continue;
        }
        $origen = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $destino = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $destinos[$campo]);
        if (!is_file($origen)) {
            $falta = true;
            continue;
        }
        $mover[] = [$origen, $destino];
    }

    if ($falta && !$mover) {
        // Ninguno de sus archivos está en el disco: la fila ya estaba marcada
        // como perdida y moverla de carpeta no la arregla.
        $stats['no_estan']++;
        continue;
    }

    $porEmpresa[$carpeta] = ($porEmpresa[$carpeta] ?? 0) + 1;

    if (!$aplicar) {
        $stats['movidos']++;
        continue;
    }

    try {
        foreach ($mover as [$origen, $destino]) {
            $dir = dirname($destino);
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new RuntimeException('No se pudo crear ' . $dir);
            }
            if (is_file($destino)) {
                // Ya hay algo con ese nombre en el destino. Si es el mismo
                // contenido, el traslado ya se hizo antes y solo falta la
                // fila; si no, se deja quieto y se anota.
                if (hash_file('sha256', $destino) !== hash_file('sha256', $origen)) {
                    throw new RuntimeException('El destino ya existe con otro contenido: ' . $destino);
                }
                @unlink($origen);
                continue;
            }
            if (!rename($origen, $destino)) {
                throw new RuntimeException('No se pudo mover ' . $origen);
            }
        }
        $db->corregirRutas((int) $d['id'], $destinos['ruta_xml'], $destinos['ruta_pdf']);
        $stats['movidos']++;
    } catch (Throwable $e) {
        $stats['errores']++;
        if (count($problemas) < 20) {
            $problemas[] = '#' . $d['id'] . ': ' . $e->getMessage();
        }
    }
}

ksort($porEmpresa, SORT_NATURAL | SORT_FLAG_CASE);
if ($porEmpresa) {
    echo "Por empresa:\n";
    foreach ($porEmpresa as $empresa => $n) {
        printf("  %-45s %6d\n", $empresa, $n);
    }
    echo "\n";
}

printf("  %-34s %6d\n", $aplicar ? 'Movidos' : 'Se moverían', $stats['movidos']);
printf("  %-34s %6d\n", 'Ya estaban en su carpeta', $stats['ya_estaban']);
printf("  %-34s %6d\n", 'Sin empresa (van a SIN EMPRESA)', $stats['sin_empresa']);
printf("  %-34s %6d\n", 'Sus archivos no están en disco', $stats['no_estan']);
printf("  %-34s %6d\n", 'Con rutas fuera de SISTEMA', $stats['fuera']);
printf("  %-34s %6d\n", 'Errores', $stats['errores']);

foreach ($problemas as $p) {
    echo "    ! ", $p, "\n";
}

if (!$aplicar) {
    echo "\nNada se movió. Para hacerlo:\n";
    echo "  php cli/separar_documentos_por_empresa.php --aplicar\n";
} else {
    echo "\nListo. Las carpetas de mes que quedaron vacías se pueden borrar a mano.\n";
    echo "Si algo quedó marcado como perdido, `php cli/limpiar_rutas_perdidas.php` lo revisa.\n";
}
echo "\n";

exit($stats['errores'] > 0 ? 1 : 0);
