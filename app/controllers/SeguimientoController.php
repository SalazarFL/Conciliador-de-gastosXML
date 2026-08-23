<?php
/**
 * Seguimiento: la cola única de trabajo.
 *
 * Los demás módulos son para cargar y comparar; este es para trabajar. Junta
 * lo que le falta respaldo o no cuadra —venga de una nota de crédito o de una
 * factura del ERP— y deja constancia de qué hizo cada quien con cada renglón.
 */
// El modelo se carga aquí y no solo con loadModel(): no hay autoload, y
// `actualizar()` usa Seguimiento::normalizarItems() para sanear la selección
// antes de tocar la base. Sin esto, cambiar de estado moría con
// 'Class "Seguimiento" not found' en vez de guardar.
require_once __DIR__ . '/../models/Seguimiento.php';
require_once __DIR__ . '/../helpers/FacturaMatcher.php';
require_once __DIR__ . '/../helpers/ClaseNotaCredito.php';
require_once __DIR__ . '/../helpers/AplicacionNotaCredito.php';
require_once __DIR__ . '/../models/ProveedorCatalogo.php';
require_once __DIR__ . '/../helpers/NavegacionDocumentos.php';
require_once __DIR__ . '/../helpers/EstadoArchivo.php';

class SeguimientoController extends Controller
{
    private const POR_PAGINA = 50;

    public function __construct()
    {
        $this->requireAuth();
    }

    public function index()
    {
        // La pestaña abierta, la barra y los filtros de columna vuelven como
        // se dejaron. 'contexto_id' no: no tiene control en pantalla, y
        // recordarlo dejaría la cola recortada sin que nada lo explique.
        $this->recordarFiltros('seguimiento', [
            'vista', 'origen', 'tarea', 'marca', 'clase', 'aplicacion', 'responsable',
            'proveedor', 'sucursal', 'desde', 'hasta', 'monto_min',
            'condicion_saldo', 'q', 'col_documento', 'col_proveedor',
            'col_monto', 'col_saldo', 'col_respaldo', 'col_tarea', 'orden',
        ]);

        $modelo = $this->loadModel('Seguimiento');
        $sociedad = $this->loadModel('Sociedad')->getActiva();

        $filtros = $this->filtros($sociedad);
        $pagina = max(1, (int) $this->get('pagina', 1));

        $cola = $modelo->cola($filtros, $pagina, self::POR_PAGINA);
        $resumen = $modelo->resumen($filtros);
        $dimensiones = $modelo->dimensionesParaFiltro($sociedad ? (int) $sociedad['id'] : 0);

        $this->render('seguimiento/index', [
            'title' => 'Seguimiento - Nexo Fiscal',
            'sociedadActiva' => $sociedad,
            'filas' => $this->decorar($cola['filas']),
            'paginacion' => [
                'total' => $cola['total'],
                'pagina' => $cola['pagina'],
                'paginas' => $cola['paginas'],
                'por_pagina' => $cola['por_pagina'],
            ],
            'resumen' => $resumen,
            'filtros' => $filtros,
            'responsables' => $modelo->responsables(),
            'proveedoresFiltro' => ProveedorCatalogo::opciones($dimensiones['proveedores']),
            'sucursales' => $dimensiones['sucursales'],
            'estados' => Seguimiento::ESTADOS,
            'tareas' => Seguimiento::TAREAS,
        ]);
    }

    /**
     * Los filtros que entiende la pantalla, saneados.
     *
     * El trabajo lo hace el modelo: la tarjeta de navegación que abre esta
     * cola en Correo y en los listados de XML tiene que rehacer exactamente
     * la misma consulta para poder recorrerla, y dos lecturas de $_GET que
     * se puedan desincronizar darían una lista distinta a la que se ve acá.
     */
    private function filtros($sociedad)
    {
        return Seguimiento::filtrosDesde($_GET, $sociedad ? (int) $sociedad['id'] : 0);
    }

