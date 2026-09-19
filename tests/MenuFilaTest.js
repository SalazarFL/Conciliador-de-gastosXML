/**
 * Clic derecho sobre un renglón de listado.
 *
 * Lo que se prueba no es el dibujo del menú sino la regla que lo llena: qué
 * cuenta como acción de la fila, cómo se llama cada opción y a quién le llega
 * el clic cuando se elige una. Eso se puede probar con un DOM de juguete —el
 * mismo camino que la prueba de la selección por rango— y no hace falta traer
 * un navegador entero.
 *
 * Uso: node tests/MenuFilaTest.js
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

// ── Un DOM de juguete ───────────────────────────────────────────────────────
//
// Solo lo que el bloque toca: buscar hacia arriba y hacia abajo con selectores,
// leer atributos, y saber si algo está a la vista. Los selectores que hacen
// falta son los del propio bloque —'a[href], button', 'table.data-table tbody
// tr', '.cfg-item-acciones'—, así que el buscador entiende etiqueta, clase,
// atributo y descendencia, y nada más.

function compuesto(nodo, trozo) {
    const partes = trozo.match(/^([a-z]+)?((?:\.[\w-]+)*)((?:\[[^\]]+\])*)$/i);
    if (!partes) { return false; }
    const [, etiqueta, clases, atributos] = partes;
    if (etiqueta && nodo.etiqueta !== etiqueta) { return false; }
    const suyas = (clases || '').split('.').filter(Boolean);
    if (suyas.some((c) => !nodo.classList.contains(c))) { return false; }
    const pedidos = (atributos || '').match(/\[[^\]]+\]/g) || [];
    return pedidos.every((a) => {
        const par = a.slice(1, -1).split('=');
        const nombre = par[0];
        if (par.length === 1) { return nodo.getAttribute(nombre) !== null; }
        return nodo.getAttribute(nombre) === par[1].replace(/^["']|["']$/g, '');
    });
}

/** Un selector suelto, con descendencia: se lee de derecha a izquierda. */
function encaja(nodo, selector) {
    const trozos = selector.trim().split(/\s+/);
    if (!compuesto(nodo, trozos.pop())) { return false; }
    let arriba = nodo.padre;
    while (trozos.length) {
        const trozo = trozos.pop();
        while (arriba && !compuesto(arriba, trozo)) { arriba = arriba.padre; }
        if (!arriba) { return false; }
        arriba = arriba.padre;
    }
    return true;
}

class Nodo {
    constructor(etiqueta) {
        this.etiqueta = etiqueta;
        this.hijos = [];
        this.padre = null;
        this.atributos = {};
        this.dataset = {};
        this.style = {};
        this.oyentes = {};
        this.texto = '';
        this.aLaVista = true;
        this.className = '';
        const clases = new Set();
        this.classList = {
            add: (c) => clases.add(c),
            remove: (c) => clases.delete(c),
            contains: (c) => clases.has(c) || (this.className || '').split(' ').indexOf(c) >= 0,
        };
    }

    get textContent() {
        return this.texto + this.hijos.map((h) => h.textContent).join('');
    }
    set textContent(v) { this.texto = v; this.hijos = []; }

    get title() { return this.atributos.title; }
    set title(v) { this.atributos.title = v; }

    get parentNode() { return this.padre; }
    get lastElementChild() { return this.hijos[this.hijos.length - 1] || null; }
    get offsetParent() { return this.aLaVista ? this.padre : null; }
    get offsetWidth() { return 220; }
    get offsetHeight() { return 150; }

    getClientRects() { return this.aLaVista ? [{}] : []; }
    cloneNode() { return nodo(this.etiqueta, Object.assign({ class: this.className }, this.atributos), this.texto); }
    getBoundingClientRect() { return { left: 10, top: 40, right: 900, bottom: 70 }; }
    getAttribute(n) { return n in this.atributos ? String(this.atributos[n]) : null; }
    setAttribute(n, v) { this.atributos[n] = v; }
    hasAttribute(n) { return n in this.atributos; }

    appendChild(hijo) { hijo.padre = this; this.hijos.push(hijo); return hijo; }
    removeChild(hijo) {
        this.hijos = this.hijos.filter((h) => h !== hijo);
        hijo.padre = null;
    }

    matches(selector) { return selector.split(',').some((s) => encaja(this, s)); }
    closest(selector) {
        let n = this;
        while (n) {
            if (n.matches(selector)) { return n; }
            n = n.padre;
        }
        return null;
    }
    descendientes() {
        return this.hijos.reduce((t, h) => t.concat([h], h.descendientes()), []);
    }
    querySelectorAll(selector) {
        return this.descendientes().filter((n) => n.matches(selector));
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }

    focus() { documento.activeElement = this; }
    addEventListener(tipo, fn) { (this.oyentes[tipo] = this.oyentes[tipo] || []).push(fn); }
    disparar(tipo, evento) { (this.oyentes[tipo] || []).forEach((fn) => fn(evento)); }
    click() { pulsados.push(this.atributos.id || this.etiqueta); this.disparar('click', {}); }
}

