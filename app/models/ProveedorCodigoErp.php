<?php
/**
 * El catálogo de proveedores del ERP: qué código pertenece a qué cédula.
 *
 * Para qué sirve: el emparejamiento cruza por el consecutivo, y ese número NO
 * es único a nivel país —lo arma el emisor, empezando en 1— así que dos
 * proveedores distintos pueden tener el mismo. La cédula es lo único que los
 * separa de verdad, y este catálogo es el que la sabe.
 *
 * De dónde sale: del reporte "Lista de Proveedores" del ERP, que trae el
 * código y la cédula escritos. Y de lo que alguien agregue a mano cuando
 * aparezca un proveedor nuevo.
 *
 * ── Lo que esto reemplazó, y por qué ──────────────────────────────────
 *
 * Antes no había catálogo y el sistema tenía que DEDUCIR la equivalencia:
 * la aprendía de las facturas que cuadraban al colón, llevaba un contador de
 * cuántas la respaldaban, abría conflictos cuando algo la contradecía y se
 * declaraba "en disputa" —dejando de validar— cuando la deducción era débil.
 * Toda esa maquinaria existía para suplir un dato que no se tenía.
 *
 * Falló donde tenía que fallar: con montos redondos y chicos, la prueba "el
 * monto cuadró" no prueba nada. Un proveedor que facturaba ₡5.000 terminó
 * enganchado a cuatro códigos que no eran suyos, y cinco facturas figuraban
 * respaldadas con el comprobante de otra persona. Con la cédula a mano, no
 * hay nada que deducir: se sabe o no se sabe.
 *
 * ── Las dos preguntas que contesta ────────────────────────────────────
 *
 *   veredicto()      Desde el ERP: "esta factura del código C, ¿puede
 *                    emparejar con este XML?" Solo si la cédula del emisor es
 *                    la que el catálogo le asigna a C.
 *
 *   conoceCedula()   Desde el XML: "este comprobante que acaba de llegar,
 *                    ¿pertenece a algún código del ERP?" Si no, no hay a qué
 *                    factura pegarlo y hay que preguntarle a una persona.
 *
 * ── Un código, una cédula… salvo que alguien diga lo contrario ─────────
 *
 * Cada código tiene UNA cédula dueña, la del listado del ERP. Pero hay
 * códigos que en la práctica reciben comprobantes de dos: BANCO BAC SAN JOSE
 * y BAC SAN JOSE LEASING facturan contra el mismo código del sistema. Pasarle
 * el código a la segunda se lo quitaba a la primera, y eso no es lo que pasa.
 *
 * Para eso está proveedor_codigo_tambien: una cédula que el código ACEPTA
 * además de la suya, porque una persona lo decidió para ese código en
 * concreto. No es una regla general —dos cédulas no comparten código porque
 * sí—, y el importador del listado no la toca ni la desmiente.
 *
 * ── Uno por empresa ────────────────────────────────────────────────────
 *
 * Cada empresa tiene su propio catálogo: el listado que se importa es el de
 * la empresa en la que se está trabajando, y el mismo código puede ser un
 * proveedor en una y otro en la otra. Lo mismo vale para lo que un código
 * acepta además de su dueña y para las cédulas que se decidió no procesar:
 * que un proveedor no le facture a una empresa no dice nada de las demás.
 *
 * Sin empresa no hay catálogo —ni para leer ni para escribir—, igual que en
 * el resto de la aplicación (ver AlcanceSociedad).
 */

class ProveedorCodigoErp extends Model
{
    use AlcanceSociedad;

    protected $table = 'proveedor_codigo_erp';

    /**
     * El catálogo de cada empresa, leído una vez por petición.
     *
     * Son unos miles de filas y las consulta cada comparación del bucle de
     * emparejamiento; traerlas de a una contra el servidor sería el mismo
     * error que ya costó segundos por pantalla en Correo.
     *
     * sociedad_id => mapa (ver mapa()).
     */
    private static $mapa = [];

