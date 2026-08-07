<?php
class DocumentosController extends Controller
{
    public function __construct() { $this->requireAuth(); }
    public function xml($id) { $this->servir((int) $id, 'xml'); }
    public function pdf($id) { $this->servir((int) $id, 'pdf'); }

    private function servir($id, $tipo)
    {
        $registro = $this->loadModel('Factura')->getOneWithProvider($id);
        $campo = $tipo === 'pdf' ? 'ruta_pdf' : 'ruta_xml';
        $ruta = $registro ? trim((string) ($registro[$campo] ?? '')) : '';
        $real = $ruta !== '' ? realpath($ruta) : false;
        if (!$registro || $real === false || !is_file($real)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo $tipo === 'pdf' ? 'El PDF está pendiente o no está disponible.' : 'El XML local no está disponible.';
            exit;
        }
        $extension = strtolower((string) pathinfo($real, PATHINFO_EXTENSION));
        if (($tipo === 'pdf' && $extension !== 'pdf') || ($tipo === 'xml' && $extension !== 'xml')) {
            http_response_code(415);
            exit('Tipo de archivo no válido.');
        }
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . ($tipo === 'pdf' ? 'application/pdf' : 'application/xml; charset=utf-8'));
        header('Content-Length: ' . filesize($real));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($real)) . '"');
        readfile($real);
        exit;
    }
}
