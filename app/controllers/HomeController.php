<?php
/**
 * Controlador de Home
 * Página principal y dashboard
 */

class HomeController extends Controller
{
    /**
     * Página principal
     */
    public function index()
    {
        // Obtener estadísticas básicas si hay conexión a BD
        $stats = [
            'total_facturas' => 0,
            'total_gastos' => 0,
            'total_conciliadas' => 0,
            'pendientes_revision' => 0
        ];
        
        try {
            // Intentar cargar estadísticas
            $facturasModel = $this->loadModel('Factura');
            $gastosModel = $this->loadModel('Gasto');
            $conciliacionModel = $this->loadModel('Conciliacion');
            
            // Obtener totales (si las tablas existen)
            $stats['total_facturas'] = $facturasModel->count();
            $stats['total_gastos'] = $gastosModel->count();
            $stats['total_conciliadas'] = $conciliacionModel->countByEstado('conciliada');
            $stats['pendientes_revision'] = $conciliacionModel->countByEstado('requiere_revision');
        } catch (Exception $e) {
            // Si no hay BD configurada, usar valores por defecto
            // No mostrar error en home
        }
        
        $data = [
            'title' => 'Inicio - XMLConcilia',
            'stats' => $stats
        ];
        
        $this->render('home/index', $data);
    }
}
