<?php
require_once __DIR__ . '/../helpers/RecuperadorDocumentos.php';
require_once __DIR__ . '/../helpers/FichaDocumento.php';
require_once __DIR__ . '/../helpers/DocumentoArchivo.php';
require_once __DIR__ . '/../helpers/NumeroFactura.php';

class DocumentosController extends Controller
{
    public function __construct() { $this->requireAuth(); }
    public function xml($id) { $this->servir((int) $id, 'xml'); }
    public function pdf($id) { $this->servir((int) $id, 'pdf'); }

    /**
     * La ficha de un comprobante en JSON, para el cuadro que se abre encima
     * del listado (GET).
     *
     * Sirve igual a una factura y a una nota de crédito porque las dos son la
     * misma fila: el visor no tiene por qué saber en qué módulo está parado.
     * Las pantallas completas —/facturas/ver y /notas-xml/ver— siguen ahí y
     * leen lo mismo; esto no las reemplaza, les ahorra el viaje.
     */
    public function ficha($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            $this->json(['ok' => false, 'message' => 'No se indicó ningún documento.'], 422);
        }

        try {
            $fila = $this->loadModel('Factura')->getOneWithProvider($id);
        } catch (Throwable $e) {
            $this->registrarFallo('Ficha del documento ' . $id, $e);
            $this->json(['ok' => false, 'message' => 'No se pudo leer este documento.'], 500);
            return;
        }

        if (!$fila) {
            $this->json(['ok' => false, 'message' => 'Este documento ya no está en el sistema.'], 404);
        }

        // La nota de crédito enseña las facturas que cita su XML. Si leerlas
        // falla, la ficha sale igual: son un dato más, no el documento.
        $referencias = [];
        if (strtoupper(trim((string) ($fila['tipo_documento'] ?? ''))) === 'NC') {
            try {
                require_once __DIR__ . '/../models/FacturaXmlDetalle.php';
                $referencias = $this->loadModel('FacturaXmlDetalle')->referenciasConDocumento($id);
            } catch (Throwable $e) {
                $this->registrarFallo('Referencias de la nota ' . $id, $e);
                $referencias = null;
            }
        }

