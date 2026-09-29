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
 *   desde    Solo en las de fábrica que se estrenaron después que el listado
 *            ('AAAA-MM-DD', o 'AAAA-MM-DD HH:MM' si hace falta afinar): a
 *            quien ya tenía su elección de columnas guardada de antes se le
 *            enciende igual. Sin esto, una columna nueva no la vería nunca
 *            quien ya acomodó las suyas. Se compara como texto con la fecha
 *            de la elección, así que las dos formas conviven: un día a secas
 *            queda antes que cualquier hora de ese mismo día.
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

/** Las notas de crédito aplicables de una factura, en una frase para el Excel.
 *  Un renglón que es una nota —en Seguimiento— no lleva nada. */
$notasAplicables = static function ($f) {
    $cuantas = (int) ($f['notas_vivas'] ?? 0);
    return $cuantas > 0
        ? sprintf('%d nota(s) por %s', $cuantas, number_format((float) ($f['notas_vivas_saldo'] ?? 0), 2, '.', ''))
        : '';
};

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
        // Un dato por celda. El tipo, la fecha, la clase y el pago iban
        // amontonados bajo el número del documento; la sucursal, bajo el
        // proveedor; la diferencia, bajo el monto; la aplicación de la nota,
        // en una insignia junto a la fecha. Cada uno en su columna se puede
        // mover, apagar y leer de arriba abajo.
        'origen'      => ['titulo' => 'Tipo',           'ancho' => 16, 'desde' => '2026-09-27 18:47',
                          'valor' => static fn($f) => ($f['origen'] ?? '') === 'nota_credito' ? 'Nota de crédito' : 'Factura'],
        'documento' => ['titulo' => 'Documento', 'valor' => 'documento', 'ancho' => 18],
        'fecha'       => ['titulo' => 'Fecha',           'valor' => 'fecha', 'ancho' => 12, 'desde' => '2026-09-27 18:47'],
        'clase'       => ['titulo' => 'Clase de nota',   'valor' => 'clase', 'ancho' => 18, 'desde' => '2026-09-27 18:47'],
        'contexto'    => ['titulo' => 'Listado',         'valor' => 'contexto', 'ancho' => 28, 'desde' => '2026-09-27 18:47'],
        'proveedor' => ['titulo' => 'Proveedor', 'valor' => 'proveedor', 'ancho' => 36],
        'sucursal'    => ['titulo' => 'Sucursal',        'valor' => 'sucursal', 'ancho' => 22, 'desde' => '2026-09-27 18:47'],
        'monto'     => ['titulo' => 'Monto sistema', 'valor' => static fn($f) => $num($f['monto'] ?? null), 'ancho' => 14],
        // El total del comprobante, entre los dos: con el monto del sistema al
        // lado se ve cuál de los dos está mal, que la diferencia sola no dice.
        'monto_xml'   => ['titulo' => 'Monto XML',       'ancho' => 14, 'desde' => '2026-09-28 21:04',
                          'valor' => static fn($f) => $num($f['monto_xml'] ?? null)],
        'diferencia'  => ['titulo' => 'Diferencia',      'ancho' => 14, 'desde' => '2026-09-27 18:47',
                          'valor' => static fn($f) => ($f['diferencia'] ?? null) === null ? '' : $num($f['diferencia'])],
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
        // En pantalla es un botón que abre las notas; en el Excel, cuántas
        // y por cuánto. Solo las facturas la llevan.
        // Llegó después de que existiera el botón de columnas: quien ya había
        // guardado su elección no la tenía y no la veía nunca.
        'notas_aplicables' => ['titulo' => 'Notas aplicables', 'ancho' => 24, 'valor' => $notasAplicables,
                               'desde' => '2026-09-27 18:47'],
        // Y su contracara, que solo llevan las notas: contra qué factura se
        // descuenta. Lo que la factura tiene esperando ya lo dice la columna
        // de al lado; repetirlo acá sería decirlo dos veces.
        'nota_juego'  => [
            'titulo' => 'Aplicación',
            'ancho'  => 34,
            'desde'  => '2026-09-27',
            'valor'  => static function ($f) {
                if (($f['origen'] ?? '') !== 'nota_credito') { return ''; }
                $estado = (string) ($f['aplicacion_estado'] ?? '');
                if ($estado === '' || $estado === AplicacionNotaCredito::NO_APLICA) { return ''; }
                $texto = AplicacionNotaCredito::etiqueta($estado);
                return !empty($f['aplicacion_factura_doc'])
                    ? $texto . ' (factura ' . $f['aplicacion_factura_doc'] . ')'
                    : $texto;
            },
        ],

        // ── Lo que la base sabe y la pantalla no enseñaba ──
        // Y lo que alguien pone encima del renglón —responsable, recordatorio,
        // anotaciones, la marca a mano, el motivo—, que iba en una tercera
        // línea bajo el documento. Está vacío en casi todos los renglones:
        // viene apagado, y se enciende desde la esquina.
        'moneda'      => ['titulo' => 'Moneda',          'valor' => 'moneda', 'ancho' => 9,  'defecto' => false],
        // El Monto XML va en la moneda del comprobante; en pantalla lleva el
        // tipo de cambio debajo, y en el Excel —donde un número suelto en
        // dólares se leería como colones— va en su columna. Apagada: en
        // colones queda vacía, que es casi siempre.
        'moneda_xml'  => ['titulo' => 'Moneda XML',      'ancho' => 9,  'defecto' => false,
                          'valor' => static fn($f) => ($f['monto_xml'] ?? null) === null ? '' : (strtoupper((string) ($f['moneda_xml'] ?? '')) ?: 'CRC')],
        'tipo_cambio' => ['titulo' => 'Tipo de cambio XML', 'ancho' => 12, 'defecto' => false,
                          'valor' => static fn($f) => (float) ($f['tipo_cambio_xml'] ?? 0) > 0
                              ? round((float) $f['tipo_cambio_xml'], 5) : ''],
        'cedula'      => ['titulo' => 'Cédula',          'valor' => 'proveedor_cedula', 'ancho' => 16, 'defecto' => false],
        'codigo'      => ['titulo' => 'Código proveedor','valor' => 'proveedor_codigo', 'ancho' => 15, 'defecto' => false],
        'consecutivo' => ['titulo' => 'Consecutivo XML', 'valor' => 'consecutivo', 'ancho' => 34, 'defecto' => false],
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
        'anotaciones' => ['titulo' => 'Anotaciones',     'ancho' => 12, 'defecto' => false,
                          'valor' => static fn($f) => (int) ($f['anotaciones'] ?? 0) > 0 ? (int) $f['anotaciones'] : ''],
        'motivo'      => ['titulo' => 'Motivo',          'valor' => 'motivo', 'ancho' => 34, 'defecto' => false],
        'movimiento'  => ['titulo' => 'Último movimiento','valor' => static fn($f) => $cuando($f['seguimiento_actualizado_en'] ?? null), 'ancho' => 17, 'defecto' => false],
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
        // Lo mismo que el modo Sistema: tipo, fecha, cómo llegó y el enganche
        // iban amontonados bajo el número; ahora cada uno es su columna.
        'tipo'        => ['titulo' => 'Tipo',                  'ancho' => 16, 'desde' => '2026-09-27 18:47',
                          'valor' => static fn($f) => ($f['origen'] ?? '') === 'xml_nota' ? 'Nota de crédito' : 'Factura'],
        'documento' => ['titulo' => 'Documento', 'valor' => 'documento', 'ancho' => 18],
        'fecha'       => ['titulo' => 'Fecha',                 'valor' => 'fecha', 'ancho' => 12, 'desde' => '2026-09-27 18:47'],
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
        'llegada'     => ['titulo' => 'Cómo llegó',            'ancho' => 13, 'desde' => '2026-09-27 18:47',
                          'valor' => static fn($f) => ($f['llegada'] ?? '') === 'correo' ? 'Correo' : 'Carga XML'],
        // La respuesta que este modo viene a dar: contra qué registro del
        // sistema está enganchado, o que no lo está.
        'sistema_doc' => ['titulo' => 'Documento del sistema', 'ancho' => 22, 'desde' => '2026-09-27 18:47',
                          'valor' => static function ($f) {
                              if (($f['tarea'] ?? '') !== 'completo') { return 'Sin registro'; }
                              $doc = trim((string) ($f['sistema_doc'] ?? ''));
                              return $doc !== '' ? $doc : 'En el sistema (sin número)';
                          }],

        // ── Lo que la base sabe y la pantalla no enseñaba ──
        'consecutivo' => ['titulo' => 'Consecutivo',           'valor' => 'consecutivo', 'ancho' => 34, 'defecto' => false],
        'moneda'      => ['titulo' => 'Moneda',                'valor' => 'moneda', 'ancho' => 9,  'defecto' => false],
        'cedula'      => ['titulo' => 'Cédula',                'valor' => 'proveedor_cedula', 'ancho' => 16, 'defecto' => false],
        'en_sistema'  => ['titulo' => 'En el sistema',         'ancho' => 13, 'defecto' => false,
                          'valor' => static fn($f) => $si(($f['tarea'] ?? '') === 'completo')],
        'estado'      => ['titulo' => 'Estado',                'ancho' => 14, 'defecto' => false,
                          'valor' => static fn($f) => Seguimiento::ESTADOS_CORREO[$f['seguimiento_estado'] ?? ''] ?? ($f['seguimiento_estado'] ?? '')],
        'a_mano'      => ['titulo' => 'Puesto a mano',         'ancho' => 14, 'defecto' => false,
                          'valor' => static fn($f) => !empty($f['estado_a_mano']) ? 'Sí' : ''],
        'tocaria'     => ['titulo' => 'Le tocaría',            'ancho' => 14, 'defecto' => false,
                          'valor' => static fn($f) => !empty($f['desajustada'])
                              ? (Seguimiento::ESTADOS_CORREO[$f['estado_calculado'] ?? ''] ?? ($f['estado_calculado'] ?? ''))
                              : ''],
        'responsable' => ['titulo' => 'Responsable',           'valor' => 'responsable', 'ancho' => 20, 'defecto' => false],
        'recordar'    => ['titulo' => 'Recordatorio',          'valor' => static fn($f) => $cuando($f['recordar_en'] ?? null), 'ancho' => 17, 'defecto' => false],
        'anotaciones' => ['titulo' => 'Anotaciones',           'ancho' => 12, 'defecto' => false,
                          'valor' => static fn($f) => (int) ($f['anotaciones'] ?? 0) > 0 ? (int) $f['anotaciones'] : ''],
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
        // Iba de letra chica bajo el proveedor: un dato por celda, y así se
        // puede mover, apagar o poner al lado de lo que uno quiera.
        'sucursal'   => ['titulo' => 'Sucursal',     'valor' => 'sucursal', 'ancho' => 24, 'desde' => '2026-09-27 18:47'],
        'monto'      => ['titulo' => 'Monto del sistema', 'valor' => static fn($l) => $num($l['monto'] ?? null), 'ancho' => 18],
        'saldo'      => ['titulo' => 'Saldo',        'valor' => static fn($l) => $num($l['saldo_pago'] ?? null), 'ancho' => 16],
        'xml'        => ['titulo' => 'Factura XML',  'ancho' => 22,
                         'valor' => static fn($l) => ($l['xml_numero'] ?? null) !== null
                             ? NumeroFactura::xmlOchoDigitos((string) $l['xml_numero']) : ''],
        // Iba pegado al número de la factura XML, en la misma celda.
        'total_xml'  => ['titulo' => 'Total XML',    'ancho' => 16, 'desde' => '2026-09-27 18:47',
                         'valor' => static fn($l) => ($l['xml_total'] ?? null) === null ? '' : $num($l['xml_total'])],
        'diferencia' => ['titulo' => 'Diferencia',   'ancho' => 16,
                         'valor' => static fn($l) => ($l['diferencia'] ?? null) === null ? '' : $num($l['diferencia'])],
        // Llegó después de que existiera el botón de columnas: quien ya había
        // guardado su elección no la tenía y no la veía nunca.
        'notas_aplicables' => ['titulo' => 'Notas aplicables', 'ancho' => 24, 'valor' => $notasAplicables,
                               'desde' => '2026-09-27 18:47'],

        // ── Lo que la base sabe y la pantalla no enseñaba ──
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
        // Código y sucursal iban juntos, de letra chica bajo el nombre; el
        // vencimiento, bajo la emisión; el pago, la compra y las notas
        // aplicables, en insignias bajo el documento. Un dato por celda: así
        // cada uno se mueve, se apaga o se pone al lado de lo que se quiera.
        'codigo'    => ['titulo' => 'Código proveedor', 'valor' => 'proveedor_codigo', 'ancho' => 15, 'desde' => '2026-09-27 18:47'],
        'sucursal'  => ['titulo' => 'Sucursal',  'valor' => 'sucursal', 'ancho' => 24, 'desde' => '2026-09-27 18:47'],
        'fecha'     => ['titulo' => 'Fecha',     'valor' => 'fecha_emision', 'ancho' => 13],
        'vence'     => ['titulo' => 'Vence',     'valor' => 'fecha_vence', 'ancho' => 13, 'desde' => '2026-09-27 18:47'],
        'monto'     => ['titulo' => 'Monto',     'valor' => static fn($f) => $num($f['monto'] ?? null), 'ancho' => 16],
        'saldo'     => ['titulo' => 'Saldo',     'valor' => static fn($f) => $num($f['saldo'] ?? null), 'ancho' => 16],
        // FacturasErpController::exportar le pega a cada fila sus notas_vivas.
        'notas_aplicables' => ['titulo' => 'Notas aplicables', 'ancho' => 24, 'valor' => $notasAplicables,
                        'desde' => '2026-09-27 18:47'],
        'pago'      => ['titulo' => 'Pago',      'valor' => 'pago_nombre', 'ancho' => 22, 'desde' => '2026-09-27 18:47'],
        'origen'    => ['titulo' => 'Compra',    'valor' => 'origen', 'ancho' => 14, 'desde' => '2026-09-27 18:47'],

        // ── Lo que la base sabe y la pantalla no enseñaba ──
        'moneda'    => ['titulo' => 'Moneda',    'valor' => 'moneda', 'ancho' => 9, 'defecto' => false],
        'estado'    => ['titulo' => 'Estado',    'ancho' => 22, 'defecto' => false,
                        'valor' => static fn($f) => ($f['estado'] ?? 'pendiente') === 'asignada_pago'
                            ? 'Asignada a un pago' : 'Pendiente'],
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
        // "NC esperadas" y "Vínculos" eran dos cifras cada una, apiladas en
        // una sola celda. Un dato por columna: cada cifra se lee, se compara
        // entre renglones y baja al Excel como número.
        'nc_cantidad' => ['titulo' => 'NC esperada por cantidad', 'ancho' => 18, 'desde' => '2026-09-27 18:47',
                          'valor' => static fn($d) => (float) ($d['nc_esperada_cantidad'] ?? 0) > 0
                              ? $num($d['nc_esperada_cantidad']) : ''],
        'nc_costo'    => ['titulo' => 'NC esperada por costo', 'ancho' => 18, 'desde' => '2026-09-27 18:47',
                          'valor' => static fn($d) => (float) ($d['nc_esperada_costo'] ?? 0) > 0
                              ? $num($d['nc_esperada_costo']) : ''],
        'confirmados' => ['titulo' => 'Vínculos confirmados', 'ancho' => 12, 'desde' => '2026-09-27 18:47',
                          'valor' => static fn($d) => (int) ($d['matches_confirmados'] ?? 0) > 0 ? (int) $d['matches_confirmados'] : ''],
        'sugeridos'   => ['titulo' => 'Vínculos sugeridos', 'ancho' => 12, 'desde' => '2026-09-27 18:47',
                          'valor' => static fn($d) => (int) ($d['matches_sugeridos'] ?? 0) > 0 ? (int) $d['matches_sugeridos'] : ''],
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
        // Un dato por celda. La sucursal iba bajo el proveedor; la fecha, la
        // aplicación y la NC del proveedor, bajo el documento; el total y la
        // diferencia, bajo el número del XML. Cada uno en su columna se puede
        // mover, apagar y leer de arriba abajo.
        'proveedor' => ['titulo' => 'Proveedor', 'valor' => 'proveedor_nombre', 'ancho' => 36],
        'sucursal'    => ['titulo' => 'Sucursal',           'valor' => 'sucursal', 'ancho' => 24, 'desde' => '2026-09-27 18:47'],
        'documento' => ['titulo' => 'Documento', 'valor' => 'documento', 'ancho' => 22],
        'fecha'       => ['titulo' => 'Fecha',              'valor' => 'fecha', 'ancho' => 13, 'desde' => '2026-09-27 18:47'],
        'aplicacion'  => ['titulo' => 'Aplicación',         'ancho' => 20, 'desde' => '2026-09-27 18:47',
                          'valor' => static function ($l) {
                              $apl = (string) ($l['estado_aplicacion'] ?? '');
                              return $apl === '' || $apl === AplicacionNotaCredito::NO_APLICA
                                  ? '' : AplicacionNotaCredito::etiqueta($apl);
                          }],
        'nc_proveedor'=> ['titulo' => 'NC del proveedor',   'valor' => 'nc_proveedor', 'ancho' => 20, 'desde' => '2026-09-27 18:47'],
        'fecha_nc'    => ['titulo' => 'Fecha de la NC del proveedor', 'valor' => 'fecha_nc_proveedor', 'ancho' => 17, 'desde' => '2026-09-27 18:47'],
        'monto'     => ['titulo' => 'Monto',     'valor' => static fn($l) => $num($l['monto'] ?? null), 'ancho' => 16],
        'saldo'     => ['titulo' => 'Saldo',     'valor' => static fn($l) => $num($l['saldo'] ?? null), 'ancho' => 16],
        'nc_xml'    => [
            'titulo' => 'NC XML',
            'ancho'  => 20,
            'valor'  => static fn($l) => (string) ($l['xml_numero'] ?: ($l['xml_consecutivo'] ?? '')),
        ],
        'total_xml'   => ['titulo' => 'Total XML',          'ancho' => 16, 'desde' => '2026-09-27 18:47',
                          'valor' => static fn($l) => ($l['xml_total'] ?? null) === null ? '' : $num($l['xml_total'])],
        'diferencia'  => ['titulo' => 'Diferencia',         'ancho' => 16, 'desde' => '2026-09-27 18:47',
                          'valor' => static fn($l) => ($l['diferencia'] ?? null) === null || $l['diferencia'] === ''
                              ? '' : $num($l['diferencia'])],
        // En pantalla solo se escribe cuando NO es el de la nota —ahí es un
        // aviso—; en el Excel va siempre, que es lo que se filtra.
        'xml_proveedor' => ['titulo' => 'Proveedor del XML', 'valor' => 'xml_proveedor', 'ancho' => 32, 'desde' => '2026-09-27 18:47'],

        // ── Lo que la base sabe y la pantalla no enseñaba ──
        'clase'       => ['titulo' => 'Clase',              'valor' => 'clase', 'ancho' => 14, 'defecto' => false],
        'codigo'      => ['titulo' => 'Código proveedor',   'valor' => 'proveedor_codigo', 'ancho' => 15, 'defecto' => false],
        'moneda'      => ['titulo' => 'Moneda',             'valor' => 'moneda', 'ancho' => 9, 'defecto' => false],
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
        // El detalle y la fuente iban bajo la acción, en la misma celda. Un
        // dato por columna: se leen, se mueven y se apagan por separado.
        'detalle'   => ['titulo' => 'Detalle', 'ancho' => 60, 'desde' => '2026-09-27 18:47',
                        'valor' => static fn($f) => $detalleLegible($f['detalle'] ?? null)],
        // De qué fuente sale el renglón: la bitácora propia, o el historial
        // que cada módulo llevaba antes de que la bitácora existiera.
        'origen'    => ['titulo' => 'Origen',  'valor' => 'origen', 'ancho' => 20, 'desde' => '2026-09-27 18:47'],
    ],
],

];
