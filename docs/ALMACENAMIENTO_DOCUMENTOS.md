# Dónde viven los documentos: las dos opciones

> **Decidido el 27/09/2026: opción B, y solo B.** SharePoint pasó a ser
> obligatorio y la carpeta compartida dejó de usarse; ya no hay empresas que
> archiven en disco. Además, Nexo Fiscal puede vivir en **una carpeta de una
> biblioteca que la empresa ya usa**, con permiso solo sobre esa carpeta
> (`Files.SelectedOperations.Selected`), en vez de en una biblioteca propia.
> Lo de abajo queda como el análisis que llevó ahí. Cómo se opera hoy:
> [SHAREPOINT.md](SHAREPOINT.md).

Este documento existe para que **la decisión sea del cliente y no nuestra**. Nexo
Fiscal soporta las dos formas de guardar los XML y los PDF, y cuál conviene
depende de cómo trabaja cada empresa, no de una limitación del sistema.

Lo que no cambia en ninguna de las dos:

> **Desde Nexo Fiscal, los archivos siempre se abren igual.** El usuario pulsa
> el documento y lo ve. No se entera de dónde está guardado, y nunca tiene que
> ir a buscarlo a otro lado.

Eso es lo que se vende. Lo de abajo es lo que se elige.

---

## Opción A · Todo en el servidor

Los documentos viven en el disco del servidor de la empresa. SharePoint se usa
como respaldo y como vitrina: una tarea sube copias para quien quiera verlas
desde ahí.

```
   Usuarios                    Servidor de la empresa
   (navegador)      ───────►   ├── base de datos
                               └── documentos  ──── copia ───►  SharePoint
                                                                (respaldo)
```

**A favor**

- Lo más rápido: leer del disco propio no tiene comparación con pedir por red.
- No depende de internet. Si se cae la conexión, el sistema sigue trabajando.
- No hace falta pedirle nada al administrador de Microsoft 365.
- Es lo más simple de montar y de sostener.

**En contra**

- SharePoint deja de ser el original y pasa a ser una copia, que puede ir un
  rato atrasada.
- Si alguien edita o mueve un archivo desde SharePoint, el sistema no se entera.

**Conviene cuando** los documentos, en la práctica, solo se consultan desde
Nexo Fiscal y quien los administra es el sistema.

---

## Opción B · SharePoint como original, por Microsoft Graph

Los documentos viven en la biblioteca de SharePoint de la empresa. El servidor
no los busca en una carpeta: se los pide a SharePoint por su API oficial
(Microsoft Graph).

```
   Usuarios                    Servidor de la empresa
   (navegador)      ───────►   └── base de datos
                                        │
                                        │  Microsoft Graph (HTTPS)
                                        ▼
                               SharePoint  ◄──── también entran las personas
                               (el original)     desde el navegador o Teams
```

**A favor**

- SharePoint es el original y está siempre al día. Lo que ve el sistema y lo
  que ve una persona es lo mismo.
- La gente sigue entrando a la biblioteca como siempre: navegador, Teams,
  Explorador. No cambia nada para ellos.
- Los permisos de los archivos siguen siendo los de SharePoint. La empresa
  mantiene su control de accesos y su auditoría.
- Funciona sin sesión de usuario abierta, que es lo que un servidor necesita.

**En contra**

- Abrir un documento es un viaje por red —medido: de medio segundo a un
  segundo por llamada— en vez de una lectura de disco. Los listados no lo
  pagan: dicen si un documento está sin preguntarle a SharePoint.
- Si se cae internet, no hay documentos.
- Requiere que el administrador de Microsoft 365 autorice la aplicación.
- Microsoft limita el ritmo de pedidos; hay que manejar reintentos.

**Conviene cuando** hay gente que trabaja con esos archivos por fuera del
sistema, o cuando la empresa exige que SharePoint sea el repositorio oficial.

---

## Lo que hay que pedirle al administrador (solo opción B)

Es un trámite de una sola vez, y lo tiene que hacer alguien con permisos de
administrador del tenant.

1. **Registrar una aplicación** en Microsoft Entra ID (antes Azure AD). Devuelve
   el identificador de la aplicación y el de la organización.
2. **Un secreto o un certificado** para esa aplicación.
3. **El permiso `Sites.Selected`**, de tipo *aplicación* (no *delegado*).
4. **Consentimiento del administrador**, que es un botón que solo él puede
   pulsar.
5. **Autorizar la aplicación sobre la biblioteca concreta**, que con
   `Sites.Selected` es un paso aparte.

### Por qué `Sites.Selected` y no otro

Existe `Sites.ReadWrite.All`, que le da a la aplicación acceso a **todos** los
sitios de SharePoint de la empresa. Un administrador con criterio lo va a negar,
y hace bien.

