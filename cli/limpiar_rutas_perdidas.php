<?php
/**
 * Borra las rutas que apuntan a un lugar que ya no existe, conservando el
 * documento en la base como registro contable.
 *
 * Uso: php cli/limpiar_rutas_perdidas.php [--aplicar]
 *
 * Sin --aplicar solo informa.
 *
 * Cuándo se usa: cuando una carpeta de documentos se borró o se movió y sus
 * archivos ya no están en ninguna parte. La fila conserva todo lo que importa
 * —proveedor, montos, fechas, semana de pago, hash— y solo pierde el "dónde
 * está", que de todos modos apuntaba al vacío. Así el sistema dice "sin
 * archivo" en vez de mandar a una carpeta inexistente.
 *
 * La regla es a propósito estrecha: solo se limpia una ruta si es ABSOLUTA y
 * el archivo no existe. Una ruta relativa que no se encuentra puede ser
 * simplemente un archivo que SharePoint todavía no ha bajado en esta
 * computadora, y borrarla sería perder la ubicación de un documento que sí
 * está. Una ruta absoluta, en cambio, es un resto de otra carpeta o de otra
 * máquina: nunca va a servirle a nadie más tal como está.
 *
 * `hash_xml` NO se toca. Es la huella del contenido: si algún día aparece un
 * respaldo y sus archivos se copian a la carpeta compartida,
 * `php cli/organizar_documentos.php --completo` los reconoce por hash y vuelve
 * a enlazarlos solo.
 */
if (PHP_SAPI !== 'cli') { exit("Solo CLI.\n"); }

require_once __DIR__ . '/../app/helpers/RutaDocumento.php';

$aplicar = in_array('--aplicar', $argv, true);

$config = require __DIR__ . '/../app/config/database.php';
$pdo = new PDO(
    $config['dsn'],
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$columnas = [
    ['tabla' => 'facturas_xml',   'campo' => 'ruta_xml'],
    ['tabla' => 'facturas_xml',   'campo' => 'ruta_pdf'],
    ['tabla' => 'correo_bandeja', 'campo' => 'archivo_xml'],
    ['tabla' => 'correo_bandeja', 'campo' => 'archivo_pdf'],
    ['tabla' => 'devoluciones',   'campo' => 'ruta_pdf'],
];

$respaldo = [];
$total = 0;
$conservadas = 0;

foreach ($columnas as $col) {
    ['tabla' => $tabla, 'campo' => $campo] = $col;

    $filas = $pdo->query(
        "SELECT id, {$campo} AS ruta FROM {$tabla}
         WHERE {$campo} REGEXP '^([A-Za-z]:|\\\\\\\\|/)'"
    )->fetchAll();

    $limpiables = [];
    foreach ($filas as $fila) {
        // Si el archivo sigue existiendo donde dice, la ruta es buena aunque
        // sea absoluta: no se toca, se convierte con migrar_rutas_relativas.
        if (is_file($fila['ruta'])) {
            $conservadas++;
            continue;
        }
        $limpiables[] = (int) $fila['id'];
        $respaldo[] = [$tabla, $campo, (int) $fila['id'], (string) $fila['ruta']];
    }

    if (!$limpiables) {
        printf("  %-28s sin rutas perdidas\n", "{$tabla}.{$campo}");
        continue;
    }

    printf("  %-28s %d ruta(s) perdida(s)\n", "{$tabla}.{$campo}", count($limpiables));
    $total += count($limpiables);

    if ($aplicar) {
        $enLotes = array_chunk($limpiables, 500);
        foreach ($enLotes as $lote) {
            $marcas = implode(',', array_fill(0, count($lote), '?'));
            $pdo->prepare("UPDATE {$tabla} SET {$campo} = NULL WHERE id IN ({$marcas})")
                ->execute($lote);
        }
    }
}

// El estado del PDF debe reflejar que no hay archivo, no seguir diciendo
// "disponible" apuntando a la nada.
if ($aplicar && $total > 0) {
    $pdo->exec("UPDATE facturas_xml
                   SET estado_pdf = 'no_disponible_historico'
                 WHERE (ruta_pdf IS NULL OR ruta_pdf = '')
                   AND estado_pdf = 'disponible'");
}

if ($respaldo) {
    $dir = dirname(__DIR__) . '/storage/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $csv = $dir . '/rutas_perdidas_' . date('Ymd_His') . '.csv';
    $fp = fopen($csv, 'w');
    if ($fp !== false) {
        fputcsv($fp, ['tabla', 'columna', 'id', 'ruta_perdida'], ',', '"', '\\');
        foreach ($respaldo as $linea) {
            fputcsv($fp, $linea, ',', '"', '\\');
        }
        fclose($fp);
        echo "\n  Se anotó a dónde apuntaba cada una en:\n  {$csv}\n";
    }
}

echo "\n  Total a limpiar: {$total}\n";
if ($conservadas > 0) {
    echo "  Conservadas (absolutas pero el archivo SÍ existe): {$conservadas}\n";
    echo "  Esas se arreglan con: php cli/migrar_rutas_relativas.php --aplicar\n";
}

if (!$aplicar) {
    echo "\nNada se cambió. Vuelve a correrlo con --aplicar si el resumen te cuadra.\n";
} else {
    echo "\nListo. Los documentos siguen en la base con sus montos, fechas y semana;\n"
       . "lo único que se borró es una ubicación que ya no llevaba a ninguna parte.\n"
       . "Si algún día aparece un respaldo, copia los archivos a la carpeta compartida y corre:\n"
       . "  php cli/organizar_documentos.php --completo\n";
}
