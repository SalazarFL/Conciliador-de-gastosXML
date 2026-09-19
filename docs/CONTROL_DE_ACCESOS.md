# Control de accesos y bitácora

Quién entra, qué puede hacer, y qué hizo.

Todo se administra desde **Configuración**, en tres secciones: *Usuarios*,
*Roles y permisos* y *Actividad*. La bitácora tiene además pantalla propia en
`/bitacora`, porque quien audita entra ahí varias veces al día.

---

## Las tres piezas

| Pieza | Dónde vive | Qué decide |
|---|---|---|
| **Catálogo de permisos** | `app/config/permisos.php` (código) | Qué acciones existen y qué permiso exige cada ruta |
| **Roles** | tablas `roles` + `rol_permisos` | Qué permisos tiene cada rol |
| **Usuarios** | `usuarios.rol_id` + `usuario_sociedades` | Qué rol tiene cada persona y a qué empresas llega |

El catálogo está en código y no en la base **a propósito**: un permiso solo
significa algo si hay una ruta que lo exige. Si la lista viviera en la base, al
añadir una pantalla habría que acordarse de insertar su permiso a mano, y al
quitar una quedarían permisos fantasma que se siguen ofreciendo y no protegen
nada. Lo que sí vive en la base es el reparto, que es lo que cambia a diario.

---

## Dónde se comprueba

En `Router::dispatch`, antes de instanciar ningún controlador:

```
petición  →  Router encuentra la ruta
          →  Permisos::guardia(método, ruta registrada)   ← corta aquí si no
          →  Bitacora::vigilar(...)                       ← programa el registro
          →  el controlador
```

Es el único sitio por el que pasa todo. Por eso:

* Una pantalla nueva que se olvide de llamar a `requireAlgo()` **no** queda
  desprotegida.
* Escribir la dirección a mano choca con la misma puerta que pulsar el botón.
* Se comprueba contra la ruta **registrada** (`/facturas/ver/{id}`), no contra
  la pedida, así que el permiso no depende del id que traiga.

Esconder entradas del menú es cortesía, no seguridad: sirve para que el sistema
se entienda, nunca para protegerlo.

Dentro de una acción, cuando la ruta sola no distingue (un mismo POST que según
lo que traiga borra o solo edita), está `$this->requirePermiso('...')`; y
`$this->puede('...')` para decidir si se dibuja un botón.

### Si la base no contesta

Una instalación recién actualizada todavía no tiene las tablas, y un servidor
caído no tiene ninguna. En ese caso **no se cierra el sistema**: se vuelve al
reparto de antes —administrador todo, el resto todo menos administración—, que
es como se comportaba `requireAdmin()`. Queda anotado en
`storage/logs/app.log` con el prefijo `PERMISOS:`.

---

## Cómo añadir una pantalla nueva

1. Registra su ruta en `app/config/routes.php`, como siempre.
2. Añádela en `app/config/permisos.php`, sección `rutas`:

```php
'POST /mi-modulo/guardar' => [
    'permiso'   => 'mi_modulo.editar',
    'registrar' => 'Guardó algo en Mi módulo',   // solo si CAMBIA datos
],
```

3. Si el permiso es nuevo, decláralo en `modulos` para que aparezca entre las
   casillas del rol.

`tests/PermisosCatalogoTest.php` compara las rutas realmente registradas contra
las declaradas y **falla en las dos direcciones**: una ruta sin declarar queda
abierta a cualquiera que haya entrado, y un permiso declarado para una ruta que
ya no existe se sigue ofreciendo como si protegiera algo.

Los tres valores especiales de `permiso`:

* `'publica'` — sin sesión (solo el login).
* `'libre'` — cualquiera que haya entrado, sin permiso concreto.
* una **lista** — con cualquiera de ellos basta. Lo usa `/configuracion`, que
  hospeda secciones de varios dueños.

---

## Los roles

Cada usuario tiene **un** rol. Uno solo, a propósito: con varios sumándose
nadie puede mirar una ficha y decir de un vistazo qué puede hacer esa persona,
que es justo lo que un control de accesos tiene que responder. Si hace falta
una excepción, se crea el rol que la describe y se le pone nombre.

Dos marcas:

* `es_admin` — lo puede todo, también los permisos que se inventen mañana. No
  se le marcan permisos porque los tiene todos por definición, y poder
  desmarcarle uno sería poder cerrar la instalación a medias.
* `es_sistema` — no se puede eliminar. Lo llevan **Administrador** y
  **Operación**: sin ellos una instalación se queda sin ningún rol al que
  volver.

Un cambio en un rol surte efecto en la **siguiente pantalla** de quien lo
tenga, no en su siguiente sesión: los permisos se resuelven de la base en cada
petición y no se guardan en la sesión. Una sesión de tres días arrastrando
permisos ya revocados es justo lo que este control viene a evitar.

### Al instalarse

Se siembra solo la primera vez que alguien entra (`Rol::sembrarRolesBase`):

