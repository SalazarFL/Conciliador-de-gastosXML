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

    public function __construct(ClienteGraph $graph, array $opciones = [])
    {
        $this->graph = $graph;
        $this->columnaHash = (string) ($opciones['columna_hash'] ?? 'HashSha256');
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
        if (!is_file($origenLocal)) {
            throw new RuntimeException('No está el archivo que se quiere archivar: ' . $origenLocal);
        }
        $clave = $this->clave($ruta);
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
     * Si la biblioteca contesta. El permiso de escritura no se prueba
     * escribiendo —ensuciaría la biblioteca con archivos de prueba—: lo da la
     * autorización de la aplicación sobre el sitio, y si falta, la primera
     * escritura lo dice con un 403 claro.
     */
    public function permiteEscritura($ruta = '')
    {
        if ($this->alcanzable === null) {
            try {
                $this->graph->pedir('GET', $this->graph->unidad() . '?$select=id');
                $this->alcanzable = true;
            } catch (Throwable $e) {
                $this->ultimoError = $e->getMessage();
                $this->alcanzable = false;
            }
        }
        return $this->alcanzable;
    }

    /**
     * Comprueba de verdad que se llega a la biblioteca y devuelve su nombre.
     * Es lo que se hace antes de pasar una empresa a SharePoint: el ajuste es
     * de todas las PC, así que quien lo cambia tiene que ver que funciona.
     *
     * @throws ErrorGraph Con el motivo, dicho para quien lo va a leer.
     */
    public function probar()
    {
        $r = $this->graph->pedir('GET', $this->graph->unidad() . '?$select=id,name,webUrl');
        return (string) ($r['json']['name'] ?? '');
    }

    public function direccion($rutaRelativa)
    {
        return self::PREFIJO . $this->clave(RutaDocumento::relativa($rutaRelativa));
    }

    public function recorreElArbolBarato()
    {
        return false;
    }

    public function tieneIdentidadPermanente()
    {
        return true;
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

    private function idRaiz()
    {
        if (!isset($this->carpetas[''])) {
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
                $this->hayColumnaHash = false;
                return $this->pedirItem($rutaApi, false);
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
            $this->hayColumnaHash = true;
        } catch (ErrorGraph $e) {
            if ($e->codigoHttp() === 400) {
                $this->hayColumnaHash = false;
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
