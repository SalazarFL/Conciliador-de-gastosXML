<?php
/**
 * Los documentos en una biblioteca de SharePoint, hablándole por Microsoft
 * Graph y no por una carpeta sincronizada.
 *
 * Es la razón de ser de la puerta: con esto, SharePoint es el original y no
 * una copia que OneDrive baja cuando quiere. No depende de que nadie tenga la
 * sesión iniciada, no hay marcadores "solo en la nube" que Apache no puede
 * leer y cada documento tiene un id que sobrevive a que alguien lo mueva.
 *
 * ── Cómo se nombra un documento ──
 *
 * La base guarda la misma ruta relativa de siempre (SISTEMA/EMPRESA/2026/…) y,
 * al leerla, se le antepone `sharepoint:`. Ese prefijo es lo que hace que
 * AlmacenDocumentos::de() sepa a quién pertenece una dirección sin mirar la
 * fila. Una ruta relativa sin prefijo sigue siendo de disco, como siempre: si
 * no, en una PC con credenciales, cualquier ruta relativa que se le escapara a
 * alguien saldría a la red en vez de ir al disco.
 *
 * ── Lo que cuesta cada cosa ──
 *
 * Desde la oficina, cada llamada tarda entre medio segundo y un segundo. De
 * ahí tres reglas:
 *
 *   - Los listados NO preguntan a la red si un documento está: contestan con
 *     lo que la fila ya sabe (existeEnFila). Cien filas serían minutos.
 *   - Lo que se averigua de un documento se recuerda durante el proceso.
 *   - Las carpetas no se crean de antemano: SharePoint crea las del camino
 *     al subir. asegurarCarpeta() solo sale a la red cuando de verdad hace
 *     falta una carpeta vacía o su id (mover).
 *
 * ── El hash ──
 *
 * SharePoint calcula su propio hash (quickXorHash), no SHA-256, que es el que
 * guarda la base. Por eso la biblioteca lleva una columna de texto HashSha256
 * y cada escritura la llena. Es una DECLARACIÓN, no una prueba: lo que se
 * declara es lo que se calculó al subir. Si la biblioteca no tiene la columna,
 * todo funciona igual y el hash queda "no se sabe"; para decidir si dos
 * documentos son el mismo se baja y se compara.
 */

require_once __DIR__ . '/AlmacenDocumentos.php';
require_once __DIR__ . '/ClienteGraph.php';

class AlmacenEnGraph extends AlmacenDocumentos
{
    public const TIPO = 'sharepoint';
    public const PREFIJO = 'sharepoint:';

    /**
     * Lo único que se sube. La biblioteca está para que todas las PC de una
     * empresa vean sus comprobantes; lo demás —respaldos de la base, los
     * Excel y CSV que se cargan, cachés, temporales— es de la computadora que
     * lo usa y se queda en ella.
     */
    public const EXTENSIONES = ['xml', 'pdf'];

    /** @var ClienteGraph */
    private $graph;

    private $columnaHash;

    /** ¿La biblioteca tiene la columna del hash? null = todavía no se sabe. */
    private $hayColumnaHash = null;

    /** clave => driveItem, o false si se sabe que no está. Por proceso. */
    private $conocidos = [];

    /** clave de carpeta => id. */
    private $carpetas = [];

    /** Lo último que salió mal, para porQueNoSeLee(). */
    private $ultimoError = '';

    private $alcanzable = null;

    /**
     * Cuánto se da por sabido que la biblioteca NO tiene la columna del hash.
     * Sin recordarlo, cada petición lo volvía a averiguar con un PATCH que
     * contesta 400 —medio segundo, en cada comprobante que se guarda—; y si
     * alguien agrega la columna, a lo sumo en este rato se empieza a usar.
     */
    private const RECORDAR_SIN_COLUMNA = 1800;

