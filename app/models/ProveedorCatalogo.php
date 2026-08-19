<?php
/**
 * La identidad de un proveedor, una sola para todo el sistema.
 *
 * El problema: cada listado guarda al proveedor de una forma distinta. El ERP
 * lo identifica por su código; los comprobantes XML, por la cédula del emisor;
 * las notas de crédito a veces solo traen el nombre escrito. El nombre, además,
 * es lo único que NO identifica: "COOPEAGRI" y "COOPERATIVA AGRICOLA INDUSTRIAL
 * Y DE SERVICIOS MULTIPLES EL GENERAL" son el mismo, y dos proveedores distintos
 * pueden escribirse igual. Por eso el filtro de proveedor no puede ser un texto
 * libre ni un desplegable de nombres: tiene que ser una identidad que cada
 * listado sepa traducir a lo que él guarda.
 *
 * Esa identidad es la CLAVE, y viaja en la URL:
 *
 *   ced:3101123456  el proveedor con esa cédula (la mejor: cruza ERP y XML)
 *   id:42           un proveedor de `proveedores` sin cédula registrada
 *   cod:001234      un código del ERP que todavía no se sabe de quién es
 *   nom:ACME S.A.   último recurso: filas que solo tienen el nombre escrito
 *
 * Elegir una clave `ced:` alcanza TODOS los códigos de ese proveedor, que es
 * justo lo que se espera al "elegir un proveedor" y lo que un desplegable de
 * códigos sueltos no podía hacer. El puente entre código y cédula es el mapa
 * que `ProveedorCodigoErp` cosecha de los emparejamientos ya verificados.
 */

class ProveedorCatalogo extends Model
{
    protected $table = 'proveedores';

    /** Una instancia para las llamadas estáticas. */
    private static $instancia = null;

    /** proveedor_codigo => ['proveedor_id', 'cedula', 'nombre'] */
    private static $mapaCodigos = null;

    /** El mapa al revés: cédula/proveedor => sus códigos del ERP. */
    private static $indiceCodigos = null;

    /** La tabla `proveedores` indexada por id y por cédula. */
    private static $proveedores = null;

    /** clave => identidad ya resuelta durante esta petición. */
    private static $resueltas = [];

    private static function yo()
    {
        if (self::$instancia === null) {
            // Sin acotar por sociedad, a propósito y sin necesitar el trait:
            // el catálogo es de identidades, no de documentos. Quién ve qué
            // documento lo decide cada listado, que sí está acotado.
            self::$instancia = new self();
        }
        return self::$instancia;
    }

    // ── Clave ──────────────────────────────────────────────────────────

    /**
     * Lo que llega por la URL, o cadena vacía si no es una clave.
     *
     * Una clave inventada no se convierte en "sin filtro": se conserva y el
     * listado sale vacío. Ver `condicion()`.
     */
    public static function normalizarClave($valor)
    {
        $valor = trim((string) $valor);
        if ($valor === '' || !preg_match('/^(ced|id|cod|nom):(.+)$/u', $valor, $m)) {
            return '';
        }
        return $m[1] . ':' . mb_substr(trim($m[2]), 0, 150, 'UTF-8');
    }

    /**
     * La clave que le corresponde a una fila de un listado.
     *
     * Manda el código del ERP cuando la fila lo trae, y no la cédula, aunque
     * la cédula identifique mejor. La razón es práctica: dentro de un mismo
     * listado hay filas del mismo proveedor con código y sin él —una factura
     * ya emparejada conoce al emisor del XML; la que todavía no, no—, y si
     * cada una eligiera una clave distinta el proveedor saldría dos veces en
     * el desplegable. Elegir siempre el código las junta. Que las dos formas
     * se entiendan entre pantallas lo resuelve `resolver()`, que expande el
     * código a su cédula y al revés.
     */
    public static function clave(array $fila)
    {
        $proveedorId = (int) ($fila['proveedor_id'] ?? 0);
        $codigo      = trim((string) ($fila['codigo'] ?? ''));
        $cedula      = self::soloDigitos($fila['cedula'] ?? '');
        $nombre      = trim((string) ($fila['nombre'] ?? ''));

        if ($codigo !== '') {
            return 'cod:' . $codigo;
        }
        if ($cedula !== '') {
            return 'ced:' . $cedula;
        }
        if ($proveedorId > 0) {
            $proveedor = self::proveedorPorId($proveedorId);
            $cedula = self::soloDigitos($proveedor['rfc'] ?? '');
            return $cedula !== '' ? 'ced:' . $cedula : 'id:' . $proveedorId;
        }
        return $nombre !== '' ? 'nom:' . mb_substr($nombre, 0, 150, 'UTF-8') : '';
    }

