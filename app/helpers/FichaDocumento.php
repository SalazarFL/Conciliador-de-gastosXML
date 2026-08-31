<?php
/**
 * La ficha de un comprobante electrónico, lista para enseñarla.
 *
 * El detalle de un documento se pedía desde seis pantallas distintas y las
 * seis hacían lo mismo: abrir OTRA pestaña, en OTRO módulo, para leer doce
 * datos y volver. Ahora ese cuadro se abre encima de donde se está —el visor
 * de app.js—, y para eso hace falta que los doce datos viajen ya resueltos:
 * el número normalizado, las fechas en castellano, los montos con su símbolo
 * y si los archivos siguen en la carpeta compartida.
 *
 * Vive acá y no en el controlador porque lo que se enseña tiene que ser lo
 * mismo para una factura y para una nota de crédito, y porque decidir cómo se
 * lee un dato es justo lo que conviene poder probar sin base de datos.
 *
 * La lista de campos se arma en PHP, no en el navegador: las etiquetas y su
 * orden son las palabras del sistema, y esas viven de este lado.
 */

require_once __DIR__ . '/EstadoArchivo.php';
require_once __DIR__ . '/NumeroFactura.php';

class FichaDocumento
{
    /** Lo que dice el ERP en `estado_pdf`, dicho en palabras. */
    private const ESTADOS_PDF = [
        'disponible' => 'Disponible',
        'pendiente' => 'Pendiente',
        'no_disponible_historico' => 'No existe (documento histórico)',
    ];

    /**
     * @param array  $fila    Fila de facturas_xml con el proveedor unido
     *                        (Factura::getOneWithProvider).
     * @param string $baseUrl Raíz de la aplicación, para las URL de archivos.
     */
    public static function de(array $fila, $baseUrl = '')
    {
        $id = (int) ($fila['id'] ?? 0);
        $base = rtrim((string) $baseUrl, '/');
        $esNota = strtoupper(trim((string) ($fila['tipo_documento'] ?? 'FE'))) === 'NC';
        $estado = EstadoArchivo::de($fila);
        $moneda = strtoupper(trim((string) ($fila['moneda'] ?? 'CRC'))) ?: 'CRC';
        $numero = NumeroFactura::xmlOchoDigitos((string) ($fila['numero_factura_asistente'] ?? ''));

        return [
            'id' => $id,
            'tipo' => $esNota ? 'NC' : 'FE',
            // El rótulo del cuadro. Una nota de crédito llamada "factura"
            // manda a buscarla al listado equivocado.
            'titulo' => $esNota ? 'Nota de crédito' : 'Factura electrónica',
            'numero' => $numero,
            'proveedor' => trim((string) ($fila['proveedor_nombre'] ?? '')),
            'cedula' => trim((string) ($fila['proveedor_cedula'] ?? '')),
            'moneda' => $moneda,
            'simbolo' => self::simbolo($moneda),
            'total' => self::monto($fila['total'] ?? 0),
            'subtotal' => self::monto($fila['subtotal'] ?? 0),
            'iva' => self::monto($fila['iva'] ?? 0),
            'campos' => self::campos($fila, $numero),
            'archivos' => self::archivos($fila, $estado, $base, $id),
            'estado' => [
                'xml_ok' => (bool) $estado['xml_ok'],
                'pdf_ok' => (bool) $estado['pdf_ok'],
                'perdido' => (bool) $estado['perdido'],
                'recuperable' => (bool) $estado['recuperable'],
                'que_falta' => (string) $estado['que_falta'],
                'nunca_llego' => (string) $estado['nunca_llego'],
                'resumen' => self::resumen($estado),
            ],
            // La pantalla completa sigue existiendo: es la salida para quien
            // quiere el enlace, y el destino si el navegador no puede con el
            // cuadro (ctrl+clic, sin JavaScript).
            'url_detalle' => $base . ($esNota ? '/notas-xml/ver/' : '/facturas/ver/') . $id,
        ];
    }

