<?php
/**
 * AI Ecommerce Agent — Control de consumo, límites operativos y estadísticas básicas.
 *
 * Implementa:
 * - Límite configurable de mensajes por sesión pública (independiente del historial truncado).
 * - Límite configurable de sesiones nuevas por IP y por hora.
 * - Detección de mensajes duplicados consecutivos sin bloquear respuestas cortas naturales.
 * - Contabilización real de llamadas y tokens (input/output) informados por OpenAI.
 * - Límites diarios de llamadas y tokens con bloqueo atómico de concurrencia.
 * - Estadísticas básicas con purga automática de días antiguos (máximo 30 días).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Ecommerce_Agent_Usage {

	const OPTION_LIMITS = 'aiea_usage_limits';
	const OPTION_DEMO   = 'aiea_demo_settings';
	const OPTION_STATS  = 'aiea_usage_stats';

	/**
	 * Días máximos de histórico diario conservados en la opción de estadísticas.
	 */
	const MAX_DAILY_HISTORY_DAYS = 30;

	/**
	 * Tiempo de expiración en segundos para reservas temporales de capacidad en vuelo hacia OpenAI.
	 */
	const RESERVATION_TTL = 90;

	/**
	 * Reserva conservadora mínima de tokens por llamada antes de conocer el `usage` real de OpenAI.
	 */
	const MIN_TOKEN_RESERVATION = 600;

	/**
	 * Mensaje público al alcanzar el límite por sesión en modo demostración (Fase 6).
	 */
	const DEMO_SESSION_LIMIT_MESSAGE = 'Has alcanzado el límite de consultas de esta demostración. Si quieres implementar Jora IA en tu tienda WooCommerce, conoce nuestros planes o solicita una instalación personalizada.';

	/**
	 * Mensaje público al alcanzar el límite global diario en modo demostración (Fase 3.A / Fase 6).
	 */
	const DEMO_DAILY_LIMIT_MESSAGE = 'La demostración pública ha alcanzado temporalmente su capacidad máxima de consultas por hoy. Por favor, vuelve a intentarlo más tarde.';

	// =========================================================
	// 1. Configuración de límites comerciales y de demostración pública
	// =========================================================

	/**
	 * Devuelve los valores predeterminados conservadores para instalaciones comerciales.
	 * Un valor de 0 representa expresamente una modalidad sin límite para ese control.
	 *
	 * @return array<string,int>
	 */
	public static function get_limits_defaults(): array {
		return [
			'max_messages_per_session'     => 20,
			'max_new_sessions_per_ip_hour' => 10,
			'max_daily_openai_calls'       => 300,
			'max_daily_tokens'             => 150000,
			'duplicate_window_seconds'     => 60,
		];
	}

	/**
	 * Obtiene los límites de consumo comerciales configurados actualmente (`aiea_usage_limits`).
	 *
	 * @return array<string,int>
	 */
	public static function get_limits(): array {
		$saved    = get_option( self::OPTION_LIMITS, [] );
		$saved    = is_array( $saved ) ? $saved : [];
		$defaults = self::get_limits_defaults();
		$merged   = wp_parse_args( $saved, $defaults );

		return [
			'max_messages_per_session'     => max( 0, (int) $merged['max_messages_per_session'] ),
			'max_new_sessions_per_ip_hour' => max( 0, (int) $merged['max_new_sessions_per_ip_hour'] ),
			'max_daily_openai_calls'       => max( 0, (int) $merged['max_daily_openai_calls'] ),
			'max_daily_tokens'             => max( 0, (int) $merged['max_daily_tokens'] ),
			'duplicate_window_seconds'     => max( 0, min( 600, (int) $merged['duplicate_window_seconds'] ) ),
		];
	}

	/**
	 * Devuelve los valores iniciales propuestos para el perfil de Demostración Pública (NUTRIX / Jora IA).
	 * Por defecto `enabled` es 0 en código para no alterar silenciosamente instalaciones comerciales.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_demo_defaults(): array {
		return [
			'enabled'                      => 0,
			'max_messages_per_session'     => 10,
			'max_new_sessions_per_ip_hour' => 3,
			'min_interval_seconds'         => 3,
			'max_messages_per_ip_hour'     => 15,
			'max_daily_openai_calls'       => 100,
			'max_daily_tokens'             => 150000,
			'max_message_length'           => 500,
			'max_output_tokens'            => 350,
			'duplicate_window_seconds'     => 60,
			'cta_pro_label'                => 'Conocer Jora IA Pro',
			'cta_pro_url'                  => '',
			'cta_install_label'            => 'Solicitar instalación',
			'cta_install_url'              => '',
		];
	}

	/**
	 * Obtiene la configuración específica de la demostración pública (`aiea_demo_settings`).
	 *
	 * @return array<string,mixed>
	 */
	public static function get_demo_settings(): array {
		$raw_saved = get_option( self::OPTION_DEMO, null );
		$defaults  = self::get_demo_defaults();

		if ( ! is_array( $raw_saved ) ) {
			$biz_info = get_option( 'aiea_business_info', [] );
			$biz_name = is_array( $biz_info ) && isset( $biz_info['business_name'] )
				? (string) $biz_info['business_name']
				: '';

			if ( '' !== $biz_name && false !== stripos( $biz_name, 'nutrix' ) ) {
				$defaults['enabled'] = 1;
				update_option( self::OPTION_DEMO, $defaults, false );
			}
			$saved = [];
		} else {
			$saved = $raw_saved;
			// Migración automática del valor heredado de tokens diarios si tenía el valor previo (30.000) o inferior.
			if ( isset( $saved['max_daily_tokens'] ) && (int) $saved['max_daily_tokens'] <= 30000 ) {
				$saved['max_daily_tokens'] = 150000;
				update_option( self::OPTION_DEMO, $saved, false );
			}
		}

		$merged = wp_parse_args( $saved, $defaults );

		$enabled = ! empty( $merged['enabled'] ) && '0' !== (string) $merged['enabled'] ? 1 : 0;
		if ( defined( 'AIEA_DEMO_MODE' ) ) {
			$enabled = AIEA_DEMO_MODE ? 1 : 0;
		}

		$pro_label     = trim( sanitize_text_field( (string) $merged['cta_pro_label'] ) );
		$install_label = trim( sanitize_text_field( (string) $merged['cta_install_label'] ) );

		return [
			'enabled'                      => $enabled,
			'max_messages_per_session'     => max( 1, min( 1000, (int) $merged['max_messages_per_session'] ) ),
			'max_new_sessions_per_ip_hour' => max( 1, min( 1000, (int) $merged['max_new_sessions_per_ip_hour'] ) ),
			'min_interval_seconds'         => max( 1, min( 60, (int) $merged['min_interval_seconds'] ) ),
			'max_messages_per_ip_hour'     => max( 1, min( 1000, (int) $merged['max_messages_per_ip_hour'] ) ),
			'max_daily_openai_calls'       => max( 1, min( 1000000, (int) $merged['max_daily_openai_calls'] ) ),
			'max_daily_tokens'             => max( 500, min( 100000000, (int) $merged['max_daily_tokens'] ) ),
			'max_message_length'           => max( 50, min( 2000, (int) $merged['max_message_length'] ) ),
			'max_output_tokens'            => max( 100, min( 1000, (int) $merged['max_output_tokens'] ) ),
			'duplicate_window_seconds'     => max( 0, min( 600, (int) $merged['duplicate_window_seconds'] ) ),
			'cta_pro_label'                => '' !== $pro_label ? $pro_label : $defaults['cta_pro_label'],
			'cta_pro_url'                  => '' !== trim( (string) $merged['cta_pro_url'] ) ? esc_url_raw( trim( (string) $merged['cta_pro_url'] ) ) : '',
			'cta_install_label'            => '' !== $install_label ? $install_label : $defaults['cta_install_label'],
			'cta_install_url'              => '' !== trim( (string) $merged['cta_install_url'] ) ? esc_url_raw( trim( (string) $merged['cta_install_url'] ) ) : '',
		];
	}

	/**
	 * Determina en el servidor si la instalación actual opera en modo Demostración Pública.
	 * Nunca depende de parámetros enviados desde el navegador.
	 *
	 * @return bool
	 */
	public static function is_demo_mode(): bool {
		if ( defined( 'AIEA_DEMO_MODE' ) ) {
			return (bool) AIEA_DEMO_MODE;
		}

		$demo = self::get_demo_settings();

		return ! empty( $demo['enabled'] );
	}

	/**
	 * Devuelve los límites efectivos activos en el servidor según el modo de la instalación (Demo vs. Comercial).
	 *
	 * @return array<string,mixed>
	 */
	public static function get_effective_limits(): array {
		if ( self::is_demo_mode() ) {
			$demo = self::get_demo_settings();

			return [
				'mode'                         => 'demo',
				'is_demo'                      => true,
				'max_messages_per_session'     => (int) $demo['max_messages_per_session'],
				'max_new_sessions_per_ip_hour' => (int) $demo['max_new_sessions_per_ip_hour'],
				'min_interval_seconds'         => (int) $demo['min_interval_seconds'],
				'max_messages_per_ip_minute'   => min( 10, (int) $demo['max_messages_per_ip_hour'] ),
				'max_messages_per_ip_hour'     => (int) $demo['max_messages_per_ip_hour'],
				'max_daily_openai_calls'       => (int) $demo['max_daily_openai_calls'],
				'max_daily_tokens'             => (int) $demo['max_daily_tokens'],
				'max_message_length'           => (int) $demo['max_message_length'],
				'max_output_tokens'            => (int) $demo['max_output_tokens'],
				'duplicate_window_seconds'     => (int) $demo['duplicate_window_seconds'],
			];
		}

		$comm = self::get_limits();

		return [
			'mode'                         => 'commercial',
			'is_demo'                      => false,
			'max_messages_per_session'     => (int) $comm['max_messages_per_session'],
			'max_new_sessions_per_ip_hour' => (int) $comm['max_new_sessions_per_ip_hour'],
			'min_interval_seconds'         => class_exists( 'AI_Ecommerce_Agent_API' ) ? AI_Ecommerce_Agent_API::RATE_LIMIT_COOLDOWN : 2,
			'max_messages_per_ip_minute'   => class_exists( 'AI_Ecommerce_Agent_API' ) ? AI_Ecommerce_Agent_API::RATE_LIMIT_PER_MINUTE : 10,
			'max_messages_per_ip_hour'     => class_exists( 'AI_Ecommerce_Agent_API' ) ? AI_Ecommerce_Agent_API::RATE_LIMIT_PER_HOUR : 60,
			'max_daily_openai_calls'       => (int) $comm['max_daily_openai_calls'],
			'max_daily_tokens'             => (int) $comm['max_daily_tokens'],
			'max_message_length'           => class_exists( 'AI_Ecommerce_Agent_API' ) ? AI_Ecommerce_Agent_API::PUBLIC_MAX_MESSAGE_LENGTH : 500,
			'max_output_tokens'            => class_exists( 'AI_Ecommerce_Agent_OpenAI' ) ? AI_Ecommerce_Agent_OpenAI::MAX_OUTPUT_TOKENS : 500,
			'duplicate_window_seconds'     => (int) $comm['duplicate_window_seconds'],
		];
	}

	/**
	 * Devuelve los botones/enlaces comerciales configurables para el aviso de fin de sesión de la demo.
	 * No inventa URLs definitivas cuando están vacías.
	 *
	 * @return array<int,array{id:string,label:string,url:string}>
	 */
	public static function get_demo_ctas(): array {
		$demo = self::get_demo_settings();

		return [
			[
				'id'    => 'jora_pro',
				'label' => (string) $demo['cta_pro_label'],
				'url'   => (string) $demo['cta_pro_url'],
			],
			[
				'id'    => 'request_install',
				'label' => (string) $demo['cta_install_label'],
				'url'   => (string) $demo['cta_install_url'],
			],
		];
	}

	// =========================================================
	// 2. Verificación previa de límites en el endpoint público
	// =========================================================

	/**
	 * Comprueba todos los límites de consumo antes de invocar OpenAI en el endpoint público:
	 * - Presupuesto diario global de llamadas y tokens de OpenAI (incluyendo reservas activas y estimación preventiva).
	 * - Límite de sesiones nuevas por IP y por hora.
	 * - Límite de mensajes por sesión pública (contador independiente del historial).
	 * - Detección de mensajes duplicados consecutivos.
	 *
	 * @param string $client_ip        Dirección IP del visitante.
	 * @param string $session_id       Token de sesión validado.
	 * @param bool   $is_new_token     True si el servidor acaba de emitir un nuevo token porque el visitante no envió uno.
	 * @param string $message          Mensaje del visitante.
	 * @param int    $estimated_tokens Estimación de tokens requeridos (0 para cálculo automático coherente).
	 * @return true|WP_Error True si pasa todas las validaciones o WP_Error con código HTTP apropiado.
	 */
	public static function check_public_consumption_limits(
		string $client_ip,
		string $session_id,
		bool $is_new_token,
		string $message,
		int $estimated_tokens = 0
	) {
		$limits  = self::get_effective_limits();
		$is_demo = ! empty( $limits['is_demo'] );
		$base_id = self::extract_base_session_id( $session_id );

		if ( '' === $base_id ) {
			self::record_limit_rejection();
			return new WP_Error(
				'aiea_invalid_session',
				'El identificador de sesión no es válido.',
				[ 'status' => 400 ]
			);
		}

		// 1. Comprobar límites diarios globales (llamadas y tokens + reservas en vuelo) bajo bloqueo exclusivo global.
		$global_lock = self::acquire_global_lock();
		if ( is_wp_error( $global_lock ) ) {
			return $global_lock;
		}

		try {
			$stats     = self::read_stats_raw();
			$today_key = self::get_today_key();
			self::purge_expired_reservations( $stats, $today_key );

			$today           = $stats['daily'][ $today_key ] ?? self::get_empty_counters();
			$active_reserved = self::sum_active_reservations( $stats, $today_key );
			$effective_calls = (int) $today['openai_calls'] + $active_reserved['calls'];
			$effective_toks  = (int) $today['total_tokens'] + $active_reserved['tokens'];

			if ( $limits['max_daily_openai_calls'] > 0 && $effective_calls >= $limits['max_daily_openai_calls'] ) {
				self::increment_stat_in_memory( $stats, 'rejected_by_limits', 1 );
				self::write_stats_raw( $stats );

				return self::build_daily_limit_error( 'aiea_daily_calls_limit', $is_demo );
			}

			if ( $estimated_tokens <= 0 ) {
				$estimated_tokens = self::estimate_initial_tokens( $message, $session_id );
			}
			$needed_tokens = max( self::MIN_TOKEN_RESERVATION, $estimated_tokens );

			if ( $limits['max_daily_tokens'] > 0 ) {
				$needed_tokens = min( $needed_tokens, (int) $limits['max_daily_tokens'] );

				if (
					$effective_toks >= $limits['max_daily_tokens']
					|| ( $effective_toks > 0 && ( $effective_toks + $needed_tokens ) > $limits['max_daily_tokens'] )
				) {
					self::increment_stat_in_memory( $stats, 'rejected_by_limits', 1 );
					self::write_stats_raw( $stats );

					return self::build_daily_limit_error( 'aiea_daily_tokens_limit', $is_demo );
				}
			}
		} finally {
			self::release_global_lock( $global_lock );
		}

		// 2. Comprobar límite de mensajes por sesión (almacenado independientemente del historial truncado).
		$sess_cnt_key = 'aiea_scnt_' . $base_id;
		$sess_count   = (int) get_transient( $sess_cnt_key );

		if ( $limits['max_messages_per_session'] > 0 && $sess_count >= $limits['max_messages_per_session'] ) {
			self::record_limit_rejection();

			if ( $is_demo ) {
				return new WP_Error(
					'aiea_session_messages_limit',
					self::DEMO_SESSION_LIMIT_MESSAGE,
					[
						'status'     => 429,
						'limit_type' => 'session_demo',
						'ctas'       => self::get_demo_ctas(),
					]
				);
			}

			return new WP_Error(
				'aiea_session_messages_limit',
				'Has alcanzado el número máximo de mensajes permitidos en esta conversación.',
				[
					'status'     => 429,
					'limit_type' => 'session',
				]
			);
		}

		// 3. Comprobar límite de sesiones nuevas por IP y por hora (sin depender del historial de mensajes).
		$sess_reg_key  = 'aiea_sreg_' . $base_id;
		$is_registered = ( false !== get_transient( $sess_reg_key ) );
		$pending_ns    = null;

		if ( $is_new_token || ! $is_registered ) {
			if ( $limits['max_new_sessions_per_ip_hour'] > 0 ) {
				$now     = time();
				$ns_key  = 'aiea_nsess_' . md5( $client_ip );
				$ns_data = get_transient( $ns_key );

				if ( is_array( $ns_data ) && isset( $ns_data['count'], $ns_data['start'] ) && ( $now - (int) $ns_data['start'] ) < 3600 ) {
					if ( (int) $ns_data['count'] >= $limits['max_new_sessions_per_ip_hour'] ) {
						self::record_limit_rejection();
						return new WP_Error(
							'aiea_new_sessions_limit',
							'Has iniciado demasiadas conversaciones nuevas en poco tiempo. Por favor, espera más tarde para iniciar otra sesión.',
							[ 'status' => 429 ]
						);
					}
					$ns_data['count'] = (int) $ns_data['count'] + 1;
					$ns_ttl           = max( 1, 3600 - ( $now - (int) $ns_data['start'] ) );
				} else {
					$ns_data = [
						'count' => 1,
						'start' => $now,
					];
					$ns_ttl  = 3600;
				}

				$pending_ns = [
					'key'  => $ns_key,
					'data' => $ns_data,
					'ttl'  => $ns_ttl,
				];
			}
		}

		// 4. Detección de mensajes duplicados consecutivos dentro de la ventana configurada.
		if ( $limits['duplicate_window_seconds'] > 0 && ! self::is_short_natural_reply( $message ) ) {
			$norm_msg = self::normalize_for_duplicate_check( $message );
			$msg_hash = md5( $norm_msg );
			$dup_key  = 'aiea_dup_' . $base_id;
			$dup_data = get_transient( $dup_key );
			$now      = time();

			if (
				is_array( $dup_data )
				&& isset( $dup_data['hash'], $dup_data['ts'] )
				&& hash_equals( (string) $dup_data['hash'], $msg_hash )
				&& ( $now - (int) $dup_data['ts'] ) < $limits['duplicate_window_seconds']
			) {
				self::record_limit_rejection();
				return new WP_Error(
					'aiea_duplicate_message',
					'Ya acabas de enviar este mismo mensaje. Por favor, formula una consulta diferente o espera un momento.',
					[ 'status' => 429 ]
				);
			}
		}

		// Persistir el registro de sesión nueva únicamente tras superar todas las validaciones previas.
		if ( $is_new_token || ! $is_registered ) {
			if ( is_array( $pending_ns ) ) {
				set_transient( $pending_ns['key'], $pending_ns['data'], $pending_ns['ttl'] );
			}
			set_transient( $sess_reg_key, 1, AI_Ecommerce_Agent_Session::SESSION_TTL );
		}

		return true;
	}

	/**
	 * Registra el mensaje aceptado en el contador independiente de la sesión y actualiza el detector de duplicados.
	 *
	 * @param string $session_id Token de sesión.
	 * @param string $message    Mensaje del usuario.
	 * @return void
	 */
	public static function commit_session_message( string $session_id, string $message ): void {
		$base_id = self::extract_base_session_id( $session_id );
		if ( '' === $base_id ) {
			return;
		}

		$sess_cnt_key = 'aiea_scnt_' . $base_id;
		$sess_count   = (int) get_transient( $sess_cnt_key );
		set_transient( $sess_cnt_key, $sess_count + 1, AI_Ecommerce_Agent_Session::SESSION_TTL );

		$limits = self::get_effective_limits();
		if ( $limits['duplicate_window_seconds'] > 0 && ! self::is_short_natural_reply( $message ) ) {
			$norm_msg = self::normalize_for_duplicate_check( $message );
			set_transient(
				'aiea_dup_' . $base_id,
				[
					'hash' => md5( $norm_msg ),
					'ts'   => time(),
				],
				$limits['duplicate_window_seconds'] + 5
			);
		}
	}

	/**
	 * Revierte el contador de mensajes de la sesión y el detector de duplicados si la petición
	 * fue rechazada por límite global diario antes de ejecutarse en OpenAI.
	 *
	 * @param string $session_id Token de sesión validado.
	 * @return void
	 */
	public static function revert_session_message( string $session_id ): void {
		$base_id = self::extract_base_session_id( $session_id );
		if ( '' === $base_id ) {
			return;
		}

		$sess_cnt_key = 'aiea_scnt_' . $base_id;
		$sess_count   = (int) get_transient( $sess_cnt_key );
		if ( $sess_count > 1 ) {
			set_transient( $sess_cnt_key, $sess_count - 1, AI_Ecommerce_Agent_Session::SESSION_TTL );
		} else {
			delete_transient( $sess_cnt_key );
		}

		delete_transient( 'aiea_dup_' . $base_id );
	}

	/**
	 * Calcula una estimación conservadora de tokens para la consulta antes de enviar a OpenAI.
	 * Coherente con estimate_payload_tokens() para sincronizar validación inicial y reserva preventiva.
	 *
	 * @param string $message    Mensaje del usuario.
	 * @param string $session_id Token o identificador de sesión.
	 * @return int Cantidad estimada de tokens.
	 */
	public static function estimate_initial_tokens( string $message = '', string $session_id = '' ): int {
		$limits     = self::get_effective_limits();
		$max_output = isset( $limits['max_output_tokens'] ) && is_numeric( $limits['max_output_tokens'] )
			? max( 100, (int) $limits['max_output_tokens'] )
			: 350;

		// El agente serializa directrices del sistema y esquemas de herramientas (~11 KB JSON -> ~2.750 tokens).
		$base_chars    = 11000;
		$msg_chars     = strlen( $message );
		$history_chars = 0;

		$base_id = self::extract_base_session_id( $session_id );
		if ( '' !== $base_id ) {
			$history = get_transient( 'aiea_hist_' . $base_id );
			if ( is_array( $history ) ) {
				$history_json = wp_json_encode( $history );
				if ( is_string( $history_json ) ) {
					$history_chars = strlen( $history_json );
				}
			}
		}

		$est_input = (int) ceil( ( $base_chars + $msg_chars + $history_chars ) / 4 );

		return max( self::MIN_TOKEN_RESERVATION, $est_input + $max_output );
	}

	// =========================================================
	// 3. Reserva centralizada de capacidad y registro atómico de OpenAI
	// =========================================================

	/**
	 * Calcula una estimación conservadora de tokens antes de realizar una llamada HTTP a OpenAI.
	 *
	 * Estrategia conservadora (Fase 3.B):
	 * Antes de la llamada no se conoce el consumo exacto; se estima la entrada a razón de ~1 token
	 * por cada 4 bytes de JSON enviado más el tope `max_output_tokens`, garantizando un mínimo de
	 * MIN_TOKEN_RESERVATION (600 tokens). Al recibir la respuesta de OpenAI, la reserva temporal se
	 * libera y se sustituye por el consumo real informado en `usage`.
	 *
	 * @param array $payload Payload que se enviará a OpenAI.
	 * @return int Cantidad conservadora de tokens a reservar.
	 */
	public static function estimate_payload_tokens( array $payload ): int {
		$json        = wp_json_encode( $payload );
		$byte_length = is_string( $json ) ? strlen( $json ) : 0;
		$est_input   = (int) ceil( $byte_length / 4 );
		$max_output  = isset( $payload['max_output_tokens'] ) && is_numeric( $payload['max_output_tokens'] )
			? max( 100, (int) $payload['max_output_tokens'] )
			: 350;

		return max( self::MIN_TOKEN_RESERVATION, $est_input + $max_output );
	}

	/**
	 * Valida de forma centralizada los límites globales diarios y reserva capacidad atómicamente
	 * antes de ejecutar cualquier llamada HTTP a OpenAI.
	 *
	 * @param int $estimated_tokens Estimación conservadora de tokens para la llamada.
	 * @return string|WP_Error Identificador de la reserva (`res_...`) o WP_Error si se alcanzó el límite o falló el bloqueo.
	 */
	public static function reserve_openai_call_capacity( int $estimated_tokens = 800 ) {
		$lock = self::acquire_global_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$limits    = self::get_effective_limits();
			$is_demo   = ! empty( $limits['is_demo'] );
			$stats     = self::read_stats_raw();
			$today_key = self::get_today_key();

			self::purge_expired_reservations( $stats, $today_key );

			$today           = $stats['daily'][ $today_key ] ?? self::get_empty_counters();
			$active_reserved = self::sum_active_reservations( $stats, $today_key );
			$effective_calls = (int) $today['openai_calls'] + $active_reserved['calls'];
			$effective_toks  = (int) $today['total_tokens'] + $active_reserved['tokens'];

			if ( $limits['max_daily_openai_calls'] > 0 && $effective_calls >= $limits['max_daily_openai_calls'] ) {
				self::increment_stat_in_memory( $stats, 'rejected_by_limits', 1 );
				self::write_stats_raw( $stats );

				return self::build_daily_limit_error( 'aiea_daily_calls_limit', $is_demo );
			}

			$reserve_tokens = max( self::MIN_TOKEN_RESERVATION, $estimated_tokens );
			if ( $limits['max_daily_tokens'] > 0 ) {
				$reserve_tokens = min( $reserve_tokens, (int) $limits['max_daily_tokens'] );

				if (
					$effective_toks >= $limits['max_daily_tokens']
					|| ( $effective_toks > 0 && ( $effective_toks + $reserve_tokens ) > $limits['max_daily_tokens'] )
				) {
					self::increment_stat_in_memory( $stats, 'rejected_by_limits', 1 );
					self::write_stats_raw( $stats );

					return self::build_daily_limit_error( 'aiea_daily_tokens_limit', $is_demo );
				}
			}

			$res_id = 'res_' . AI_Ecommerce_Agent_Session::generate_id();
			if ( ! isset( $stats['reservations'] ) || ! is_array( $stats['reservations'] ) ) {
				$stats['reservations'] = [];
			}

			$stats['reservations'][ $res_id ] = [
				'day'    => $today_key,
				'ts'     => time(),
				'calls'  => 1,
				'tokens' => $reserve_tokens,
			];

			self::write_stats_raw( $stats );

			return $res_id;
		} finally {
			self::release_global_lock( $lock );
		}
	}

	/**
	 * Libera una reserva en vuelo cuando la llamada a OpenAI falla y registra el error.
	 *
	 * @param string $reservation_id Identificador de reserva devuelto por reserve_openai_call_capacity().
	 * @param bool   $record_error   True para contabilizar en `openai_errors`.
	 * @return void
	 */
	public static function release_openai_call_reservation( string $reservation_id, bool $record_error = true ): void {
		self::update_stats_atomic(
			function ( array &$stats ) use ( $reservation_id, $record_error ): void {
				if ( '' !== $reservation_id && isset( $stats['reservations'][ $reservation_id ] ) ) {
					unset( $stats['reservations'][ $reservation_id ] );
				}
				if ( $record_error ) {
					self::increment_stat_in_memory( $stats, 'openai_errors', 1 );
				}
			}
		);
	}

	/**
	 * Registra una solicitud entrante al endpoint público.
	 *
	 * @return void
	 */
	public static function record_public_request(): void {
		self::update_stats_atomic(
			function ( array &$stats ): void {
				self::increment_stat_in_memory( $stats, 'public_requests', 1 );
			}
		);
	}

	/**
	 * Registra un rechazo por límites de frecuencia o de consumo en el endpoint público.
	 *
	 * @return void
	 */
	public static function record_limit_rejection(): void {
		self::update_stats_atomic(
			function ( array &$stats ): void {
				self::increment_stat_in_memory( $stats, 'rejected_by_limits', 1 );
			}
		);
	}

	/**
	 * Registra un error de conexión o respuesta HTTP de OpenAI y libera la reserva si existe.
	 *
	 * @param string $reservation_id Identificador de reserva opcional.
	 * @return void
	 */
	public static function record_openai_error( string $reservation_id = '' ): void {
		self::release_openai_call_reservation( $reservation_id, true );
	}

	/**
	 * Registra una llamada real completada a la Responses API de OpenAI, libera su reserva temporal
	 * y contabiliza únicamente los tokens reales informados en `usage`.
	 *
	 * Si la respuesta no incluye bloque `usage`, incrementa `unreported_usage_calls` sin inventar cantidades.
	 *
	 * @param array  $response_body  Cuerpo JSON decodificado devuelto por OpenAI.
	 * @param string $reservation_id Identificador de reserva temporal asociada a la llamada.
	 * @return void
	 */
	public static function record_openai_call_response( array $response_body, string $reservation_id = '' ): void {
		$usage         = isset( $response_body['usage'] ) && is_array( $response_body['usage'] ) ? $response_body['usage'] : null;
		$has_usage     = false;
		$input_tokens  = 0;
		$output_tokens = 0;
		$total_tokens  = 0;

		if ( is_array( $usage ) ) {
			if ( isset( $usage['input_tokens'] ) && is_numeric( $usage['input_tokens'] ) ) {
				$input_tokens = max( 0, (int) $usage['input_tokens'] );
				$has_usage    = true;
			} elseif ( isset( $usage['prompt_tokens'] ) && is_numeric( $usage['prompt_tokens'] ) ) {
				$input_tokens = max( 0, (int) $usage['prompt_tokens'] );
				$has_usage    = true;
			}

			if ( isset( $usage['output_tokens'] ) && is_numeric( $usage['output_tokens'] ) ) {
				$output_tokens = max( 0, (int) $usage['output_tokens'] );
				$has_usage     = true;
			} elseif ( isset( $usage['completion_tokens'] ) && is_numeric( $usage['completion_tokens'] ) ) {
				$output_tokens = max( 0, (int) $usage['completion_tokens'] );
				$has_usage     = true;
			}

			if ( isset( $usage['total_tokens'] ) && is_numeric( $usage['total_tokens'] ) ) {
				$total_tokens = max( 0, (int) $usage['total_tokens'] );
				$has_usage    = true;
			} elseif ( $has_usage ) {
				$total_tokens = $input_tokens + $output_tokens;
			}
		}

		self::update_stats_atomic(
			function ( array &$stats ) use ( $reservation_id, $has_usage, $input_tokens, $output_tokens, $total_tokens ): void {
				if ( '' !== $reservation_id && isset( $stats['reservations'][ $reservation_id ] ) ) {
					unset( $stats['reservations'][ $reservation_id ] );
				}

				self::increment_stat_in_memory( $stats, 'openai_calls', 1 );
				if ( $has_usage ) {
					self::increment_stat_in_memory( $stats, 'input_tokens', $input_tokens );
					self::increment_stat_in_memory( $stats, 'output_tokens', $output_tokens );
					self::increment_stat_in_memory( $stats, 'total_tokens', $total_tokens );
				} else {
					self::increment_stat_in_memory( $stats, 'unreported_usage_calls', 1 );
				}
			}
		);
	}

	/**
	 * Devuelve las estadísticas actuales (día actual y acumulado total) listas para mostrar en administración.
	 *
	 * @return array{today_date:string,today:array<string,int>,total:array<string,int>}
	 */
	public static function get_stats_summary(): array {
		$stats     = self::read_stats_raw();
		$today_key = self::get_today_key();
		$today     = isset( $stats['daily'][ $today_key ] ) && is_array( $stats['daily'][ $today_key ] )
			? wp_parse_args( $stats['daily'][ $today_key ], self::get_empty_counters() )
			: self::get_empty_counters();
		$total     = isset( $stats['total'] ) && is_array( $stats['total'] )
			? wp_parse_args( $stats['total'], self::get_empty_counters() )
			: self::get_empty_counters();

		return [
			'today_date' => $today_key,
			'today'      => $today,
			'total'      => $total,
		];
	}

	// =========================================================
	// 4. Utilidades internas de concurrencia y limpieza
	// =========================================================

	/**
	 * Construye el WP_Error controlado cuando se alcanza un límite diario global (llamadas o tokens).
	 * En modo demostración devuelve un mensaje específico de capacidad temporal sin exponer contadores internos.
	 *
	 * @param string $code    Código de error (`aiea_daily_calls_limit` o `aiea_daily_tokens_limit`).
	 * @param bool   $is_demo Si la instalación está en modo demostración pública.
	 * @return WP_Error
	 */
	private static function build_daily_limit_error( string $code, bool $is_demo ): WP_Error {
		if ( $is_demo ) {
			return new WP_Error(
				$code,
				self::DEMO_DAILY_LIMIT_MESSAGE,
				[
					'status'     => 429,
					'limit_type' => 'daily_demo',
				]
			);
		}

		$msg = 'aiea_daily_calls_limit' === $code
			? 'El asistente ha alcanzado su límite diario de consultas. Por favor, inténtalo de nuevo mañana o contacta con la tienda.'
			: 'El asistente ha alcanzado su capacidad diaria de atención. Por favor, inténtalo de nuevo más tarde.';

		return new WP_Error(
			$code,
			$msg,
			[
				'status'     => 429,
				'limit_type' => 'daily',
			]
		);
	}

	/**
	 * Purga reservas en vuelo expiradas o pertenecientes a días anteriores.
	 *
	 * @param array  $stats     Estructura de estadísticas por referencia.
	 * @param string $today_key Fecha actual YYYY-MM-DD.
	 * @return void
	 */
	private static function purge_expired_reservations( array &$stats, string $today_key ): void {
		if ( empty( $stats['reservations'] ) || ! is_array( $stats['reservations'] ) ) {
			$stats['reservations'] = [];
			return;
		}

		$now = time();
		foreach ( $stats['reservations'] as $res_id => $item ) {
			if ( ! is_array( $item ) ) {
				unset( $stats['reservations'][ $res_id ] );
				continue;
			}
			$res_day = isset( $item['day'] ) ? (string) $item['day'] : '';
			$res_ts  = isset( $item['ts'] ) ? (int) $item['ts'] : 0;

			if ( $res_day !== $today_key || ( $now - $res_ts ) > self::RESERVATION_TTL ) {
				unset( $stats['reservations'][ $res_id ] );
			}
		}
	}

	/**
	 * Suma las llamadas y tokens reservados por peticiones actualmente en vuelo hacia OpenAI.
	 *
	 * @param array  $stats     Estructura de estadísticas.
	 * @param string $today_key Fecha actual YYYY-MM-DD.
	 * @return array{calls:int,tokens:int}
	 */
	private static function sum_active_reservations( array $stats, string $today_key ): array {
		$calls  = 0;
		$tokens = 0;

		if ( empty( $stats['reservations'] ) || ! is_array( $stats['reservations'] ) ) {
			return [
				'calls'  => 0,
				'tokens' => 0,
			];
		}

		foreach ( $stats['reservations'] as $item ) {
			if ( ! is_array( $item ) || ( $item['day'] ?? '' ) !== $today_key ) {
				continue;
			}
			$calls  += max( 0, (int) ( $item['calls'] ?? 0 ) );
			$tokens += max( 0, (int) ( $item['tokens'] ?? 0 ) );
		}

		return [
			'calls'  => $calls,
			'tokens' => $tokens,
		];
	}

	/**
	 * Estructura base de contadores.
	 *
	 * @return array<string,int>
	 */
	private static function get_empty_counters(): array {
		return [
			'public_requests'        => 0,
			'openai_calls'           => 0,
			'input_tokens'           => 0,
			'output_tokens'          => 0,
			'total_tokens'           => 0,
			'unreported_usage_calls' => 0,
			'openai_errors'          => 0,
			'rejected_by_limits'     => 0,
		];
	}

	/**
	 * Determina si un mensaje es una respuesta corta y natural conversacional que no debe bloquearse por duplicidad.
	 *
	 * @param string $message Mensaje del visitante.
	 * @return bool
	 */
	public static function is_short_natural_reply( string $message ): bool {
		$norm = self::normalize_for_duplicate_check( $message );
		if ( '' === $norm ) {
			return true;
		}

		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $norm, 'UTF-8' ) : strlen( $norm );
		if ( $len <= 4 ) {
			return true;
		}

		$natural_replies = [
			'si', 'no', 'ok', 'oka', 'okey', 'okay', 'vale', 'gracias', 'muchas gracias',
			'claro', 'perfecto', 'listo', 'bueno', 'dale', 'de acuerdo', 'entiendo',
			'por favor', 'hola', 'buenas', 'buenos dias', 'buenas tardes', 'buenas noches',
			'si por favor', 'no gracias', '1', '2', '3', '4', '5',
		];

		return in_array( $norm, $natural_replies, true );
	}

	/**
	 * Normaliza un mensaje para comparar duplicados consecutivos.
	 *
	 * @param string $message Mensaje original.
	 * @return string
	 */
	private static function normalize_for_duplicate_check( string $message ): string {
		$text = trim( $message );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = strtr(
			$text,
			[
				'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
				'ü' => 'u', 'ñ' => 'n',
			]
		);
		$text = preg_replace( '/[¿?¡!.,;:_\-\/\"\'\(\)]/u', ' ', $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/**
	 * Extrae el UUID/identificador base sin firma HMAC a partir del token de sesión.
	 *
	 * @param string $session_id Token de sesión.
	 * @return string
	 */
	private static function extract_base_session_id( string $session_id ): string {
		$clean = AI_Ecommerce_Agent_Session::sanitize_id( $session_id );
		if ( '' === $clean ) {
			return '';
		}
		$parts = explode( '.', $clean );

		return $parts[0] ?? '';
	}

	/**
	 * Obtiene la fecha actual en formato YYYY-MM-DD según la zona horaria de WordPress.
	 *
	 * @return string
	 */
	private static function get_today_key(): string {
		if ( function_exists( 'wp_date' ) ) {
			return (string) wp_date( 'Y-m-d' );
		}
		if ( function_exists( 'current_time' ) ) {
			return (string) current_time( 'Y-m-d' );
		}

		return gmdate( 'Y-m-d' );
	}

	/**
	 * Adquiere un bloqueo de archivo exclusivo para lecturas/escrituras atómicas de los límites y estadísticas globales.
	 *
	 * @return resource|WP_Error
	 */
	private static function acquire_global_lock() {
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

		$lock_file = trailingslashit( $temp_dir ) . 'aiea_global_usage.lock';
		$handle    = @fopen( $lock_file, 'c+' );
		if ( false === $handle || ! is_resource( $handle ) ) {
			return new WP_Error(
				'aiea_lock_unavailable',
				'El asistente no está disponible en este momento. Por favor, inténtalo de nuevo en unos instantes.',
				[ 'status' => 503 ]
			);
		}

		// Bloqueo exclusivo (espera breve del sistema operativo entre procesos concurrentes).
		if ( ! @flock( $handle, LOCK_EX ) ) {
			@fclose( $handle );
			return new WP_Error(
				'aiea_lock_unavailable',
				'El asistente no está disponible en este momento. Por favor, inténtalo de nuevo en unos instantes.',
				[ 'status' => 503 ]
			);
		}

		return $handle;
	}

	/**
	 * Libera el bloqueo global de estadísticas/consumo.
	 *
	 * @param mixed $handle Descriptor de archivo.
	 * @return void
	 */
	private static function release_global_lock( $handle ): void {
		if ( is_resource( $handle ) ) {
			@flock( $handle, LOCK_UN );
			@fclose( $handle );
		}
	}

	/**
	 * Ejecuta una actualización atómica sobre `aiea_usage_stats`.
	 *
	 * @param callable $callback Función que recibe `&$stats`.
	 * @return void
	 */
	private static function update_stats_atomic( callable $callback ): void {
		$lock = self::acquire_global_lock();
		if ( is_wp_error( $lock ) ) {
			return;
		}

		try {
			$stats = self::read_stats_raw();
			$callback( $stats );
			self::write_stats_raw( $stats );
		} finally {
			self::release_global_lock( $lock );
		}
	}

	/**
	 * Lee y normaliza la estructura de `aiea_usage_stats`.
	 * Invalida la caché en memoria de la opción para leer siempre el dato más reciente bajo el bloqueo global.
	 *
	 * @return array{total:array<string,int>,daily:array<string,array<string,int>>,reservations:array<string,array<string,mixed>>}
	 */
	private static function read_stats_raw(): array {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_STATS, 'options' );
		}

		$raw = get_option( self::OPTION_STATS, [] );
		$raw = is_array( $raw ) ? $raw : [];

		$total = isset( $raw['total'] ) && is_array( $raw['total'] )
			? wp_parse_args( $raw['total'], self::get_empty_counters() )
			: self::get_empty_counters();

		$daily        = isset( $raw['daily'] ) && is_array( $raw['daily'] ) ? $raw['daily'] : [];
		$reservations = isset( $raw['reservations'] ) && is_array( $raw['reservations'] ) ? $raw['reservations'] : [];

		return [
			'total'        => $total,
			'daily'        => $daily,
			'reservations' => $reservations,
		];
	}

	/**
	 * Incrementa un contador tanto en el acumulado total como en el registro del día actual,
	 * y purga días antiguos para evitar que la opción crezca indefinidamente.
	 *
	 * @param array  $stats Estructura de estadísticas por referencia.
	 * @param string $field Nombre del contador.
	 * @param int    $delta Valor a sumar.
	 * @return void
	 */
	private static function increment_stat_in_memory( array &$stats, string $field, int $delta ): void {
		if ( $delta <= 0 ) {
			return;
		}

		$today_key = self::get_today_key();
		if ( ! isset( $stats['daily'][ $today_key ] ) || ! is_array( $stats['daily'][ $today_key ] ) ) {
			$stats['daily'][ $today_key ] = self::get_empty_counters();
		} else {
			$stats['daily'][ $today_key ] = wp_parse_args( $stats['daily'][ $today_key ], self::get_empty_counters() );
		}

		$stats['total'][ $field ]               = max( 0, (int) ( $stats['total'][ $field ] ?? 0 ) + $delta );
		$stats['daily'][ $today_key ][ $field ] = max( 0, (int) ( $stats['daily'][ $today_key ][ $field ] ?? 0 ) + $delta );

		// Limpieza automática: conservar únicamente los últimos MAX_DAILY_HISTORY_DAYS días.
		if ( count( $stats['daily'] ) > self::MAX_DAILY_HISTORY_DAYS ) {
			ksort( $stats['daily'] );
			$stats['daily'] = array_slice( $stats['daily'], -self::MAX_DAILY_HISTORY_DAYS, null, true );
		}
	}

	/**
	 * Guarda `aiea_usage_stats` sin autoload para no sobrecargar cada carga de página de WordPress.
	 *
	 * @param array $stats Estructura de estadísticas.
	 * @return void
	 */
	private static function write_stats_raw( array $stats ): void {
		update_option( self::OPTION_STATS, $stats, false );
	}
}
