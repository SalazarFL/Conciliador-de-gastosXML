# Compatibilidad certificada con PHP 8.4

## Estado

La aplicación fue validada el **15 de agosto de 2026** con la siguiente matriz:

| Componente | Versión validada |
|---|---|
| PHP CLI | 8.4.24 x64 Thread Safe, VS17 |
| PHP web | 8.4.24 x64 Non Thread Safe, VS17, FastCGI |
| Extensión IMAP | PECL IMAP 1.0.3 |
| Apache | 2.4.58 Win64, VS17 |
| MariaDB | 10.4.32 |
| Composer | 2.10.2 |
| Rama base | `trabajo-multisociedad-y-rutas-compartidas` |
| Commit base | `e38fb11fcc202cf3d3f0d54fdba8fd25c5aafbfb` más los cambios de compatibilidad pendientes de commit |

El alcance es **compatibilidad de la aplicación con PHP 8.4**. La certificación
se debe repetir después de integrar cambios funcionales o actualizar PHP,
Apache, MariaDB o una extensión.

## Evidencia obtenida

- 158 archivos PHP pasaron `php -l` con `E_ALL`, sin errores ni deprecaciones.
- Las 43 pruebas automatizadas finalizaron sin fallos ni omisiones.
- Composer validó `composer.json` y su bloqueo de plataforma.
- Apache y PHP 8.4 FastCGI completaron inicio de sesión con CSRF y sesión real.
- Las rutas `/`, `/diagnostico`, `/notas-credito`, `/por-pagar` y `/correo`
  respondieron HTTP 200 bajo PHP 8.4.
- El tramo nuevo del log de Apache terminó sin errores, advertencias de PHP ni
  avisos deprecados.
- Las pruebas de base se ejecutaron en `xmlconcilia_php84_cert`, una base local
  aislada que contiene estructura y datos sintéticos, no información empresarial.

## Decisión de despliegue en Windows

No se debe copiar el PHP oficial sobre `C:\xampp\php` ni reemplazar DLL de
Apache. Al cargar PHP 8.4 TS directamente como `mod_php` en el Apache 2.4.58 de
esta instalación se observó un conflicto de DLL/OpenSSL que impedía cargar
`curl`. La matriz limpia utiliza:

```text
Apache 2.4 → mod_proxy_fcgi → PHP 8.4 NTS (php-cgi.exe)
```

FastCGI mantiene las bibliotecas de PHP en otro proceso y evita alterar el
Apache/PHP 8.2 que ya funciona. Para un despliegue permanente, `php-cgi.exe`
debe quedar bajo un supervisor de procesos o servicio de Windows; el script de
certificación lo inicia y lo apaga únicamente durante la prueba.

Los binarios instalados en la computadora donde se realizó la validación son:

```text
C:\xampp\php84\php.exe
C:\xampp\php84-nts\php-cgi.exe
C:\xampp\php84\composer.phar
```

Las configuraciones paralelas de Apache están en:

```text
C:\xampp\php84\apache-cert\httpd.conf
C:\xampp\php84\apache-cert\httpd-xampp.conf
```

Esa instancia escucha únicamente en `127.0.0.1:8484`; el FastCGI de prueba,
únicamente en `127.0.0.1:9084`.

## Repetir la certificación

Se necesita una configuración local que apunte a una base cuyo nombre incluya
`test` o `cert`. El verificador rechaza bases remotas y nombres que no parezcan
de prueba para evitar ejecutar la suite sobre datos reales.

```powershell
$env:XMLCONCILIA_CONFIG_FILE = 'C:\xampp\htdocs\xmlconcilia\app\config\local.php84-cert.php'

powershell -NoProfile -ExecutionPolicy Bypass -File scripts\verificar-php84.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\verificar-web-php84.ps1
```

La configuración local de certificación está ignorada por Git. Nunca se deben
guardar credenciales reales en el repositorio.

Para revisar solo versión, extensiones, sintaxis y deprecaciones, sin abrir la
base de datos:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\verificar-php84.ps1 -SoloCodigo
```

También se puede ejecutar mediante Composer:

```powershell
C:\xampp\php84\php.exe C:\xampp\php84\composer.phar certify:php84
C:\xampp\php84\php.exe C:\xampp\php84\composer.phar certify:web84
```

## Límites pendientes de validación externa

- Se confirmó que IMAP 1.0.3 carga y que las pruebas de correo pasan, pero no
  se abrió un buzón empresarial real durante la certificación.
- No se cambió el Apache cotidiano de la computadora: continúa con su
  configuración original para no interrumpir el trabajo.
- La base aislada se creó copiando únicamente la estructura de la base local
  actual. La cadena histórica completa de instalación desde `schema.sql` y
  todas las migraciones debe evaluarse por separado antes de entregar un
  instalador para terceros.
- Exportaciones y parsers están cubiertos por pruebas, pero conviene realizar
  una aceptación manual con archivos representativos y sin datos sensibles
  antes de cada entrega comercial.

## Referencias oficiales

- [Versiones de PHP soportadas](https://www.php.net/supported-versions.php)
- [Migración a PHP 8.4](https://www.php.net/manual/en/migration84.php)
- [PHP para Windows: TS y NTS](https://www.php.net/manual/en/install.windows.manual.php)
- [FastCGI de Apache](https://httpd.apache.org/docs/2.4/mod/mod_proxy_fcgi.html)
- [IMAP 1.0.3 para Windows](https://pecl.php.net/package/imap/1.0.3/windows)