    public function __construct(ClienteGraph $graph, array $opciones = [])
    {
        $this->graph = $graph;
        $this->columnaHash = (string) ($opciones['columna_hash'] ?? 'HashSha256');
        if ($graph->recordado('sin_columna_hash', self::RECORDAR_SIN_COLUMNA) === true) {
            $this->hayColumnaHash = false;
        }
    }

    /** Anota si la biblioteca tiene la columna, aquí y para las próximas peticiones. */
    private function saberColumnaHash($hay)
    {
        $this->hayColumnaHash = (bool) $hay;
        $this->graph->recordar('sin_columna_hash', $hay ? null : true);
    }

    /**
     * Olvida lo que se sabía de la columna y lo vuelve a averiguar. Para
     * quien acaba de agregarla y quiere comprobarlo ya (probar_sharepoint).
     */
    public function olvidarColumnaHash()
    {
        $this->hayColumnaHash = null;
        $this->graph->recordar('sin_columna_hash', null);
    }

    public function cliente()
    {
        return $this->graph;
    }

    /** Cómo se llama en la biblioteca la columna del SHA-256. */
    public function columnaHash()
    {
        return $this->columnaHash;
    }

    /** ¿La biblioteca tiene la columna del hash? null mientras no se sepa. */
    public function tieneColumnaHash()
    {
        return $this->hayColumnaHash;
    }

    // ── Leer ──────────────────────────────────────────────────────────────

    public function existe($ruta)
    {
        return $this->identidad($ruta) !== null;
    }

    /**
     * Lo que usan los listados: si la fila no está marcada como faltante, el
     * documento está. La marca la pone y la quita el reconciliador, que es el
     * único que puede permitirse preguntarle a SharePoint por todo.
     */
    public function existeEnFila(array $fila, $campo)
    {
        $ruta = trim((string) ($fila[$campo] ?? ''));
        return $ruta !== '' && empty($fila['archivo_faltante_en']);
    }

    public function abrir($ruta)
    {
        $destino = fopen('php://temp/maxmemory:2097152', 'w+b');
        try {
            $esta = $this->graph->bajar($this->graph->rutaItem($this->clave($ruta)), $destino);
        } catch (Throwable $e) {
            $this->ultimoError = $e->getMessage();
            fclose($destino);
            return false;
        }
        if (!$esta) {
            fclose($destino);
            $this->ultimoError = '';
            return false;
        }
        rewind($destino);
        return $destino;
    }

    public function tamano($ruta)
    {
        $item = $this->identidad($ruta);
        return $item === null ? null : (int) $item['tamano'];
    }

    public function porQueNoSeLee($ruta)
    {
        if ($this->ultimoError !== '') {
            return $this->ultimoError;
        }
        return 'No está en la biblioteca de SharePoint: alguien lo movió o lo borró desde el navegador. '
            . 'Si se borró, está en la papelera del sitio durante 93 días.';
    }

    public function descripcion()
    {
        return 'la biblioteca de SharePoint';
    }

    /**
     * @return array|null item_id, etag (cTag: cambia si cambia el contenido),
     *         tamano, modificado_en y hash (el declarado, '' si no hay).
     */
    public function identidad($ruta)
    {
        $clave = $this->clave($ruta);
        if (array_key_exists($clave, $this->conocidos)) {
            return $this->conocidos[$clave] ?: null;
        }

        $item = $this->pedirItem($this->graph->rutaItem($clave));
        $this->conocidos[$clave] = $item === null ? false : $item;

        return $item;
    }

    // ── Escribir ──────────────────────────────────────────────────────────