`Sites.Selected` es lo contrario: la aplicación no puede tocar nada hasta que el
administrador la habilite biblioteca por biblioteca. Pedir ese permiso, acotado
a la biblioteca de cuentas por pagar, es un pedido que se defiende solo y que
suele aprobarse sin discusión.

### Cómo se ve en funcionamiento

```
1. El servidor pide un permiso temporal (dura 1 hora, se renueva solo)

   POST https://login.microsoftonline.com/{organización}/oauth2/v2.0/token
        client_id + client_secret + grant_type=client_credentials

2. Con ese permiso, pide el archivo

   GET https://graph.microsoft.com/v1.0/drives/{biblioteca}/root:/2026/08/FE_00108309.xml:/content
       Authorization: Bearer {token}
```

Sin persona, sin login interactivo, sin doble factor. Que es exactamente lo que
hoy no se puede hacer con una carpeta sincronizada.

---

## Lo que NO es una opción: la carpeta sincronizada

Hoy los documentos se leen desde una carpeta de OneDrive que sincroniza una
biblioteca de SharePoint (`CSC CxP - Documentos`). **Esto funciona en una
computadora de trabajo y no funciona en un servidor**, así que no se ofrece como
tercera opción: es lo que las dos opciones de arriba vienen a reemplazar.

El motivo, medido sobre la instalación actual:

```
38.847 archivos · 3,15 GB en la biblioteca
       249 están solo en la nube  ← estos no se pueden abrir
    38.598 están en el disco
```

De los archivos que guarda solo en la nube, OneDrive deja en el disco un
marcador que parece el archivo: existe, pesa lo que pesa el original y hasta
dice que se puede leer. El contenido solo llega si Windows lo baja al abrirlo, y
esa descarga **se le niega a un programa que corre como servicio** — Apache no es
una persona con sesión iniciada.

Hoy es el 0,6 % de los archivos y parece un detalle. Con 17 sociedades son unos
18 GB de documentos, y OneDrive libera espacio dejando en la nube lo que menos se
usa — que es justamente lo viejo, o sea lo que uno busca cuando aparece un
reclamo de hace ocho meses. Además el cliente de sincronización sufre bastante
antes de las cientos de miles de archivos a las que se llegaría, y necesita una
sesión de usuario abierta.

El sistema ya detecta este caso y lo explica con palabras en vez de entregar un
PDF vacío (ver `RutaDocumento::abrirParaLeer`), pero detectarlo no lo vuelve
legible.

---

## Cómo presentarlo

La conversación con el cliente no es técnica. Son dos preguntas:

1. **¿Alguien necesita trabajar con estos archivos por fuera del sistema?**
   Abrirlos, moverlos o compartirlos desde SharePoint.
   → Si la respuesta es sí, opción B.

2. **¿La empresa exige que SharePoint sea el repositorio oficial?**
   → Si la respuesta es sí, opción B.

Si las dos son no, la opción A hace lo mismo, más rápido y con menos piezas.

En cualquiera de los dos casos, la promesa al usuario final es idéntica: **los
documentos se abren desde el sistema, siempre.**

---

## Cómo quedó

Todo acceso a un documento pasa por una sola puerta (`AlmacenDocumentos`) con
dos implementaciones, disco y Graph. Hasta el 27/09/2026 la elección era por
empresa; desde entonces **todo lo nuevo va a SharePoint** y el disco queda
solo para leer lo que se archivó antes en la carpeta compartida, hasta
migrarlo.

Migrar lo de una empresa es un comando que sube lo existente y lo verifica;
se puede cortar, retomar y revertir sin perder nada, porque nunca borra el
disco.

Cómo se prepara —con biblioteca propia o con una carpeta—, se migra lo viejo y
se vigila: **[SHAREPOINT.md](SHAREPOINT.md)**.

Las dos advertencias que se escribieron antes de hacerlo, y se respetaron:

- **La base de datos nunca va en SharePoint.** MariaDB mantiene sus archivos
  abiertos escribiendo a mitad de archivo con bloqueos por rango de bytes; un
  cliente de sincronización no entiende nada de eso y termina en copias en
  conflicto y corrupción. La base vive en el disco del servidor.
- **El proceso que reconcilia el archivo se rediseñó, no se tradujo.** En disco
  sigue recorriendo el árbol; en SharePoint sigue a cada documento por su id
  permanente y por los cambios que la biblioteca informa, sin bajar nada.

---

*Los números de este documento se midieron el 31/08/2026 sobre la instalación
local con los tres buzones reales cargados. La capacidad del módulo de correo a
17 sociedades se analiza aparte.*
