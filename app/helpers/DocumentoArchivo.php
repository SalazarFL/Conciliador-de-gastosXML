<?php
/**
 * Archivo durable de comprobantes fuera de la base de datos.
 *
 * Estructura: RAIZ/SISTEMA/EMPRESA/AAAA/MM MES/Facturas|Notas de crédito/ARCHIVO.ext
 * Los archivos se copian primero como .partial, se valida SHA-256 y solo
 * entonces se renombran. Nunca sobrescribe un contenido diferente.
 *
 * Por qué la empresa antes del año: los documentos de dos empresas del grupo
 * no se mezclan ni en la base ni en el disco. Quien abre la carpeta compartida
 * busca "las facturas de tal empresa", no "las facturas de julio de alguna de
 * las dos"; y el árbol es lo que se respalda, se audita y se entrega. Va justo
 * bajo SISTEMA para que la advertencia de no-tocar siga estando una sola vez,
 * arriba de todo.
 *
 * Las instalaciones anteriores tienen el árbol sin ese nivel. Se pasa con
 * `php cli/separar_documentos_por_empresa.php --aplicar`, que mueve los
 * archivos y corrige la ruta guardada de cada documento.
 *
 * Por qué SISTEMA: la raíz es una carpeta compartida donde también se trabaja
 * —se arman los pagos, se dejan archivos a mano— y ahí dentro el
 * árbol de años no se distinguía de una carpeta cualquiera. Todo lo que la
 * aplicación bajó del correo cuelga de SISTEMA, con su nota de advertencia,
 * para que se vea de un vistazo qué no hay que mover: la base guarda la ruta
 * de cada documento, y un archivo cambiado de lugar deja de abrirse y aparece
 * marcado como perdido. Lo que sí es para trabajar —PAGOS, con
 * copias— queda al lado, fuera de SISTEMA.
 *
 * La carpeta depende solo de la fecha de emisión y del tipo de documento:
 * dos datos que no cambian nunca. Antes había además una subcarpeta por
 * estado (EN SISTEMA, REVISAR, …) y era la causa de que los archivos tuvieran
 * que reacomodarse cada vez que una factura avanzaba. El estado se consulta en
 * la aplicación, que es donde está al día.
 */
require_once __DIR__ . '/NumeroFactura.php';
require_once __DIR__ . '/RutaDocumento.php';
require_once __DIR__ . '/AlmacenDocumentos.php';
require_once __DIR__ . '/AlmacenEnDisco.php';

class DocumentoArchivo
{
    /**
     * Donde se reúnen los pares XML/PDF de cada pago, al lado de SISTEMA.
     *
     * Se llamó 'PAGOS SEMANALES' mientras se pagó una vez por semana. Las
     * instalaciones que vienen de entonces tienen la carpeta con ese nombre y
     * documentos dentro, así que no se la deja huérfana: la primera vez que
     * alguien la necesita se renombra sola. Ver migrarCarpetaVieja().
     */
    public const CARPETA_PAGOS = 'PAGOS';

    /** Como se llamaba antes. Solo se usa para renombrarla una vez. */
    public const CARPETA_PAGOS_VIEJA = 'PAGOS SEMANALES';

    /** Todo lo que la aplicación archiva cuelga de aquí. Ver el docblock. */
    public const CARPETA_SISTEMA = 'SISTEMA';

    /** La advertencia que queda dentro de SISTEMA, para quien abra la carpeta. */
    public const NOTA_SISTEMA = 'LEEME - NO MODIFICAR.txt';

    /** Tope del nombre del proveedor dentro del archivo: FE_<proveedor>_ddmmyy_00000000. */
    public const MAX_TOKEN_PROVEEDOR = 45;

    private const SUFIJOS_SOCIETARIOS = [
        'SA', 'SAS', 'SRL', 'SL', 'SC', 'SCA', 'SOCIEDAD', 'ANONIMA',
        'SIMPLIFICADA', 'RESPONSABILIDAD', 'LTDA', 'LIMITADA', 'LTD',
        'LIMITED', 'CIA', 'COMPANIA', 'COMPANY', 'CO', 'INC',
        'INCORPORATED', 'CORP', 'CORPORATION', 'CV', 'LLC', 'GMBH', 'AG',
    ];

    private const MESES = [
        1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL',
        5 => 'MAYO', 6 => 'JUNIO', 7 => 'JULIO', 8 => 'AGOSTO',
        9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE',
    ];

    /** Nombre de carpeta para lo que no se sabe de qué empresa es. */
    public const EMPRESA_SIN_DEFINIR = 'SIN EMPRESA';

    /** Tope del nombre de la empresa como carpeta. */
    public const MAX_CARPETA_EMPRESA = 60;

