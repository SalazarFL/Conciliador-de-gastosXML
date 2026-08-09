<?php
/**
 * Diagnóstico de la instalación de esta computadora.
 *
 * La misma revisión que `php cli/diagnostico.php`, en pantalla: quien reporta
 * un problema puede abrir esto y mandar una captura, sin que nadie tenga que
 * conectarse a su equipo.
 */

require_once __DIR__ . '/../helpers/Diagnostico.php';

class DiagnosticoController extends Controller
{
    public function __construct() { $this->requireAuth(); }

    public function index()
    {
        $this->render('diagnostico/index', [
            'informe' => (new Diagnostico())->ejecutar(),
        ]);
    }
}
