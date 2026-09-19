<?php
/**
 * Una biblioteca de SharePoint que vive en memoria y contesta como Graph.
 *
 * El guion (TransporteGuionado) sirve para ensayar lo que sale mal de a una
 * llamada por vez. Para el contrato del almacén —escribir, mover, copiar,
 * borrar, en cualquier orden— hace falta otra cosa: algo que se acuerde de lo
 * que se subió. Esto es eso: la parte de la API que usa AlmacenEnGraph, con
 * las mismas respuestas que da Microsoft —ids que no cambian al mover, 409
 * nameAlreadyExists, 404 itemNotFound, carpetas que se crean solas al subir—.
 *
 * También lleva la cuenta de los cambios, como el delta de SharePoint: cada
 * alta, edición, movimiento o borrado queda anotado, y /root/delta los
 * devuelve desde un enlace, por páginas, con 410 si el enlace venció. Como el
 * delta de verdad, NO trae parentReference.path: la ruta hay que pedirla.
 *
 * Lo que NO hace: permisos, sesiones de carga (eso lo prueba
 * SubidaGrandeTest con el guion), ni el ritmo.
 *
 * Cuenta las llamadas, para que una prueba pueda exigir "esto no sale a la red".
 */

require_once __DIR__ . '/../../app/helpers/TransporteGraph.php';

class GraphEnMemoria implements TransporteGraph
{
    /** id => ['id','nombre','padre','carpeta','contenido','ctag','hash'] */
    public $items = [];

    /** ¿La biblioteca tiene la columna HashSha256? La vieja no la tiene. */
    public $conColumnaHash = true;

    /** Cuántas llamadas llegaron, token incluido. */
    public $llamadas = 0;

    /** Lo que se pidió, en orden: "METODO ruta". */
    public $registro = [];

    private $siguiente = 1;

    /** secuencia => id de lo que cambió. */
    public $cambios = [];

    /** Lo que se borró, para contarlo en el delta. */
    public $borrados = [];

    /** SharePoint puede dar por vencido un enlace de cambios: 410. */
    public $enlaceVencido = false;

    /** Cambios por página del delta. */
    public $porPagina = 100;

    private $secuencia = 0;

    public function __construct()
    {
        $this->items['RAIZ'] = ['id' => 'RAIZ', 'nombre' => 'root', 'padre' => null, 'carpeta' => true,
            'contenido' => '', 'ctag' => 'c1', 'hash' => ''];
    }

    public function enviar(array $p)
    {
        $this->llamadas++;
        $url = (string) $p['url'];
        if (strpos($url, 'login.microsoftonline.com') !== false) {
            return $this->json(200, ['access_token' => 'token-de-memoria', 'expires_in' => 3599]);
        }
        $url = preg_replace('~^https://graph\.microsoft\.com/v1\.0~', '', $url);
        $this->registro[] = $p['metodo'] . ' ' . $url;

        if ($p['metodo'] === 'POST' && strtok($url, '?') === '/$batch') {
            $respuestas = [];
            foreach (json_decode($p['cuerpo'], true)['requests'] as $r) {
                $sub = $this->atender($r['method'], $r['url'],
                    isset($r['body']) ? json_encode($r['body']) : null, null);
                $respuestas[] = ['id' => $r['id'], 'status' => $sub['codigo'],
                    'body' => json_decode($sub['cuerpo'], true)];
            }
            return $this->json(200, ['responses' => $respuestas]);
        }
        return $this->atender($p['metodo'], $url, $p['cuerpo'] ?? null, $p['guardar_en'] ?? null);
    }

