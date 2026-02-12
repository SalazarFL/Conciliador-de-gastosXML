<?php
/**
 * Controlador de Proveedores
 * API para búsqueda y gestión de proveedores
 */

class ProveedoresController extends Controller
{
    /**
     * Buscar proveedores (API AJAX)
     */
    public function buscar()
    {
        $termino = $this->get('q', '');
        
        if (strlen($termino) < 2) {
            return $this->json([
                'success' => false,
                'message' => 'El término de búsqueda debe tener al menos 2 caracteres'
            ], 400);
        }
        
        try {
            $proveedorModel = $this->loadModel('Proveedor');
            $resultados = $proveedorModel->buscar($termino);
            
            return $this->json([
                'success' => true,
                'data' => $resultados
            ]);
        } catch (Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Error al buscar proveedores',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
