<?php
/**
 * Qué columnas puede enseñar cada listado.
 *
 * Un listado no enseña todo lo que sabe: la pantalla se llenaría y nadie lee
 * dieciocho columnas de corrido. Pero lo que no cabe en la pantalla de uno le
 * hace falta al otro —la cédula del proveedor, el archivo del que salió el
 * documento, la sucursal—, y hasta ahora la única forma de verlo era abrir la
 * ficha renglón por renglón.
 *
 * Acá está, por listado, TODO lo que se puede enseñar. Cada quien enciende y
 * apaga lo suyo desde el botón de la esquina de la tabla, y lo que quede
 * encendido es lo que baja el Excel: el archivo es lo que se está viendo, no
 * una lista fija que alguien decidió una vez.
 *
 * Cada columna dice:
 *   titulo   Cómo se llama, en la cabecera de la tabla, en la lista del botón
 *            y en el Excel. Uno solo para los tres: son la misma columna.
 *   defecto  Si viene encendida de fábrica. Sin esto puesto, viene encendida.
 *   valor    Qué escribir en el Excel: el nombre del campo de la fila, o una
 *            función que lo arme. La PANTALLA no lo usa —el marcado de cada
 *            celda vive en su vista, con sus insignias y sus enlaces—, esto es
 *            para el archivo, donde una insignia de color no significa nada.
 *   ancho    Ancho de la columna en el Excel, en caracteres.
 *   estilo   Función que devuelve el fondo de la celda en el Excel
 *            (2 = amarillo, 3 = verde), para lo que se lee de un vistazo.
 *
 * Las columnas de acciones y las casillas de marcar NO están aquí: no son
 * datos, no se apagan y no se exportan.
 *
 * IMPORTANTE: el `id` de cada columna es el mismo `data-col` que lleva su
 * <th> y su <td> en la vista. Esa igualdad es la que hace que apagar una
 * casilla esconda la columna correcta y que el Excel baje lo que se ve;
 * ColumnasCatalogoTest la comprueba vista por vista.
 */

/*
 * De dónde salen los nombres en castellano que usan las columnas de más abajo:
 * los estados de la cola, las clases de nota, el número XML de ocho dígitos.
 * Se cargan acá y no se copian: el día que un estado cambie de nombre, cambia
 * en un solo sitio y la pantalla y el Excel lo dicen igual.
 */
require_once __DIR__ . '/../core/Model.php';
require_once __DIR__ . '/../models/Seguimiento.php';
require_once __DIR__ . '/../helpers/AplicacionNotaCredito.php';
require_once __DIR__ . '/../helpers/NumeroFactura.php';

/** Sí/No, que en un Excel se filtra y un icono no. */
$si = static function ($v) { return $v ? 'Sí' : 'No'; };

/** Un importe para Excel: número de verdad, o vacío si no hay. Vacío y cero
 *  no son lo mismo —"no hay saldo que mirar" contra "ya se pagó"—. */
$num = static function ($v) { return ($v === null || $v === '') ? '' : round((float) $v, 2); };

/**
 * El detalle de la bitácora, que se guarda en JSON, escrito en una línea.
 *
 * En pantalla se ve recortado bajo la acción; en el Excel es una columna que
 * se puede leer entera. Los cambios se escriben "campo: antes → después", que
 * es lo que alguien va a buscar cuando pregunte qué se tocó.
 */
$detalleLegible = static function ($detalle) {
    if ($detalle === null || $detalle === '') { return ''; }
    $datos = json_decode((string) $detalle, true);
    if (!is_array($datos)) { return (string) $detalle; }

    $plano = static function ($valor) {
        return is_array($valor) ? (json_encode($valor, JSON_UNESCAPED_UNICODE) ?: '') : (string) $valor;
    };
    $partes = [];
    foreach ($datos as $clave => $valor) {
        if ($clave === 'cambios' && is_array($valor)) {
            foreach ($valor as $campo => $cambio) {
                $partes[] = $campo . ': ' . $plano($cambio['antes'] ?? '')
                    . ' → ' . $plano($cambio['despues'] ?? '');
            }
            continue;
        }
        $partes[] = $clave . ': ' . $plano($valor);
    }
    return mb_substr(implode(' | ', $partes), 0, 900, 'UTF-8');
};

/** Una fecha y hora larga recortada a lo que se lee. */
$cuando = static function ($v) { return $v ? substr((string) $v, 0, 16) : ''; };

