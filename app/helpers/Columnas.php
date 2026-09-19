<?php
/**
 * Las columnas de un listado: cuáles hay, cuáles se están viendo y cómo se
 * bajan a Excel.
 *
 * El catálogo vive en app/config/columnas.php. Acá está lo que hace falta para
 * usarlo desde los dos lados:
 *
 *   la pantalla   pinta las cabeceras y las celdas, cada una con su data-col
 *   el archivo    baja EXACTAMENTE las columnas que están encendidas
 *
 * Qué está encendido llega en la URL, en `cols`, que es lo que el botón de la
 * esquina de la tabla mantiene al día. Viene de fuera, así que no se cree:
 * solo pasan los identificadores que el catálogo conoce.
 *
 * El ORDEN sí es el de `cols`, y no el del catálogo. Antes se reordenaba al
 * del catálogo para que dos personas con las mismas columnas bajaran el mismo
 * archivo; el efecto era que quien acomodaba las columnas de la pantalla a su
 * gusto bajaba un Excel con otro orden, y tenía que volver a acomodarlas ahí.
 * El archivo es lo que se está viendo, orden incluido.
 */

require_once __DIR__ . '/XlsxWriter.php';

class Columnas
{
    /** El catálogo entero, leído una sola vez por petición. */
    private static $catalogo = null;

    private static function todo()
    {
        if (self::$catalogo === null) {
            self::$catalogo = require __DIR__ . '/../config/columnas.php';
        }
        return self::$catalogo;
    }

    public static function existe($listado)
    {
        return isset(self::todo()[$listado]);
    }

    /**
     * Las columnas de un listado, ya normalizadas: id => [titulo, defecto,
     * valor, ancho, estilo]. Un listado que no está en el catálogo devuelve
     * vacío en vez de reventar: una pantalla sin catálogo simplemente no
     * ofrece el botón.
     */
    public static function catalogo($listado)
    {
        $def = self::todo()[$listado]['columnas'] ?? [];
        $normalizadas = [];
        foreach ($def as $id => $col) {
            $normalizadas[$id] = [
                'id'      => $id,
                'titulo'  => (string) ($col['titulo'] ?? $id),
                'defecto' => !isset($col['defecto']) || $col['defecto'] !== false,
                'valor'   => $col['valor'] ?? $id,
                'ancho'   => (int) ($col['ancho'] ?? 18),
                'estilo'  => $col['estilo'] ?? null,
            ];
        }
        return $normalizadas;
    }

    /** Las que vienen encendidas de fábrica. */
    public static function porDefecto($listado)
    {
        $ids = [];
        foreach (self::catalogo($listado) as $id => $col) {
            if ($col['defecto']) { $ids[] = $id; }
        }
        return $ids;
    }

    /**
     * Qué columnas se están viendo, según lo que pidió la pantalla.
     *
     * Sin nada pedido —un enlace escrito a mano, un marcador viejo— valen las
     * de fábrica. Y si lo pedido no deja ninguna en pie, también: bajar un
     * Excel de cero columnas no es lo que nadie quiso.
     *
     * Se respeta el orden en que vienen, que es el orden en que quien mira
     * acomodó las columnas de la pantalla. Lo único que se descarta es lo que
     * el catálogo no reconoce y lo repetido.
     */
    public static function elegidas($listado, $pedidas)
    {
        $catalogo = self::catalogo($listado);
        if (!$catalogo) { return []; }

        $lista = is_array($pedidas) ? $pedidas : explode(',', (string) $pedidas);

        $validas = [];
        foreach ($lista as $id) {
            $id = trim((string) $id);
            if ($id === '' || !isset($catalogo[$id]) || in_array($id, $validas, true)) {
                continue;
            }
            $validas[] = $id;
        }

        return $validas ?: self::porDefecto($listado);
    }

    /**
     * Las filas del listado convertidas en la tabla que espera XlsxWriter:
     * una cabecera, una matriz de valores y los anchos de cada columna.
     */
    public static function tabla($listado, array $filas, $pedidas)
    {
        $catalogo = self::catalogo($listado);
        $ids = self::elegidas($listado, $pedidas);

        $cabeceras = [];
        $anchos = [];
        foreach ($ids as $ci => $id) {
            $cabeceras[] = $catalogo[$id]['titulo'];
            $anchos[$ci] = $catalogo[$id]['ancho'];
        }

        $datos = [];
        $estilos = [];
        foreach (array_values($filas) as $ri => $fila) {
            $renglon = [];
            foreach ($ids as $ci => $id) {
                $col = $catalogo[$id];
                $renglon[] = self::valor($col['valor'], $fila);

                if (is_callable($col['estilo'])) {
                    $marca = (int) call_user_func($col['estilo'], $fila);
                    if ($marca > 0) { $estilos[$ri][$ci] = $marca; }
                }
            }
            $datos[] = $renglon;
        }

        return [
            'ids'       => $ids,
            'cabeceras' => $cabeceras,
            'filas'     => $datos,
            'anchos'    => $anchos,
            'estilos'   => $estilos,
        ];
    }

