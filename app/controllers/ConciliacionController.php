<?php
/**
 * Controlador de conciliación de facturas y gastos
 */

class ConciliacionController extends Controller
{
	public function index()
	{
		$this->render('conciliacion/index', [
			'title' => 'Conciliación - XMLConcilia'
		]);
	}

	public function ejecutar()
	{
		$this->respondNotImplemented('Ejecución de conciliación');
	}

	public function resultados()
	{
		$this->render('conciliacion/resultado', [
			'title' => 'Resultados de Conciliación - XMLConcilia'
		]);
	}

	public function pendientes()
	{
		$this->respondNotImplemented('Listado de pendientes de revisión');
	}

	public function revisar($id)
	{
		$this->respondNotImplemented('Revisión manual de conciliación');
	}

	public function exportar()
	{
		$this->respondNotImplemented('Exportación de conciliación');
	}

	public function marcarRevisado()
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