    // ── Resolución ─────────────────────────────────────────────────────

    /**
     * Todo lo que un listado puede necesitar para reconocer al proveedor.
     *
     * @return array|null ['clave','cedula','proveedor_ids','codigos','nombres']
     */
    public static function resolver($clave)
    {
        $clave = self::normalizarClave($clave);
        if ($clave === '') {
            return null;
        }
        if (array_key_exists($clave, self::$resueltas)) {
            return self::$resueltas[$clave];
        }

        [$tipo, $valor] = explode(':', $clave, 2);
        $id = [
            'clave'         => $clave,
            'cedula'        => '',
            'proveedor_ids' => [],
            'codigos'       => [],
            'nombres'       => [],
        ];

        if ($tipo === 'ced') {
            $id['cedula'] = self::soloDigitos($valor);
            foreach (self::proveedoresPorCedula($id['cedula']) as $p) {
                $id['proveedor_ids'][] = (int) $p['id'];
                $id['nombres'][] = (string) $p['razon_social'];
            }
        } elseif ($tipo === 'id') {
            $proveedor = self::proveedorPorId((int) $valor);
            if ($proveedor) {
                $id['proveedor_ids'][] = (int) $proveedor['id'];
                $id['nombres'][] = (string) $proveedor['razon_social'];
                $id['cedula'] = self::soloDigitos($proveedor['rfc'] ?? '');
            }
        } elseif ($tipo === 'cod') {
            $id['codigos'][] = $valor;
            $delMapa = self::mapaCodigos()[$valor] ?? null;
            if ($delMapa) {
                $id['proveedor_ids'][] = (int) $delMapa['proveedor_id'];
                $id['cedula'] = self::soloDigitos($delMapa['cedula']);
                if ($delMapa['nombre'] !== '') {
                    $id['nombres'][] = $delMapa['nombre'];
                }
            }
        } else {
            $id['nombres'][] = $valor;
        }

        // Los códigos del ERP que apuntan a este proveedor: elegirlo alcanza
        // todos, aunque el ERP lo tenga registrado dos veces.
        $indice = self::indiceCodigos();
        $porBuscar = [];
        if ($id['cedula'] !== '') {
            $porBuscar = $indice['por_cedula'][$id['cedula']] ?? [];
        }
        foreach ($id['proveedor_ids'] as $proveedorId) {
            $porBuscar = array_merge($porBuscar, $indice['por_proveedor'][$proveedorId] ?? []);
        }
        foreach ($porBuscar as $codigo) {
            $id['codigos'][] = $codigo;
            $nombreMapa = self::mapaCodigos()[$codigo]['nombre'] ?? '';
            if ($nombreMapa !== '') {
                $id['nombres'][] = $nombreMapa;
            }
        }

        $id['proveedor_ids'] = array_values(array_unique(array_filter($id['proveedor_ids'])));
        $id['codigos'] = array_values(array_unique(array_filter($id['codigos'], 'strlen')));
        $id['nombres'] = array_values(array_unique(array_filter(array_map('trim', $id['nombres']), 'strlen')));
        sort($id['codigos'], SORT_NATURAL);

        self::$resueltas[$clave] = $id;
        return $id;
    }

