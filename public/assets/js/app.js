/**
 * JavaScript principal de la aplicación
 * Nexo Fiscal
 */

/* Esta pantalla se está viendo DENTRO de una ventana del sistema: un marco
 * abierto sobre otra pantalla desde un botón que antes mandaba a otra pestaña
 * (ver el visor de ventanas, al final del archivo).
 *
 * Se marca en la primera línea que corre, y no al terminar de cargar, porque
 * app.js se carga justo después de <body> y antes del menú lateral: cuando el
 * CSS lo esconde, el menú todavía no se dibujó y no hay parpadeo.
 */
if (window.self !== window.top) {
	document.documentElement.classList.add('en-ventana');
}

/* El token de la sesión en cada escritura que va a la propia aplicación.
 *
 * El servidor rechaza un POST que no pruebe salir de una pantalla de Nexo
 * Fiscal (ver app/core/Csrf.php). En vez de pedirle a cada pantalla que se
 * acuerde de mandarlo, se le pone aquí a todo lo que sale: fetch,
 * XMLHttpRequest y formularios, también los que se envían con form.submit().
 * Solo al mismo origen: el token no viaja nunca a otro sitio.
 */
(function () {
	'use strict';

	var meta = document.querySelector('meta[name="csrf-token"]');
	var token = meta ? meta.getAttribute('content') : '';
	if (!token) return;

	function esPropia(url) {
		try { return new URL(url, window.location.href).origin === window.location.origin; }
		catch (e) { return false; }
	}
	function escribe(metodo) {
		metodo = String(metodo || 'GET').toUpperCase();
		return metodo !== 'GET' && metodo !== 'HEAD' && metodo !== 'OPTIONS';
	}

	if (window.fetch) {
		var fetchOriginal = window.fetch;
		window.fetch = function (recurso, opciones) {
			try {
				var esPeticion = typeof Request !== 'undefined' && recurso instanceof Request;
				var metodo = (opciones && opciones.method) || (esPeticion ? recurso.method : 'GET');
				var url = esPeticion ? recurso.url : String(recurso);
				if (escribe(metodo) && esPropia(url)) {
					opciones = Object.assign({}, opciones || {});
					var cabeceras = new Headers(opciones.headers || (esPeticion ? recurso.headers : undefined));
					if (!cabeceras.has('X-CSRF-Token')) cabeceras.set('X-CSRF-Token', token);
					opciones.headers = cabeceras;
				}
			} catch (e) { /* ante la duda, la petición sale como la escribió la pantalla */ }
			return fetchOriginal.call(this, recurso, opciones);
		};
	}

	if (window.XMLHttpRequest) {
		var abrir = XMLHttpRequest.prototype.open;
		var enviar = XMLHttpRequest.prototype.send;
		XMLHttpRequest.prototype.open = function (metodo, url) {
			this._nexoCsrf = escribe(metodo) && esPropia(url);
			return abrir.apply(this, arguments);
		};
		XMLHttpRequest.prototype.send = function () {
			if (this._nexoCsrf) {
				try { this.setRequestHeader('X-CSRF-Token', token); } catch (e) {}
			}
			return enviar.apply(this, arguments);
		};
	}

	function sellar(form) {
		if (!form || !escribe(form.getAttribute('method') || form.method)) return;
		if (!esPropia(form.getAttribute('action') || window.location.href)) return;
		var campo = form.querySelector('input[name="csrf_token"]');
		if (!campo) {
			campo = document.createElement('input');
			campo.type = 'hidden';
			campo.name = 'csrf_token';
			form.appendChild(campo);
		}
		if (!campo.value) campo.value = token;
	}
	document.addEventListener('submit', function (e) { sellar(e.target); }, true);
	if (window.HTMLFormElement) {
		var submitOriginal = HTMLFormElement.prototype.submit;
		HTMLFormElement.prototype.submit = function () {
			sellar(this);
			return submitOriginal.apply(this, arguments);
		};
	}
})();

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
				'<div class="app-dialog-body" hidden></div>' +
				'<div class="app-dialog-error" role="alert" hidden></div>' +
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
			body: layer.querySelector('.app-dialog-body'),
			error: layer.querySelector('.app-dialog-error'),
			close: layer.querySelector('.app-dialog-close'),
			cancel: layer.querySelector('.app-dialog-cancel'),
			confirm: layer.querySelector('.app-dialog-confirm')
		};

		ui.close.addEventListener('click', function () { finish(false); });
		ui.cancel.addEventListener('click', function () { finish(false); });
		ui.confirm.addEventListener('click', confirmar);
		// Enter en un campo del formulario es aceptar, como en cualquier
		// formulario; en un área de texto sigue siendo salto de línea.
		ui.body.addEventListener('keydown', function (event) {
			if (event.key === 'Enter' && event.target.tagName !== 'TEXTAREA' && event.target.tagName !== 'BUTTON') {
				event.preventDefault();
				confirmar();
			}
		});
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
		// En un formulario la explicación es opcional: sin ella, el hueco del
		// mensaje sobraría entre el título y los campos.
		ui.message.hidden = !!active.options.content && !active.options.message;
		ui.body.innerHTML = '';
		if (active.options.content) {
			if (typeof active.options.content === 'string') ui.body.innerHTML = active.options.content;
			else ui.body.appendChild(active.options.content);
		}
		ui.body.hidden = !active.options.content;
		mostrarError('');
		ui.confirm.disabled = false;
		ui.cancel.textContent = active.options.cancelText || 'Cancelar';
		ui.confirm.textContent = active.options.confirmText || (active.options.confirm ? 'Continuar' : 'Entendido');
		ui.cancel.hidden = !active.options.confirm;
		ui.confirm.className = 'btn app-dialog-confirm ' + (type === 'danger' || type === 'warning' ? 'is-' + type : 'btn-primary');

		document.body.classList.add('app-dialog-open');
		ui.layer.setAttribute('aria-hidden', 'false');
		requestAnimationFrame(function () {
			ui.layer.classList.add('open');
			var campo = ui.body.hidden ? null : ui.body.querySelector('input:not([type=hidden]):not([disabled]):not([readonly]), select, textarea');
			if (campo) {
				campo.focus();
				if (typeof campo.select === 'function' && campo.tagName === 'INPUT') campo.select();
			} else {
				(active.options.confirm ? ui.cancel : ui.confirm).focus();
			}
		});
	}

	function mostrarError(texto) {
		ui.error.textContent = texto || '';
		ui.error.hidden = !texto;
	}

	/* Aceptar. En un formulario pasa primero por onConfirm, que puede
	 * devolver (o prometer) un texto de error: el cuadro se queda abierto
	 * con lo escrito y el error a la vista, en vez de cerrarse y obligar a
	 * empezar de nuevo. Cualquier otro valor cierra y es la respuesta. */
	function confirmar() {
		if (!active || !ui || ui.confirm.disabled) return;
		var actual = active;
		if (typeof actual.options.onConfirm !== 'function') { finish(true); return; }

		mostrarError('');
		var textoBoton = ui.confirm.innerHTML;
		ui.confirm.disabled = true;
		ui.confirm.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + (actual.options.pendingText || 'Guardando…');

		Promise.resolve()
			.then(function () { return actual.options.onConfirm(ui.body); })
			.then(function (resultado) {
				if (active !== actual) return;
				ui.confirm.disabled = false;
				ui.confirm.innerHTML = textoBoton;
				if (typeof resultado === 'string' && resultado !== '') { mostrarError(resultado); return; }
				if (resultado === false) return;
				finish(resultado === undefined ? true : resultado);
			}, function (error) {
				if (active !== actual) return;
				ui.confirm.disabled = false;
				ui.confirm.innerHTML = textoBoton;
				mostrarError((error && error.message) || 'No se pudo completar.');
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

	/*
	 * Elegir para un proveedor un código que ya es de otra cédula.
	 *
	 * Hay dos respuestas y ninguna es la obvia: a veces el código es de verdad
	 * del proveedor nuevo y el dueño anterior estaba mal; otras veces los dos
	 * facturan contra el mismo código (BANCO BAC y BAC LEASING) y lo que hace
	 * falta es que acepte a los dos. La que no quita nada va marcada.
	 *
	 * Resuelve 'tambien', 'pasar' o false si se cancela.
	 */
	function codigoConDueno(datos) {
		function esc(t) {
			return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		}
		var nuevo = esc(datos.nombre || datos.cedula);
		var dueno = esc(datos.dueno || datos.cedulaDueno);
		var contenido =
			'<div class="dlg-codigo-dueno">' +
				'<label class="dlg-opcion-radio">' +
					'<input type="radio" name="modo_codigo" value="tambien" checked>' +
					'<span><b>Que lo acepte también</b>' +
					'<small>Sus facturas aceptan los comprobantes de los dos. ' + dueno +
					' sigue siendo el dueño del código.</small></span>' +
				'</label>' +
				'<label class="dlg-opcion-radio">' +
					'<input type="radio" name="modo_codigo" value="pasar">' +
					'<span><b>Pasárselo a este proveedor</b>' +
					'<small>Sus facturas aceptan los comprobantes de ' + nuevo +
					' y dejan de aceptar los de ' + dueno + '.</small></span>' +
				'</label>' +
			'</div>';

		return AppDialog.form({
			title: 'Ese código ya tiene dueño',
			type: 'warning',
			message: 'El código ' + datos.codigo + ' hoy es de ' + (datos.dueno || datos.cedulaDueno)
				+ ' (cédula ' + datos.cedulaDueno + '). ¿Qué hacemos con '
				+ (datos.nombre || datos.cedula) + '?',
			content: contenido,
			confirmText: 'Guardar',
			pendingText: 'Guardando…',
			/* Un texto devuelto acá es un error que deja el cuadro abierto: la
			 * elección viaja envuelta y se desenvuelve al resolver. */
			onConfirm: function (cuerpo) {
				var marcada = cuerpo.querySelector('input[name="modo_codigo"]:checked');
				return marcada ? { modo: marcada.value } : 'Elegí una de las dos.';
			}
		}).then(function (r) { return r && r.modo ? r.modo : false; });
	}

	window.AppDialog = {
		codigoConDueno: codigoConDueno,
		alert: function (message, options) {
			options = options || {};
			return show(Object.assign({}, options, { message: String(message || ''), confirm: false }));
		},
		confirm: function (message, options) {
			options = options || {};
			return show(Object.assign({}, options, { message: String(message || ''), confirm: true }));
		},
		/* Un formulario corto con la cara de los demás diálogos, en lugar de
		 * prompt(). `content` es un elemento o HTML; `onConfirm(cuerpo)` valida
		 * y guarda, y devuelve un texto de error para quedarse abierto. Se
		 * resuelve con lo que devuelva onConfirm, o false si se cancela. */
		form: function (options) {
			options = options || {};
			return show(Object.assign({ confirmText: 'Guardar' }, options, {
				message: String(options.message || ''), confirm: true
			}));
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
		/* Un diálogo abierto es siempre la capa de arriba: Escape y Tab son
		 * suyos y no pueden llegar a lo que quedó debajo. Sin esto, cerrar una
		 * confirmación con Escape cerraba también el cuadro o el panel sobre
		 * el que se había abierto. */
		if (event.key === 'Escape' || event.key === 'Tab') {
			event.stopImmediatePropagation();
		}
		if (event.key === 'Escape') {
			event.preventDefault();
			finish(false);
			return;
		}
		if (event.key !== 'Tab') return;
		var focusable = Array.prototype.filter.call(
			ui.panel.querySelectorAll('button:not([hidden]):not([disabled]), input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled])'),
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

/* ══════════════════════════════════════════════════════════════════════
   ENTER BUSCA, EN CUALQUIER CAMPO DE UNA BARRA DE FILTROS

   El navegador solo envía el formulario con Enter desde una caja de texto.
   Desde un desplegable (Sucursal, Estado), una casilla (Solo con saldo) o
   una fecha no hacía nada, y desde el botón del proveedor volvía a abrir la
   lista: se escribía el filtro, se pulsaba Enter y la pantalla se quedaba
   igual, como si el filtro no funcionara.

   Ahora, dentro de una barra de filtros, Enter es "Buscar" esté donde esté
   el cursor. Va en captura para llegar antes que el selector de proveedor,
   que con la lista cerrada usaba Enter para abrirla (Espacio la sigue
   abriendo). Con la lista abierta no se toca: ahí Enter elige, y elegir ya
   busca solo.

   requestSubmit y no submit(): pasa por los listeners de envío, que es por
   donde las pantallas que buscan sin recargar (Notas de crédito) hacen lo
   suyo.
   ══════════════════════════════════════════════════════════════════════ */
(function () {
	'use strict';

	document.addEventListener('keydown', function (evento) {
		if (evento.key !== 'Enter' || evento.isComposing || evento.defaultPrevented) return;
		if (evento.shiftKey || evento.ctrlKey || evento.altKey || evento.metaKey) return;

		var campo = evento.target;
		var form = campo && campo.closest ? campo.closest('form.filter-bar') : null;
		if (!form) return;

		if (campo.closest('[data-prov-picker].is-abierto')) return;
		if (campo.tagName === 'TEXTAREA' || campo.tagName === 'A') return;
		// Los demás botones hacen lo suyo (Limpiar, Buscar); el del proveedor
		// no es un botón de acción, es el campo.
		if (campo.tagName === 'BUTTON' && !campo.matches('[data-prov-boton]')) return;

		evento.preventDefault();
		evento.stopPropagation();
		if (typeof form.requestSubmit === 'function') form.requestSubmit();
		else form.submit();
	}, true);
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

		/* La sucursal solo si el documento tiene una: sin ella el renglón
		 * entero se va, en vez de dejar el icono solo. */
		var sucursal = tarjeta.querySelector('[data-navdoc-sucursal]');
		var nombreSucursal = String(it.sucursal || '').trim();
		sucursal.hidden = nombreSucursal === '';
		if (nombreSucursal !== '') {
			sucursal.querySelector('[data-navdoc-sucursal-texto]').textContent = nombreSucursal;
			sucursal.title = 'Sucursal: ' + nombreSucursal;
		}

		tarjeta.querySelector('[data-navdoc-fecha]').textContent = it.fecha || '—';
		tarjeta.querySelector('[data-navdoc-monto]').textContent = '₡' +
			Number(it.total).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
		/* El marcador de posición. Dos números sueltos no dicen de qué lista
		 * hablan, así que el título lo deletrea: cuál de cuántos, y de dónde
		 * salió esa lista. Si la lista de origen es más larga de lo que la
		 * tarjeta se trae, se marca con un "+" en vez de dar el tope por
		 * total: decir "12 / 300" de una cola de 412 sería mentir. */
		var pos = tarjeta.querySelector('[data-navdoc-pos]');
		var origen = tarjeta.dataset.navdocTitulo || '';
		var totalOrigen = parseInt(tarjeta.dataset.navdocTotal, 10) || items.length;
		var recortada = totalOrigen > items.length;
		pos.textContent = (idx + 1) + ' / ' + items.length + (recortada ? '+' : '');
		pos.title = 'Documento ' + (idx + 1) + ' de ' + items.length
			+ (origen ? ' de ' + origen : '')
			+ (recortada
				? ' — la lista tiene ' + totalOrigen + '; las flechas llegan hasta '
				  + items.length + ', el resto se sigue desde el módulo'
				: '');

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

		/* Y los formularios de la pantalla siguen al documento visible. La
		 * barra de filtros se envía por GET y lleva el contexto escondido
		 * (partials/contexto-oculto.php); si mandara el documento con el que
		 * se entró, buscar a mano después de avanzar con las flechas
		 * devolvería la tarjeta al principio del recorrido. */
		var ocultos = document.querySelectorAll('input[type="hidden"][name="ctx_item"]');
		for (var i = 0; i < ocultos.length; i++) {
			ocultos[i].value = it.id;
		}

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

/* Volver a bajar del correo el respaldo de un documento que se perdió.
 *
 * El botón sale donde se ve el faltante —el listado de comprobantes y la
 * cola de seguimiento—, así que el comportamiento vive acá una sola vez y las
 * dos pantallas solo ponen el data-recuperar-doc. Por delegación, para que
 * valga también en los renglones que se pintan después de cargar la página.
 *
 * Quien decide si de verdad se puede reponer es el servidor: compara la huella
 * de lo que baja con la que se guardó al archivar y, si no es la misma, no
 * escribe nada. Acá no se adivina. */
(function () {
	'use strict';

	var enCurso = false;

	document.addEventListener('click', function (evento) {
		var boton = evento.target.closest ? evento.target.closest('[data-recuperar-doc]') : null;
		if (!boton || enCurso) { return; }
		evento.preventDefault();

		var id = boton.dataset.recuperarDoc;
		var base = document.body.dataset.base || '';
		var antes = boton.innerHTML;
		enCurso = true;
		boton.disabled = true;
		boton.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';

		fetch(base + '/documentos/recuperar', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: 'ids=' + encodeURIComponent(id)
		})
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (!d.ok) { throw new Error(d.message || 'No se pudo reponer el respaldo.'); }
			// Recargar y no repintar el renglón: lo que cambia con esto no es
			// solo un icono —el documento pasa a tener respaldo— y de ahí
			// cuelgan el estado, los contadores y los filtros de la pantalla.
			return window.AppDialog.alert(d.message, { title: 'Respaldo repuesto' })
				.then(function () { window.location.reload(); });
		})
		.catch(function (e) {
			boton.disabled = false;
			boton.innerHTML = antes;
			window.AppDialog.alert(e.message, { title: 'No se pudo reponer' });
		})
		.finally(function () { enCurso = false; });
	}, false);
})();

/* Subir a mano el PDF que el proveedor no adjuntó.
 *
 * Sale donde se ve que falta —el renglón del pago, el aviso de la carpeta
 * del pago y la lista de comprobantes— con data-subir-pdf. Si el servidor
 * no encuentra en el PDF la clave ni el consecutivo de la factura, pregunta
 * antes de guardarlo: es la forma de no subir el PDF de otra. */
(function () {
	'use strict';

	var enCurso = false;

	function enviar(id, archivo, forzar) {
		var datos = new FormData();
		datos.append('pdf', archivo);
		if (forzar) { datos.append('forzar', '1'); }
		var base = document.body.dataset.base || '';
		return fetch(base + '/documentos/pdf/' + encodeURIComponent(id) + '/subir', { method: 'POST', body: datos })
			.then(function (r) { return r.json(); });
	}

	document.addEventListener('click', function (evento) {
		var boton = evento.target.closest ? evento.target.closest('[data-subir-pdf]') : null;
		if (!boton || enCurso) { return; }
		evento.preventDefault();

		var id = boton.dataset.subirPdf;
		var selector = document.createElement('input');
		selector.type = 'file';
		selector.accept = 'application/pdf,.pdf';
		selector.addEventListener('change', function () {
			var archivo = selector.files && selector.files[0];
			if (!archivo) { return; }
			var antes = boton.innerHTML;
			enCurso = true;
			boton.disabled = true;
			boton.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';

			enviar(id, archivo, false)
				.then(function (d) {
					if (d.ok || !d.confirmar) { return d; }
					return window.AppDialog.confirm(d.message + '\n\n¿Subirlo igual?', {
						title: 'Revisá el PDF', confirmText: 'Sí, es de esta factura'
					}).then(function (si) {
						return si ? enviar(id, archivo, true) : { ok: false, cancelado: true };
					});
				})
				.then(function (d) {
					if (d.cancelado) { throw null; }
					if (!d.ok) { throw new Error(d.message || 'No se pudo guardar el PDF.'); }
					// Recargar: cambian el estado, el aviso de la carpeta y los contadores.
					return window.AppDialog.alert(d.message, { title: 'PDF guardado' })
						.then(function () { window.location.reload(); });
				})
				.catch(function (e) {
					boton.disabled = false;
					boton.innerHTML = antes;
					if (e) { window.AppDialog.alert(e.message, { title: 'No se pudo subir el PDF' }); }
				})
				.finally(function () { enCurso = false; });
		});
		selector.click();
	}, false);
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


/* ══════════════════════════════════════════════════════════════════════
   SELECCIÓN POR RANGO CON SHIFT

   Una lista con casillas y acciones en tanda invita a marcar veinte
   renglones seguidos, y de a un clic eso son veinte clics con el riesgo de
   saltarse uno sin notarlo. Shift es lo que todo el mundo prueba primero
   —el correo, el explorador de archivos y las hojas de cálculo lo hacen—,
   así que aquí hace lo mismo: marca desde la última casilla que se tocó
   hasta esta.

   Ctrl no necesita nada: en una lista de casillas, un clic suelto ya suma
   o quita uno sin tocar los demás, que es justo lo que Ctrl hace en las
   listas donde marcar uno desmarca el resto.

   Vive acá y no dentro de una pantalla porque son tres las listas que
   trabajan en tanda —la cola de seguimiento, la bandeja del correo y sus
   incidencias— y el comportamiento tiene que ser el mismo en las tres.
══════════════════════════════════════════════════════════════════════ */
(function () {
	'use strict';

	/**
	 * Enciende Shift + clic sobre las casillas de un contenedor.
	 *
	 * 	param {Element}  contenedor  La tabla o lista que las contiene.
	 * 	param {string}   selector    Cómo se reconoce una casilla ('.chk-fila').
	 * 	param {Function} [alCambiar] Se llama tras cada clic, para que quien
	 *                               lleva la cuenta la rehaga. Las casillas del
	 *                               tramo se marcan desde el código y eso NO
	 *                               dispara su 'change': sin este aviso, la
	 *                               barra de acciones diría "2 seleccionados"
	 *                               con veinte marcados.
	 * 	returns {{olvidarAncla: Function}|null}
	 */
	window.AppSeleccionRango = function (contenedor, selector, alCambiar) {
		if (!contenedor) { return null; }

		var ancla = null;

		function casillas() {
			return Array.prototype.slice.call(contenedor.querySelectorAll(selector));
		}

		function casillaDe(evento) {
			return evento.target && evento.target.closest
				? evento.target.closest(selector)
				: null;
		}

		/* Para el navegador, Shift + clic significa "extendé la selección de
		 * texto": sin esto media tabla queda pintada de azul en cada rango.
		 * Cancelar el mousedown corta ese arrastre y no impide que la casilla
		 * cambie —eso ocurre en el clic—; lo único que quita es el foco, que se
		 * devuelve abajo para poder seguir con el teclado. */
		contenedor.addEventListener('mousedown', function (evento) {
			if (evento.shiftKey && casillaDe(evento)) { evento.preventDefault(); }
		});

		contenedor.addEventListener('click', function (evento) {
			var casilla = casillaDe(evento);
			if (!casilla) { return; }

			var todas = casillas();
			var i = todas.indexOf(casilla);
			if (i < 0) { return; }

			/* Cuando llega el clic, la casilla YA tiene su nuevo estado: ese es el
			 * que se reparte por el tramo. Así Shift sobre una que se acaba de
			 * marcar marca el tramo entero, y sobre una que se acaba de desmarcar
			 * lo desmarca, que es lo que se espera de las dos. */
			if (evento.shiftKey && ancla !== null && ancla < todas.length && ancla !== i) {
				var desde = Math.min(ancla, i);
				var hasta = Math.max(ancla, i);
				for (var k = desde; k <= hasta; k++) {
					todas[k].checked = casilla.checked;
				}
			}

			/* El ancla es siempre la última casilla tocada, con Shift o sin él: así
			 * un segundo Shift encadena desde donde quedó la vista en vez de volver
			 * a un punto que quien está marcando ya no tiene presente. */
			ancla = i;
			casilla.focus();
			if (typeof alCambiar === 'function') { alCambiar(); }
		});

		return {
			/* Marcar o desmarcar todo de golpe deja el ancla sin sentido: el
			 * siguiente Shift se mediría contra una casilla que nadie tocó. */
			olvidarAncla: function () { ancla = null; }
		};
	};
})();

/* Visor de ficha: el documento se lee encima de donde se está.
 *
 * El ojito de los listados llevaba a /facturas/ver o /notas-xml/ver: otra
 * pestaña, otro módulo, doce datos y volver. Cuando lo que se está haciendo es
 * revisar treinta renglones contra el ERP, ese viaje se hace treinta veces y
 * cada uno pierde el sitio en el que se iba.
 *
 * Así que el mismo dato se pide en JSON —/documentos/ficha/{id}, que arma
 * FichaDocumento— y se pinta en un cuadro sobre la pantalla. Una sola vez acá
 * para los seis sitios que lo enseñan; cada pantalla solo marca su enlace:
 *
 *   <a href="..." data-ficha="123"><i class="fas fa-eye"></i></a>
 *
 * El href se queda puesto a propósito: ctrl+clic, el botón central y un
 * navegador sin JavaScript siguen abriendo la pantalla completa, que no
 * desaparece. Esto le ahorra el viaje, no la reemplaza. */
(function () {
	'use strict';

	var capa = null;
	var partes = null;
	var abierta = false;
	var focoPrevio = null;
	var fichas = {};
	var pedido = 0;

	function base() {
		return document.body.dataset.base || '';
	}

	function esc(valor) {
		return String(valor === null || valor === undefined ? '' : valor)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	function crear() {
		if (capa) { return partes; }

		capa = document.createElement('div');
		capa.className = 'ficha-capa';
		capa.setAttribute('aria-hidden', 'true');
		capa.innerHTML =
			'<section class="ficha" role="dialog" aria-modal="true" aria-labelledby="ficha-numero" tabindex="-1">' +
				'<header class="ficha-head">' +
					'<div class="ficha-head-texto">' +
						'<div class="ficha-tipo" data-ficha-tipo></div>' +
						'<h2 class="ficha-numero" id="ficha-numero" data-ficha-numero></h2>' +
					'</div>' +
					'<span class="ficha-marca" data-ficha-marca hidden></span>' +
					'<button type="button" class="ficha-cerrar" data-ficha-cerrar aria-label="Cerrar">' +
						'<i class="fas fa-xmark"></i>' +
					'</button>' +
				'</header>' +
				'<div class="ficha-cuerpo" data-ficha-cuerpo></div>' +
				'<footer class="ficha-pie">' +
					'<span class="ficha-registro" data-ficha-registro></span>' +
					'<button type="button" class="btn btn-outline btn-sm" data-ficha-cerrar>Cerrar</button>' +
				'</footer>' +
			'</section>';
		document.body.appendChild(capa);

		partes = {
			panel: capa.querySelector('.ficha'),
			tipo: capa.querySelector('[data-ficha-tipo]'),
			numero: capa.querySelector('[data-ficha-numero]'),
			marca: capa.querySelector('[data-ficha-marca]'),
			cuerpo: capa.querySelector('[data-ficha-cuerpo]'),
			registro: capa.querySelector('[data-ficha-registro]')
		};

		capa.addEventListener('click', function (evento) {
			if (evento.target === capa || (evento.target.closest && evento.target.closest('[data-ficha-cerrar]'))) {
				cerrar();
			}
		});

		return partes;
	}

	function abrir(id) {
		crear();
		if (!abierta) {
			focoPrevio = document.activeElement;
			abierta = true;
			capa.classList.add('is-abierta');
			capa.setAttribute('aria-hidden', 'false');
			document.body.classList.add('ficha-abierta');
		}
		/* El foco entra al cuadro, no a su botón de cerrar: así el lector de
		 * pantalla lee el documento y el primer Tab lleva a lo que se puede
		 * hacer con él, sin dejar un aro dibujado sobre la ✕. */
		partes.panel.focus();

		/* Lo ya leído se vuelve a enseñar sin pedirlo: en una revisión se abre
		 * el mismo documento varias veces, y esperar por algo que no cambió es
		 * lo que hace que la gente prefiera la pestaña. */
		if (fichas[id]) {
			pedido++;
			pintar(fichas[id]);
			return;
		}

		var mio = ++pedido;
		cargando();

		fetch(base() + '/documentos/ficha/' + encodeURIComponent(id), {
			headers: { 'Accept': 'application/json' }
		})
		.then(function (r) {
			return r.json().catch(function () {
				throw new Error('El servidor no contestó con la ficha del documento.');
			});
		})
		.then(function (d) {
			if (!d || !d.ok || !d.ficha) {
				throw new Error((d && d.message) || 'No se pudo leer este documento.');
			}
			fichas[id] = d.ficha;
			// Mientras llegaba pudo abrirse otra: manda la última que se pidió.
			if (mio === pedido) { pintar(d.ficha); }
		})
		.catch(function (e) {
			if (mio === pedido) { fallo(e.message, id); }
		});
	}

	function cerrar() {
		if (!abierta) { return; }
		abierta = false;
		pedido++;
		capa.classList.remove('is-abierta');
		capa.setAttribute('aria-hidden', 'true');
		document.body.classList.remove('ficha-abierta');
		if (focoPrevio && typeof focoPrevio.focus === 'function') { focoPrevio.focus(); }
		focoPrevio = null;
	}

	function cabecera(tipo, numero, marca) {
		partes.tipo.textContent = tipo;
		partes.numero.textContent = numero;
		if (marca) {
			partes.marca.hidden = false;
			partes.marca.className = 'ficha-marca is-' + marca.tono;
			partes.marca.innerHTML = '<i class="fas ' + esc(marca.icono) + '"></i> ' + esc(marca.texto);
		} else {
			partes.marca.hidden = true;
		}
	}

	function cargando() {
		cabecera('Documento', 'Leyendo…', null);
		partes.registro.textContent = '';
		partes.cuerpo.innerHTML =
			'<div class="ficha-espera"><i class="fas fa-circle-notch fa-spin"></i> Buscando el documento…</div>';
	}

	function fallo(mensaje, id) {
		cabecera('Documento', 'No se pudo abrir', null);
		partes.registro.textContent = '';
		partes.cuerpo.innerHTML =
			'<div class="ficha-fallo">' +
				'<i class="fas fa-triangle-exclamation"></i>' +
				'<div>' + esc(mensaje) +
					'<div class="ficha-fallo-salida">' +
						'<button type="button" class="btn btn-outline btn-sm" data-ficha-reintentar="' + Number(id) + '">' +
							'<i class="fas fa-rotate-right"></i> Reintentar' +
						'</button>' +
					'</div>' +
				'</div>' +
			'</div>';
	}

	function pintar(f) {
		cabecera(f.titulo, f.numero, f.estado && f.estado.resumen);
		partes.registro.textContent = 'Registro interno #' + f.id;

		var html = '<div class="ficha-cabeza">' +
			'<div class="ficha-quien">' +
				'<div class="ficha-et">Proveedor</div>' +
				'<div class="ficha-proveedor">' + (f.proveedor ? esc(f.proveedor) : '<span class="ficha-vacio">—</span>') + '</div>' +
				(f.cedula ? '<div class="ficha-cedula">Cédula ' + esc(f.cedula) + '</div>' : '') +
			'</div>' +
			'<div class="ficha-cuanto">' +
				'<div class="ficha-et">Total</div>' +
				'<div class="ficha-total">' + esc(f.simbolo) + esc(f.total) + '</div>' +
				'<div class="ficha-desglose">Subtotal ' + esc(f.subtotal) +
					' · IVA ' + esc(f.iva) + ' · ' + esc(f.moneda) + '</div>' +
			'</div>' +
		'</div>';

		html += '<div class="ficha-datos">';
		(f.campos || []).forEach(function (campo) {
			html += '<div class="ficha-dato' + (campo.mono ? ' es-mono' : '') + '">' +
				'<div class="ficha-et">' + esc(campo.etiqueta) + '</div>' +
				'<div class="ficha-valor">' +
					(campo.valor ? esc(campo.valor) : '<span class="ficha-vacio">—</span>') +
					(campo.copiar
						? '<button type="button" class="ficha-copiar" data-ficha-copiar="' + esc(campo.valor) + '"' +
						  ' title="Copiar ' + esc(campo.etiqueta.toLowerCase()) + '">' +
						  '<i class="fas fa-copy"></i></button>'
						: '') +
				'</div>' +
			'</div>';
		});
		html += '</div>';

		/* Las facturas que la nota cita en su XML. null = el XML todavía no
		 * se leyó, que no es lo mismo que no citar ninguna. La que ya está en
		 * el sistema se abre acá mismo, en su propia ficha. */
		if (f.tipo === 'NC') {
			html += '<div class="ficha-referencias"><div class="ficha-et">Facturas de referencia</div>';
			if (f.referencias === null || f.referencias === undefined) {
				html += '<div class="ficha-ref-vacio">Todavía no se leyeron las referencias de este XML.</div>';
			} else if (!f.referencias.length) {
				html += '<div class="ficha-ref-vacio">El XML no cita ninguna factura.</div>';
			} else {
				f.referencias.forEach(function (r) {
					var detalle = [r.fecha, r.tipo].filter(Boolean).join(' · ');
					html += '<div class="ficha-ref">' +
						'<div class="ficha-ref-texto">' +
							'<div class="ficha-ref-numero" title="' + esc(r.consecutivo || r.numero) + '">' +
								(r.numero ? esc(r.numero) : '<span class="ficha-vacio">sin número</span>') +
							'</div>' +
							(detalle ? '<div class="ficha-ref-detalle">' + esc(detalle) + '</div>' : '') +
							(r.razon ? '<div class="ficha-ref-razon">' + esc(r.razon) + '</div>' : '') +
						'</div>' +
						(r.documento_id > 0
							? '<button type="button" class="btn btn-outline btn-sm" data-ficha="' + Number(r.documento_id) + '">' +
							  '<i class="fas fa-eye"></i> Ver</button>'
							: '<span class="ficha-archivo-estado">no está en el sistema</span>') +
					'</div>';
				});
			}
			html += '</div>';
		}

		html += '<div class="ficha-archivos"><div class="ficha-et">Archivos</div>';
		(f.archivos || []).forEach(function (archivo) {
			html += '<div class="ficha-archivo' + (archivo.ok ? '' : ' es-ausente') + '">' +
				'<i class="fas ' + esc(archivo.icono) + ' ficha-archivo-icono"></i>' +
				'<span class="ficha-archivo-nombre" title="' + esc(archivo.nombre) + '">' +
					(archivo.nombre ? esc(archivo.nombre) : '<span class="ficha-vacio">sin ' + esc(archivo.etiqueta) + '</span>') +
				'</span>' +
				(archivo.url
					? '<a class="btn btn-outline btn-sm" href="' + esc(archivo.url) + '" target="_blank" rel="noopener"' +
					  ' data-ventana="' + esc(archivo.etiqueta) + '" data-ventana-titulo="' + esc(f.numero) + '">' +
					  '<i class="fas fa-up-right-from-square"></i> Abrir</a>'
					: '<span class="ficha-archivo-estado">' + (archivo.nombre ? 'no está en la carpeta' : 'nunca llegó') + '</span>') +
			'</div>';
		});
		/* El faltante y su arreglo, en el mismo sitio donde se descubre: es la
		 * regla del resto del sistema (ver partials/marca-archivo.php). */
		if (f.estado && f.estado.perdido) {
			html += '<div class="ficha-perdido">' +
				'<i class="fas fa-link-slash"></i>' +
				'<span>Se archivó y ya no está en el archivo de documentos.</span>' +
				(f.estado.recuperable
					? '<button type="button" class="btn btn-primary btn-sm" data-recuperar-doc="' + Number(f.id) + '">' +
					  '<i class="fas fa-cloud-arrow-down"></i> Volver a bajarlo del correo</button>'
					: '<span class="ficha-archivo-estado">no se guardó de dónde bajarlo</span>') +
			'</div>';
		}
		html += '</div>';

		partes.cuerpo.innerHTML = html;
		partes.cuerpo.scrollTop = 0;
	}

	/* Copiar la clave: son cincuenta dígitos que nadie transcribe a mano y que
	 * es justo lo que se pega en el buscador del ERP. */
	function copiar(boton) {
		var texto = boton.dataset.fichaCopiar || '';
		var listo = function () {
			boton.classList.add('es-copiado');
			boton.innerHTML = '<i class="fas fa-check"></i>';
			setTimeout(function () {
				boton.classList.remove('es-copiado');
				boton.innerHTML = '<i class="fas fa-copy"></i>';
			}, 1200);
		};

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(texto).then(listo, function () { aLaAntigua(texto, listo); });
			return;
		}
		aLaAntigua(texto, listo);
	}

	/* Sin HTTPS el portapapeles moderno no existe, y esta aplicación corre en
	 * http dentro de la red de la oficina. */
	function aLaAntigua(texto, listo) {
		var campo = document.createElement('textarea');
		campo.value = texto;
		campo.setAttribute('readonly', '');
		campo.style.position = 'fixed';
		campo.style.opacity = '0';
		document.body.appendChild(campo);
		campo.select();
		try { document.execCommand('copy'); listo(); } catch (e) { /* se queda a la vista para copiarla a mano */ }
		document.body.removeChild(campo);
	}

	document.addEventListener('click', function (evento) {
		if (!evento.target.closest) { return; }

		var copia = evento.target.closest('[data-ficha-copiar]');
		if (copia) { evento.preventDefault(); copiar(copia); return; }

		var reintento = evento.target.closest('[data-ficha-reintentar]');
		if (reintento) {
			evento.preventDefault();
			abrir(Number(reintento.dataset.fichaReintentar));
			return;
		}

		var disparo = evento.target.closest('[data-ficha]');
		if (!disparo) { return; }
		/* Lo que el navegador hace mejor se le deja: abrir aparte a propósito
		 * —ctrl, ⌘, shift, botón central— sigue llevando a la pantalla
		 * completa, que para eso el enlace conserva su href. */
		if (evento.ctrlKey || evento.metaKey || evento.shiftKey || evento.altKey || evento.button !== 0) {
			return;
		}
		var id = Number(disparo.dataset.ficha);
		if (!(id > 0)) { return; }
		evento.preventDefault();
		abrir(id);
	}, false);

	document.addEventListener('keydown', function (evento) {
		if (!abierta) { return; }
		/* Desde la ficha se puede abrir una ventana —el XML, el PDF—, y
		 * entonces la de arriba es ella: Escape la cierra a ella y deja el
		 * cuadro donde estaba. */
		if (document.querySelector('.ventana-capa.is-abierta')) { return; }
		/* Misma regla que los diálogos: mientras el cuadro está abierto, estas
		 * dos teclas son suyas. El expediente de Seguimiento también cierra
		 * con Escape y se iba con la ficha si no se corta acá. */
		if (evento.key === 'Escape' || evento.key === 'Tab') {
			evento.stopImmediatePropagation();
		}
		if (evento.key === 'Escape') {
			evento.preventDefault();
			cerrar();
			return;
		}
		if (evento.key !== 'Tab') { return; }
		var enfocables = Array.prototype.filter.call(
			partes.panel.querySelectorAll('a[href], button:not([disabled])'),
			function (el) { return el.offsetParent !== null; }
		);
		if (!enfocables.length) { return; }
		var primero = enfocables[0];
		var ultimo = enfocables[enfocables.length - 1];
		if (evento.shiftKey && document.activeElement === primero) {
			evento.preventDefault();
			ultimo.focus();
		} else if (!evento.shiftKey && document.activeElement === ultimo) {
			evento.preventDefault();
			primero.focus();
		}
	});
})();

/* Visor de ventanas: otra pantalla del sistema, encima de la que se está usando.
 *
 * "Buscar en el correo", desde la cola de seguimiento o desde el pago,
 * abría otra pestaña en otro módulo. El trabajo es de ida y vuelta —mirar el
 * renglón, ir a buscarlo, volver al siguiente— y con treinta renglones son
 * treinta pestañas y treinta vueltas a encontrar dónde se iba.
 *
 * La ventana abre esa misma pantalla, entera y funcionando, en un marco grande
 * encima de esta. No es una copia ni un resumen: es el módulo de verdad, con su
 * buscador, sus botones y su tarjeta de "documento que se busca". Lo único que
 * se le quita es el menú lateral y la barra de arriba, que ya están —y mandan—
 * en la pantalla de abajo (ver `html.en-ventana` en styles.css).
 *
 * Cada pantalla solo marca su enlace, y el href se queda puesto para que
 * ctrl+clic siga abriendo la pestaña de siempre:
 *
 *   <a href="/correo?buscar=123" data-ventana="Correo" data-ventana-titulo="FE 00012345">
 *
 *   data-ventana         qué se está abriendo; sale como rótulo pequeño
 *   data-ventana-titulo  sobre qué; sale como título (opcional)
 */
(function () {
	'use strict';

	var capa = null;
	var partes = null;
	var abierta = false;
	var focoPrevio = null;
	var cerrando = false;

	/*
	 * Una ventana puede llevar a otra pantalla: desde los comprobantes XML se
	 * pasa al correo. Antes eso abría una segunda ventana encima de la primera
	 * —dos cabeceras, dos ✕, la de abajo tapada y sin usar—; ahora la misma
	 * ventana se transforma, y estos dos guardan por dónde ha pasado para
	 * poder volver.
	 */
	var paso = null;      // el escalón que se está viendo
	var historia = [];    // los anteriores, en orden

	function crear() {
		if (capa) { return partes; }

		capa = document.createElement('div');
		capa.className = 'ventana-capa';
		capa.setAttribute('aria-hidden', 'true');
		capa.innerHTML =
			'<section class="ventana" role="dialog" aria-modal="true" aria-labelledby="ventana-titulo" tabindex="-1">' +
				'<header class="ventana-head">' +
					'<button type="button" class="ventana-atras" data-ventana-atras hidden>' +
						'<i class="fas fa-arrow-left"></i>' +
					'</button>' +
					'<div class="ventana-head-texto">' +
						'<div class="ventana-tipo" data-ventana-tipo></div>' +
						'<h2 class="ventana-titulo" id="ventana-titulo" data-ventana-rotulo></h2>' +
					'</div>' +
					'<button type="button" class="ventana-cerrar" data-ventana-cerrar aria-label="Cerrar">' +
						'<i class="fas fa-xmark"></i>' +
					'</button>' +
				'</header>' +
				'<div class="ventana-cuerpo">' +
					'<iframe class="ventana-marco" data-ventana-marco title="Otra pantalla del sistema"></iframe>' +
					'<div class="ventana-espera" data-ventana-espera>' +
						'<i class="fas fa-circle-notch fa-spin"></i> Abriendo…' +
					'</div>' +
				'</div>' +
			'</section>';
		document.body.appendChild(capa);

		partes = {
			panel: capa.querySelector('.ventana'),
			atras: capa.querySelector('[data-ventana-atras]'),
			tipo: capa.querySelector('[data-ventana-tipo]'),
			rotulo: capa.querySelector('[data-ventana-rotulo]'),
			marco: capa.querySelector('[data-ventana-marco]'),
			espera: capa.querySelector('[data-ventana-espera]')
		};

		partes.atras.addEventListener('click', volver);

		capa.addEventListener('click', function (evento) {
			if (evento.target === capa || (evento.target.closest && evento.target.closest('[data-ventana-cerrar]'))) {
				cerrar();
			}
		});

		// El marco avisa cuando termina de cargar; al cerrar lo mandamos a una
		// página en blanco y ese aviso no cuenta.
		partes.marco.addEventListener('load', function () {
			if (cerrando) { return; }
			partes.espera.hidden = true;
			// Dónde quedó el marco, que no siempre es donde se lo mandó: dentro
			// se busca, se pagina y se envían formularios. Volver tiene que
			// traer de vuelta lo último que se estaba viendo en ese escalón.
			try {
				if (paso) { paso.url = partes.marco.contentWindow.location.href; }
			} catch (e) {
				// Otro origen dentro del marco: se queda la URL con la que se abrió.
			}
		});

		return partes;
	}

	function abrir(url, tipo, titulo) {
		crear();
		historia = [];
		paso = { url: url, tipo: tipo || '', titulo: titulo || '' };
		rotular();
		partes.espera.hidden = false;
		cerrando = false;
		partes.marco.src = url;

		if (!abierta) {
			focoPrevio = document.activeElement;
			abierta = true;
			capa.classList.add('is-abierta');
			capa.setAttribute('aria-hidden', 'false');
			document.body.classList.add('ventana-abierta');
		}
		partes.panel.focus();
	}

	/** La cabecera dice en qué escalón va la ventana y a cuál se vuelve. */
	function rotular() {
		var tipo = (paso && paso.tipo) || 'Nexo Fiscal';
		var titulo = (paso && paso.titulo) || '';
		partes.tipo.textContent = tipo;
		partes.rotulo.textContent = titulo;
		partes.marco.title = tipo + (titulo ? ' · ' + titulo : '');

		var previo = historia.length ? historia[historia.length - 1] : null;
		partes.atras.hidden = previo === null;
		if (previo) {
			partes.atras.title = 'Volver a ' + (previo.tipo || 'la pantalla anterior');
			partes.atras.setAttribute('aria-label', partes.atras.title);
		}
	}

	/**
	 * Otro escalón dentro de la MISMA ventana: lo pidió un enlace de la
	 * pantalla que está dentro del marco (ver el aviso al padre, más abajo).
	 */
	function escalon(tipo, titulo) {
		if (paso) { historia.push(paso); }
		paso = {
			url: '',
			tipo: tipo || (paso ? paso.tipo : ''),
			titulo: titulo || (paso ? paso.titulo : '')
		};
		partes.espera.hidden = false;
		rotular();
	}

	/** Y el camino de vuelta, un escalón cada vez. */
	function volver() {
		var previo = historia.pop();
		if (!previo) { return; }
		paso = previo;
		cerrando = false;
		partes.espera.hidden = false;
		partes.marco.src = previo.url || 'about:blank';
		rotular();
	}

	/**
	 * ¿Lo que hubo dentro era una pantalla del sistema, o un archivo?
	 *
	 * Decide si al cerrar hay que releer esta pantalla. Dentro de un módulo se
	 * importa, se descarta y se engancha —y entonces el renglón de abajo ya no
	 * dice la verdad—; un XML o un PDF solo se miran y no cambian nada.
	 */
	function huboPantalla() {
		try {
			return !!(partes.marco.contentDocument
				&& partes.marco.contentDocument.querySelector('.app-layout'));
		} catch (e) {
			return false;
		}
	}

	function cerrar() {
		if (!abierta) { return; }
		var releer = huboPantalla();

		abierta = false;
		cerrando = true;
		capa.classList.remove('is-abierta');
		capa.setAttribute('aria-hidden', 'true');
		document.body.classList.remove('ventana-abierta');
		// A página en blanco: dentro puede haber quedado un módulo preguntando
		// al servidor cada pocos segundos —el correo sincroniza solo—, y eso no
		// puede seguir corriendo escondido detrás de la pantalla.
		partes.marco.src = 'about:blank';
		paso = null;
		historia = [];

		if (focoPrevio && typeof focoPrevio.focus === 'function') { focoPrevio.focus(); }
		focoPrevio = null;

		/* Y se relee la pantalla de abajo. Lo que se hace dentro de la ventana
		 * —importar el comprobante que faltaba, descartarlo, engancharlo— es
		 * justo lo que cambia el renglón desde el que se abrió: dejarlo como
		 * estaba es enseñar un faltante que ya se resolvió. Misma razón por la
		 * que repone el respaldo recarga en vez de repintar el renglón. */
		if (releer) { window.location.reload(); }
	}

	document.addEventListener('click', function (evento) {
		if (!evento.target.closest) { return; }
		var disparo = evento.target.closest('[data-ventana]');
		if (!disparo) { return; }
		// Lo que el navegador hace mejor se le deja: abrir aparte a propósito
		// sigue llevando a la pestaña de siempre.
		if (evento.ctrlKey || evento.metaKey || evento.shiftKey || evento.altKey || evento.button !== 0) {
			return;
		}
		var url = disparo.getAttribute('href') || disparo.dataset.ventanaUrl || '';
		if (!url || url.charAt(0) === '#') { return; }
		evento.preventDefault();

		/*
		 * Si esta pantalla ya se está viendo DENTRO de una ventana, lo pedido
		 * no abre otra encima: esta misma se convierte en la nueva pantalla, y
		 * la cabecera de arriba lo dice. La de antes queda a un clic, en la
		 * flecha de volver.
		 */
		if (document.documentElement.classList.contains('en-ventana')) {
			avisarAlPadre(disparo.dataset.ventana, disparo.dataset.ventanaTitulo);
			window.location.href = url;
			return;
		}

		abrir(url, disparo.dataset.ventana, disparo.dataset.ventanaTitulo);
	}, false);

	/** Desde dentro del marco: "me voy a otra pantalla, cambiá el rótulo". */
	function avisarAlPadre(tipo, titulo) {
		try {
			window.parent.postMessage({
				nexo: 'ventana:ir',
				tipo: String(tipo || ''),
				titulo: String(titulo || '')
			}, window.location.origin);
		} catch (e) {
			// Sin padre al que avisar, el marco navega igual: se pierde el
			// rótulo, no la pantalla.
		}
	}

	/* Y del lado de la ventana, ese aviso. Se acepta solo del marco propio y
	 * del mismo origen: es la única pantalla que tiene algo que decir aquí. */
	window.addEventListener('message', function (evento) {
		if (evento.origin !== window.location.origin) { return; }
		var dato = evento.data;
		if (!dato || dato.nexo !== 'ventana:ir') { return; }
		if (!abierta || !partes || evento.source !== partes.marco.contentWindow) { return; }
		escalon(dato.tipo, dato.titulo);
	});

	document.addEventListener('keydown', function (evento) {
		if (!abierta || evento.key !== 'Escape') { return; }
		evento.preventDefault();
		evento.stopImmediatePropagation();
		cerrar();
	});
})();


/* ═══════════════════════════════════════════════════════════════════════════
 * CUADROS QUE SE MUEVEN
 *
 * La ficha de un documento, la ventana de otro módulo y los avisos se
 * arrastran por su cabecera, como una ventana de escritorio. Hacía falta
 * porque el cuadro se abre justo encima del renglón del que habla: para
 * comparar lo que dice con lo que hay debajo había que cerrarlo, mirar y
 * volver a abrirlo. Por lo mismo se quitó el desenfoque del fondo: detrás hay
 * una pantalla que se está usando, no un telón.
 *
 * Uno solo para los tres, y delegado en el documento: los tres cuadros se
 * crean sobre la marcha y en momentos distintos, así que enganchar cada uno
 * al nacer obligaría a acordarse de esto en tres sitios.
 *
 * El desplazamiento viaja en dos variables de CSS (--movido-x, --movido-y) y
 * no en el transform entero: el transform es de la animación de entrada, y
 * pisarlo desde aquí la anularía.
 * ══════════════════════════════════════════════════════════════════════════ */
(function () {
	'use strict';

	var ASAS = '.ficha-head, .ventana-head, .app-dialog-head';
	var CUADROS = '.ficha, .ventana, .app-dialog';
	var DENTRO_DEL_ASA = 'button, a, input, select, textarea, [contenteditable]';

	/* Cuánto del cuadro tiene que quedar siempre a la vista. Sin un tope se
	 * puede empujar entero fuera de la pantalla, y desde ahí no hay cómo
	 * traerlo de vuelta ni cómo cerrarlo. */
	var VISIBLE = 130;
	var ALTO_DEL_ASA = 44;

	var movimiento = null;
	var seMovio = false;

	function numero(valor) {
		var n = parseFloat(valor);
		return isNaN(n) ? 0 : n;
	}

	function entre(valor, minimo, maximo) {
		return Math.max(minimo, Math.min(maximo, valor));
	}

	function asaDe(evento) {
		var destino = evento.target;
		if (!destino || !destino.closest) { return null; }
		// Los botones de la cabecera —cerrar, volver— siguen siendo botones.
		if (destino.closest(DENTRO_DEL_ASA)) { return null; }
		return destino.closest(ASAS);
	}

	/** Devuelve el cuadro al centro, que es donde nace. */
	function centrar(cuadro) {
		cuadro.style.removeProperty('--movido-x');
		cuadro.style.removeProperty('--movido-y');
	}

	document.addEventListener('pointerdown', function (evento) {
		seMovio = false;
		if (evento.button !== 0) { return; }

		var asa = asaDe(evento);
		var cuadro = asa && asa.closest(CUADROS);
		if (!cuadro) { return; }

		var caja = cuadro.getBoundingClientRect();
		var estilo = getComputedStyle(cuadro);
		movimiento = {
			cuadro: cuadro,
			punteroX: evento.clientX,
			punteroY: evento.clientY,
			// De dónde parte, que no tiene por qué ser el centro: el cuadro se
			// queda donde se lo dejó la última vez.
			baseX: numero(estilo.getPropertyValue('--movido-x')),
			baseY: numero(estilo.getPropertyValue('--movido-y')),
			izquierda: caja.left,
			arriba: caja.top,
			ancho: caja.width
		};

		cuadro.classList.add('se-esta-moviendo');
		/* Sin capturar el puntero, en cuanto se cruza por encima del documento
		 * que la ventana lleva dentro el arrastre se queda a medias: los
		 * eventos pasan a ser del marco y aquí no llega ninguno. */
		try { asa.setPointerCapture(evento.pointerId); } catch (e) {}
	}, true);

	document.addEventListener('pointermove', function (evento) {
		if (!movimiento) { return; }
		evento.preventDefault();

		var m = movimiento;
		var izquierda = entre(m.izquierda + (evento.clientX - m.punteroX),
			VISIBLE - m.ancho, window.innerWidth - VISIBLE);
		var arriba = entre(m.arriba + (evento.clientY - m.punteroY),
			0, window.innerHeight - ALTO_DEL_ASA);

		if (izquierda !== m.izquierda || arriba !== m.arriba) { seMovio = true; }

		m.cuadro.style.setProperty('--movido-x', (m.baseX + izquierda - m.izquierda) + 'px');
		m.cuadro.style.setProperty('--movido-y', (m.baseY + arriba - m.arriba) + 'px');
	}, true);

	function soltar() {
		if (!movimiento) { return; }
		movimiento.cuadro.classList.remove('se-esta-moviendo');
		movimiento = null;
	}
	document.addEventListener('pointerup', soltar, true);
	document.addEventListener('pointercancel', soltar, true);

	/* Un arrastre que acaba sobre el fondo deja un clic cuyo destino es la
	 * capa —el ancestro común de donde empezó y donde terminó—, y pulsar la
	 * capa es lo que cierra el cuadro. Se traga ese clic, y solo ese: mover el
	 * cuadro no puede cerrarlo.
	 *
	 * Se comprueba también en qué cayó, y no solo que se venga de un arrastre:
	 * un clic puede nacer del teclado, sin puntero que lo anuncie, y tragarse
	 * ese dejaría un botón muerto sin motivo. */
	document.addEventListener('click', function (evento) {
		if (!seMovio) { return; }
		seMovio = false;
		var destino = evento.target;
		if (!destino || !destino.matches
			|| !destino.matches('.ficha-capa, .ventana-capa, .app-dialog-layer')) {
			return;
		}
		evento.stopPropagation();
		evento.preventDefault();
	}, true);

	/* Doble clic en la cabecera: al centro otra vez. Es la salida para quien lo
	 * apartó y ya no quiere ir a buscarlo con el ratón. */
	document.addEventListener('dblclick', function (evento) {
		var asa = asaDe(evento);
		var cuadro = asa && asa.closest(CUADROS);
		if (cuadro) { centrar(cuadro); }
	});

	/* Al cambiar de tamaño la ventana del navegador todo se recoloca, y un
	 * cuadro apartado puede quedar fuera de la pantalla, donde ya no se
	 * alcanza. Vuelven al centro, que siempre se alcanza. */
	window.addEventListener('resize', function () {
		var cuadros = document.querySelectorAll(CUADROS);
		for (var i = 0; i < cuadros.length; i++) { centrar(cuadros[i]); }
	});
})();


/* ═══════════════════════════════════════════════════════════════════════════
 * CLIC DERECHO SOBRE UN RENGLÓN
 *
 * Las acciones de una fila viven en su última columna, en botones de un icono
 * cada uno: hay que leer el renglón, llevar el ratón hasta el borde derecho y
 * ahí adivinar cuál de los tres iconos es el que hace falta —o pararse encima
 * a esperar el globo de ayuda—. Con el clic derecho las mismas acciones salen
 * donde está el puntero, en una lista y con su nombre escrito.
 *
 * El menú no sabe qué hace ninguna acción, y es a propósito: no hay un
 * catálogo de opciones por módulo que mantener al día. Lee los botones que la
 * fila YA tiene dibujados y, al elegir uno, le manda el clic al botón de
 * verdad. De ahí salen tres cosas gratis: valen igual las confirmaciones, la
 * ficha y las ventanas; una pantalla nueva entra sola en cuanto tiene una
 * tabla; y lo que un usuario no tiene permitido ver tampoco está dibujado en
 * su fila, así que tampoco puede aparecer en su menú.
 * ═══════════════════════════════════════════════════════════════════════════ */
(function () {
	/* Un renglón de listado: las tablas del sistema y las tarjetas de
	 * Configuración, que son un listado con otra forma. */
	var FILAS = 'table.data-table tbody tr, table.table tbody tr, .cfg-item';

	/* Lo que se puede pulsar. Las casillas y los campos de filtro quedan fuera
	 * a propósito: eso se usa donde está, y dentro de un campo el menú que
	 * sirve es el del navegador. */
	var PULSABLES = 'a[href], button';

	/* Cajones donde una fila junta sus acciones. Lo que esté aquí dentro es una
	 * acción aunque no lleve título. */
	var CAJONES = '.cfg-item-acciones, .seg-respaldo, .pp-acciones';

	var menu = null;
	var fila = null;

	/** Cómo se llama la acción. El título es la frase que las pantallas ya
	 *  escribieron para el globo de ayuda: sirve igual de nombre.
	 *
	 *  Un botón de un icono y sin título se queda fuera del menú, y no hay más
	 *  remedio: una opción en blanco no se puede elegir. En la práctica no pasa
	 *  —todos llevan título, que es lo que enseña el globo—, pero quien añada
	 *  uno nuevo tiene aquí el porqué. */
	function etiqueta(el) {
		var texto = (el.getAttribute('title') || el.getAttribute('aria-label') || '').trim();
		if (!texto) { texto = (el.textContent || '').replace(/\s+/g, ' ').trim(); }
		if (!texto) { return ''; }
		/* Varios títulos explican además el porqué ("Buscarlo en el correo. La
		 * nota no trae número propio: se busca por proveedor…"). En el menú va
		 * la orden; la explicación se queda en el globo de la propia opción. */
		var corte = texto.indexOf('. ');
		if (corte > 0) { texto = texto.slice(0, corte); }
		return texto.length > 64 ? texto.slice(0, 63) + '…' : texto;
	}

	/* Un renglón está lleno de cosas que se pueden pulsar y no todas son una
	 * acción sobre él: el proveedor lleva a lo suyo, el lote abre el lote. Vale
	 * lo que tenga título —todos los botones de acción del sistema lo llevan,
	 * porque es lo que enseña el globo de ayuda— y lo que esté en la última
	 * columna o en un cajón de acciones, que ya es su sitio. */
	function esAccion(el) {
		if (el.getAttribute('title') || el.getAttribute('aria-label')) { return true; }
		var celda = el.closest('td');
		if (celda && celda === celda.parentNode.lastElementChild) { return true; }
		return !!el.closest(CAJONES);
	}

	function utilizable(el) {
		if (el.disabled || el.dataset.sinMenu === 'si') { return false; }
		/* Escondido por su pantalla —un botón que solo sale con la selección
		 * puesta, la mitad de un par que depende del estado— no es una acción
		 * que esta fila tenga ahora. */
		if (el.offsetParent === null && !el.getClientRects().length) { return false; }
		return esAccion(el) && etiqueta(el) !== '';
	}

	function acciones(renglon) {
		var encontradas = [];
		var vistas = {};
		Array.prototype.forEach.call(renglon.querySelectorAll(PULSABLES), function (el) {
			if (!utilizable(el)) { return; }
			var nombre = etiqueta(el);
			/* Lo mismo escrito dos veces —el chip del XML y el botón de verlo—
			 * es una sola opción en el menú. */
			if (vistas[nombre]) { return; }
			vistas[nombre] = true;
			encontradas.push({
				nombre: nombre,
				ayuda: (el.getAttribute('title') || '').trim(),
				origen: el
			});
		});
		return encontradas;
	}

	/** De qué renglón es este menú. La tabla se sigue viendo detrás, y sin esto
	 *  treinta renglones iguales no dicen sobre cuál se va a actuar. */
	function rotulo(renglon) {
		var celdas = renglon.querySelectorAll('td, .cfg-item-titulo');
		for (var i = 0; i < celdas.length; i++) {
			if (celdas[i].querySelector('input, select')) { continue; }
			var texto = (celdas[i].textContent || '').replace(/\s+/g, ' ').trim();
			if (texto.length > 1) {
				return texto.length > 40 ? texto.slice(0, 39) + '…' : texto;
			}
		}
		return '';
	}

	function opciones() {
		return menu ? Array.prototype.slice.call(menu.querySelectorAll('.menu-fila-opcion')) : [];
	}

	/** Arriba y abajo con el teclado, dando la vuelta por los dos extremos. */
	function mover(paso) {
		var lista = opciones();
		if (!lista.length) { return; }
		var donde = lista.indexOf(document.activeElement);
		var siguiente = donde < 0
			? (paso > 0 ? 0 : lista.length - 1)
			: (donde + paso + lista.length) % lista.length;
		lista[siguiente].focus();
	}

	function crear(items, titulo) {
		var caja = document.createElement('div');
		caja.className = 'menu-fila';
		caja.setAttribute('role', 'menu');
		caja.tabIndex = -1;

		if (titulo) {
			var cabecera = document.createElement('div');
			cabecera.className = 'menu-fila-titulo';
			cabecera.textContent = titulo;
			cabecera.title = titulo;
			caja.appendChild(cabecera);
		}

		items.forEach(function (accion) {
			var opcion = document.createElement('button');
			opcion.type = 'button';
			opcion.className = 'menu-fila-opcion';
			opcion.setAttribute('role', 'menuitem');
			/* El título entero, con su porqué, no se pierde: en el menú es el
			 * globo de la opción cuando dice más que su nombre. */
			if (accion.ayuda && accion.ayuda !== accion.nombre) { opcion.title = accion.ayuda; }

			var marca = document.createElement('span');
			marca.className = 'menu-fila-icono';
			var icono = accion.origen.querySelector('i');
			if (icono) { marca.appendChild(icono.cloneNode(true)); }
			opcion.appendChild(marca);

			var texto = document.createElement('span');
			texto.className = 'menu-fila-texto';
			texto.textContent = accion.nombre;
			opcion.appendChild(texto);

			opcion.addEventListener('click', function () { ejecutar(accion.origen); });
			caja.appendChild(opcion);
		});

		caja.addEventListener('keydown', function (evento) {
			if (evento.key === 'ArrowDown') { evento.preventDefault(); mover(1); }
			else if (evento.key === 'ArrowUp') { evento.preventDefault(); mover(-1); }
		});

		return caja;
	}

	/* Pegado al puntero y siempre entero dentro de la ventana: si no cabe hacia
	 * abajo o hacia la derecha se abre para el otro lado, como cualquier menú
	 * de escritorio. En la última fila de un listado largo, que es donde más se
	 * usa, esto es la diferencia entre verlo y no verlo. */
	function colocar(x, y) {
		var margen = 6;
		var ancho = menu.offsetWidth;
		var alto = menu.offsetHeight;
		var izq = (x + ancho + margen > window.innerWidth) ? x - ancho : x;
		var arriba = (y + alto + margen > window.innerHeight) ? y - alto : y;
		menu.style.left = Math.max(margen, Math.min(izq, window.innerWidth - ancho - margen)) + 'px';
		menu.style.top = Math.max(margen, Math.min(arriba, window.innerHeight - alto - margen)) + 'px';
	}

	function cerrar() {
		if (!menu) { return; }
		menu.parentNode.removeChild(menu);
		menu = null;
		if (fila) { fila.classList.remove('is-menu-abierto'); fila = null; }
	}

	function ejecutar(origen) {
		cerrar();
		/* El clic va al botón de la fila, no a una copia suya. Así la acción es
		 * exactamente la misma que si se hubiera pulsado el botón: su
		 * confirmación, su ficha, su ventana, su formulario. Este menú no tiene
		 * que saber qué hace ninguna, ni enterarse cuando cambie. */
		origen.click();
	}

	document.addEventListener('contextmenu', function (evento) {
		if (!evento.target.closest) { return; }
		if (evento.target.closest('.menu-fila')) { return; }
		/* Shift + clic derecho es la salida al menú del navegador: copiar el
		 * texto, abrir en otra pestaña, inspeccionar. */
		if (evento.shiftKey) { cerrar(); return; }
		/* Y dentro de un campo manda siempre el del navegador, que ahí sí tiene
		 * algo que ofrecer: pegar, deshacer, el corrector. */
		if (evento.target.closest('input, select, textarea, [contenteditable="true"]')) { cerrar(); return; }

		var renglon = evento.target.closest(FILAS);
		if (!renglon) { cerrar(); return; }
		var items = acciones(renglon);
		/* Un renglón sin acciones no tiene menú que enseñar: se deja el del
		 * navegador, por si lo que se quería era copiar lo que dice. */
		if (!items.length) { cerrar(); return; }

		evento.preventDefault();
		cerrar();

		fila = renglon;
		fila.classList.add('is-menu-abierto');
		menu = crear(items, rotulo(fila));
		document.body.appendChild(menu);

		/* La tecla de menú del teclado no trae puntero: entonces sale pegado a
		 * la fila, que es de lo que habla. */
		var x = evento.clientX;
		var y = evento.clientY;
		if (!x && !y) {
			var sitio = fila.getBoundingClientRect();
			x = sitio.left + 24;
			y = sitio.bottom;
		}
		colocar(x, y);

		/* El foco entra al menú, no a su primera opción: con el ratón, una
		 * opción ya marcada se lee como la que se va a ejecutar. Con el teclado
		 * la primera flecha la marca. */
		menu.focus();
	});

	document.addEventListener('pointerdown', function (evento) {
		if (!menu || !evento.target.closest) { return; }
		if (evento.target.closest('.menu-fila')) { return; }
		cerrar();
	}, true);

	document.addEventListener('keydown', function (evento) {
		if (!menu || evento.key !== 'Escape') { return; }
		/* Mientras el menú está abierto, Escape es suyo: debajo puede haber un
		 * cuadro o una ventana que también cierran con Escape, y se irían los
		 * dos de un solo golpe. */
		evento.preventDefault();
		evento.stopImmediatePropagation();
		cerrar();
	}, true);

	/* El menú va fijo a un punto de la pantalla: si lo de debajo se mueve, deja
	 * de señalar la fila con la que se abrió. */
	document.addEventListener('scroll', function (evento) {
		if (!menu) { return; }
		var donde = evento.target;
		if (donde && donde.closest && donde.closest('.menu-fila')) { return; }
		cerrar();
	}, true);
	window.addEventListener('resize', cerrar);
	window.addEventListener('blur', cerrar);
})();


/* ═══════════════════════════════════════════════════════════════════════════
 * QUÉ COLUMNAS SE VEN
 *
 * Un listado sabe de cada renglón mucho más de lo que cabe en la pantalla: la
 * cédula del proveedor, la sucursal, el archivo del que salió, la fecha en que
 * se cargó. Enseñarlo todo llena la tabla y no se lee; enseñar siempre lo
 * mismo obliga a abrir la ficha renglón por renglón para ver un dato que la
 * base ya tiene. El botón de la esquina de la tabla lo resuelve: cada quien
 * enciende lo que le hace falta hoy.
 *
 * Las columnas están TODAS dibujadas en el HTML —las apagadas también—, así
 * que encender una es quitarle una regla de CSS y no volver a pedir la página.
 * Esa regla es una sola, en una hoja de estilo, y no una clase por celda: los
 * listados que se repintan solos (Notas de crédito, Correo) borran sus <td> y
 * los vuelven a hacer, y una clase puesta a mano se perdería en cada repinte.
 *
 * Lo que quede encendido es lo que baja el Excel: el enlace de Exportar lleva
 * la lista puesta. El archivo es lo que se está viendo, que era el punto.
 *
 * El catálogo no se repite acá: se lee de las propias cabeceras de la tabla,
 * que ya lo traen del servidor (app/config/columnas.php) en su data-col. Una
 * lista de columnas escrita dos veces es una lista que un día no coincide.
 * ═══════════════════════════════════════════════════════════════════════════ */
(function () {
	/* La misma clave que usa el guion de la vista (partials/columnas.php) para
	 * aplicar la elección antes de que la página se pinte. Si cambia acá,
	 * cambia allá: son las dos mitades de lo mismo. */
	function clave(listado) { return 'columnas:' + listado; }

	var panel = null;
	var tablaAbierta = null;
	var arrastrando = null;

	function tablas() {
		return Array.prototype.slice.call(document.querySelectorAll('table[data-listado]'));
	}

	/** Las columnas de esta tabla, en su orden, leídas de la cabecera. */
	function columnas(tabla) {
		var cabeceras = tabla.querySelectorAll('thead tr:first-child th[data-col]');
		return Array.prototype.map.call(cabeceras, function (th) {
			return {
				id: th.getAttribute('data-col'),
				/* El título de la columna, no lo que haya dentro del <th>: ahí
				 * puede haber un icono de orden o un signo de filtro puesto. */
				titulo: (th.getAttribute('data-col-titulo') || th.textContent || '').replace(/\s+/g, ' ').trim(),
				defecto: th.getAttribute('data-col-defecto') !== 'no'
			};
		});
	}

	function porDefecto(tabla) {
		return columnas(tabla).filter(function (c) { return c.defecto; })
			.map(function (c) { return c.id; });
	}

	/** Lo que esta persona eligió, si eligió algo alguna vez. */
	function guardadas(listado) {
		try {
			var crudo = localStorage.getItem(clave(listado));
			if (!crudo) { return null; }
			var lista = JSON.parse(crudo);
			return Array.isArray(lista) && lista.length ? lista : null;
		} catch (e) {
			return null;
		}
	}

	/* Con la elección se guarda cuándo se hizo, con hora: una columna que se
	 * encienda de fábrica después (partials/columnas.php) es más nueva que
	 * ella y se enseña; una de antes ya estaba en el panel cuando se eligió.
	 * La hora hace falta porque una columna puede estrenarse el mismo día en
	 * que alguien ya acomodó las suyas. */
	function hoy() {
		var d = new Date();
		function dos(n) { return ('0' + n).slice(-2); }
		return d.getFullYear() + '-' + dos(d.getMonth() + 1) + '-' + dos(d.getDate())
			+ ' ' + dos(d.getHours()) + ':' + dos(d.getMinutes());
	}

	function guardar(listado, ids) {
		try {
			localStorage.setItem(clave(listado), JSON.stringify(ids));
			localStorage.setItem(clave(listado) + ':fecha', hoy());
		} catch (e) {}
	}

	function olvidar(listado) {
		try {
			localStorage.removeItem(clave(listado));
			localStorage.removeItem(clave(listado) + ':fecha');
		} catch (e) {}
	}

	/**
	 * Las columnas que pasaron a venir de fábrica después de la elección
	 * guardada, metidas en ella: cada una detrás de la que tiene antes en el
	 * catálogo, que es donde estaba el dato cuando vivía dentro de otra celda.
	 * Es la misma cuenta que hace el guion de la vista antes de pintar.
	 */
	function conNuevas(listado, elegidas) {
		var cat = (window.columnasCatalogo || {})[listado];
		if (!cat) { return elegidas; }
		var fecha = '';
		try { fecha = localStorage.getItem(clave(listado) + ':fecha') || ''; } catch (e) {}
		var lista = elegidas.slice();
		cat.orden.forEach(function (id, i) {
			var desde = cat.nuevas[id];
			if (!desde || desde <= fecha || lista.indexOf(id) >= 0) { return; }
			var lugar = 0;
			for (var j = i - 1; j >= 0; j--) {
				var k = lista.indexOf(cat.orden[j]);
				if (k >= 0) { lugar = k + 1; break; }
			}
			lista.splice(lugar, 0, id);
		});
		return lista;
	}

	/** Qué se está viendo ahora mismo: lo elegido, o lo de fábrica. */
	function visibles(tabla) {
		var listado = tabla.getAttribute('data-listado');
		var todas = columnas(tabla).map(function (c) { return c.id; });
		var elegidas = guardadas(listado);
		if (!elegidas) { return porDefecto(tabla); }
		/* Solo las que esta tabla tiene: una elección guardada hace meses puede
		 * nombrar una columna que ya no existe, y una que se inventó después
		 * no puede quedar escondida para siempre por no estar en la lista
		 * vieja —para eso el catálogo dice cuáles vienen de fábrica—. */
		var vivas = conNuevas(listado, elegidas).filter(function (id) { return todas.indexOf(id) >= 0; });
		return vivas.length ? vivas : porDefecto(tabla);
	}

	/**
	 * La hoja de estilo que esconde lo apagado. Una sola por listado, con el
	 * mismo id que pone la vista: acá se reescribe la que ya venía puesta, y
	 * así no hay un parpadeo de columnas que aparecen y se van.
	 */
	function pintar(tabla) {
		var listado = tabla.getAttribute('data-listado');
		var hoja = document.getElementById('cols-' + listado);
		if (!hoja) {
			hoja = document.createElement('style');
			hoja.id = 'cols-' + listado;
			document.head.appendChild(hoja);
		}

		var puestas = visibles(tabla);
		var apagadas = columnas(tabla)
			.map(function (c) { return c.id; })
			.filter(function (id) { return puestas.indexOf(id) < 0; });

		hoja.textContent = apagadas.length
			? '[data-listado="' + listado + '"] [data-col="'
				+ apagadas.join('"],[data-listado="' + listado + '"] [data-col="') + '"]{display:none}'
			: '';

		ordenar(tabla, puestas.concat(apagadas));
		enlacesDeExportar(listado, puestas);
	}

	/* ── El orden de las columnas ────────────────────────────────────────────
	 *
	 * Esconder una columna es una regla de CSS, pero MOVERLA no: en una tabla
	 * el orden es el orden de las celdas, y no hay forma de cambiarlo desde la
	 * hoja de estilo sin romper la alineacion entre cabecera y cuerpo. Asi que
	 * se mueven las celdas, fila por fila.
	 *
	 * Las celdas SIN data-col no se tocan y se quedan en su sitio: la de
	 * acciones, la del ancla del final, la de la casilla de seleccion. Solo se
	 * reparten entre si las que el catalogo conoce, ocupando exactamente las
	 * mismas posiciones que ya ocupaban.
	 */
	function ordenar(tabla, orden) {
		var lugar = {};
		orden.forEach(function (id, i) { lugar[id] = i; });

		var filas = Array.prototype.slice.call(tabla.querySelectorAll('thead tr, tbody tr'));
		filas.forEach(function (fila) { ordenarFila(fila, lugar); });
	}

	function ordenarFila(fila, lugar) {
		var celdas = Array.prototype.slice.call(fila.children);
		var conCol = celdas.filter(function (c) { return c.hasAttribute('data-col'); });
		if (conCol.length < 2) { return; }

		/* Una celda con un data-col que la cabecera no tiene: no se sabe donde
		 * ponerla, y adivinar seria dejar la fila descuadrada. Se deja la fila
		 * como esta. */
		for (var i = 0; i < conCol.length; i++) {
			if (!(conCol[i].getAttribute('data-col') in lugar)) { return; }
		}

		var ordenadas = conCol.slice().sort(function (a, b) {
			return lugar[a.getAttribute('data-col')] - lugar[b.getAttribute('data-col')];
		});

		var yaEsta = true;
		for (var j = 0; j < conCol.length; j++) {
			if (conCol[j] !== ordenadas[j]) { yaEsta = false; break; }
		}
		if (yaEsta) { return; }

		/* Cada celda ordenada entra en la posicion que ocupaba una de las de
		 * su clase, para que las que no llevan data-col no se muevan. */
		var posiciones = conCol.map(function (c) { return celdas.indexOf(c); });
		var nuevas = celdas.slice();
		posiciones.forEach(function (pos, k) { nuevas[pos] = ordenadas[k]; });
		nuevas.forEach(function (c) { fila.appendChild(c); });
	}

	/**
	 * Los listados que se repintan solos —Notas de credito al filtrar, Correo
	 * al listar— rehacen su <tbody> con el orden del catalogo. Se vuelve a
	 * aplicar el elegido en cuanto aparecen las filas nuevas.
	 */
	function vigilarRepintes(tabla) {
		if (!window.MutationObserver) { return; }
		var cuerpos = tabla.querySelectorAll('tbody');
		if (!cuerpos.length) { return; }

		var trabajando = false;
		var vigia = new MutationObserver(function () {
			if (trabajando) { return; }
			trabajando = true;
			try {
				var puestas = visibles(tabla);
				var todas = columnas(tabla).map(function (c) { return c.id; });
				ordenar(tabla, puestas.concat(todas.filter(function (id) {
					return puestas.indexOf(id) < 0;
				})));
			} finally {
				trabajando = false;
			}
		});
		Array.prototype.forEach.call(cuerpos, function (cuerpo) {
			vigia.observe(cuerpo, { childList: true });
		});
	}

	/**
	 * Y el Excel baja lo mismo. El enlace ya lleva los filtros puestos; acá se
	 * le añade —o se le cambia— la lista de columnas.
	 */
	function enlacesDeExportar(listado, puestas) {
		var enlaces = document.querySelectorAll('a[data-exportar="' + listado + '"]');
		Array.prototype.forEach.call(enlaces, function (enlace) {
			var href = enlace.getAttribute('href') || '';
			var partes = href.split('#');
			var base = partes[0].replace(/([?&])cols=[^&]*/g, '$1').replace(/[?&]$/, '');
			var union = base.indexOf('?') >= 0 ? '&' : '?';
			enlace.setAttribute('href',
				base.replace(/&&+/g, '&').replace(/\?&/, '?')
				+ union + 'cols=' + encodeURIComponent(puestas.join(','))
				+ (partes[1] ? '#' + partes[1] : ''));
		});
	}

	// ── El panel ────────────────────────────────────────────────────────────

	function cerrar() {
		if (!panel) { return; }
		panel.parentNode.removeChild(panel);
		panel = null;
		tablaAbierta = null;
	}

	function abrir(tabla, boton) {
		cerrar();
		tablaAbierta = tabla;

		var puestas = visibles(tabla);
		panel = document.createElement('div');
		panel.className = 'cols-panel';
		panel.setAttribute('role', 'dialog');
		panel.setAttribute('aria-label', 'Columnas del listado');

		var titulo = document.createElement('div');
		titulo.className = 'cols-titulo';
		titulo.textContent = 'Columnas';
		panel.appendChild(titulo);

		var pista = document.createElement('div');
		pista.className = 'cols-pista';
		pista.textContent = 'Arrastra para cambiar el orden.';
		panel.appendChild(pista);

		var lista = document.createElement('div');
		lista.className = 'cols-lista';

		/* En el orden elegido, no en el del catalogo: el panel es lo que dice
		 * en que orden van, asi que tiene que enseñarlo ya puesto. Las
		 * apagadas van despues, en el orden de siempre. */
		var todas = columnas(tabla);
		var porId = {};
		todas.forEach(function (c) { porId[c.id] = c; });
		var enOrden = puestas.map(function (id) { return porId[id]; }).filter(Boolean);
		todas.forEach(function (c) {
			if (puestas.indexOf(c.id) < 0) { enOrden.push(c); }
		});

		enOrden.forEach(function (col) {
			var fila = document.createElement('label');
			fila.className = 'cols-opcion';
			fila.draggable = true;

			var agarre = document.createElement('span');
			agarre.className = 'cols-agarre';
			agarre.setAttribute('aria-hidden', 'true');
			agarre.textContent = '≡';

			var casilla = document.createElement('input');
			casilla.type = 'checkbox';
			casilla.value = col.id;
			casilla.checked = puestas.indexOf(col.id) >= 0;
			casilla.addEventListener('change', function () { cambiar(tabla); });

			var texto = document.createElement('span');
			texto.className = 'cols-nombre';
			texto.textContent = col.titulo || col.id;

			fila.appendChild(agarre);
			fila.appendChild(casilla);
			fila.appendChild(texto);
			arrastrable(fila, lista, tabla);
			lista.appendChild(fila);
		});
		panel.appendChild(lista);

		var pie = document.createElement('div');
		pie.className = 'cols-pie';
		pie.appendChild(boton_('Todas', function () { marcarTodas(tabla, true); }));
		pie.appendChild(boton_('Como estaba', function () {
			olvidar(tabla.getAttribute('data-listado'));
			pintar(tabla);
			refrescarCasillas(tabla);
		}));
		panel.appendChild(pie);

		document.body.appendChild(panel);
		colocar(boton);
		panel.querySelector('input').focus();
	}

	/**
	 * Arrastrar una fila del panel para cambiar el orden de esa columna.
	 *
	 * Con la API del navegador y no con raton a mano: es la que ya sabe de
	 * pantallas tactiles, de teclas de cancelar y de soltar fuera del panel.
	 * Al soltar se guarda, que es lo mismo que hace marcar una casilla —el
	 * orden del panel ES el orden que se guarda—, asi que no hace falta
	 * confirmar nada.
	 */
	function arrastrable(fila, lista, tabla) {
		fila.addEventListener('dragstart', function (evento) {
			arrastrando = fila;
			fila.classList.add('cols-arrastrando');
			try {
				evento.dataTransfer.effectAllowed = 'move';
				/* Firefox no empieza el arrastre sin algo puesto aqui. */
				evento.dataTransfer.setData('text/plain', fila.querySelector('input').value);
			} catch (e) {}
		});

		fila.addEventListener('dragend', function () {
			fila.classList.remove('cols-arrastrando');
			arrastrando = null;
			cambiar(tabla);
		});

		fila.addEventListener('dragover', function (evento) {
			if (!arrastrando || arrastrando === fila) { return; }
			evento.preventDefault();
			try { evento.dataTransfer.dropEffect = 'move'; } catch (e) {}

			/* Por encima de la mitad va antes; por debajo, despues. Sin esto
			 * la fila salta de sitio en cuanto el puntero la roza. */
			var caja = fila.getBoundingClientRect();
			var antes = (evento.clientY - caja.top) < (caja.height / 2);
			lista.insertBefore(arrastrando, antes ? fila : fila.nextSibling);
		});

		fila.addEventListener('drop', function (evento) { evento.preventDefault(); });
	}

	function boton_(texto, alPulsar) {
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'cols-accion';
		b.textContent = texto;
		b.addEventListener('click', alPulsar);
		return b;
	}

	/** Debajo del botón y pegado a su derecha, sin salirse de la ventana. */
	function colocar(boton) {
		var sitio = boton.getBoundingClientRect();
		var margen = 6;
		var ancho = panel.offsetWidth;
		var alto = panel.offsetHeight;

		var izq = Math.min(sitio.right - ancho, window.innerWidth - ancho - margen);
		var arriba = sitio.bottom + 4;
		if (arriba + alto + margen > window.innerHeight) {
			arriba = Math.max(margen, sitio.top - alto - 4);
		}
		panel.style.left = Math.max(margen, izq) + 'px';
		panel.style.top = arriba + 'px';
	}

	function casillas() {
		return panel ? Array.prototype.slice.call(panel.querySelectorAll('input[type=checkbox]')) : [];
	}

	function cambiar(tabla) {
		var marcadas = casillas().filter(function (c) { return c.checked; });
		/* Una tabla sin columnas no es una tabla. La última encendida no se
		 * puede apagar: se vuelve a encender y ahí se ve por qué. */
		if (!marcadas.length) {
			var ultima = casillas()[0];
			ultima.checked = true;
			ultima.closest('.cols-opcion').classList.add('cols-rebote');
			setTimeout(function () {
				var fila = ultima.closest('.cols-opcion');
				if (fila) { fila.classList.remove('cols-rebote'); }
			}, 400);
			return;
		}
		guardar(tabla.getAttribute('data-listado'), marcadas.map(function (c) { return c.value; }));
		pintar(tabla);
	}

	function marcarTodas(tabla, si) {
		casillas().forEach(function (c) { c.checked = si; });
		cambiar(tabla);
	}

	function refrescarCasillas(tabla) {
		var puestas = visibles(tabla);
		casillas().forEach(function (c) { c.checked = puestas.indexOf(c.value) >= 0; });
	}

	// ── El botón de la esquina ──────────────────────────────────────────────

	/**
	 * Va en la última celda de la cabecera —la de las acciones, que está
	 * vacía— o en la que la vista marque. Es la esquina de la tabla: el sitio
	 * donde se busca esto en cualquier programa que lo tenga.
	 */
	function ponerBoton(tabla) {
		if (tabla.getAttribute('data-cols-listo') === 'si') { return; }
		if (!columnas(tabla).length) { return; }

		var celda = tabla.querySelector('thead th[data-columnas-boton]')
			|| tabla.querySelector('thead tr:first-child th:last-child');
		if (!celda) { return; }

		var boton = document.createElement('button');
		boton.type = 'button';
		boton.className = 'cols-boton';
		boton.title = 'Elegir qué columnas se ven';
		boton.setAttribute('aria-label', 'Elegir qué columnas se ven');
		boton.innerHTML = '<i class="fas fa-table-columns"></i>';
		boton.addEventListener('click', function (evento) {
			evento.stopPropagation();
			if (panel && tablaAbierta === tabla) { cerrar(); return; }
			abrir(tabla, boton);
		});

		celda.appendChild(boton);
		tabla.setAttribute('data-cols-listo', 'si');
	}

	/*
	 * Volver a poner las columnas en los enlaces de Exportar.
	 *
	 * Lo necesita el listado de Notas de crédito, que filtra en vivo y rehace
	 * el enlace entero cada vez que alguien escribe: al rehacerlo se lleva por
	 * delante la lista de columnas, y sin esto el Excel bajaría las de fábrica
	 * en cuanto se tocara un filtro.
	 */
	window.ColumnasEnExportar = function () {
		tablas().forEach(function (tabla) {
			enlacesDeExportar(tabla.getAttribute('data-listado'), visibles(tabla));
		});
	};

	function arrancar() {
		tablas().forEach(function (tabla) {
			ponerBoton(tabla);
			/* Y se repinta aunque la vista ya lo hubiera hecho: así el enlace
			 * de Exportar sale con las columnas puestas desde el principio, y
			 * no solo después de tocar el botón. */
			pintar(tabla);
			vigilarRepintes(tabla);
		});
	}

	document.addEventListener('pointerdown', function (evento) {
		if (!panel || !evento.target.closest) { return; }
		if (evento.target.closest('.cols-panel, .cols-boton')) { return; }
		cerrar();
	}, true);

	document.addEventListener('keydown', function (evento) {
		if (!panel || evento.key !== 'Escape') { return; }
		evento.preventDefault();
		evento.stopImmediatePropagation();
		cerrar();
	}, true);

	window.addEventListener('resize', cerrar);

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', arrancar);
	} else {
		arrancar();
	}
})();