    /**
     * Arma el .xlsx y lo manda al navegador. No vuelve: XlsxWriter::send
     * termina la petición, como cualquier descarga.
     *
     * $sufijo distingue archivos del mismo listado que no son lo mismo —el
     * pago lleva el nombre de su pago—, para que en la carpeta de
     * descargas no queden diez archivos iguales con distinta hora.
     */
    public static function descargar($listado, array $filas, $pedidas, $sufijo = '')
    {
        $tabla = self::tabla($listado, $filas, $pedidas);
        $def = self::todo()[$listado] ?? [];

        $base = (string) ($def['archivo'] ?? $listado);
        $sufijo = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $sufijo);
        $sufijo = trim((string) $sufijo, '_');
        $nombre = $base . ($sufijo !== '' ? '_' . $sufijo : '') . '_' . date('Ymd_His') . '.xlsx';

        $tmp = XlsxWriter::generate(
            $tabla['cabeceras'],
            $tabla['filas'],
            self::hoja($def['nombre'] ?? $listado),
            $tabla['estilos'],
            $tabla['anchos']
        );
        XlsxWriter::send($tmp, $nombre);
    }

    // ── Lo que la pantalla dibuja sola ──────────────────────────────────────
    //
    // Las columnas DE FÁBRICA las escribe cada vista a mano, y tiene que ser
    // así: llevan insignias, enlaces, sub-líneas y avisos que solo esa
    // pantalla sabe pintar. Las demás —las que la base sabía y no se
    // enseñaban— son texto y ya, así que se dibujan desde el catálogo. Con eso
    // no hay dieciocho <td> repetidos por vista que mantener al día, y lo que
    // se ve encendido es LITERALMENTE lo mismo que sale en el Excel: sale de
    // la misma función.

    /**
     * Las cabeceras de las columnas que no vienen de fábrica.
     *
     * $conTitulo en false las deja vacías: es lo que necesita la segunda fila
     * de cabecera de Seguimiento —la de los filtros por columna—, que tiene
     * que llevar los mismos data-col para esconderse a la par de la de arriba.
     */
    public static function cabecerasExtra($listado, $conTitulo = true)
    {
        $html = '';
        foreach (self::catalogo($listado) as $id => $col) {
            if ($col['defecto']) { continue; }
            $html .= '<th data-col="' . $id . '" data-col-defecto="no">'
                . ($conTitulo ? htmlspecialchars($col['titulo'], ENT_QUOTES) : '')
                . '</th>';
        }
        return $html;
    }

    /** Y sus celdas, con el mismo valor con el que bajarían al Excel. */
    public static function celdasExtra($listado, array $fila)
    {
        $html = '';
        foreach (self::catalogo($listado) as $id => $col) {
            if ($col['defecto']) { continue; }
            $texto = (string) self::valor($col['valor'], $fila);
            $html .= '<td data-col="' . $id . '" class="muted">'
                . ($texto === '' ? '—' : htmlspecialchars($texto, ENT_QUOTES))
                . '</td>';
        }
        return $html;
    }

    /** El valor de una celda: un campo de la fila, o lo que devuelva su función. */
    private static function valor($como, array $fila)
    {
        if (is_callable($como)) {
            return call_user_func($como, $fila);
        }
        $bruto = $fila[$como] ?? '';
        return $bruto === null ? '' : $bruto;
    }

    /**
     * Nombre de hoja que Excel acepte: hasta 31 caracteres y sin los seis
     * signos que reserva. Con uno solo de más, Excel declara el libro dañado
     * y no abre nada.
     */
    private static function hoja($nombre)
    {
        $limpio = str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', (string) $nombre);
        $limpio = trim(preg_replace('/\s+/u', ' ', $limpio));
        if (function_exists('mb_substr')) {
            $limpio = mb_substr($limpio, 0, 31);
        } else {
            $limpio = substr($limpio, 0, 31);
        }
        return $limpio !== '' ? $limpio : 'Datos';
    }
}
