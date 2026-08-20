/**
 * JavaScript principal de la aplicación
 * Nexo Fiscal
 */

/* Diálogos visuales compartidos. Sustituyen alert/confirm del navegador para
 * que los mensajes tengan contexto, una acción clara y la identidad del sistema. */
(function () {
	'use strict';

	var queue = [];
	var active = null;
	var ui = null;
	var previousFocus = null;

	var types = {
		info:    { icon: 'fa-circle-info',          title: 'Información' },
		warning: { icon: 'fa-triangle-exclamation', title: 'Revisa esta información' },
		danger:  { icon: 'fa-circle-exclamation',   title: 'No se pudo completar' },
		success: { icon: 'fa-circle-check',         title: 'Proceso completado' }
	};

	function createUi() {
		if (ui) return ui;

		var layer = document.createElement('div');
		layer.className = 'app-dialog-layer';
		layer.setAttribute('aria-hidden', 'true');
		layer.innerHTML =
			'<section class="app-dialog" role="dialog" aria-modal="true" aria-labelledby="app-dialog-title" aria-describedby="app-dialog-message">' +
				'<button type="button" class="app-dialog-close" aria-label="Cerrar"><i class="fas fa-xmark"></i></button>' +
				'<div class="app-dialog-head">' +
					'<div class="app-dialog-icon" aria-hidden="true"><i class="fas fa-circle-info"></i></div>' +
					'<div><div class="app-dialog-brand">Nexo Fiscal</div><h2 class="app-dialog-title" id="app-dialog-title"></h2></div>' +
				'</div>' +
				'<div class="app-dialog-message" id="app-dialog-message"></div>' +
				'<div class="app-dialog-actions">' +
					'<button type="button" class="btn btn-outline app-dialog-cancel">Cancelar</button>' +
					'<button type="button" class="btn btn-primary app-dialog-confirm">Entendido</button>' +
				'</div>' +
			'</section>';
		document.body.appendChild(layer);

		ui = {
			layer: layer,
			panel: layer.querySelector('.app-dialog'),
			icon: layer.querySelector('.app-dialog-icon i'),
			title: layer.querySelector('.app-dialog-title'),
			message: layer.querySelector('.app-dialog-message'),
			close: layer.querySelector('.app-dialog-close'),
			cancel: layer.querySelector('.app-dialog-cancel'),
			confirm: layer.querySelector('.app-dialog-confirm')
		};

		ui.close.addEventListener('click', function () { finish(false); });
		ui.cancel.addEventListener('click', function () { finish(false); });
		ui.confirm.addEventListener('click', function () { finish(true); });
		layer.addEventListener('click', function (event) {
			if (event.target === layer) finish(false);
		});

		return ui;
	}

	function openNext() {
		if (active || !queue.length) return;
		createUi();
		active = queue.shift();
		previousFocus = document.activeElement;

		var type = types[active.options.type] ? active.options.type : 'info';
		var meta = types[type];
		ui.panel.className = 'app-dialog is-' + type;
		ui.icon.className = 'fas ' + meta.icon;
		ui.title.textContent = active.options.title || meta.title;
		ui.message.textContent = active.options.message || '';
		ui.cancel.textContent = active.options.cancelText || 'Cancelar';
		ui.confirm.textContent = active.options.confirmText || (active.options.confirm ? 'Continuar' : 'Entendido');
		ui.cancel.hidden = !active.options.confirm;
		ui.confirm.className = 'btn app-dialog-confirm ' + (type === 'danger' || type === 'warning' ? 'is-' + type : 'btn-primary');

		document.body.classList.add('app-dialog-open');
		ui.layer.setAttribute('aria-hidden', 'false');
		requestAnimationFrame(function () {
			ui.layer.classList.add('open');
			(active.options.confirm ? ui.cancel : ui.confirm).focus();
		});
	}

	function finish(result) {
		if (!active || !ui) return;
		var completed = active;
		active = null;
		ui.layer.classList.remove('open');
		ui.layer.setAttribute('aria-hidden', 'true');
		document.body.classList.remove('app-dialog-open');

		setTimeout(function () {
			completed.resolve(result);
			if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
			previousFocus = null;
			openNext();
		}, 180);
	}

	function show(options) {
		return new Promise(function (resolve) {
			queue.push({ options: options, resolve: resolve });
			openNext();
		});
	}

	function dataOptions(element) {
		return {
			message: element.dataset.confirm || '',
			title: element.dataset.confirmTitle || 'Confirma esta acción',
			type: element.dataset.confirmType || 'warning',
			confirmText: element.dataset.confirmAccept || 'Continuar',
			cancelText: element.dataset.confirmCancel || 'Cancelar',
			confirm: true
		};
	}

	function reconfirmOptions(element) {
		return {
			message: element.dataset.reconfirm || '',
			title: element.dataset.reconfirmTitle || 'Confirmá una vez más',
			type: element.dataset.reconfirmType || 'danger',
			confirmText: element.dataset.reconfirmAccept || 'Sí, continuar',
			cancelText: element.dataset.reconfirmCancel || 'Cancelar',
			confirm: true
		};
	}

	/* Segunda vuelta para lo que no se puede deshacer. El primer diálogo
	 * explica qué va a pasar; el segundo obliga a decidirlo otra vez, ya
	 * sabiéndolo. Solo aparece si el elemento trae data-reconfirm: sin ese
	 * atributo nada cambia para el resto de las confirmaciones. */
	function pedirConfirmacion(element) {
		return show(dataOptions(element)).then(function (aceptado) {
			if (!aceptado || !element.dataset.reconfirm) return aceptado;
			return show(reconfirmOptions(element));
		});
	}

	window.AppDialog = {
		alert: function (message, options) {
			options = options || {};
			return show(Object.assign({}, options, { message: String(message || ''), confirm: false }));
		},
		confirm: function (message, options) {
			options = options || {};
			return show(Object.assign({}, options, { message: String(message || ''), confirm: true }));
		}
	};

	/* Confirmaciones declarativas para formularios, enlaces y botones, incluidos
	 * los que se agregan a la página después de cargarla. */
	document.addEventListener('submit', function (event) {
		var form = event.target.closest ? event.target.closest('form[data-confirm]') : null;
		if (!form) return;
		if (form.dataset.dialogConfirmed === 'true') {
			delete form.dataset.dialogConfirmed;
			return;
		}

		event.preventDefault();
		if (form.dataset.dialogPending === 'true') return;
		form.dataset.dialogPending = 'true';
		var submitter = event.submitter || null;

		pedirConfirmacion(form).then(function (confirmed) {
			delete form.dataset.dialogPending;
			if (!confirmed) return;
			form.dataset.dialogConfirmed = 'true';
			if (typeof form.requestSubmit === 'function') {
				if (submitter) form.requestSubmit(submitter);
				else form.requestSubmit();
			}
			else form.submit();
		});
	}, true);

	document.addEventListener('click', function (event) {
		var trigger = event.target.closest ? event.target.closest('a[data-confirm], button[data-confirm]') : null;
		if (!trigger) return;
		if (trigger.dataset.dialogConfirmed === 'true') {
			delete trigger.dataset.dialogConfirmed;
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();
		if (trigger.dataset.dialogPending === 'true') return;
		trigger.dataset.dialogPending = 'true';

		pedirConfirmacion(trigger).then(function (confirmed) {
			delete trigger.dataset.dialogPending;
			if (!confirmed) return;
			trigger.dataset.dialogConfirmed = 'true';
			trigger.click();
		});
	}, true);

	document.addEventListener('keydown', function (event) {
		if (!active || !ui) return;
		if (event.key === 'Escape') {
			event.preventDefault();
			finish(false);
			return;
		}
		if (event.key !== 'Tab') return;
		var focusable = Array.prototype.filter.call(
			ui.panel.querySelectorAll('button:not([hidden]):not([disabled])'),
			function (element) { return element.offsetParent !== null; }
		);
		if (!focusable.length) return;
		var first = focusable[0];
		var last = focusable[focusable.length - 1];
		if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
		else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
	});
})();

/* Filtro de proveedor: desplegable con buscador, igual en todos los listados.
 *
 * Lo que se busca es el código y la cédula —lo que identifica al proveedor—,
 * y también el nombre, que es lo que la gente recuerda. El índice de búsqueda
 * se arma con el texto que la fila ya muestra, la primera vez que alguien
 * escribe: mandarlo también en atributos duplicaría el listado entero dentro
 * del HTML de cada pantalla. Los dígitos van aparte para que escribir "3-101"
 * encuentre lo mismo que escribir la cédula corrida.
 *
 * Todo por delegación: la barra de filtros de algunas pantallas se dibuja
 * después de cargar la página. */
(function () {
	'use strict';

	function normalizar(texto) {
		return String(texto || '')
			.toLowerCase()
			.normalize('NFD')
			.replace(/[\u0300-\u036f]/g, '')
			.replace(/\s+/g, ' ')
			.trim();
	}

	function opciones(picker) {
		return Array.prototype.slice.call(picker.querySelectorAll('.prov-opcion'));
	}

	/* El índice de una fila, calculado una sola vez. La cuenta de documentos
	 * queda fuera a propósito: si entrara, buscar "7" traería todo lo que
	 * tenga siete facturas. */
	function indice(li) {
		if (!li._provIndice) {
			var claves = li.querySelector('.prov-opcion-claves');
			var nombre = li.querySelector('.prov-nom');
			var texto = claves || nombre
				? normalizar((claves ? claves.textContent + ' ' : '') + (nombre ? nombre.textContent : ''))
				: normalizar(li.textContent);
			li._provIndice = { texto: texto, digitos: texto.replace(/\D+/g, '') };
		}
		return li._provIndice;
	}

	function visibles(picker) {
		return opciones(picker).filter(function (li) { return !li.hidden; });
	}

	function marcarActiva(picker, li) {
		opciones(picker).forEach(function (otra) { otra.classList.remove('is-activa'); });
		if (!li) return;
		li.classList.add('is-activa');
		if (li.scrollIntoView) li.scrollIntoView({ block: 'nearest' });
	}

	/* Un término con dígitos también busca en los dígitos sueltos: la cédula
	 * se escribe con guiones en un lado y sin ellos en el otro. */
	function coincide(li, terminos) {
		var datos = indice(li);
		return terminos.every(function (termino) {
			if (datos.texto.indexOf(termino) !== -1) return true;
			var soloDigitos = termino.replace(/\D+/g, '');
			return soloDigitos !== '' && datos.digitos.indexOf(soloDigitos) !== -1;
		});
	}

	function filtrar(picker) {
		var campo = picker.querySelector('[data-prov-buscar]');
		var vacio = picker.querySelector('[data-prov-vacio]');
		var terminos = normalizar(campo ? campo.value : '').split(' ').filter(Boolean);

		opciones(picker).forEach(function (li) {
			li.hidden = terminos.length > 0 && !coincide(li, terminos);
		});

		var quedan = visibles(picker);
		if (vacio) vacio.hidden = quedan.length > 0;
		marcarActiva(picker, quedan[0] || null);
		// Al escribir la lista se acorta: si el panel abrió hacia arriba, su
		// borde de abajo tiene que seguir pegado al botón.
		colocar(picker);
	}

	/* Coloca el panel pegado al botón, contra la ventana.
	 *
	 * Va fijo y no dentro de la tarjeta porque las tarjetas recortan lo que
	 * se sale de ellas: en un listado que quedó en una fila, el desplegable
	 * salía cortado a media lista. Aquí se decide además si abre hacia abajo
	 * o hacia arriba, y cuánta lista cabe, para que siempre se vea entero. */
	function colocar(picker) {
		var panel = picker.querySelector('[data-prov-panel]');
		var boton = picker.querySelector('[data-prov-boton]');
		var lista = picker.querySelector('[data-prov-lista]');
		if (!panel || !boton || panel.hidden) return;

		var caja = boton.getBoundingClientRect();
		var alto = window.innerHeight;
		var margen = 8;

		panel.style.width = Math.min(Math.max(caja.width, 300), 460, window.innerWidth - margen * 2) + 'px';
		panel.style.left = Math.round(Math.max(margen,
			Math.min(caja.left, window.innerWidth - panel.offsetWidth - margen))) + 'px';

		var debajo = alto - caja.bottom - margen;
		var encima = caja.top - margen;
		var haciaArriba = debajo < 200 && encima > debajo;
		var espacio = haciaArriba ? encima : debajo;

		// Lo que no es lista (el buscador, el aviso de "nadie coincide") ocupa
		// su parte: la lista se queda con lo que sobre, nunca menos de tres
		// renglones, que es cuando el desplegable deja de servir.
		if (lista) {
			lista.style.maxHeight = Math.max(120, Math.min(290, espacio - (panel.offsetHeight - lista.offsetHeight))) + 'px';
		}

		// Pegado al botón, pero siempre dentro de la ventana: sin este tope
		// por los dos lados, bajar la página con el desplegable abierto lo
		// mandaba fuera de la pantalla.
		var arriba = haciaArriba ? caja.top - panel.offsetHeight - 3 : caja.bottom + 3;
		panel.style.top = Math.round(Math.min(
			Math.max(arriba, margen),
			Math.max(margen, alto - panel.offsetHeight - margen)
		)) + 'px';
	}

	/** ¿Sigue el botón a la vista? Si no, no hay a qué pegar el panel. */
	function botonVisible(picker) {
		var boton = picker.querySelector('[data-prov-boton]');
		if (!boton) return false;
		var caja = boton.getBoundingClientRect();
		return caja.bottom > 0 && caja.top < window.innerHeight;
	}

	function abrir(picker) {
		cerrarTodos(picker);
		var panel = picker.querySelector('[data-prov-panel]');
		var boton = picker.querySelector('[data-prov-boton]');
		var campo = picker.querySelector('[data-prov-buscar]');
		if (!panel) return;

		picker.classList.add('is-abierto');
		panel.hidden = false;
		colocar(picker);
		if (boton) boton.setAttribute('aria-expanded', 'true');
		if (campo) {
			campo.value = '';
			campo.focus();
		}
		filtrar(picker);
		marcarActiva(picker, picker.querySelector('.prov-opcion.is-elegida:not([hidden])'));
		colocar(picker);
	}

	function cerrar(picker) {
		var panel = picker.querySelector('[data-prov-panel]');
		var boton = picker.querySelector('[data-prov-boton]');
		picker.classList.remove('is-abierto');
		if (panel) panel.hidden = true;
		if (boton) boton.setAttribute('aria-expanded', 'false');
	}

	function cerrarTodos(excepto) {
		Array.prototype.forEach.call(document.querySelectorAll('[data-prov-picker].is-abierto'), function (picker) {
			if (picker !== excepto) cerrar(picker);
		});
	}

	function etiquetaDe(li) {
		if (!li.dataset.valor) {
			return document.createTextNode(li.dataset.etiqueta || 'Todos los proveedores');
		}
		var trozo = document.createDocumentFragment();
		var clave = li.querySelector('.prov-cod') || li.querySelector('.prov-ced');
		if (clave) {
			var cod = document.createElement('span');
			cod.className = 'prov-cod';
			cod.textContent = clave.textContent;
			trozo.appendChild(cod);
		}
		var nombre = li.querySelector('.prov-nom');
		if (nombre) {
			var nom = document.createElement('span');
			nom.className = 'prov-nom';
			nom.textContent = nombre.textContent;
			trozo.appendChild(nom);
		}
		return trozo;
	}

	function elegir(picker, li) {
		var oculto = picker.querySelector('[data-prov-valor]');
		var etiqueta = picker.querySelector('[data-prov-etiqueta]');
		if (!oculto || !li) return;

		var cambio = oculto.value !== li.dataset.valor;
		oculto.value = li.dataset.valor;

		opciones(picker).forEach(function (otra) {
			var elegida = otra === li;
			otra.classList.toggle('is-elegida', elegida);
			otra.setAttribute('aria-selected', elegida ? 'true' : 'false');
		});
		picker.classList.toggle('is-elegido', li.dataset.valor !== '');

		if (etiqueta) {
			etiqueta.textContent = '';
			etiqueta.appendChild(etiquetaDe(li));
		}
		cerrar(picker);

		var boton = picker.querySelector('[data-prov-boton]');
		if (boton) boton.focus();
		if (!cambio) return;

		/* Los listados que buscan sin recargar (Notas de crédito) escuchan el
		 * change del campo; los demás recargan al enviar el formulario. */
		oculto.dispatchEvent(new Event('change', { bubbles: true }));
		var form = picker.closest('form');
		if (form && picker.dataset.provAutosubmit === '1') {
			if (typeof form.requestSubmit === 'function') form.requestSubmit();
			else form.submit();
		}
	}

	document.addEventListener('click', function (evento) {
		var boton = evento.target.closest ? evento.target.closest('[data-prov-boton]') : null;
		if (boton) {
			var picker = boton.closest('[data-prov-picker]');
			if (picker.classList.contains('is-abierto')) cerrar(picker);
			else abrir(picker);
			return;
		}

		var opcion = evento.target.closest ? evento.target.closest('.prov-opcion') : null;
		if (opcion) {
			elegir(opcion.closest('[data-prov-picker]'), opcion);
			return;
		}

		if (!evento.target.closest || !evento.target.closest('[data-prov-picker]')) cerrarTodos(null);
	});

	document.addEventListener('input', function (evento) {
		if (evento.target.matches && evento.target.matches('[data-prov-buscar]')) {
			filtrar(evento.target.closest('[data-prov-picker]'));
		}
	});

	/* El panel va fijo a la ventana, así que hay que reacomodarlo cuando la
	 * página se mueve debajo. En captura, para enterarse también del scroll
	 * de una tabla ancha; el de la propia lista no cuenta. */
	function reacomodar(evento) {
		var abiertos = document.querySelectorAll('[data-prov-picker].is-abierto');
		if (!abiertos.length) return;
		if (evento && evento.target && evento.target.closest
			&& evento.target.closest('[data-prov-panel]')) return;

		Array.prototype.forEach.call(abiertos, function (picker) {
			// Si el filtro se fue de la pantalla, el desplegable se cierra en
			// vez de quedar suelto en medio de la página.
			if (botonVisible(picker)) colocar(picker);
			else cerrar(picker);
		});
	}

	document.addEventListener('scroll', reacomodar, true);
	window.addEventListener('resize', reacomodar);

	document.addEventListener('keydown', function (evento) {
		var picker = evento.target.closest ? evento.target.closest('[data-prov-picker]') : null;
		if (!picker) return;

		if (evento.key === 'Escape' && picker.classList.contains('is-abierto')) {
			evento.preventDefault();
			cerrar(picker);
			var boton = picker.querySelector('[data-prov-boton]');
			if (boton) boton.focus();
			return;
		}
		if ((evento.key === 'Enter' || evento.key === ' ') && !picker.classList.contains('is-abierto')) {
			if (evento.target.matches('[data-prov-boton]')) {
				evento.preventDefault();
				abrir(picker);
			}
			return;
		}
		if (!picker.classList.contains('is-abierto')) return;

		/* Con el desplegable abierto, Enter elige de la lista y nunca envía el
		 * formulario: escribir algo que no encuentra a nadie y pulsar Enter
		 * recargaría la pantalla sin haber elegido. */
		if (evento.key === 'Enter' || evento.key === 'ArrowDown' || evento.key === 'ArrowUp') {
			evento.preventDefault();
		}

		var lista = visibles(picker);
		if (!lista.length) return;
		var actual = picker.querySelector('.prov-opcion.is-activa');
		var posicion = lista.indexOf(actual);

		if (evento.key === 'ArrowDown') {
			marcarActiva(picker, lista[Math.min(posicion + 1, lista.length - 1)] || lista[0]);
		} else if (evento.key === 'ArrowUp') {
			marcarActiva(picker, lista[Math.max(posicion - 1, 0)]);
		} else if (evento.key === 'Enter') {
			elegir(picker, actual || lista[0]);
		}
	});
})();

/* app.js se carga arriba del <body>, antes de que las vistas se pinten, para
 * que sus acciones puedan usar AppDialog. Cuando esto corre, la tarjeta
 * todavía no existe en el documento: por eso se busca al estar listo el DOM y
 * no de una. Buscarla de una es lo que la dejaba dibujada pero en blanco. */
(function () {
	'use strict';

	var ESTADOS = {
		respaldada:     { color: '#16a34a', nombre: 'Con respaldo' },
		con_diferencia: { color: '#dc2626', nombre: 'Con diferencia' },
		sin_respaldo:   { color: '#94a3b8', nombre: 'Sin respaldo' }
	};

	var tarjeta = null;
	var items = [];
	var idx = 0;
	var listo = false;

	/* Engancha la tarjeta si ya está en el documento. Devuelve si quedó lista,
	 * para que quien pregunte por el documento visible sepa si hay alguno. */
	function iniciar() {
		if (listo) return true;

		tarjeta = document.querySelector('[data-navdoc]');
		if (!tarjeta) return false;

		try {
			items = JSON.parse(tarjeta.dataset.navdocItems || '[]');
		} catch (e) {
			items = [];
		}
		if (!items.length) { tarjeta = null; return false; }

		idx = Math.max(0, Math.min(items.length - 1, parseInt(tarjeta.dataset.navdocIdx, 10) || 0));

		tarjeta.querySelector('[data-navdoc-prev]').addEventListener('click', function () {
			if (idx > 0) { idx--; pintar(true); }
		});
		tarjeta.querySelector('[data-navdoc-next]').addEventListener('click', function () {
			if (idx < items.length - 1) { idx++; pintar(true); }
		});
		tarjeta.querySelector('[data-navdoc-buscar]').addEventListener('click', buscar);
		tarjeta.querySelector('[data-navdoc-cerrar]').addEventListener('click', function () {
			tarjeta.style.display = 'none';
			tarjeta.dispatchEvent(new CustomEvent('navdoc:cerrada'));
		});

		listo = true;
		pintar(false);
		return true;
	}

	function actual() { return items[idx] || null; }

	function pintar(avisar) {
		var it = actual();
		if (!it) return;
		var est = ESTADOS[it.estado] || ESTADOS.sin_respaldo;

		var punto = tarjeta.querySelector('[data-navdoc-punto]');
		punto.style.background = est.color;
		punto.style.boxShadow = '0 0 0 3px ' + est.color + '33';
		punto.title = est.nombre;

		var numero = tarjeta.querySelector('[data-navdoc-numero]');
		numero.textContent = it.numero;
		numero.title = it.numero + ' — ' + est.nombre;

		var proveedor = tarjeta.querySelector('[data-navdoc-proveedor]');
		proveedor.textContent = it.proveedor;
		proveedor.title = it.proveedor;

		tarjeta.querySelector('[data-navdoc-fecha]').textContent = it.fecha || '—';
		tarjeta.querySelector('[data-navdoc-monto]').textContent = '₡' +
			Number(it.total).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
		tarjeta.querySelector('[data-navdoc-pos]').textContent = (idx + 1) + ' / ' + items.length;

		tarjeta.querySelector('[data-navdoc-prev]').disabled = idx === 0;
		tarjeta.querySelector('[data-navdoc-next]').disabled = idx === items.length - 1;

		/* La URL sigue al documento visible: si la página se recarga —al
		 * procesar correos, al filtrar—, la tarjeta vuelve a abrir en ESTE y no
		 * en el primero. Se suelta lo que trajo la búsqueda anterior para que
		 * la recarga no la repita. */
		try {
			history.replaceState(null, '',
				window.location.pathname + '?' + tarjeta.dataset.navdocParams +
				'&ctx_item=' + encodeURIComponent(it.id));
		} catch (e) {}

		if (avisar) {
			tarjeta.dispatchEvent(new CustomEvent('navdoc:cambia', { detail: it }));
		}
	}

	function buscar() {
		if (!iniciar()) return;
		tarjeta.dispatchEvent(new CustomEvent('navdoc:buscar', { detail: actual() }));
	}

	/* Para que la pantalla pueda buscar el primero en cuanto termine de
	 * prepararse, sin duplicar acá lo que significa buscar en cada una.
	 *
	 * Las dos enganchan primero: los guiones de cada vista corren en medio del
	 * documento, antes de que esté listo, y preguntan por el documento visible
	 * nada más cargar. */
	window.navdocBuscar = buscar;
	window.navdocActual = function () { return iniciar() ? actual() : null; };

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', iniciar);
	} else {
		iniciar();
	}
})();

