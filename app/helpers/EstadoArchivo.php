<?php
/**
 * Si el documento todavía tiene sus archivos, y qué se puede hacer si no.
 *
 * La base guarda la ruta del XML y del PDF, pero los archivos viven en una
 * carpeta compartida que cualquiera abre en el Explorador. Que la fila tenga
 * ruta no quiere decir que al final de esa ruta haya algo: se borran carpetas,
 * y entonces el listado seguía diciendo "respaldada" mientras el comprobante
 * ya no existía. Solo se descubría al hacer clic.
 *
 * De ahí que esto se pregunte en TODOS los módulos que enseñan un documento, y
 * que viva en un solo sitio en vez de repetirse en cada listado. La marca y el
 * botón que lo acompañan están en app/views/partials/marca-archivo.php.
 *
 * Distinguir importa: "falta el comprobante" es un documento que nunca llegó y
 * hay que conseguir; "archivo perdido" es uno que llegó, se archivó y
 * desapareció, y ese casi siempre se puede volver a bajar del correo del que
 * salió (ver RecuperadorDocumentos).
 */

require_once __DIR__ . '/RutaDocumento.php';

class EstadoArchivo
{
    /**
     * @param array $fila Con ruta_xml y ruta_pdf. Para saber si se puede
     *        reponer basta una de dos: la columna calculada `recuperable`
     *        —la que traen las consultas de listado, para no arrastrar el
     *        hash entero— o `correo_uid` y `hash_xml` en crudo.
     */
    public static function de(array $fila)
    {
        $rutaXml = trim((string) ($fila['ruta_xml'] ?? ''));
        $rutaPdf = trim((string) ($fila['ruta_pdf'] ?? ''));

        // Se le pregunta al almacén, no al disco: los documentos pueden no
        // estar en un disco. Ver AlmacenDocumentos.
        $xmlOk = $rutaXml !== '' && RutaDocumento::existe($rutaXml);
        $pdfOk = $rutaPdf !== '' && RutaDocumento::existe($rutaPdf);
        $perdido = ($rutaXml !== '' && !$xmlOk) || ($rutaPdf !== '' && !$pdfOk);

        return [
            'xml_ok'      => $xmlOk,
            'pdf_ok'      => $pdfOk,
            'perdido'     => $perdido,
            'recuperable' => $perdido && self::puedeBajarseDelCorreo($fila),
            'que_falta'   => self::queFalta($rutaXml, $xmlOk, $rutaPdf, $pdfOk),
            // El par completo es lo que se puede entregar. La carpeta del pago
            // semanal solo recibe pares: un documento al que le falta el PDF
            // no sirve para respaldar un pago, así que no se copia.
            'entregable'  => $xmlOk && $pdfOk,
            // Y la otra mitad de la historia: lo que nunca llegó. `que_falta`
            // habla de lo que se archivó y desapareció —eso se recupera del
            // correo—; esto habla de lo que el proveedor no mandó nunca, que
            // hay que ir a conseguir. Confundirlos manda a buscar al lugar
            // equivocado.
            'nunca_llego' => self::nuncaLlego($rutaXml, $rutaPdf),
        ];
    }

    /** Lo mismo sobre una lista, con las claves prefijadas por `archivo_`. */
    public static function decorar(array $filas)
    {
        foreach ($filas as $i => $fila) {
            if (!is_array($fila)) {
                continue;
            }
            foreach (self::de($fila) as $clave => $valor) {
                $filas[$i]['archivo_' . $clave] = $valor;
            }
        }
        return $filas;
    }

    /**
     * La columna que las consultas de listado pueden traer ya resuelta, para
     * decidir si un documento se puede volver a bajar sin cargar su hash.
     */
    public static function columnaRecuperable($alias = 'x.')
    {
        return "CASE WHEN {$alias}correo_uid > 0 AND COALESCE({$alias}hash_xml, '') <> ''
                     THEN 1 ELSE 0 END";
    }

    private static function puedeBajarseDelCorreo(array $fila)
    {
        if (array_key_exists('recuperable', $fila)) {
            return !empty($fila['recuperable']);
        }
        return (int) ($fila['correo_uid'] ?? 0) > 0
            && trim((string) ($fila['hash_xml'] ?? '')) !== '';
    }

    /** Los que ni siquiera tienen ruta: nunca entraron al sistema. */
    private static function nuncaLlego($rutaXml, $rutaPdf)
    {
        $faltan = [];
        if ($rutaXml === '') $faltan[] = 'XML';
        if ($rutaPdf === '') $faltan[] = 'PDF';
        return implode(' y ', $faltan);
    }

    private static function queFalta($rutaXml, $xmlOk, $rutaPdf, $pdfOk)
    {
        $faltan = [];
        if ($rutaXml !== '' && !$xmlOk) $faltan[] = 'XML';
        if ($rutaPdf !== '' && !$pdfOk) $faltan[] = 'PDF';
        return implode(' y ', $faltan);
    }
}
