<?php
/**
 * La carpeta del pago contra el pago: cuáles facturas están y cuáles no.
 *
 * La carpeta del pago es lo que se manda. Si el pago tiene 308 facturas, la
 * carpeta tiene que tener 308 pares XML+PDF, y si no los tiene, quien la
 * manda tiene que saber cuáles faltan ANTES de mandarla.
 *
 * Eso no pasaba. El pago del 28/09/2026 salió con 614 archivos para 308
 * facturas y nada lo dijo: a una factura el proveedor nunca le mandó el PDF,
 * la carpeta solo recibe pares completos —bien—, y el aviso se perdió en el
 * camino —mal—. Al pasar los documentos a SharePoint, la carga del pago dejó
 * de nombrar las incompletas ("las copia la tarea programada") y la tarea
 * solo las contaba. Lo único que quedaba era una marca "Sin PDF" en uno de
 * 308 renglones, y el pago decía "respaldada" porque el XML cuadraba.
 *
 * Por eso esto no le pregunta a la base qué se copió: mira la carpeta. Lista
 * lo que hay de verdad —en SharePoint es una llamada: trae hasta 999
 * archivos de una vez— y lo cruza contra las líneas del pago. Así se entera
 * también de lo que nadie le contó: una copia que alguien borró a mano, una
 * que nunca llegó a subirse, un archivo que sobra.
 *
 * Una copia completa que falta en la carpeta y que la base da por hecha
 * (AlmacenCopiaPago) se olvida acá, para que la tarea la reponga en su
 * próxima vuelta. Sin eso, una copia borrada a mano no volvía nunca.
 */

require_once __DIR__ . '/DocumentoArchivo.php';
require_once __DIR__ . '/AlmacenDocumentos.php';
require_once __DIR__ . '/NumeroFactura.php';
require_once __DIR__ . '/../models/AlmacenCopiaPago.php';

class ControlCarpetaPago
{
    /** Por qué una factura no está en la carpeta, y qué hacer. */
    public const MOTIVOS = [
        'sin_comprobante' => 'No tiene comprobante: falta el XML de esta factura.',
        'sin_pdf'         => 'El proveedor nunca mandó el PDF. Hay que pedírselo; al subirlo se copia solo.',
        'sin_xml'         => 'Nunca llegó el XML.',
        'sin_xml_ni_pdf'  => 'Nunca llegaron ni el XML ni el PDF.',
        'perdido'         => 'El archivo se borró de SharePoint. Se puede recuperar desde el correo.',
        'en_disco'        => 'Sigue en la carpeta compartida de antes: pasarlo a SharePoint (php cli/migrar_documentos_a_graph.php).',
        'sin_copiar'      => 'Está completa y se está copiando a la carpeta ahora. Recarga en un minuto.',
    ];

    /**
     * Revisa la carpeta de un pago. Nunca lanza: si la carpeta no se puede
     * leer lo dice en 'error', y la pantalla del pago sigue abriendo.
     *
     * @param array $pago  Con id y carpeta_pago.
     */
    public static function revisar(array $pago, $erp, $facturas, $copias = null)
    {
        $carpeta = trim((string) ($pago['carpeta_pago'] ?? ''));
        if ($carpeta === '') {
            return null;
        }
        try {
            $lineas = $erp->getFacturasPagoParaMatching((int) $pago['id']);
            $ids = array_values(array_filter(array_map('intval', array_column($lineas, 'factura_xml_id'))));

            $documentos = [];
            if ($ids) {
                foreach ($facturas->getParaOrganizarArchivos($ids, AlmacenDocumentos::SHAREPOINT) as $doc) {
                    $documentos[(int) $doc['id']] = $doc;
                }
                // Lo que no está en SharePoint es de la carpeta compartida de
                // antes: no entra a la carpeta del pago hasta migrarlo.
                foreach ($facturas->getParaOrganizarArchivos($ids) as $doc) {
                    $doc['almacen'] = AlmacenDocumentos::DISCO;
                    $documentos[(int) $doc['id']] = $doc;
                }
            }

            $almacen = AlmacenDocumentos::principal();
            $ruta = DocumentoArchivo::sobreAlmacen($almacen)->carpetaPago($carpeta);
            $archivos = [];
            foreach ($almacen->listarCarpeta($ruta) as $item) {
                if (empty($item['es_carpeta'])) {
                    $archivos[] = (string) $item['nombre'];
                }
            }
        } catch (Throwable $e) {
            return ['error' => 'No se pudo revisar la carpeta del pago: ' . $e->getMessage(), 'carpeta' => $carpeta];
        }

        $control = self::comparar($lineas, $documentos, $archivos);
        $control['carpeta'] = $carpeta;

        // Las que la base da por copiadas y no están: se olvidan para que la
        // tarea las vuelva a copiar.
        $reponer = [];
        foreach ($control['faltan'] as $falta) {
            if ($falta['motivo'] === 'sin_copiar' && $falta['factura_xml_id'] > 0) {
                $reponer[] = $falta['factura_xml_id'];
            }
        }
        if ($reponer) {
            try {
                ($copias ?: new AlmacenCopiaPago())->olvidar($reponer);
            } catch (Throwable $e) {
            }
        }

        return $control;
    }