    public function escribir($ruta, $origenLocal, array $opciones = [])
    {
        $clave = $this->clave($ruta);
        // Antes que nada, y sin salir a la red: un archivo que no es un
        // comprobante no tiene nada que hacer en la biblioteca.
        if (!self::esComprobante($clave)) {
            throw new InvalidArgumentException('A SharePoint solo van XML y PDF: "' . basename($clave)
                . '" se queda en esta computadora.');
        }
        if (!is_file($origenLocal)) {
            throw new RuntimeException('No está el archivo que se quiere archivar: ' . $origenLocal);
        }
        $hash = (string) ($opciones['hash'] ?? hash_file('sha256', $origenLocal));

        try {
            $subido = $this->graph->subir($clave, $origenLocal, 'fail', ['ruta' => $clave]);
        } catch (AlmacenConflicto $e) {
            // Ya hay algo con ese nombre. Si es lo mismo, archivar dos veces
            // no es un error; si es otra cosa, que decida quien llama.
            unset($this->conocidos[$clave]);
            $existente = $this->identidad($clave);
            if ($existente === null) {
                throw $e;   // lo borraron entre medio: que se reintente entero
            }
            $hashExistente = $this->hashDe($clave, $existente);
            if ($hashExistente === $hash) {
                return $this->resultado($clave, $hash, false, $existente);
            }
            throw new AlmacenConflicto(self::PREFIJO . $clave, $hashExistente);
        }

        $item = $this->deItem($subido);
        $this->declararHash($item['item_id'], $hash);
        $item['hash'] = $this->hayColumnaHash ? $hash : '';
        $this->conocidos[$clave] = $item;

        return $this->resultado($clave, $hash, true, $item);
    }

    /**
     * Un PATCH que cambia la carpeta y el nombre de una vez. El id no cambia:
     * es lo que hace que mover en SharePoint no rompa nada.
     */
    public function mover($rutaOrigen, $rutaDestino)
    {
        $origen = $this->clave($rutaOrigen);
        $destino = $this->clave($rutaDestino);
        if ($origen === $destino) {
            return self::PREFIJO . $destino;
        }
        $item = $this->identidad($origen);
        if ($item === null) {
            throw new RuntimeException('No está el documento que se quiere mover: ' . $origen);
        }

        $padre = $this->idCarpeta($this->padreDe($destino));
        $this->graph->pedir('PATCH', $this->graph->unidad() . '/items/' . rawurlencode($item['item_id']), [
            'json' => ['parentReference' => ['id' => $padre], 'name' => basename($destino)],
            'ruta' => self::PREFIJO . $destino,
        ]);
        unset($this->conocidos[$origen], $this->conocidos[$destino]);

        return self::PREFIJO . $destino;
    }

    /**
     * Baja y vuelve a subir, en vez de POST /copy.
     *
     * La copia del lado de SharePoint es asíncrona: devuelve un monitor que
     * hay que sondear hasta que termine, y un POST que no se sabe si llegó no
     * se puede repetir sin arriesgar dos copias. Con 81 KB de promedio, bajar
     * y subir son dos llamadas y se sabe exactamente qué pasó.
     */
    public function copiar($rutaOrigen, $rutaDestino)
    {
        $local = $this->rutaLocal($rutaOrigen);
        if ($local === null) {
            throw new RuntimeException('No está el documento que se quiere copiar: ' . $this->clave($rutaOrigen));
        }
        try {
            return $this->escribir($rutaDestino, $local);
        } finally {
            $this->soltarLocal($local);
        }
    }

    /**
     * Va a la papelera del sitio, 93 días: mejor que unlink(), que no tiene
     * vuelta atrás.
     */
    public function borrar($ruta = '', $itemId = '')
    {
        $clave = $ruta !== '' ? $this->clave($ruta) : '';
        $destino = $itemId !== ''
            ? $this->graph->unidad() . '/items/' . rawurlencode($itemId)
            : $this->graph->rutaItem($clave);
        $r = $this->graph->pedir('DELETE', $destino, ['aceptar' => [404]]);
        if ($clave !== '') {
            $this->conocidos[$clave] = false;
        }
        if ($itemId !== '') {
            foreach ($this->conocidos as $k => $item) {
                if ($item && $item['item_id'] === $itemId) {
                    $this->conocidos[$k] = false;
                }
            }
        }
        return $r['codigo'] !== 404;
    }

