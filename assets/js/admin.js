(function () {
	'use strict';

	// =========================================================
	// 1. Navegación por pestañas sin recarga de página
	// =========================================================
	var tabLinks  = document.querySelectorAll('.aiea-tab-link');
	var tabPanels = document.querySelectorAll('.aiea-tab-panel');
	var TAB_STORAGE_KEY = 'aiea_admin_active_tab';

	function activateTab(tabKey, updateHistory) {
		var found = false;
		tabLinks.forEach(function (link) {
			var isMatch = link.getAttribute('data-tab') === tabKey;
			if (isMatch) {
				found = true;
			}
		});
		if (!found) {
			return;
		}

		tabLinks.forEach(function (link) {
			var isMatch = link.getAttribute('data-tab') === tabKey;
			link.classList.toggle('nav-tab-active', isMatch);
			link.setAttribute('aria-selected', isMatch ? 'true' : 'false');
		});

		tabPanels.forEach(function (panel) {
			var isMatch = panel.getAttribute('data-tab-panel') === tabKey;
			panel.classList.toggle('is-active', isMatch);
			panel.hidden = !isMatch;
		});

		try {
			window.sessionStorage.setItem(TAB_STORAGE_KEY, tabKey);
		} catch (e) {
			// Ignorar errores de almacenamiento.
		}

		if (updateHistory && window.history && window.history.replaceState) {
			try {
				var url = new URL(window.location.href);
				url.searchParams.set('tab', tabKey);
				window.history.replaceState({}, '', url.toString());
			} catch (e) {
				// Ignorar si URL API no está disponible.
			}
		}
	}

	if (tabLinks.length > 0) {
		tabLinks.forEach(function (link) {
			link.addEventListener('click', function (ev) {
				var tabKey = link.getAttribute('data-tab');
				if (tabKey) {
					ev.preventDefault();
					activateTab(tabKey, true);
				}
			});
		});

		// Restaurar la pestaña activa tras guardar cambios en options.php si no viene ?tab= en la URL.
		try {
			var currentUrl = new URL(window.location.href);
			if (!currentUrl.searchParams.get('tab')) {
				var savedTab = window.sessionStorage.getItem(TAB_STORAGE_KEY);
				if (savedTab) {
					activateTab(savedTab, true);
				}
			}
		} catch (e) {
			// Ignorar errores.
		}
	}

	// =========================================================
	// 2. Gestor interactivo de Preguntas Frecuentes (FAQ)
	// =========================================================
	var faqList       = document.getElementById('aiea-faq-list');
	var faqAddBtn     = document.getElementById('aiea-faq-add-btn');
	var faqCancelBtn  = document.getElementById('aiea-faq-cancel-btn');
	var faqEditorId   = document.getElementById('aiea-faq-editor-id');
	var faqEditorQ    = document.getElementById('aiea-faq-editor-question');
	var faqEditorA    = document.getElementById('aiea-faq-editor-answer');
	var faqEditorAct  = document.getElementById('aiea-faq-editor-active');
	var faqEditorTit  = document.getElementById('aiea-faq-editor-title');
	var faqSearch     = document.getElementById('aiea-faq-search');
	var faqCountEl    = document.getElementById('aiea-faq-count');

	function getFaqItems() {
		if (!faqList) return [];
		return Array.prototype.slice.call(faqList.querySelectorAll('.aiea-faq-item'));
	}

	function reindexFaqs() {
		if (!faqList) return;
		var optName = faqList.getAttribute('data-option-name') || 'aiea_faq_items';
		var items   = getFaqItems();
		var emptyEl = document.getElementById('aiea-faq-empty');

		if (items.length === 0) {
			if (!emptyEl) {
				var p = document.createElement('p');
				p.id = 'aiea-faq-empty';
				p.className = 'aiea-faq-empty';
				p.textContent = 'Todavía no hay preguntas frecuentes configuradas. Usa el formulario superior para crear la primera.';
				faqList.appendChild(p);
			}
		} else if (emptyEl && emptyEl.parentNode) {
			emptyEl.parentNode.removeChild(emptyEl);
		}

		items.forEach(function (item, idx) {
			var orderNum = idx + 1;
			var badge    = item.querySelector('.aiea-faq-order-badge');
			var idIn     = item.querySelector('.aiea-faq-input-id');
			var orderIn  = item.querySelector('.aiea-faq-input-order');
			var qIn      = item.querySelector('.aiea-faq-input-question');
			var aIn      = item.querySelector('.aiea-faq-input-answer');
			var actIn    = item.querySelector('.aiea-faq-input-active');

			if (badge) badge.textContent = '#' + orderNum;
			if (idIn) idIn.name = optName + '[' + idx + '][id]';
			if (orderIn) {
				orderIn.name  = optName + '[' + idx + '][order]';
				orderIn.value = String(orderNum);
			}
			if (qIn) qIn.name = optName + '[' + idx + '][question]';
			if (aIn) aIn.name = optName + '[' + idx + '][answer]';
			if (actIn) actIn.name = optName + '[' + idx + '][active]';
		});

		if (faqCountEl) {
			faqCountEl.textContent = items.length + (items.length === 1 ? ' pregunta registrada' : ' preguntas registradas');
		}
	}

	function resetFaqEditor() {
		if (!faqEditorId || !faqEditorQ || !faqEditorA || !faqEditorAct) return;
		faqEditorId.value     = '';
		faqEditorQ.value      = '';
		faqEditorA.value      = '';
		faqEditorAct.checked  = true;
		if (faqEditorTit) faqEditorTit.textContent = 'Añadir nueva pregunta frecuente';
		if (faqAddBtn) faqAddBtn.textContent = 'Añadir a la lista';
		if (faqCancelBtn) faqCancelBtn.hidden = true;
	}

	function createFaqItemNode(id, question, answer, active) {
		var wrapper = document.createElement('div');
		wrapper.className = 'aiea-faq-item' + (active ? '' : ' is-inactive');
		wrapper.setAttribute('data-faq-id', id);

		var idInput = document.createElement('input');
		idInput.type = 'hidden';
		idInput.className = 'aiea-faq-input-id';
		idInput.value = id;
		wrapper.appendChild(idInput);

		var orderInput = document.createElement('input');
		orderInput.type = 'hidden';
		orderInput.className = 'aiea-faq-input-order';
		orderInput.value = '1';
		wrapper.appendChild(orderInput);

		var header = document.createElement('div');
		header.className = 'aiea-faq-item-header';

		var badge = document.createElement('span');
		badge.className = 'aiea-faq-order-badge';
		badge.textContent = '#1';
		header.appendChild(badge);

		var qInput = document.createElement('input');
		qInput.type = 'text';
		qInput.className = 'aiea-faq-input-question large-text';
		qInput.value = question;
		header.appendChild(qInput);

		var toggleLabel = document.createElement('label');
		toggleLabel.className = 'aiea-faq-active-toggle';

		var actInput = document.createElement('input');
		actInput.type = 'checkbox';
		actInput.className = 'aiea-faq-input-active';
		actInput.value = '1';
		actInput.checked = !!active;
		toggleLabel.appendChild(actInput);

		var actSpan = document.createElement('span');
		actSpan.textContent = 'Activa';
		toggleLabel.appendChild(actSpan);
		header.appendChild(toggleLabel);
		wrapper.appendChild(header);

		var body = document.createElement('div');
		body.className = 'aiea-faq-item-body';

		var aTextarea = document.createElement('textarea');
		aTextarea.className = 'aiea-faq-input-answer large-text';
		aTextarea.rows = 2;
		aTextarea.value = answer;
		body.appendChild(aTextarea);
		wrapper.appendChild(body);

		var footer = document.createElement('div');
		footer.className = 'aiea-faq-item-footer';

		var btnUp = document.createElement('button');
		btnUp.type = 'button';
		btnUp.className = 'button button-small aiea-faq-move-up';
		btnUp.textContent = '↑ Subir';
		footer.appendChild(btnUp);

		var btnDown = document.createElement('button');
		btnDown.type = 'button';
		btnDown.className = 'button button-small aiea-faq-move-down';
		btnDown.textContent = '↓ Bajar';
		footer.appendChild(btnDown);

		var btnEdit = document.createElement('button');
		btnEdit.type = 'button';
		btnEdit.className = 'button button-small aiea-faq-edit-focus';
		btnEdit.textContent = 'Editar arriba';
		footer.appendChild(btnEdit);

		var btnDel = document.createElement('button');
		btnDel.type = 'button';
		btnDel.className = 'button button-small button-link-delete aiea-faq-delete';
		btnDel.textContent = 'Eliminar';
		footer.appendChild(btnDel);

		wrapper.appendChild(footer);
		return wrapper;
	}

	if (faqList) {
		if (faqAddBtn) {
			faqAddBtn.addEventListener('click', function () {
				var q = faqEditorQ ? faqEditorQ.value.trim() : '';
				var a = faqEditorA ? faqEditorA.value.trim() : '';
				var act = faqEditorAct ? faqEditorAct.checked : true;
				var editingId = faqEditorId ? faqEditorId.value.trim() : '';

				if (!q || !a) {
					window.alert('Por favor, escribe tanto la pregunta como la respuesta oficial.');
					return;
				}

				if (editingId) {
					var existing = faqList.querySelector('.aiea-faq-item[data-faq-id="' + editingId + '"]');
					if (existing) {
						var qIn   = existing.querySelector('.aiea-faq-input-question');
						var aIn   = existing.querySelector('.aiea-faq-input-answer');
						var actIn = existing.querySelector('.aiea-faq-input-active');
						if (qIn) qIn.value = q;
						if (aIn) aIn.value = a;
						if (actIn) actIn.checked = act;
						existing.classList.toggle('is-inactive', !act);
					}
				} else {
					var newId = 'faq_' + Date.now().toString(36) + '_' + Math.random().toString(36).substring(2, 6);
					var node  = createFaqItemNode(newId, q, a, act);
					faqList.appendChild(node);
					reindexFaqs();
				}

				resetFaqEditor();
			});
		}

		if (faqCancelBtn) {
			faqCancelBtn.addEventListener('click', function () {
				resetFaqEditor();
			});
		}

		faqList.addEventListener('change', function (ev) {
			var target = ev.target;
			if (target && target.classList.contains('aiea-faq-input-active')) {
				var item = target.closest('.aiea-faq-item');
				if (item) {
					item.classList.toggle('is-inactive', !target.checked);
				}
			}
		});

		faqList.addEventListener('click', function (ev) {
			var target = ev.target;
			if (!target) return;

			var item = target.closest('.aiea-faq-item');
			if (!item) return;

			if (target.classList.contains('aiea-faq-move-up')) {
				var prev = item.previousElementSibling;
				if (prev && prev.classList.contains('aiea-faq-item')) {
					faqList.insertBefore(item, prev);
					reindexFaqs();
				}
			} else if (target.classList.contains('aiea-faq-move-down')) {
				var next = item.nextElementSibling;
				if (next && next.classList.contains('aiea-faq-item')) {
					faqList.insertBefore(next, item);
					reindexFaqs();
				}
			} else if (target.classList.contains('aiea-faq-delete')) {
				item.parentNode.removeChild(item);
				reindexFaqs();
			} else if (target.classList.contains('aiea-faq-edit-focus')) {
				var idVal = item.getAttribute('data-faq-id') || '';
				var qIn   = item.querySelector('.aiea-faq-input-question');
				var aIn   = item.querySelector('.aiea-faq-input-answer');
				var actIn = item.querySelector('.aiea-faq-input-active');

				if (faqEditorId) faqEditorId.value = idVal;
				if (faqEditorQ && qIn) faqEditorQ.value = qIn.value;
				if (faqEditorA && aIn) faqEditorA.value = aIn.value;
				if (faqEditorAct && actIn) faqEditorAct.checked = actIn.checked;
				if (faqEditorTit) faqEditorTit.textContent = 'Editando pregunta frecuente';
				if (faqAddBtn) faqAddBtn.textContent = 'Actualizar en la lista';
				if (faqCancelBtn) faqCancelBtn.hidden = false;
				if (faqEditorQ) faqEditorQ.focus();
			}
		});

		if (faqSearch) {
			faqSearch.addEventListener('input', function () {
				var query = faqSearch.value.trim().toLowerCase();
				getFaqItems().forEach(function (item) {
					var qIn  = item.querySelector('.aiea-faq-input-question');
					var aIn  = item.querySelector('.aiea-faq-input-answer');
					var text = ((qIn ? qIn.value : '') + ' ' + (aIn ? aIn.value : '')).toLowerCase();
					item.hidden = query !== '' && text.indexOf(query) === -1;
				});
			});
		}
	}

	// =========================================================
	// 3. Sincronización de selectores de color y restaurar apariencia
	// =========================================================
	var colorPickers = document.querySelectorAll('.aiea-color-picker');
	colorPickers.forEach(function (picker) {
		var targetId  = picker.getAttribute('data-target');
		var hexInput  = targetId ? document.getElementById(targetId) : null;
		if (!hexInput) return;

		picker.addEventListener('input', function () {
			hexInput.value = picker.value.toLowerCase();
		});

		hexInput.addEventListener('input', function () {
			var val = hexInput.value.trim();
			if (/^#[0-9a-fA-F]{6}$/.test(val)) {
				picker.value = val.toLowerCase();
			}
		});
	});

	var resetAppBtn = document.getElementById('aiea-reset-appearance');
	if (resetAppBtn && typeof AIEAAdmin !== 'undefined' && AIEAAdmin.appearanceDefaults) {
		resetAppBtn.addEventListener('click', function () {
			var defs = AIEAAdmin.appearanceDefaults;
			Object.keys(defs).forEach(function (key) {
				var field = document.querySelector('[name="aiea_appearance[' + key + ']"]');
				if (field) {
					field.value = defs[key];
				}
				var picker = document.querySelector('.aiea-color-picker[data-target="aiea-color-' + key + '"]');
				if (picker && typeof defs[key] === 'string' && /^#[0-9a-fA-F]{6}$/.test(defs[key])) {
					picker.value = defs[key];
				}
			});
		});
	}

	// =========================================================
	// 4. Panel administrativo de prueba de conexión con OpenAI
	// =========================================================
	var button = document.getElementById('aiea-test-send');
	if (!button || typeof AIEAAdmin === 'undefined') return;
	var field  = document.getElementById('aiea-test-message');
	var status = document.getElementById('aiea-test-status');
	var result = document.getElementById('aiea-test-result');

	var sessionId = '';

	button.addEventListener('click', function () {
		var message = field.value.trim();
		result.hidden = true;
		result.textContent = '';

		if (!message) {
			status.textContent = AIEAAdmin.strings.empty;
			status.className   = 'aiea-test-status is-error';
			return;
		}

		button.disabled    = true;
		status.textContent = AIEAAdmin.strings.loading;
		status.className   = 'aiea-test-status is-loading';

		fetch(AIEAAdmin.restUrl, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': AIEAAdmin.nonce },
			body:        JSON.stringify({ message: message, session_id: sessionId })
		})
		.then(function (response) {
			return response.json().then(function (data) {
				if (!response.ok) throw new Error(data && data.message ? data.message : AIEAAdmin.strings.error);
				return data;
			});
		})
		.then(function (data) {
			if (data.session_id) {
				sessionId = data.session_id;
			}
			status.textContent = 'Conexión correcta · Modelo: ' + (data.model || 'configurado');
			status.className   = 'aiea-test-status is-success';
			result.textContent = data.message || '';
			result.hidden      = false;
		})
		.catch(function (error) {
			status.textContent = error.message || AIEAAdmin.strings.error;
			status.className   = 'aiea-test-status is-error';
		})
		.finally(function () { button.disabled = false; });
	});
}());
