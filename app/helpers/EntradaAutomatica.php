<?php
/**
 * La entrada automática: lo que llega por correo se guarda solo.
 *
 * Hasta ahora todo comprobante nuevo caía en la Bandeja y se quedaba ahí
 * hasta que una persona lo marcaba y confirmaba la importación. Esa
 * autorización no decidía nada: quien la daba miraba las mismas tres cosas
 * que el sistema ya había comprobado al capturarlo —que el XML se lee, que
 * Hacienda no lo rechazó y que el receptor es la empresa— y le daba a
 * importar. Lo que sí producía era espera: un comprobante que entró el
 * viernes a las seis se registraba el lunes, y hasta entonces su factura del
 * ERP figuraba «sin respaldo» aunque el XML estuviera bajado desde el viernes.
 *
 * Ahora el que pasa esas tres comprobaciones entra solo: se archiva en la
 * carpeta de su mes, queda registrado y se engancha con su factura del ERP.
 *
 * La Bandeja no desaparece ni se vuelve un trámite: se queda exactamente con
 * lo que una persona sí tiene que resolver —rechazados por Hacienda, de otra
 * cédula, y lo que falló al importar—. Sin nada de eso está vacía, que es la
 * señal de que no hay nada que hacer.
 *
 * ── Esto no es una regla nueva ──
 *
 * Es lo que el modo Descargas ya hacía con un buzón entero
 * (CorreoController::procesarTandaLote): capturar, importar lo limpio, apartar
 * lo que no. Se extrajo acá para que la tarea programada haga lo mismo con el
 * correo que va llegando, en vez de escribir la misma regla dos veces.
 *
 * ── Qué se compara contra qué ──
 *
 * Cada documento busca su listado al entrar, y cada uno el suyo:
 *
 *   Factura (FE)      XmlDocumentImporter la engancha con su fila del reporte
 *                     del ERP por consecutivo y monto, y hereda de ella la
 *                     pago de pago. Eso ocurre dentro de importar().
 *
 *   Nota de crédito   Se le extrae el detalle y, al terminar la corrida, se
 *   (NC)              revisan los listados de notas de esa empresa.
 *
 * Y al final de la corrida, una sola vez, se cruzan los pagos que
 * tenían facturas sin respaldo. Ese cruce cuesta millones de comparaciones de
 * texto y no se puede pagar por documento: por eso verificarListados() es
 * aparte y se llama cuando ya entró todo lo de la corrida.
 */

require_once __DIR__ . '/XmlDocumentImporter.php';
require_once __DIR__ . '/DocumentoArchivo.php';
require_once __DIR__ . '/RutaDocumento.php';
require_once __DIR__ . '/AlmacenDocumentos.php';

class EntradaAutomatica
{
    /**
     * Los tipos que entran solos. La nota de débito no está —no se guarda
     * nunca, y eso lo decide XmlDocumentImporter— y el mensaje de Hacienda
     * tampoco: no es un comprobante, sirve para validar y se descarta.
     */
    public const TIPOS = ['FE', 'NC'];

    private $bandeja;
    private $sociedadesPorId;
    private $importer = null;

    /** Empresas que recibieron algo en esta corrida, para el cruce final. */
    private $sociedadesConFacturas = [];
    private $sociedadesConNotas = [];

    /**
     * @param CorreoBandeja $bandeja
     * @param array $sociedadesPorId  id => fila de sociedades (para la cédula)
     */
    public function __construct($bandeja, array $sociedadesPorId = [])
    {
        $this->bandeja = $bandeja;
        $this->sociedadesPorId = $sociedadesPorId;
    }

    /**
     * ¿La configuración pide que los documentos entren solos?
     *
     * Por omisión sí. Quien no la quiera la apaga desde Configuración →
     * Automatización, y entonces todo vuelve a esperar en la Bandeja.
     */
    public static function activa(array $autoSync)
    {
        return !array_key_exists('entrada_automatica', $autoSync)
            || !empty($autoSync['entrada_automatica']);
    }