    /**
     * Los datos del documento, en el orden de las preguntas que se le hacen:
     * cuándo es, cómo se llama en cada sistema y por dónde entró.
     *
     * `mono` marca lo que se compara carácter a carácter contra el ERP, y
     * `copiar` lo que nadie transcribe a mano: la clave son cincuenta dígitos.
     */
    private static function campos(array $fila, $numero)
    {
        $campos = [
            ['etiqueta' => 'Fecha de emisión', 'valor' => self::fecha($fila['fecha_emision'] ?? '')],
            ['etiqueta' => 'Número asistente', 'valor' => $numero, 'mono' => true],
            ['etiqueta' => 'Consecutivo', 'valor' => trim((string) ($fila['consecutivo_completo'] ?? '')), 'mono' => true, 'copiar' => true],
            ['etiqueta' => 'Clave', 'valor' => trim((string) ($fila['clave'] ?? '')), 'mono' => true, 'copiar' => true],
            ['etiqueta' => 'Receptor', 'valor' => trim((string) ($fila['receptor_id'] ?? '')), 'mono' => true],
            ['etiqueta' => 'Estado del PDF', 'valor' => self::estadoPdf($fila['estado_pdf'] ?? '')],
            ['etiqueta' => 'Llegó por correo el', 'valor' => self::fechaHora($fila['fecha_correo'] ?? '')],
            ['etiqueta' => 'Archivada el', 'valor' => self::fechaHora($fila['archivado_en'] ?? '')],
        ];

        foreach ($campos as $i => $campo) {
            $campos[$i] = [
                'etiqueta' => $campo['etiqueta'],
                'valor' => $campo['valor'],
                'mono' => !empty($campo['mono']),
                // Nada que copiar de un campo vacío.
                'copiar' => !empty($campo['copiar']) && $campo['valor'] !== '',
            ];
        }

        return $campos;
    }

    /**
     * Los dos archivos del documento: cómo se llaman, si están y dónde se
     * abren. `url` viene vacía cuando no hay nada que abrir, para que el visor
     * no ofrezca un botón que lleva a un 404.
     */
    private static function archivos(array $fila, array $estado, $base, $id)
    {
        return [
            [
                'tipo' => 'xml',
                'etiqueta' => 'XML',
                'icono' => 'fa-code',
                'nombre' => trim((string) ($fila['archivo_xml'] ?? '')),
                'ok' => (bool) $estado['xml_ok'],
                'url' => $estado['xml_ok'] ? $base . '/documentos/xml/' . $id : '',
            ],
            [
                'tipo' => 'pdf',
                'etiqueta' => 'PDF',
                'icono' => 'fa-file-pdf',
                'nombre' => trim((string) ($fila['archivo_pdf'] ?? '')),
                'ok' => (bool) $estado['pdf_ok'],
                'url' => $estado['pdf_ok'] ? $base . '/documentos/pdf/' . $id : '',
            ],
        ];
    }

    /**
     * La marca de arriba del cuadro, con el mismo vocabulario que los
     * listados: primero lo que se archivó y desapareció —eso desmiente al
     * resto—, después lo que hay y lo que falta.
     */
    private static function resumen(array $estado)
    {
        if (!empty($estado['perdido'])) {
            $falta = trim((string) $estado['que_falta']);
            return [
                'texto' => $falta !== '' ? 'Falta el ' . $falta : 'Archivo perdido',
                'tono' => 'perdido',
                'icono' => 'fa-link-slash',
            ];
        }
        if ($estado['xml_ok'] && $estado['pdf_ok']) {
            return ['texto' => 'XML + PDF', 'tono' => 'ok', 'icono' => 'fa-circle-check'];
        }
        if (!$estado['xml_ok'] && !$estado['pdf_ok']) {
            return ['texto' => 'Sin archivos', 'tono' => 'falta', 'icono' => 'fa-triangle-exclamation'];
        }
        return $estado['xml_ok']
            ? ['texto' => 'Falta el PDF', 'tono' => 'aviso', 'icono' => 'fa-file-pdf']
            : ['texto' => 'Falta el XML', 'tono' => 'aviso', 'icono' => 'fa-file-circle-xmark'];
    }

    private static function simbolo($moneda)
    {
        if ($moneda === 'CRC') {
            return '₡';
        }
        return $moneda === 'USD' ? '$' : '';
    }

    private static function monto($valor)
    {
        return number_format((float) $valor, 2);
    }

    private static function estadoPdf($valor)
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return self::ESTADOS_PDF['pendiente'];
        }
        return self::ESTADOS_PDF[$valor] ?? $valor;
    }

    /** 'YYYY-MM-DD' → 'DD/MM/YYYY'. Lo que no sea una fecha se deja como está. */
    private static function fecha($valor)
    {
        $valor = trim((string) $valor);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $valor, $m)) {
            return $valor;
        }
        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }

    /** Lo mismo con la hora, sin los segundos: nadie los mira. */
    private static function fechaHora($valor)
    {
        $valor = trim((string) $valor);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', $valor, $m)) {
            return self::fecha($valor);
        }
        return $m[3] . '/' . $m[2] . '/' . $m[1] . ' ' . $m[4] . ':' . $m[5];
    }
}
