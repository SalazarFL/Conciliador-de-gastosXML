/**
 * Shift + clic sobre una lista de casillas.
 *
 * Se prueba con un DOM mínimo hecho a mano —el navegador de verdad no hace
 * falta para esto y traer uno costaría más que la función— cuidando de imitar
 * lo único que aquí importa de su comportamiento: cuando llega el evento
 * 'click', la casilla YA tiene su nuevo estado. Toda la regla del rango
 * depende de eso.
 *
 * Uso: node tests/SeleccionRangoTest.js
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

// ── Un DOM de juguete: casillas, eventos y poco más ─────────────────────────

class Nodo {
    constructor(clase) {
        this.className = clase || '';
        this.checked = false;
        this.padre = null;
        this.enfocado = false;
        this.oyentes = {};
    }
    closest(selector) {
        const clase = selector.replace(/^\./, '');
        let n = this;
        while (n) {
            if (n.className === clase) { return n; }
            n = n.padre;
        }
        return null;
    }
    focus() { this.enfocado = true; }
    addEventListener(tipo, fn) {
        (this.oyentes[tipo] = this.oyentes[tipo] || []).push(fn);
    }
    disparar(tipo, evento) {
        (this.oyentes[tipo] || []).forEach((fn) => fn(evento));
    }
}

class Contenedor extends Nodo {
    constructor(cuantas) {
        super('tabla');
        this.hijos = [];
        for (let i = 0; i < cuantas; i++) {
            const casilla = new Nodo('chk-fila');
            casilla.padre = this;
            this.hijos.push(casilla);
        }
    }
    querySelectorAll() { return this.hijos; }

    /** Un clic de verdad: el navegador cambia el estado y DESPUÉS avisa. */
    clic(i, opciones) {
        const casilla = this.hijos[i];
        const evento = {
            target: casilla,
            shiftKey: !!(opciones && opciones.shift),
            preventDefault() { this.cancelado = true; },
            cancelado: false,
        };
        this.disparar('mousedown', evento);
        casilla.checked = !casilla.checked;
        this.disparar('click', evento);
        return evento;
    }

    marcadas() {
        return this.hijos.reduce((s, c, i) => (c.checked ? s.concat(i) : s), []);
    }
}

// ── Se carga el helper tal como lo carga el navegador ───────────────────────

const codigo = fs.readFileSync(
    path.join(__dirname, '..', 'public', 'assets', 'js', 'app.js'), 'utf8');

// app.js entero toca document, localStorage y demás al cargarse. Solo interesa
// el bloque de la selección por rango, que se aísla por su marca de sección.
//
// Se recorta por los dos lados. Antes se cortaba solo por delante y se llevaba
// TODO lo que viniera después: el día que alguien añadió otro bloque al final
// del archivo, esta prueba empezó a fallar por código que no tenía nada que
// ver con la selección por rango.
const desde = codigo.indexOf('SELECCIÓN POR RANGO CON SHIFT');
comprueba(desde !== -1, 'app.js sigue trayendo el bloque de selección por rango');
const CIERRE = '\n})();';
const cierre = codigo.indexOf(CIERRE, desde);
comprueba(cierre !== -1, 'el bloque de selección por rango cierra su función');
if (fallos) { process.exit(1); }
const bloque = codigo.slice(codigo.lastIndexOf('/*', desde), cierre + CIERRE.length);

const contexto = { window: {}, Math };
vm.createContext(contexto);
vm.runInContext(bloque, contexto);

const AppSeleccionRango = contexto.window.AppSeleccionRango;
comprueba(typeof AppSeleccionRango === 'function',
    'app.js expone window.AppSeleccionRango');
if (fallos) { process.exit(1); }

// ── Las reglas ──────────────────────────────────────────────────────────────

function nueva(cuantas) {
    const tabla = new Contenedor(cuantas);
    let avisos = 0;
    const rango = AppSeleccionRango(tabla, '.chk-fila', () => { avisos++; });
    return { tabla, rango, avisos: () => avisos };
}

// Sin Shift, un clic es un clic: no arrastra a nadie.
{
    const { tabla } = nueva(10);
    tabla.clic(2);
    tabla.clic(6);
    comprueba(String(tabla.marcadas()) === '2,6',
        'sin Shift solo se marca la casilla que se tocó');
}

// Lo que se pidió: una arriba, otra abajo con Shift, y las del medio caen.
{
    const { tabla } = nueva(10);
    tabla.clic(1);
    tabla.clic(7, { shift: true });
    comprueba(String(tabla.marcadas()) === '1,2,3,4,5,6,7',
        'Shift marca todo el tramo entre las dos');
}

