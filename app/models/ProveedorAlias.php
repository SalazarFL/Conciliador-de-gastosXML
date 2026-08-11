<?php
/**
 * Equivalencias de nombre de proveedor aprendidas al emparejar a mano.
 *
 * Cuando alguien vincula una línea del listado con una factura cuyo proveedor
 * "no se parece" —"COOPEAGRI" contra "COOPERATIVA AGRICOLA INDUSTRIAL Y DE
 * SERVICIOS MULTIPLES EL GENERAL"—, esa decisión se guarda aquí. A partir de
 * ese momento el emparejador las trata como el mismo proveedor, tanto en las
 * facturas que ya estaban sin respaldo como en las que lleguen después.
 *
 * El mapa se carga UNA vez por petición: son pocas filas y las consulta cada
 * comparación de nombres.
 */

require_once __DIR__ . '/../helpers/FacturaMatcher.php';

class ProveedorAlias extends Model
{
    protected $table = 'proveedor_alias';

    private static $mapa = null;

    /**
     * alias normalizado => nombre canónico normalizado del proveedor.
     *
     * El nombre canónico se arma en la consulta desde `proveedores`, no se
     * copia en la fila: si al proveedor se le corrige la razón social, el
     * alias sigue apuntando a la versión buena sin tener que rehacerlo.
     */
    public static function mapa()
    {
        if (self::$mapa !== null) {
            return self::$mapa;
        }

        self::$mapa = [];
        try {
            $filas = (new self())->fetchAll(
                'SELECT a.alias_norm, p.razon_social
                   FROM proveedor_alias a
                   JOIN proveedores p ON p.id = a.proveedor_id'
            ) ?: [];
            foreach ($filas as $f) {
                $canonico = self::normalizar((string) $f['razon_social']);
                if ($canonico !== '') {
                    self::$mapa[(string) $f['alias_norm']] = $canonico;
                }
            }
        } catch (Throwable $e) {
            // Sin tabla (instalación que no ha corrido la migración) el
            // emparejador sigue funcionando como antes, solo sin alias.
            self::$mapa = [];
        }

        return self::$mapa;
    }

    /** La llama aprender(); también las pruebas entre casos. */
    public static function olvidarMapa()
    {
        self::$mapa = null;
    }

    /**
     * Misma normalización que usa el emparejador, para que el alias se busque
     * exactamente con la forma con la que se comparan los nombres.
     */
    public static function normalizar($texto)
    {
        $tokens = FacturaMatcher::tokenizarProveedor($texto);
        return $tokens ? implode(' ', $tokens) : '';
    }

    /**
     * Guarda "este texto es este proveedor". Devuelve false si no hay nada que
     * aprender: texto vacío, o un nombre que ya se parecía lo suficiente y por
     * lo tanto no necesita ayuda.
     */
    public function aprender($proveedorId, $textoAlias, $usuarioId = 0)
    {
        $proveedorId = (int) $proveedorId;
        $alias = self::normalizar($textoAlias);
        if ($proveedorId <= 0 || $alias === '') {
            return false;
        }

        // Un alias igual al nombre real no aporta nada.
        $canonico = self::normalizar((string) $this->fetchColumn(
            'SELECT razon_social FROM proveedores WHERE id = ? LIMIT 1',
            [$proveedorId]
        ));
        if ($canonico === '' || $canonico === $alias) {
            return false;
        }

        // Si el texto ya estaba aprendido apuntando a otro proveedor, gana el
        // último: es la corrección de quien se equivocó la vez anterior.
        $this->execute(
            'INSERT INTO proveedor_alias (proveedor_id, alias_norm, alias_texto, creado_por)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                proveedor_id = VALUES(proveedor_id),
                alias_texto  = VALUES(alias_texto),
                creado_por   = VALUES(creado_por)',
            [$proveedorId, $alias, mb_substr(trim((string) $textoAlias), 0, 255, 'UTF-8'),
             $usuarioId > 0 ? (int) $usuarioId : null]
        );

        self::olvidarMapa();
        return true;
    }

    /** ¿Este texto ya está aprendido? Devuelve el nombre canónico o ''. */
    public static function canonicoDe($texto)
    {
        $norm = self::normalizar($texto);
        if ($norm === '') {
            return '';
        }
        $mapa = self::mapa();
        return $mapa[$norm] ?? '';
    }

    /** Para la pantalla de administración: alias con su proveedor. */
    public function listar()
    {
        return $this->fetchAll(
            'SELECT a.id, a.alias_texto, a.alias_norm, a.veces_aplicado, a.creado_en,
                    p.id AS proveedor_id, p.razon_social, p.rfc AS cedula,
                    u.nombre AS creado_por_nombre
               FROM proveedor_alias a
               JOIN proveedores p ON p.id = a.proveedor_id
               LEFT JOIN usuarios u ON u.id = a.creado_por
              ORDER BY a.creado_en DESC'
        ) ?: [];
    }

    public function eliminar($id)
    {
        $filas = $this->execute('DELETE FROM proveedor_alias WHERE id = ?', [(int) $id]);
        self::olvidarMapa();
        return $filas;
    }

    /** Cuenta cuántas veces sirvió, para saber si vale la pena conservarlo. */
    public function registrarUso($aliasNorm)
    {
        return $this->registrarUsos([(string) $aliasNorm]);
    }

    /**
     * Suma un uso a cada alias de la lista, en una sola consulta.
     *
     * Cuenta **corridas de verificación**, no líneas: lo que hace falta saber
     * es si el alias sigue sirviendo para algo. Contar línea por línea
     * significaría escribir dentro del bucle de comparaciones —cientos de
     * miles— y volvería a inflarse cada vez que se reverifica el listado.
     */
    public function registrarUsos(array $aliasNorms)
    {
        $norms = array_values(array_unique(array_filter(array_map(
            function ($n) { return trim((string) $n); },
            $aliasNorms
        ), 'strlen')));
        if (!$norms) {
            return 0;
        }

        try {
            return (int) $this->execute(
                'UPDATE proveedor_alias SET veces_aplicado = veces_aplicado + 1
                  WHERE alias_norm IN (' . implode(',', array_fill(0, count($norms), '?')) . ')',
                $norms
            );
        } catch (Throwable $e) {
            return 0; // un contador no puede tumbar una verificación
        }
    }
}