/* Filtro de clase de nota: casillas, varias a la vez.
 *
 * Ninguna marcada quiere decir "todas": es el estado en blanco, no un filtro
 * que no encuentra nada. Lo elegido se escribe separado por comas en el campo
 * escondido, que es lo único que viaja en el formulario.
 *
 * El refresco va en CAPTURA sobre el picker y no en cada casilla: la pantalla
 * de Notas de crédito busca en vivo y engancha su propio 'input' a cada
 * casilla del formulario. En captura, el valor escondido ya está actualizado
 * cuando ese oyente corre, en vez de depender de quién se registró primero.
 *
 * Todo por delegación, igual que el filtro de proveedor. */
(function () {
	'use strict';

	function casillas(picker) {
		return Array.prototype.slice.call(picker.querySelectorAll('[data-clase-casilla]'));
	}

	function refrescar(picker) {
		var marcadas = casillas(picker).filter(function (c) { return c.checked; });
		var valor = picker.querySelector('[data-clase-valor]');
		var etiqueta = picker.querySelector('[data-clase-etiqueta]');

		valor.value = marcadas.map(function (c) { return c.value; }).join(',');
		etiqueta.textContent = marcadas.length === 0
			? 'Todas'
			: (marcadas.length === 1
				? marcadas[0].parentNode.querySelector('span').textContent.trim()
				: marcadas.length + ' clases');
		picker.classList.toggle('is-elegido', marcadas.length > 0);
	}

	function abrir(picker, si) {
		var panel = picker.querySelector('[data-clase-panel]');
		var boton = picker.querySelector('[data-clase-boton]');
		picker.classList.toggle('is-abierto', si);
		if (panel) panel.hidden = !si;
		if (boton) boton.setAttribute('aria-expanded', si ? 'true' : 'false');
	}

	function cerrarTodos(excepto) {
		Array.prototype.forEach.call(document.querySelectorAll('[data-clase-picker].is-abierto'), function (picker) {
			if (picker !== excepto) abrir(picker, false);
		});
	}

	/* Vaciar el filtro desde fuera: lo usa Seguimiento al pasar el tipo de
	 * documento a "Facturas", donde la clase no significa nada. */
	window.filtroClaseVaciar = function (picker) {
		if (!picker) return;
		casillas(picker).forEach(function (c) { c.checked = false; });
		refrescar(picker);
		abrir(picker, false);
	};

	document.addEventListener('click', function (evento) {
		if (!evento.target.closest) return;

		var boton = evento.target.closest('[data-clase-boton]');
		if (boton) {
			var picker = boton.closest('[data-clase-picker]');
			cerrarTodos(picker);
			abrir(picker, !picker.classList.contains('is-abierto'));
			return;
		}

		var limpiar = evento.target.closest('[data-clase-limpiar]');
		if (limpiar) {
			window.filtroClaseVaciar(limpiar.closest('[data-clase-picker]'));
			return;
		}

		if (!evento.target.closest('[data-clase-picker]')) cerrarTodos(null);
	});

	document.addEventListener('input', function (evento) {
		if (!evento.target.closest) return;
		var picker = evento.target.closest('[data-clase-picker]');
		if (picker && evento.target.hasAttribute('data-clase-casilla')) refrescar(picker);
	}, true);

	document.addEventListener('keydown', function (evento) {
		if (evento.key === 'Escape') cerrarTodos(null);
	});
})();