    /**
     * El cruce, sin red ni base.
     *
     * @param array $lineas     Líneas del pago: id, documento, numero_corto,
     *                          proveedor_nombre, factura_xml_id.
     * @param array $documentos factura_xml_id => fila como la da
     *                          Factura::getParaOrganizarArchivos().
     * @param array $archivos   Nombres de archivo que hay en la carpeta.
     */
    public static function comparar(array $lineas, array $documentos, array $archivos)
    {
        // Lo que hay, contado por nombre base y extensión. Contado y no
        // marcado: dos facturas distintas pueden llamarse igual, y entonces
        // la segunda va como <base>_DUP_<hash> (DocumentoArchivo::copiarValidado).
        // Cada línea consume un XML y un PDF de su nombre.
        $hay = [];
        $cuantos = 0;
        foreach ($archivos as $nombre) {
            $nombre = (string) $nombre;
            $extension = strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION));
            if ($extension !== 'xml' && $extension !== 'pdf') {
                continue;
            }
            $hay[self::clave(pathinfo($nombre, PATHINFO_FILENAME))][$extension][] = $nombre;
            $cuantos++;
        }

        $completas = 0;
        $faltan = [];
        foreach ($lineas as $linea) {
            $xmlId = (int) ($linea['factura_xml_id'] ?? 0);
            $doc = $xmlId > 0 ? ($documentos[$xmlId] ?? null) : null;

            if ($doc !== null) {
                $base = self::clave(DocumentoArchivo::nombreBase($doc, (string) ($doc['proveedor_nombre'] ?? 'PROVEEDOR')));
                if (!empty($hay[$base]['xml']) && !empty($hay[$base]['pdf'])) {
                    array_shift($hay[$base]['xml']);
                    array_shift($hay[$base]['pdf']);
                    $completas++;
                    continue;
                }
            }

            $faltan[] = [
                'linea_id'       => (int) ($linea['id'] ?? 0),
                'factura_xml_id' => $xmlId,
                'numero'         => self::numero($linea, $doc),
                'proveedor'      => trim((string) ($linea['proveedor_nombre'] ?? ($doc['proveedor_nombre'] ?? ''))),
                'motivo'         => self::motivo($xmlId, $doc),
            ];
        }

        // Lo que quedó sin línea: archivos que no son de ninguna factura del
        // pago, o la mitad suelta de un par.
        $sobran = [];
        foreach ($hay as $porExtension) {
            foreach ($porExtension as $lista) {
                array_push($sobran, ...$lista);
            }
        }
        sort($sobran, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'total'     => count($lineas),
            'completas' => $completas,
            'archivos'  => $cuantos,
            'faltan'    => $faltan,
            'sobran'    => $sobran,
        ];
    }

    /** El texto que explica un motivo. */
    public static function explicar($motivo)
    {
        return self::MOTIVOS[$motivo] ?? $motivo;
    }

    private static function motivo($xmlId, $doc)
    {
        if ($xmlId <= 0) {
            return 'sin_comprobante';
        }
        if ($doc === null) {
            return 'sin_comprobante';
        }
        if (($doc['almacen'] ?? '') === AlmacenDocumentos::DISCO) {
            return 'en_disco';
        }
        $sinXml = trim((string) ($doc['ruta_xml'] ?? '')) === '';
        $sinPdf = trim((string) ($doc['ruta_pdf'] ?? '')) === '';
        if ($sinXml && $sinPdf) {
            return 'sin_xml_ni_pdf';
        }
        if ($sinXml) {
            return 'sin_xml';
        }
        if ($sinPdf) {
            return 'sin_pdf';
        }
        if (!empty($doc['archivo_faltante_en'])) {
            return 'perdido';
        }
        return 'sin_copiar';
    }

    private static function numero(array $linea, $doc)
    {
        $numero = trim((string) ($linea['numero_corto'] ?? ''));
        if ($numero === '' && $doc !== null) {
            $numero = trim((string) ($doc['numero_factura_asistente'] ?? ''));
        }
        if ($numero === '') {
            return trim((string) ($linea['documento'] ?? ''));
        }
        return NumeroFactura::xmlOchoDigitos($numero);
    }

    /** El nombre sin el sufijo de colisión y sin distinguir mayúsculas, como SharePoint. */
    private static function clave($base)
    {
        $base = preg_replace('/_DUP_[0-9A-F]{8}$/i', '', (string) $base);
        return mb_strtoupper((string) $base, 'UTF-8');
    }
}
