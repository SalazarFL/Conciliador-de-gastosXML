<?php
/**
 * Diagnóstico de la instalación de esta computadora.
 *
 * La misma revisión que `php cli/diagnostico.php`, en pantalla: quien reporta
 * un problema puede abrir esto y mandar una captura, sin que nadie tenga que
 * conectarse a su equipo.
 *
 * Aquí vive también el respaldo de la base a la carpeta compartida, porque es
 * la pantalla a la que ya se manda a alguien cuando algo no anda — y sacar el
 * respaldo es justamente lo primero que hay que pedirle a quien sí alcanza el
 * servidor. El botón está reservado a administradores: escribe en la carpeta
 * que todos sincronizan.
 */

require_once __DIR__ . '/../helpers/Diagnostico.php';
require_once __DIR__ . '/../helpers/RespaldoBase.php';

class DiagnosticoController extends Controller
{
    public function __construct() { $this->requireAuth(); }

    public function index()
    {
        $esAdmin = !empty($_SESSION['user_is_admin']);

        $this->render('diagnostico/index', [
            'informe' => (new Diagnostico())->ejecutar(),
            'esAdmin' => $esAdmin,
            // La tarjeta de respaldo se dibuja ya con datos: si el JS falla o
            // la carpeta compartida no responde, igual se ve el último estado.
            'respaldo' => $esAdmin ? $this->datosRespaldo() : null,
        ]);
    }

    // ── Respaldo de la base ────────────────────────────────────────

    /**
     * Estado del último respaldo y qué hay hoy en la carpeta compartida
     * (POST, JSON). La pantalla lo consulta cada pocos segundos mientras hay
     * uno corriendo.
     */
    public function respaldoEstado()
    {
        $this->soloAdminJson();
        $this->json(['ok' => true] + $this->datosRespaldo());
    }