    /** Lo que se le dice a quien archiva en disco sin carpeta compartida. */
    public const SIN_CARPETA = 'Esta empresa archiva sus XML y PDF en la carpeta compartida, y esta '
        . 'computadora no la tiene configurada. Configúrala en Correo, o pasa la empresa a SharePoint '
        . 'en Configuración → Empresas del grupo.';

    private $raiz;
    private $empresa = '';
    private $notaSistemaLista = false;

    /** @var AlmacenDocumentos Dónde se archiva: el de la sociedad, ver paraSociedad(). */
    private $almacen;

    public function __construct($raiz = '', $empresa = '')
    {
        $this->empresa = self::carpetaEmpresa($empresa);
        $raiz = trim((string) $raiz);
        if ($raiz === '') {
            // La de RutaDocumento, que es la configurada salvo que alguien la
            // haya fijado a propósito —una prueba, una migración—: así nada
            // que archive sin raíz explícita puede escribir en la carpeta
            // real mientras se prueba otra cosa.
            $raiz = RutaDocumento::raiz();
        }

        $this->raiz = rtrim($raiz, '/\\');
        $this->almacen = AlmacenDocumentos::actual();

        // Sin carpeta compartida se puede seguir: una PC que solo trabaja
        // empresas en SharePoint no la necesita —los XML y PDF van a la
        // biblioteca y lo demás se queda en esta máquina—. Lo que no se puede
        // es archivar en disco sin ella, y eso lo frena exigirCarpeta() en el
        // momento de escribir, no acá: el archivo que se construye todavía no
        // sabe de qué empresa va a ser el documento.
        if ($this->raiz === '') {
            return;
        }
        $this->asegurarDirectorio($this->raiz);
        if (!$this->almacen->permiteEscritura($this->raiz)) {
            throw new RuntimeException('La carpeta raíz no permite escritura: ' . $this->raiz);
        }
    }

    public static function raizConfigurada()
    {
        $archivo = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'correo' . DIRECTORY_SEPARATOR . 'config.json';
        if (!is_file($archivo)) {
            return '';
        }
        $config = json_decode((string) file_get_contents($archivo), true);
        return is_array($config) ? trim((string) ($config['carpeta_destino'] ?? '')) : '';
    }

    public function raiz()
    {
        return $this->raiz;
    }

    /**
     * El mismo archivo, apuntando a la carpeta de otra empresa.
     *
     * Devuelve una copia en vez de cambiarse a sí mismo: en una carga de
     * correo se archivan documentos de varias empresas seguidos, y un objeto
     * que recuerda "la última empresa" es exactamente la clase de estado que
     * termina guardando un documento en la carpeta equivocada.
     */
    public function paraEmpresa($empresa)
    {
        $otro = clone $this;
        $otro->empresa = self::carpetaEmpresa($empresa);
        $otro->notaSistemaLista = false;
        return $otro;
    }

    /**
     * El mismo archivo, para una sociedad: su carpeta Y su almacén.
     *
     * Es por donde pasan todas las escrituras de documentos —la importación,
     * la reposición—, así que es el único lugar donde hace falta decidir
     * dónde se archiva. Si la sociedad está en otro almacén, la raíz pasa a
     * ser la de ese almacén: en SharePoint no hay raíz de esta máquina, las
     * rutas son relativas a la biblioteca.
     *
     * Si esta PC no puede usar el almacén de la sociedad, lanza. Archivar en
     * disco lo que el resto de las PC busca en SharePoint es perder el
     * documento sin que nadie se entere.
     */
    public function paraSociedad($sociedadId, $empresa)
    {
        $otro = $this->paraEmpresa($empresa);
        $almacen = AlmacenDocumentos::para($sociedadId);
        if ($almacen !== $this->almacen) {
            $otro->almacen = $almacen;
            $otro->raiz = rtrim((string) $almacen->direccion(''), '/\\');
        }
        return $otro;
    }

    /**
     * Un archivo que trabaja directamente sobre un almacén, sin pasar por la
     * carpeta de esta máquina.
     *
     * Lo usa el reconciliador de SharePoint para armar la carpeta del pago:
     * los documentos están en la biblioteca y las copias van a la biblioteca.
     * Exigirle una carpeta compartida configurada —que es lo que hace el
     * constructor— sería atar a SharePoint a lo que vino a reemplazar.
     */
    public static function sobreAlmacen(AlmacenDocumentos $almacen, $empresa = '')
    {
        $otro = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $otro->empresa = self::carpetaEmpresa($empresa);
        $otro->almacen = $almacen;
        $otro->raiz = rtrim((string) $almacen->direccion(''), '/\\');
        return $otro;
    }

    /** Dónde archiva este objeto. */
    public function almacen()
    {
        return $this->almacen;
    }

    /** Con qué empresa está trabajando ('' = la raíz de SISTEMA, sin separar). */
    public function empresa()
    {
        return $this->empresa;
    }