/** Marcado a mano, corto: etiqueta, atributos, texto e hijos. */
function nodo(etiqueta, atributos, contenido) {
    const n = new Nodo(etiqueta);
    Object.keys(atributos || {}).forEach((k) => {
        if (k === 'class') { n.className = atributos[k]; }
        else { n.atributos[k] = atributos[k]; }
    });
    (Array.isArray(contenido) ? contenido : [contenido]).forEach((c) => {
        if (c === undefined || c === null) { return; }
        if (typeof c === 'string') { n.texto += c; }
        else { n.appendChild(c); }
    });
    return n;
}

let pulsados = [];
const documento = new Nodo('#document');
documento.body = documento.appendChild(new Nodo('body'));
documento.createElement = (etiqueta) => new Nodo(etiqueta);
documento.activeElement = null;
documento.oyentesDoc = {};
documento.addEventListener = function (tipo, fn) {
    (this.oyentesDoc[tipo] = this.oyentesDoc[tipo] || []).push(fn);
};

const ventana = { innerWidth: 1200, innerHeight: 800, addEventListener() {} };

// ── Se carga el bloque tal como lo carga el navegador ───────────────────────

const codigo = fs.readFileSync(
    path.join(__dirname, '..', 'public', 'assets', 'js', 'app.js'), 'utf8');

const desde = codigo.indexOf('CLIC DERECHO SOBRE UN RENGLÓN');
comprueba(desde !== -1, 'app.js sigue trayendo el bloque del clic derecho');
const CIERRE = '\n})();';
const cierre = codigo.indexOf(CIERRE, desde);
comprueba(cierre !== -1, 'el bloque del clic derecho cierra su función');
if (fallos) { process.exit(1); }
const bloque = codigo.slice(codigo.lastIndexOf('/*', desde), cierre + CIERRE.length);

const contexto = { document: documento, window: ventana, Math, Array, String, Object };
vm.createContext(contexto);
vm.runInContext(bloque, contexto);

function clicDerecho(destino, opciones) {
    const evento = Object.assign({
        target: destino,
        shiftKey: false,
        clientX: 300,
        clientY: 200,
        cancelado: false,
        preventDefault() { this.cancelado = true; },
    }, opciones || {});
    (documento.oyentesDoc.contextmenu || []).forEach((fn) => fn(evento));
    return evento;
}

function escape() {
    (documento.oyentesDoc.keydown || []).forEach((fn) => fn({
        key: 'Escape', preventDefault() {}, stopImmediatePropagation() {},
    }));
}

function menu() { return documento.body.hijos.find((h) => h.className === 'menu-fila') || null; }
function nombres() {
    return menu().querySelectorAll('.menu-fila-texto').map((t) => t.textContent);
}

// ── Un listado de verdad, con la forma que tiene en Seguimiento ─────────────

function listado() {
    const chipXml = nodo('a', { href: '/documentos/xml/7', title: 'Ver el XML', id: 'chip-xml' },
        [nodo('i', { class: 'fas fa-code' }), ' XML']);
    const chipPdf = nodo('span', { title: 'Falta el PDF' }, 'PDF');   // no se pulsa: no es acción
    const proveedor = nodo('a', { href: '/proveedores/9', id: 'link-proveedor' }, 'Distribuidora del Valle');
    const correo = nodo('a', {
        href: '/correo?buscar=1', id: 'btn-correo',
        title: 'Buscarlo en el correo. La nota no trae número propio: se busca por proveedor',
    }, nodo('i', { class: 'fas fa-envelope-open-text' }));
    const expediente = nodo('button', { id: 'btn-expediente', title: 'Ver expediente' });
    const repetido = nodo('button', { id: 'btn-repetido', title: 'Ver expediente' });
    const escondido = nodo('button', { id: 'btn-escondido', title: 'Solo con selección' });
    escondido.aLaVista = false;
    const inhabil = nodo('button', { id: 'btn-inhabil', title: 'Nada que hacer' });
    inhabil.disabled = true;

    const fila = nodo('tr', {}, [
        nodo('td', { class: 'center' }, nodo('input', { type: 'checkbox' })),
        nodo('td', {}, '00123456'),
        nodo('td', {}, proveedor),
        nodo('td', { class: 'right' }, '1 250 000,00'),
        nodo('td', { class: 'center' }, nodo('div', { class: 'seg-respaldo' }, [chipXml, chipPdf])),
        nodo('td', {}, [correo, expediente, repetido, escondido, inhabil]),
    ]);
    const vacia = nodo('tr', {}, [nodo('td', {}, 'Sin nada que hacer')]);
    const tabla = nodo('table', { class: 'data-table seg-tabla' },
        nodo('tbody', {}, [fila, vacia]));
    documento.body.appendChild(tabla);
    return { fila, vacia, correo, expediente, chipXml, proveedor };
}

