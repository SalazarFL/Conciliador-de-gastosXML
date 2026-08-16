# Migracion permanente del stack Windows

## Objetivo

Sustituir el conjunto XAMPP por componentes independientes, sin cambiar todos
los elementos a la vez y conservando una reversión inmediata durante el cambio.

Stack preparado:

- Apache 2.4.68 x64 en `C:\WebServer\Apache24`.
- PHP 8.4.24 NTS en `C:\WebServer\PHP84`, ejecutado con `mod_fcgid`.
- MariaDB 11.4.12 LTS en `C:\WebServer\MariaDB114`.
- Datos paralelos en `C:\WebServer\Data\MariaDB114`.
- Logs separados en `C:\WebServer\logs`.

Mientras se valida, Apache escucha únicamente `127.0.0.1:8484` y MariaDB
únicamente `127.0.0.1:3307`. El XAMPP activo conserva 80, 443 y 3306.

## Evidencia completada

- Respaldo de aplicación, configuración, historial Git y base de datos en
  `C:\WebMigration\backups\pre-migration-20260815-233408`.
- Volcado empresarial verificado con SHA-256 y marca final de `mysqldump`.
- 37 tablas/vistas, 219164 filas y una rutina coinciden entre origen y copia.
- Las 37 tablas pasan `mariadb-check --extended`.
- 161 archivos PHP pasan sintaxis y revisión de deprecaciones.
- Las 44 pruebas automatizadas pasan en PHP 8.4.24 NTS y MariaDB 11.4.12.
- Login, CSRF, sesión y rutas principales pasan mediante Apache/FastCGI.
- Los servicios nuevos sobreviven un reinicio controlado y XAMPP conserva sin
  cambios sus servicios y puertos originales.

## Servicios instalados

Los servicios paralelos ya quedaron registrados con inicio automático,
recuperación por reinicio y dependencia de Apache respecto de MariaDB:

- `XmlConciliaMariaDB114` en `127.0.0.1:3307`.
- `XmlConciliaApache84` en `127.0.0.1:8484`.

Para comprobar que ambos sobreviven un reinicio controlado, abrir PowerShell
mediante **Ejecutar como administrador** y ejecutar:

```powershell
Set-ExecutionPolicy -Scope Process Bypass -Force
& 'C:\xampp\htdocs\xmlconcilia\scripts\reiniciar-validar-servicios-migracion.ps1'
```

El script reinicia solamente los dos servicios nuevos, repite el inicio de
sesión y la navegación automatizada, y verifica que `mysql` y `Apache2.4` de
XAMPP conserven su estado.

## Acceso remoto durante la validación

La computadora remota `Auxiliar-06C` pertenece al mismo Tailscale que el
servidor. Para probar el stack nuevo sin abrir puertos del router, se publica
temporalmente por HTTPS en el puerto 8443:

```powershell
Set-ExecutionPolicy -Scope Process Bypass -Force
& 'C:\xampp\htdocs\xmlconcilia\scripts\configurar-acceso-remoto-migracion.ps1'
```

El script requiere PowerShell como administrador, no expone MariaDB y valida
la URL HTTPS con un inicio de sesión automatizado. Durante el corte definitivo
se sustituirá el puerto temporal 8443 por el HTTPS estándar 443.

## Corte definitivo

La aplicación validada se despliega en `C:\WebApps\xmlconcilia`. El corte final
cierra temporalmente las pantallas web, genera un respaldo nuevo, compara cada
tabla después de restaurarla en MariaDB 11.4 y solo entonces cambia los
servicios. Ejecutar en PowerShell como administrador:

```powershell
Set-ExecutionPolicy -Scope Process Bypass -Force
& 'C:\WebApps\xmlconcilia\scripts\ejecutar-corte-final.ps1' -Execute
```

Al terminar, el acceso es `https://fabian.tail96f46b.ts.net`, MariaDB 11.4
atiende únicamente en `127.0.0.1:3306` y los servicios antiguos `Apache2.4` y
`mysql` quedan detenidos y deshabilitados. No se desinstalan durante el periodo
de observación, de modo que existe una reversión local inmediata si la
validación final falla.

## Estado final — 16 de agosto de 2026

- Aplicación activa: `C:\WebApps\xmlconcilia`.
- Base activa: MariaDB 11.4.12 en `C:\WebServer\Data\MariaDB114` y puerto
  local 3306.
- Acceso: `https://fabian.tail96f46b.ts.net`, exclusivo del tailnet.
- Apache/PHP: Apache 2.4.68 y PHP 8.4.24 NTS mediante FastCGI.
- Datos comparados: 37 tablas y 219163 filas.
- Respaldo final: `C:\WebMigration\cutovers\cutover-20260816-071903\bd_xmlconcilia-final.sql`.
- SHA-256: `0AD19015BE30A5B5DC7A918D4A77BB6DB4FEF725DE8EF8AFC26A2316B84BDE98`.
- `Apache2.4` y `mysql` de XAMPP: detenidos y deshabilitados.
