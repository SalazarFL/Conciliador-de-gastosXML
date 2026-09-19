/**
 * El token de la sesión viaja solo en lo que escribe a la propia aplicación.
 *
 * Lo que se prueba es la regla del bloque de app.js que se lo pone a cada
 * fetch, XMLHttpRequest y formulario: a qué peticiones sí (las que escriben y
 * van al mismo origen), a cuáles no (las lecturas y todo lo que va a otro
 * sitio, que nunca debe ver el token) y que no pisa lo que la pantalla ya
 * mandaba. Con un navegador de juguete, igual que MenuFilaTest.js.
 *
 * Uso: node tests/CsrfTokenTest.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let fallos = 0;
function comprueba(condicion, mensaje) {
    if (!condicion) {
        console.error('FAIL: ' + mensaje);
        fallos++;
    }
}

const TOKEN = 'a'.repeat(64);

// ── Un navegador de juguete ─────────────────────────────────────────────────

const enviados = [];
function fetchFalso(recurso, opciones) {
    enviados.push({ recurso, opciones });
    return Promise.resolve({ ok: true });
}

class XhrFalso {
    constructor() { this.cabeceras = {}; }
    open(metodo, url) { this.metodo = metodo; this.url = url; }
    setRequestHeader(nombre, valor) { this.cabeceras[nombre] = valor; }
    send() { this.enviado = true; }
}

class FormFalso {
    constructor(atributos) {
        this.atributos = atributos;
        this.method = (atributos.method || 'get').toLowerCase();
        this.hijos = [];
        this.enviadoPorCodigo = false;
    }
    getAttribute(nombre) { return nombre in this.atributos ? this.atributos[nombre] : null; }
    querySelector(selector) {
        const nombre = (selector.match(/name="([^"]+)"/) || [])[1];
        return this.hijos.find((h) => h.name === nombre) || null;
    }
    appendChild(hijo) { this.hijos.push(hijo); }
    submit() { this.enviadoPorCodigo = true; }
}

const oyentes = {};
const documento = {
    querySelector: (s) => (s === 'meta[name="csrf-token"]' ? { getAttribute: () => TOKEN } : null),
    createElement: () => ({ type: '', name: '', value: '' }),
    addEventListener: (tipo, fn) => { oyentes[tipo] = fn; },
};

const ventana = {
    location: { href: 'http://localhost/seguimiento?modo=sistema', origin: 'http://localhost' },
    fetch: fetchFalso,
    XMLHttpRequest: XhrFalso,
    HTMLFormElement: FormFalso,
};

const contexto = {
    window: ventana,
    document: documento,
    XMLHttpRequest: XhrFalso,
    HTMLFormElement: FormFalso,
    Headers, Request, URL,
    Object, String, Promise,
};
ventana.window = ventana;

// ── Se carga el bloque tal como lo carga el navegador ───────────────────────

const codigo = fs.readFileSync(path.join(__dirname, '..', 'public', 'assets', 'js', 'app.js'), 'utf8');
const INICIO = '/* El token de la sesión en cada escritura';
const desde = codigo.indexOf(INICIO);
comprueba(desde !== -1, 'app.js trae el bloque del token');
const CIERRE = '\n})();';
const cierre = codigo.indexOf(CIERRE, desde);
comprueba(cierre !== -1, 'el bloque del token cierra su función');
vm.createContext(contexto);
vm.runInContext(codigo.slice(desde, cierre + CIERRE.length), contexto);

function tokenDe(envio) {
    const h = envio.opciones && envio.opciones.headers;
    if (!h) return null;
    return typeof h.get === 'function' ? h.get('X-CSRF-Token') : (h['X-CSRF-Token'] || null);
}

// ── fetch ───────────────────────────────────────────────────────────────────

ventana.fetch('/seguimiento/actualizar', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
    body: 'items[]=factura|7',
});
comprueba(tokenDe(enviados[0]) === TOKEN, 'un POST relativo lleva el token');
comprueba(enviados[0].opciones.headers.get('Content-Type') === 'application/x-www-form-urlencoded; charset=UTF-8',
    'y conserva las cabeceras que ya traía');
comprueba(enviados[0].opciones.body === 'items[]=factura|7', 'y su cuerpo');

ventana.fetch('/avisos/resumen', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
comprueba(tokenDe(enviados[1]) === null, 'un GET no lo lleva');

ventana.fetch('https://cdnjs.cloudflare.com/algo', { method: 'POST', body: 'x' });
comprueba(tokenDe(enviados[2]) === null, 'un POST a otro sitio nunca lo lleva');

ventana.fetch('http://localhost/pagos/borrar/3', { method: 'delete' });
comprueba(tokenDe(enviados[3]) === TOKEN, 'un DELETE absoluto al mismo origen, en minúsculas, sí');

ventana.fetch('/x', { method: 'POST', headers: { 'X-CSRF-Token': 'propio' } });
comprueba(tokenDe(enviados[4]) === 'propio', 'no pisa un token que la pantalla ya puso');

ventana.fetch(new Request('http://localhost/facturas/subir', { method: 'POST', headers: { 'X-Uno': '1' } }));
comprueba(tokenDe(enviados[5]) === TOKEN, 'un Request armado a mano también');
comprueba(enviados[5].opciones.headers.get('X-Uno') === '1', 'con las cabeceras del Request');

// ── XMLHttpRequest ──────────────────────────────────────────────────────────

const xhrPost = new contexto.XMLHttpRequest();
xhrPost.open('POST', '/correo/procesar');
xhrPost.send('a=1');
comprueba(xhrPost.cabeceras['X-CSRF-Token'] === TOKEN, 'un XHR que escribe lo lleva');

const xhrAjeno = new contexto.XMLHttpRequest();
xhrAjeno.open('POST', 'https://otro.example/api');
xhrAjeno.send();
comprueba(!('X-CSRF-Token' in xhrAjeno.cabeceras), 'un XHR a otro sitio no');

const xhrGet = new contexto.XMLHttpRequest();
xhrGet.open('GET', '/seguimiento/detalle');
xhrGet.send();
comprueba(!('X-CSRF-Token' in xhrGet.cabeceras), 'un XHR de lectura no');

// ── Formularios ─────────────────────────────────────────────────────────────

const formPost = new FormFalso({ method: 'post', action: '/usuarios/guardar' });
oyentes.submit({ target: formPost });
comprueba(formPost.hijos.length === 1 && formPost.hijos[0].name === 'csrf_token' && formPost.hijos[0].value === TOKEN,
    'un formulario POST enviado con el botón recibe el campo oculto');
oyentes.submit({ target: formPost });
comprueba(formPost.hijos.length === 1, 'enviarlo dos veces no duplica el campo');

const formGet = new FormFalso({ action: '/seguimiento' });
oyentes.submit({ target: formGet });
comprueba(formGet.hijos.length === 0, 'la barra de filtros (GET) no lleva token en la URL');

const formAjeno = new FormFalso({ method: 'POST', action: 'https://otro.example/login' });
oyentes.submit({ target: formAjeno });
comprueba(formAjeno.hijos.length === 0, 'un formulario a otro sitio no');

const formCodigo = new FormFalso({ method: 'post' });
formCodigo.submit();
comprueba(formCodigo.enviadoPorCodigo && formCodigo.hijos.length === 1 && formCodigo.hijos[0].value === TOKEN,
    'form.submit() desde código también, y se envía igual');

if (fallos > 0) {
    process.exit(1);
}
console.log('OK: el token viaja solo en lo que escribe a la propia aplicación');
