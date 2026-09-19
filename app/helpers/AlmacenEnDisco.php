<?php
/**
 * Los documentos en el disco al que llega esta máquina.
 *
 * Es lo que la aplicación viene haciendo desde siempre, ahora detrás de la
 * puerta: la carpeta compartida se abre con las funciones de archivos de PHP y
 * ya. Sirve tanto si esa carpeta es del disco del servidor como si es una
 * biblioteca de SharePoint sincronizada con OneDrive —que es el montaje de
 * hoy—, y de ahí viene la única sutileza de esta clase.
 *
 * Ver AlmacenDocumentos para el porqué de la puerta.
 */

require_once __DIR__ . '/AlmacenDocumentos.php';
require_once __DIR__ . '/RutaDocumento.php';

class AlmacenEnDisco extends AlmacenDocumentos
{
    /**
     * Existir no es lo mismo que poder leerse, y acá se responde lo primero.
     *
     * Se conserva `is_file()` a propósito, aunque de un marcador de OneDrive
     * conteste que sí: es lo que hace falta para saber si el documento sigue
     * registrado en su sitio o si alguien lo movió o lo borró —que es la
     * pregunta que hacen los listados para marcar "archivo perdido"—. Un
     * documento que está en la nube NO está perdido: está, y hay que decir
     * otra cosa de él.
     *
     * Quien necesita el contenido usa abrir(), que sí distingue.
     */
    public function existe($ruta)
    {
        $absoluta = RutaDocumento::absoluta($ruta);

        return $absoluta !== '' && is_file($absoluta);
    }

    /**
     * Abre el documento de verdad.
     *
     * Acá está la diferencia con existe(). De los documentos que guarda solo
     * en la nube, OneDrive deja en el disco un marcador que existe, pesa lo
     * que pesa el original y hasta contesta que sí a is_readable(). El
     * contenido llega solo si Windows lo baja al abrirlo, y esa descarga se le
     * niega a quien corre como servicio —Apache es LocalSystem, no la persona
     * que sincroniza la carpeta—, así que fopen() falla con "Invalid argument".
     *
     * Sin abrirlo antes de contestar, quien servía el archivo mandaba las
     * cabeceras con el tamaño completo y después cero bytes: el navegador solo
     * decía "error al cargar el documento PDF", sin pista de qué había pasado.
     */
    public function abrir($ruta)
    {
        $absoluta = RutaDocumento::absoluta($ruta);
        if ($absoluta === '' || !is_file($absoluta)) {
            return false;
        }

        return @fopen($absoluta, 'rb');
    }

    public function tamano($ruta)
    {
        $absoluta = RutaDocumento::absoluta($ruta);
        if ($absoluta === '' || !is_file($absoluta)) {
            return null;
        }
        $bytes = @filesize($absoluta);

        return $bytes === false ? null : (int) $bytes;
    }

    /**
     * En disco el archivo local ES el archivo: no hay nada que bajar ni nada
     * que limpiar después. Se comprueba que se pueda abrir para no entregar la
     * ruta de un marcador que después no se va a poder leer.
     */
    public function rutaLocal($ruta)
    {
        $manejador = $this->abrir($ruta);
        if ($manejador === false) {
            return null;
        }
        fclose($manejador);

        return RutaDocumento::absoluta($ruta);
    }

    public function porQueNoSeLee($ruta)
    {
        return 'El archivo está en la carpeta compartida, pero OneDrive lo tiene solo en la nube '
            . 'y el servidor no puede bajarlo: corre como servicio de Windows y esa descarga se le '
            . 'niega. Abrí la carpeta compartida en el Explorador, clic derecho sobre ella y elegí '
            . '"Conservar siempre en este dispositivo". Para este documento suelto alcanza con '
            . 'abrirlo una vez desde el Explorador.';
    }

    public function descripcion()
    {
        return 'la carpeta compartida de documentos';
    }

    // ── Escribir ──────────────────────────────────────────────────────────

