<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Ecommerce_Agent_API {

	/**
	 * Longitud máxima permitida para mensajes en el panel administrativo.
	 */
	const ADMIN_MAX_MESSAGE_LENGTH = 2000;

	/**
	 * Longitud máxima permitida para mensajes en el endpoint público.
	 */
	const PUBLIC_MAX_MESSAGE_LENGTH = 500;

	/**
	 * Tiempo mínimo de espera entre mensajes consecutivos de un visitante tras finalizar una petición (segundos).
	 */
	const RATE_LIMIT_COOLDOWN = 2;

	/**
	 * Tiempo máximo de resguardo para el estado en curso mientras se espera a OpenAI (segundos).
	 */
	const RATE_LIMIT_IN_FLIGHT_TTL = 60;

	/**
	 * Máximo de mensajes permitidos por minuto por IP en el endpoint público.
	 */
	const RATE_LIMIT_PER_MINUTE = 10;

	/**
	 * Máximo de mensajes permitidos por hora por IP en el endpoint público.
	 */
	const RATE_LIMIT_PER_HOUR = 60;

	/**
	 * Registra los endpoints REST del plugin (administrativo y público).
	 */
	public static function register_routes() {
		// Endpoint administrativo de pruebas (restringido a administradores).
		register_rest_route(
			'aiea/v1',
			'/chat',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'chat' ],
				'permission_callback' => [ __CLASS__, 'permissions_check' ],
				'args'                => [
					'message'    => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'session_id' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// Endpoint público independiente para el chat de visitantes de la tienda.
		register_rest_route(
			'aiea/v1',
			'/chat/public',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'public_chat' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'message'    => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'session_id' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// Endpoint público para añadir productos al carrito desde tarjetas interactivas sin invocar a OpenAI.
		register_rest_route(
			'aiea/v1',
			'/cart/add',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'public_add_to_cart' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'product_id'   => [
						'required' => false,
					],
					'id'           => [
						'required' => false,
					],
					'quantity'     => [
						'required'          => false,
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					],
					'variation_id' => [
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					],
					'attributes'   => [
						'required' => false,
						'type'     => 'object',
					],
				],
			]
		);
	}

	/**
	 * Verifica permisos para el endpoint administrativo.
	 *
	 * @param WP_REST_Request $request Petición REST.
	 * @return true|WP_Error
	 */
	public static function permissions_check( $request ) {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'aiea_forbidden',
				'Solo un administrador autenticado puede ejecutar esta prueba.',
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Procesa los mensajes del panel de pruebas administrativo.
	 *
	 * @param WP_REST_Request $request Petición REST.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function chat( WP_REST_Request $request ) {
		$message    = trim( (string) $request->get_param( 'message' ) );
		$session_id = trim( (string) $request->get_param( 'session_id' ) );

		if ( '' === $message ) {
			return new WP_Error( 'aiea_empty_message', 'Escribe un mensaje para probar el agente.', [ 'status' => 400 ] );
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $message ) : strlen( $message );
		if ( $length > self::ADMIN_MAX_MESSAGE_LENGTH ) {
			return new WP_Error( 'aiea_message_too_long', 'El mensaje no puede superar los 2000 caracteres.', [ 'status' => 400 ] );
		}

		$result = AI_Ecommerce_Agent_OpenAI::send_message( $message, $session_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			[
				'success'      => true,
				'message'      => $result['message'],
				'model'        => $result['model'],
				'session_id'   => isset( $result['session_id'] ) ? $result['session_id'] : '',
				'cart_updated' => ! empty( $result['cart_updated'] ),
				'products'     => ! empty( $result['products'] ) ? $result['products'] : [],
			]
		);
	}

	/**
	 * Procesa los mensajes del endpoint público para visitantes de la tienda.
	 *
	 * @param WP_REST_Request $request Petición REST.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function public_chat( WP_REST_Request $request ) {
		// 0. Comprobar método HTTP explícitamente (incluso ante invocaciones directas).
		$method = method_exists( $request, 'get_method' ) ? strtoupper( (string) $request->get_method() ) : 'POST';
		if ( '' !== $method && 'POST' !== $method ) {
			return new WP_Error(
				'aiea_method_not_allowed',
				'Método HTTP no permitido. Utiliza POST.',
				[ 'status' => 405 ]
			);
		}

		// 0.1 Comprobar si el agente público está activado antes de adquirir bloqueos o invocar OpenAI.
		$options = get_option( 'aiea_settings', [] );
		$enabled = is_array( $options ) && ! empty( $options['enabled'] ) && '0' !== (string) $options['enabled'];
		if ( ! $enabled ) {
			return new WP_Error(
				'aiea_agent_disabled',
				'El asistente no está disponible en este momento.',
				[ 'status' => 403 ]
			);
		}

		// 0.2 Validar estructura de los datos recibidos antes de procesarlos.
		$raw_msg_param  = $request->get_param( 'message' );
		$raw_sess_param = $request->get_param( 'session_id' );

		if ( null !== $raw_msg_param && ! is_scalar( $raw_msg_param ) ) {
			if ( class_exists( 'AI_Ecommerce_Agent_Usage' ) ) {
				AI_Ecommerce_Agent_Usage::record_limit_rejection();
			}
			return new WP_Error(
				'aiea_invalid_payload',
				'El formato del mensaje no es válido.',
				[ 'status' => 400 ]
			);
		}

		if ( null !== $raw_sess_param && ! is_scalar( $raw_sess_param ) ) {
			if ( class_exists( 'AI_Ecommerce_Agent_Usage' ) ) {
				AI_Ecommerce_Agent_Usage::record_limit_rejection();
			}
			return new WP_Error(
				'aiea_invalid_session',
				'El identificador de sesión no es válido.',
				[ 'status' => 400 ]
			);
		}

		$client_ip = self::get_client_ip();
		$raw_sess  = trim( (string) $raw_sess_param );
		$message   = trim( (string) $raw_msg_param );

		$effective_limits = class_exists( 'AI_Ecommerce_Agent_Usage' )
			? AI_Ecommerce_Agent_Usage::get_effective_limits()
			: [
				'min_interval_seconds'       => self::RATE_LIMIT_COOLDOWN,
				'max_messages_per_ip_minute' => self::RATE_LIMIT_PER_MINUTE,
				'max_messages_per_ip_hour'   => self::RATE_LIMIT_PER_HOUR,
				'max_message_length'         => self::PUBLIC_MAX_MESSAGE_LENGTH,
			];

		$max_message_length = max( 50, (int) ( $effective_limits['max_message_length'] ?? self::PUBLIC_MAX_MESSAGE_LENGTH ) );
		$cooldown_seconds   = max( 1, (int) ( $effective_limits['min_interval_seconds'] ?? self::RATE_LIMIT_COOLDOWN ) );

		if ( class_exists( 'AI_Ecommerce_Agent_Usage' ) ) {
			AI_Ecommerce_Agent_Usage::record_public_request();
		}

		// 1. Validar el token de sesión si el cliente envió uno; rechazar valores arbitrarios o sin firma válida.
		$is_new_token = ( '' === $raw_sess );
		if ( ! $is_new_token ) {
			if ( ! AI_Ecommerce_Agent_Session::validate_signed_token( $raw_sess, $client_ip ) ) {
				if ( class_exists( 'AI_Ecommerce_Agent_Usage' ) ) {
					AI_Ecommerce_Agent_Usage::record_limit_rejection();
				}
				return new WP_Error(
					'aiea_invalid_session',
					'El identificador de sesión no es válido o ha expirado.',
					[ 'status' => 400 ]
				);
			}
			$session_id = $raw_sess;
		} else {
			$session_id = AI_Ecommerce_Agent_Session::generate_signed_token( $client_ip );
		}

		// 2. Validar contenido y longitud del mensaje entrante.
		if ( '' === $message ) {
			return new WP_Error(
				'aiea_empty_message',
				'Por favor, escribe un mensaje.',
				[ 'status' => 400 ]
			);
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $message, 'UTF-8' ) : strlen( $message );
		if ( $length > $max_message_length ) {
			if ( class_exists( 'AI_Ecommerce_Agent_Usage' ) ) {
				AI_Ecommerce_Agent_Usage::record_limit_rejection();
			}
			return new WP_Error(
				'aiea_message_too_long',
				sprintf( 'El mensaje no puede superar los %d caracteres.', $max_message_length ),
				[ 'status' => 400 ]
			);
		}

		// 3. Adquirir bloqueo exclusivo por IP antes de evaluar o modificar el limitador.
		$lock_handle = self::acquire_ip_lock( $client_ip );
		if ( is_wp_error( $lock_handle ) ) {
			if ( 'aiea_rate_limit_exceeded' === $lock_handle->get_error_code() && class_exists( 'AI_Ecommerce_Agent_Usage' ) ) {
				AI_Ecommerce_Agent_Usage::record_limit_rejection();
			}
			return $lock_handle;
		}

		$rate_limit_passed = false;

		try {
			// 4. Evaluar controles de frecuencia (cooldown, mensajes/minuto y mensajes/hora por IP) bajo exclusión mutua.
			$rate_check = self::check_rate_limit( $client_ip, $session_id, $effective_limits );
			if ( is_wp_error( $rate_check ) ) {
				if ( class_exists( 'AI_Ecommerce_Agent_Usage' ) ) {
					AI_Ecommerce_Agent_Usage::record_limit_rejection();
				}
				return $rate_check;
			}

			// 5. Evaluar controles de consumo y abuso (presupuesto diario, límite por sesión, sesiones nuevas/hora y duplicados).
			if ( class_exists( 'AI_Ecommerce_Agent_Usage' ) ) {
				$usage_check = AI_Ecommerce_Agent_Usage::check_public_consumption_limits(
					$client_ip,
					$session_id,
					$is_new_token,
					$message
				);
				if ( is_wp_error( $usage_check ) ) {
					return $usage_check;
				}
			}

			// 5.1 Confirmar y persistir los contadores de frecuencia y de sesión una vez superadas todas las validaciones.
			self::commit_rate_limit( $rate_check );
			$rate_limit_passed = true;

			if ( class_exists( 'AI_Ecommerce_Agent_Usage' ) ) {
				AI_Ecommerce_Agent_Usage::commit_session_message( $session_id, $message );
			}

			// 6. Enviar mensaje al agente manteniendo aislada la sesión y capturando cualquier excepción inesperada.
			try {
				$result = AI_Ecommerce_Agent_OpenAI::send_message( $message, $session_id );
			} catch ( Throwable $e ) {
				$result = new WP_Error( 'aiea_internal_error', 'Error interno procesando la consulta.' );
			}

			if ( is_wp_error( $result ) ) {
				$err_code = $result->get_error_code();
				if ( in_array( $err_code, [ 'aiea_daily_calls_limit', 'aiea_daily_tokens_limit' ], true ) ) {
					if ( class_exists( 'AI_Ecommerce_Agent_Usage' ) ) {
						AI_Ecommerce_Agent_Usage::revert_session_message( $session_id );
					}
					$rate_limit_passed = false;

					return $result;
				}

				// No exponer errores internos de configuración, rutas ni detalles de OpenAI al público.
				return new WP_Error(
					'aiea_service_unavailable',
					'El asistente no está disponible en este momento. Por favor, inténtalo de nuevo en unos instantes.',
					[ 'status' => 503 ]
				);
			}

			$cart_updated = ! empty( $result['cart_updated'] );

			return rest_ensure_response(
				[
					'success'      => true,
					'message'      => $result['message'],
					'session_id'   => $session_id,
					'cart_updated' => $cart_updated,
					'cart'         => $cart_updated ? self::get_current_cart_data() : null,
					'products'     => ! empty( $result['products'] ) ? $result['products'] : [],
				]
			);
		} finally {
			// Registrar el cooldown desde la finalización de la petición aceptada y liberar siempre el bloqueo.
			if ( $rate_limit_passed ) {
				self::record_cooldown( $client_ip, $session_id, $cooldown_seconds );
			}
			self::release_ip_lock( $lock_handle );
		}
	}

	/**
	 * Obtiene desde WooCommerce el estado actual del carrito del visitante tras una actualización exitosa.
	 *
	 * @return array{count:int,subtotal:string,cart_hash:string}
	 */
	private static function get_current_cart_data(): array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->cart ) || ! is_object( WC()->cart ) ) {
			return [
				'count'     => 0,
				'subtotal'  => '',
				'cart_hash' => '',
			];
		}

		$count     = method_exists( WC()->cart, 'get_cart_contents_count' )
			? (int) WC()->cart->get_cart_contents_count()
			: 0;
		$subtotal  = method_exists( WC()->cart, 'get_cart_subtotal' )
			? wp_kses_post( (string) WC()->cart->get_cart_subtotal() )
			: '';
		$cart_hash = method_exists( WC()->cart, 'get_cart_hash' )
			? (string) WC()->cart->get_cart_hash()
			: '';

		return [
			'count'        => $count,
			'subtotal'     => $subtotal,
			'cart_hash'    => $cart_hash,
			'url_carrito'  => function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : '',
			'url_checkout' => function_exists( 'wc_get_checkout_url' ) ? (string) wc_get_checkout_url() : '',
		];
	}

	/**
	 * Adquiere un bloqueo de archivo exclusivo y no bloqueante por dirección IP.
	 *
	 * @param string $client_ip Dirección IP del visitante.
	 * @return resource|WP_Error Descriptor de archivo bloqueado o WP_Error si no se puede adquirir.
	 */
	private static function acquire_ip_lock( string $client_ip ) {
		if ( ! function_exists( 'flock' ) ) {
			return new WP_Error(
				'aiea_lock_unavailable',
				'El asistente no está disponible en este momento. Por favor, inténtalo de nuevo en unos instantes.',
				[ 'status' => 503 ]
			);
		}

		$temp_dir = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
		if ( ! is_string( $temp_dir ) || '' === $temp_dir || ! @is_dir( $temp_dir ) || ! wp_is_writable( $temp_dir ) ) {
			return new WP_Error(
				'aiea_lock_unavailable',
				'El asistente no está disponible en este momento. Por favor, inténtalo de nuevo en unos instantes.',
				[ 'status' => 503 ]
			);
		}

		$lock_file = trailingslashit( $temp_dir ) . 'aiea_rl_lock_' . md5( $client_ip ) . '.lock';
		$handle    = @fopen( $lock_file, 'c+' );

		if ( false === $handle || ! is_resource( $handle ) ) {
			return new WP_Error(
				'aiea_lock_unavailable',
				'El asistente no está disponible en este momento. Por favor, inténtalo de nuevo en unos instantes.',
				[ 'status' => 503 ]
			);
		}

		$would_block = 0;
		$locked      = @flock( $handle, LOCK_EX | LOCK_NB, $would_block );

		if ( ! $locked ) {
			@fclose( $handle );

			if ( $would_block ) {
				return new WP_Error(
					'aiea_rate_limit_exceeded',
					'Estás enviando mensajes demasiado rápido. Por favor, espera unos segundos.',
					[ 'status' => 429 ]
				);
			}

			return new WP_Error(
				'aiea_lock_unavailable',
				'El asistente no está disponible en este momento. Por favor, inténtalo de nuevo en unos instantes.',
				[ 'status' => 503 ]
			);
		}

		return $handle;
	}

	/**
	 * Libera y cierra de forma segura el descriptor de bloqueo por IP.
	 *
	 * @param resource $lock_handle Descriptor de archivo obtenido en acquire_ip_lock().
	 * @return void
	 */
	private static function release_ip_lock( $lock_handle ): void {
		if ( is_resource( $lock_handle ) ) {
			@flock( $lock_handle, LOCK_UN );
			@fclose( $lock_handle );
		}
	}

	/**
	 * Comprueba los límites de frecuencia de solicitudes por visitante sin escribir transients todavía.
	 * Debe ejecutarse bajo el bloqueo adquirido con acquire_ip_lock().
	 *
	 * @param string $client_ip        Dirección IP del visitante.
	 * @param string $session_id       Token de sesión verificado.
	 * @param array  $effective_limits Límites efectivos según el modo de la instalación.
	 * @return array|WP_Error Datos preparados para commit_rate_limit() o WP_Error si se supera algún límite.
	 */
	private static function check_rate_limit( string $client_ip, string $session_id, array $effective_limits = [] ) {
		$now_micro        = microtime( true );
		$now              = time();
		$ip_hash          = md5( $client_ip );
		$sess_hash        = md5( $session_id );
		$cooldown_seconds = max( 1, (int) ( $effective_limits['min_interval_seconds'] ?? self::RATE_LIMIT_COOLDOWN ) );
		$max_per_minute   = max( 1, (int) ( $effective_limits['max_messages_per_ip_minute'] ?? self::RATE_LIMIT_PER_MINUTE ) );
		$max_per_hour     = max( 1, (int) ( $effective_limits['max_messages_per_ip_hour'] ?? self::RATE_LIMIT_PER_HOUR ) );

		// 1. Cooldown por IP y por sesión (cubre peticiones en vuelo y el intervalo mínimo tras la finalización).
		$cd_ip_key   = 'aiea_rl_cd_' . $ip_hash;
		$cd_sess_key = 'aiea_rl_cds_' . $sess_hash;

		$cd_ip_val   = get_transient( $cd_ip_key );
		$cd_sess_val = get_transient( $cd_sess_key );

		if (
			self::is_cooldown_active( $cd_ip_val, $now_micro, $cooldown_seconds )
			|| self::is_cooldown_active( $cd_sess_val, $now_micro, $cooldown_seconds )
		) {
			return new WP_Error(
				'aiea_rate_limit_exceeded',
				'Estás enviando mensajes demasiado rápido. Por favor, espera unos segundos.',
				[ 'status' => 429 ]
			);
		}

		// 2. Límite por minuto por IP.
		$min_key  = 'aiea_rl_m_' . $ip_hash;
		$min_data = get_transient( $min_key );
		if ( is_array( $min_data ) && isset( $min_data['count'], $min_data['start'] ) && ( $now - $min_data['start'] ) < 60 ) {
			if ( $min_data['count'] >= $max_per_minute ) {
				return new WP_Error(
					'aiea_rate_limit_exceeded',
					'Has alcanzado el límite de mensajes por minuto. Por favor, espera un momento antes de continuar.',
					[ 'status' => 429 ]
				);
			}
			$min_data['count']++;
			$min_ttl = max( 1, 60 - ( $now - $min_data['start'] ) );
		} else {
			$min_data = [
				'count' => 1,
				'start' => $now,
			];
			$min_ttl = 60;
		}

		// 3. Límite por hora por IP.
		$hr_key  = 'aiea_rl_h_' . $ip_hash;
		$hr_data = get_transient( $hr_key );
		if ( is_array( $hr_data ) && isset( $hr_data['count'], $hr_data['start'] ) && ( $now - $hr_data['start'] ) < 3600 ) {
			if ( $hr_data['count'] >= $max_per_hour ) {
				return new WP_Error(
					'aiea_rate_limit_exceeded',
					'Has superado el límite de consultas permitido por ahora. Inténtalo más tarde.',
					[
						'status'     => 429,
						'limit_type' => 'ip_hourly',
					]
				);
			}
			$hr_data['count']++;
			$hr_ttl = max( 1, 3600 - ( $now - $hr_data['start'] ) );
		} else {
			$hr_data = [
				'count' => 1,
				'start' => $now,
			];
			$hr_ttl = 3600;
		}

		return [
			'cd_ip_key'   => $cd_ip_key,
			'cd_sess_key' => $cd_sess_key,
			'now_micro'   => $now_micro,
			'min_key'     => $min_key,
			'min_data'    => $min_data,
			'min_ttl'     => $min_ttl,
			'hr_key'      => $hr_key,
			'hr_data'     => $hr_data,
			'hr_ttl'      => $hr_ttl,
		];
	}

	/**
	 * Persiste los contadores de frecuencia por IP y marca la petición como `in_flight`.
	 *
	 * @param array $rate_state Estado devuelto por check_rate_limit().
	 * @return void
	 */
	private static function commit_rate_limit( array $rate_state ): void {
		$in_flight_data = [
			'state' => 'in_flight',
			'ts'    => $rate_state['now_micro'],
		];

		set_transient( $rate_state['cd_ip_key'], $in_flight_data, self::RATE_LIMIT_IN_FLIGHT_TTL );
		set_transient( $rate_state['cd_sess_key'], $in_flight_data, self::RATE_LIMIT_IN_FLIGHT_TTL );
		set_transient( $rate_state['min_key'], $rate_state['min_data'], $rate_state['min_ttl'] );
		set_transient( $rate_state['hr_key'], $rate_state['hr_data'], $rate_state['hr_ttl'] );
	}

	/**
	 * Determina si un registro de cooldown (en vuelo o completado) continúa activo.
	 *
	 * @param mixed $cooldown_data    Valor almacenado en el transient de cooldown.
	 * @param float $now_micro        Marca de tiempo actual en segundos con microsegundos.
	 * @param int   $cooldown_seconds Segundos mínimos de intervalo tras completar una petición.
	 * @return bool
	 */
	private static function is_cooldown_active( $cooldown_data, float $now_micro, int $cooldown_seconds = self::RATE_LIMIT_COOLDOWN ): bool {
		if ( false === $cooldown_data ) {
			return false;
		}

		if ( is_array( $cooldown_data ) && isset( $cooldown_data['state'], $cooldown_data['ts'] ) ) {
			$elapsed = $now_micro - (float) $cooldown_data['ts'];

			if ( 'in_flight' === $cooldown_data['state'] ) {
				return $elapsed < self::RATE_LIMIT_IN_FLIGHT_TTL;
			}

			return $elapsed < $cooldown_seconds;
		}

		return true;
	}

	/**
	 * Registra el cooldown a partir del instante exacto en que finaliza la petición.
	 *
	 * @param string $client_ip        Dirección IP del visitante.
	 * @param string $session_id       Token de sesión verificado.
	 * @param int    $cooldown_seconds Segundos de cooldown configurados.
	 * @return void
	 */
	private static function record_cooldown( string $client_ip, string $session_id, int $cooldown_seconds = self::RATE_LIMIT_COOLDOWN ): void {
		$completed_data = [
			'state' => 'completed',
			'ts'    => microtime( true ),
		];
		$ttl            = max( 1, $cooldown_seconds ) + 2;

		set_transient( 'aiea_rl_cd_' . md5( $client_ip ), $completed_data, $ttl );
		set_transient( 'aiea_rl_cds_' . md5( $session_id ), $completed_data, $ttl );
	}

	/**
	 * Obtiene la dirección IP del cliente desde REMOTE_ADDR para evitar suplantación por cabeceras.
	 *
	 * @return string Dirección IP validada.
	 */
	private static function get_client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}

		return '0.0.0.0';
	}

	/**
	 * Añade un producto al carrito directamente desde la interfaz del chat (tarjetas de productos)
	 * validando existencia, stock y variaciones en el servidor sin consumir tokens de OpenAI.
	 *
	 * @param WP_REST_Request $request Petición REST.
	 * @return WP_REST_Response
	 */
	public static function public_add_to_cart( WP_REST_Request $request ) {
		$json_params = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : [];
		$json_params = is_array( $json_params ) ? $json_params : [];

		$raw_prod_id = $request->get_param( 'product_id' );
		if ( null === $raw_prod_id || '' === $raw_prod_id ) {
			$raw_prod_id = $request->get_param( 'id' );
		}
		if ( null === $raw_prod_id || '' === $raw_prod_id ) {
			$raw_prod_id = $request->get_param( 'producto_id' );
		}
		if ( null === $raw_prod_id || '' === $raw_prod_id ) {
			$raw_prod_id = $json_params['product_id'] ?? $json_params['id'] ?? $json_params['producto_id'] ?? null;
		}

		$product_id   = is_scalar( $raw_prod_id ) ? ( is_numeric( $raw_prod_id ) ? (int) $raw_prod_id : trim( (string) $raw_prod_id ) ) : 0;
		$raw_qty      = $request->get_param( 'quantity' ) ?: ( $json_params['quantity'] ?? 1 );
		$quantity     = max( 1, (int) $raw_qty );
		$variation_id = (int) ( $request->get_param( 'variation_id' ) ?: ( $json_params['variation_id'] ?? 0 ) );
		$raw_attrs    = $request->get_param( 'attributes' ) ?: ( $json_params['attributes'] ?? [] );
		$attributes   = is_array( $raw_attrs ) ? $raw_attrs : [];

		if ( ! class_exists( 'AI_Ecommerce_Agent_Woo' ) ) {
			return rest_ensure_response(
				[
					'success' => false,
					'codigo'  => 'woo_no_disponible',
					'mensaje' => 'El sistema de comercio electrónico no está disponible actualmente.',
				]
			);
		}

		$result = AI_Ecommerce_Agent_Woo::añadir_al_carrito( $product_id, $quantity, $variation_id, $attributes );

		if ( ! empty( $result['exito'] ) ) {
			return rest_ensure_response(
				[
					'success'             => true,
					'codigo'              => 'producto_anadido',
					'mensaje'             => isset( $result['mensaje'] ) ? $result['mensaje'] : 'Producto añadido al carrito con éxito.',
					'total_items_carrito' => isset( $result['total_items_carrito'] ) ? (int) $result['total_items_carrito'] : 0,
					'subtotal_carrito'    => isset( $result['subtotal_carrito'] ) ? $result['subtotal_carrito'] : '',
					'url_carrito'         => isset( $result['url_carrito'] ) ? $result['url_carrito'] : ( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '' ),
					'url_checkout'        => isset( $result['url_checkout'] ) ? $result['url_checkout'] : ( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '' ),
					'url_producto'        => isset( $result['url_producto'] ) ? $result['url_producto'] : '',
					'cart'                => self::get_current_cart_data(),
				]
			);
		}

		return rest_ensure_response(
			[
				'success'      => false,
				'codigo'       => isset( $result['codigo'] ) ? $result['codigo'] : 'error_anadir_carrito',
				'mensaje'      => isset( $result['mensaje'] ) ? $result['mensaje'] : 'No se pudo añadir el producto al carrito.',
				'url_producto' => isset( $result['url_producto'] ) ? $result['url_producto'] : '',
			]
		);
	}
}