    private function atender($metodo, $url, $cuerpo, $guardarEn)
    {
        $consulta = (string) parse_url($url, PHP_URL_QUERY);
        $ruta = (string) parse_url($url, PHP_URL_PATH);
        if (!preg_match('~^/drives/[^/]+/(?:root(?::/(.*?):)?|items/([^/]+))(/.*)?$~', $ruta, $m)) {
            return $this->error(400, 'invalidRequest', 'Ruta que la biblioteca de memoria no conoce: ' . $ruta);
        }
        $porRuta = isset($m[1]) && $m[1] !== '' ? implode('/', array_map('rawurldecode', explode('/', $m[1]))) : null;
        $porId = isset($m[2]) && $m[2] !== '' ? rawurldecode($m[2]) : null;
        $resto = $m[3] ?? '';
        $id = $porId ?? ($porRuta === null ? 'RAIZ' : $this->buscar($porRuta));
        $datos = $cuerpo !== null ? json_decode($cuerpo, true) : null;

        // Subir: la única operación que acepta un destino que no existe.
        if ($metodo === 'PUT' && $resto === '/content') {
            if ($id !== null) {
                if (strpos($consulta, 'conflictBehavior=fail') !== false) {
                    return $this->error(409, 'nameAlreadyExists', 'The specified item name already exists.');
                }
                $this->items[$id]['contenido'] = (string) $cuerpo;
                $this->items[$id]['ctag'] = 'c' . $this->siguiente++;
                $this->cambio($id);
                return $this->json(200, $this->vista($id));
            }
            $partes = explode('/', $porRuta);
            $nombre = array_pop($partes);
            $padre = $this->carpetas($partes);
            $nuevo = $this->crear($padre, $nombre, false, (string) $cuerpo);
            return $this->json(201, $this->vista($nuevo));
        }

        if ($id === null || !isset($this->items[$id])) {
            return $this->error(404, 'itemNotFound', 'The resource could not be found.');
        }

        switch ($metodo . ' ' . $resto) {
            case 'GET /delta':
                return $this->delta($consulta);
            case 'GET ':
                if (strpos($consulta, 'listItem') !== false && !$this->conColumnaHash) {
                    return $this->error(400, 'invalidRequest', "Field 'HashSha256' is not recognized");
                }
                return $this->json(200, $this->vista($id, strpos($consulta, 'listItem') !== false));
            case 'GET /content':
                if (is_resource($guardarEn)) {
                    fwrite($guardarEn, $this->items[$id]['contenido']);
                    return ['codigo' => 200, 'cabeceras' => [], 'cuerpo' => '', 'error' => ''];
                }
                return ['codigo' => 200, 'cabeceras' => [], 'cuerpo' => $this->items[$id]['contenido'], 'error' => ''];
            case 'GET /children':
                $hijos = [];
                foreach ($this->items as $item) {
                    if ($item['padre'] === $id) {
                        $hijos[] = $this->vista($item['id']);
                    }
                }
                return $this->json(200, ['value' => $hijos]);
            case 'PATCH /listItem/fields':
                if (!$this->conColumnaHash) {
                    return $this->error(400, 'invalidRequest', "Field 'HashSha256' is not recognized");
                }
                $this->items[$id]['hash'] = (string) ($datos['HashSha256'] ?? '');
                return $this->json(200, ['HashSha256' => $this->items[$id]['hash']]);
            case 'PATCH ':
                $padre = $datos['parentReference']['id'] ?? $this->items[$id]['padre'];
                $nombre = $datos['name'] ?? $this->items[$id]['nombre'];
                $ocupado = $this->hijo($padre, $nombre);
                if ($ocupado !== null && $ocupado !== $id) {
                    return $this->error(409, 'nameAlreadyExists', 'The specified item name already exists.');
                }
                $this->items[$id]['padre'] = $padre;
                $this->items[$id]['nombre'] = $nombre;
                $this->cambio($id);
                return $this->json(200, $this->vista($id));
            case 'DELETE ':
                $this->borrarArbol($id);
                return ['codigo' => 204, 'cabeceras' => [], 'cuerpo' => '', 'error' => ''];
            case 'POST /children':
                if ($this->hijo($id, $datos['name']) !== null) {
                    return $this->error(409, 'nameAlreadyExists', 'The specified item name already exists.');
                }
                return $this->json(201, $this->vista($this->crear($id, $datos['name'], true, '')));
        }
        return $this->error(400, 'invalidRequest', 'No implementado en memoria: ' . $metodo . ' ' . $resto);
    }