    /**
     * Acá vive el patrón que antes estaba en DocumentoArchivo::copiarValidado:
     * copiar a un archivo `.partial_<uniqid>` al lado, comprobar que llegó
     * entero y recién entonces renombrarlo al nombre definitivo.
     *
     * El renombrado dentro de la misma carpeta es atómico, así que nadie ve
     * jamás un documento a medias. Importa más de lo que parece: el
     * organizador corre cada pocos minutos y el índice se arma leyendo la
     * carpeta, de modo que un archivo incompleto con el nombre bueno se
     * indexaría como si fuera el documento.
     *
     * Contra SharePoint todo esto desaparece —la subida materializa el
     * archivo entero o ninguno—, y por eso el `.partial` bajó hasta acá en vez
     * de quedarse donde estaba.
     */
    public function escribir($ruta, $origenLocal, array $opciones = [])
    {
        $origenLocal = (string) $origenLocal;
        if ($origenLocal === '' || !is_file($origenLocal)) {
            throw new RuntimeException('No está el archivo que se quiere guardar: ' . $origenLocal);
        }

        $hash = (string) ($opciones['hash'] ?? '');
        if ($hash === '') {
            $hash = (string) @hash_file('sha256', $origenLocal);
        }
        if ($hash === '') {
            throw new RuntimeException('No se pudo calcular el hash de ' . basename($origenLocal) . '.');
        }

        $destino = RutaDocumento::absoluta($ruta);
        if ($destino === '') {
            throw new RuntimeException('No hay carpeta compartida configurada para guardar ' . $ruta . '.');
        }

        // Lo que ya está y es lo mismo no se vuelve a escribir. Lo que ya está
        // y es distinto no lo decide este almacén.
        if (is_file($destino)) {
            $hashDestino = (string) @hash_file('sha256', $destino);
            if ($hashDestino === $hash) {
                return $this->datosDe($destino, $hash, false);
            }
            throw new AlmacenConflicto($destino, $hashDestino);
        }

        $this->asegurarCarpeta(dirname($destino));

        $parcial = $destino . '.partial_' . str_replace('.', '', uniqid('', true));
        if (!@copy($origenLocal, $parcial)) {
            throw new RuntimeException('No se pudo copiar ' . basename($origenLocal) . ' a la carpeta compartida.');
        }
        if (@hash_file('sha256', $parcial) !== $hash) {
            @unlink($parcial);
            throw new RuntimeException('La copia no superó la validación SHA-256.');
        }
        if (!@rename($parcial, $destino)) {
            @unlink($parcial);
            throw new RuntimeException('No se pudo finalizar el archivo ' . $destino . '.');
        }

        return $this->datosDe($destino, $hash, true);
    }

    /**
     * Mover es rename(), con una salvedad: en Windows rename() no reemplaza de
     * forma fiable un destino que ya existe, así que se comprueba antes. Si lo
     * que hay en el destino es este mismo archivo, no hay nada que hacer.
     */
    public function mover($rutaOrigen, $rutaDestino)
    {
        $origen = RutaDocumento::absoluta($rutaOrigen);
        $destino = RutaDocumento::absoluta($rutaDestino);
        if ($origen === '' || !is_file($origen)) {
            throw new RuntimeException('No está el archivo que se quiere mover: ' . $rutaOrigen);
        }
        if ($this->mismaRuta($origen, $destino)) {
            return $destino;
        }
        if (is_file($destino)) {
            throw new AlmacenConflicto($destino, (string) @hash_file('sha256', $destino));
        }

        $this->asegurarCarpeta(dirname($destino));
        if (!@rename($origen, $destino)) {
            throw new RuntimeException('No se pudo mover ' . $origen . ' a ' . $destino . '.');
        }

        return $destino;
    }

    public function copiar($rutaOrigen, $rutaDestino)
    {
        $origen = RutaDocumento::absoluta($rutaOrigen);
        if ($origen === '' || !is_file($origen)) {
            throw new RuntimeException('No está el archivo que se quiere copiar: ' . $rutaOrigen);
        }

        return $this->escribir($rutaDestino, $origen);
    }