    /**
     * Mete al sistema lo que la captura acaba de dejar en la Bandeja.
     *
     * @param array $captura  lo que devolvió CorreoController::procesarMensaje
     * @param array $correo   de qué mensaje salió: cuenta_id, carpeta,
     *                        uidvalidity, uid, fecha_correo
     */
    public function ingresar(array $captura, array $correo = [])
    {
        $res = [
            'ingresados' => 0, 'duplicados' => 0, 'pdf_pendientes' => 0,
            'retenidos' => 0, 'fallidos' => 0, 'errores' => [],
        ];

        foreach (($captura['filas'] ?? []) as $ref) {
            $filaId = (int) ($ref['id'] ?? 0);
            if ($filaId <= 0) {
                continue;
            }

            // Rechazada por Hacienda o a nombre de otra cédula: se queda en la
            // Bandeja. No es un fallo del sistema y no lo arregla reintentar;
            // hay que mirarlo y decidir, que es para lo que la Bandeja sigue
            // existiendo.
            if (($ref['estado'] ?? '') !== 'pendiente') {
                $res['retenidos']++;
                $this->compartir($filaId);
                continue;
            }

            $filas = $this->bandeja->getByIds([$filaId], 'pendiente');
            if (empty($filas)) {
                continue;
            }
            $fila = $filas[0];

            $sociedadId = (int) ($fila['sociedad_id'] ?? 0);
            if ($sociedadId <= 0) {
                // Sin empresa resuelta no se registra: en la tarea programada
                // no hay sesión de la que deducirla, y adivinarla sería meter
                // el documento en la contabilidad de otra.
                $res['retenidos']++;
                $res['errores'][] = 'Sin empresa resuelta, queda en la Bandeja: '
                    . $this->etiqueta($fila);
                $this->compartir($filaId, $fila);
                continue;
            }

            $rutaXml = (string) ($fila['archivo_xml'] ?? '');
            if ($rutaXml === '' || !RutaDocumento::existe($rutaXml)) {
                $res['fallidos']++;
                $res['errores'][] = 'No se encontró el XML capturado de ' . $this->etiqueta($fila);
                continue;
            }

            try {
                $r = $this->importer()->importar(
                    $rutaXml,
                    (string) ($fila['archivo_pdf'] ?? ''),
                    [
                        'origen' => 'correo_automatico',
                        'tipos_permitidos' => self::TIPOS,
                        'validar_receptor' => true,
                        'cedula_receptor' => $this->cedulaDe($sociedadId),
                        'sociedad_id' => $sociedadId,
                        'correo_cuenta_id' => (int) ($correo['cuenta_id'] ?? ($fila['cuenta_id'] ?? 0)),
                        'correo_carpeta' => $correo['carpeta'] ?? null,
                        'correo_uidvalidity' => $correo['uidvalidity'] ?? null,
                        'correo_uid' => $correo['uid'] ?? null,
                        'fecha_correo' => $correo['fecha_correo'] ?? ($fila['fecha_correo'] ?? null),
                    ]
                );
            } catch (Throwable $e) {
                // La fila se queda pendiente en la Bandeja, con sus archivos:
                // son la única copia que hay del comprobante, y lo que falló
                // puede ser cosa de esta corrida —la carpeta compartida sin
                // responder, por ejemplo—. Reintentarlo es un clic.
                $res['fallidos']++;
                $res['errores'][] = 'No entró ' . $this->etiqueta($fila) . ': ' . $e->getMessage();
                $this->compartir($filaId, $fila);
                continue;
            }

            if (($r['estado'] ?? '') === 'importado') {
                $res['ingresados']++;
            } else {
                // Ya estaba en el sistema. La llamada no fue en vano: completa
                // el PDF que faltara y reintenta el enganche con el ERP.
                $res['duplicados']++;
            }
            if (!empty($r['pdf_pendiente'])) {
                $res['pdf_pendientes']++;
            }

            if (strtoupper((string) ($r['tipo_documento'] ?? 'FE')) === 'NC') {
                $this->sociedadesConNotas[$sociedadId] = true;
            } else {
                $this->sociedadesConFacturas[$sociedadId] = true;
            }

            // El comprobante ya está archivado en la carpeta de su mes y
            // registrado: la copia de la Bandeja sobra. La fila se conserva
            // como historial de por dónde entró.
            $this->bandeja->marcarImportadasGeneral([$filaId]);
            foreach (['archivo_xml', 'archivo_pdf'] as $campo) {
                $ruta = (string) ($fila[$campo] ?? '');
                if ($ruta !== '') {
                    AlmacenDocumentos::de($ruta)->borrar($ruta);
                }
            }
        }

        return $res;
    }

