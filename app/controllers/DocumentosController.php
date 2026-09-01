<?php
require_once __DIR__ . '/../helpers/RecuperadorDocumentos.php';
require_once __DIR__ . '/../helpers/FichaDocumento.php';

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

        $this->json(['ok' => true, 'ficha' => FichaDocumento::de($fila, $this->url(''))]);
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
                . 'Para una semana entera está `php cli/recuperar_documentos.php --aplicar`.'], 422);
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
        $real = $ruta !== '' ? realpath($ruta) : false;
        if (!$registro || $real === false || !is_file($real)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            // Decir por qué, no solo que no está: si la fila conserva la ruta,
            // el archivo se archivó y desapareció de la carpeta compartida, y
            // eso tiene arreglo desde el listado.
            $seArchivo = $registro && $ruta !== '';
            echo $seArchivo
                ? 'Este ' . strtoupper($tipo) . ' se archivó y ya no está en la carpeta compartida. '
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