    /**
     * La condición SQL que reconoce al proveedor elegido, con las columnas que
     * este listado tenga a mano:
     *
     *   ProveedorCatalogo::condicion($clave, [
     *       'codigo'       => 'e.proveedor_codigo',
     *       'cedula'       => 'p.rfc',
     *       'proveedor_id' => 'x.proveedor_id',
     *       'nombre'       => 'e.proveedor_nombre',  // solo donde falte el código
     *   ], $params);
     *
     * Devuelve '' cuando no hay proveedor elegido, y '1=0' cuando lo hay pero
     * este listado no puede reconocerlo: elegir un proveedor y ver el listado
     * entero sería mentir; verlo vacío dice la verdad.
     *
     * `nombre` se pasa solo donde las filas pueden no traer código —notas de
     * crédito, devoluciones, seguimiento—: comparar por nombre confunde a dos
     * proveedores que se escriben igual, y es justamente lo que la cédula y el
     * código vienen a arreglar.
     */
    public static function condicion($clave, array $columnas, array &$params)
    {
        $clave = self::normalizarClave($clave);
        if ($clave === '') {
            return '';
        }

        $id = self::resolver($clave);
        $partes = [];

        if (!empty($columnas['codigo']) && $id['codigos']) {
            $partes[] = $columnas['codigo'] . ' IN (' . self::marcas($id['codigos']) . ')';
            foreach ($id['codigos'] as $codigo) {
                $params[] = $codigo;
            }
        }
        if (!empty($columnas['cedula']) && $id['cedula'] !== '') {
            // La cédula se guarda con y sin guiones según de dónde venga.
            $partes[] = "REPLACE(REPLACE(" . $columnas['cedula'] . ", '-', ''), ' ', '') = ?";
            $params[] = $id['cedula'];
        }
        if (!empty($columnas['proveedor_id']) && $id['proveedor_ids']) {
            $partes[] = $columnas['proveedor_id'] . ' IN (' . self::marcas($id['proveedor_ids']) . ')';
            foreach ($id['proveedor_ids'] as $proveedorId) {
                $params[] = $proveedorId;
            }
        }
        foreach ((array) ($columnas['nombre'] ?? []) as $columna) {
            if ((string) $columna !== '' && $id['nombres']) {
                $partes[] = $columna . ' IN (' . self::marcas($id['nombres']) . ')';
                foreach ($id['nombres'] as $nombre) {
                    $params[] = $nombre;
                }
            }
        }

        return $partes ? '(' . implode(' OR ', $partes) . ')' : '1=0';
    }

    // ── Opciones del selector ──────────────────────────────────────────

    /**
     * Junta las filas que dio un listado en una opción por proveedor.
     *
     * Entra lo que el listado sepa de cada fila —código, cédula, proveedor_id,
     * nombre y cuántas veces aparece— y sale la lista del desplegable, con el
     * código y la cédula al frente y el nombre como referencia.
     */
    public static function opciones($filas)
    {
        $grupos = [];
        foreach ((array) $filas as $fila) {
            $clave = self::clave($fila);
            if ($clave === '') {
                continue;
            }
            $id = self::resolver($clave);

            // Un proveedor, una opción, aunque el ERP le haya dado dos
            // códigos o el listado lo nombre de dos formas: la cédula es la
            // que dice quién es. Sin ella, cada clave va por su cuenta.
            $grupo = $id['cedula'] !== '' ? 'ced:' . $id['cedula'] : $clave;
            if (!isset($grupos[$grupo])) {
                $grupos[$grupo] = [
                    'clave'   => $clave,
                    'codigos' => [],
                    'cedula'  => $id['cedula'],
                    'nombre'  => '',
                    'n'       => 0,
                ];
            }
            $grupos[$grupo]['n'] += (int) ($fila['n'] ?? 0);

            // Entre dos formas de nombrar al proveedor gana la que más
            // listados saben reconocer: el código del ERP.
            if (strncmp($clave, 'cod:', 4) === 0 && strncmp($grupos[$grupo]['clave'], 'cod:', 4) !== 0) {
                $grupos[$grupo]['clave'] = $clave;
            }
            if ($grupos[$grupo]['cedula'] === '') {
                $grupos[$grupo]['cedula'] = self::soloDigitos($fila['cedula'] ?? '');
            }
            // El nombre más largo suele ser el completo, no la abreviatura.
            $nombre = trim((string) ($fila['nombre'] ?? ''));
            if ($nombre !== '' && mb_strlen($nombre) > mb_strlen($grupos[$grupo]['nombre'])) {
                $grupos[$grupo]['nombre'] = $nombre;
            }
        }

        foreach ($grupos as $grupo => $opcion) {
            $id = self::resolver($opcion['clave']);
            // Todos los códigos del proveedor, no solo el de esta fila: es lo
            // que alcanza el filtro y lo que la persona escribe al buscar.
            $grupos[$grupo]['codigos'] = $id['codigos'];
            if ($opcion['nombre'] === '' && $id['nombres']) {
                $grupos[$grupo]['nombre'] = $id['nombres'][0];
            }
        }

        $grupos = self::absorberSoloNombre($grupos);

        $opciones = array_values($grupos);
        // Por código: es el orden en que la gente los tiene en la cabeza y en
        // el ERP. Los que no tienen código quedan al final.
        usort($opciones, function ($a, $b) {
            $ca = $a['codigos'][0] ?? '';
            $cb = $b['codigos'][0] ?? '';
            if (($ca === '') !== ($cb === '')) {
                return $ca === '' ? 1 : -1;
            }
            $cmp = strnatcasecmp($ca, $cb);
            return $cmp !== 0 ? $cmp : strcasecmp($a['nombre'], $b['nombre']);
        });

        return $opciones;
    }