    // ── Lo que una prueba quiere mirar sin pasar por la API ─────────────

    /** El contenido que hay en una ruta, o null. */
    public function contenido($ruta)
    {
        $id = $this->buscar($ruta);
        return $id === null ? null : $this->items[$id]['contenido'];
    }

    public function hashDeclarado($ruta)
    {
        $id = $this->buscar($ruta);
        return $id === null ? null : $this->items[$id]['hash'];
    }

    /** Lo que haría una persona en el navegador: mover a mano, sin la app. */
    public function moverAMano($ruta, $carpetaDestino)
    {
        $id = $this->buscar($ruta);
        $this->items[$id]['padre'] = $this->carpetas(explode('/', $carpetaDestino));
        $this->cambio($id);
        return $id;
    }

    /**
     * Cambiarle el nombre a una carpeta. Como SharePoint, avisa de la carpeta
     * y no de cada cosa que tiene dentro.
     */
    public function renombrarAMano($ruta, $nombreNuevo)
    {
        $id = $this->buscar($ruta);
        $this->items[$id]['nombre'] = $nombreNuevo;
        $this->cambio($id);
        return $id;
    }

    /** Mandar a la papelera, desde el navegador. */
    public function borrarAMano($ruta)
    {
        $id = $this->buscar($ruta);
        $this->borrarArbol($id);
        return $id;
    }

    /** Sacarlo de la papelera: vuelve con su mismo id, a su misma carpeta. */
    public function restaurarAMano($id)
    {
        $this->items[$id] = $this->borrados[$id];
        unset($this->borrados[$id]);
        $this->cambio($id);
    }

    /** Abrirlo en el navegador y guardar otra cosa encima. */
    public function editarAMano($ruta, $contenido)
    {
        $id = $this->buscar($ruta);
        $this->items[$id]['contenido'] = (string) $contenido;
        $this->items[$id]['ctag'] = 'c' . $this->siguiente++;
        $this->cambio($id);
        return $id;
    }

    public function buscar($ruta)
    {
        $actual = 'RAIZ';
        foreach (array_filter(explode('/', str_replace('\\', '/', (string) $ruta)), 'strlen') as $nombre) {
            $actual = $this->hijo($actual, $nombre);
            if ($actual === null) {
                return null;
            }
        }
        return $actual;
    }

    // ── Interno ─────────────────────────────────────────────────────────

    private function hijo($padre, $nombre)
    {
        foreach ($this->items as $item) {
            // SharePoint no distingue mayúsculas en los nombres.
            if ($item['padre'] === $padre && strcasecmp($item['nombre'], $nombre) === 0) {
                return $item['id'];
            }
        }
        return null;
    }

    private function carpetas(array $partes)
    {
        $actual = 'RAIZ';
        foreach (array_filter($partes, 'strlen') as $nombre) {
            $actual = $this->hijo($actual, $nombre) ?? $this->crear($actual, $nombre, true, '');
        }
        return $actual;
    }

    private function crear($padre, $nombre, $carpeta, $contenido)
    {
        $id = 'ITEM' . str_pad((string) $this->siguiente++, 4, '0', STR_PAD_LEFT);
        $this->items[$id] = ['id' => $id, 'nombre' => $nombre, 'padre' => $padre, 'carpeta' => $carpeta,
            'contenido' => $contenido, 'ctag' => 'c' . $this->siguiente++, 'hash' => ''];
        $this->cambio($id);
        return $id;
    }

    private function borrarArbol($id)
    {
        foreach ($this->items as $item) {
            if ($item['padre'] === $id) {
                $this->borrarArbol($item['id']);
            }
        }
        $this->borrados[$id] = $this->items[$id];
        unset($this->items[$id]);
        $this->cambio($id);
    }

    private function cambio($id)
    {
        $this->cambios[++$this->secuencia] = $id;
    }