* Quien tuviera `is_admin = 1` → **Administrador**.
* Todos los demás → **Operación**: todo el trabajo del día, sin
  administración, sin configuración del correo y sin los ajustes de la
  instalación.

Nadie se queda fuera el día que se sube, que es la única forma de que una
función de permisos no acabe desactivada a las dos horas.

`usuarios.is_admin` sigue existiendo junto al rol y no es una repetición: es el
salvavidas. Sostiene al primer administrador antes de que haya roles asignados
y evita que un error editando roles deje la instalación sin nadie que pueda
entrar a arreglarla. Ya no se marca a mano: lo determina el rol elegido.

---

## El alcance por empresa

`usuario_sociedades` limita a una persona a ciertas empresas. **Sin filas para
un usuario = todas**, no ninguna: si significara ninguna, el día que esto se
instala todo el mundo se quedaría sin ver nada hasta que un administrador
fuera usuario por usuario.

Se aplica en tres sitios:

* `Sociedad::getAll()` filtra lo que se ofrece (selector de Inicio, barra
  superior, Configuración).
* `SociedadesController::activar()` rechaza un POST hacia una empresa no
  asignada — esconder los botones no serviría contra un POST escrito a mano.
* Al entrar, si la empresa recordada ya no está entre las suyas, se corrige.

Dar de alta empresas es `empresas.administrar`; **elegir** con cuál se trabaja
lo hace cualquiera, entre las suyas.

---

## La bitácora

### Cómo se llena, sin tener que acordarse

No hay que llamar a nada desde los controladores. El enrutador avisa
(`Bitacora::vigilar`) antes de ejecutar la ruta, y el renglón se escribe **al
terminar la petición**, con el resultado ya conocido. Eso es a propósito: casi
todos los controladores de esta aplicación terminan en un `redirect()` o un
`json()` que hacen `exit`, así que cualquier código escrito "después de la
acción" no llegaría a correr nunca. Un `register_shutdown_function` sí.

Qué se registra lo decide `permisos.php`: solo las rutas con `registrar`. Las
que únicamente leen —y aquí hay muchos POST que solo leen— no dejan rastro,
porque una bitácora donde el 95% de los renglones son "abrió la bandeja" no la
mira nadie.

Cuando hace falta contar algo que la ruta no dice —qué usuario se editó, qué
cambió—, el controlador llama a `Bitacora::registrar()` con `antes` y
`despues`; se guarda **solo lo que cambió**, y ese renglón sustituye al
automático para que un mismo acto no aparezca dos veces.

También se registran: entrar, salir, los **intentos fallidos de entrada** y los
**intentos de entrar sin permiso** (saber que alguien llegó a una puerta
cerrada es la mitad de para qué sirve un registro de accesos).

### Lo que nunca entra

Contraseñas, confirmaciones de contraseña y tokens CSRF se descartan antes de
escribir, por cualquier camino. Guardarlos en una tabla que un administrador
consulta a diario es exactamente el descuido que este módulo existe para
evitar. Los valores enormes se recortan: no dicen nada al leerlos y engordan la
tabla hasta hacerla inservible.

### El historial anterior

Antes de esto ya había rastro de usuario repartido por media docena de tablas.
Nada se copia ni se migra: se **lee en su sitio** y se mezcla en la misma línea
de tiempo (casilla *Incluir el historial anterior*). Las fuentes están
declaradas en `Bitacora::fuentes()`; si una tabla no existe en esta
instalación, simplemente no participa.

| Fuente | De dónde sale |
|---|---|
| `seguimiento_bitacora` | cambios de estado en Seguimiento |
| `facturas_erp_cargas` | cargas del reporte del ERP |
| `facturas_erp_descartes` | incidencias del ERP descartadas |
| `notas_credito_cargas` | listados de notas de crédito subidos |
| `correo_incidencias_descartes` | incidencias del correo descartadas |
| `notificaciones` | avisos cerrados |
| `proveedor_alias` | alias de proveedor aprendidos |

Por omisión la pantalla muestra los **últimos 30 días**: la consulta unida
puede tocar ocho tablas, y sin acotar por fecha leería todo lo que la empresa
lleva hecho desde que existe para pintar cincuenta renglones.

### Que no crezca sin control

*Depurar* borra lo anterior a un plazo. Solo toca la tabla `bitacora`: el
historial de los módulos es dato de trabajo, no registro de auditoría, y
borrarlo desde aquí dejaría a Seguimiento sin sus anotaciones.

---

## Instalación

Las tablas se crean solas la primera vez que alguien entra (`Rol::ensureTables`
y `Bitacora::ensureTable`, una sola vez por instalación gracias a `Esquema`).

En servidores donde el usuario de la web **no tiene permisos de DDL**, hay que
correr una vez:

```
mysql -h SERVIDOR -P 3307 -u root -p bd_xmlconcilia < database/migrations/005_roles_y_bitacora.sql
```

Es idempotente.

---

## Pruebas

```
php tests/PermisosCatalogoTest.php    # no necesita base de datos
php tests/RolesYBitacoraTest.php      # se salta sola si no hay base
```