    /**
     * Lanza el respaldo (POST, JSON) y contesta al instante.
     *
     * No se hace aquí mismo: el volcado tarda de segundos a minutos según el
     * tamaño y la carpeta de OneDrive, y una petición HTTP que dura eso se
     * come el max_execution_time y deja al usuario mirando una pestaña
     * congelada sin saber si funcionó. Se lanza `cli/respaldar_base.php` en
     * segundo plano —el mismo lanzador oculto que usa la sincronización de
     * correo— y la pantalla sigue el avance por el archivo de estado.
     */
    public function respaldoIniciar()
    {
        $this->soloAdminJson();
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        $previo = RespaldoBase::leerEstado();
        if (is_array($previo) && ($previo['estado'] ?? '') === 'corriendo') {
            $this->json(['ok' => true, 'yaCorriendo' => true] + $this->datosRespaldo());
        }

        // Lo que sí conviene comprobar antes de lanzar: un error aquí se ve en
        // pantalla al momento, y dentro del proceso de fondo solo quedaría
        // escrito en un archivo que nadie mira.
        if (!function_exists('exec')) {
            $this->json(['ok' => false, 'message' => 'exec() está deshabilitado en PHP; no se puede lanzar el respaldo.'], 422);
        }
        if (RespaldoBase::rutaMysqldump() === null) {
            $this->json(['ok' => false, 'message' => 'No se encontró mysqldump.exe. Se buscó en C:\\xampp\\mysql\\bin y en el PATH.'], 422);
        }
        $php = $this->rutaPhpCli();
        if ($php === null) {
            $this->json(['ok' => false, 'message' => 'No se encontró php.exe (busqué en C:\\xampp\\php\\). Verifica la instalación de XAMPP.'], 422);
        }
        try {
            $carpeta = RespaldoBase::carpetaDestino();
            if (!RutaDocumento::permiteEscritura($carpeta)) {
                $this->json([
                    'ok' => false,
                    'message' => 'No se puede escribir en la carpeta compartida: ' . $carpeta,
                ], 422);
            }
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        try {
            $this->lanzarEnSegundoPlano($php, 'manual');
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No se pudo lanzar el respaldo: ' . $e->getMessage()], 500);
        }

        $this->json([
            'ok'      => true,
            'lanzado' => true,
            'message' => 'Respaldo en curso. Puedes cerrar esta página: sigue corriendo.',
        ]);
    }

    /**
     * Registra la tarea programada nocturna (POST, JSON).
     *
     * Un respaldo que depende de que alguien se acuerde no es un respaldo. La
     * tarea corre a la hora indicada en la sesión del usuario conectado, sin
     * contraseña ni permisos de administrador de Windows.
     */
    public function respaldoAutoActivar()
    {
        $this->soloAdminJson();
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->json(['ok' => false, 'message' => 'La tarea programada solo está disponible en Windows.'], 422);
        }
        if (!function_exists('exec')) {
            $this->json(['ok' => false, 'message' => 'exec() está deshabilitado en PHP; no se puede registrar la tarea.'], 422);
        }

        $hora = trim((string) $this->post('hora', '22:00'));
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hora)) {
            $this->json(['ok' => false, 'message' => 'Hora inválida; usa el formato HH:MM (24 horas).'], 422);
        }

        $php = $this->rutaPhpCli();
        if ($php === null) {
            $this->json(['ok' => false, 'message' => 'No se encontró php.exe (busqué en C:\\xampp\\php\\).'], 422);
        }
        $script = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'respaldar_base.php';
        if (!is_file($script)) {
            $this->json(['ok' => false, 'message' => 'No se encontró cli/respaldar_base.php.'], 500);
        }

        try {
            $vbs = $this->rutaLanzador();
            $cmd = '"' . $php . '" "' . $script . '" --motivo=automatico';
            file_put_contents(
                $vbs,
                'CreateObject("WScript.Shell").Run "' . str_replace('"', '""', $cmd) . '", 0, False' . "\r\n"
            );
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => 'No se pudo crear el lanzador: ' . $e->getMessage()], 500);
        }

        [$codigo, $salida] = $this->ejecutarPowerShell($this->scriptRegistrarTarea($vbs, $hora));
        if ($codigo !== 0) {
            $detalle = trim(implode(' ', array_slice($salida, 0, 6)));
            $this->json([
                'ok' => false,
                'message' => 'No se pudo registrar la tarea programada' . ($detalle !== '' ? ': ' . $detalle : '.'),
            ], 500);
        }

        $this->json([
            'ok'      => true,
            'hora'    => $hora,
            'message' => 'Respaldo automático activado todos los días a las ' . $hora . '.',
        ]);
    }

    public function respaldoAutoDesactivar()
    {
        $this->soloAdminJson();
        if (!$this->isPost()) {
            $this->json(['ok' => false, 'message' => 'Metodo no permitido.'], 405);
        }

        if (DIRECTORY_SEPARATOR === '\\' && function_exists('exec')) {
            $this->ejecutarPowerShell(
                "\$ErrorActionPreference = 'SilentlyContinue'\r\n"
                . "Unregister-ScheduledTask -TaskName '" . RespaldoBase::TAREA . "' -Confirm:\$false | Out-Null\r\n"
                . "Write-Output 'OK'\r\n"
            );
        }

        $this->json(['ok' => true, 'message' => 'Respaldo automático desactivado.']);
    }

    // ── Piezas ─────────────────────────────────────────────────────

    /**
     * Todo lo que la tarjeta necesita para dibujarse. Se arma igual para el
     * render inicial y para el sondeo, para que no haya dos verdades.
     */
    private function datosRespaldo()
    {
        $carpeta = '';
        $error = '';
        try {
            $carpeta = RespaldoBase::carpetaDestino();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $archivos = RespaldoBase::listar();
        foreach ($archivos as $i => $a) {
            $archivos[$i]['tamano'] = RespaldoBase::humano($a['bytes']);
        }

        return [
            'estado'     => RespaldoBase::leerEstado(),
            'archivos'   => array_slice($archivos, 0, 10),
            'carpeta'    => $carpeta,
            'carpetaError' => $error,
            'conserva'   => RespaldoBase::CONSERVAR,
            'automatico' => $this->tareaProgramada(),
            'mysqldump'  => RespaldoBase::rutaMysqldump(),
        ];
    }

    /** ¿Existe la tarea nocturna en este Windows? Devuelve la hora si la hay. */
    private function tareaProgramada()
    {
        if (DIRECTORY_SEPARATOR !== '\\' || !function_exists('exec')) {
            return ['activo' => false, 'hora' => ''];
        }
        $salida = [];
        $codigo = 1;
        @exec('schtasks /query /TN "' . RespaldoBase::TAREA . '" /FO LIST 2>&1', $salida, $codigo);
        if ($codigo !== 0) {
            return ['activo' => false, 'hora' => ''];
        }
        // La hora se saca del "Next Run Time"/"Hora próxima ejecución", que
        // schtasks localiza: se busca el patrón de hora, no la etiqueta.
        $hora = '';
        foreach ($salida as $linea) {
            if (preg_match('/(\d{1,2}:\d{2}):\d{2}/', $linea, $m)) {
                $hora = $m[1];
                break;
            }
        }
        return ['activo' => true, 'hora' => $hora];
    }

    /** Lanza el CLI oculto y sin esperar, igual que la sincronización. */
    private function lanzarEnSegundoPlano($php, $motivo)
    {
        $script = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'respaldar_base.php';
        if (!is_file($script)) {
            throw new RuntimeException('No se encontró cli/respaldar_base.php.');
        }

        $cmd = '"' . $php . '" "' . $script . '" --motivo=' . $motivo;

        if (DIRECTORY_SEPARATOR === '\\') {
            // wscript lanza sin ventana de consola y devuelve el control de
            // inmediato; con exec() a secas PHP se queda esperando el final.
            $vbs = $this->rutaLanzador('respaldo_manual.vbs');
            file_put_contents(
                $vbs,
                'CreateObject("WScript.Shell").Run "' . str_replace('"', '""', $cmd) . '", 0, False' . "\r\n"
            );
            @exec('wscript.exe //B //Nologo "' . $vbs . '"');
            return;
        }
        @exec($cmd . ' > /dev/null 2>&1 &');
    }

    private function rutaLanzador($nombre = 'respaldo_launch.vbs')
    {
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage'
             . DIRECTORY_SEPARATOR . 'correo';
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear ' . $dir);
        }
        return $dir . DIRECTORY_SEPARATOR . $nombre;
    }

    /**
     * Ubica php.exe de la CLI. XAMPP lo trae en <unidad>\xampp\php\php.exe;
     * se prueban también rutas derivadas del binario actual y de PHPRC.
     */
    private function rutaPhpCli()
    {
        $root = dirname(__DIR__, 2); // ...\xmlconcilia
        $candidatos = [
            dirname($root, 2) . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe',
            'C:\\xampp\\php\\php.exe',
        ];
        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            $candidatos[] = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php.exe';
        }
        $phprc = getenv('PHPRC');
        if ($phprc) {
            $candidatos[] = rtrim($phprc, '\\/') . DIRECTORY_SEPARATOR . 'php.exe';
        }
        foreach ($candidatos as $c) {
            if ($c !== '' && @is_file($c)) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Escribe un .ps1 temporal (con BOM: PowerShell 5.1 lee sin BOM como ANSI)
     * y lo ejecuta. Devuelve [codigoSalida, lineasDeSalida].
     */
    private function ejecutarPowerShell($script)
    {
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage'
             . DIRECTORY_SEPARATOR . 'correo' . DIRECTORY_SEPARATOR . 'tmp';
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear ' . $dir);
        }
        $ruta = $dir . DIRECTORY_SEPARATOR . 'ps_' . bin2hex(random_bytes(6)) . '.ps1';
        file_put_contents($ruta, "\xEF\xBB\xBF" . $script);

        $salida = [];
        $codigo = 1;
        @exec('powershell -NoProfile -ExecutionPolicy Bypass -File "' . $ruta . '" 2>&1', $salida, $codigo);

        @unlink($ruta);
        return [$codigo, $salida];
    }

    /**
     * Registra la tarea diaria. Sin acentos: corre en PowerShell 5.1, que lee
     * el .ps1 como ANSI si algo se cuela sin BOM.
     */
    private function scriptRegistrarTarea($vbs, $hora)
    {
        $plantilla = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$tn = '{{TAREA}}'
$vbs = '{{VBS}}'

$accion = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument ('//B //Nologo "' + $vbs + '"')
$t = New-ScheduledTaskTrigger -Daily -At '{{HORA}}'

# Correr como el usuario con sesion abierta (sin contrasena ni admin)
$usuario = (Get-CimInstance Win32_ComputerSystem).UserName
if (-not $usuario) { $usuario = "$env:USERDOMAIN\$env:USERNAME" }
$principal = New-ScheduledTaskPrincipal -UserId $usuario -LogonType Interactive -RunLevel Limited

# StartWhenAvailable: si la maquina estaba apagada a esa hora, corre al prender.
$ajustes = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 2)

Register-ScheduledTask -TaskName $tn -Action $accion -Trigger $t -Principal $principal -Settings $ajustes -Force | Out-Null
Write-Output 'OK'
POWERSHELL;

        return str_replace(
            ['{{TAREA}}', '{{VBS}}', '{{HORA}}'],
            [RespaldoBase::TAREA, str_replace("'", "''", $vbs), $hora],
            $plantilla
        );
    }

    /**
     * El diagnóstico es para todo el mundo —de eso se trata, que quien tiene
     * el problema lo pueda mandar—, pero generar un respaldo escribe en la
     * carpeta compartida de la empresa. Eso sí es de administrador.
     */
    private function soloAdminJson()
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['user_is_admin'])) {
            $this->json(['ok' => false, 'message' => 'Solo un administrador puede generar respaldos.'], 403);
        }
    }
}