    /**
     * Sube a la zona de paso del grupo los archivos de una fila que se QUEDA
     * en la Bandeja.
     *
     * Mientras el comprobante iba a archivarse en esta misma corrida, su
     * adjunto se quedó en esta computadora (RutaDocumento::carpetaTrabajoLocal
     * dice por qué). En cuanto pasa a esperar a que alguien lo mire, deja de
     * ser cosa de esta máquina: quien lo mire puede estar en otra, y desde
     * otra no hay forma de abrir un archivo de este disco.
     *
     * Si no se puede subir —sin carpeta compartida, sin red— la fila se queda
     * apuntando a lo de aquí: es la única copia del comprobante y perderla
     * sería peor. La próxima corrida en esta computadora lo reintenta, desde
     * rescatarLoQueQuedoAqui().
     */
    private function compartir($filaId, ?array $fila = null)
    {
        $filaId = (int) $filaId;
        if ($filaId <= 0) {
            return;
        }
        if ($fila === null) {
            $filas = $this->bandeja->getByIds([$filaId]);
            if (empty($filas)) {
                return;
            }
            $fila = $filas[0];
        }

        $nuevas = [];
        foreach (['archivo_xml', 'archivo_pdf'] as $campo) {
            $ruta = (string) ($fila[$campo] ?? '');
            if ($ruta === '' || !RutaDocumento::esDeTrabajoLocal($ruta) || !RutaDocumento::existe($ruta)) {
                continue;
            }
            try {
                // El mes es el de la carpeta donde ya está, no el de hoy: una
                // corrida que arranca el 31 a las 23:59 no parte el par.
                $destino = RutaDocumento::carpetaTrabajo(
                        'BANDEJA/' . ($campo === 'archivo_pdf' ? 'pdf' : 'xml')
                        . '/' . basename(dirname($ruta)),
                        (int) ($fila['sociedad_id'] ?? 0)
                    ) . DIRECTORY_SEPARATOR . basename($ruta);
                AlmacenDocumentos::de($destino)->adoptar($destino, $ruta);
                $nuevas[$campo] = $destino;
            } catch (Throwable $e) {
                // Se queda donde está; la fila lo sigue apuntando.
            }
        }

        if ($nuevas) {
            $this->bandeja->reapuntarArchivos($filaId, $nuevas);
        }
    }

    /**
     * Sube lo que una corrida anterior dejó a medias en esta computadora.
     *
     * Solo puede pasar si la corrida se cortó entre capturar el adjunto y
     * archivarlo —se apagó la máquina, se mató el proceso—: la fila quedó
     * pendiente apuntando a un archivo que únicamente esta PC ve. Como la
     * captura siempre la hace la computadora de la tarea programada, la
     * siguiente corrida de esa misma tarea lo encuentra y lo sube.
     *
     * @return int Cuántas filas se subieron.
     */
    public function rescatarLoQueQuedoAqui()
    {
        $subidas = 0;
        foreach ($this->bandeja->pendientesEnEstaPC() as $fila) {
            $antes = [(string) ($fila['archivo_xml'] ?? ''), (string) ($fila['archivo_pdf'] ?? '')];
            $this->compartir((int) $fila['id'], $fila);
            $filas = $this->bandeja->getByIds([(int) $fila['id']]);
            $ahora = empty($filas) ? $antes
                : [(string) ($filas[0]['archivo_xml'] ?? ''), (string) ($filas[0]['archivo_pdf'] ?? '')];
            if ($ahora !== $antes) {
                $subidas++;
            }
        }

        return $subidas;
    }