    public function asegurarCarpeta($ruta)
    {
        $clave = $this->clave($ruta);
        if ($clave !== '') {
            $this->idCarpeta($clave);
        }
        return self::PREFIJO . $clave;
    }

    public function listarCarpeta($ruta)
    {
        $url = $this->graph->rutaItem($this->clave($ruta))
            . '/children?$select=id,name,size,folder,file,lastModifiedDateTime&$top=999';
        $lista = [];
        while ($url !== '') {
            $r = $this->graph->pedir('GET', $url, ['aceptar' => [404]]);
            if ($r['codigo'] === 404) {
                return [];
            }
            foreach (($r['json']['value'] ?? []) as $hijo) {
                $lista[] = [
                    'nombre' => (string) $hijo['name'],
                    'es_carpeta' => isset($hijo['folder']),
                    'tamano' => (int) ($hijo['size'] ?? 0),
                    'item_id' => (string) $hijo['id'],
                    'modificado_en' => (string) ($hijo['lastModifiedDateTime'] ?? ''),
                ];
            }
            $url = (string) ($r['json']['@odata.nextLink'] ?? '');
        }
        return $lista;
    }

    /**
     * Si se llega a donde vive Nexo Fiscal. El permiso de escritura no se
     * prueba escribiendo —ensuciaría la biblioteca con archivos de prueba—:
     * lo da la autorización de la aplicación, y si falta, la primera
     * escritura lo dice con un 403 claro.
     *
     * Se pregunta por la carpeta de Nexo Fiscal y no por la biblioteca: con
     * permiso solo sobre la carpeta, la biblioteca contesta 403 aunque todo
     * funcione.
     */
    public function permiteEscritura($ruta = '')
    {
        if ($this->alcanzable === null) {
            try {
                if ($this->graph->carpeta() === '') {
                    $this->graph->pedir('GET', $this->graph->raizApi() . '?$select=id');
                } else {
                    $this->graph->verificarCarpeta();
                }
                $this->alcanzable = true;
            } catch (Throwable $e) {
                $this->ultimoError = $e->getMessage();
                $this->alcanzable = false;
            }
        }
        return $this->alcanzable;
    }

    /**
     * Comprueba de verdad que se llega y devuelve dónde, para decirlo en
     * pantalla: el nombre de la biblioteca o la ruta de la carpeta.
     *
     * @throws ErrorGraph Con el motivo, dicho para quien lo va a leer.
     */
    public function probar()
    {
        if ($this->graph->carpeta() === '') {
            $r = $this->graph->pedir('GET', $this->graph->unidad() . '?$select=id,name,webUrl');
            return 'biblioteca "' . (string) ($r['json']['name'] ?? '') . '"';
        }
        $actual = $this->rutaDeItem($this->graph->verificarCarpeta());

        return 'carpeta "' . ($actual !== null ? $actual : $this->graph->carpeta()) . '"';
    }

    /**
     * La ruta de la carpeta de Nexo Fiscal como la tiene HOY SharePoint, o
     * null si no se pudo saber. Alguien pudo renombrarla o moverla: el id
     * sigue sirviendo, pero la ruta de la configuración ya no.
     */
    public function rutaActualDeLaCarpeta()
    {
        if ($this->graph->carpeta() === '') {
            return '';
        }
        try {
            return $this->rutaDeItem($this->graph->verificarCarpeta());
        } catch (Throwable $e) {
            return null;
        }
    }

    /** '/drives/{id}/root:/A/B' + nombre → 'A/B/nombre', desde la raíz de la biblioteca. */
    private function rutaDeItem(array $item)
    {
        $padre = $item['parentReference']['path'] ?? null;
        $nombre = (string) ($item['name'] ?? '');
        if (!is_string($padre) || $nombre === '' || ($pos = strpos($padre, 'root:')) === false) {
            return null;
        }
        return ltrim(trim(rawurldecode(substr($padre, $pos + 5)), '/') . '/' . $nombre, '/');
    }

