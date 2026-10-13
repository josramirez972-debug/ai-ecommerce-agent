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

	function saveTurnToLocalHistory(userText, assistantText) {
		var history = getSavedHistory();
		history.push({ role: 'user', content: userText });
		history.push({ role: 'assistant', content: assistantText });
		if (history.length > MAX_STORED_ITEMS) {
			history = history.slice(history.length - MAX_STORED_ITEMS);
		}
		setStoredItem(STORAGE_KEY_HISTORY, JSON.stringify(history));
	}

	function scrollToBottom() {
		msgList.scrollTop = msgList.scrollHeight;
	}

	function appendMessageElement(role, text) {
		var wrapper = document.createElement('div');
		wrapper.className = 'aiea-chat-msg ' + (role === 'user' ? 'aiea-chat-msg--user' : 'aiea-chat-msg--assistant');

		var bubble = document.createElement('div');
		bubble.className = 'aiea-chat-bubble';
		bubble.textContent = String(text || '');

		wrapper.appendChild(bubble);
		msgList.appendChild(wrapper);
		scrollToBottom();
		return wrapper;
	}

	function restoreHistory() {
		var history = getSavedHistory();
		for (var i = 0; i < history.length; i++) {
			var item = history[i];
			if (item && (item.role === 'user' || item.role === 'assistant') && typeof item.content === 'string') {
				appendMessageElement(item.role, item.content);
			}
		}
	}

	function showNotice(text) {
		if (!noticeEl) {
			return;
		}
		if (!text) {
			noticeEl.hidden = true;
			noticeEl.textContent = '';
			return;
		}
		noticeEl.textContent = String(text);
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

	function resolveErrorMessage(status, data) {
		var code      = data && typeof data.code === 'string' ? data.code : '';
		var serverMsg = data && typeof data.message === 'string' ? data.message.trim() : '';

		if (status === 400 && code === 'aiea_invalid_session') {
			setSessionId('');
			removeStoredItem(STORAGE_KEY_HISTORY);
			return strings.errorSession || 'Tu sesión anterior expiró. Por favor, envía tu mensaje de nuevo.';
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

			return fetch(AIEAPublic.restUrl, {
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

				var wcFragmentsUrl = typeof AIEAPublic.wcFragmentsUrl === 'string' && AIEAPublic.wcFragmentsUrl !== ''
					? AIEAPublic.wcFragmentsUrl
					: '/?wc-ajax=get_refreshed_fragments';

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
					showNotice(resolveErrorMessage(result.status, result.data));
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
					appendMessageElement('assistant', reply);
					saveTurnToLocalHistory(message, reply);
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