    /** sociedad_id => las cédulas que su catálogo conoce. */
    private static $cedulas = [];

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTables(); });
    }

    private function ensureTables()
    {
        // sociedad_id sin valor por omisión, a propósito: una fila escrita
        // sin decir de qué empresa es tiene que fallar, no caer en una.
        $this->execute("CREATE TABLE IF NOT EXISTS proveedor_codigo_erp (
            `sociedad_id` INT UNSIGNED NOT NULL,
            `proveedor_codigo` VARCHAR(30) NOT NULL,
            `proveedor_id` INT UNSIGNED NOT NULL,
            `cedula` VARCHAR(20) NOT NULL DEFAULT '',
            `nombre_erp` VARCHAR(255) NOT NULL DEFAULT '',
            -- 'erp' lo trajo el listado; 'manual' lo escribió una persona, y
            -- por eso el importador no lo pisa.
            `origen` VARCHAR(20) NOT NULL DEFAULT 'erp',
            `confirmado_por` INT UNSIGNED NULL DEFAULT NULL,
            `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `actualizado_en` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`sociedad_id`, `proveedor_codigo`),
            KEY `idx_proveedor` (`proveedor_id`),
            KEY `idx_origen` (`origen`),
            KEY `idx_cedula` (`cedula`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /*
         * Las cédulas que sí se decidió no procesar.
         *
         * Un comprobante cuya cédula no está en el catálogo para el sistema y
         * pregunta. A veces la respuesta correcta es "ese proveedor no nos
         * factura, no lo quiero ver más" —publicidad, un correo mal dirigido,
         * un proveedor que se dio de baja—. Sin dónde anotarlo, la misma
         * pregunta volvería con cada comprobante que llegara de esa cédula.
         */
        $this->execute("CREATE TABLE IF NOT EXISTS proveedor_cedula_ignorada (
            `sociedad_id` INT UNSIGNED NOT NULL,
            `cedula` VARCHAR(20) NOT NULL,
            `nombre` VARCHAR(255) NOT NULL DEFAULT '',
            `motivo` VARCHAR(255) NULL DEFAULT NULL,
            `usuario_id` INT UNSIGNED NULL DEFAULT NULL,
            `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`sociedad_id`, `cedula`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /*
         * Las cédulas que un código acepta además de la suya (ver arriba).
         *
         * Con sus claves foráneas: si el proveedor se borra, lo que aceptaba
         * se va con él; si la persona que lo decidió se va, la decisión queda.
         * Una instalación donde usuarios.id todavía tiene signo no puede crear
         * esa clave: ahí la tabla nace sin claves y funciona igual.
         */
        $tambien = "CREATE TABLE IF NOT EXISTS proveedor_codigo_tambien (
            `sociedad_id` INT UNSIGNED NOT NULL,
            `proveedor_codigo` VARCHAR(30) NOT NULL,
            `cedula` VARCHAR(20) NOT NULL,
            `proveedor_id` INT UNSIGNED NOT NULL,
            `nombre` VARCHAR(255) NOT NULL DEFAULT '',
            `usuario_id` INT UNSIGNED NULL DEFAULT NULL,
            `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`sociedad_id`, `proveedor_codigo`, `cedula`),
            KEY `idx_cedula` (`cedula`),
            KEY `idx_proveedor` (`proveedor_id`),
            KEY `idx_usuario` (`usuario_id`)%s
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        try {
            $this->execute(sprintf($tambien, ",
            CONSTRAINT `fk_codigo_tambien_proveedor` FOREIGN KEY (`proveedor_id`)
                REFERENCES `proveedores` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_codigo_tambien_usuario` FOREIGN KEY (`usuario_id`)
                REFERENCES `usuarios` (`id`) ON DELETE SET NULL"));
        } catch (Throwable $e) {
            $this->execute(sprintf($tambien, ''));
        }

        $this->ponerseAlDia();
    }

    /**
     * En las bases ya instaladas, el catálogo nació como un mapa deducido: le
     * sobran la tabla de conflictos y los índices que la servían. Se dejan
     * caer acá porque una tabla que ya nadie escribe pero que sigue ahí es una
     * invitación a que alguien la vuelva a leer dentro de un año.
     */
    private function ponerseAlDia()
    {
        try {
            $this->execute('DROP TABLE IF EXISTS proveedor_codigo_conflictos');

            // Las dos columnas que sostenían la deducción. Se van: una columna
            // muerta con nombre de viva es una invitación a que alguien la
            // vuelva a leer, y lo que medían —cuántas facturas respaldan una
            // conjetura— ya no significa nada cuando la cédula está escrita.
            foreach (['veces_confirmado', 'en_disputa'] as $columna) {
                $tiene = (int) $this->fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proveedor_codigo_erp'
                        AND COLUMN_NAME = ?",
                    [$columna]
                );
                if ($tiene > 0) {
                    $this->execute("ALTER TABLE proveedor_codigo_erp DROP COLUMN `{$columna}`");
                }
            }

            // Lo que quedaba deducido no se hereda: sin cédula escrita detrás,
            // una fila de la cosecha es justo lo que dejaba pasar comprobantes
            // del proveedor equivocado.
            $this->execute("DELETE FROM proveedor_codigo_erp WHERE origen = 'cosecha'");
        } catch (Throwable $e) {
            // Sin permisos de DDL el catálogo funciona igual.
        }

        $this->pasarAPorEmpresa();
    }

    /**
     * Las tablas nacieron siendo una sola para todo el grupo. Ahora cada
     * empresa tiene la suya: se les agrega la empresa a la llave.
     *
     * Las filas que ya estaban se le dan a una empresa solo si hay UNA: ahí no
     * hay nada que adivinar. Con varias, no se sabe de cuál salió cada listado
     * —el archivo no lo guarda, y deducirlo de las facturas es justo el tipo
     * de conjetura que este catálogo vino a eliminar—, así que quedan en la
     * empresa 0, que nadie ve, y cada empresa tiene que importar su listado.
     * El portón de "sin listado no se importa nada" se encarga de pedirlo.
     */
    private function pasarAPorEmpresa()
    {
        $tablas = [
            'proveedor_codigo_erp'      => '`proveedor_codigo`',
            'proveedor_codigo_tambien'  => '`proveedor_codigo`, `cedula`',
            'proveedor_cedula_ignorada' => '`cedula`',
        ];
        foreach ($tablas as $tabla => $llave) {
            try {
                $tiene = (int) $this->fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'sociedad_id'",
                    [$tabla]
                );
                if ($tiene > 0) {
                    continue;
                }
                // Todo en una sola sentencia: si el cambio de llave falla, la
                // columna tampoco queda a medias.
                $this->execute(
                    "ALTER TABLE `{$tabla}`
                        ADD COLUMN `sociedad_id` INT UNSIGNED NOT NULL FIRST,
                        DROP PRIMARY KEY,
                        ADD PRIMARY KEY (`sociedad_id`, {$llave})"
                );
                $unica = $this->fetchAll('SELECT id FROM sociedades LIMIT 2') ?: [];
                if (count($unica) === 1) {
                    $this->execute(
                        "UPDATE `{$tabla}` SET sociedad_id = ?",
                        [(int) $unica[0]['id']]
                    );
                }
            } catch (Throwable $e) {
                // Sin permisos de DDL queda como estaba; las consultas por
                // empresa fallarán y lo dirán, que es mejor que mezclarlas.
            }
        }
    }

    /**
     * La empresa cuyo catálogo toca este modelo. 0 si no hay ninguna.
     *
     * "Todas" no existe para el catálogo: un código es de una empresa, y
     * mezclarlos es exactamente lo que dejaría de separar a dos proveedores
     * con el mismo código en empresas distintas.
     */
    private function empresa()
    {
        return $this->alcanceEsTodas() ? 0 : (int) $this->sociedadId();
    }

    /**
     * Lo mismo para las preguntas estáticas: la empresa que se diga o, si no
     * se dice, la que tiene seleccionada quien hace la petición.
     */
    private static function empresaDe($sociedadId)
    {
        $sociedadId = (int) $sociedadId;
        return $sociedadId > 0 ? $sociedadId : (int) self::sociedadSeleccionadaId();
    }

    /** El error que devuelven las escrituras cuando no hay empresa. */
    private static function sinEmpresa()
    {
        return ['ok' => false, 'message' => 'Elegí una empresa: cada una tiene su propio catálogo de proveedores.'];
    }

    // ── El catálogo del ERP ────────────────────────────────────────────

    /**
     * Enseña el mapa desde el listado de proveedores del ERP.
     *
     * Es la diferencia entre saber y deducir. Hasta acá el mapa se armaba solo
     * desde los emparejamientos que cuadraban de monto, y esa prueba es débil
     * donde los montos son redondos y chicos: un proveedor que factura ₡5.000
     * quedó enganchado a cuatro códigos ajenos porque sus consecutivos chocaban
     * por los últimos ocho dígitos. El listado del ERP dice la cédula de cada
     * código sin adivinar nada, así que manda sobre cualquier contador.
     *
     * Varios códigos con la misma cédula se aprenden TODOS. No es un caso
     * raro ni un error del ERP: una sociedad con varios puntos de venta se da
     * de alta una vez por punto —mismo dueño, mismos comprobantes, códigos
     * distintos— y quedarse con uno solo dejaría a los demás sin guarda.
     * Por eso la llave de la tabla es el código y no la cédula.
     *
     * Un código cuyo proveedor nunca mandó un XML también se aprende, y es de
     * los que más sirven: es el que permite decir "este comprobante NO es de
     * este código" cuando el emparejador ofrece el de otro.
     *
     * El listado entra en el catálogo de la empresa de este modelo, y solo en
     * ese: es el listado de proveedores de esa empresa en el ERP.
     *
     * @param array $filas  Del parser: codigo, cedula, nombre.
     * @param bool  $simular Si es true no escribe nada y solo devuelve el plan.
     */
    public function importarDesdeErp(array $filas, $simular = false)
    {
        require_once __DIR__ . '/Proveedor.php';

        $empresa = $this->empresa();
        if ($empresa <= 0) {
            throw new RuntimeException(self::sinEmpresa()['message']);
        }

        $r = [
            'sociedad_id' => $empresa,
            'leidos' => 0, 'confirmados' => 0, 'corregidos' => 0, 'nuevos' => 0,
            'proveedores_creados' => 0, 'sin_cedula' => 0, 'choca_con_manual' => 0,
            'cambios' => [], 'manuales' => [],
        ];

        $mapa = self::mapa($empresa);
        $proveedores = new Proveedor();
        $porCedula = $this->proveedoresPorCedula();

        foreach ($filas as $fila) {
            $codigo = trim((string) ($fila['codigo'] ?? ''));
            $cedula = preg_replace('/\D/', '', (string) ($fila['cedula'] ?? ''));
            $nombre = trim((string) ($fila['nombre'] ?? ''));
            if ($codigo === '') {
                continue;
            }
            $r['leidos']++;
            if ($cedula === '') {
                $r['sin_cedula']++;
                continue;
            }

            $actual = $mapa[$codigo] ?? null;
            $cedulaActual = $actual ? preg_replace('/\D/', '', (string) $actual['cedula']) : '';

            if ($actual === null) {
                $r['nuevos']++;
            } elseif ($cedulaActual === $cedula) {
                $r['confirmados']++;
            } elseif (($actual['origen'] ?? '') === 'manual') {
                // guardarDesdeErp no le toca la cédula a lo que se decidió a
                // mano. Contarlo como corregido anunciaba un cambio que no
                // ocurre y escondía que el ERP opina distinto: eso es lo que
                // alguien tiene que mirar.
                $r['choca_con_manual']++;
                if (count($r['manuales']) < 200) {
                    $r['manuales'][] = [
                        'codigo' => $codigo,
                        'nombre' => $nombre,
                        'cedula_erp' => $cedula,
                        'cedula_manual' => $cedulaActual,
                    ];
                }
            } else {
                $r['corregidos']++;
                if (count($r['cambios']) < 200) {
                    $r['cambios'][] = [
                        'codigo' => $codigo,
                        'nombre' => $nombre,
                        'cedula_erp' => $cedula,
                        'cedula_mapa' => $cedulaActual,
                    ];
                }
            }

            if ($simular) {
                // Cuántos proveedores habría que dar de alta se cuenta también
                // en seco: son cientos, y enterarse después de apretar el botón
                // no es enterarse.
                if (!isset($porCedula[$cedula])) {
                    $porCedula[$cedula] = 0;
                    $r['proveedores_creados']++;
                }
                continue;
            }

            /*
             * Sin proveedor no hay a quién apuntar. Se crea con la cédula y el
             * nombre del ERP —cortado y todo— porque lo que hace falta de esta
             * fila es la cédula: en cuanto llegue el primer XML de ese emisor,
             * `obtenerOCrear` lo encuentra por cédula y le pone su nombre real.
             */
            $proveedorId = $porCedula[$cedula] ?? 0;
            if ($proveedorId <= 0) {
                $proveedorId = (int) $proveedores->obtenerOCrear($cedula, $nombre !== '' ? $nombre : $cedula);
                if ($proveedorId <= 0) {
                    continue;
                }
                $porCedula[$cedula] = $proveedorId;
                $r['proveedores_creados']++;
            }

            $this->guardarDesdeErp($empresa, $codigo, $proveedorId, $cedula, $nombre);
        }

        if (!$simular) {
            self::olvidarMapa();
        }
        return $r;
    }

    /**
     * Escribe una fila del catálogo.
     *
     * Lo que alguien corrigió a mano no se pisa: el archivo del ERP es la
     * fuente normal, pero si una persona tuvo que arreglar un código es porque
     * sabía algo que el archivo no decía, y volver a exportarlo no puede
     * deshacerlo en silencio.
     */
    private function guardarDesdeErp($empresa, $codigo, $proveedorId, $cedula, $nombre)
    {
        try {
            $this->execute(
                "INSERT INTO proveedor_codigo_erp
                    (sociedad_id, proveedor_codigo, proveedor_id, cedula, nombre_erp, origen)
                 VALUES (?, ?, ?, ?, ?, 'erp')
                 ON DUPLICATE KEY UPDATE
                    proveedor_id = IF(origen = 'manual', proveedor_id, VALUES(proveedor_id)),
                    cedula       = IF(origen = 'manual', cedula, VALUES(cedula)),
                    nombre_erp   = IF(VALUES(nombre_erp) = '', nombre_erp, VALUES(nombre_erp))",
                [(int) $empresa, $codigo, (int) $proveedorId, $cedula, $nombre]
            );
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** cedula => proveedor_id, para no consultar uno por uno en 1.700 filas. */
    private function proveedoresPorCedula()
    {
        $porCedula = [];
        try {
            foreach ($this->fetchAll("SELECT id, rfc FROM proveedores WHERE rfc IS NOT NULL AND rfc <> ''") ?: [] as $f) {
                $limpia = preg_replace('/\D/', '', (string) $f['rfc']);
                if ($limpia !== '') {
                    $porCedula[$limpia] = (int) $f['id'];
                }
            }
        } catch (Throwable $e) {
        }
        return $porCedula;
    }

    /**
     * Los emparejamientos que el catálogo del ERP deja sin sustento.
     *
     * Una factura de un código enganchada a un XML de otra cédula estaba mal
     * desde el principio: ahora hay con qué probarlo. "Otra cédula" es la del
     * catálogo tal como queda después de importar: la del archivo, salvo en
     * los códigos fijados a mano, que conservan la suya. Se listan aparte de la
     * importación porque deshacerlos borra trabajo ya hecho y esa es una
     * decisión de quien mira, no del importador.
     */
    public function emparejamientosQueContradice(array $filas, $limite = 500)
    {
        // Solo las facturas de la empresa del listado: el mismo código en
        // otra empresa puede ser otro proveedor, y el archivo no habla de él.
        $empresa = $this->empresa();
        if ($empresa <= 0) {
            return [];
        }

        $porCodigo = [];
        foreach ($filas as $f) {
            $cedula = preg_replace('/\D/', '', (string) ($f['cedula'] ?? ''));
            $codigo = trim((string) ($f['codigo'] ?? ''));
            if ($codigo !== '' && $cedula !== '') {
                $porCodigo[$codigo] = $cedula;
            }
        }
        if (!$porCodigo) {
            return [];
        }

        // Donde una persona fijó la cédula a mano, la importación no la
        // cambia, así que la que manda sigue siendo esa. Medir con la del
        // archivo soltaba justo las facturas que esa decisión emparejó.
        foreach (self::mapa($empresa) as $codigo => $fila) {
            if (isset($porCodigo[$codigo]) && $fila['origen'] === 'manual' && $fila['cedula'] !== '') {
                $porCodigo[$codigo] = preg_replace('/\D/', '', $fila['cedula']);
            }
        }

        try {
            $filasErp = $this->fetchAll(
                "SELECT e.id, e.proveedor_codigo, e.proveedor_nombre, e.documento, e.monto,
                        e.estado_respaldo, e.factura_xml_id, p.rfc, p.razon_social
                   FROM facturas_erp e
                   JOIN facturas_xml f ON f.id = e.factura_xml_id
                   JOIN proveedores  p ON p.id = f.proveedor_id
                  WHERE e.sociedad_id = ?
                    AND e.proveedor_codigo <> '' AND p.rfc IS NOT NULL AND p.rfc <> ''
                  LIMIT 20000",
                [$empresa]
            ) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        // Lo que una persona decidió que un código también acepta no es una
        // contradicción del listado: el listado solo sabe de la dueña.
        $aceptadas = [];
        foreach (self::leerTambien($empresa) as $t) {
            $aceptadas[(string) $t['proveedor_codigo']][self::normalizarCedula($t['cedula'])] = true;
        }

        $malos = [];
        foreach ($filasErp as $f) {
            $esperada = $porCodigo[$f['proveedor_codigo']] ?? '';
            if ($esperada === '') {
                continue;
            }
            $tiene = preg_replace('/\D/', '', (string) $f['rfc']);
            if ($tiene === $esperada || isset($aceptadas[$f['proveedor_codigo']][$tiene])) {
                continue;
            }
            $malos[] = $f;
            if (count($malos) >= $limite) {
                break;
            }
        }
        return $malos;
    }

    /**
     * Suelta los emparejamientos que el catálogo desmiente.
     *
     * La factura vuelve a "sin respaldo", que es lo que honestamente es: tenía
     * colgado el comprobante de otra cédula. Duele verlo —cinco facturas que
     * figuraban respaldadas dejan de estarlo— pero figurar respaldada con el
     * XML de otro es peor que figurar sin respaldo: lo segundo se persigue, lo
     * primero se da por hecho y nadie lo vuelve a mirar.
     *
     * @return int Cuántos se soltaron.
     */
    public function desatarEmparejamientosContradichos(array $filas)
    {
        $malos = $this->emparejamientosQueContradice($filas, 5000);
        if (!$malos) {
            return 0;
        }

        $ids = array_values(array_unique(array_map('intval', array_column($malos, 'id'))));
        $sueltos = 0;
        // Por tandas: son pocos hoy, pero una consulta por fila contra la base
        // fuera de la oficina es lo que ya costó minutos en otras pantallas.
        foreach (array_chunk($ids, 200) as $tanda) {
            $huecos = implode(',', array_fill(0, count($tanda), '?'));
            // Los dos scores se borran con el enlace: quedarían describiendo
            // el parecido con un comprobante que la fila ya no tiene.
            $sueltos += (int) $this->execute(
                "UPDATE facturas_erp
                    SET factura_xml_id = NULL,
                        estado_respaldo = 'sin_respaldo',
                        score_numero = NULL,
                        score_proveedor = NULL
                  WHERE id IN ({$huecos})",
                $tanda
            );
        }
        return $sueltos;
    }

    /** Qué tan lleno está el catálogo, y de dónde salió cada fila. */
    public function resumenDelMapa()
    {
        $resumen = [
            'total' => 0, 'erp' => 0, 'manual' => 0,
            'cedulas' => 0, 'ignoradas' => 0, 'sin_codigo' => 0,
        ];
        $empresa = $this->empresa();
        if ($empresa <= 0) {
            return $resumen;
        }
        try {
            foreach ($this->fetchAll(
                'SELECT origen, COUNT(*) n FROM proveedor_codigo_erp WHERE sociedad_id = ? GROUP BY origen',
                [$empresa]
            ) ?: [] as $f) {
                $resumen['total'] += (int) $f['n'];
                $origen = (string) $f['origen'];
                if (isset($resumen[$origen])) {
                    $resumen[$origen] = (int) $f['n'];
                }
            }
            // Cédulas distintas, que no son lo mismo que códigos: una sociedad
            // con varios puntos de venta ocupa varios códigos y una sola.
            $resumen['cedulas'] = (int) $this->fetchColumn(
                "SELECT COUNT(DISTINCT cedula) FROM proveedor_codigo_erp WHERE sociedad_id = ? AND cedula <> ''",
                [$empresa]
            );
            $resumen['ignoradas'] = (int) $this->fetchColumn(
                'SELECT COUNT(*) FROM proveedor_cedula_ignorada WHERE sociedad_id = ?',
                [$empresa]
            );
            $resumen['sin_codigo'] = $this->cuantosSinCodigo();
        } catch (Throwable $e) {
        }
        return $resumen;
    }

    // ── Lectura ────────────────────────────────────────────────────────

    /**
     * proveedor_codigo => ['proveedor_id', 'cedula', 'origen', 'hermanos',
     *                      'tambien' => [proveedor_id => ['cedula', 'hermanos']]]
     *
     * `tambien` son las cédulas que el código acepta además de la suya; cada
     * una con sus propios hermanos, porque la cédula aceptada puede ser dueña
     * de otros códigos.
     *
     * El de una empresa: la que se diga o, si no, la seleccionada. Sin
     * empresa el mapa está vacío.
     */
    public static function mapa($sociedadId = 0)
    {
        $empresa = self::empresaDe($sociedadId);
        if (isset(self::$mapa[$empresa])) {
            return self::$mapa[$empresa];
        }
        if ($empresa <= 0) {
            return [];
        }

        $mapa = [];
        try {
            $filas = (new self())->fetchAll(
                'SELECT proveedor_codigo, proveedor_id, cedula, origen
                   FROM proveedor_codigo_erp WHERE sociedad_id = ?',
                [$empresa]
            ) ?: [];
            foreach ($filas as $f) {
                $mapa[(string) $f['proveedor_codigo']] = [
                    'proveedor_id' => (int) $f['proveedor_id'],
                    'cedula'       => (string) $f['cedula'],
                    'origen'       => (string) $f['origen'],
                    'tambien'      => [],
                ];
            }

            // Un código que ya no está en el catálogo no acepta nada: su fila
            // de "también" quedó colgando y no se lee.
            foreach (self::leerTambien($empresa) as $t) {
                $codigo = (string) $t['proveedor_codigo'];
                if (isset($mapa[$codigo])) {
                    $mapa[$codigo]['tambien'][(int) $t['proveedor_id']] = [
                        'cedula' => (string) $t['cedula'],
                    ];
                }
            }

            /*
             * Cuántos códigos comparten cada cédula.
             *
             * Se cuenta acá, una vez, porque es lo que decide si la guarda
             * puede opinar: con un solo código la cédula identifica; con tres
             * —CEDI, MAYOREO y PROMOCIONES de la misma empresa— dice que el
             * comprobante es del grupo, y nada más. Distinguir dentro del
             * grupo no lo puede hacer la cédula, y fingir que sí es el hueco
             * por el que se colaron los enganches malos.
             */
            $cuantos = [];
            foreach ($mapa as $fila) {
                // Una cédula aceptada cuenta como un código más de esa cédula:
                // si también es dueña de otro, su comprobante ya no dice de
                // cuál de los dos es, y lo tiene que elegir una persona.
                foreach (array_merge([$fila['cedula']], array_column($fila['tambien'], 'cedula')) as $ced) {
                    if ($ced !== '') {
                        $cuantos[$ced] = ($cuantos[$ced] ?? 0) + 1;
                    }
                }
            }
            foreach ($mapa as $codigo => $fila) {
                $mapa[$codigo]['hermanos'] = max(0, ($cuantos[$fila['cedula']] ?? 1) - 1);
                foreach ($fila['tambien'] as $pid => $t) {
                    $mapa[$codigo]['tambien'][$pid]['hermanos'] = max(0, ($cuantos[$t['cedula']] ?? 1) - 1);
                }
            }
        } catch (Throwable $e) {
            // Instalación sin la tabla todavía: sin catálogo, sin guarda.
            $mapa = [];
        }

        return self::$mapa[$empresa] = $mapa;
    }

    /**
     * ¿Hay listado cargado en esta empresa?
     *
     * Es la pregunta del portón: sin listado no se importa nada, porque una
     * factura que entra antes que la lista se empareja a ciegas y después
     * nadie vuelve a mirarla. Se responde con el mapa ya cacheado, así que
     * preguntarlo en cada pantalla no cuesta una consulta.
     *
     * Por empresa: que otra tenga su listado no le sirve a esta, sus códigos
     * son otros.
     */
    public static function hayListado($sociedadId = 0)
    {
        return self::mapa($sociedadId) !== [];
    }

    public static function olvidarMapa()
    {
        self::$mapa = [];
        self::$cedulas = [];
    }

    /**
     * ¿Este comprobante lo emitió el proveedor de este código del ERP?
     *
     *   'propio'      -> sí, y la cédula identifica a uno solo; se acepta
     *   'compartido'  -> la cédula coincide, pero la comparten varios códigos
     *                    de la misma empresa. El comprobante es del grupo y no
     *                    hay forma de saber de cuál de ellos: no se engancha
     *                    solo, lo elige una persona. Salvo con el consecutivo
     *                    exacto y el monto al colón, que identifican el
     *                    documento sin preguntar de qué punto salió.
     *   'ajeno'       -> no; se rechaza y se sigue buscando
     *   'desconocido' -> no se pudo consultar el listado (sin base de datos:
     *                    pruebas, utilidades sueltas). En la aplicación no
     *                    ocurre, porque sin listado no se importa nada.
     *
     * $sociedadId es la empresa de la factura del ERP: el código se busca en
     * SU catálogo. Sin ella, la seleccionada.
     */
    public static function veredicto($codigo, $proveedorIdXml, $sociedadId = 0)
    {
        $codigo = trim((string) $codigo);
        $proveedorIdXml = (int) $proveedorIdXml;
        if ($codigo === '' || $proveedorIdXml <= 0) {
            return 'desconocido';
        }

        $mapa = self::mapa($sociedadId);

        /*
         * Catálogo vacío: la guarda no opina.
         *
         * Es la red de seguridad que impide que una instalación recién puesta
         * —o una a la que todavía no se le cargó el listado del ERP— se quede
         * sin emparejar ni una factura. Sin esto, "el código no está en el
         * catálogo" y "no hay catálogo" se confundirían, y la segunda no es una
         * afirmación sobre el proveedor: es no saber nada.
         */
        if (!$mapa) {
            return 'desconocido';
        }

        /*
         * Un código que el catálogo no tiene NO empareja con nada.
         *
         * Es deliberado y es estricto: el catálogo es la lista de proveedores
         * del ERP, así que un código que no aparece ahí es uno que nadie dio
         * de alta —o que vino en un grupo que no se exportó—. Emparejarlo "a
         * ver si suena" es exactamente cómo se colaron los enlaces malos que
         * esto vino a arreglar. La salida normal es agregar el código, que se
         * puede hacer desde la pantalla.
         *
         * Hay una sola excepción, y no vive acá: con el consecutivo EXACTO y
         * el nombre del proveedor parecido, quien empareja sí acepta el código
         * que falta (ver fueraDelCatalogo). Lo pidió el usuario el 28/09: los
         * códigos nuevos del ERP —viáticos, autoconsumo— no pueden quedarse
         * sin respaldo mientras nadie exporta el listado. El número corto
         * sigue sin tener esa salida.
         */
        $fila = $mapa[$codigo] ?? null;
        if ($fila === null) {
            return 'ajeno';
        }
        if ($fila['proveedor_id'] !== $proveedorIdXml) {
            // No es la dueña, pero puede ser una cédula que alguien decidió
            // que este código también acepta.
            $aceptada = $fila['tambien'][$proveedorIdXml] ?? null;
            if ($aceptada === null) {
                return 'ajeno';
            }
            return empty($aceptada['hermanos']) ? 'propio' : 'compartido';
        }

        // La cédula coincide, pero si la comparten varios códigos lo único
        // que prueba es que el comprobante salió de esa empresa. Cuál de sus
        // puntos lo emitió no está en la cédula, y adivinarlo es justamente
        // lo que hay que dejar de hacer.
        return empty($fila['hermanos']) ? 'propio' : 'compartido';
    }

    /**
     * ¿El código falta en un catálogo que sí existe?
     *
     * `veredicto` dice 'ajeno' tanto para un código que es de otra cédula como
     * para uno que el catálogo no tiene, y no son lo mismo: el primero es una
     * afirmación ("este comprobante no es suyo"), el segundo es no saber. Quien
     * quiera darle al segundo otra salida —el consecutivo exacto con el nombre
     * del proveedor— necesita poder distinguirlos.
     *
     * Sin catálogo responde false: ahí el veredicto ya es 'desconocido'.
     */
    public static function fueraDelCatalogo($codigo, $sociedadId = 0)
    {
        $codigo = trim((string) $codigo);
        if ($codigo === '') {
            return false;
        }
        $mapa = self::mapa($sociedadId);
        return $mapa && !isset($mapa[$codigo]);
    }

    /**
     * ¿Este comprobante pertenece a algún código del ERP?
     *
     * Es la pregunta al revés, la que se hace al importar un XML: no "¿de
     * quién es este código?" sino "¿este emisor le factura a la empresa?".
     * Si la respuesta es no, no hay ninguna factura del ERP a la que pegarlo
     * y hay que preguntarle a una persona.
     *
     * Una cédula que se decidió ignorar cuenta como conocida: ya se contestó
     * una vez que ese proveedor no va, y volver a preguntarlo sería empezar de
     * nuevo cada semana.
     *
     * $sociedadId es la empresa que recibió el comprobante: que el emisor esté
     * en el catálogo de otra no dice que le facture a esta.
     */
    public static function conoceCedula($cedula, $sociedadId = 0)
    {
        $cedula = self::normalizarCedula($cedula);
        if ($cedula === '') {
            return false;
        }
        $cedulas = self::cedulas(self::empresaDe($sociedadId));
        // Sin catálogo cargado no se puede afirmar que una cédula sea
        // desconocida: no habría con qué compararla.
        return !$cedulas || isset($cedulas[$cedula]);
    }

    /** cedula => true, con las ignoradas dentro, de una empresa. */
    private static function cedulas($empresa)
    {
        if (isset(self::$cedulas[$empresa])) {
            return self::$cedulas[$empresa];
        }
        if ($empresa <= 0) {
            return [];
        }

        $cedulas = [];
        try {
            $modelo = new self();
            foreach ($modelo->fetchAll(
                "SELECT DISTINCT cedula FROM proveedor_codigo_erp WHERE sociedad_id = ? AND cedula <> ''",
                [$empresa]
            ) ?: [] as $f) {
                $cedulas[self::normalizarCedula($f['cedula'])] = true;
            }
            foreach ($modelo->fetchAll(
                'SELECT cedula FROM proveedor_cedula_ignorada WHERE sociedad_id = ?',
                [$empresa]
            ) ?: [] as $f) {
                $cedulas[self::normalizarCedula($f['cedula'])] = true;
            }
            foreach (self::leerTambien($empresa) as $f) {
                $cedulas[self::normalizarCedula($f['cedula'])] = true;
            }
            unset($cedulas['']);
        } catch (Throwable $e) {
            $cedulas = [];
        }
        return self::$cedulas[$empresa] = $cedulas;
    }

    /**
     * Las cédulas se comparan solo por sus dígitos.
     *
     * El ERP las imprime a veces con guiones ("3-101-155686") y los XML nunca;
     * sin limpiarlas, la misma cédula escrita de dos formas parecerían dos.
     *
     * Los ceros de la izquierda NO se quitan: en Costa Rica ninguna cédula
     * empieza en cero, así que quitarlos no arregla nada y sí podría juntar
     * dos identificaciones distintas de las que vienen de fuera.
     */
    public static function normalizarCedula($cedula)
    {
        return preg_replace('/\D/', '', (string) $cedula);
    }

    public function porCodigo($codigo)
    {
        if ($this->empresa() <= 0) {
            return null;
        }
        try {
            return $this->fetchOne(
                'SELECT m.*, p.razon_social
                   FROM proveedor_codigo_erp m
                   LEFT JOIN proveedores p ON p.id = m.proveedor_id
                  WHERE m.sociedad_id = ? AND m.proveedor_codigo = ? LIMIT 1',
                [$this->empresa(), (string) $codigo]
            ) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ── Mantenerlo a mano ──────────────────────────────────────────────

    /**
     * Una página del catálogo, con búsqueda por código, cédula o nombre.
     *
     * Paginado porque son 1.700 filas y subiendo: pintarlas todas convierte la
     * pantalla de configuración en la más lenta del sistema por una tabla que
     * casi siempre se abre para buscar una sola.
     */
    public function listar($buscar = '', $limite = 50, $desde = 0)
    {
        $buscar = trim((string) $buscar);
        $empresa = $this->empresa();
        if ($empresa <= 0) {
            return ['filas' => [], 'total' => 0];
        }
        $where = 'WHERE m.sociedad_id = ?';
        $params = [$empresa];
        if ($buscar !== '') {
            // También por el nombre con que el proveedor factura, que es el
            // que uno tiene a mano; el del ERP viene cortado.
            $o = "m.proveedor_codigo LIKE ? OR m.cedula LIKE ? OR m.nombre_erp LIKE ?
                         OR p.razon_social LIKE ?";
            $like = '%' . addcslashes($buscar, '%_\\') . '%';
            array_push($params, $like, $like, $like, $like);
            $digitos = preg_replace('/[\s-]+/', '', $buscar);
            if ($digitos !== $buscar && ctype_digit($digitos)) {
                $o .= ' OR m.cedula LIKE ?';
                $params[] = '%' . $digitos . '%';
            }
            $where .= " AND ({$o})";
        }

        try {
            $filas = $this->fetchAll(
                "SELECT m.proveedor_codigo, m.cedula, m.nombre_erp, m.origen, m.creado_en,
                        p.razon_social
                   FROM proveedor_codigo_erp m
                   LEFT JOIN proveedores p ON p.id = m.proveedor_id
                   {$where}
                  ORDER BY m.proveedor_codigo
                  LIMIT " . max(1, (int) $limite) . ' OFFSET ' . max(0, (int) $desde),
                $params
            ) ?: [];
            $total = (int) $this->fetchColumn(
                "SELECT COUNT(*) FROM proveedor_codigo_erp m
                   LEFT JOIN proveedores p ON p.id = m.proveedor_id {$where}",
                $params
            );

            // Lo que cada código acepta además de su dueña, para verlo y
            // poder quitarlo desde la misma fila.
            $porCodigo = [];
            foreach (self::leerTambien($empresa, array_column($filas, 'proveedor_codigo')) as $t) {
                $porCodigo[(string) $t['proveedor_codigo']][] = [
                    'cedula' => (string) $t['cedula'],
                    'nombre' => (string) ($t['razon_social'] ?: $t['nombre']),
                ];
            }
            foreach ($filas as &$fila) {
                $fila['tambien'] = $porCodigo[(string) $fila['proveedor_codigo']] ?? [];
            }
            unset($fila);

            return ['filas' => $filas, 'total' => $total];
        } catch (Throwable $e) {
            return ['filas' => [], 'total' => 0];
        }
    }

    // ── Elegir un código ───────────────────────────────────────────────

    /**
     * Códigos que coinciden con lo escrito: por código, nombre o cédula.
     *
     * Salen de dos lados. El catálogo, que ya tiene cédula, y las facturas
     * del ERP, que traen código y nombre aunque ese código no esté anotado
     * todavía; esos son justamente los candidatos más probables para un
     * proveedor que no tiene código, y por eso van primero.
     *
     * @return array Filas con codigo, nombre, cedula, razon_social y facturas.
     */
    public function opcionesDeCodigo($buscar, $limite = 30)
    {
        $buscar = trim((string) $buscar);
        if ($buscar === '') {
            return [];
        }
        $filas = $this->candidatosDeCodigo([$buscar]);

        $exacto = mb_strtolower($buscar);
        usort($filas, function ($a, $b) use ($exacto) {
            $ea = mb_strtolower((string) $a['codigo']) === $exacto;
            $eb = mb_strtolower((string) $b['codigo']) === $exacto;
            if ($ea !== $eb) {
                return $ea ? -1 : 1;
            }
            $la = (string) $a['cedula'] === '';
            $lb = (string) $b['cedula'] === '';
            if ($la !== $lb) {
                return $la ? -1 : 1;
            }
            return strcmp((string) $a['nombre'], (string) $b['nombre']);
        });
        return array_slice($filas, 0, max(1, (int) $limite));
    }

    /**
     * Los códigos cuyo nombre se parece al del proveedor.
     *
     * Por palabras y no por el nombre entero: el ERP corta los nombres por el
     * ancho de la columna y los escribe a su manera ("ARAYA HIDALGO ROBERTO"),
     * así que el nombre completo casi nunca coincide y dos palabras sí.
     */
    public function opcionesParecidas($nombre, $limite = 10)
    {
        $palabras = self::palabrasDelNombre($nombre);
        if (empty($palabras)) {
            return [];
        }

        $conPuntaje = [];
        foreach ($this->candidatosDeCodigo($palabras) as $fila) {
            $texto = mb_strtolower(self::sinTildes($fila['nombre'] . ' ' . $fila['razon_social']));
            $puntos = 0;
            foreach ($palabras as $p) {
                if (mb_strpos($texto, mb_strtolower(self::sinTildes($p))) !== false) {
                    $puntos++;
                }
            }
            if ($puntos > 0) {
                $fila['coinciden'] = $puntos;
                $conPuntaje[] = $fila;
            }
        }

        // Con varias palabras, una sola coincidencia es ruido ("SODA", "LA").
        $minimo = count($palabras) >= 2 ? 2 : 1;
        $conPuntaje = array_values(array_filter($conPuntaje, function ($f) use ($minimo) {
            return $f['coinciden'] >= $minimo;
        }));
        usort($conPuntaje, function ($a, $b) {
            if ($a['coinciden'] !== $b['coinciden']) {
                return $b['coinciden'] - $a['coinciden'];
            }
            $la = (string) $a['cedula'] === '';
            $lb = (string) $b['cedula'] === '';
            if ($la !== $lb) {
                return $la ? -1 : 1;
            }
            return $b['facturas'] - $a['facturas'];
        });
        return array_slice($conPuntaje, 0, max(1, (int) $limite));
    }

    /**
     * Un código por fila, con lo que se sabe de él, que coincida con
     * cualquiera de los textos. Sin orden: lo ordena quien pregunta.
     */
    private function candidatosDeCodigo(array $textos)
    {
        // De la empresa y de nada más: un código de las facturas de otra
        // empresa puede ser otro proveedor, y elegirlo acá lo daría de alta
        // con la cédula equivocada.
        $empresa = $this->empresa();
        if ($empresa <= 0) {
            return [];
        }

        $condCatalogo = [];
        $condErp = [];
        $pCatalogo = [];
        $pErp = [];
        foreach ($textos as $t) {
            $like = '%' . addcslashes((string) $t, '%_\\') . '%';
            $condCatalogo[] = '(m.proveedor_codigo LIKE ? OR m.nombre_erp LIKE ? OR p.razon_social LIKE ? OR m.cedula LIKE ?)';
            array_push($pCatalogo, $like, $like, $like, $like);
            $condErp[] = '(e.proveedor_codigo LIKE ? OR e.proveedor_nombre LIKE ?)';
            array_push($pErp, $like, $like);

            // La cédula se busca también sin guiones ni espacios, que es como
            // se guarda: "3-101-610198" tiene que encontrar 3101610198.
            $digitos = preg_replace('/[\s-]+/', '', (string) $t);
            if ($digitos !== (string) $t && ctype_digit($digitos)) {
                $condCatalogo[] = 'm.cedula LIKE ?';
                $pCatalogo[] = '%' . $digitos . '%';
            }
        }

        $porCodigo = [];
        try {
            $catalogo = $this->fetchAll(
                'SELECT m.proveedor_codigo AS codigo, m.nombre_erp AS nombre, m.cedula,
                        COALESCE(p.razon_social, \'\') AS razon_social
                   FROM proveedor_codigo_erp m
                   LEFT JOIN proveedores p ON p.id = m.proveedor_id
                  WHERE m.sociedad_id = ? AND (' . implode(' OR ', $condCatalogo) . ')
                  LIMIT 200',
                array_merge([$empresa], $pCatalogo)
            ) ?: [];
            foreach ($catalogo as $f) {
                $porCodigo[(string) $f['codigo']] = [
                    'codigo' => (string) $f['codigo'],
                    'nombre' => (string) $f['nombre'],
                    'cedula' => (string) $f['cedula'],
                    'razon_social' => (string) $f['razon_social'],
                    'facturas' => 0,
                ];
            }
        } catch (Throwable $e) {
        }

        try {
            $erp = $this->fetchAll(
                'SELECT e.proveedor_codigo AS codigo, MAX(e.proveedor_nombre) AS nombre, COUNT(*) AS facturas
                   FROM facturas_erp e
                  WHERE e.sociedad_id = ? AND e.proveedor_codigo <> \'\' AND (' . implode(' OR ', $condErp) . ')
                  GROUP BY e.proveedor_codigo
                  LIMIT 200',
                array_merge([$empresa], $pErp)
            ) ?: [];
            $faltan = [];
            foreach ($erp as $f) {
                $codigo = (string) $f['codigo'];
                if (isset($porCodigo[$codigo])) {
                    $porCodigo[$codigo]['facturas'] = (int) $f['facturas'];
                    if ($porCodigo[$codigo]['nombre'] === '') {
                        $porCodigo[$codigo]['nombre'] = (string) $f['nombre'];
                    }
                    continue;
                }
                $porCodigo[$codigo] = [
                    'codigo' => $codigo, 'nombre' => (string) $f['nombre'],
                    'cedula' => '', 'razon_social' => '', 'facturas' => (int) $f['facturas'],
                ];
                $faltan[] = $codigo;
            }

            // Que el nombre del ERP haya coincidido no quiere decir que el
            // código esté libre: puede estar en el catálogo con otro nombre.
            if ($faltan) {
                $marcas = implode(',', array_fill(0, count($faltan), '?'));
                foreach ($this->fetchAll(
                    "SELECT m.proveedor_codigo AS codigo, m.cedula, COALESCE(p.razon_social, '') AS razon_social
                       FROM proveedor_codigo_erp m
                       LEFT JOIN proveedores p ON p.id = m.proveedor_id
                      WHERE m.sociedad_id = ? AND m.proveedor_codigo IN ({$marcas})",
                    array_merge([$empresa], $faltan)
                ) ?: [] as $m) {
                    $porCodigo[(string) $m['codigo']]['cedula'] = (string) $m['cedula'];
                    $porCodigo[(string) $m['codigo']]['razon_social'] = (string) $m['razon_social'];
                }
            }
        } catch (Throwable $e) {
            // Sin la tabla del ERP todavía, el catálogo alcanza.
        }

        return array_values($porCodigo);
    }

    /**
     * ¿El código existe en algún lado? En la lista o en las facturas del ERP.
     *
     * Es la condición para elegirlo desde "Por resolver": un código escrito a
     * mano que no existe es casi siempre una cédula o un número mal copiado.
     */
    public function codigoExiste($codigo)
    {
        $codigo = trim((string) $codigo);
        $empresa = $this->empresa();
        if ($codigo === '' || $empresa <= 0) {
            return false;
        }
        if ((int) $this->fetchColumn(
            'SELECT COUNT(*) FROM proveedor_codigo_erp WHERE sociedad_id = ? AND proveedor_codigo = ?',
            [$empresa, $codigo]
        ) > 0) {
            return true;
        }
        try {
            return (int) $this->fetchColumn(
                'SELECT COUNT(*) FROM facturas_erp WHERE sociedad_id = ? AND proveedor_codigo = ?',
                [$empresa, $codigo]
            ) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Las palabras de un nombre que sirven para buscarlo. */
    private static function palabrasDelNombre($nombre)
    {
        static $vacias = [
            'sociedad', 'anonima', 'limitada', 'responsabilidad', 'null', 'costa', 'rica',
            'compania', 'empresa', 'grupo', 'para', 'con', 'los', 'las', 'del', 'por',
        ];
        $palabras = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', self::sinTildes(mb_strtolower((string) $nombre))) as $p) {
            if (mb_strlen($p) >= 3 && !ctype_digit($p) && !in_array($p, $vacias, true)) {
                $palabras[$p] = true;
            }
        }
        return array_slice(array_keys($palabras), 0, 5);
    }

    private static function sinTildes($texto)
    {
        return strtr((string) $texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);
    }

    /**
     * Da de alta o corrige un código a mano.
     *
     * Es la puerta por la que entra un proveedor nuevo sin tener que volver a
     * exportar el listado entero del ERP, y la que arregla un código que se dio
     * de alta con la cédula equivocada.
     *
     * Queda con `origen = 'manual'`, que el importador del catálogo respeta:
     * si alguien corrigió algo a mano es porque sabía más que el archivo.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public function guardarCodigo($codigo, $cedula, $nombre = '', $usuarioId = 0)
    {
        require_once __DIR__ . '/Proveedor.php';

        $codigo = trim((string) $codigo);
        $cedula = self::normalizarCedula($cedula);
        $nombre = trim((string) $nombre);

        if ($codigo === '') {
            return ['ok' => false, 'message' => 'Falta el código del proveedor.'];
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,30}$/', $codigo)) {
            return ['ok' => false, 'message' => 'El código solo admite letras, números, punto, guion y guion bajo.'];
        }
        // Nueve dígitos la física, diez la jurídica, once o doce la DIMEX.
        if (strlen($cedula) < 9 || strlen($cedula) > 12) {
            return ['ok' => false, 'message' => 'La cédula tiene que tener entre 9 y 12 dígitos.'];
        }
        $empresa = $this->empresa();
        if ($empresa <= 0) {
            return self::sinEmpresa();
        }

        try {
            $proveedorId = (int) (new Proveedor())->obtenerOCrear($cedula, $nombre !== '' ? $nombre : $cedula);
            if ($proveedorId <= 0) {
                return ['ok' => false, 'message' => 'No se pudo registrar el proveedor de esa cédula.'];
            }

            $this->execute(
                "INSERT INTO proveedor_codigo_erp
                    (sociedad_id, proveedor_codigo, proveedor_id, cedula, nombre_erp, origen, confirmado_por)
                 VALUES (?, ?, ?, ?, ?, 'manual', ?)
                 ON DUPLICATE KEY UPDATE
                    proveedor_id = VALUES(proveedor_id),
                    cedula       = VALUES(cedula),
                    nombre_erp   = IF(VALUES(nombre_erp) = '', nombre_erp, VALUES(nombre_erp)),
                    origen       = 'manual',
                    confirmado_por = VALUES(confirmado_por)",
                [$empresa, $codigo, $proveedorId, $cedula, $nombre, (int) $usuarioId > 0 ? (int) $usuarioId : null]
            );

            // Dar de alta un código puede contestar una pregunta abierta: si
            // esa cédula estaba marcada como "no procesar", ya no lo está.
            $this->execute(
                'DELETE FROM proveedor_cedula_ignorada WHERE sociedad_id = ? AND cedula = ?',
                [$empresa, $cedula]
            );

            self::olvidarMapa();
            return ['ok' => true, 'message' => 'Código ' . $codigo . ' guardado.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo guardar: ' . $e->getMessage()];
        }
    }

    /**
     * Que un código acepte, además de la de su dueño, los comprobantes de
     * otra cédula.
     *
     * Es la salida para cuando el código ya tiene dueño y el comprobante de
     * otra cédula también le corresponde: el dueño no cambia —sus facturas
     * siguen aceptando lo suyo— y el código acepta además lo de la otra. Lo
     * decide una persona para ESTE código; no se deduce ni se hereda.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public function aceptarTambien($codigo, $cedula, $nombre = '', $usuarioId = 0)
    {
        require_once __DIR__ . '/Proveedor.php';

        $codigo = trim((string) $codigo);
        $cedula = self::normalizarCedula($cedula);
        $nombre = trim((string) $nombre);

        if ($codigo === '') {
            return ['ok' => false, 'message' => 'Falta el código del proveedor.'];
        }
        if (strlen($cedula) < 9 || strlen($cedula) > 12) {
            return ['ok' => false, 'message' => 'La cédula tiene que tener entre 9 y 12 dígitos.'];
        }
        $empresa = $this->empresa();
        if ($empresa <= 0) {
            return self::sinEmpresa();
        }

        try {
            $dueno = $this->porCodigo($codigo);
            // Sin dueño no hay nada que compartir: lo que corresponde es
            // dárselo, que es guardarCodigo().
            if (!$dueno) {
                return ['ok' => false, 'message' => 'El código ' . $codigo . ' todavía no tiene dueño: '
                    . 'dáselo a este proveedor en vez de compartirlo.'];
            }
            if (self::normalizarCedula($dueno['cedula']) === $cedula) {
                return ['ok' => true, 'message' => 'El código ' . $codigo . ' ya es de esa cédula.'];
            }

            $proveedorId = (int) (new Proveedor())->obtenerOCrear($cedula, $nombre !== '' ? $nombre : $cedula);
            if ($proveedorId <= 0) {
                return ['ok' => false, 'message' => 'No se pudo registrar el proveedor de esa cédula.'];
            }

            $this->execute(
                "INSERT INTO proveedor_codigo_tambien
                    (sociedad_id, proveedor_codigo, cedula, proveedor_id, nombre, usuario_id)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    proveedor_id = VALUES(proveedor_id),
                    nombre       = IF(VALUES(nombre) = '', nombre, VALUES(nombre))",
                [$empresa, $codigo, $cedula, $proveedorId, mb_substr($nombre, 0, 255),
                 (int) $usuarioId > 0 ? (int) $usuarioId : null]
            );

            // Igual que al darle un código: si esa cédula estaba marcada como
            // "no procesar", la decisión de aceptarla la contesta.
            $this->execute(
                'DELETE FROM proveedor_cedula_ignorada WHERE sociedad_id = ? AND cedula = ?',
                [$empresa, $cedula]
            );

            self::olvidarMapa();
            return ['ok' => true, 'message' => 'El código ' . $codigo . ' acepta ahora también los '
                . 'comprobantes de la cédula ' . $cedula . '.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo guardar: ' . $e->getMessage()];
        }
    }

    /** Deja de aceptar en un código los comprobantes de una cédula que no es su dueña. */
    public function dejarDeAceptar($codigo, $cedula)
    {
        $codigo = trim((string) $codigo);
        $cedula = self::normalizarCedula($cedula);
        $empresa = $this->empresa();
        if ($empresa <= 0) {
            return self::sinEmpresa();
        }
        try {
            $filas = (int) $this->execute(
                'DELETE FROM proveedor_codigo_tambien WHERE sociedad_id = ? AND proveedor_codigo = ? AND cedula = ?',
                [$empresa, $codigo, $cedula]
            );
            self::olvidarMapa();
            return $filas > 0
                ? ['ok' => true, 'message' => 'El código ' . $codigo . ' ya no acepta los comprobantes de ' . $cedula . '.']
                : ['ok' => false, 'message' => 'Ese código no aceptaba esa cédula.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo quitar: ' . $e->getMessage()];
        }
    }

    /**
     * Las filas de proveedor_codigo_tambien de una empresa, de todos sus
     * códigos o de estos. Vacío si la tabla todavía no existe: sin ella el
     * catálogo es el de antes.
     */
    private static function leerTambien($empresa, ?array $codigos = null)
    {
        $empresa = (int) $empresa;
        if ($empresa <= 0) {
            return [];
        }
        try {
            $modelo = new self();
            if ($codigos === null) {
                return $modelo->fetchAll(
                    'SELECT proveedor_codigo, cedula, proveedor_id
                       FROM proveedor_codigo_tambien WHERE sociedad_id = ?',
                    [$empresa]
                ) ?: [];
            }
            $codigos = array_values(array_unique(array_map('strval', $codigos)));
            if (!$codigos) {
                return [];
            }
            $marcas = implode(',', array_fill(0, count($codigos), '?'));
            return $modelo->fetchAll(
                "SELECT t.proveedor_codigo, t.cedula, t.proveedor_id, t.nombre,
                        COALESCE(p.razon_social, '') AS razon_social
                   FROM proveedor_codigo_tambien t
                   LEFT JOIN proveedores p ON p.id = t.proveedor_id
                  WHERE t.sociedad_id = ? AND t.proveedor_codigo IN ({$marcas})
                  ORDER BY t.creado_en",
                array_merge([$empresa], $codigos)
            ) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Borra un código del catálogo.
     *
     * Borrar no es inocuo y por eso se dice en pantalla: un código fuera del
     * catálogo deja de emparejar con nada. Es lo correcto cuando el código se
     * dio de baja en el ERP, y es un tiro en el pie cuando se borra por error.
     */
    public function eliminarCodigo($codigo)
    {
        $codigo = trim((string) $codigo);
        if ($codigo === '') {
            return ['ok' => false, 'message' => 'Falta el código.'];
        }
        $empresa = $this->empresa();
        if ($empresa <= 0) {
            return self::sinEmpresa();
        }
        try {
            $filas = (int) $this->execute(
                'DELETE FROM proveedor_codigo_erp WHERE sociedad_id = ? AND proveedor_codigo = ?',
                [$empresa, $codigo]
            );
            // Lo que aceptaba además de su dueño se va con él: un código fuera
            // del catálogo no empareja con nada, tampoco con eso.
            $this->execute(
                'DELETE FROM proveedor_codigo_tambien WHERE sociedad_id = ? AND proveedor_codigo = ?',
                [$empresa, $codigo]
            );
            self::olvidarMapa();
            return $filas > 0
                ? ['ok' => true, 'message' => 'Código ' . $codigo . ' eliminado de la lista.']
                : ['ok' => false, 'message' => 'Ese código no estaba en la lista.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo eliminar: ' . $e->getMessage()];
        }
    }

    // ── Cédulas que no se procesan ─────────────────────────────────────

    /** "Este proveedor no nos factura": deja de preguntar por sus comprobantes. */
    public function ignorarCedula($cedula, $nombre = '', $motivo = '', $usuarioId = 0)
    {
        $cedula = self::normalizarCedula($cedula);
        if ($cedula === '') {
            return ['ok' => false, 'message' => 'Falta la cédula.'];
        }
        $empresa = $this->empresa();
        if ($empresa <= 0) {
            return self::sinEmpresa();
        }
        try {
            $this->execute(
                'INSERT INTO proveedor_cedula_ignorada (sociedad_id, cedula, nombre, motivo, usuario_id)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), motivo = VALUES(motivo)',
                [
                    $empresa,
                    $cedula,
                    mb_substr(trim((string) $nombre), 0, 255),
                    mb_substr(trim((string) $motivo), 0, 255) ?: null,
                    (int) $usuarioId > 0 ? (int) $usuarioId : null,
                ]
            );
            self::olvidarMapa();
            return ['ok' => true, 'message' => 'Los comprobantes de esa cédula no se van a volver a preguntar.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo guardar: ' . $e->getMessage()];
        }
    }

    /** Vuelve a preguntar por una cédula que se había mandado a callar. */
    public function dejarDeIgnorar($cedula)
    {
        $cedula = self::normalizarCedula($cedula);
        $empresa = $this->empresa();
        if ($empresa <= 0) {
            return self::sinEmpresa();
        }
        try {
            $this->execute(
                'DELETE FROM proveedor_cedula_ignorada WHERE sociedad_id = ? AND cedula = ?',
                [$empresa, $cedula]
            );
            self::olvidarMapa();
            return ['ok' => true, 'message' => 'Esa cédula vuelve a preguntarse.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo quitar: ' . $e->getMessage()];
        }
    }

    public function ignoradas()
    {
        if ($this->empresa() <= 0) {
            return [];
        }
        try {
            return $this->fetchAll(
                'SELECT i.*, u.nombre AS usuario
                   FROM proveedor_cedula_ignorada i
                   LEFT JOIN usuarios u ON u.id = i.usuario_id
                  WHERE i.sociedad_id = ?
                  ORDER BY i.creado_en DESC',
                [$this->empresa()]
            ) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    // ── Comprobantes que no pertenecen a ningún código ─────────────────

    /**
     * Los XML cuyo emisor no está en el catálogo.
     *
     * Se calcula, no se guarda. Es la diferencia entre una lista que se cura
     * sola y una que hay que acordarse de limpiar: en cuanto alguien le pone
     * su código a una cédula, sus comprobantes desaparecen de acá sin que
     * nadie tenga que volver a tocarlos.
     *
     * Agrupado por cédula porque la decisión es por proveedor, no por
     * comprobante: darle un código a una cédula resuelve de una vez los
     * treinta XML que llegaron de ella.
     */
    public function comprobantesSinCodigo($limite = 200)
    {
        $empresa = $this->empresa();
        if ($empresa <= 0) {
            return [];
        }
        try {
            return $this->fetchAll(
                "SELECT p.id AS proveedor_id, p.rfc AS cedula, p.razon_social,
                        COUNT(*) AS comprobantes,
                        MIN(f.fecha_emision) AS desde,
                        MAX(f.fecha_emision) AS hasta,
                        SUM(f.total) AS total
                   FROM facturas_xml f
                   JOIN proveedores p ON p.id = f.proveedor_id
                  WHERE " . self::SIN_CODIGO_EN_LA_EMPRESA . "
                  GROUP BY p.id, p.rfc, p.razon_social
                  ORDER BY comprobantes DESC
                  LIMIT " . max(1, (int) $limite),
                array_fill(0, 4, $empresa)
            ) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Cuántos proveedores distintos están esperando que se decida por ellos. */
    public function cuantosSinCodigo()
    {
        $empresa = $this->empresa();
        if ($empresa <= 0) {
            return 0;
        }
        try {
            return (int) $this->fetchColumn(
                "SELECT COUNT(DISTINCT p.id)
                   FROM facturas_xml f
                   JOIN proveedores p ON p.id = f.proveedor_id
                  WHERE " . self::SIN_CODIGO_EN_LA_EMPRESA,
                array_fill(0, 4, $empresa)
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Un comprobante de la empresa cuyo emisor no está en el catálogo de esa
     * misma empresa. Que esté en el de otra no cuenta: a esta no le dice qué
     * código tiene. Lleva cuatro veces la empresa, en este orden.
     */
    private const SIN_CODIGO_EN_LA_EMPRESA = "f.sociedad_id = ?
                    AND p.rfc IS NOT NULL AND p.rfc <> ''
                    AND NOT EXISTS (
                        SELECT 1 FROM proveedor_codigo_erp m
                         WHERE m.sociedad_id = ?
                           AND REPLACE(REPLACE(m.cedula, '-', ''), ' ', '') = p.rfc
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM proveedor_cedula_ignorada i
                         WHERE i.sociedad_id = ? AND i.cedula = p.rfc
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM proveedor_codigo_tambien t
                         WHERE t.sociedad_id = ? AND t.cedula = p.rfc
                    )";
}
