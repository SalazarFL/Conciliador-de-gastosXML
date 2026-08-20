<?php
/**
 * Configuración del sistema: una sola pantalla, alcanzable con el engranaje
 * desde cualquier módulo.
 *
 * Antes esto era un modal que vivía dentro de Correo. La carpeta raíz de XML y
 * PDF, las empresas del grupo o el respaldo de la base no son opciones del
 * correo —las usan todos los módulos—, y para cambiarlas había que saber que
 * se guardaban ahí. Aquí están juntas, agrupadas por tema, con una dirección
 * propia que se puede compartir: /configuracion#empresas.
 *
 * Los POST que atienden estas secciones NO se mudaron: siguen en el
 * controlador dueño de lo que escriben (CorreoController para la carpeta, las
 * cuentas y la automatización; DiagnosticoController para el respaldo;
 * SociedadesController y UsuariosController para lo suyo). Traerlos aquí
 * obligaría a duplicar los helpers con los que trabajan. Es el mismo reparto
 * que ya usan las pantallas de carga de cada módulo.
 */

require_once __DIR__ . '/../helpers/MailFetcher.php';
require_once __DIR__ . '/../helpers/RespaldoBase.php';

class ConfiguracionController extends Controller
{
    public function __construct()
    {
        $this->requireAuth();
    }

    public function index()
    {
        $esAdmin = !empty($_SESSION['user_is_admin']);

        $configLocal    = $this->configLocal();
        $cuentaActivaId = (int) ($configLocal['cuenta_id'] ?? 0);

        $cuentas         = [];
        $sociedades      = [];
        $sociedadActiva  = null;
        $sociedadEnUsoId = 0;

        try {
            $sociedadModel   = $this->loadModel('Sociedad');
            $sociedades      = $sociedadModel->getAll();
            $sociedadActiva  = $sociedadModel->getActiva();
            $sociedadEnUsoId = (int) ($sociedadActiva['id'] ?? 0);
        } catch (Throwable $e) {
            // Sin base la pantalla sigue sirviendo para la carpeta raíz
        }

        $nombrePorSociedad = [];
        foreach ($sociedades as $s) {
            $nombrePorSociedad[(int) $s['id']] = (string) $s['nombre'];
        }

        try {
            $cuentasModel = $this->loadModel('CorreoCuenta');
            $cuentasModel->seedDesdeArchivo();

            // TODOS los buzones, también los de otras empresas: este es el
            // único lugar donde se corrige una asignación equivocada, y un
            // buzón mal asignado que no se listara sería invisible.
            foreach ($cuentasModel->getAll() as $c) {
                $sociedadesDe = [];
                try {
                    $sociedadesDe = $cuentasModel->sociedadesDe((int) $c['id']);
                } catch (Throwable $e) {
                }

                $nombres = [];
                foreach ($sociedadesDe as $sid) {
                    if (isset($nombrePorSociedad[(int) $sid])) {
                        $nombres[] = $nombrePorSociedad[(int) $sid];
                    }
                }

                // Al front solo van datos no sensibles: nunca la contraseña.
                $cuentas[] = [
                    'id'         => (int) $c['id'],
                    'nombre'     => (string) $c['nombre'],
                    'usuario'    => (string) $c['usuario'],
                    'host'       => (string) $c['host'],
                    'puerto'     => (int) $c['puerto'],
                    'carpeta'    => (string) $c['carpeta'],
                    'indice_retencion_dias' => (int) ($c['indice_retencion_dias'] ?? 1825),
                    'sociedades' => $sociedadesDe,
                    'sociedades_nombres' => $nombres,
                    'atiende_actual' => in_array($sociedadEnUsoId, array_map('intval', $sociedadesDe), true),
                ];
            }
        } catch (Throwable $e) {
        }

        $semanas = [];
        try {
            $semanas = $this->loadModel('Semana')->getAll();
        } catch (Throwable $e) {
        }

        $usuarios = [];
        if ($esAdmin) {
            try {
                require_once __DIR__ . '/../models/Usuario.php';
                $usuarios = (new Usuario())->getAll();
            } catch (Throwable $e) {
            }
        }

        $this->render('configuracion/index', [
            'title'           => 'Configuración - Nexo Fiscal',
            'esAdmin'         => $esAdmin,
            'imapDisponible'  => MailFetcher::extensionDisponible(),
            'configLocal'     => $configLocal,
            'cuentas'         => $cuentas,
            'cuentaActivaId'  => $cuentaActivaId,
            'sociedades'      => $sociedades,
            'sociedadActiva'  => $sociedadActiva,
            'sociedadEnUsoId' => $sociedadEnUsoId,
            'semanas'         => $semanas,
            'semanaActiva'    => $this->semanaActiva(),
            'usuarios'        => $usuarios,
            // La tarjeta de respaldo se dibuja ya con datos: si el JS falla o
            // la carpeta compartida no responde, igual se ve el último estado.
            'respaldo'        => $esAdmin ? RespaldoBase::panel() : null,
            // Ancla a la que saltar (?ir=usuarios), para que quien llegue
            // redirigido desde otra pantalla caiga en su sección.
            'seccionInicial'  => preg_replace('/[^a-z]/', '', strtolower((string) $this->get('ir', ''))),
        ]);
    }

    /**
     * La configuración de esta computadora (storage/correo/config.json). La
     * escribe CorreoController@config; aquí solo se lee para pintar.
     */
    private function configLocal()
    {
        $defaults = ['carpeta_destino' => '', 'cuenta_id' => 0];
        $ruta = MailFetcher::storagePath() . DIRECTORY_SEPARATOR . 'config.json';

        if (is_file($ruta)) {
            $data = json_decode((string) file_get_contents($ruta), true);
            if (is_array($data)) {
                return array_merge($defaults, $data);
            }
        }

        return $defaults;
    }
}
