<?php
/**
 * Carga de documentos: la única puerta de entrada de archivos al sistema.
 *
 * Antes cada módulo tenía su propio formulario arriba de su tabla, y para
 * empezar una semana había que recorrer cuatro pantallas sabiendo de antemano
 * cuál pedía qué. Aquí están los cuatro en un solo lugar y en el orden en que
 * se usan: primero los listados del ERP, que son los que mandan, y después los
 * comprobantes electrónicos que los respaldan.
 *
 * Lo que NO está aquí, a propósito:
 *   - el pago semanal (/por-pagar) y las devoluciones (/devoluciones), que se
 *     cargan dentro del flujo de trabajo de su propio módulo;
 *   - la captura desde el correo (/correo), que no es una carga de archivos.
 *
 * Esta clase solo dibuja la pantalla. Cada POST sigue atendido por el
 * controlador dueño del modelo que escribe —FacturasErp, NotaCredito,
 * Factura— porque esos manejadores comparten helpers con el resto de su
 * módulo (la cola de XML, la verificación de semanas, el borrado de
 * auditoría); traerlos aquí obligaría a duplicarlos y a mantener dos copias.
 * Lo que se movió es lo que le importa a quien usa el sistema: dónde se
 * cargan las cosas.
 */

require_once __DIR__ . '/../helpers/DocumentoArchivo.php';

class CargaController extends Controller
{
    public function __construct() { $this->requireAuth(); }

    public function index()
    {
        $sociedad = null;
        try {
            $sociedad = $this->loadModel('Sociedad')->getActiva();
        } catch (Throwable $e) {
            // Sin sociedades registradas la pantalla lo dice y no deja cargar.
        }

        $semanas = [];
        $semanaActiva = $this->semanaActiva();
        try {
            $semanas = $this->loadModel('Semana')->getAll();
        } catch (Throwable $e) {
        }

        $this->render('carga/index', [
            'title'          => 'Carga de documentos - Nexo Fiscal',
            'sociedadActiva' => $sociedad,
            'semanas'        => $semanas,
            'semanaActiva'   => $semanaActiva,
            'carpetaRaiz'    => DocumentoArchivo::raizConfigurada(),
            'ultimas'        => $this->ultimasCargas($sociedad),
        ]);
    }

    /**
     * Qué se cargó de último en cada sitio. No es un historial: es la respuesta
     * a "¿ya subí el listado de esta semana?", que es lo que se pregunta quien
     * abre esta pantalla.
     */
    private function ultimasCargas($sociedad)
    {
        $ultimas = [
            'listado_facturas' => null,
            'listado_notas'    => null,
        ];

        try {
            $fila = $this->loadModel('FacturaErp')->ultimaCarga();
            if ($fila) {
                $ultimas['listado_facturas'] = [
                    'nombre' => (string) ($fila['archivo_origen'] ?? ''),
                    'fecha'  => (string) ($fila['creado_en'] ?? ''),
                    'lineas' => (int) ($fila['filas_leidas'] ?? 0),
                ];
            }
        } catch (Throwable $e) {
            $ultimas['listado_facturas'] = null;
        }

        try {
            $ultimo = $sociedad
                ? $this->loadModel('NotaCredito')->ultimaCarga((int) $sociedad['id'])
                : null;
            if ($ultimo) {
                $ultimas['listado_notas'] = [
                    'nombre' => (string) ($ultimo['archivo_origen'] ?? ''),
                    'fecha'  => (string) ($ultimo['creado_en'] ?? ''),
                    'lineas' => (int) ($ultimo['filas_leidas'] ?? 0),
                ];
            }
        } catch (Throwable $e) {
            $ultimas['listado_notas'] = null;
        }

        return $ultimas;
    }
}