    /**
     * Cada cuántos días insiste el recordatorio. 0 = sin recordatorio.
     *
     * Solo se admiten los plazos que ofrece la pantalla: un número libre desde
     * fuera acabaría en la columna y nadie podría volver a elegirlo desde el
     * desplegable.
     */
    private function frecuencia($valor)
    {
        $dias = (int) $valor;
        return in_array($dias, [1, 3, 7, 15, 30], true) ? $dias : 0;
    }

    /**
     * El primer aviso: dentro de tantos días, a la hora pedida.
     *
     * La hora es del reloj de quien lo pide, y la base guarda esa misma hora
     * de pared. No hay husos de por medio: todas las máquinas y el servidor
     * están en Costa Rica.
     */
    private function momentoRecordatorio($dias, $hora)
    {
        $hora = trim((string) $hora);
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hora)) {
            $hora = '08:00';
        }
        return date('Y-m-d', strtotime('+' . (int) $dias . ' days')) . ' ' . $hora . ':00';
    }

    // Aquí se armaba la lista de listados para el desplegable "Listado".
    // Salió con el desplegable: recorrer la unión entera es lo caro de esta
    // pantalla, y era una pasada más por una pregunta que el pago semanal ya
    // contesta en su propia pantalla. `Seguimiento::contextosDisponibles()`
    // sigue ahí para cuando haga falta.

    /**
     * Lo que la vista necesita y no sale de la base: el término con el que
     * buscar el documento en el correo y si los archivos siguen en disco.
     *
     * Se comprueba el archivo de verdad, no solo la columna: la base guarda
     * rutas relativas a una carpeta sincronizada, y un documento puede estar
     * registrado pero todavía no haber bajado a esta computadora.
     */
    private function decorar(array $filas)
    {
        foreach ($filas as &$fila) {
            // No es lo mismo no haber tenido nunca el comprobante que haberlo
            // tenido y haberlo perdido: lo primero se resuelve buscándolo, lo
            // segundo volviéndolo a bajar del correo. Quién es quién lo decide
            // EstadoArchivo, que es el mismo criterio en todos los módulos.
            $archivo = EstadoArchivo::de($fila);
            $fila['xml_ok'] = $archivo['xml_ok'];
            $fila['pdf_ok'] = $archivo['pdf_ok'];
            $fila['xml_perdido'] = !$archivo['xml_ok'] && trim((string) ($fila['ruta_xml'] ?? '')) !== '';
            $fila['pdf_perdido'] = !$archivo['pdf_ok'] && trim((string) ($fila['ruta_pdf'] ?? '')) !== '';
            $fila['recuperable'] = $archivo['recuperable'];
            $fila['pdf_historico'] = ($fila['estado_pdf'] ?? '') === 'no_disponible_historico';
            $this->decorarBusqueda($fila);
            // El recordatorio solo tiene sentido mientras el renglón siga en
            // revisión: en las demás pestañas no hay nada que esperar.
            $fila['vencido'] = !empty($fila['recordar_en'])
                && $fila['recordar_en'] <= date('Y-m-d H:i:s')
                && $fila['seguimiento_estado'] === Seguimiento::ESTADO_A_MANO;
            // La marca a mano dejó de concordar con los datos: ni se mueve
            // sola ni se calla, para que alguien decida.
            $fila['desajustada'] = !empty($fila['estado_a_mano'])
                && $fila['estado_a_mano'] !== $fila['estado_calculado'];
        }
        unset($fila);
        return $filas;
    }

    /**
     * Con qué se busca el documento, y por qué.
     *
     * El término lo decide NavegacionDocumentos, que es quien lo necesita para
     * la tarjeta de las pantallas donde se busca; acá se agrega lo que la
     * pantalla enseña alrededor: si se está buscando por número o por
     * proveedor —que cambia el texto del botón— y la fecha de referencia.
     *
     * En una factura el término es su propio número. En una nota NO: el
     * número largo del reporte es el consecutivo de la factura que la nota
     * corrige (ver ClaseNotaCredito), así que buscarlo traía siempre el
     * documento equivocado. Cuando la nota no trae consecutivo propio —lo
     * corriente— no hay número que buscar y se cae al nombre del proveedor,
     * acotado a los 15 días alrededor de su fecha.
     */
    private function decorarBusqueda(array &$fila)
    {
        $fila['busqueda'] = NavegacionDocumentos::busquedaDe($fila);
        $fila['busqueda_fecha'] = '';

        $esNotaSinNumero = ($fila['origen'] ?? '') === 'nota_credito'
            && trim((string) ($fila['nc_proveedor'] ?? '')) === '';
        $fila['busqueda_por'] = $esNotaSinNumero ? 'proveedor' : 'numero';

        if ($esNotaSinNumero) {
            $fecha = strtotime((string) ($fila['fecha'] ?? ''));
            if ($fecha !== false) {
                $fila['busqueda_fecha'] = date('d/m/Y', $fecha);
            }
        }
    }

    // ── Acciones ────────────────────────────────────────────────────────────

    /** Cambio de estado, responsable, fecha o motivo sobre uno o varios. */
    public function actualizar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        try {
            $items = $this->itemsDelPost();
            if (!$items) {
                throw new Exception('No se seleccionó ningún renglón.');
            }

            $cambio = [];
            // Vacío = no tocar la marca (una anotación suelta). El valor
            // SIN_MARCA sí la toca: la borra y devuelve el renglón al cálculo.
            if (($estado = trim((string) $this->post('estado', ''))) !== '') {
                $cambio['estado'] = $estado;
            }
            if ($this->post('responsable', null) !== null) {
                $cambio['responsable'] = trim((string) $this->post('responsable'));
            }
            if ($this->post('recordar_cada', null) !== null) {
                $cambio['recordar_cada'] = $this->frecuencia($this->post('recordar_cada'));
                $cambio['recordar_en'] = $cambio['recordar_cada'] > 0
                    ? $this->momentoRecordatorio($cambio['recordar_cada'], $this->post('recordar_hora', ''))
                    : null;
            }
            if ($this->post('motivo', null) !== null) {
                $cambio['motivo'] = trim((string) $this->post('motivo'));
            }
            $cambio['comentario'] = trim((string) $this->post('comentario', ''));

            if (!isset($cambio['estado']) && $cambio['comentario'] === ''
                && !array_key_exists('responsable', $cambio)
                && !array_key_exists('recordar_en', $cambio)
                && !array_key_exists('motivo', $cambio)) {
                throw new Exception('No hay ningún cambio que guardar.');
            }

            $resultado = $this->loadModel('Seguimiento')->aplicar($items, $cambio, $this->usuario());
            $this->json(['ok' => true] + $resultado);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** El expediente de un renglón: sus datos y su bitácora. */
    public function detalle()
    {
        try {
            $origen = (string) $this->get('origen', '');
            $ref = (int) $this->get('referencia_id', 0);
            $modelo = $this->loadModel('Seguimiento');

            $fila = $modelo->uno($origen, $ref);
            if (!$fila) {
                throw new Exception('El renglón no existe o ya no está en la cola.');
            }

            $decoradas = $this->decorar([$fila]);
            // El estado del archivo ya está resuelto arriba; la ruta de una
            // carpeta del disco de la oficina no tiene por qué viajar al
            // navegador.
            unset($decoradas[0]['ruta_xml'], $decoradas[0]['ruta_pdf']);
            $this->json([
                'ok' => true,
                'fila' => $decoradas[0],
                'bitacora' => $modelo->bitacora($origen, $ref),
            ]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** La cola tal como se ve, en CSV, para trabajarla fuera o mandarla. */
    public function exportar()
    {
        $modelo = $this->loadModel('Seguimiento');
        $sociedad = $this->loadModel('Sociedad')->getActiva();
        $filtros = $this->filtros($sociedad);

        $cola = $modelo->cola($filtros, 1, 5000);
        $filas = $this->decorar($cola['filas']);

        $nombre = 'seguimiento_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');

        $salida = fopen('php://output', 'w');
        // BOM: sin él Excel en Windows abre los acentos rotos.
        fwrite($salida, "\xEF\xBB\xBF");
        fputcsv($salida, [
            'Origen', 'Documento', 'Clase', 'Proveedor', 'Fecha', 'Moneda', 'Monto',
            'Saldo', 'Diferencia', 'Qué falta', 'XML', 'PDF', 'Estado', 'Puesto a mano',
            'Le tocaría', 'Responsable', 'Recordatorio', 'Motivo', 'Listado',
            'Consecutivo XML', 'Último movimiento',
            'Nota de crédito en juego',
        ], ';', '"', '\\');

        foreach ($filas as $f) {
            fputcsv($salida, [
                $f['origen'] === 'nota_credito' ? 'Nota de crédito' : 'Factura',
                $f['documento'],
                $f['clase'] ?: '',
                $f['proveedor'],
                $f['fecha'],
                $f['moneda'],
                number_format((float) $f['monto'], 2, '.', ''),
                number_format((float) $f['saldo'], 2, '.', ''),
                $f['diferencia'] !== null ? number_format((float) $f['diferencia'], 2, '.', '') : '',
                Seguimiento::TAREAS[$f['tarea']] ?? $f['tarea'],
                $f['xml_ok'] ? 'Sí' : 'No',
                $f['pdf_ok'] ? 'Sí' : ($f['pdf_historico'] ? 'Histórico' : 'No'),
                Seguimiento::ESTADOS[$f['seguimiento_estado']] ?? $f['seguimiento_estado'],
                $f['estado_a_mano'] ? 'Sí' : '',
                // Solo cuando la marca a mano y el cálculo no coinciden: en el
                // resto de las filas repetir el estado no dice nada.
                $f['desajustada'] ? (Seguimiento::ESTADOS[$f['estado_calculado']] ?? $f['estado_calculado']) : '',
                $f['responsable'] ?: '',
                $f['recordar_en'] ?: '',
                $f['motivo'] ?: '',
                $f['contexto'],
                $f['consecutivo'] ?: '',
                $f['seguimiento_actualizado_en'] ?: '',
                // Las dos caras de lo mismo: una nota dice contra qué factura
                // se descuenta, una factura dice cuánta nota tiene esperando.
                self::notaEnJuego($f),
            ], ';', '"', '\\');
        }
        fclose($salida);
        exit;
    }

    /**
     * La nota de crédito en juego de un renglón, en una frase.
     *
     * Se mira desde el origen del renglón porque la pregunta es distinta a
     * cada lado: de una nota interesa contra qué factura va, de una factura
     * interesa cuánto tiene esperando sin aplicar.
     */
    private static function notaEnJuego(array $f)
    {
        if (($f['origen'] ?? '') === 'nota_credito') {
            $estado = (string) ($f['aplicacion_estado'] ?? '');
            if ($estado === '' || $estado === AplicacionNotaCredito::NO_APLICA) {
                return '';
            }
            $texto = AplicacionNotaCredito::etiqueta($estado);
            return !empty($f['aplicacion_factura_doc'])
                ? $texto . ' (factura ' . $f['aplicacion_factura_doc'] . ')'
                : $texto;
        }

        $cuantas = (int) ($f['notas_vivas'] ?? 0);
        if ($cuantas <= 0) {
            return '';
        }
        return sprintf(
            '%d nota(s) sin aplicar por %s',
            $cuantas,
            number_format((float) ($f['notas_vivas_saldo'] ?? 0), 2, '.', '')
        );
    }

    private function itemsDelPost()
    {
        $crudos = $this->post('items', []);
        if (is_string($crudos)) {
            $crudos = json_decode($crudos, true) ?: [];
        }
        return is_array($crudos) ? Seguimiento::normalizarItems($crudos) : [];
    }

    private function usuario()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return [
            'id' => $_SESSION['user_id'] ?? null,
            'nombre' => $_SESSION['user_nombre'] ?? $_SESSION['user_username'] ?? 'Sistema',
        ];
    }
}
