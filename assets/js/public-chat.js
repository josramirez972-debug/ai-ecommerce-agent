(function () {
	'use strict';

	if (typeof AIEAPublic === 'undefined') {
		return;
	}

	var widget    = document.getElementById('aiea-chat-widget');
	var win       = document.getElementById('aiea-chat-window');
	var toggleBtn = document.getElementById('aiea-chat-toggle');
	var closeBtn  = document.getElementById('aiea-chat-close');
	var msgList   = document.getElementById('aiea-chat-messages');
	var typingEl  = document.getElementById('aiea-chat-typing');
	var noticeEl  = document.getElementById('aiea-chat-notice');
	var form      = document.getElementById('aiea-chat-form');
	var input     = document.getElementById('aiea-chat-input');
	var sendBtn   = document.getElementById('aiea-chat-send');

	if (!widget || !win || !toggleBtn || !msgList || !form || !input || !sendBtn) {
		return;
	}

	var STORAGE_KEY_SESSION = 'aiea_session_id';
	var STORAGE_KEY_HISTORY = 'aiea_chat_history';
	var STORAGE_KEY_OPEN    = 'aiea_chat_open';
	var MAX_STORED_ITEMS    = 20;

	var memorySessionId = '';
	var isSending       = false;
	var maxLength       = parseInt(AIEAPublic.maxLength, 10) || 500;
	var strings         = AIEAPublic.strings || {};

	function toSameOriginUrl(url, fallback) {
		if (!url || typeof url !== 'string') {
			return fallback || '';
		}
		try {
			var parsed = new URL(url, window.location.href);
			if (window.location && window.location.origin) {
				return window.location.origin + parsed.pathname + parsed.search;
			}
			return parsed.href;
		} catch (e) {
			return url || fallback || '';
		}
	}

	function getStoredItem(key) {
		try {
			return window.sessionStorage.getItem(key) || '';
		} catch (e) {
			return '';
		}
	}

	function setStoredItem(key, value) {
		try {
			window.sessionStorage.setItem(key, value);
		} catch (e) {
			// Ignorar si sessionStorage no está disponible en modo privado estricto.
		}
	}

	function removeStoredItem(key) {
		try {
			window.sessionStorage.removeItem(key);
		} catch (e) {
			// Ignorar errores de almacenamiento.
		}
	}

	function getSessionId() {
		var stored = getStoredItem(STORAGE_KEY_SESSION);
		return stored || memorySessionId;
	}

	function setSessionId(id) {
		memorySessionId = id || '';
		if (id) {
			setStoredItem(STORAGE_KEY_SESSION, id);
		} else {
			removeStoredItem(STORAGE_KEY_SESSION);
		}
	}

	function getSavedHistory() {
		var raw = getStoredItem(STORAGE_KEY_HISTORY);
		if (!raw) {
			return [];
		}
		try {
			var parsed = JSON.parse(raw);
			return Array.isArray(parsed) ? parsed : [];
		} catch (e) {
			return [];
		}
	}

	function renderSafeMessageContent(container, rawText) {
		if (!rawText) {
			container.textContent = '';
			return;
		}

		var text = String(rawText);

		// 1. Escapar caracteres HTML para prevenir inyección XSS
		var escaped = text
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');

		// 2. Parsear enlaces Markdown [etiqueta](url) exclusivamente con esquemas http y https
		var parsed = escaped.replace(/\[([^\]]+)\]\((https?:\/\/[^\s\)\"\']+)\)/gi, function (match, label, url) {
			return '<a href="' + url + '" target="_blank" rel="noopener noreferrer" class="aiea-chat-link">' + label + '</a>';
		});

		// 3. Convertir saltos de línea \n en <br>
		parsed = parsed.replace(/\r?\n/g, '<br>');

		container.innerHTML = parsed;
	}

	function handleAddToCartClick(btn) {
		var productId = parseInt(btn.getAttribute('data-product-id'), 10);
		var productName = btn.getAttribute('data-product-name') || 'el producto';
		if (!productId) {
			return;
		}

		btn.disabled = true;
		var originalText = btn.textContent;
		btn.textContent = 'Añadiendo…';
		btn.classList.add('aiea-card-btn--loading');

		var addUrl = toSameOriginUrl(AIEAPublic.addToCartUrl, '/wp-json/aiea/v1/cart/add');

		function sendAddRequest(useNonce) {
			var headers = {
				'Content-Type': 'application/json'
			};
			if (useNonce && AIEAPublic.nonce) {
				headers['X-WP-Nonce'] = AIEAPublic.nonce;
			}

			return fetch(addUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: headers,
				body: JSON.stringify({
					product_id: productId,
					id: productId,
					quantity: 1
				})
			}).then(function (res) {
				return res.json().catch(function () { return {}; }).then(function (data) {
					return {
						ok: res.ok,
						status: res.status,
						data: data || {}
					};
				});
			});
		}

		sendAddRequest(true)
			.then(function (result) {
				if (!result.ok && result.status === 403 && result.data && result.data.code === 'rest_cookie_invalid_nonce') {
					AIEAPublic.nonce = '';
					return sendAddRequest(false);
				}
				return result;
			})
			.then(function (result) {
				btn.classList.remove('aiea-card-btn--loading');
				var resData = result.data;

				if (result.ok && resData && resData.success) {
					btn.textContent = '¡Añadido!';
					btn.classList.add('aiea-card-btn--success');
					setTimeout(function () {
						btn.disabled = false;
						btn.textContent = originalText;
						btn.classList.remove('aiea-card-btn--success');
					}, 2500);

					try {
						if (resData.cart) {
							notifyWooCommerceCartUpdated(resData.cart);
						}

						var cartUrl = resData.url_carrito || '';
						var checkoutUrl = resData.url_checkout || '';
						var msg = '¡Listo! Añadí 1x ' + productName + ' a tu carrito.';
						var links = [];
						if (cartUrl) {
							links.push('[Ver Carrito](' + cartUrl + ')');
						}
						if (checkoutUrl) {
							links.push('[Finalizar Compra](' + checkoutUrl + ')');
						}
						if (links.length > 0) {
							msg += ' ' + links.join(' | ');
						}

						appendMessageElement('assistant', msg);
						saveTurnToLocalHistory('', msg);
					} catch (e) {
						// Ignorar error de renderizado secundario
					}
				} else {
					btn.disabled = false;
					btn.textContent = originalText;
					var errMsg = (resData && (resData.mensaje || resData.message)) || 'No se pudo añadir el producto al carrito. Por favor, verifica la disponibilidad e inténtalo de nuevo.';
					appendMessageElement('assistant', errMsg);
				}
			})
			.catch(function () {
				btn.classList.remove('aiea-card-btn--loading');
				btn.disabled = false;
				btn.textContent = originalText;
				appendMessageElement('assistant', 'Hubo un error de conexión al añadir al carrito. Por favor, inténtalo de nuevo.');
			});
	}

	function renderProductCards(products) {
		if (!Array.isArray(products) || products.length === 0) {
			return null;
		}

		var container = document.createElement('div');
		container.className = 'aiea-product-cards-container';

		for (var i = 0; i < products.length; i++) {
			var p = products[i];
			if (!p || typeof p !== 'object' || !p.id) {
				continue;
			}

			var card = document.createElement('div');
			card.className = 'aiea-product-card';

			// 1. Imagen o placeholder
			var imgWrap = document.createElement('div');
			imgWrap.className = 'aiea-product-card-image';
			if (p.imagen && typeof p.imagen === 'string' && /^https?:\/\//i.test(p.imagen)) {
				var img = document.createElement('img');
				img.src = p.imagen;
				img.alt = p.nombre || 'Producto';
				img.loading = 'lazy';
				imgWrap.appendChild(img);
			} else {
				var placeholder = document.createElement('div');
				placeholder.className = 'aiea-product-card-placeholder';
				placeholder.innerHTML = '<svg viewBox="0 0 24 24"><path d="M21 16.5c0 .38-.21.71-.53.88l-7.9 4.44c-.16.12-.36.18-.57.18s-.41-.06-.57-.18l-7.9-4.44A.991.991 0 013 16.5v-9c0-.38.21-.71.53-.88l7.9-4.44c.16-.12.36-.18.57-.18s.41.06.57.18l7.9 4.44c.32.17.53.5.53.88v9z"/></svg>';
				imgWrap.appendChild(placeholder);
			}
			card.appendChild(imgWrap);

			// 2. Cuerpo (Título, Precios, Stock)
			var body = document.createElement('div');
			body.className = 'aiea-product-card-body';

			var title = document.createElement('h4');
			title.className = 'aiea-product-card-title';
			title.textContent = p.nombre || 'Producto';
			body.appendChild(title);

			var priceWrap = document.createElement('div');
			priceWrap.className = 'aiea-product-card-price';
			if (p.en_oferta && p.precio_oferta && p.precio_regular) {
				var saleSpan = document.createElement('span');
				saleSpan.className = 'aiea-price-sale';
				saleSpan.textContent = p.precio_oferta;

				var regSpan = document.createElement('span');
				regSpan.className = 'aiea-price-regular';
				regSpan.textContent = p.precio_regular;

				priceWrap.appendChild(saleSpan);
				priceWrap.appendChild(regSpan);
			} else {
				var currentSpan = document.createElement('span');
				currentSpan.className = 'aiea-price-current';
				currentSpan.textContent = p.precio || 'Consultar';
				priceWrap.appendChild(currentSpan);
			}
			body.appendChild(priceWrap);

			var stockBadge = document.createElement('span');
			stockBadge.className = 'aiea-stock-badge ' + (p.disponible ? 'aiea-stock-badge--in' : 'aiea-stock-badge--out');
			stockBadge.textContent = p.disponible ? 'En stock' : 'Agotado';
			body.appendChild(stockBadge);

			card.appendChild(body);

			// 3. Acciones ("Ver producto" y "Añadir al carrito" solo si comprable)
			var actions = document.createElement('div');
			actions.className = 'aiea-product-card-actions';

			var viewBtn = document.createElement('a');
			viewBtn.className = 'aiea-card-btn aiea-card-btn--secondary';
			viewBtn.textContent = 'Ver producto';
			if (p.url && typeof p.url === 'string' && /^https?:\/\//i.test(p.url)) {
				viewBtn.href = p.url;
				viewBtn.target = '_blank';
				viewBtn.rel = 'noopener noreferrer';
			} else {
				viewBtn.href = '#';
			}
			actions.appendChild(viewBtn);

			if (p.comprable) {
				var addBtn = document.createElement('button');
				addBtn.type = 'button';
				addBtn.className = 'aiea-card-btn aiea-card-btn--primary aiea-add-to-cart-btn';
				addBtn.textContent = 'Añadir al carrito';
				addBtn.setAttribute('data-product-id', String(p.id));
				addBtn.setAttribute('data-product-name', String(p.nombre || ''));

				addBtn.addEventListener('click', function (e) {
					e.preventDefault();
					handleAddToCartClick(this);
				});

				actions.appendChild(addBtn);
			}

			card.appendChild(actions);
			container.appendChild(card);
		}

		return container;
	}

	function saveTurnToLocalHistory(userText, assistantText, products) {
		var history = getSavedHistory();
		if (userText) {
			history.push({ role: 'user', content: userText });
		}
		if (assistantText) {
			var turn = { role: 'assistant', content: assistantText };
			if (Array.isArray(products) && products.length > 0) {
				turn.products = products;
			}
			history.push(turn);
		}
		if (history.length > MAX_STORED_ITEMS) {
			history = history.slice(history.length - MAX_STORED_ITEMS);
		}
		setStoredItem(STORAGE_KEY_HISTORY, JSON.stringify(history));
	}

	function scrollToBottom() {
		msgList.scrollTop = msgList.scrollHeight;
	}

	function appendMessageElement(role, text, products) {
		var wrapper = document.createElement('div');
		wrapper.className = 'aiea-chat-msg ' + (role === 'user' ? 'aiea-chat-msg--user' : 'aiea-chat-msg--assistant');

		var bubble = document.createElement('div');
		bubble.className = 'aiea-chat-bubble';
		renderSafeMessageContent(bubble, text);

		wrapper.appendChild(bubble);

		if (Array.isArray(products) && products.length > 0) {
			var cardsEl = renderProductCards(products);
			if (cardsEl) {
				wrapper.appendChild(cardsEl);
			}
		}

		msgList.appendChild(wrapper);
		scrollToBottom();
		return wrapper;
	}

	function restoreHistory() {
		var history = getSavedHistory();
		for (var i = 0; i < history.length; i++) {
			var item = history[i];
			if (item && (item.role === 'user' || item.role === 'assistant') && typeof item.content === 'string') {
				appendMessageElement(item.role, item.content, item.products || null);
			}
		}
	}

	function showNotice(text, ctas) {
		if (!noticeEl) {
			return;
		}
		noticeEl.innerHTML = '';
		if (!text) {
			noticeEl.hidden = true;
			return;
		}

		var textNode = document.createElement('div');
		textNode.className = 'aiea-chat-notice-text';
		textNode.textContent = String(text);
		noticeEl.appendChild(textNode);

		if (Array.isArray(ctas) && ctas.length > 0) {
			var actionsWrap = document.createElement('div');
			actionsWrap.className = 'aiea-chat-notice-actions';

			for (var i = 0; i < ctas.length; i++) {
				var cta = ctas[i];
				if (!cta || typeof cta.label !== 'string' || cta.label.trim() === '') {
					continue;
				}
				var btn = document.createElement('a');
				btn.className = 'aiea-chat-cta-btn' + (i === 0 ? ' aiea-chat-cta-btn--primary' : ' aiea-chat-cta-btn--secondary');
				btn.textContent = cta.label.trim();

				if (typeof cta.url === 'string' && cta.url.trim() !== '') {
					btn.href = cta.url.trim();
					btn.target = '_blank';
					btn.rel = 'noopener noreferrer';
				} else {
					btn.href = '#';
					btn.setAttribute('aria-disabled', 'true');
					btn.addEventListener('click', function (e) {
						e.preventDefault();
					});
				}
				actionsWrap.appendChild(btn);
			}

			if (actionsWrap.childNodes.length > 0) {
				noticeEl.appendChild(actionsWrap);
			}
		}

		noticeEl.hidden = false;
	}

	function setLoadingState(loading) {
		isSending = loading;
		input.disabled = loading;
		sendBtn.disabled = loading;
		if (typingEl) {
			typingEl.hidden = !loading;
		}
		if (loading) {
			scrollToBottom();
		}
	}

	function adjustInputHeight() {
		input.style.height = 'auto';
		var nextHeight = Math.min(input.scrollHeight, 100);
		input.style.height = (nextHeight > 40 ? nextHeight : 40) + 'px';
	}

	function setChatOpen(open) {
		win.hidden = !open;
		toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
		setStoredItem(STORAGE_KEY_OPEN, open ? '1' : '0');
		if (open) {
			scrollToBottom();
			setTimeout(function () {
				if (!input.disabled) {
					input.focus();
				}
			}, 50);
		}
	}

	function resolveErrorCtas(data) {
		if (!data || typeof data !== 'object') {
			return [];
		}
		if (data.data && Array.isArray(data.data.ctas)) {
			return data.data.ctas;
		}
		if (Array.isArray(data.ctas)) {
			return data.ctas;
		}
		if (data.code === 'aiea_session_messages_limit' && Array.isArray(AIEAPublic.demoCtas)) {
			return AIEAPublic.demoCtas;
		}
		return [];
	}

	function resolveErrorMessage(status, data) {
		var code      = data && typeof data.code === 'string' ? data.code : '';
		var serverMsg = data && typeof data.message === 'string' ? data.message.trim() : '';

		if (status === 400 && code === 'aiea_invalid_session') {
			setSessionId('');
			removeStoredItem(STORAGE_KEY_HISTORY);
			return strings.errorSession || 'Tu sesión anterior expiró. Por favor, envía tu mensaje de nuevo.';
		}

		if (code === 'aiea_session_messages_limit') {
			return serverMsg || strings.demoSessionLimit || 'Has alcanzado el límite de consultas de esta demostración.';
		}

		if (code === 'aiea_daily_calls_limit' || code === 'aiea_daily_tokens_limit') {
			return serverMsg || strings.demoDailyLimit || 'La demostración ha alcanzado temporalmente su capacidad de consultas. Por favor, inténtalo de nuevo más tarde.';
		}

		if (status === 400) {
			return serverMsg || strings.error400 || 'No se pudo procesar tu mensaje.';
		}

		if (status === 403) {
			return serverMsg || strings.error403 || 'El asistente no está disponible en este momento.';
		}

		if (status === 429) {
			return serverMsg || strings.error429 || 'Estás enviando mensajes demasiado rápido. Espera unos segundos.';
		}

		if (status === 503 || status >= 500) {
			return serverMsg || strings.error503 || 'El asistente no está disponible en este momento. Inténtalo de nuevo en unos instantes.';
		}

		return serverMsg || strings.error503 || 'No se pudo completar la consulta.';
	}

	function handleSubmit(event) {
		if (event) {
			event.preventDefault();
		}

		if (isSending) {
			return;
		}

		var message = input.value.trim();
		showNotice('');

		if (!message) {
			showNotice(strings.emptyMessage || 'Por favor, escribe un mensaje.');
			return;
		}

		if (message.length > maxLength) {
			showNotice(strings.tooLong || 'El mensaje supera la longitud máxima permitida.');
			return;
		}

		var userMsgEl = appendMessageElement('user', message);
		input.value = '';
		adjustInputHeight();
		setLoadingState(true);

		var payload = {
			message: message
		};
		var currentSessionId = getSessionId();
		if (currentSessionId) {
			payload.session_id = currentSessionId;
		}

		function sendChatRequest(useNonce) {
			var headers = {
				'Content-Type': 'application/json'
			};
			if (useNonce && AIEAPublic.nonce) {
				headers['X-WP-Nonce'] = AIEAPublic.nonce;
			}

			var targetRestUrl = toSameOriginUrl(AIEAPublic.restUrl, '/wp-json/aiea/v1/chat/public');

			return fetch(targetRestUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: headers,
				body: JSON.stringify(payload)
			}).then(function (response) {
				return response
					.json()
					.catch(function () {
						return {};
					})
					.then(function (data) {
						return {
							ok: response.ok,
							status: response.status,
							data: data || {}
						};
					});
			});
		}

		function applyElementorMenuCartDom(cartData) {
			if (!cartData || typeof cartData !== 'object') {
				return;
			}

			if (typeof cartData.count === 'number' && !isNaN(cartData.count)) {
				var countStr = String(cartData.count);
				var counterNodes = document.querySelectorAll(
					'.elementor-menu-cart__toggle [data-counter], ' +
					'.elementor-menu-cart__toggle_button [data-counter], ' +
					'.elementor-widget-woocommerce-menu-cart [data-counter], ' +
					'.elementor-button-icon[data-counter], ' +
					'.elementor-button-icon-qty'
				);
				for (var i = 0; i < counterNodes.length; i++) {
					counterNodes[i].setAttribute('data-counter', countStr);
					if (counterNodes[i].classList && counterNodes[i].classList.contains('elementor-button-icon-qty')) {
						counterNodes[i].textContent = countStr;
					}
				}
			}

			if (typeof cartData.subtotal === 'string' && cartData.subtotal !== '') {
				var subtotalNodes = document.querySelectorAll(
					'.elementor-menu-cart__toggle .elementor-button-text, ' +
					'.elementor-menu-cart__toggle_button .elementor-button-text'
				);
				for (var j = 0; j < subtotalNodes.length; j++) {
					if (subtotalNodes[j].innerHTML.trim() !== '') {
						subtotalNodes[j].innerHTML = cartData.subtotal;
					}
				}
			}
		}

		function applyFragmentsToDom(fragments, cartHash) {
			if (!fragments || typeof fragments !== 'object') {
				return;
			}

			var keys = Object.keys(fragments);
			for (var i = 0; i < keys.length; i++) {
				var selector = keys[i];
				var html = fragments[selector];
				if (typeof html !== 'string') {
					continue;
				}
				if (window.jQuery) {
					window.jQuery(selector).replaceWith(html);
				} else {
					var targets = document.querySelectorAll(selector);
					for (var k = 0; k < targets.length; k++) {
						targets[k].outerHTML = html;
					}
				}
			}

			try {
				if (window.wc_cart_fragments_params && window.wc_cart_fragments_params.fragment_name) {
					window.sessionStorage.setItem(
						window.wc_cart_fragments_params.fragment_name,
						JSON.stringify(fragments)
					);
					if (cartHash && window.wc_cart_fragments_params.cart_hash_key) {
						window.sessionStorage.setItem(window.wc_cart_fragments_params.cart_hash_key, cartHash);
						window.localStorage.setItem(window.wc_cart_fragments_params.cart_hash_key, cartHash);
					}
				}
			} catch (e) {
				// Ignorar si el almacenamiento de sesión está restringido.
			}
		}

		function notifyWooCommerceCartUpdated(cartData) {
			try {
				applyElementorMenuCartDom(cartData);

				var rawFragUrl = typeof AIEAPublic.wcFragmentsUrl === 'string' && AIEAPublic.wcFragmentsUrl !== ''
					? AIEAPublic.wcFragmentsUrl
					: '/?wc-ajax=get_refreshed_fragments';
				var wcFragmentsUrl = toSameOriginUrl(rawFragUrl, '/?wc-ajax=get_refreshed_fragments');

				fetch(wcFragmentsUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
					},
					body: 'time=' + Date.now()
				})
					.then(function (res) {
						return res.json();
					})
					.then(function (fragData) {
						if (fragData && fragData.fragments) {
							var hash = fragData.cart_hash || (cartData && cartData.cart_hash) || '';
							applyFragmentsToDom(fragData.fragments, hash);
							applyElementorMenuCartDom(cartData);

							if (window.jQuery && window.jQuery(document.body)) {
								window.jQuery(document.body).trigger('added_to_cart', [fragData.fragments, hash, false]);
								window.jQuery(document.body).trigger('wc_fragments_refreshed');
							}
						} else if (window.jQuery && window.jQuery(document.body)) {
							window.jQuery(document.body).trigger('wc_fragment_refresh');
						}
					})
					.catch(function () {
						if (window.jQuery && window.jQuery(document.body)) {
							window.jQuery(document.body).trigger('wc_fragment_refresh');
						}
					});

				if (typeof CustomEvent === 'function' && document.body) {
					document.body.dispatchEvent(
						new CustomEvent('wc-blocks_added_to_cart', {
							bubbles: true,
							detail: { preserveCartData: false }
						})
					);
				}
			} catch (e) {
				// Ignorar si el tema actual no escucha eventos del mini-carrito.
			}
		}

		sendChatRequest(true)
			.then(function (result) {
				if (!result.ok && result.status === 403 && result.data && result.data.code === 'rest_cookie_invalid_nonce') {
					AIEAPublic.nonce = '';
					return sendChatRequest(false);
				}
				return result;
			})
			.then(function (result) {
				if (!result.ok) {
					if (userMsgEl && userMsgEl.parentNode) {
						userMsgEl.parentNode.removeChild(userMsgEl);
					}
					input.value = message;
					adjustInputHeight();
					showNotice(resolveErrorMessage(result.status, result.data), resolveErrorCtas(result.data));
					return;
				}

				var data = result.data;
				if (data.session_id && typeof data.session_id === 'string') {
					setSessionId(data.session_id);
				}

				if (data.cart_updated) {
					notifyWooCommerceCartUpdated(data.cart || null);
				}

				var reply = typeof data.message === 'string' && data.message.trim() !== ''
					? data.message.trim()
					: '';

				if (reply) {
					appendMessageElement('assistant', reply, data.products || []);
					saveTurnToLocalHistory(message, reply, data.products || []);
				}
			})
			.catch(function () {
				if (userMsgEl && userMsgEl.parentNode) {
					userMsgEl.parentNode.removeChild(userMsgEl);
				}
				input.value = message;
				adjustInputHeight();
				showNotice(strings.errorNetwork || 'No se pudo conectar con la tienda. Comprueba tu conexión e inténtalo de nuevo.');
			})
			.finally(function () {
				setLoadingState(false);
				if (!win.hidden) {
					input.focus();
				}
			});
	}

	// Eventos de interfaz
	toggleBtn.addEventListener('click', function () {
		setChatOpen(win.hidden);
	});

	if (closeBtn) {
		closeBtn.addEventListener('click', function () {
			setChatOpen(false);
			toggleBtn.focus();
		});
	}

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && !win.hidden) {
			setChatOpen(false);
			toggleBtn.focus();
		}
	});

	input.addEventListener('input', function () {
		if (noticeEl && !noticeEl.hidden) {
			showNotice('');
		}
		adjustInputHeight();
	});

	input.addEventListener('keydown', function (event) {
		if (event.key === 'Enter' && !event.shiftKey) {
			event.preventDefault();
			handleSubmit(event);
		}
	});

	form.addEventListener('submit', handleSubmit);

	// Inicialización al cargar la página
	restoreHistory();
	if (getStoredItem(STORAGE_KEY_OPEN) === '1') {
		setChatOpen(true);
	}
}());
