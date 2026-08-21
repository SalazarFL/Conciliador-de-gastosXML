<?php
/**
 * Archivo durable de comprobantes fuera de la base de datos.
 *
 * Estructura: RAIZ/SISTEMA/AAAA/MM MES/Facturas|Notas de crédito/ARCHIVO.ext
 * Los archivos se copian primero como .partial, se valida SHA-256 y solo
 * entonces se renombran. Nunca sobrescribe un contenido diferente.
 *
 * Por qué SISTEMA: la raíz es una carpeta compartida donde también se trabaja
 * —se arman los pagos semanales, se dejan archivos a mano— y ahí dentro el
 * árbol de años no se distinguía de una carpeta cualquiera. Todo lo que la
 * aplicación bajó del correo cuelga de SISTEMA, con su nota de advertencia,
 * para que se vea de un vistazo qué no hay que mover: la base guarda la ruta
 * de cada documento, y un archivo cambiado de lugar deja de abrirse y aparece
 * marcado como perdido. Lo que sí es para trabajar —PAGOS SEMANALES, con
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

class DocumentoArchivo
{
    public const CARPETA_PAGOS = 'PAGOS SEMANALES';

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

    private $raiz;
    private $notaSistemaLista = false;

    public function __construct($raiz = '')
    {
        $raiz = trim((string) $raiz);
        if ($raiz === '') {
            $raiz = self::raizConfigurada();
        }
        if ($raiz === '') {
            throw new RuntimeException('Configura la carpeta raíz de XML y PDF en Correo.');
        }

        $this->raiz = rtrim($raiz, '/\\');
        $this->asegurarDirectorio($this->raiz);
        if (!RutaDocumento::permiteEscritura($this->raiz)) {
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
        ];
    }

    /** Agrega un PDF a un registro ya archivado sin volver a copiar su XML. */
    public function archivarPdfPara(array $registro, $pdfOrigen)
    {
        if (!is_file($pdfOrigen)) {
            throw new RuntimeException('El PDF temporal ya no existe.');
        }

        $rutaXml = trim((string) ($registro['ruta_xml'] ?? ''));
        if ($rutaXml !== '' && is_file($rutaXml)) {
            $carpeta = dirname($rutaXml);
            $base = pathinfo($rutaXml, PATHINFO_FILENAME);
        } else {
            $carpeta = $this->carpetaDocumento(
                (string) ($registro['fecha_emision'] ?? ''),
                (string) ($registro['tipo_documento'] ?? 'FE')
            );
            $base = self::nombreBase($registro, (string) ($registro['proveedor_nombre'] ?? 'PROVEEDOR'));
        }
        $this->asegurarDirectorio($carpeta);
        $pdf = $this->copiarValidado($pdfOrigen, $carpeta, $base, 'pdf');

        return [
            'ruta_pdf' => $pdf['ruta'],
            'archivo_pdf' => basename($pdf['ruta']),
            'hash_pdf' => $pdf['hash'],
            'pdf_creado' => $pdf['creado'],
            'estado_pdf' => 'disponible',
            'archivado_en' => date('Y-m-d H:i:s'),
        ];
    }

    public static function eliminarCreados(array $resultado)
    {
        foreach ([['xml_creado', 'ruta_xml'], ['pdf_creado', 'ruta_pdf']] as $par) {
            if (!empty($resultado[$par[0]]) && !empty($resultado[$par[1]]) && is_file($resultado[$par[1]])) {
                @unlink($resultado[$par[1]]);
            }
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

    /** La carpeta que la aplicación administra: dentro no se toca nada. */
    public function carpetaSistema()
    {
        return $this->raiz . DIRECTORY_SEPARATOR . self::CARPETA_SISTEMA;
    }

    /** SISTEMA/AAAA/MM MES, que es el nivel donde se reparte todo lo archivado. */
    public function carpetaMes($fecha)
    {
        $ts = strtotime($fecha);
        if ($ts === false) {
            throw new RuntimeException('El XML no contiene una fecha de emisión válida.');
        }

        return $this->carpetaSistema() . DIRECTORY_SEPARATOR . date('Y', $ts)
            . DIRECTORY_SEPARATOR . date('m', $ts) . ' ' . self::MESES[(int) date('n', $ts)];
    }

    /** SISTEMA/AAAA/MM MES/Facturas|Notas de crédito — solo fecha y tipo. */
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

    /** Carpeta elegida para reunir los pares XML/PDF de un pago semanal. */
    public function carpetaPagoSemanal($nombre)
    {
        $nombre = self::normalizarCarpetaPago($nombre);
        if ($nombre === '') {
            throw new RuntimeException('Selecciona un nombre válido para la carpeta del pago semanal.');
        }
        return $this->raiz . DIRECTORY_SEPARATOR . self::CARPETA_PAGOS
            . DIRECTORY_SEPARATOR . $nombre;
    }

    public function prepararCarpetaPagoSemanal($nombre)
    {
        $ruta = $this->carpetaPagoSemanal($nombre);
        $this->asegurarDirectorio($ruta);
        return $ruta;
    }

    /**
     * Deja en la carpeta del pago semanal una COPIA del par ya archivado.
     *
     * Copia y no traslada, a propósito. Esa carpeta existe para entregar los
     * respaldos de una semana: se arma, se manda y quien la recibe la borra
     * cuando termina. Mientras el par se mudaba ahí dentro, esa carpeta era el
     * único ejemplar del documento y borrarla se llevaba el XML y el PDF sin
     * que nada lo avisara —así se perdieron los respaldos de un pago entero—.
     * El original se queda en la carpeta del mes, que nadie reparte.
     *
     * Idempotente y barato: el organizador corre seguido, y si la copia ya
     * está y pesa lo mismo no se vuelve a leer el contenido de nada.
     */
    public function copiarAPagoSemanal($xmlOrigen, $pdfOrigen, array $documento, $carpetaPago, $proveedor)
    {
        $carpeta = $this->carpetaPagoSemanal($carpetaPago);
        $base = self::nombreBase($documento, $proveedor);
        $salida = ['ruta_xml' => '', 'ruta_pdf' => '', 'copiados' => 0];

        foreach (['xml' => $xmlOrigen, 'pdf' => $pdfOrigen] as $extension => $origen) {
            $origen = trim((string) $origen);
            if ($origen === '' || !is_file($origen)) {
                continue;
            }

            $destino = $carpeta . DIRECTORY_SEPARATOR . $base . '.' . $extension;
            if (is_file($destino) && filesize($destino) === filesize($origen)) {
                $salida['ruta_' . $extension] = $destino;
                continue;
            }

            $this->asegurarDirectorio($carpeta);
            $copia = $this->copiarValidado($origen, $carpeta, $base, $extension);
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
        $nombre = preg_replace('/\s+/u', ' ', trim((string) $nombre, " .\t\n\r\0\x0B"));
        return mb_substr((string) $nombre, 0, 100, 'UTF-8');
    }

    /** Carpetas semanales ya creadas que pueden reutilizarse en el selector. */
    public function carpetasPagoSemanal()
    {
        $base = $this->raiz . DIRECTORY_SEPARATOR . self::CARPETA_PAGOS;
        if (!is_dir($base)) {
            return [];
        }
        $resultado = [];
        foreach (new DirectoryIterator($base) as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }
            $resultado[] = $item->getFilename();
        }
        natcasesort($resultado);
        return array_values($resultado);
    }

    private function copiarValidado($origen, $carpeta, $base, $extension)
    {
        $hash = hash_file('sha256', $origen);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('No se pudo calcular el hash de ' . basename($origen) . '.');
        }

        $destino = $carpeta . DIRECTORY_SEPARATOR . $base . '.' . $extension;
        if (is_file($destino)) {
            if (hash_file('sha256', $destino) === $hash) {
                return ['ruta' => $destino, 'hash' => $hash, 'creado' => false];
            }
            $destino = $carpeta . DIRECTORY_SEPARATOR . $base . '_DUP_' . strtoupper(substr($hash, 0, 8)) . '.' . $extension;
            if (is_file($destino)) {
                if (hash_file('sha256', $destino) === $hash) {
                    return ['ruta' => $destino, 'hash' => $hash, 'creado' => false];
                }
                throw new RuntimeException('Existe una colisión de nombre y hash en ' . $destino . '.');
            }
        }

        $parcial = $destino . '.partial_' . str_replace('.', '', uniqid('', true));
        if (!copy($origen, $parcial)) {
            throw new RuntimeException('No se pudo copiar ' . basename($origen) . ' al archivo local.');
        }
        if (hash_file('sha256', $parcial) !== $hash) {
            @unlink($parcial);
            throw new RuntimeException('La copia local no superó la validación SHA-256.');
        }
        if (!rename($parcial, $destino)) {
            @unlink($parcial);
            throw new RuntimeException('No se pudo finalizar el archivo local ' . $destino . '.');
        }

        return ['ruta' => $destino, 'hash' => $hash, 'creado' => true];
    }

    private static function tokenProveedor($nombre)
    {
        $valor = mb_strtoupper(trim((string) $nombre), 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
        if (is_string($ascii) && $ascii !== '') {
            $valor = $ascii;
        }
        $valor = str_replace('.', '', $valor);
        $valor = preg_replace('/[^A-Z0-9 ]/', ' ', $valor);
        $valor = preg_replace('/\s+/', ' ', trim($valor));
        $sufijos = array_flip(self::SUFIJOS_SOCIETARIOS);
        $tokens = array_values(array_filter(explode(' ', $valor), function ($token) use ($sufijos) {
            return $token !== '' && !isset($sufijos[$token]);
        }));
        $token = $tokens ? implode('_', $tokens) : 'PROVEEDOR';
        return trim(substr($token, 0, self::MAX_TOKEN_PROVEEDOR), '_');
    }

    private function asegurarDirectorio($ruta)
    {
        if (!is_dir($ruta) && !mkdir($ruta, 0777, true) && !is_dir($ruta)) {
            throw new RuntimeException('No se pudo crear la carpeta: ' . $ruta);
        }
        $this->asegurarNotaSistema($ruta);
    }

    /**
     * La advertencia va cuando SISTEMA ya existe, no antes: crearla al abrir
     * la aplicación llenaría de carpetas cualquier ruta que alguien escriba
     * por error en la configuración. Un fallo al escribirla no interrumpe el
     * archivado —el documento importa más que su letrero—.
     */
    private function asegurarNotaSistema($ruta)
    {
        if ($this->notaSistemaLista) {
            return;
        }
        $sistema = $this->carpetaSistema();
        $normal = rtrim((string) $ruta, '/' . DIRECTORY_SEPARATOR);
        $dentro = strcasecmp($normal, $sistema) === 0
            || stripos($normal, $sistema . DIRECTORY_SEPARATOR) === 0;
        if (!$dentro || !is_dir($sistema)) {
            return;
        }
        $this->notaSistemaLista = true;
        $nota = $sistema . DIRECTORY_SEPARATOR . self::NOTA_SISTEMA;
        if (is_file($nota)) {
            return;
        }
        @file_put_contents($nota, self::textoNotaSistema());
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
