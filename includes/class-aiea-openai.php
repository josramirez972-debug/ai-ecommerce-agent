<?php
/**
 * AI Ecommerce Agent — Cliente de la Responses API de OpenAI.
 *
 * Soporta el ciclo de Function Calling en dos llamadas HTTP:
 *   1ª llamada → OpenAI puede invocar buscar_productos().
 *   Ejecución local de la herramienta con datos reales de WooCommerce.
 *   2ª llamada → OpenAI recibe los resultados y genera la respuesta final.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Ecommerce_Agent_OpenAI {

	/** @var string Endpoint de la Responses API de OpenAI. */
	const API_ENDPOINT = 'https://api.openai.com/v1/responses';

	/** @var int Máximo de tokens en cada respuesta generada. */
	const MAX_OUTPUT_TOKENS = 500;

	/** @var int Timeout HTTP en segundos. */
	const HTTP_TIMEOUT = 45;

	/** @var array Modelos permitidos. */
	const ALLOWED_MODELS = [ 'gpt-4.1-mini', 'gpt-4.1', 'gpt-4o-mini' ];

	/** @var int Máximo de rondas encadenadas de herramientas por turno (ej. buscar_productos -> anadir_al_carrito). */
	const MAX_TOOL_ROUNDS = 3;

	/** @var int Máximo de invocaciones de herramientas procesadas por ronda. */
	const MAX_CALLS_PER_ROUND = 4;

	// =========================================================
	// Método público principal
	// =========================================================

	/**
	 * Envía un mensaje al agente de IA con soporte de memoria de conversación.
	 *
	 * Si WooCommerce está activo, registra las herramientas buscar_productos()
	 * y anadir_al_carrito() y ejecuta el ciclo de Function Calling cuando OpenAI las invoca.
	 *
	 * @param  string          $message    Mensaje del usuario (ya sanitizado).
	 * @param  string          $session_id Identificador de sesión para el historial (opcional).
	 * @return array|WP_Error  array( 'message' => string, 'model' => string, 'session_id' => string, 'cart_updated' => bool ) o WP_Error.
	 */
	public static function send_message( string $message, string $session_id = '' ) {

		// --- 1. Leer configuración guardada ---
		$options = get_option( 'aiea_settings', [] );
		$options = is_array( $options ) ? $options : [];

		$key   = isset( $options['api_key'] )    ? trim( (string) $options['api_key'] ) : '';
		$model = isset( $options['model'] )      ? sanitize_text_field( $options['model'] ) : 'gpt-4.1-mini';
		$name  = isset( $options['agent_name'] ) ? sanitize_text_field( $options['agent_name'] ) : 'Asistente de ventas';

		if ( '' === $key ) {
			return new WP_Error(
				'aiea_missing_api_key',
				'No hay una API Key guardada. Ve a AI Ecommerce Agent → Configuración.',
				[ 'status' => 400 ]
			);
		}

		if ( ! in_array( $model, self::ALLOWED_MODELS, true ) ) {
			$model = 'gpt-4.1-mini';
		}

		// --- 2. Determinar si WooCommerce está disponible ---
		$woo_active   = class_exists( 'WooCommerce' ) && function_exists( 'wc_get_products' );
		$tools        = self::get_tools_definition( $woo_active );
		$instructions = self::build_instructions( $name, $woo_active );

		// --- 3. Gestionar identificador e historial de sesión ---
		$session_id = AI_Ecommerce_Agent_Session::sanitize_id( $session_id );
		if ( '' === $session_id ) {
			$session_id = AI_Ecommerce_Agent_Session::generate_id();
		}

		// --- 3.1 Control de estado conversacional y derivación estricta de reclamos (Fase 2) ---
		$is_claim_active = AI_Ecommerce_Agent_Session::is_claim_derived( $session_id );
		$is_new_claim    = self::is_claim_intent( $message );

		if ( $is_new_claim ) {
			// Derivación inmediata de reclamo (prioridad absoluta incluso en mensajes mixtos)
			AI_Ecommerce_Agent_Session::set_claim_derived( $session_id, true );
			$reply = self::build_claim_derivation_response();
			AI_Ecommerce_Agent_Session::save_turn( $session_id, $message, $reply );

			return [
				'message'      => $reply,
				'model'        => 'rule-claim-guard',
				'session_id'   => $session_id,
				'cart_updated' => false,
			];
		}

		if ( $is_claim_active ) {
			if ( self::is_commercial_intent( $message ) ) {
				// El cliente cambia claramente a intención comercial: reanudar ventas
				AI_Ecommerce_Agent_Session::set_claim_derived( $session_id, false );
			} else {
				// El cliente continúa insistiendo en el reclamo o enviando datos personales
				AI_Ecommerce_Agent_Session::set_claim_derived( $session_id, true );
				$reply = self::build_claim_continuation_response();
				AI_Ecommerce_Agent_Session::save_turn( $session_id, $message, $reply );

				return [
					'message'      => $reply,
					'model'        => 'rule-claim-guard',
					'session_id'   => $session_id,
					'cart_updated' => false,
				];
			}
		}

		$history       = AI_Ecommerce_Agent_Session::get_history( $session_id );
		$current_input = AI_Ecommerce_Agent_Session::to_responses_api_input( $history );
		$current_input[] = [
			'type'    => 'message',
			'role'    => 'user',
			'content' => $message,
		];

		$cart_updated      = false;
		$last_response     = null;
		$effective_limits  = class_exists( 'AI_Ecommerce_Agent_Usage' ) ? AI_Ecommerce_Agent_Usage::get_effective_limits() : [];
		$max_output_tokens = isset( $effective_limits['max_output_tokens'] ) && (int) $effective_limits['max_output_tokens'] >= 100
			? min( 1000, (int) $effective_limits['max_output_tokens'] )
			: self::MAX_OUTPUT_TOKENS;

		if ( class_exists( 'AI_Ecommerce_Agent_Woo' ) ) {
			AI_Ecommerce_Agent_Woo::reset_last_search_results();
		}

		// --- 4. Ciclo controlado de llamadas y ejecución de herramientas (hasta MAX_TOOL_ROUNDS rondas) ---
		for ( $round = 1; $round <= self::MAX_TOOL_ROUNDS; $round++ ) {
			$payload = [
				'model'             => $model,
				'instructions'      => $instructions,
				'input'             => $current_input,
				'max_output_tokens' => $max_output_tokens,
				'store'             => false,
			];

			if ( ! empty( $tools ) ) {
				$payload['tools'] = $tools;
			}

			$response = self::http_post( $key, $payload );
			if ( is_wp_error( $response ) ) {
				// No guardar historial en caso de error para evitar estados inconsistentes.
				return $response;
			}

			$last_response  = $response;
			$output         = isset( $response['output'] ) && is_array( $response['output'] )
				? $response['output']
				: [];
			$function_calls = self::find_function_calls( $output );

			if ( empty( $function_calls ) ) {
				// Sin herramientas invocadas en esta ronda: extraer texto final y guardar turno.
				$result = self::build_result( $response, $model );
				if ( ! is_wp_error( $result ) ) {
					AI_Ecommerce_Agent_Session::save_turn( $session_id, $message, $result['message'] );
					$result['session_id']   = $session_id;
					$result['cart_updated'] = $cart_updated;
					$result['products']     = class_exists( 'AI_Ecommerce_Agent_Woo' ) ? AI_Ecommerce_Agent_Woo::get_last_search_results() : [];
				}
				return $result;
			}

			// Conservar en el input los ítems que no sean function_call y añadir cada function_call ejecutado con su salida.
			foreach ( $output as $item ) {
				if ( isset( $item['type'] ) && 'function_call' === $item['type'] ) {
					continue;
				}
				$current_input[] = $item;
			}

			$executed_count = 0;
			foreach ( $function_calls as $fc ) {
				if ( $executed_count >= self::MAX_CALLS_PER_ROUND ) {
					break;
				}

				$call_id = ! empty( $fc['call_id'] )
					? trim( (string) $fc['call_id'] )
					: trim( (string) ( $fc['id'] ?? '' ) );

				if ( '' === $call_id ) {
					return new WP_Error(
						'aiea_invalid_tool_call',
						'OpenAI devolvió una invocación de herramienta sin identificador válido.',
						[ 'status' => 502 ]
					);
				}

				$tool_result = self::execute_tool( $fc );
				if ( ! empty( $tool_result['exito'] ) && isset( $tool_result['codigo'] ) && 'producto_anadido' === $tool_result['codigo'] ) {
					$cart_updated = true;
				}

				$tool_json = wp_json_encode( $tool_result );
				if ( is_string( $tool_json ) && strlen( $tool_json ) > 6000 ) {
					$tool_json = function_exists( 'mb_substr' )
						? mb_substr( $tool_json, 0, 6000, 'UTF-8' )
						: substr( $tool_json, 0, 6000 );
				}

				$current_input[] = $fc;
				$current_input[] = [
					'type'    => 'function_call_output',
					'call_id' => $call_id,
					'output'  => (string) $tool_json,
				];

				$executed_count++;
			}
		}

		// --- 5. Llamada final de síntesis textual con tool_choice = none ---
		$final_payload = [
			'model'             => $model,
			'instructions'      => $instructions,
			'input'             => $current_input,
			'max_output_tokens' => $max_output_tokens,
			'store'             => false,
			'tools'             => $tools,
			'tool_choice'       => 'none',
		];

		$final_response = self::http_post( $key, $final_payload );
		if ( is_wp_error( $final_response ) ) {
			return $final_response;
		}

		$result = self::build_result( $final_response, $model );
		if ( ! is_wp_error( $result ) ) {
			AI_Ecommerce_Agent_Session::save_turn( $session_id, $message, $result['message'] );
			$result['session_id']   = $session_id;
			$result['cart_updated'] = $cart_updated;
			$result['products']     = class_exists( 'AI_Ecommerce_Agent_Woo' ) ? AI_Ecommerce_Agent_Woo::get_last_search_results() : [];
		}

		return $result;
	}

	// =========================================================
	// Métodos auxiliares privados
	// =========================================================

	/**
	 * Construye las instrucciones del sistema según el estado de WooCommerce y la identidad configurada.
	 *
	 * @param  string $name       Nombre configurado del agente.
	 * @param  bool   $woo_active Si WooCommerce está disponible.
	 * @return string
	 */
	private static function build_instructions( string $name, bool $woo_active ): string {
		$agent_name = '' !== $name ? $name : 'Asistente de ventas';
		$store_ctx  = '';

		if ( class_exists( 'AI_Ecommerce_Agent_Knowledge' ) ) {
			$biz = AI_Ecommerce_Agent_Knowledge::get_business_info();
			if ( ! empty( $biz['business_name'] ) ) {
				$store_ctx = ' de la tienda "' . sanitize_text_field( (string) $biz['business_name'] ) . '"';
			}
		}

		$claims_email   = class_exists( 'AI_Ecommerce_Agent_Knowledge' ) ? AI_Ecommerce_Agent_Knowledge::get_claims_email() : '';
		$claims_channel = '' !== $claims_email ? "al correo oficial de reclamaciones: {$claims_email}" : 'a los canales oficiales de soporte y atención al cliente de la tienda';

		$instructions = "Eres {$agent_name}, asesor comercial{$store_ctx}. Responde siempre en español de forma natural, cercana, profesional, breve, directa y útil.\n\n"
			. "DIRECTRICES CONVERSACIONALES Y DE EXPERIENCIA DE COMPRA:\n"
			. "1. TONO NATURAL Y FLUIDEZ CONVERSACIONAL:\n"
			. "   - Habla con cercanía, respeto y profesionalismo en español natural.\n"
			. "   - NO REPITAS SALUDOS: Si en los turnos anteriores ya saludaste al cliente o la conversación ya está en curso, ve directo a responder sin decir '¡Hola!', '¡Buenas!' ni frases introductorias redundantes.\n"
			. "   - NO TERMINES CON PREGUNTAS FORZADAS: No finalices cada mensaje con una pregunta innecesaria ('¿Hay algo más en lo que te pueda ayudar?', '¿Te gustaría saber algo más?'). Pregunta ÚNICAMENTE cuando falte información indispensable para avanzar (ej. selección de sabor, presentación o destino de envío).\n"
			. "   - RESOLUCIÓN DE REFERENCIAS CONTEXTUALES: Si el cliente pregunta por 'la primera', 'esa proteína', 'la segunda opción', '¿cuánto cuesta?', '¿tienen en stock?' o 'añádela', revisa el historial para identificar el producto con precisión y consulta buscar_productos con su nombre si necesitas verificar existencias o ID.\n"
			. "   - Respuestas concisas, bien adaptadas a la intención del cliente y orientadas hacia una acción útil (conocer el producto, resolver dudas o avanzar al checkout) sin insistencia.\n"
			. "2. PRESENTACIÓN DE PRODUCTOS Y ENLACES REALES (buscar_productos):\n"
			. "   - Ante cualquier consulta sobre productos, ejecuta SIEMPRE buscar_productos en WooCommerce para consultar datos reales.\n"
			. "   - Al presentar un producto encontrado, incluye SIEMPRE un enlace funcional a su ficha en formato Markdown utilizando estrictamente la URL provista en el campo 'url': [Nombre Real del Producto](url_real). NUNCA inventes URLs ni supongas slugs.\n"
			. "   - Presenta el nombre, precio formateado, disponibilidad y presentación cuando estén disponibles.\n"
			. "   - Si el producto está AGOTADO ('disponible': false o sin stock): indícalo explícitamente y aclara que no se puede comprar en este momento. PROHIBIDO describirlo como disponible. Comparte el enlace a su ficha y ofrece alternativas en stock si existen en la tienda.\n"
			. "   - Si el producto no existe en el catálogo, reconoce abiertamente que no lo encontraste sin inventar productos ficticios.\n"
			. "   - Si es un producto 'variable', informa los atributos disponibles (ej. sabores, tamaños) y precios de las variaciones. Si un producto es variable y no tiene variaciones configuradas o no tiene precio asignado en la tienda, indícalo con transparencia sin inventar datos (explica que las opciones están en proceso de configuración y que actualmente no está disponible para compra inmediata).\n"
			. "   - RECOMENDACIONES Y COMPARACIONES: Puedes orientar sobre características comerciales, formatos, sabores, tamaños, precios y descripciones declaradas en el catálogo. PROHIBIDO hacer afirmaciones terapéuticas, curativas o diagnósticas no comprobadas. Si consultan por suplementos para condiciones de salud específicas, asesora desde el enfoque comercial y sugiere consultar con un profesional de la salud.\n"
			. "3. CARRITO, CHECKOUT Y COMPRA ASISTIDA (anadir_al_carrito):\n"
			. "   - Si el cliente solicita añadir un producto al carrito ('añádelo', 'quiero comprarlo', 'lo llevo'):\n"
			. "     a) Verifica que el producto sea comprable y tenga stock.\n"
			. "     b) Si es variable y faltan atributos obligatorios, pregunta amablemente qué opción prefiere antes de ejecutar la herramienta.\n"
			. "     c) Ejecuta anadir_al_carrito con el product_id numérico y la variación correspondiente.\n"
			. "     d) Confirma que el producto se añadió ÚNICAMENTE si 'exito' es true.\n"
			. "     e) Facilita siempre enlaces funcionales al carrito y al checkout utilizando las URLs devueltas por la herramienta: [Ver Carrito](url_carrito) y [Finalizar Compra](url_checkout).\n"
			. "     f) Si la operación falla o el producto está agotado, explícalo con claridad, no afirmes que se añadió, ofrece su enlace de ficha y presenta alternativas si las hay.\n"
			. "     g) Nunca intentes completar la compra automáticamente ni pidas datos de pago; el cliente debe finalizar su compra de forma segura en el checkout oficial de WooCommerce.\n"
			. "4. DERIVACIÓN ESTRICTA DE RECLAMOS, QUEJAS Y REEMBOLSOS:\n"
			. "   - Este chat es EXCLUSIVAMENTE un canal de orientación comercial y ventas. NO es un canal de postventa ni de resolución de reclamos.\n"
			. "   - Ante cualquier reclamo, queja, inconformidad, pedido dañado, no entregado, cobro doble o solicitud de reembolso, responde con empatía y brevedad remitiendo {$claims_channel}.\n"
			. "   - PROHIBIDO solicitar datos personales sensibles (cédula, documentos, teléfono personal o datos de tarjetas de crédito) para 'gestionar el reclamo por chat'.\n"
			. "   - PROHIBIDO prometer reembolsos, devoluciones de dinero o cancelaciones desde el chat.\n"
			. "   - PROHIBIDO ofrecer productos o descuentos como compensación a un reclamo.\n"
			. "   - Si el cliente cambia a una consulta comercial ('Está bien, muéstrame las proteínas'), reanuda la atención de ventas con naturalidad sin volver a tocar el reclamo.\n"
			. "5. PRIORIDAD DE FUENTES REALES Y PROHIBICIÓN DE INFERENCIAS:\n"
			. "   - Prioriza siempre las fuentes reales verificadas sobre suposiciones. Nunca conviertas inferencias en hechos ni inventes políticas, precios, existencias, tiempos de entrega, costos de envío, cobertura, garantías, devoluciones, reembolsos, cancelaciones, horarios o métodos de pago.\n"
			. "   - Distingue siempre entre 'información no disponible o no especificada' y 'no permitido o excluido'.\n"
			. "   - No menciones restricciones hipotéticas sin evidencia en las herramientas.\n"
			. "   - Recomienda contactar con la tienda ÚNICAMENTE cuando falte información confirmada o exista contradicción entre fuentes.\n"
			. "6. COBERTURA GEOGRÁFICA Y ENVÍOS (consultar_cobertura_envios y consultar_politicas):\n"
			. "   - Ante cualquier consulta sobre envíos, ciudades atendidas, cobertura nacional, costos o tiempos de entrega, ejecuta siempre consultar_cobertura_envios (pasando la ubicación consultada).\n"
			. "   - No deduzcas cobertura nacional solo por el modo 'nacional' si la lista de ciudades o regiones está limitada.\n"
			. "   - Distingue estrictamente los 5 estados de evaluacion_ubicacion_consultada:\n"
			. "     a) 'ciudad_atendida_confirmada': responde de forma clara y directa confirmando que sí se hacen envíos a esa ciudad. Si tiene_restricciones_especificas es false, PROHIBIDO afirmar o sugerir que existen posibles restricciones geográficas y NO pidas contactar a la tienda para confirmar la cobertura de esa ciudad.\n"
			. "     b) 'ciudad_excluida_explicitamente': indica claramente que esa ciudad o zona está expresamente excluida de cobertura.\n"
			. "     c) 'cobertura_no_especificada': indica que la cobertura para esa ciudad no está especificada (sin afirmar que esté prohibida ni deducir cobertura nacional), menciona las ciudades atendidas confirmadas y sugiere consultar con la tienda.\n"
			. "     d) 'fuente_cobertura_no_disponible': indica que la fuente de cobertura no está disponible y sugiere contactar con la tienda.\n"
			. "     e) 'fuentes_contradictorias': informa con claridad que existe una discrepancia entre las fuentes configuradas para esa ciudad y recomienda confirmarlo con la tienda.\n"
			. "   - COSTOS Y TIEMPOS DE ENVÍO: Si costos_envio_confirmados es false o tiempos_entrega_confirmados es false por contradicción con la política oficial, PROHIBIDO presentar valores no respaldados o contradictorios (como '12.000 COP' o '1 a 3 días hábiles') como hechos confirmados. Explica que el costo exacto varía según el destino/pedido y se muestra en el checkout antes del pago, y que el plazo definitivo de entrega depende de la confirmación logística en el checkout (con 24 h de preparación de referencia según la política oficial).\n"
			. "7. POLÍTICAS OFICIALES (consultar_politicas):\n"
			. "   - Ejecuta siempre consultar_politicas con el tipo exacto: 'devoluciones', 'reembolsos', 'garantia', 'cancelaciones', 'terminos', 'privacidad', 'envios' o 'pagos'.\n"
			. "   - NUNCA uses una página equivocada para responder otra política (p. ej. nunca respondas sobre garantías con la página de envíos, ni sobre cancelaciones con la de privacidad, ni sobre reembolsos con la de garantías).\n"
			. "   - No presentes textos pendientes de validación ni marcadores en borrador como políticas confirmadas.\n"
			. "   - Si consultar_politicas devuelve verificada=false (como cuando una política no existe o su página asignada no corresponde al tema), indícalo con claridad y sin inventar condiciones.\n"
			. "8. PREGUNTAS FRECUENTES (consultar_faq):\n"
			. "   - Las FAQ activas apoyan la atención, pero NUNCA anulan las restricciones explícitas de las políticas oficiales. Si una FAQ aparece marcada con contradice_politica_oficial_envios=true, no presentes su plazo como confirmado.\n"
			. "9. INFORMACIÓN DEL NEGOCIO, HORARIOS Y CONTACTO (consultar_informacion_negocio y consultar_horarios_contacto):\n"
			. "   - Ejecuta consultar_informacion_negocio y/o consultar_horarios_contacto para datos de la empresa, horarios o contacto humano. Si puede_confirmar_estado_actual es false, no afirmes si está abierto o cerrado ahora mismo.\n"
			. "10. MÉTODOS DE PAGO REALES (consultar_metodos_pago):\n"
			. "   - Ejecuta siempre consultar_metodos_pago. Muestra únicamente pasarelas con enabled='yes' presentes en metodos_disponibles. Si metodos_disponibles está vacío, no confirmes métodos informativos (como PSE, Nequi o Daviplata) e indica que los métodos disponibles se muestran al finalizar la compra en el checkout.\n"
			. "11. SEGURIDAD Y PRIVACIDAD:\n"
			. "   - Nunca expongas claves ni configuraciones sensibles, y nunca solicites contraseñas, documentos de identidad ni códigos bancarios.";

		if ( ! $woo_active ) {
			$instructions .= "\n\nAVISO: En esta prueba no tienes acceso directo al catálogo ni a las pasarelas de WooCommerce. Si te preguntan por productos o métodos de pago activos, indícalo con amabilidad y no inventes datos.";
		}

		return $instructions;
	}

	/**
	 * Devuelve la definición de herramientas para la Responses API.
	 *
	 * @param  bool $woo_active Si WooCommerce está activo para incluir herramientas de catálogo y carrito.
	 * @return array[]
	 */
	private static function get_tools_definition( bool $woo_active = true ): array {
		$tools = [];

		if ( $woo_active ) {
			$tools[] = [
				'type'        => 'function',
				'name'        => 'buscar_productos',
				'description' => 'Consulta productos en el catálogo real de la tienda WooCommerce. Devuelve metadatos de paginación (total, página, páginas, hay_más) y lista de productos con id, tipo (simple o variable), nombre, precio, descripción, disponible, url y variaciones cuando el producto es variable.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'term'  => [
							'type'        => 'string',
							'description' => 'Término a buscar. Para buscar un producto específico, envía su nombre o término relevante (ej. "Citrulina Malato"). Para buscar por categoría, envía el nombre de la categoría (ej. "Aminoácidos"). Para explorar o paginar el catálogo general, envía una cadena vacía "" o "todos".',
						],
						'limit' => [
							'type'        => 'integer',
							'description' => 'Número máximo de resultados a devolver por página (1 a 10). Por defecto 5.',
							'default'     => 5,
						],
						'page'  => [
							'type'        => 'integer',
							'description' => 'Número de página a consultar (1 en adelante). Por defecto 1. Úsalo cuando el usuario solicite ver más productos o la siguiente página del catálogo.',
							'default'     => 1,
						],
					],
					'required' => [ 'term' ],
				],
			];

			$tools[] = [
				'type'        => 'function',
				'name'        => 'anadir_al_carrito',
				'description' => 'Ejecuta añadir_al_carrito() en WooCommerce para agregar un producto real al carrito del visitante. Verifica existencia, estado publicado, stock y variaciones. Devuelve exito=true solo cuando WooCommerce aceptó el producto en el carrito.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'product_id'   => [
							'type'        => 'integer',
							'description' => 'ID numérico real del producto en WooCommerce obtenido mediante buscar_productos.',
						],
						'quantity'     => [
							'type'        => 'integer',
							'description' => 'Cantidad de unidades a añadir (entero positivo, por defecto 1).',
							'default'     => 1,
						],
						'variation_id' => [
							'type'        => 'integer',
							'description' => 'ID numérico de la variación seleccionada cuando el producto es de tipo variable. Enviar 0 si es un producto simple.',
							'default'     => 0,
						],
						'attributes'   => [
							'type'                 => 'object',
							'description'          => 'Mapa clave-valor con los atributos de variación seleccionados por el cliente (ej. {"Presentación": "500 g"} o {"Sabor": "Vainilla"}). Obligatorio en productos variables si el cliente especificó la opción.',
							'additionalProperties' => [
								'type' => 'string',
							],
						],
					],
					'required' => [ 'product_id', 'quantity' ],
				],
			];
		}

		$tools[] = [
			'type'        => 'function',
			'name'        => 'consultar_informacion_negocio',
			'description' => 'Consulta la información comercial configurada de la tienda (nombre comercial, descripción, categoría/sector, país, departamento/región, ciudad, dirección física, correo, teléfono, WhatsApp, sitio web y URL de contacto).',
			'parameters'  => [
				'type'       => 'object',
				'properties' => (object) [],
				'required'   => [],
			],
		];

		$tools[] = [
			'type'        => 'function',
			'name'        => 'consultar_cobertura_envios',
			'description' => 'Consulta la configuración estructurada de envíos y cobertura geográfica de la tienda (modalidad de envío, países/departamentos/ciudades atendidos, zonas excluidas, restricciones, costos y tiempos estimados) y evalúa si una ubicación específica está atendida, excluida o sin definir.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'ubicacion' => [
						'type'        => 'string',
						'description' => 'Ciudad, municipio, departamento, región o país por el que pregunta el cliente (ej. "Medellín", "Bogotá", "Colombia"). Enviar cadena vacía "" si pregunta por los envíos en general.',
					],
				],
				'required' => [],
			],
		];

		$tools[] = [
			'type'        => 'function',
			'name'        => 'consultar_politicas',
			'description' => 'Recupera del servidor el contenido actualizado de las páginas oficiales de WordPress seleccionadas por el administrador (envíos, devoluciones, reembolsos, garantías, cancelaciones, términos y condiciones, privacidad, FAQ general, empresa o contacto).',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'tipo'     => [
						'type'        => 'string',
						'enum'        => [ 'envios', 'devoluciones', 'reembolsos', 'garantia', 'cancelaciones', 'terminos', 'privacidad', 'faq', 'empresa', 'contacto', 'pagos' ],
						'description' => 'Categoría de la página oficial a consultar: "envios", "devoluciones", "reembolsos", "garantia", "cancelaciones", "terminos", "privacidad", "faq", "empresa", "contacto" o "pagos".',
					],
					'consulta' => [
						'type'        => 'string',
						'description' => 'Pregunta, ciudad, producto o término específico consultado por el cliente para extraer los fragmentos pertinentes.',
					],
				],
				'required' => [ 'tipo' ],
			],
		];

		$tools[] = [
			'type'        => 'function',
			'name'        => 'consultar_faq',
			'description' => 'Busca en las preguntas frecuentes (FAQ) activas configuradas por el administrador de la tienda. Nunca devuelve preguntas desactivadas.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'consulta' => [
						'type'        => 'string',
						'description' => 'Pregunta o palabras clave de la duda del cliente para buscar en las FAQ activas.',
					],
				],
				'required' => [],
			],
		];

		$tools[] = [
			'type'        => 'function',
			'name'        => 'consultar_horarios_contacto',
			'description' => 'Consulta los días y horarios de atención, cierres especiales, zona horaria, estado actual (abierto/cerrado cuando es verificable) e indicaciones para contactar con un asesor humano.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => (object) [],
				'required'   => [],
			],
		];

		$tools[] = [
			'type'        => 'function',
			'name'        => 'consultar_metodos_pago',
			'description' => 'Herramienta de solo lectura que consulta en WooCommerce qué pasarelas y métodos de pago están realmente habilitados y disponibles para la compra actual.',
			'parameters'  => [
				'type'       => 'object',
				'properties' => [
					'metodo_consultado' => [
						'type'        => 'string',
						'description' => 'Nombre del método de pago específico por el que pregunta el cliente (ej. "transferencia", "PayPal", "PSE", "Nequi", "contraentrega", "tarjeta"). Enviar "" si pregunta en general.',
					],
				],
				'required' => [],
			],
		];

		return $tools;
	}

	/**
	 * Realiza una petición POST a la Responses API de OpenAI y contabiliza llamadas/tokens/errores reales.
	 *
	 * @param  string         $key     API Key de OpenAI.
	 * @param  array          $payload Payload a enviar como JSON.
	 * @return array|WP_Error Body decodificado o WP_Error.
	 */
	private static function http_post( string $key, array $payload ) {
		$reservation_id = '';
		if ( class_exists( 'AI_Ecommerce_Agent_Usage' ) ) {
			$estimated_tokens = AI_Ecommerce_Agent_Usage::estimate_payload_tokens( $payload );
			$reservation      = AI_Ecommerce_Agent_Usage::reserve_openai_call_capacity( $estimated_tokens );
			if ( is_wp_error( $reservation ) ) {
				return $reservation;
			}
			$reservation_id = (string) $reservation;
		}

		$response = wp_remote_post(
			self::API_ENDPOINT,
			[
				'timeout'     => self::HTTP_TIMEOUT,
				'redirection' => 2,
				'headers'     => [
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				],
				'body'        => wp_json_encode( $payload ),
			]
		);

		if ( is_wp_error( $response ) ) {
			if ( class_exists( 'AI_Ecommerce_Agent_Usage' ) ) {
				AI_Ecommerce_Agent_Usage::record_openai_error( $reservation_id );
			}
			return new WP_Error(
				'aiea_connection_error',
				'No se pudo conectar con OpenAI. Comprueba la conexión de WordPress e inténtalo de nuevo.',
				[ 'status' => 502 ]
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			if ( class_exists( 'AI_Ecommerce_Agent_Usage' ) ) {
				AI_Ecommerce_Agent_Usage::record_openai_error( $reservation_id );
			}
			return self::openai_http_error( $status, $body );
		}

		if ( ! is_array( $body ) ) {
			if ( class_exists( 'AI_Ecommerce_Agent_Usage' ) ) {
				AI_Ecommerce_Agent_Usage::record_openai_error( $reservation_id );
			}
			return new WP_Error(
				'aiea_invalid_response',
				'OpenAI devolvió una respuesta que no se pudo interpretar.',
				[ 'status' => 502 ]
			);
		}

		if ( class_exists( 'AI_Ecommerce_Agent_Usage' ) ) {
			AI_Ecommerce_Agent_Usage::record_openai_call_response( $body, $reservation_id );
		}

		return $body;
	}

	/**
	 * Extrae todos los ítems de tipo function_call presentes en el array output[] de OpenAI.
	 *
	 * @param  array   $output Array output[] del response body.
	 * @return array[] Lista de ítems function_call.
	 */
	private static function find_function_calls( array $output ): array {
		$calls = [];
		foreach ( $output as $item ) {
			if ( isset( $item['type'] ) && 'function_call' === $item['type'] ) {
				$calls[] = $item;
			}
		}
		return $calls;
	}

	/**
	 * Ejecuta la herramienta solicitada por OpenAI y devuelve el resultado.
	 *
	 * @param  array $function_call Ítem function_call del output de OpenAI.
	 * @return array Resultado normalizado de la herramienta.
	 */
	private static function execute_tool( array $function_call ): array {
		$name      = isset( $function_call['name'] ) ? (string) $function_call['name'] : '';
		$args_json = isset( $function_call['arguments'] ) ? (string) $function_call['arguments'] : '{}';
		$args      = json_decode( $args_json, true );
		$args      = is_array( $args ) ? $args : [];

		if ( 'buscar_productos' === $name ) {
			$term  = isset( $args['term'] )
				? sanitize_text_field( (string) $args['term'] )
				: '';
			$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 5;
			$page  = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;

			return AI_Ecommerce_Agent_Woo::buscar_productos( $term, $limit, $page );
		}

		if ( 'anadir_al_carrito' === $name || 'añadir_al_carrito' === $name ) {
			$product_id   = $args['product_id'] ?? 0;
			$quantity     = $args['quantity'] ?? 1;
			$variation_id = $args['variation_id'] ?? 0;
			$attributes   = isset( $args['attributes'] ) && is_array( $args['attributes'] )
				? $args['attributes']
				: [];

			return AI_Ecommerce_Agent_Woo::añadir_al_carrito( $product_id, $quantity, $variation_id, $attributes );
		}

		if ( 'consultar_informacion_negocio' === $name ) {
			return AI_Ecommerce_Agent_Knowledge::consultar_informacion_negocio();
		}

		if ( 'consultar_cobertura_envios' === $name ) {
			$ubicacion = isset( $args['ubicacion'] ) ? sanitize_text_field( (string) $args['ubicacion'] ) : '';

			return AI_Ecommerce_Agent_Knowledge::consultar_cobertura_envios( $ubicacion );
		}

		if ( 'consultar_politicas' === $name ) {
			$tipo     = isset( $args['tipo'] ) ? sanitize_text_field( (string) $args['tipo'] ) : '';
			$consulta = isset( $args['consulta'] ) ? sanitize_text_field( (string) $args['consulta'] ) : '';

			return AI_Ecommerce_Agent_Policies::consultar_politica( $tipo, $consulta );
		}

		if ( 'consultar_faq' === $name ) {
			$consulta = isset( $args['consulta'] ) ? sanitize_text_field( (string) $args['consulta'] ) : '';

			return AI_Ecommerce_Agent_Knowledge::consultar_faq( $consulta );
		}

		if ( 'consultar_horarios_contacto' === $name ) {
			return AI_Ecommerce_Agent_Knowledge::consultar_horarios_contacto();
		}

		if ( 'consultar_metodos_pago' === $name ) {
			$metodo = isset( $args['metodo_consultado'] ) ? sanitize_text_field( (string) $args['metodo_consultado'] ) : '';

			return AI_Ecommerce_Agent_Woo::consultar_metodos_pago( $metodo );
		}

		// Herramienta desconocida: informar al modelo para que gestione el caso.
		return [ [ 'error' => 'Herramienta no reconocida: ' . sanitize_text_field( $name ) ] ];
	}

	/**
	 * Extrae el texto de la respuesta y lo devuelve como resultado final.
	 *
	 * @param  array          $body  Body decodificado del response.
	 * @param  string         $model Nombre del modelo usado.
	 * @return array|WP_Error array('message'=>string,'model'=>string) o WP_Error.
	 */
	private static function build_result( array $body, string $model ) {
		$text = self::extract_text( $body );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		return [ 'message' => $text, 'model' => $model ];
	}

	/**
	 * Extrae el texto generado del body de la respuesta de OpenAI.
	 *
	 * Intenta primero el campo directo output_text y luego recorre output[].
	 *
	 * @param  array          $body Body decodificado.
	 * @return string|WP_Error
	 */
	private static function extract_text( array $body ) {
		// Campo directo disponible en algunas versiones de la API.
		if ( isset( $body['output_text'] ) && is_string( $body['output_text'] ) ) {
			$text = trim( $body['output_text'] );
			if ( '' !== $text ) {
				return $text;
			}
		}

		// Recorrido del array output[].
		if ( ! empty( $body['output'] ) && is_array( $body['output'] ) ) {
			$text = '';
			foreach ( $body['output'] as $item ) {
				if ( empty( $item['content'] ) || ! is_array( $item['content'] ) ) {
					continue;
				}
				foreach ( $item['content'] as $content ) {
					if (
						isset( $content['type'], $content['text'] )
						&& 'output_text' === $content['type']
					) {
						$text .= $content['text'];
					}
				}
			}
			$text = trim( $text );
			if ( '' !== $text ) {
				return $text;
			}
		}

		return new WP_Error(
			'aiea_empty_response',
			'OpenAI respondió, pero no se encontró texto en la respuesta.',
			[ 'status' => 502 ]
		);
	}

	/**
	 * Genera un WP_Error descriptivo para los errores HTTP de la API de OpenAI.
	 *
	 * Los detalles técnicos solo se exponen cuando WP_DEBUG está activo.
	 *
	 * @param  int        $status Código de respuesta HTTP.
	 * @param  array|null $body   Body decodificado (puede ser null).
	 * @return WP_Error
	 */
	private static function openai_http_error( int $status, $body ): WP_Error {
		switch ( $status ) {
			case 401:
				$msg = 'OpenAI rechazó la API Key. Comprueba que esté copiada correctamente y pertenezca al proyecto correcto.';
				break;
			case 429:
				$msg = 'OpenAI indicó un límite de uso o cuota. Revisa la facturación y los límites de la API.';
				break;
			case 400:
				$msg = 'OpenAI no aceptó la solicitud. Comprueba el modelo y los parámetros.';
				break;
			default:
				$msg = 'OpenAI devolvió un error HTTP ' . $status . '.';
		}

		if ( ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || current_user_can( 'manage_options' ) ) && ! empty( $body['error']['message'] ) ) {
			$msg .= ' Detalle: ' . sanitize_text_field( $body['error']['message'] );
		}

		return new WP_Error( 'aiea_openai_error', $msg, [ 'status' => 502 ] );
	}

	/**
	 * Detecta si el mensaje expresa una intención clara de reclamo, queja, cobro indebido o incidencia posventa.
	 *
	 * @param string $message Mensaje del usuario.
	 * @return bool True si corresponde a intención de reclamo.
	 */
	public static function is_claim_intent( string $message ): bool {
		$clean      = mb_strtolower( trim( $message ), 'UTF-8' );
		$normalized = strtr(
			$clean,
			[
				'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
			]
		);

		$patterns = [
			'/\b(reclamo|reclamacion|queja|quejarme)\b/u',
			'/\b(lleg[oó]\s+(da[ñn]ad[oa]|rot[oa]|averiad[oa]|defectuos[oa]|incomplet[oa]|en\s+mal\s+estado|vencid[oa]|abiert[oa]))/u',
			'/\b(producto|pedido|paquete)\s+(da[ñn]ad[oa]|rot[oa]|averiad[oa]|defectuos[oa]|incomplet[oa]|en\s+mal\s+estado|vencid[oa]|abiert[oa])/u',
			'/\b(nunca\s+recib[ií]\s+mi\s+pedido|no\s+(me\s+)?(ha\s+)?llegad[oa]\s+(el|mi)\s+(pedido|paquete|compra))/u',
			'/\b(no\s+llega\s+(el|mi)\s+(pedido|paquete|compra)|(el|mi)\s+(pedido|paquete|compra)\s+no\s+(ha\s+)?llega(d[oa])?)/u',
			'/\b(cobraron\s+dos\s+veces|doble\s+cobro|cobro\s+duplicado|cobro\s+doble|cobro\s+indebido|me\s+descontaron\s+dos\s+veces)\b/u',
			'/\b(solicitar|pedir|quiero|exijo)\s+(un\s+)?reembolso\b/u',
			'/\b(devoluci[oó]n\s+de\s+(mi\s+)?dinero|que\s+me\s+devuelvan\s+(el|mi)\s+dinero)\b/u',
			'/\b(tengo\s+un\s+problema|inconformidad)\s+con\s+(mi|el)\s+(compra|pedido)\b/u',
			'/\bcancelar\s+(un|el|mi)?\s*pedido\s+por\s+(un\s+)?problema\b/u',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $normalized ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detecta si el mensaje expresa una intención comercial clara para reanudar la atención de ventas.
	 *
	 * @param string $message Mensaje del usuario.
	 * @return bool True si es intención comercial sin reclamo.
	 */
	public static function is_commercial_intent( string $message ): bool {
		// Si incluye reclamo, priorizar siempre el reclamo:
		if ( self::is_claim_intent( $message ) ) {
			return false;
		}

		$clean      = mb_strtolower( trim( $message ), 'UTF-8' );
		$normalized = strtr(
			$clean,
			[
				'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
			]
		);

		$patterns = [
			'/\b(muestrame|muestra|ver|mostrar|listar|tienen|que\s+tienen|que\s+hay|venden|catalogo|productos?)\b/u',
			'/\b(proteina|creatina|aminoacido|colageno|vitamina|suplemento|hmb|whey|isolate|preentreno|omega)\b/u',
			'/\b(cuanto\s+cuesta|precio|comprar|anadir|agregar|carrito|disponible|stock)\b/u',
			'/\b(recomiendame|recomienda|busco|quiero\s+(ver|saber|comprar|consultar|adquirir|llevar))\b/u',
			'/\b(esta\s+bien|bueno|ok|vale|listo),?\s+(muestrame|tienen|quiero|cuentame|que|como|vamos)\b/u',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $normalized ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Construye la respuesta respetuosa y empática para derivar un reclamo al correo oficial configurado.
	 *
	 * @return string
	 */
	public static function build_claim_derivation_response(): string {
		$claims_email = class_exists( 'AI_Ecommerce_Agent_Knowledge' )
			? AI_Ecommerce_Agent_Knowledge::get_claims_email()
			: '';

		if ( '' !== $claims_email ) {
			return sprintf(
				'Lamento que hayas tenido este problema. Para que tu caso pueda ser revisado por el equipo responsable, debes escribir a nuestro correo oficial de reclamaciones: %s. Allí podrán indicarte cómo proceder.',
				$claims_email
			);
		}

		return 'Lamento que hayas tenido este problema. Para que tu caso pueda ser revisado por el equipo responsable, por favor comunícate a través de nuestros canales oficiales de atención al cliente. Este chat es exclusivamente un canal de orientación comercial y no está habilitado para gestionar reclamos ni incidencias de compras.';
	}

	/**
	 * Construye la respuesta cuando un cliente insiste en el reclamo por chat o envía datos personales.
	 *
	 * @return string
	 */
	public static function build_claim_continuation_response(): string {
		$claims_email = class_exists( 'AI_Ecommerce_Agent_Knowledge' )
			? AI_Ecommerce_Agent_Knowledge::get_claims_email()
			: '';

		if ( '' !== $claims_email ) {
			return sprintf(
				'Comprendo tu situación, pero este asistente es únicamente un canal de orientación comercial y ventas; no podemos gestionar reclamos, prometer reembolsos ni recibir datos personales o de pago por este medio. Por favor, remite los detalles de tu caso a nuestro correo oficial de reclamaciones: %s para que el equipo responsable te asista.',
				$claims_email
			);
		}

		return 'Comprendo tu situación, pero este asistente es únicamente un canal de orientación comercial y ventas; no podemos gestionar reclamos, prometer reembolsos ni recibir datos personales o de pago por este medio. Por favor, comunícate directamente por nuestros canales oficiales de atención al cliente para que el equipo correspondiente te asista.';
	}
}
