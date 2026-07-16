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
            $stats['total_facturas'] = $this->loadModel('Factura')->count();
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
            $porPagar = $this->loadModel('PorPagar');
            $listados = $porPagar->getListados(1);
            if (!empty($listados)) {
                $ultimoListado = $listados[0];
                $resumenListado = $porPagar->resumenPorEstado((int) $ultimoListado['id']);
            }
        } catch (Throwable $e) {
        }

        $this->render('home/index', [
            'title' => 'Inicio - XMLConcilia',
            'stats' => $stats,
            'sociedades' => $sociedades,
            'sociedadActiva' => $sociedadActiva,
            'ultimoListado' => $ultimoListado,
            'resumenListado' => $resumenListado,
        ]);
    }
}