    /**
     * Que el archivo ya no esté no es un fallo: lo que se pedía era que no
     * estuviera. Devuelve si hubo algo que borrar, para quien lleve la cuenta.
     *
     * El identificador se acepta y se ignora: en disco la identidad ES la
     * ruta. Está en la firma porque contra SharePoint deshacer un archivado
     * tiene que poder hacerse por identificador, y quien llama no debería
     * tener que saber contra cuál de los dos está hablando.
     */
    public function borrar($ruta = '', $itemId = '')
    {
        $absoluta = RutaDocumento::absoluta($ruta);
        if ($absoluta === '' || !is_file($absoluta)) {
            return false;
        }

        return (bool) @unlink($absoluta);
    }

    public function asegurarCarpeta($ruta)
    {
        $carpeta = RutaDocumento::absoluta($ruta);
        if ($carpeta === '') {
            throw new RuntimeException('No hay carpeta compartida configurada.');
        }
        if (!is_dir($carpeta) && !@mkdir($carpeta, 0777, true) && !is_dir($carpeta)) {
            throw new RuntimeException('No se pudo crear la carpeta ' . $carpeta . '.');
        }

        return $carpeta;
    }

    public function listarCarpeta($ruta)
    {
        $carpeta = RutaDocumento::absoluta($ruta);
        if ($carpeta === '' || !is_dir($carpeta)) {
            return [];
        }

        $salida = [];
        foreach (new DirectoryIterator($carpeta) as $item) {
            if ($item->isDot()) {
                continue;
            }
            $salida[] = [
                'nombre'        => $item->getFilename(),
                'es_carpeta'    => $item->isDir(),
                'tamano'        => $item->isDir() ? null : (int) $item->getSize(),
                'item_id'       => '',
                'modificado_en' => date('Y-m-d H:i:s', (int) $item->getMTime()),
            ];
        }

        return $salida;
    }

    /**
     * En disco no hay identificador que sobreviva a que muevan el archivo: la
     * ruta es todo lo que hay. Se devuelve igual el resto, que sí sirve —el
     * organizador decide con tamaño y fecha si tiene que volver a leer el
     * contenido—.
     */
    public function identidad($ruta)
    {
        $absoluta = RutaDocumento::absoluta($ruta);
        if ($absoluta === '' || !is_file($absoluta)) {
            return null;
        }

        return [
            'item_id'       => '',
            'etag'          => '',
            'tamano'        => (int) @filesize($absoluta),
            'modificado_en' => date('Y-m-d H:i:s', (int) @filemtime($absoluta)),
        ];
    }

    /**
     * No se usa is_writable(): en Windows contesta false para las carpetas que
     * OneDrive sincroniza desde SharePoint. Vienen con el atributo de solo
     * lectura —que en una carpeta no significa "no se puede escribir", sino
     * que Windows la trata distinto— y encima son puntos de reanálisis, así
     * que la comprobación de PHP falla aunque los permisos sí alcancen. La
     * única respuesta fiable es intentar escribir un archivo y borrarlo.
     */
    public function permiteEscritura($ruta = '')
    {
        $carpeta = trim((string) $ruta);
        $carpeta = $carpeta === '' ? RutaDocumento::raiz() : RutaDocumento::absoluta($carpeta);
        if ($carpeta === '' || !is_dir($carpeta)) {
            return false;
        }

        $prueba = rtrim($carpeta, '\\/') . DIRECTORY_SEPARATOR
            . '.xmlconcilia_prueba_' . getmypid() . '_' . uniqid();
        if (@file_put_contents($prueba, 'ok') === false) {
            return false;
        }
        @unlink($prueba);

        return true;
    }

    public function direccion($rutaRelativa)
    {
        return RutaDocumento::absoluta($rutaRelativa);
    }

    public function recorreElArbolBarato()
    {
        return true;
    }

    public function tieneIdentidadPermanente()
    {
        return false;
    }

    private function datosDe($destino, $hash, $creado)
    {
        return [
            'ruta'    => $destino,
            'hash'    => $hash,
            'creado'  => (bool) $creado,
            'tamano'  => (int) @filesize($destino),
            'item_id' => '',
            'etag'    => '',
        ];
    }

    /** En Windows dos rutas que solo difieren en mayúsculas son la misma. */
    private function mismaRuta($a, $b)
    {
        $a = str_replace('/', DIRECTORY_SEPARATOR, (string) $a);
        $b = str_replace('/', DIRECTORY_SEPARATOR, (string) $b);

        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($a, $b) === 0 : $a === $b;
    }
}