const l = listado();

// El menú es un espejo de la fila: lo que se puede pulsar y nada más.
{
    pulsados = [];
    const evento = clicDerecho(l.fila.hijos[1]);
    comprueba(evento.cancelado, 'sobre una fila con acciones no sale el menú del navegador');
    comprueba(menu() !== null, 'sale el menú de la fila');
    comprueba(String(nombres()) === 'Ver el XML,Buscarlo en el correo,Ver expediente',
        'lista las acciones de la fila, y solo esas: ' + nombres().join(' · '));
}

// El título largo se parte: la orden queda de nombre y el porqué, de globo.
{
    const opcion = menu().querySelectorAll('.menu-fila-opcion')[1];
    comprueba(opcion.title.indexOf('no trae número propio') > 0,
        'la explicación entera se queda en el globo de la opción');
}

// De qué renglón habla el menú.
{
    comprueba(menu().querySelector('.menu-fila-titulo').textContent === '00123456',
        'el menú lleva escrito de qué documento es');
    comprueba(l.fila.classList.contains('is-menu-abierto'),
        'la fila queda marcada mientras el menú está encima');
}

// Elegir una opción es pulsar el botón de la fila: el mismo, no una copia.
{
    pulsados = [];
    menu().querySelectorAll('.menu-fila-opcion')[2].disparar('click', {});
    comprueba(String(pulsados) === 'btn-expediente',
        'el clic le llega al botón de verdad, y solo a él: ' + pulsados.join(','));
    comprueba(menu() === null, 'al elegir, el menú se va');
    comprueba(!l.fila.classList.contains('is-menu-abierto'), 'y la fila se despinta');
}

// Un renglón sin acciones no tiene menú que enseñar: se deja el del navegador,
// por si lo que se quería era copiar lo que dice.
{
    const evento = clicDerecho(l.vacia.hijos[0]);
    comprueba(!evento.cancelado && menu() === null,
        'una fila sin acciones deja pasar el menú del navegador');
}

// Las dos salidas al menú del navegador.
{
    let evento = clicDerecho(l.fila.hijos[1], { shiftKey: true });
    comprueba(!evento.cancelado && menu() === null, 'Shift + clic derecho deja el menú del navegador');

    evento = clicDerecho(l.fila.querySelector('input'));
    comprueba(!evento.cancelado && menu() === null, 'dentro de un campo manda el menú del navegador');
}

// Escape cierra.
{
    clicDerecho(l.fila.hijos[1]);
    comprueba(menu() !== null, 'el menú vuelve a salir');
    escape();
    comprueba(menu() === null, 'Escape cierra el menú');
    comprueba(!l.fila.classList.contains('is-menu-abierto'), 'y despinta la fila');
}

// Contra el borde de la pantalla el menú se abre para el otro lado: en la
// última fila de un listado largo, que es donde más se usa, esto es la
// diferencia entre verlo y no verlo.
{
    clicDerecho(l.fila.hijos[1], { clientX: 1190, clientY: 780 });
    const m = menu();
    comprueba(parseInt(m.style.left, 10) + m.offsetWidth <= ventana.innerWidth,
        'pegado al borde derecho, el menú se abre hacia la izquierda');
    comprueba(parseInt(m.style.top, 10) + m.offsetHeight <= ventana.innerHeight,
        'pegado al borde de abajo, el menú se abre hacia arriba');
    escape();
}

// ── Las tarjetas de Configuración son un listado con otra forma ─────────────

{
    const tarjeta = nodo('div', { class: 'cfg-item' }, [
        nodo('div', { class: 'cfg-item-cuerpo' },
            nodo('div', { class: 'cfg-item-titulo' }, 'Buzón de compras')),
        nodo('div', { class: 'cfg-item-acciones' }, [
            nodo('a', { href: '/usuarios/editar/3', id: 'cfg-editar', title: 'Editar' },
                nodo('i', { class: 'fas fa-pen' })),
            nodo('button', { id: 'cfg-borrar', title: 'Eliminar' }),
            // Un icono suelto y sin título no tiene nombre que enseñar: una
            // opción en blanco no se puede elegir. Por eso todo botón de
            // acción lleva título, que es además su globo de ayuda.
            nodo('button', { id: 'cfg-sin-nombre' }, nodo('i', { class: 'fas fa-ghost' })),
        ]),
    ]);
    documento.body.appendChild(tarjeta);

    clicDerecho(tarjeta.querySelector('.cfg-item-titulo'));
    comprueba(menu() !== null, 'una tarjeta de Configuración también tiene menú');
    comprueba(String(nombres()) === 'Editar,Eliminar',
        'con sus acciones, y sin la que no tiene nombre: ' + nombres().join(' · '));
    escape();
}

if (fallos) {
    console.error('\n' + fallos + ' comprobaciones fallaron');
    process.exit(1);
}
console.log('MenuFilaTest: todo bien');