    public function direccion($rutaRelativa)
    {
        return self::PREFIJO . $this->clave(RutaDocumento::relativa($rutaRelativa));
    }

    public function recorreElArbolBarato()
    {
        return false;
    }

    /** La subida crea las carpetas del camino: ver ClienteGraph::subir. */
    public function creaCarpetasAlEscribir()
    {
        return true;
    }

    public function tieneIdentidadPermanente()
    {
        return true;
    }

    /** ¿Es un XML o un PDF? Por la extensión, sin importar mayúsculas. */
    public static function esComprobante($ruta)
    {
        $extension = strtolower((string) pathinfo(str_replace('\\', '/', (string) $ruta), PATHINFO_EXTENSION));

        return in_array($extension, self::EXTENSIONES, true);
    }

    // ── Interno ───────────────────────────────────────────────────────────

    /**
     * La ruta dentro de la biblioteca: sin prefijo, con '/' y sin barras en
     * las puntas. Una ruta de disco acá es un error de quien llama, y se dice.
     */
    private function clave($ruta)
    {
        $ruta = (string) $ruta;
        if (stripos($ruta, self::PREFIJO) === 0) {
            $ruta = substr($ruta, strlen(self::PREFIJO));
        } elseif (RutaDocumento::esAbsoluta($ruta)) {
            throw new InvalidArgumentException('Se le pidió a SharePoint una ruta de disco: ' . $ruta);
        }
        return trim(str_replace('\\', '/', $ruta), '/');
    }

    private function padreDe($clave)
    {
        $padre = dirname($clave);
        return $padre === '.' ? '' : $padre;
    }

    /** El id de una carpeta, creándola —y las de su camino— si hace falta. */
    private function idCarpeta($clave)
    {
        if ($clave === '') {
            return $this->idRaiz();
        }
        if (isset($this->carpetas[$clave])) {
            return $this->carpetas[$clave];
        }

        // Lo común es que ya exista: una sola pregunta.
        $item = $this->pedirItem($this->graph->rutaItem($clave), false);
        if ($item !== null) {
            return $this->carpetas[$clave] = $item['item_id'];
        }

        $padre = $this->idCarpeta($this->padreDe($clave));
        // Lo que se sabía de esa ruta —que no estaba— deja de ser cierto.
        unset($this->conocidos[$clave]);
        try {
            $r = $this->graph->pedir('POST', $this->graph->unidad() . '/items/' . rawurlencode($padre) . '/children', [
                'json' => ['name' => basename($clave), 'folder' => new stdClass(),
                           '@microsoft.graph.conflictBehavior' => 'fail'],
                // Crear una carpeta dos veces da 409, que se maneja abajo.
                'idempotente' => true,
                'ruta' => self::PREFIJO . $clave,
            ]);
            return $this->carpetas[$clave] = (string) $r['json']['id'];
        } catch (AlmacenConflicto $e) {
            // Otra PC la creó en el mismo instante: sirve igual.
            $item = $this->pedirItem($this->graph->rutaItem($clave), false);
            if ($item === null) {
                throw $e;
            }
            return $this->carpetas[$clave] = $item['item_id'];
        }
    }

    /** El id de donde vive Nexo Fiscal: la raíz de la biblioteca o su carpeta. */
    private function idRaiz()
    {
        if (!isset($this->carpetas[''])) {
            if ($this->graph->carpeta() !== '') {
                return $this->carpetas[''] = $this->graph->idCarpeta();
            }
            $r = $this->graph->pedir('GET', $this->graph->unidad() . '/root?$select=id');
            $this->carpetas[''] = (string) $r['json']['id'];
        }
        return $this->carpetas[''];
    }