document.addEventListener('DOMContentLoaded', function () {
	var openButtons = document.querySelectorAll('[data-modal-target]');
	var closeButtons = document.querySelectorAll('[data-modal-close]');
	var modals = document.querySelectorAll('[data-modal]');

	function openModal(modalId) {
		var modal = document.getElementById(modalId);
		if (!modal) {
			return;
		}

		modal.style.display = 'block';
		document.body.style.overflow = 'hidden';
	}

	function closeModal(modal) {
		if (!modal) {
			return;
		}

		modal.style.display = 'none';
		document.body.style.overflow = '';
	}

	openButtons.forEach(function (button) {
		button.addEventListener('click', function () {
			openModal(button.getAttribute('data-modal-target'));
		});
	});

	closeButtons.forEach(function (button) {
		button.addEventListener('click', function () {
			closeModal(button.closest('[data-modal]'));
		});
	});

	modals.forEach(function (modal) {
		modal.addEventListener('click', function (event) {
			if (event.target === modal) {
				closeModal(modal);
			}
		});
	});

	document.addEventListener('keydown', function (event) {
		if (event.key !== 'Escape') {
			return;
		}

		modals.forEach(function (modal) {
			if (modal.style.display === 'block') {
				closeModal(modal);
			}
		});
	});
});
