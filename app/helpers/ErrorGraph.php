<?php
/**
 * Graph dijo que no, o no dijo nada.
 *
 * El mensaje es para quien usa la aplicación: qué pasó y, cuando se puede,
 * qué hacer. Lo que Microsoft contestó —en inglés y con códigos— queda en
 * detalle(), para el registro y para quien mantiene el sistema.
 *
 * El 409 de "ya existe" NO es esto: es una respuesta de negocio y sale como
 * AlmacenConflicto, que quien archiva sabe manejar.
 */
class ErrorGraph extends RuntimeException
{
    private $codigoHttp;
    private $codigoGraph;
    private $detalle;

    public function __construct($codigoHttp, $codigoGraph = '', $detalle = '', $mensaje = null)
    {
        $this->codigoHttp = (int) $codigoHttp;
        $this->codigoGraph = (string) $codigoGraph;
        $this->detalle = (string) $detalle;
        parent::__construct($mensaje ?? self::explicar($this->codigoHttp, $this->codigoGraph, $this->detalle));
    }

    /** Arma el error a partir de una respuesta cruda del transporte. */
    public static function deRespuesta(array $respuesta)
    {
        $json = json_decode((string) ($respuesta['cuerpo'] ?? ''), true);
        $codigoGraph = '';
        $detalle = (string) ($respuesta['error'] ?? '');
        if (is_array($json) && isset($json['error'])) {
            if (is_array($json['error'])) {
                $codigoGraph = (string) ($json['error']['code'] ?? '');
                $detalle = (string) ($json['error']['message'] ?? '');
            } else {
                // El de Entra ID (el token) viene plano: error + error_description.
                $codigoGraph = (string) $json['error'];
                $detalle = (string) ($json['error_description'] ?? '');
            }
        } elseif ($detalle === '') {
            $detalle = substr((string) ($respuesta['cuerpo'] ?? ''), 0, 300);
        }

        return new self((int) ($respuesta['codigo'] ?? 0), $codigoGraph, $detalle);
    }

    public function codigoHttp()
    {
        return $this->codigoHttp;
    }

    public function codigoGraph()
    {
        return $this->codigoGraph;
    }

    public function detalle()
    {
        return $this->detalle;
    }

    /** ¿Es cosa del momento —red, ritmo, Microsoft caído— y no de lo pedido? */
    public function esTransitorio()
    {
        return self::codigoTransitorio($this->codigoHttp);
    }

    public function noEncontrado()
    {
        return $this->codigoHttp === 404;
    }

    public static function codigoTransitorio($codigo)
    {
        return in_array((int) $codigo, [0, 429, 500, 502, 503, 504, 509], true);
    }

    private static function explicar($http, $graph, $detalle)
    {
        if ($http === 0) {
            $base = 'No se pudo conectar con Microsoft.';
            if (stripos($detalle, 'certificate') !== false || stripos($detalle, 'SSL') !== false) {
                return $base . ' A este PHP le falta el archivo de autoridades de certificación '
                    . '(curl.cainfo en php.ini); si se acaba de configurar, falta reiniciar Apache.';
            }
            return $base . ' Revisa la conexión a internet de este equipo.';
        }
        if (strpos($graph, 'invalid_client') !== false || stripos($detalle, 'AADSTS7000215') !== false) {
            return 'Microsoft no aceptó el secreto de la aplicación: está mal copiado o venció. '
                . 'Hay que generar uno nuevo en Entra ID y ponerlo en app/config/local.graph.php.';
        }
        if (stripos($detalle, 'AADSTS700016') !== false) {
            return 'La aplicación (client_id) no existe en ese tenant. Revisa app/config/local.graph.php.';
        }
        if (stripos($detalle, 'AADSTS90002') !== false) {
            return 'El tenant_id de app/config/local.graph.php no existe.';
        }
        switch ($http) {
            case 401:
                return 'Microsoft no aceptó las credenciales de la aplicación.';
            case 403:
                return 'La aplicación no tiene permiso sobre la biblioteca de SharePoint. Con Sites.Selected '
                    . 'hay que autorizarla sobre el sitio, con permiso de escritura.';
            case 404:
                return 'No está en SharePoint.';
            case 423:
                return 'El archivo está bloqueado en SharePoint: alguien lo tiene abierto.';
            case 429:
                return 'SharePoint pidió bajar el ritmo y no hubo tiempo de esperar. Se reintenta solo en la próxima vuelta.';
            case 507:
                return 'La biblioteca de SharePoint se quedó sin espacio. Hasta que se libere o se amplíe '
                    . 'la cuota no se puede archivar nada.';
        }
        if ($http >= 500) {
            return 'SharePoint no está respondiendo (HTTP ' . $http . '). Es de Microsoft, no de este equipo; '
                . 'se reintenta solo.';
        }
        return 'SharePoint rechazó la operación (HTTP ' . $http . ($graph !== '' ? ', ' . $graph : '') . ').';
    }
}