    /**
     * Un driveItem traducido a lo que el sistema entiende, o null si no está.
     * Pide también el hash declarado, salvo que ya se sepa que la biblioteca
     * no tiene la columna.
     */
    private function pedirItem($rutaApi, $conHash = true)
    {
        $campos = '?$select=id,name,size,cTag,eTag,lastModifiedDateTime,file,folder';
        $conHash = $conHash && $this->hayColumnaHash !== false;
        $expandir = $conHash
            ? '&$expand=listItem($expand=fields($select=' . $this->columnaHash . '))'
            : '';
        try {
            $r = $this->graph->pedir('GET', $rutaApi . $campos . $expandir, ['aceptar' => [404]]);
        } catch (ErrorGraph $e) {
            // Sin la columna, Graph rechaza la pregunta entera. Se anota y se
            // sigue sin ella: el hash pasa a "no se sabe".
            if ($conHash && $e->codigoHttp() === 400) {
                $this->saberColumnaHash(false);
                return $this->pedirItem($rutaApi, false);
            }
            // Con permiso sobre una carpeta y no sobre la biblioteca, las
            // columnas de la biblioteca pueden quedar fuera de alcance: 403.
            // Si sin ellas contesta, era eso; si no, el 403 es del documento.
            if ($conHash && $e->codigoHttp() === 403) {
                $item = $this->pedirItem($rutaApi, false);
                $this->saberColumnaHash(false);
                return $item;
            }
            $this->ultimoError = $e->getMessage();
            throw $e;
        }
        if ($r['codigo'] === 404) {
            return null;
        }
        $item = $this->deItem((array) $r['json']);
        if ($conHash) {
            $item['hash'] = (string) ($r['json']['listItem']['fields'][$this->columnaHash] ?? '');
        }
        return $item;
    }

    private function deItem(array $json)
    {
        return [
            'item_id' => (string) ($json['id'] ?? ''),
            'etag' => (string) ($json['cTag'] ?? ($json['eTag'] ?? '')),
            'tamano' => (int) ($json['size'] ?? 0),
            'modificado_en' => (string) ($json['lastModifiedDateTime'] ?? ''),
            'hash' => '',
        ];
    }

    /** El SHA-256 de lo que hay: el declarado o, si no hay, bajándolo. */
    private function hashDe($clave, array $item)
    {
        if ($item['hash'] !== '') {
            return $item['hash'];
        }
        $fp = $this->abrir($clave);
        if ($fp === false) {
            return '';
        }
        $contexto = hash_init('sha256');
        hash_update_stream($contexto, $fp);
        fclose($fp);
        $hash = hash_final($contexto);
        // De paso se completa la columna: la próxima vez no hay que bajarlo.
        $this->declararHash($item['item_id'], $hash);
        return $hash;
    }

    private function declararHash($itemId, $hash)
    {
        if ($this->hayColumnaHash === false || $itemId === '') {
            return;
        }
        try {
            $this->graph->pedir('PATCH',
                $this->graph->unidad() . '/items/' . rawurlencode($itemId) . '/listItem/fields',
                ['json' => [$this->columnaHash => $hash], 'idempotente' => true]);
            if ($this->hayColumnaHash !== true) {
                $this->saberColumnaHash(true);
            }
        } catch (ErrorGraph $e) {
            // 400: la biblioteca no tiene la columna. 403: la tiene, pero el
            // permiso de la aplicación no alcanza a sus columnas.
            if (in_array($e->codigoHttp(), [400, 403], true)) {
                $this->saberColumnaHash(false);
                return;
            }
            // El documento ya se subió entero; perder la declaración no lo
            // deshace. Queda "no se sabe" y se completa la próxima vez.
        }
    }

    private function resultado($clave, $hash, $creado, array $item)
    {
        return [
            'ruta' => self::PREFIJO . $clave,
            'hash' => $hash,
            'creado' => $creado,
            'tamano' => (int) $item['tamano'],
            'item_id' => $item['item_id'],
            'etag' => $item['etag'],
        ];
    }
}