// ═══════════════════════════════════════════════════════════════════════════
// COMPROBANTES XML  y  NOTAS XML
// Son la misma tabla mirada por tipo de documento —FE y NC—, así que enseñan
// exactamente lo mismo. Se declaran una vez: el día que a una le entre una
// columna, a la otra también, que es lo que se quería cuando se igualaron.
// ═══════════════════════════════════════════════════════════════════════════

$comprobantes = [
        'fecha'       => ['titulo' => 'Fecha',       'valor' => 'fecha_emision', 'ancho' => 12],
        'proveedor'   => ['titulo' => 'Proveedor',   'valor' => 'proveedor_nombre', 'ancho' => 36],
        'consecutivo' => ['titulo' => 'Consecutivo', 'valor' => 'consecutivo_completo', 'ancho' => 34],
        'numero'      => ['titulo' => 'Número',      'valor' => 'numero_factura_asistente', 'ancho' => 12],
        'moneda'      => ['titulo' => 'Moneda',      'valor' => 'moneda', 'ancho' => 9],
        'subtotal'    => ['titulo' => 'Subtotal',    'valor' => static fn($f) => $num($f['subtotal'] ?? null), 'ancho' => 14],
        'iva'         => ['titulo' => 'IVA',         'valor' => static fn($f) => $num($f['iva'] ?? null), 'ancho' => 12],
        'total'       => ['titulo' => 'Total',       'valor' => static fn($f) => $num($f['total'] ?? null), 'ancho' => 14],
        'saldo'       => [
            'titulo' => 'Saldo',
            'ancho'  => 14,
            // Sin enganche no hay saldo que mirar, y un 0,00 diría que ya se
            // pagó: es la misma distinción que hace la pantalla con palabras.
            'valor'  => static fn($f) => ($f['saldo_erp'] ?? null) === null ? 'sin enganche' : $num($f['saldo_erp']),
        ],
        'pdf'         => ['titulo' => 'PDF',         'valor' => static fn($f) => $si(!empty($f['archivo_pdf_ok'])), 'ancho' => 8],
        'origen'      => ['titulo' => 'Origen',      'valor' => static fn($f) => empty($f['correo_cuenta_id']) ? 'Carga XML' : 'Correo', 'ancho' => 12],

        // ── Lo que la base sabe y la pantalla no enseñaba ──
        // Van todas AL FINAL, y no intercaladas donde encajarían por tema: la
        // vista escribe a mano las de fábrica y añade estas detrás de un tirón
        // (Columnas::cabecerasExtra). Si una se colara en medio del bloque de
        // arriba, saldría dos veces. ColumnasCatalogoTest lo vigila.
        'cedula'       => ['titulo' => 'Cédula',              'valor' => 'proveedor_cedula',   'ancho' => 16, 'defecto' => false],
        'xml'          => ['titulo' => 'XML',                 'valor' => static fn($f) => $si(!empty($f['archivo_xml_ok'])), 'ancho' => 8,  'defecto' => false],
        'documento_erp'=> ['titulo' => 'Documento del ERP',   'valor' => 'documento_erp',       'ancho' => 20, 'defecto' => false],
        'pago'       => ['titulo' => 'Pago de pago',      'valor' => 'pago_nombre',       'ancho' => 22, 'defecto' => false],
        'carga'        => ['titulo' => 'Archivo de carga',    'valor' => 'archivo_importacion', 'ancho' => 30, 'defecto' => false],
        'fecha_carga'  => ['titulo' => 'Fecha de carga',      'valor' => static fn($f) => $cuando($f['fecha_importacion'] ?? null), 'ancho' => 17, 'defecto' => false],
        'archivo_xml'  => ['titulo' => 'Nombre del XML',      'valor' => 'archivo_xml',         'ancho' => 34, 'defecto' => false],
        'registrado'   => ['titulo' => 'Registrado en',       'valor' => static fn($f) => $cuando($f['creado_en'] ?? null), 'ancho' => 17, 'defecto' => false],
];