    /**
     * Mete las filas que solo tienen nombre dentro del proveedor al que
     * pertenecen.
     *
     * En los listados que guardan el proveedor a veces con código y a veces
     * solo escrito —las notas de crédito, y por ellas la cola de seguimiento—
     * el mismo proveedor generaba dos opciones: la del código y la del nombre
     * suelto. Y peor: elegir la del código traía TAMBIÉN las del nombre,
     * porque el filtro las reconoce, así que la cuenta de la opción decía
     * menos de lo que después aparecía en pantalla.
     *
     * Se juntan usando la misma lista de nombres con la que filtra
     * `condicion()`, para que lo que promete la opción sea lo que devuelve.
     */
    private static function absorberSoloNombre(array $grupos)
    {
        $porNombre = [];
        foreach ($grupos as $grupo => $opcion) {
            if (strncmp($opcion['clave'], 'nom:', 4) === 0) {
                continue;
            }
            foreach (self::resolver($opcion['clave'])['nombres'] as $nombre) {
                $porNombre[$nombre] = $porNombre[$nombre] ?? $grupo;
            }
        }
        if (!$porNombre) {
            return $grupos;
        }

        foreach ($grupos as $grupo => $opcion) {
            if (strncmp($opcion['clave'], 'nom:', 4) !== 0) {
                continue;
            }
            $destino = $porNombre[substr($opcion['clave'], 4)] ?? null;
            if ($destino === null || !isset($grupos[$destino])) {
                continue;
            }
            $grupos[$destino]['n'] += $opcion['n'];
            unset($grupos[$grupo]);
        }

        return $grupos;
    }

    /** Cómo se lee el proveedor elegido cuando no está en la lista visible. */
    public static function etiqueta($clave)
    {
        $id = self::resolver($clave);
        if ($id === null) {
            return '';
        }
        $partes = [];
        if ($id['codigos']) {
            $partes[] = implode(', ', array_slice($id['codigos'], 0, 3));
        }
        if ($id['cedula'] !== '') {
            $partes[] = $id['cedula'];
        }
        if ($id['nombres']) {
            $partes[] = $id['nombres'][0];
        }
        return $partes ? implode(' · ', $partes) : $clave;
    }

    /** La opción del proveedor elegido, para mostrarlo aunque no esté listado. */
    public static function opcionSuelta($clave)
    {
        $id = self::resolver($clave);
        if ($id === null) {
            return null;
        }
        return [
            'clave'   => $id['clave'],
            'codigos' => $id['codigos'],
            'cedula'  => $id['cedula'],
            'nombre'  => $id['nombres'][0] ?? '',
            'n'       => 0,
        ];
    }

    // ── Fuentes ────────────────────────────────────────────────────────