        $this->json(['ok' => true, 'ficha' => FichaDocumento::de($fila, $this->url(''), $referencias)]);
    }

    /**
     * Vuelve a bajar del correo el respaldo de uno o varios documentos y lo
     * deja en su misma ruta (POST, JSON).
     *
     * Solo repone lo que puede probar idéntico: cada adjunto se acepta si su
     * contenido da el SHA-256 que se guardó al archivarlo. Lo demás se informa
     * sin escribir nada.
     */
    public function recuperar()
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }

        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            preg_split('/[^0-9]+/', (string) $this->post('ids', ''))
        ))));
        if (!$ids) {
            $this->json(['ok' => false, 'message' => 'No se indicó ningún documento.'], 422);
        }
        if (count($ids) > 200) {
            $this->json(['ok' => false, 'message' => 'Son demasiados de una vez: repón hasta 200. '
                . 'Para un pago entero está `php cli/recuperar_documentos.php --aplicar`.'], 422);
        }

        @set_time_limit(180);

        try {
            $facturas = $this->loadModel('Factura');
            $documentos = $facturas->getRecuperablesDelCorreo($ids);
            if (!$documentos) {
                $this->json(['ok' => false, 'message' => 'De ese documento no se guardó el correo del que '
                    . 'salió, o no se guardó la huella de su XML: no hay de dónde volver a bajarlo. '
                    . 'Queda la papelera de SharePoint o pedírselo al proveedor.'], 422);
            }

            $resumen = (new RecuperadorDocumentos($facturas))->recuperar($documentos);
        } catch (Throwable $e) {
            $this->registrarFallo('Recuperar el respaldo del correo', $e);
            $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $this->json([
            'ok' => $resumen['recuperados'] > 0 || $resumen['ya_estaban'] > 0,
            'message' => $this->resumenEnPalabras($resumen),
            'resumen' => $resumen,
        ]);
    }

    /**
     * Sube a mano el PDF de un comprobante que no lo tiene (POST, JSON).
     *
     * Para el proveedor que no lo adjunta —lo manda como enlace, o por
     * WhatsApp—: el correo trae solo el XML y el PDF nunca entra. Se archiva al
     * lado de su XML, con su mismo nombre, y si la factura está en un pago se
     * copia en el acto a la carpeta del pago.
     *
     * No reemplaza un PDF que ya está: eso no es completar un respaldo, es
     * cambiarlo, y no se hace desde un botón.
     */
    public function subirPdf($id)
    {
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }
        require_once __DIR__ . '/../helpers/PdfDeFactura.php';
        require_once __DIR__ . '/../helpers/PdfTextoExtractor.php';

        $id = (int) $id;
        $archivo = $_FILES['pdf'] ?? null;
        if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) $archivo['tmp_name'])) {
            $this->json(['ok' => false, 'message' => 'No llegó ningún archivo. Elegí el PDF otra vez.'], 422);
        }
        $config = require __DIR__ . '/../config/config.php';
        if ((int) $archivo['size'] > (int) ($config['max_upload_size'] ?? 10485760)) {
            $this->json(['ok' => false, 'message' => 'El PDF pesa más de lo permitido.'], 422);
        }
        $tmp = (string) $archivo['tmp_name'];
        if (!PdfDeFactura::esPdf($tmp)) {
            $this->json(['ok' => false, 'message' => 'Ese archivo no es un PDF.'], 422);
        }

        $facturas = $this->loadModel('Factura');
        $fila = $facturas->getOneWithProvider($id);
        if (!$fila) {
            $this->json(['ok' => false, 'message' => 'Este documento ya no está en el sistema.'], 404);
        }
        if (trim((string) ($fila['ruta_xml'] ?? '')) === '') {
            $this->json(['ok' => false, 'message' => 'Este documento no tiene XML: primero hay que cargar el XML.'], 422);
        }
        $rutaPdf = trim((string) ($fila['ruta_pdf'] ?? ''));
        if ($rutaPdf !== '' && RutaDocumento::existe($rutaPdf)) {
            $this->json(['ok' => false, 'message' => 'Este documento ya tiene su PDF. No se reemplaza desde aquí.'], 422);
        }

        // ¿Es de esta factura? Ver PdfDeFactura.
        $aviso = '';
        try {
            $corresponde = PdfDeFactura::corresponde(PdfTextoExtractor::extraer($tmp), $fila);
        } catch (Throwable $e) {
            $corresponde = null;
        }
        if ($corresponde === false && (string) $this->post('forzar', '') !== '1') {
            $this->json([
                'ok' => false,
                'confirmar' => true,
                'message' => 'Este PDF no menciona la clave ni el consecutivo de la factura '
                    . NumeroFactura::xmlOchoDigitos((string) ($fila['numero_factura_asistente'] ?? ''))
                    . ' de ' . trim((string) ($fila['proveedor_nombre'] ?? '')) . '. Puede ser el PDF de otra factura.',
            ], 409);
        }
        if ($corresponde === null) {
            $aviso = ' No se pudo leer el texto del PDF para comprobar que sea de esta factura: revisalo a la vista.';
        }

        $creados = [];
        try {
            $sociedadId = (int) ($fila['sociedad_id'] ?? 0);
            $sociedad = $sociedadId > 0 ? $this->loadModel('Sociedad')->findById($sociedadId) : null;
            $documentos = (new DocumentoArchivo())->paraSociedad(
                $sociedadId,
                is_array($sociedad) && $sociedad['nombre'] !== '' ? $sociedad['nombre'] : DocumentoArchivo::EMPRESA_SIN_DEFINIR
            );
            $creados = $documentos->archivarPdfPara($fila, $tmp);
            $facturas->actualizarArchivos($id, $creados);
        } catch (Throwable $e) {
            DocumentoArchivo::eliminarCreados($creados);
            $this->registrarFallo('Subir el PDF del documento ' . $id, $e);
            $this->json(['ok' => false, 'message' => 'No se pudo guardar el PDF: ' . $e->getMessage()], 500);
        }

        $this->json([
            'ok' => true,
            'message' => 'PDF guardado junto a su XML.' . $this->copiarAlPago($facturas, $id) . $aviso,
        ]);
    }

    /**
     * Si el documento está en un pago, su par completo va ya a la carpeta del
     * pago: esperar a la tarea programada deja la carpeta incompleta unos
     * minutos, justo cuando alguien la está por mandar. Si falla, la tarea lo
     * repone igual; solo se avisa.
     */
    private function copiarAlPago($facturas, $id)
    {
        try {
            $filas = $facturas->getParaOrganizarArchivos([$id], AlmacenDocumentos::SHAREPOINT);
            $fila = $filas[0] ?? null;
            $carpeta = trim((string) ($fila['carpeta_pago'] ?? ''));
            if (!$fila || empty($fila['en_pago']) || $carpeta === '') {
                return '';
            }
            DocumentoArchivo::sobreAlmacen(AlmacenDocumentos::principal())->copiarAPago(
                (string) $fila['ruta_xml'], (string) $fila['ruta_pdf'], $fila, $carpeta,
                (string) ($fila['proveedor_nombre'] ?? 'PROVEEDOR')
            );
            return ' También quedó en la carpeta del pago (PAGOS / ' . $carpeta . ').';
        } catch (Throwable $e) {
            return ' La copia a la carpeta del pago la hace la tarea programada en unos minutos.';
        }
    }

    private function resumenEnPalabras(array $resumen)
    {
        if ($resumen['recuperados'] > 0) {
            $texto = $resumen['recuperados'] === 1
                ? 'Respaldo repuesto en su carpeta.'
                : $resumen['recuperados'] . ' respaldos repuestos en su carpeta.';
            $fallos = $resumen['revisados'] - $resumen['recuperados'] - $resumen['ya_estaban'];
            return $fallos > 0 ? $texto . ' ' . $fallos . ' no se pudieron bajar.' : $texto;
        }
        if ($resumen['ya_estaban'] > 0 && $resumen['revisados'] === $resumen['ya_estaban']) {
            return 'El archivo ya estaba en su sitio.';
        }
        foreach ($resumen['detalle'] as $linea) {
            if (!empty($linea['mensaje']) && $linea['estado'] !== 'ya_estaba') {
                return $linea['mensaje'];
            }
        }
        return 'No se pudo reponer nada.';
    }

    private function servir($id, $tipo)
    {
        $registro = $this->loadModel('Factura')->getOneWithProvider($id);
        $campo = $tipo === 'pdf' ? 'ruta_pdf' : 'ruta_xml';
        $ruta = $registro ? trim((string) ($registro[$campo] ?? '')) : '';
        // Se le pregunta al almacén, no al disco: realpath() solo sabe de
        // sistemas de archivos y contestaría "no está" de todo documento que
        // viva en SharePoint. La ruta ya viene resuelta por la puerta.
        $real = $ruta;
        if (!$registro || $ruta === '' || !RutaDocumento::existe($ruta)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            // Decir por qué, no solo que no está: si la fila conserva la ruta,
            // el archivo se archivó y desapareció de la carpeta compartida, y
            // eso tiene arreglo desde el listado.
            $seArchivo = $registro && $ruta !== '';
            echo $seArchivo
                ? 'Este ' . strtoupper($tipo) . ' se archivó y ya no está donde se guardó. '
                    . 'Volvé al renglón de este documento: sale marcado "Archivo perdido", y ahí mismo '
                    . 'está el botón para volver a bajarlo del correo.'
                : ($tipo === 'pdf' ? 'El PDF está pendiente o no está disponible.' : 'El XML local no está disponible.');
            exit;
        }
        $extension = strtolower((string) pathinfo($real, PATHINFO_EXTENSION));
        if (($tipo === 'pdf' && $extension !== 'pdf') || ($tipo === 'xml' && $extension !== 'xml')) {
            http_response_code(415);
            exit('Tipo de archivo no válido.');
        }
        // Abrir ANTES de mandar cabeceras: si el archivo es un marcador de
        // OneDrive, aquí se sabe y todavía se puede contestar con palabras en
        // vez de con un documento vacío.
        $manejador = RutaDocumento::abrirParaLeer($real);
        if ($manejador === false) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Este ' . strtoupper($tipo) . ' no se puede abrir todavía. '
                . RutaDocumento::porQueNoSeLee($real);
            exit;
        }
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . ($tipo === 'pdf' ? 'application/pdf' : 'application/xml; charset=utf-8'));
        header('Content-Length: ' . RutaDocumento::tamano($real));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($real)) . '"');
        fpassthru($manejador);
        fclose($manejador);
        exit;
    }
}