return [

'facturas' => [
    'nombre'   => 'Comprobantes XML',
    'archivo'  => 'comprobantes_xml',
    'columnas' => $comprobantes,
],

'notasxml' => [
    'nombre'   => 'Notas de crédito XML',
    'archivo'  => 'notas_xml',
    'columnas' => $comprobantes,
],

// ═══════════════════════════════════════════════════════════════════════════
// COLA DE SEGUIMIENTO — modo Sistema
// Su exportación bajaba veintidós columnas mientras la pantalla enseñaba seis:
// las veintidós están acá, y ahora se pueden VER, no solo bajar.
// ═══════════════════════════════════════════════════════════════════════════

'seguimiento' => [
    'nombre'  => 'Cola de seguimiento',
    'archivo' => 'seguimiento',
    'columnas' => [
        'documento' => ['titulo' => 'Documento', 'valor' => 'documento', 'ancho' => 18],
        'proveedor' => ['titulo' => 'Proveedor', 'valor' => 'proveedor', 'ancho' => 36],
        'monto'     => ['titulo' => 'Monto',     'valor' => static fn($f) => $num($f['monto'] ?? null), 'ancho' => 14],
        'saldo'     => [
            'titulo' => 'Saldo',
            'ancho'  => 14,
            'valor'  => static fn($f) => ($f['saldo'] ?? null) === null ? '' : $num($f['saldo']),
        ],
        'respaldo'  => [
            'titulo' => 'Respaldo',
            'ancho'  => 14,
            // En la pantalla son dos chips; en el Excel, una frase que se
            // puede filtrar. Un icono no se filtra.
            'valor'  => static function ($f) {
                $x = !empty($f['xml_ok']) ? 'XML' : '';
                $p = !empty($f['pdf_ok']) ? 'PDF' : (!empty($f['pdf_historico']) ? 'PDF histórico' : '');
                $tiene = array_filter([$x, $p]);
                return $tiene ? implode(' + ', $tiene) : 'Sin respaldo';
            },
        ],

        // ── Lo que la base sabe y la pantalla no enseñaba ──
        'origen'      => ['titulo' => 'Tipo',            'ancho' => 16, 'defecto' => false,
                          'valor' => static fn($f) => ($f['origen'] ?? '') === 'nota_credito' ? 'Nota de crédito' : 'Factura'],
        'clase'       => ['titulo' => 'Clase de nota',   'valor' => 'clase', 'ancho' => 18, 'defecto' => false],
        'fecha'       => ['titulo' => 'Fecha',           'valor' => 'fecha', 'ancho' => 12, 'defecto' => false],
        'moneda'      => ['titulo' => 'Moneda',          'valor' => 'moneda', 'ancho' => 9,  'defecto' => false],
        'cedula'      => ['titulo' => 'Cédula',          'valor' => 'proveedor_cedula', 'ancho' => 16, 'defecto' => false],
        'codigo'      => ['titulo' => 'Código proveedor','valor' => 'proveedor_codigo', 'ancho' => 15, 'defecto' => false],
        'sucursal'    => ['titulo' => 'Sucursal',        'valor' => 'sucursal', 'ancho' => 22, 'defecto' => false],
        'consecutivo' => ['titulo' => 'Consecutivo XML', 'valor' => 'consecutivo', 'ancho' => 34, 'defecto' => false],
        'diferencia'  => ['titulo' => 'Diferencia',      'ancho' => 14, 'defecto' => false,
                          'valor' => static fn($f) => ($f['diferencia'] ?? null) === null ? '' : $num($f['diferencia'])],
        'falta'       => ['titulo' => 'Qué falta',       'ancho' => 20, 'defecto' => false,
                          'valor' => static fn($f) => Seguimiento::TAREAS[$f['tarea'] ?? ''] ?? ($f['tarea'] ?? '')],
        'estado'      => ['titulo' => 'Estado',          'ancho' => 14, 'defecto' => false,
                          'valor' => static fn($f) => Seguimiento::ESTADOS[$f['seguimiento_estado'] ?? ''] ?? ($f['seguimiento_estado'] ?? '')],
        'a_mano'      => ['titulo' => 'Puesto a mano',   'ancho' => 14, 'defecto' => false,
                          'valor' => static fn($f) => !empty($f['estado_a_mano']) ? 'Sí' : ''],
        'tocaria'     => ['titulo' => 'Le tocaría',      'ancho' => 14, 'defecto' => false,
                          // Solo cuando la marca a mano y el cálculo no coinciden:
                          // en el resto repetir el estado no dice nada.
                          'valor' => static fn($f) => !empty($f['desajustada'])
                              ? (Seguimiento::ESTADOS[$f['estado_calculado'] ?? ''] ?? ($f['estado_calculado'] ?? ''))
                              : ''],
        'responsable' => ['titulo' => 'Responsable',     'valor' => 'responsable', 'ancho' => 20, 'defecto' => false],
        'recordar'    => ['titulo' => 'Recordatorio',    'valor' => static fn($f) => $cuando($f['recordar_en'] ?? null), 'ancho' => 17, 'defecto' => false],
        'motivo'      => ['titulo' => 'Motivo',          'valor' => 'motivo', 'ancho' => 34, 'defecto' => false],
        'contexto'    => ['titulo' => 'Listado',         'valor' => 'contexto', 'ancho' => 28, 'defecto' => false],
        'movimiento'  => ['titulo' => 'Último movimiento','valor' => static fn($f) => $cuando($f['seguimiento_actualizado_en'] ?? null), 'ancho' => 17, 'defecto' => false],
        'nota_juego'  => [
            'titulo' => 'Nota de crédito en juego',
            'ancho'  => 34,
            'defecto' => false,
            /*
             * Las dos caras de lo mismo: una nota dice contra qué factura se
             * descuenta; una factura, cuánta nota tiene esperando. En pantalla
             * son dos insignias dentro de la celda del documento; acá, una
             * frase, que es lo que se puede leer en una columna y filtrar en un
             * Excel.
             */
            'valor' => static function ($f) {
                if (($f['origen'] ?? '') === 'nota_credito') {
                    $estado = (string) ($f['aplicacion_estado'] ?? '');
                    if ($estado === '' || $estado === AplicacionNotaCredito::NO_APLICA) { return ''; }
                    $texto = AplicacionNotaCredito::etiqueta($estado);
                    return !empty($f['aplicacion_factura_doc'])
                        ? $texto . ' (factura ' . $f['aplicacion_factura_doc'] . ')'
                        : $texto;
                }
                $cuantas = (int) ($f['notas_vivas'] ?? 0);
                return $cuantas > 0
                    ? sprintf('%d nota(s) sin aplicar por %s', $cuantas,
                        number_format((float) ($f['notas_vivas_saldo'] ?? 0), 2, '.', ''))
                    : '';
            },
        ],
    ],
],

// ═══════════════════════════════════════════════════════════════════════════
// COLA DE SEGUIMIENTO — modo Correo
// No comparte columnas con el de arriba porque la mitad de aquellas no aplica
// —saldo propio, diferencia, clase— y bajarlas en blanco haría creer que el
// dato existe y salió vacío. Lo que sí trae y el otro no es lo que este modo
// viene a contestar: contra qué registro del ERP está enganchado, y cómo llegó.
// ═══════════════════════════════════════════════════════════════════════════

'seguimiento_correo' => [
    'nombre'  => 'Cola de seguimiento (correo)',
    'archivo' => 'seguimiento_correo',
    'columnas' => [
        'documento' => ['titulo' => 'Documento', 'valor' => 'documento', 'ancho' => 18],
        'proveedor' => ['titulo' => 'Proveedor', 'valor' => 'proveedor', 'ancho' => 36],
        'monto'     => ['titulo' => 'Monto',     'valor' => static fn($f) => $num($f['monto'] ?? null), 'ancho' => 14],
        'saldo'     => [
            'titulo' => 'Saldo',
            'ancho'  => 14,
            'valor'  => static fn($f) => ($f['saldo'] ?? null) === null ? '' : $num($f['saldo']),
        ],
        'respaldo'  => [
            'titulo' => 'Respaldo',
            'ancho'  => 14,
            'valor'  => static function ($f) {
                $x = !empty($f['xml_ok']) ? 'XML' : '';
                $p = !empty($f['pdf_ok']) ? 'PDF' : (!empty($f['pdf_historico']) ? 'PDF histórico' : '');
                $tiene = array_filter([$x, $p]);
                return $tiene ? implode(' + ', $tiene) : 'Sin respaldo';
            },
        ],

        // ── Lo que la base sabe y la pantalla no enseñaba ──
        'tipo'        => ['titulo' => 'Tipo',                  'ancho' => 16, 'defecto' => false,
                          'valor' => static fn($f) => ($f['origen'] ?? '') === 'xml_nota' ? 'Nota de crédito' : 'Factura'],
        'consecutivo' => ['titulo' => 'Consecutivo',           'valor' => 'consecutivo', 'ancho' => 34, 'defecto' => false],
        'fecha'       => ['titulo' => 'Fecha',                 'valor' => 'fecha', 'ancho' => 12, 'defecto' => false],
        'moneda'      => ['titulo' => 'Moneda',                'valor' => 'moneda', 'ancho' => 9,  'defecto' => false],
        'cedula'      => ['titulo' => 'Cédula',                'valor' => 'proveedor_cedula', 'ancho' => 16, 'defecto' => false],
        'en_sistema'  => ['titulo' => 'En el sistema',         'ancho' => 13, 'defecto' => false,
                          'valor' => static fn($f) => $si(($f['tarea'] ?? '') === 'completo')],
        'sistema_doc' => ['titulo' => 'Documento del sistema', 'valor' => 'sistema_doc', 'ancho' => 20, 'defecto' => false],
        'llegada'     => ['titulo' => 'Cómo llegó',            'ancho' => 13, 'defecto' => false,
                          'valor' => static fn($f) => ($f['llegada'] ?? '') === 'correo' ? 'Correo' : 'Carga XML'],
        'estado'      => ['titulo' => 'Estado',                'ancho' => 14, 'defecto' => false,
                          'valor' => static fn($f) => Seguimiento::ESTADOS_CORREO[$f['seguimiento_estado'] ?? ''] ?? ($f['seguimiento_estado'] ?? '')],
        'a_mano'      => ['titulo' => 'Puesto a mano',         'ancho' => 14, 'defecto' => false,
                          'valor' => static fn($f) => !empty($f['estado_a_mano']) ? 'Sí' : ''],
        'responsable' => ['titulo' => 'Responsable',           'valor' => 'responsable', 'ancho' => 20, 'defecto' => false],
        'recordar'    => ['titulo' => 'Recordatorio',          'valor' => static fn($f) => $cuando($f['recordar_en'] ?? null), 'ancho' => 17, 'defecto' => false],
        'motivo'      => ['titulo' => 'Motivo',                'valor' => 'motivo', 'ancho' => 34, 'defecto' => false],
        'contexto'    => ['titulo' => 'Importación',           'valor' => 'contexto', 'ancho' => 28, 'defecto' => false],
        'movimiento'  => ['titulo' => 'Último movimiento',     'valor' => static fn($f) => $cuando($f['seguimiento_actualizado_en'] ?? null), 'ancho' => 17, 'defecto' => false],
    ],
],

// ═══════════════════════════════════════════════════════════════════════════
// PAGO
// ═══════════════════════════════════════════════════════════════════════════

'porpagar' => [
    'nombre'  => 'Pago',
    'archivo' => 'por_pagar',
    'columnas' => [
        'estado'     => [
            'titulo' => 'Estado',
            'ancho'  => 18,
            'valor'  => static function ($l) {
                $e = (string) ($l['estado'] ?? '');
                return ['respaldada' => 'Respaldada', 'con_diferencia' => 'Con diferencia',
                        'sin_respaldo' => 'Sin respaldo'][$e] ?? $e;
            },
            // Verde o amarillo, que es lo que se mira al abrir el archivo.
            'estilo' => static fn($l) => ($l['estado'] ?? '') === 'respaldada' ? 3 : 2,
        ],
        'fecha'      => ['titulo' => 'Fecha',        'valor' => 'fecha_emision', 'ancho' => 13],
        'documento'  => ['titulo' => 'Documento del sistema', 'valor' => 'documento', 'ancho' => 24],
        'proveedor'  => ['titulo' => 'Proveedor',    'valor' => 'proveedor_nombre', 'ancho' => 38],
        'monto'      => ['titulo' => 'Monto del sistema', 'valor' => static fn($l) => $num($l['monto'] ?? null), 'ancho' => 18],
        'saldo'      => ['titulo' => 'Saldo',        'valor' => static fn($l) => $num($l['saldo_pago'] ?? null), 'ancho' => 16],
        'xml'        => ['titulo' => 'Factura XML',  'ancho' => 22,
                         'valor' => static fn($l) => ($l['xml_numero'] ?? null) !== null
                             ? NumeroFactura::xmlOchoDigitos((string) $l['xml_numero']) : ''],
        'diferencia' => ['titulo' => 'Diferencia',   'ancho' => 16,
                         'valor' => static fn($l) => ($l['diferencia'] ?? null) === null ? '' : $num($l['diferencia'])],

        // ── Lo que la base sabe y la pantalla no enseñaba ──
        // La sucursal ya bajaba en el Excel pero en pantalla iba de letra
        // chica bajo el proveedor: acá es columna, y se puede ordenar.
        'sucursal'   => ['titulo' => 'Sucursal',     'valor' => 'sucursal', 'ancho' => 24, 'defecto' => false],
        'total_xml'  => ['titulo' => 'Total XML',    'ancho' => 16, 'defecto' => false,
                         'valor' => static fn($l) => ($l['xml_total'] ?? null) === null ? '' : $num($l['xml_total'])],
        'vence'      => ['titulo' => 'Vence',        'valor' => 'fecha_vence', 'ancho' => 13, 'defecto' => false],
        'moneda'     => ['titulo' => 'Moneda',       'valor' => 'moneda', 'ancho' => 9, 'defecto' => false],
        'codigo'     => ['titulo' => 'Código proveedor', 'valor' => 'proveedor_codigo', 'ancho' => 15, 'defecto' => false],
    ],
],

// ═══════════════════════════════════════════════════════════════════════════
// FACTURAS DEL ERP
// ═══════════════════════════════════════════════════════════════════════════

'facturaserp' => [
    'nombre'  => 'Facturas del sistema',
    'archivo' => 'facturas_erp',
    'columnas' => [
        'documento' => ['titulo' => 'Documento', 'valor' => 'documento', 'ancho' => 24],
        'proveedor' => ['titulo' => 'Proveedor', 'valor' => 'proveedor_nombre', 'ancho' => 38],
        'fecha'     => ['titulo' => 'Fecha',     'valor' => 'fecha_emision', 'ancho' => 13],
        'monto'     => ['titulo' => 'Monto',     'valor' => static fn($f) => $num($f['monto'] ?? null), 'ancho' => 16],
        'saldo'     => ['titulo' => 'Saldo',     'valor' => static fn($f) => $num($f['saldo'] ?? null), 'ancho' => 16],

        // ── Lo que la base sabe y la pantalla no enseñaba ──
        'codigo'    => ['titulo' => 'Código proveedor', 'valor' => 'proveedor_codigo', 'ancho' => 15, 'defecto' => false],
        'sucursal'  => ['titulo' => 'Sucursal',  'valor' => 'sucursal', 'ancho' => 24, 'defecto' => false],
        'vence'     => ['titulo' => 'Vence',     'valor' => 'fecha_vence', 'ancho' => 13, 'defecto' => false],
        'origen'    => ['titulo' => 'Compra',    'valor' => 'origen', 'ancho' => 14, 'defecto' => false],
        'moneda'    => ['titulo' => 'Moneda',    'valor' => 'moneda', 'ancho' => 9, 'defecto' => false],
        'estado'    => ['titulo' => 'Estado',    'ancho' => 22, 'defecto' => false,
                        'valor' => static fn($f) => ($f['estado'] ?? 'pendiente') === 'asignada_pago'
                            ? 'Asignada a un pago' : 'Pendiente'],
        'pago'    => ['titulo' => 'Pago',    'valor' => 'pago_nombre', 'ancho' => 22, 'defecto' => false],
    ],
],

// ═══════════════════════════════════════════════════════════════════════════
// DEVOLUCIONES
// ═══════════════════════════════════════════════════════════════════════════

'devoluciones' => [
    'nombre'  => 'Devoluciones',
    'archivo' => 'devoluciones',
    'columnas' => [
        'fecha'     => ['titulo' => 'Fecha',   'valor' => 'fecha', 'ancho' => 13],
        'tipo'      => ['titulo' => 'Tipo',    'valor' => 'tipo', 'ancho' => 16],
        'numero'    => ['titulo' => 'N°',      'valor' => 'numero', 'ancho' => 14],
        'proveedor' => ['titulo' => 'Proveedor', 'ancho' => 36,
                        'valor' => static fn($d) => (string) ($d['proveedor_local'] ?: $d['proveedor_nombre_erp'] ?: '')],
        'factura'   => ['titulo' => 'Factura (boleta)', 'valor' => 'numero_factura', 'ancho' => 20],
        'monto'     => ['titulo' => 'Monto',   'valor' => static fn($d) => $num($d['total'] ?? null), 'ancho' => 16],
        'esperadas' => [
            'titulo' => 'NC esperadas',
            'ancho'  => 22,
            // Las dos cifras que en pantalla van una encima de la otra, en una
            // sola celda: en el Excel dos líneas dentro de una celda no sirven.
            'valor'  => static function ($d) {
                $partes = [];
                if ((float) ($d['nc_esperada_cantidad'] ?? 0) > 0) { $partes[] = 'Cant: ' . number_format((float) $d['nc_esperada_cantidad'], 2); }
                if ((float) ($d['nc_esperada_costo'] ?? 0) > 0) { $partes[] = 'Costo: ' . number_format((float) $d['nc_esperada_costo'], 2); }
                return implode(' · ', $partes);
            },
        ],
        'vinculos'  => [
            'titulo' => 'Vínculos',
            'ancho'  => 16,
            'valor'  => static function ($d) {
                $c = (int) ($d['matches_confirmados'] ?? 0);
                $s = (int) ($d['matches_sugeridos'] ?? 0);
                $partes = [];
                if ($c > 0) { $partes[] = $c . ' confirmados'; }
                if ($s > 0) { $partes[] = $s . ' sugeridos'; }
                return implode(' · ', $partes);
            },
        ],
        'estado'    => ['titulo' => 'Estado',  'valor' => 'estado', 'ancho' => 16],

        // ── Lo que la base sabe y la pantalla no enseñaba ──
        // El reporte del ERP trae bastante más de lo que cabía en la tabla:
        // dónde se hizo, quién lo hizo, qué se anotó y cuánto se devolvió.
        'codigo'      => ['titulo' => 'Código proveedor', 'valor' => 'proveedor_codigo_erp', 'ancho' => 15, 'defecto' => false],
        'sucursal'    => ['titulo' => 'Sucursal',      'valor' => 'sucursal', 'ancho' => 24, 'defecto' => false],
        'bodega'      => ['titulo' => 'Bodega',        'valor' => 'bodega', 'ancho' => 20, 'defecto' => false],
        'estado_erp'  => ['titulo' => 'Estado en el ERP', 'valor' => 'estado_erp', 'ancho' => 18, 'defecto' => false],
        'usuario_erp' => ['titulo' => 'Hecha por',     'valor' => 'usuario_erp', 'ancho' => 20, 'defecto' => false],
        'cantidad'    => ['titulo' => 'Cantidad',      'ancho' => 12, 'defecto' => false,
                          'valor' => static fn($d) => ($d['cantidad_total'] ?? null) === null ? '' : round((float) $d['cantidad_total'], 3)],
        'observaciones' => ['titulo' => 'Observaciones', 'valor' => 'observaciones', 'ancho' => 40, 'defecto' => false],
        'archivo'     => ['titulo' => 'PDF del reporte', 'valor' => 'archivo_pdf', 'ancho' => 30, 'defecto' => false],
        'importado'   => ['titulo' => 'Importado en', 'valor' => static fn($d) => $cuando($d['creado_en'] ?? null), 'ancho' => 17, 'defecto' => false],
        'verificado'  => ['titulo' => 'Verificado en', 'valor' => static fn($d) => $cuando($d['verificado_en'] ?? null), 'ancho' => 17, 'defecto' => false],
    ],
],

// ═══════════════════════════════════════════════════════════════════════════
// NOTAS DE CRÉDITO (el reporte cargado, línea por línea)
// ═══════════════════════════════════════════════════════════════════════════

'notascredito' => [
    'nombre'  => 'Notas de crédito',
    'archivo' => 'notas_credito',
    'columnas' => [
        // El orden es el de la tabla: el estado manda, porque es lo que se
        // viene a mirar —qué nota no cuadra con su XML—.
        'estado'    => [
            'titulo' => 'Estado',
            'ancho'  => 18,
            'valor'  => static function ($l) {
                $e = (string) ($l['estado'] ?? '');
                return ['coincide' => 'Coincide', 'con_diferencia' => 'Con diferencia',
                        'sin_respaldo' => 'Sin respaldo'][$e] ?? $e;
            },
            'estilo' => static fn($l) => ($l['estado'] ?? '') === 'coincide' ? 3 : 2,
        ],
        'proveedor' => ['titulo' => 'Proveedor', 'valor' => 'proveedor_nombre', 'ancho' => 36],
        'documento' => ['titulo' => 'Documento', 'valor' => 'documento', 'ancho' => 22],
        'monto'     => ['titulo' => 'Monto',     'valor' => static fn($l) => $num($l['monto'] ?? null), 'ancho' => 16],
        'saldo'     => ['titulo' => 'Saldo',     'valor' => static fn($l) => $num($l['saldo'] ?? null), 'ancho' => 16],
        'nc_xml'    => [
            'titulo' => 'NC XML',
            'ancho'  => 20,
            'valor'  => static fn($l) => (string) ($l['xml_numero'] ?: ($l['xml_consecutivo'] ?? '')),
        ],

        // ── Lo que la base sabe y la pantalla no enseñaba ──
        // Siete de estas se habían retirado de la tabla por estar vacías o
        // repetidas en la mayoría de los renglones. Estaban de más COMO
        // COLUMNA FIJA; en la minoría en que dicen algo hacen falta, y ahora
        // se encienden para esa vuelta y se vuelven a apagar.
        'fecha'       => ['titulo' => 'Fecha',              'valor' => 'fecha', 'ancho' => 13, 'defecto' => false],
        'clase'       => ['titulo' => 'Clase',              'valor' => 'clase', 'ancho' => 14, 'defecto' => false],
        'codigo'      => ['titulo' => 'Código proveedor',   'valor' => 'proveedor_codigo', 'ancho' => 15, 'defecto' => false],
        'sucursal'    => ['titulo' => 'Sucursal',           'valor' => 'sucursal', 'ancho' => 24, 'defecto' => false],
        'moneda'      => ['titulo' => 'Moneda',             'valor' => 'moneda', 'ancho' => 9, 'defecto' => false],
        'nc_proveedor'=> ['titulo' => 'NC del proveedor',   'valor' => 'nc_proveedor', 'ancho' => 20, 'defecto' => false],
        'fecha_nc'    => ['titulo' => 'Fecha de la NC del proveedor', 'valor' => 'fecha_nc_proveedor', 'ancho' => 17, 'defecto' => false],
        'total_xml'   => ['titulo' => 'Total XML',          'ancho' => 16, 'defecto' => false,
                          'valor' => static fn($l) => ($l['xml_total'] ?? null) === null ? '' : $num($l['xml_total'])],
        'diferencia'  => ['titulo' => 'Diferencia',         'ancho' => 16, 'defecto' => false,
                          'valor' => static fn($l) => ($l['diferencia'] ?? null) === null || $l['diferencia'] === ''
                              ? '' : $num($l['diferencia'])],
        'xml_proveedor' => ['titulo' => 'Proveedor del XML', 'valor' => 'xml_proveedor', 'ancho' => 32, 'defecto' => false],
        'aplicacion'  => ['titulo' => 'Aplicación',         'ancho' => 20, 'defecto' => false,
                          'valor' => static function ($l) {
                              $apl = (string) ($l['estado_aplicacion'] ?? '');
                              return $apl === '' || $apl === AplicacionNotaCredito::NO_APLICA
                                  ? '' : AplicacionNotaCredito::etiqueta($apl);
                          }],
        'factura_erp' => ['titulo' => 'Factura del ERP',    'valor' => 'factura_erp_documento', 'ancho' => 22, 'defecto' => false],
        'fila'        => ['titulo' => 'Fila del reporte',   'valor' => 'fila_origen', 'ancho' => 14, 'defecto' => false],
        'motivo'      => ['titulo' => 'Por qué quedó así',  'valor' => 'motivo_match', 'ancho' => 40, 'defecto' => false],
    ],
],

// ═══════════════════════════════════════════════════════════════════════════
// BITÁCORA
// ═══════════════════════════════════════════════════════════════════════════

'bitacora' => [
    'nombre'  => 'Bitácora',
    'archivo' => 'bitacora',
    'columnas' => [
        'cuando'    => ['titulo' => 'Cuándo',    'valor' => static fn($f) => $cuando($f['fecha'] ?? null), 'ancho' => 18],
        'quien'     => ['titulo' => 'Quién',     'valor' => 'usuario_nombre', 'ancho' => 24],
        'modulo'    => ['titulo' => 'Módulo',    'valor' => 'modulo', 'ancho' => 16],
        'accion'    => ['titulo' => 'Qué hizo',  'valor' => 'accion', 'ancho' => 42],
        'resultado' => ['titulo' => 'Resultado', 'valor' => 'resultado', 'ancho' => 12],

        // ── Lo que la base sabe y la pantalla no enseñaba ──
        // El detalle sí se ve en pantalla, pero recortado y bajo la acción:
        // como columna propia se puede leer entero y filtrar.
        'detalle'   => ['titulo' => 'Detalle', 'ancho' => 60, 'defecto' => false,
                        'valor' => static fn($f) => $detalleLegible($f['detalle'] ?? null)],
        // De qué fuente sale el renglón: la bitácora propia, o el historial
        // que cada módulo llevaba antes de que la bitácora existiera.
        'origen'    => ['titulo' => 'Origen',  'valor' => 'origen', 'ancho' => 20, 'defecto' => false],
    ],
],

];
