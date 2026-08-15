<?php
/**
 * Controlador de Home
 * Panel principal: sociedades (con la activa se trabaja) y estado del
 * último listado de facturas por pagar.
 */

class HomeController extends Controller
{
    public function __construct() { $this->requireAuth(); }

    public function index()
    {
        $stats = [
            'total_facturas' => 0,
            'bandeja_pendientes' => 0,
        ];
        $sociedades = [];
        $sociedadActiva = null;
        $ultimoListado = null;
        $resumenListado = [];

        try {
            $stats['total_facturas'] = $this->loadModel('Factura')->contarFacturas();
        } catch (Throwable $e) {
        }

        try {
            $conteo = $this->loadModel('CorreoBandeja')->contarPorEstado();
            $stats['bandeja_pendientes'] = (int) ($conteo['pendiente'] ?? 0);
        } catch (Throwable $e) {
        }

        try {
            $sociedadModel = $this->loadModel('Sociedad');
            $sociedades = $sociedadModel->getAll();
            $sociedadActiva = $sociedadModel->getActiva();
        } catch (Throwable $e) {
        }

        try {
            $listados = $this->loadModel('PorPagar')->getListados(1);
            if (!empty($listados)) {
                $ultimoListado = $listados[0];
                // El semáforo del pago vive en las facturas del ERP marcadas
                // para esa semana, no en una tabla de líneas propia.
                $resumenListado = $this->loadModel('FacturaErp')
                    ->resumenRespaldoPago((int) $ultimoListado['id']);
            }
        } catch (Throwable $e) {
        }

        $this->render('home/index', [
            'title' => 'Inicio - Nexo Fiscal',
            'stats' => $stats,
            'sociedades' => $sociedades,
            'sociedadActiva' => $sociedadActiva,
            'ultimoListado' => $ultimoListado,
            'resumenListado' => $resumenListado,
        ]);
    }
}