// Y al revés: de abajo hacia arriba tiene que dar lo mismo.
{
    const { tabla } = nueva(10);
    tabla.clic(7);
    tabla.clic(1, { shift: true });
    comprueba(String(tabla.marcadas()) === '1,2,3,4,5,6,7',
        'el tramo se marca igual eligiendo de abajo hacia arriba');
}

// Shift sobre una que se acaba de DESmarcar limpia el tramo: es la mitad que
// falta, y sin ella deshacer una selección larga vuelve a ser clic a clic.
{
    const { tabla } = nueva(10);
    tabla.clic(0);
    tabla.clic(9, { shift: true });
    comprueba(tabla.marcadas().length === 10, 'primero se marcan las diez');

    tabla.clic(8);                    // la desmarca
    tabla.clic(3, { shift: true });   // y arrastra el tramo 3..8
    comprueba(String(tabla.marcadas()) === '0,1,2,9',
        'Shift sobre una recién desmarcada desmarca el tramo entero');
}

// El ancla es la última tocada, con Shift o sin él: dos Shift seguidos
// encadenan desde donde quedó la vista.
{
    const { tabla } = nueva(12);
    tabla.clic(2);
    tabla.clic(5, { shift: true });
    tabla.clic(9, { shift: true });
    comprueba(String(tabla.marcadas()) === '2,3,4,5,6,7,8,9',
        'un segundo Shift sigue el rango desde el anterior');
}

// Sin ancla previa —primer clic de la página— Shift no puede inventarse un
// tramo: se comporta como un clic normal.
{
    const { tabla } = nueva(10);
    tabla.clic(4, { shift: true });
    comprueba(String(tabla.marcadas()) === '4',
        'el primer clic con Shift marca solo esa, no un tramo imaginario');
}

// Marcar todo de golpe borra el ancla: si no, el siguiente Shift se mediría
// contra una casilla que nadie tocó y arrastraría un tramo que nadie pidió.
{
    const { tabla, rango } = nueva(10);
    tabla.clic(1);                    // ancla en la 1
    tabla.hijos.forEach((c) => { c.checked = true; });  // "seleccionar todas"
    rango.olvidarAncla();

    tabla.clic(8, { shift: true });   // sin ancla: se comporta como un clic suelto
    comprueba(String(tabla.marcadas()) === '0,1,2,3,4,5,6,7,9',
        'tras marcar todo, el siguiente Shift no arrastra el tramo desde el ancla vieja');

    // Y ese clic sí deja ancla nueva, para poder seguir trabajando en tandas.
    tabla.clic(5, { shift: true });
    comprueba(String(tabla.marcadas()) === '0,1,2,3,4,9',
        'el clic sin ancla deja una, y el siguiente Shift ya arrastra desde ahí');
}

// La barra de acciones se entera: las casillas del tramo se marcan desde el
// código y eso no dispara su 'change'.
{
    const { tabla, avisos } = nueva(10);
    tabla.clic(1);
    tabla.clic(7, { shift: true });
    comprueba(avisos() === 2, 'cada clic avisa para que se rehaga la cuenta');
}

// Shift + clic, para el navegador, es "extendé la selección de texto": sin
// cancelarlo media tabla queda pintada de azul en cada rango.
{
    const { tabla } = nueva(10);
    comprueba(tabla.clic(3).cancelado === false,
        'un clic normal no se cancela: la casilla recibe el foco como siempre');
    comprueba(tabla.clic(6, { shift: true }).cancelado === true,
        'el Shift sí, para que no arrastre la selección de texto');
    comprueba(tabla.hijos[6].enfocado,
        'y el foco se devuelve a mano, para poder seguir con el teclado');
}

// ── Y que la cola de seguimiento lo tenga conectado ─────────────────────────
//
// El helper puede estar impecable y no hacer nada si la pantalla no lo llama.
{
    const vista = fs.readFileSync(
        path.join(__dirname, '..', 'app', 'views', 'seguimiento', 'index.php'), 'utf8');

    comprueba(/AppSeleccionRango\(\s*tabla\s*,\s*'\.chk-fila'/.test(vista),
        'la cola de seguimiento enciende el rango sobre sus casillas');
    comprueba(/olvidarAncla\(\)/.test(vista),
        'y olvida el ancla al marcar o desmarcar todo con la casilla de arriba');
    comprueba(vista.indexOf('refrescarBarra') !== -1
        && /AppSeleccionRango\([^)]*refrescarBarra/.test(vista),
        'le pasa refrescarBarra, o la cuenta de "N seleccionados" se quedaría corta');
}

if (fallos) { process.exit(1); }
console.log('OK: selección por rango con Shift');