    /** ¿Entró algo que valga la pena cruzar contra los listados? */
    public function hayQueVerificar()
    {
        return !empty($this->sociedadesConFacturas) || !empty($this->sociedadesConNotas);
    }

    /**
     * El cruce contra los listados, una vez por corrida y por empresa.
     *
     * Se hace acá y no al entrar cada documento porque recorrer los pagos
     * abiertos buscando qué quedó sin respaldo cuesta millones de
     * comparaciones de texto: pagarlo por cada correo que llega sería tener la
     * base en danza todo el día para el mismo resultado.
     *
     * Nunca lanza. Los documentos ya entraron y están archivados; que un
     * listado no se pueda revisar ahora no deshace eso, y la próxima corrida
     * —o el botón «Verificar de nuevo»— lo repite.
     */
    public function verificarListados()
    {
        $resumen = ['pagos_resueltos' => 0, 'sociedades_fe' => 0, 'sociedades_nc' => 0, 'errores' => []];

        foreach (array_keys($this->sociedadesConFacturas) as $sociedadId) {
            try {
                require_once __DIR__ . '/PorPagarVerificador.php';
                require_once __DIR__ . '/../models/FacturaErp.php';
                require_once __DIR__ . '/../models/Factura.php';

                $resumen['pagos_resueltos'] += (int) PorPagarVerificador::verificarPendientes(
                    (new FacturaErp())->setSociedad($sociedadId),
                    (new Factura())->setSociedad($sociedadId)
                );
                $resumen['sociedades_fe']++;
            } catch (Throwable $e) {
                $resumen['errores'][] = 'Pagos de la empresa ' . $sociedadId . ': ' . $e->getMessage();
            }
        }

        foreach (array_keys($this->sociedadesConNotas) as $sociedadId) {
            try {
                require_once __DIR__ . '/NotasCreditoVerificador.php';
                require_once __DIR__ . '/../models/NotaCredito.php';

                // Sin sesión, el modelo se acotaría a la empresa por omisión:
                // hay que decirle en cuál está trabajando esta vuelta.
                NotasCreditoVerificador::verificarTodosSociedad(
                    $sociedadId,
                    (new NotaCredito())->setSociedad($sociedadId)
                );
                $resumen['sociedades_nc']++;
            } catch (Throwable $e) {
                $resumen['errores'][] = 'Notas de crédito de la empresa ' . $sociedadId . ': ' . $e->getMessage();
            }
        }

        $this->sociedadesConFacturas = [];
        $this->sociedadesConNotas = [];

        return $resumen;
    }

    /** El importador se crea una sola vez: valida la carpeta raíz al nacer. */
    private function importer()
    {
        if ($this->importer === null) {
            $this->importer = new XmlDocumentImporter();
        }
        return $this->importer;
    }

    private function cedulaDe($sociedadId)
    {
        $sociedad = $this->sociedadesPorId[(int) $sociedadId] ?? null;
        return is_array($sociedad) ? (string) ($sociedad['cedula'] ?? '') : '';
    }

    /** Cómo nombrar un documento en un aviso: su número, o su archivo. */
    private function etiqueta(array $fila)
    {
        $numero = trim((string) ($fila['numero_corto'] ?? ''));
        if ($numero !== '') {
            return (string) ($fila['tipo_doc'] ?? 'FE') . ' ' . $numero;
        }
        $archivo = (string) ($fila['archivo_xml'] ?? '');
        return $archivo !== '' ? basename($archivo) : ('la fila ' . (int) ($fila['id'] ?? 0));
    }
}
