<?php
/**
 * Un ZIP con los XML y PDF de la bandeja, ya con el nombrado del archivo.
 *
 * Es guardado y nada más: no importa, no marca, no mueve ni borra nada. La
 * bandeja queda igual que estaba. Sirve para llevarse un lote a donde haga
 * falta —el contador, una carpeta suelta, un correo— sin pasar por el flujo
 * de importación, que es otra cosa y tiene sus propias consecuencias.
 *
 * El nombre de cada archivo es el mismo que usa DocumentoArchivo, para que lo
 * guardado fuera se lea igual que lo archivado dentro:
 *
 *   FE_PROVEEDOR_ddmmyy_00000000.xml   y su .pdf al lado
 *
 * Van todos planos en la raíz del ZIP: un lote se revisa de un vistazo, y las
 * subcarpetas por año y mes solo estorban cuando lo que se quiere es agarrar
 * los archivos y mandarlos.
 */
require_once __DIR__ . '/DocumentoArchivo.php';

class PaqueteDocumentos
{
    /**
     * Arma el ZIP y devuelve el recuento de lo que entró y lo que faltaba.
     *
     * Una fila sin su archivo en disco no detiene el paquete: se cuenta y se
     * sigue. Es lo corriente que falte el PDF —muchos proveedores no lo
     * mandan— y sería absurdo quedarse sin los XML por eso. Solo cuando no
     * hay nada que empaquetar se lanza el error, porque un ZIP vacío parece
     * un guardado exitoso y no lo es.
     */
    public static function crear(array $filas, $rutaZip)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Esta instalación de PHP no tiene la extensión zip activada.');
        }

        $zip = new ZipArchive();
        if ($zip->open((string) $rutaZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo ZIP temporal.');
        }

        $resumen = ['documentos' => 0, 'xml' => 0, 'pdf' => 0, 'sin_xml' => 0, 'sin_pdf' => 0];
        $usados = [];

        foreach ($filas as $fila) {
            $base = self::baseUnica($fila, $usados);
            $entro = false;

            $rutaXml = trim((string) ($fila['archivo_xml'] ?? ''));
            if ($rutaXml !== '' && is_file($rutaXml) && $zip->addFile($rutaXml, $base . '.xml')) {
                $resumen['xml']++;
                $entro = true;
            } else {
                $resumen['sin_xml']++;
            }

            $rutaPdf = trim((string) ($fila['archivo_pdf'] ?? ''));
            if ($rutaPdf !== '' && is_file($rutaPdf) && $zip->addFile($rutaPdf, $base . '.pdf')) {
                $resumen['pdf']++;
                $entro = true;
            } else {
                $resumen['sin_pdf']++;
            }

            if ($entro) {
                $resumen['documentos']++;
            }
        }

        if ($resumen['xml'] + $resumen['pdf'] === 0) {
            $zip->close();
            @unlink((string) $rutaZip);
            throw new RuntimeException('Ninguno de los documentos tiene su archivo en el disco.');
        }

        if (!$zip->close()) {
            @unlink((string) $rutaZip);
            throw new RuntimeException('No se pudo terminar de escribir el archivo ZIP.');
        }

        return $resumen;
    }

    /**
     * El nombre del archivo, con un sufijo si ya se usó.
     *
     * Dos filas pueden dar el mismo nombre —el mismo comprobante capturado
     * dos veces, o un proveedor que repite consecutivo— y dentro de un ZIP el
     * segundo pisaría al primero sin avisar. Numerarlo conserva los dos y deja
     * ver que hay algo repetido.
     */
    private static function baseUnica(array $fila, array &$usados)
    {
        $base = DocumentoArchivo::nombreBase($fila, (string) ($fila['proveedor'] ?? ''));
        $final = $base;
        $n = 1;
        while (isset($usados[strtolower($final)])) {
            $n++;
            $final = $base . '_' . $n;
        }
        $usados[strtolower($final)] = true;

        return $final;
    }
}
