<?php
/**
 * Un almacén de documentos que vive en memoria, para las pruebas.
 *
 * No existe ningún archivo detrás. Si algo contesta bien con esto puesto, es
 * que preguntó por la puerta (AlmacenDocumentos) y no se fue al disco por su
 * cuenta —que es el camino que se rompe el día que los documentos estén en
 * SharePoint, y se rompe en silencio, diciendo "archivo perdido" de algo que
 * está—.
 *
 * Vive acá y no dentro de una prueba porque lo usan varias: la de la puerta y
 * la del contrato, que corre las mismas aserciones contra este almacén y
 * contra el de disco para comprobar que se comportan igual.
 */

require_once __DIR__ . '/../../app/helpers/AlmacenDocumentos.php';

/**
 * Un almacén que vive en memoria. No existe ningún archivo detrás: si algo
 * contesta bien con esto puesto, es que preguntó por la puerta.
 */
class AlmacenDePrueba extends AlmacenDocumentos
{
    public $documentos = [];   // ruta => contenido

    /**
     * Con qué prefijo se presentan sus direcciones. Vacío por omisión, que es
     * como lo usan las pruebas que lo fijan para todo; 'sharepoint:' para
     * hacer de SharePoint al lado del disco.
     */
    public $prefijo = '';
    public $enLaNube = [];     // rutas que existen pero no sueltan contenido
    public $soltados = [];

    public function existe($ruta)
    {
        $ruta = $this->clave($ruta);
        return isset($this->documentos[$ruta]) || in_array($ruta, $this->enLaNube, true);
    }

    public function abrir($ruta)
    {
        $ruta = $this->clave($ruta);
        if (!isset($this->documentos[$ruta])) {
            return false;
        }
        $fp = fopen('php://memory', 'r+');
        fwrite($fp, $this->documentos[$ruta]);
        rewind($fp);
        return $fp;
    }

    public function tamano($ruta)
    {
        $ruta = $this->clave($ruta);
        return isset($this->documentos[$ruta]) ? strlen($this->documentos[$ruta]) : null;
    }

    // rutaLocal() NO se reemplaza: la heredada es la que va a usar cualquier
    // almacén remoto —baja por abrir() a storage/tmp/almacen—, y es la que
    // hay que ejercitar.

    public function soltarLocal($rutaLocal)
    {
        $this->soltados[] = $rutaLocal;
        parent::soltarLocal($rutaLocal);
    }

    public function porQueNoSeLee($ruta)
    {
        return 'Está en el almacén de prueba y no suelta contenido.';
    }

    public function descripcion()
    {
        return 'el almacén de prueba';
    }

    // ── Escribir, también en memoria ─────────────────────────────────────

    public function escribir($ruta, $origenLocal, array $opciones = [])
    {
        if (!is_file($origenLocal)) {
            throw new RuntimeException('No está el archivo de origen.');
        }
        $contenido = (string) file_get_contents($origenLocal);
        $hash = (string) ($opciones['hash'] ?? hash('sha256', $contenido));
        $clave = $this->clave($ruta);

        if (isset($this->documentos[$clave])) {
            $hashExistente = hash('sha256', $this->documentos[$clave]);
            if ($hashExistente === $hash) {
                return $this->datos($clave, $hash, false);
            }
            throw new AlmacenConflicto($clave, $hashExistente);
        }

        $this->documentos[$clave] = $contenido;

        return $this->datos($clave, $hash, true);
    }

    public function mover($rutaOrigen, $rutaDestino)
    {
        $origen = $this->clave($rutaOrigen);
        $destino = $this->clave($rutaDestino);
        if (!isset($this->documentos[$origen])) {
            throw new RuntimeException('No está el archivo que se quiere mover.');
        }
        if ($origen === $destino) {
            return $destino;
        }
        if (isset($this->documentos[$destino])) {
            throw new AlmacenConflicto($destino, hash('sha256', $this->documentos[$destino]));
        }
        $this->documentos[$destino] = $this->documentos[$origen];
        unset($this->documentos[$origen]);

        return $destino;
    }

    public function copiar($rutaOrigen, $rutaDestino)
    {
        $origen = $this->clave($rutaOrigen);
        if (!isset($this->documentos[$origen])) {
            throw new RuntimeException('No está el archivo que se quiere copiar.');
        }
        $destino = $this->clave($rutaDestino);
        $hash = hash('sha256', $this->documentos[$origen]);
        if (isset($this->documentos[$destino])) {
            $hashExistente = hash('sha256', $this->documentos[$destino]);
            if ($hashExistente === $hash) {
                return $this->datos($destino, $hash, false);
            }
            throw new AlmacenConflicto($destino, $hashExistente);
        }
        $this->documentos[$destino] = $this->documentos[$origen];

        return $this->datos($destino, $hash, true);
    }

    public function borrar($ruta = '', $itemId = '')
    {
        $clave = $this->clave($ruta);
        if (!isset($this->documentos[$clave])) {
            return false;
        }
        unset($this->documentos[$clave]);

        return true;
    }

    public function asegurarCarpeta($ruta)
    {
        // En memoria no hay carpetas: una ruta es una clave entera.
        return $this->clave($ruta);
    }

    public function listarCarpeta($ruta)
    {
        $prefijo = $this->clave($ruta);
        $prefijo = $prefijo === '' ? '' : $prefijo . '/';
        $salida = [];
        foreach ($this->documentos as $clave => $contenido) {
            if ($prefijo !== '' && strpos($clave, $prefijo) !== 0) {
                continue;
            }
            $resto = substr($clave, strlen($prefijo));
            if (strpos($resto, '/') !== false) {
                continue;
            }
            $salida[] = [
                'nombre'        => $resto,
                'es_carpeta'    => false,
                'tamano'        => strlen($contenido),
                'item_id'       => 'prueba:' . $clave,
                'modificado_en' => '2026-01-01 00:00:00',
            ];
        }

        return $salida;
    }

    public function identidad($ruta)
    {
        $clave = $this->clave($ruta);
        if (!isset($this->documentos[$clave])) {
            return null;
        }

        return [
            'item_id'       => 'prueba:' . $clave,
            'etag'          => hash('crc32b', $this->documentos[$clave]),
            'tamano'        => strlen($this->documentos[$clave]),
            'modificado_en' => '2026-01-01 00:00:00',
        ];
    }

    public function permiteEscritura($ruta = '')
    {
        return true;
    }

    public function direccion($rutaRelativa)
    {
        return $this->prefijo . $this->clave($rutaRelativa);
    }

    public function recorreElArbolBarato()
    {
        return false;
    }

    public function tieneIdentidadPermanente()
    {
        return true;
    }

    private function datos($clave, $hash, $creado)
    {
        return [
            'ruta'    => $this->prefijo . $clave,
            'hash'    => $hash,
            'creado'  => (bool) $creado,
            'tamano'  => strlen($this->documentos[$clave]),
            'item_id' => 'prueba:' . $clave,
            'etag'    => hash('crc32b', $this->documentos[$clave]),
        ];
    }

    private function clave($ruta)
    {
        $ruta = (string) $ruta;
        if ($this->prefijo !== '' && stripos($ruta, $this->prefijo) === 0) {
            $ruta = substr($ruta, strlen($this->prefijo));
        }
        return ltrim(str_replace('\\', '/', $ruta), '/');
    }
}