    private function enlace($token, $desde = 0)
    {
        return 'https://graph.microsoft.com/v1.0/drives/d/root/delta?token=' . $token
            . ($desde > 0 ? '&desde=' . $desde : '');
    }

    private function delta($consulta)
    {
        parse_str($consulta, $q);
        if ($this->enlaceVencido && ($q['token'] ?? '') !== 'latest') {
            return $this->error(410, 'resyncRequired', 'Resync required. Replace any local items with the server version.');
        }
        $token = $q['token'] ?? null;
        if ($token === 'latest') {
            return $this->json(200, ['value' => [], '@odata.deltaLink' => $this->enlace($this->secuencia)]);
        }
        if ($token === null) {
            $ids = array_keys($this->items);   // la enumeración inicial: todo
        } else {
            $ultimos = [];
            foreach ($this->cambios as $secuencia => $id) {
                if ($secuencia > (int) $token) {
                    unset($ultimos[$id]);
                    $ultimos[$id] = true;   // al final: el orden es el del último cambio
                }
            }
            $ids = array_keys($ultimos);
        }
        $desde = (int) ($q['desde'] ?? 0);
        $valor = [];
        foreach (array_slice($ids, $desde, $this->porPagina) as $id) {
            if (isset($this->items[$id])) {
                $vista = $this->vista($id);
                unset($vista['parentReference']['path']);   // el delta no la trae
                if ($id === 'RAIZ') {
                    $vista['root'] = new stdClass();
                }
                $valor[] = $vista;
            } else {
                $b = $this->borrados[$id] ?? ['carpeta' => false];
                $valor[] = ['id' => $id, 'deleted' => ['state' => 'deleted'],
                            ($b['carpeta'] ? 'folder' : 'file') => new stdClass()];
            }
        }
        $datos = ['value' => $valor];
        if ($desde + $this->porPagina < count($ids)) {
            $datos['@odata.nextLink'] = $this->enlace((string) $token, $desde + $this->porPagina);
        } else {
            $datos['@odata.deltaLink'] = $this->enlace($this->secuencia);
        }
        return $this->json(200, $datos);
    }

    /** '/drives/d/root:/SISTEMA/EMPRESA', con cada tramo codificado, como Graph. */
    private function rutaDelPadre($padre)
    {
        $tramos = [];
        for ($actual = $padre; $actual !== null && $actual !== 'RAIZ' && isset($this->items[$actual]);
             $actual = $this->items[$actual]['padre']) {
            array_unshift($tramos, rawurlencode($this->items[$actual]['nombre']));
        }
        return '/drives/d/root:' . ($tramos ? '/' . implode('/', $tramos) : '');
    }

    private function vista($id, $conCampos = false)
    {
        $i = $this->items[$id];
        $vista = [
            'id' => $i['id'],
            'name' => $i['nombre'],
            'cTag' => $i['ctag'],
            'eTag' => 'e' . $i['ctag'],
            'size' => strlen($i['contenido']),
            'lastModifiedDateTime' => '2026-09-18T12:00:00Z',
            'parentReference' => $i['padre'] === null
                ? []
                : ['id' => $i['padre'], 'path' => $this->rutaDelPadre($i['padre'])],
        ];
        $vista[$i['carpeta'] ? 'folder' : 'file'] = $i['carpeta'] ? ['childCount' => 0] : ['mimeType' => 'application/octet-stream'];
        if ($conCampos) {
            $vista['listItem'] = ['fields' => $i['hash'] !== '' ? ['HashSha256' => $i['hash']] : []];
        }
        return $vista;
    }

    private function json($codigo, array $datos)
    {
        return ['codigo' => $codigo, 'cabeceras' => ['content-type' => 'application/json'],
            'cuerpo' => json_encode($datos), 'error' => ''];
    }

    private function error($codigo, $codigoGraph, $mensaje)
    {
        return $this->json($codigo, ['error' => ['code' => $codigoGraph, 'message' => $mensaje]]);
    }
}