    /**
     * El nombre de la empresa como nombre de carpeta.
     *
     * Se queda con letras, dígitos y espacios —nada que Windows rechace ni que
     * confunda a un script— y respeta el nombre tal como lo escribió quien
     * registró la empresa, en mayúsculas para que combine con el resto del
     * árbol. Cadena vacía significa "sin separar por empresa", que es cómo se
     * comportaba antes y cómo siguen funcionando las pruebas del archivo.
     */
    public static function carpetaEmpresa($nombre)
    {
        $valor = mb_strtoupper(trim((string) $nombre), 'UTF-8');
        if ($valor === '') {
            return '';
        }
        $valor = strtr($valor, self::ACENTOS);
        // El punto se borra en vez de convertirse en separador: "S.A" es "SA",
        // no "S A". Es la misma regla que el nombre del proveedor.
        $valor = str_replace('.', '', $valor);
        $valor = preg_replace('~[\\\\/\x00-\x1F<>:"|?*]+~u', ' ', $valor);
        $valor = self::sinReservadosDeSharePoint($valor);
        $valor = preg_replace('/\s+/u', ' ', trim((string) $valor));
        $valor = trim(mb_substr((string) $valor, 0, self::MAX_CARPETA_EMPRESA, 'UTF-8'));
        return $valor !== '' ? $valor : self::EMPRESA_SIN_DEFINIR;
    }

    /**
     * Lo que SharePoint rechaza además de lo que ya rechaza Windows.
     *
     * Los caracteres prohibidos son los mismos en los dos y ya se filtran
     * arriba. SharePoint suma tres reglas propias: un nombre no puede empezar
     * por `~` —lo reserva para los archivos de bloqueo de Office—, no puede
     * contener `_vti_` —lo usa internamente— ni terminar en punto. Con el
     * nombrado de los documentos nada de esto aparece nunca; con el nombre de
     * una empresa o de un pago, que escribe una persona, sí puede, y el fallo
     * saldría recién en producción, al subir.
     */
    private static function sinReservadosDeSharePoint($valor)
    {
        $valor = str_ireplace('_vti_', ' ', (string) $valor);
        $valor = ltrim($valor, "~ \t");

        return rtrim($valor, ". \t");
    }

    public function archivar($xmlOrigen, $pdfOrigen, array $documento, $proveedor)
    {
        if (!is_file($xmlOrigen)) {
            throw new RuntimeException('El XML temporal ya no existe.');
        }

        $pdfDisponible = is_string($pdfOrigen) && $pdfOrigen !== '' && is_file($pdfOrigen);
        $carpeta = $this->carpetaDocumento(
            (string) ($documento['fecha_emision'] ?? ''),
            (string) ($documento['tipo_documento'] ?? 'FE')
        );
        $this->asegurarDirectorio($carpeta);

        $base = self::nombreBase($documento, $proveedor);
        $xml = $this->copiarValidado($xmlOrigen, $carpeta, $base, 'xml');

        // Si el XML necesitó sufijo por colisión, el PDF conserva ese mismo
        // nombre base para que el par siga siendo evidente.
        $baseFinal = pathinfo($xml['ruta'], PATHINFO_FILENAME);
        $pdf = null;
        if ($pdfDisponible) {
            $pdf = $this->copiarValidado($pdfOrigen, $carpeta, $baseFinal, 'pdf');
        }

        return [
            'ruta_xml' => $xml['ruta'],
            'archivo_xml' => basename($xml['ruta']),
            'hash_xml' => $xml['hash'],
            'xml_creado' => $xml['creado'],
            'ruta_pdf' => $pdf ? $pdf['ruta'] : null,
            'archivo_pdf' => $pdf ? basename($pdf['ruta']) : null,
            'hash_pdf' => $pdf ? $pdf['hash'] : null,
            'pdf_creado' => $pdf ? $pdf['creado'] : false,
            'estado_pdf' => $pdf ? 'disponible' : 'pendiente',
            'archivado_en' => date('Y-m-d H:i:s'),
            'item_xml_id' => $xml['item_id'],
            'etag_xml' => $xml['etag'],
            'item_pdf_id' => $pdf ? $pdf['item_id'] : '',
            'etag_pdf' => $pdf ? $pdf['etag'] : '',
        ];
    }

