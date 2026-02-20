<?php
/**
 * Controlador de generación de reportes
 */

class ReportesController extends Controller
{
	public function index()
	{
		$this->render('reportes/index', [
			'title' => 'Reportes - XMLConcilia'
		]);
	}

	public function resumen()
	{
		$this->respondNotImplemented('Reporte resumen');
	}

	public function porProveedor()
	{
		$this->respondNotImplemented('Reporte por proveedor');
	}

	public function porEstado()
	{
		$this->respondNotImplemented('Reporte por estado');
	}

	public function diferencias()
	{
		$this->respondNotImplemented('Reporte de diferencias');
	}

	public function exportar()
	{
		$this->respondNotImplemented('Exportación de reportes');
	}

	public function estadisticas()
	{
		$this->json([
			'success' => false,
			'message' => 'Endpoint no implementado aún'
		], 501);
	}

	private function respondNotImplemented($feature)
	{
		http_response_code(501);
		header('Content-Type: text/html; charset=utf-8');
		echo '<h1>501 - No implementado</h1>';
		echo '<p>' . htmlspecialchars($feature, ENT_QUOTES, 'UTF-8') . ' estará disponible en la siguiente iteración.</p>';
		exit;
	}
}