    /**
     * El puente código del ERP ↔ proveedor del XML, con nombre y cédula.
     *
     * Se lee una vez por petición: son unos cientos de filas y las consulta
     * cada opción del desplegable.
     */
    private static function mapaCodigos()
    {
        if (self::$mapaCodigos !== null) {
            return self::$mapaCodigos;
        }

        self::$mapaCodigos = [];
        try {
            $filas = self::yo()->fetchAll(
                "SELECT m.proveedor_codigo, m.proveedor_id,
                        COALESCE(NULLIF(p.rfc, ''), m.cedula) AS cedula,
                        COALESCE(NULLIF(p.razon_social, ''), m.nombre_erp) AS nombre
                   FROM proveedor_codigo_erp m
                   LEFT JOIN proveedores p ON p.id = m.proveedor_id"
            ) ?: [];
            foreach ($filas as $fila) {
                self::$mapaCodigos[(string) $fila['proveedor_codigo']] = [
                    'proveedor_id' => (int) $fila['proveedor_id'],
                    'cedula'       => (string) ($fila['cedula'] ?? ''),
                    'nombre'       => trim((string) ($fila['nombre'] ?? '')),
                ];
            }
        } catch (Throwable $e) {
            // Instalación sin la migración del mapa: el filtro sigue
            // funcionando por código, sin unir los del mismo proveedor.
            self::$mapaCodigos = [];
        }

        return self::$mapaCodigos;
    }

    /**
     * Los códigos de cada proveedor, al revés que el mapa.
     *
     * Sin este índice, resolver una clave recorría el mapa entero, y el
     * desplegable resuelve una clave por opción: cuatrocientas opciones por
     * doscientos códigos son ochenta mil vueltas por pantalla.
     */
    private static function indiceCodigos()
    {
        if (self::$indiceCodigos !== null) {
            return self::$indiceCodigos;
        }

        self::$indiceCodigos = ['por_cedula' => [], 'por_proveedor' => []];
        foreach (self::mapaCodigos() as $codigo => $fila) {
            $cedula = self::soloDigitos($fila['cedula']);
            if ($cedula !== '') {
                self::$indiceCodigos['por_cedula'][$cedula][] = (string) $codigo;
            }
            if ($fila['proveedor_id'] > 0) {
                self::$indiceCodigos['por_proveedor'][(int) $fila['proveedor_id']][] = (string) $codigo;
            }
        }

        return self::$indiceCodigos;
    }

    /**
     * Todos los proveedores, leídos de una vez.
     *
     * De una vez y no de a uno: el desplegable pregunta por cada opción, y
     * con la base al otro lado de la red eso son cientos de idas y vueltas
     * para armar una pantalla. Son unos cientos de filas de tres columnas.
     */
    private static function proveedores()
    {
        if (self::$proveedores !== null) {
            return self::$proveedores;
        }

        self::$proveedores = ['por_id' => [], 'por_cedula' => []];
        try {
            $filas = self::yo()->fetchAll('SELECT id, rfc, razon_social FROM proveedores') ?: [];
            foreach ($filas as $fila) {
                $fila['id'] = (int) $fila['id'];
                self::$proveedores['por_id'][$fila['id']] = $fila;
                $cedula = self::soloDigitos($fila['rfc'] ?? '');
                if ($cedula !== '') {
                    self::$proveedores['por_cedula'][$cedula][] = $fila;
                }
            }
        } catch (Throwable $e) {
            self::$proveedores = ['por_id' => [], 'por_cedula' => []];
        }

        return self::$proveedores;
    }

    private static function proveedoresPorCedula($cedula)
    {
        return $cedula === '' ? [] : (self::proveedores()['por_cedula'][$cedula] ?? []);
    }

    private static function proveedorPorId($id)
    {
        return self::proveedores()['por_id'][(int) $id] ?? null;
    }

    /**
     * Vaciar lo recordado en esta petición.
     *
     * Lo usan las pruebas y quien cambie el mapa de códigos a mano: el
     * catálogo lee sus tablas una sola vez, así que sin esto seguiría
     * contestando con lo de antes.
     */
    public static function olvidar()
    {
        self::$mapaCodigos = null;
        self::$indiceCodigos = null;
        self::$proveedores = null;
        self::$resueltas = [];
    }

    // ── Utilidades ─────────────────────────────────────────────────────

    /**
     * La cédula sin guiones ni espacios: el ERP la escribe "3-101-123456" y el
     * XML "3101123456", y son la misma.
     */
    public static function soloDigitos($valor)
    {
        return preg_replace('/\D+/', '', (string) $valor);
    }

    private static function marcas(array $valores)
    {
        return implode(',', array_fill(0, count($valores), '?'));
    }
}