    /** Agrega un PDF a un registro ya archivado sin volver a copiar su XML. */
    public function archivarPdfPara(array $registro, $pdfOrigen)
    {
        if (!is_file($pdfOrigen)) {
            throw new RuntimeException('El PDF temporal ya no existe.');
        }

        // El XML ya archivado vive en el almacén: se le pregunta a él.
        $rutaXml = trim((string) ($registro['ruta_xml'] ?? ''));
        $archivo = $this;
        if ($rutaXml !== '' && RutaDocumento::existe($rutaXml)) {
            $carpeta = dirname($rutaXml);
            $base = pathinfo($rutaXml, PATHINFO_FILENAME);
            // El PDF va al lado de su XML y en su MISMO almacén, aunque la
            // empresa se haya pasado a otro desde entonces: la fila dice en
            // un solo lugar dónde vive el par, y un par partido en dos
            // almacenes tendría la mitad perdida.
            $archivo = clone $this;
            $archivo->almacen = AlmacenDocumentos::de($rutaXml);
        } else {
            $carpeta = $this->carpetaDocumento(
                (string) ($registro['fecha_emision'] ?? ''),
                (string) ($registro['tipo_documento'] ?? 'FE')
            );
            $base = self::nombreBase($registro, (string) ($registro['proveedor_nombre'] ?? 'PROVEEDOR'));
        }
        $archivo->asegurarDirectorio($carpeta);
        $pdf = $archivo->copiarValidado($pdfOrigen, $carpeta, $base, 'pdf');

        return [
            'item_pdf_id' => $pdf['item_id'],
            'etag_pdf' => $pdf['etag'],
            'ruta_pdf' => $pdf['ruta'],
            'archivo_pdf' => basename($pdf['ruta']),
            'hash_pdf' => $pdf['hash'],
            'pdf_creado' => $pdf['creado'],
            'estado_pdf' => 'disponible',
            'archivado_en' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Deshace un archivado cuando la base no pudo guardar la fila.
     *
     * Solo borra lo que ESTA llamada creó —de ahí las banderas `xml_creado` y
     * `pdf_creado`—: si el documento ya estaba archivado de antes, un rollback
     * no puede llevárselo. Esa distinción es la que hace que reintentar una
     * importación sea seguro.
     *
     * En SharePoint se borra por id —la ruta pudo haber cambiado entre medio;
     * el id no— y el borrado se anota ANTES de intentarlo en
     * almacen_pendientes: si falla —y falla justo cuando la red es la que
     * tumbó la base—, la tarea programada lo reintenta. Ver AlmacenPendiente.
     * Nunca lanza: quien llama ya está manejando un error, el de la base.
     */
    public static function eliminarCreados(array $resultado)
    {
        foreach ([['xml_creado', 'ruta_xml', 'item_xml_id'], ['pdf_creado', 'ruta_pdf', 'item_pdf_id']] as $par) {
            if (empty($resultado[$par[0]]) || empty($resultado[$par[1]])) {
                continue;
            }
            $ruta = (string) $resultado[$par[1]];
            $itemId = (string) ($resultado[$par[2]] ?? '');
            $almacen = AlmacenDocumentos::de($ruta);

            if ($almacen instanceof AlmacenEnDisco) {
                $almacen->borrar($ruta);
                continue;
            }

            $pendiente = self::anotarPendiente($ruta, $itemId);
            try {
                $almacen->borrar($ruta, $itemId);
                if ($pendiente !== null) {
                    $pendiente[0]->tachar($pendiente[1]);
                }
            } catch (Throwable $e) {
                // Queda anotado —si se pudo anotar— y la tarea lo reintenta.
                if ($pendiente !== null) {
                    try {
                        $pendiente[0]->fallo($pendiente[1], $e->getMessage());
                    } catch (Throwable $e2) {
                    }
                }
            }
        }
    }

    /** @return array|null [modelo, id] o null si la base tampoco contesta. */
    private static function anotarPendiente($ruta, $itemId)
    {
        try {
            require_once dirname(__DIR__) . '/core/Model.php';
            require_once dirname(__DIR__) . '/models/AlmacenPendiente.php';
            $modelo = new AlmacenPendiente();
            return [$modelo, $modelo->anotarBorrado($ruta, $itemId, 'Deshacer un archivado que la base no guardó')];
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function nombreBase(array $documento, $proveedor)
    {
        $tipo = strtoupper(trim((string) ($documento['tipo_documento'] ?? ($documento['tipo_doc'] ?? 'FE'))));
        if (!in_array($tipo, ['FE', 'NC', 'ND'], true)) {
            $tipo = 'FE';
        }

        $ts = strtotime((string) ($documento['fecha_emision'] ?? ''));
        $fecha = $ts !== false ? date('dmy', $ts) : '000000';
        $numero = trim((string) ($documento['numero_factura_asistente'] ?? ($documento['numero_corto'] ?? '0')));
        $numero = preg_replace('/[^A-Za-z0-9-]/', '', $numero);
        if ($numero === '') {
            $numero = '0';
        }
        $numero = NumeroFactura::xmlOchoDigitos($numero);

        return $tipo . '_' . self::tokenProveedor($proveedor) . '_' . $fecha . '_' . $numero;
    }

    /**
     * La carpeta que la aplicación administra: dentro no se toca nada.
     *
     * Es la raíz del árbol, sin empresa: es el nivel donde vive la nota de
     * advertencia y el que revisa el diagnóstico.
     */
    public function carpetaSistema()
    {
        return $this->enRaiz(self::CARPETA_SISTEMA);
    }

    /** SISTEMA/EMPRESA — de aquí para abajo, todo es de una sola empresa. */
    public function carpetaEmpresaSistema()
    {
        return $this->empresa === ''
            ? $this->carpetaSistema()
            : $this->carpetaSistema() . DIRECTORY_SEPARATOR . $this->empresa;
    }

    /** SISTEMA/EMPRESA/AAAA/MM MES, donde se reparte todo lo archivado. */
    public function carpetaMes($fecha)
    {
        $ts = strtotime($fecha);
        if ($ts === false) {
            throw new RuntimeException('El XML no contiene una fecha de emisión válida.');
        }

        return $this->carpetaEmpresaSistema() . DIRECTORY_SEPARATOR . date('Y', $ts)
            . DIRECTORY_SEPARATOR . date('m', $ts) . ' ' . self::MESES[(int) date('n', $ts)];
    }

    /** SISTEMA/EMPRESA/AAAA/MM MES/Facturas|Notas de crédito. */
    public function carpetaDocumento($fecha, $tipo)
    {
        $tipo = strtoupper(trim($tipo));
        $grupo = $tipo === 'NC' ? 'Notas de crédito' : ($tipo === 'ND' ? 'Notas de débito' : 'Facturas');

        return $this->carpetaMes($fecha) . DIRECTORY_SEPARATOR . $grupo;
    }

    /**
     * Deja creada la carpeta SISTEMA con su nota de advertencia.
     *
     * La nota es el motivo de que la carpeta se llame así: quien abra la
     * carpeta compartida desde el explorador no tiene por qué saber que esos
     * archivos los administra una aplicación. Se escribe una sola vez —si
     * alguien la borra, la siguiente corrida la repone— y nunca se sobrescribe
     * una existente, por si alguien le agregó algo.
     */
    public function asegurarCarpetaSistema()
    {
        $carpeta = $this->carpetaSistema();
        $this->asegurarDirectorio($carpeta);
        return $carpeta;
    }

    /**
     * La carpeta que contiene la de cada pago, ya con el nombre bueno.
     *
     * Renombrar es lo correcto y no crear una nueva al lado: adentro hay
     * carpetas de pagos anteriores, con los respaldos que alguien todavía
     * puede necesitar. Dejar dos carpetas —una con lo viejo y otra con lo
     * nuevo— es justamente lo que hace que la gente no encuentre nada.
     *
     * Solo se renombra si la vieja existe y la nueva no. Si las dos están
     * —alguien creó "PAGOS" a mano— no se toca ninguna: mezclarlas es una
     * decisión de quien administra la carpeta, no de este código. Y si el
     * renombrado falla (OneDrive sincronizando, alguien con la carpeta
     * abierta), se sigue trabajando con la que haya.
     *
     * Va directo al disco a propósito, y es de las pocas cosas que lo hacen:
     * "PAGOS SEMANALES" solo existe en instalaciones viejas sobre la carpeta
     * compartida. Una biblioteca de SharePoint nace con el nombre nuevo, así
     * que ahí no hay nada que migrar y is_dir() contesta que no.
     */
    public function carpetaBasePagos()
    {
        $this->exigirCarpeta();
        $nueva = $this->enRaiz(self::CARPETA_PAGOS);
        $vieja = $this->enRaiz(self::CARPETA_PAGOS_VIEJA);

        if (!is_dir($nueva) && is_dir($vieja)) {
            if (@rename($vieja, $nueva)) {
                return $nueva;
            }
            return $vieja;   // no se pudo: se sigue con la de siempre
        }

        return $nueva;
    }

    /** Carpeta elegida para reunir los pares XML/PDF de un pago. */
    public function carpetaPago($nombre)
    {
        $nombre = self::normalizarCarpetaPago($nombre);
        if ($nombre === '') {
            throw new RuntimeException('Selecciona un nombre válido para la carpeta del pago.');
        }
        return $this->carpetaBasePagos() . DIRECTORY_SEPARATOR . $nombre;
    }

    public function prepararCarpetaPago($nombre)
    {
        $ruta = $this->carpetaPago($nombre);
        $this->asegurarDirectorio($ruta);
        return $ruta;
    }

    /**
     * Deja en la carpeta del pago una COPIA del par ya archivado.
     *
     * Copia y no traslada, a propósito. Esa carpeta existe para entregar los
     * respaldos de un pago: se arma, se manda y quien la recibe la borra
     * cuando termina. Mientras el par se mudaba ahí dentro, esa carpeta era el
     * único ejemplar del documento y borrarla se llevaba el XML y el PDF sin
     * que nada lo avisara —así se perdieron los respaldos de un pago entero—.
     * El original se queda en la carpeta del mes, que nadie reparte.
     *
     * Idempotente y barato: el organizador corre seguido, y si la copia ya
     * está y pesa lo mismo no se vuelve a leer el contenido de nada.
     */
    public function copiarAPago($xmlOrigen, $pdfOrigen, array $documento, $carpetaPago, $proveedor)
    {
        $carpeta = $this->carpetaPago($carpetaPago);
        $base = self::nombreBase($documento, $proveedor);
        $salida = ['ruta_xml' => '', 'ruta_pdf' => '', 'copiados' => 0];

        // Los orígenes son documentos ya archivados: viven en el almacén, no
        // en esta máquina, y todo lo que se sabe de ellos se le pregunta a él.
        $almacen = $this->almacen;
        foreach (['xml' => $xmlOrigen, 'pdf' => $pdfOrigen] as $extension => $origen) {
            $origen = trim((string) $origen);
            $delOrigen = $origen !== '' ? $almacen->identidad($origen) : null;
            if ($delOrigen === null) {
                continue;
            }

            // El atajo barato: si la copia ya está y pesa lo mismo, se da por
            // vigente sin leer el contenido de nada. Es la única comparación
            // por tamaño del sistema y es a propósito —el organizador corre
            // cada pocos minutos y esta carpeta se rehace entera—.
            $destino = $carpeta . DIRECTORY_SEPARATOR . $base . '.' . $extension;
            $yaEsta = $almacen->identidad($destino);
            if ($yaEsta !== null && (int) $yaEsta['tamano'] === (int) $delOrigen['tamano']) {
                $salida['ruta_' . $extension] = $destino;
                continue;
            }

            $this->asegurarDirectorio($carpeta);
            $copia = RutaDocumento::conArchivoLocal($origen, function ($local) use ($carpeta, $base, $extension) {
                return $this->copiarValidado($local, $carpeta, $base, $extension);
            });
            $salida['ruta_' . $extension] = $copia['ruta'];
            if ($copia['creado']) {
                $salida['copiados']++;
            }
        }

        return $salida;
    }

    /** Solo admite un nombre de carpeta, nunca rutas absolutas ni recorridos "..". */
    public static function normalizarCarpetaPago($nombre)
    {
        $nombre = trim((string) $nombre);
        $nombre = preg_replace('~[\\\\/\x00-\x1F<>:"|?*]+~u', ' ', $nombre);
        $nombre = self::sinReservadosDeSharePoint($nombre);
        $nombre = preg_replace('/\s+/u', ' ', trim((string) $nombre, " .\t\n\r\0\x0B"));
        // Recortar puede dejar un punto al final, y eso SharePoint lo rechaza.
        return rtrim(mb_substr((string) $nombre, 0, 100, 'UTF-8'), ". \t");
    }

    /**
     * Carpetas de pago ya creadas que pueden reutilizarse en el selector.
     *
     * Solo donde recorrer es barato —el disco—. En SharePoint serían una o
     * varias llamadas cada vez que alguien abre Pagos, para un selector que
     * igual acepta cualquier nombre: la pantalla usa los nombres que ya
     * tienen los pagos en la base.
     */
    public function carpetasPago()
    {
        if (!$this->almacen->recorreElArbolBarato()
            || ($this->raiz === '' && $this->almacen instanceof AlmacenEnDisco)) {
            return [];
        }
        $resultado = [];
        foreach ($this->almacen->listarCarpeta($this->carpetaBasePagos()) as $item) {
            if ($item['es_carpeta']) {
                $resultado[] = $item['nombre'];
            }
        }
        natcasesort($resultado);
        return array_values($resultado);
    }

    /**
     * Guarda el documento y devuelve dónde quedó.
     *
     * Acá quedó SOLO la regla de nombres, que es lo propio del archivo: cómo
     * se llama un documento y qué hacer cuando ese nombre ya está ocupado. La
     * mecánica de escribir —copiar a un lado, verificar y renombrar al final
     * para que nadie vea un archivo a medias— bajó al almacén, porque cambia
     * según dónde vivan los documentos: en disco hace falta, y contra
     * SharePoint la subida ya es entera o nada.
     *
     * La regla ante un nombre ocupado, que no cambió:
     *
     *   mismo contenido      no se reescribe nada, se devuelve lo que ya estaba
     *   contenido distinto   se reintenta como `<base>_DUP_<8 hex del hash>`
     *   y si ESE también     se para: dos documentos distintos reclamando el
     *   está ocupado         mismo nombre y el mismo hash no lo arregla nadie
     *                        automáticamente
     */
    private function copiarValidado($origen, $carpeta, $base, $extension)
    {
        $this->exigirCarpeta();
        $hash = hash_file('sha256', $origen);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('No se pudo calcular el hash de ' . basename($origen) . '.');
        }

        $almacen = $this->almacen;
        $destino = $carpeta . DIRECTORY_SEPARATOR . $base . '.' . $extension;

        try {
            $escrito = $almacen->escribir($destino, $origen, ['hash' => $hash]);
        } catch (AlmacenConflicto $e) {
            $destino = $carpeta . DIRECTORY_SEPARATOR . $base
                . '_DUP_' . strtoupper(substr($hash, 0, 8)) . '.' . $extension;
            try {
                $escrito = $almacen->escribir($destino, $origen, ['hash' => $hash]);
            } catch (AlmacenConflicto $e2) {
                throw new RuntimeException('Existe una colisión de nombre y hash en ' . $destino . '.');
            }
        }

        return [
            'ruta'    => $escrito['ruta'],
            'hash'    => $escrito['hash'],
            'creado'  => $escrito['creado'],
            // En disco van vacíos; en SharePoint son la identidad que
            // sobrevive a que alguien mueva el archivo, y la fila los guarda.
            'item_id' => (string) ($escrito['item_id'] ?? ''),
            'etag'    => (string) ($escrito['etag'] ?? ''),
        ];
    }

    /**
     * Acentos que se van, letra a letra. La Ñ no está: no es un acento.
     *
     * Esto lo hacía iconv con ASCII//TRANSLIT, y hacía dos cosas mal.
     *
     * La primera es la que se ve: en esta plataforma TRANSLIT no reemplaza la
     * letra, le antepone una marca —Ñ→"~N", É→"'E"—, y el filtro de abajo
     * convertía esa marca en un espacio. El token se partía en dos y
     * "COMPAÑIA RIO JAVA" se archivaba como COMPA_NIA_RIO_JAVA, igual que
     * "JOSÉ PÉREZ" como JOS_E_P_EREZ. No era cosa de la Ñ: era de cualquier
     * letra con tilde, que en nombres de proveedor son casi todos.
     *
     * La segunda no se ve y es peor: lo que TRANSLIT devuelve depende de la
     * biblioteca de CADA máquina, y la aplicación corre en varias. El mismo
     * proveedor podía archivarse con un nombre en una computadora y con otro
     * en la de al lado, sobre la misma carpeta compartida.
     *
     * Un mapa no se transporta ni cambia de opinión: dice exactamente qué
     * sale, aquí y en cualquier equipo.
     */
    private const ACENTOS = [
        'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A',
        'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
        'Ç' => 'C', 'Ý' => 'Y',
    ];

    /**
     * El nombre del proveedor tal como va en el nombre del archivo.
     *
     * La Ñ se conserva. Es una letra del idioma, no un acento: COMPAÑIA y
     * COMPANIA no son la misma palabra, y este nombre lo lee la persona que
     * abre la carpeta compartida en el Explorador.
     *
     * Los acentos sí se van (Á→A, Ü→U): ahí la letra es la misma, y quitarlos
     * evita que el mismo proveedor genere dos nombres distintos según cómo lo
     * escribiera quien emitió el comprobante.
     */
    private static function tokenProveedor($nombre)
    {
        $valor = mb_strtoupper(trim((string) $nombre), 'UTF-8');
        $valor = strtr($valor, self::ACENTOS);
        $valor = str_replace('.', '', $valor);

        // Lo que no sea letra, dígito o Ñ pasa a ser separador. Con /u, porque
        // sin él la Ñ son dos bytes y la clase los trataría por separado.
        $valor = preg_replace('/[^A-Z0-9Ñ ]/u', ' ', $valor);
        $valor = preg_replace('/\s+/u', ' ', trim((string) $valor));

        $sufijos = array_flip(self::SUFIJOS_SOCIETARIOS);
        $tokens = array_values(array_filter(explode(' ', $valor), function ($token) use ($sufijos) {
            return $token !== '' && !isset($sufijos[$token]);
        }));
        $token = $tokens ? implode('_', $tokens) : 'PROVEEDOR';

        // mb_substr y no substr: el tope es de letras, y cortar una Ñ por la
        // mitad dejaría un byte suelto en el nombre de un archivo.
        return trim(mb_substr($token, 0, self::MAX_TOKEN_PROVEEDOR, 'UTF-8'), '_') ?: 'PROVEEDOR';
    }
    /**
     * Una carpeta al pie de la raíz. En SharePoint la raíz es la biblioteca
     * misma —cadena vacía— y la ruta no puede empezar con un separador.
     */
    private function enRaiz($nombre)
    {
        return $this->raiz === '' ? $nombre : $this->raiz . DIRECTORY_SEPARATOR . $nombre;
    }

    private function asegurarDirectorio($ruta)
    {
        $this->exigirCarpeta();
        // Donde la subida crea el camino sola (SharePoint), pedir la carpeta
        // antes eran llamadas por red de más en cada comprobante.
        if ($this->almacen->creaCarpetasAlEscribir()) {
            return;
        }
        $this->almacen->asegurarCarpeta($ruta);
        $this->asegurarNotaSistema($ruta);
    }

    /**
     * ¿Hay dónde escribir? En SharePoint siempre: la biblioteca es la raíz.
     * En disco hace falta la carpeta compartida de esta máquina, y sin ella
     * se para con un motivo que dice qué hacer, en vez de dejar que una ruta
     * relativa termine escrita quién sabe dónde.
     */
    private function exigirCarpeta()
    {
        if ($this->raiz === '' && $this->almacen instanceof AlmacenEnDisco) {
            throw new RuntimeException(self::SIN_CARPETA);
        }
    }

    /**
     * ¿Esta máquina necesita la carpeta compartida?
     *
     * Solo si alguna empresa —o la indicada— archiva en disco. Con todas en
     * SharePoint, los XML y PDF viven en la biblioteca y lo demás en esta
     * máquina: la carpeta sincronizada sobra. Si la base no contesta, sí: es
     * lo que se hizo siempre, y equivocarse para este lado solo pide un
     * ajuste de más.
     */
    public static function necesitaCarpetaCompartida($sociedadId = null)
    {
        try {
            if ($sociedadId !== null && (int) $sociedadId > 0) {
                return AlmacenDocumentos::tipoDeSociedad((int) $sociedadId) === AlmacenDocumentos::DISCO;
            }
            if (!class_exists('Sociedad')) {
                require_once dirname(__DIR__) . '/core/Model.php';
                require_once dirname(__DIR__) . '/models/Sociedad.php';
            }
            return (new Sociedad())->hayEmpresasEnDisco();
        } catch (Throwable $e) {
            return true;
        }
    }

    /**
     * La advertencia va cuando SISTEMA ya existe, no antes: crearla al abrir
     * la aplicación llenaría de carpetas cualquier ruta que alguien escriba
     * por error en la configuración. Un fallo al escribirla no interrumpe el
     * archivado —el documento importa más que su letrero—.
     *
     * Solo en disco. A SharePoint van XML y PDF y nada más (ver
     * AlmacenEnGraph::escribir), y ahí tampoco hace la misma falta: un
     * documento movido desde el navegador se sigue por su id, y uno borrado
     * queda en la papelera del sitio y marcado en la aplicación.
     */
    private function asegurarNotaSistema($ruta)
    {
        if ($this->notaSistemaLista || !$this->almacen instanceof AlmacenEnDisco) {
            return;
        }
        $sistema = $this->carpetaSistema();
        $normal = rtrim((string) $ruta, '/' . DIRECTORY_SEPARATOR);
        $dentro = strcasecmp($normal, $sistema) === 0
            || stripos($normal, $sistema . DIRECTORY_SEPARATOR) === 0;
        // Si la ruta está dentro de SISTEMA, SISTEMA ya existe: quien llama
        // acaba de pedirle la carpeta al almacén.
        if (!$dentro) {
            return;
        }
        $this->notaSistemaLista = true;
        $almacen = $this->almacen;
        $nota = $sistema . DIRECTORY_SEPARATOR . self::NOTA_SISTEMA;
        if ($almacen->identidad($nota) !== null) {
            return;
        }
        // El almacén recibe archivos, no texto: se arma uno local y se le
        // entrega.
        $local = tempnam(sys_get_temp_dir(), 'nota');
        try {
            if ($local !== false && @file_put_contents($local, self::textoNotaSistema()) !== false) {
                $almacen->escribir($nota, $local);
            }
        } catch (Throwable $e) {
            // Sin letrero se sigue: el documento importa más.
        } finally {
            if ($local !== false) {
                @unlink($local);
            }
        }
    }

    private static function textoNotaSistema()
    {
        $lineas = [
            'CARPETA DEL SISTEMA - NO MODIFICAR',
            '',
            'Lo que hay aquí lo administra Nexo Fiscal: son los XML y los PDF tal',
            'como llegaron por correo, ordenados por año, mes y tipo de documento.',
            '',
            'No mover, no renombrar y no borrar nada de esta carpeta. La aplicación',
            'guarda la ruta de cada documento; un archivo cambiado de lugar deja de',
            'abrirse desde el sistema y aparece marcado como perdido.',
            '',
            'Si necesitas los respaldos de un pago, están en la carpeta',
            '"' . self::CARPETA_PAGOS . '", al lado de esta. Esa sí es para trabajar:',
            'se arma sola con una COPIA de cada documento y se puede enviar o borrar',
            'sin afectar nada.',
            '',
            'Este archivo lo genera Nexo Fiscal.',
        ];

        // BOM y saltos de Windows: la nota se abre con el Bloc de notas desde
        // el explorador, y sin ellos aparece en una sola línea y con los
        // acentos rotos, que es justo lo contrario de lo que busca.
        return "\xEF\xBB\xBF" . implode("\r\n", $lineas) . "\r\n";
    }
}
