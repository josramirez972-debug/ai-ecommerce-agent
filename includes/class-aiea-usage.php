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
	const OPTION_STATS  = 'aiea_usage_stats';

	/**
	 * Días máximos de histórico diario conservados en la opción de estadísticas.
	 */
	const MAX_DAILY_HISTORY_DAYS = 30;

	// =========================================================
	// 1. Configuración de límites y valores iniciales conservadores
	// =========================================================

	/**
	 * Devuelve los valores predeterminados conservadores para los límites de consumo.
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
	 * Obtiene los límites de consumo configurados actualmente.
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

	// =========================================================
	// 2. Verificación previa de límites en el endpoint público
	// =========================================================

	/**
	 * Comprueba todos los límites de consumo antes de invocar OpenAI en el endpoint público:
	 * - Presupuesto diario de llamadas y tokens de OpenAI (bajo bloqueo global atómico).
	 * - Límite de sesiones nuevas por IP y por hora.
	 * - Límite de mensajes por sesión pública (contador independiente del historial).
	 * - Detección de mensajes duplicados consecutivos.
	 *
	 * @param string $client_ip      Dirección IP del visitante.
	 * @param string $session_id     Token de sesión validado.
	 * @param bool   $is_new_token   True si el servidor acaba de emitir un nuevo token porque el visitante no envió uno.
	 * @param string $message        Mensaje del visitante.
	 * @return true|WP_Error True si pasa todas las validaciones o WP_Error con código HTTP apropiado.
	 */
	public static function check_public_consumption_limits(
		string $client_ip,
		string $session_id,
		bool $is_new_token,
		string $message
	) {
		$limits  = self::get_limits();
		$base_id = self::extract_base_session_id( $session_id );

		if ( '' === $base_id ) {
			self::record_limit_rejection();
			return new WP_Error(
				'aiea_invalid_session',
				'El identificador de sesión no es válido.',
				[ 'status' => 400 ]
			);
		}

		// 1. Comprobar límites diarios globales (llamadas y tokens) bajo bloqueo exclusivo global.
		$global_lock = self::acquire_global_lock();
		if ( is_wp_error( $global_lock ) ) {
			return $global_lock;
		}

		try {
			$stats     = self::read_stats_raw();
			$today_key = self::get_today_key();
			$today     = $stats['daily'][ $today_key ] ?? self::get_empty_counters();

			if ( $limits['max_daily_openai_calls'] > 0 && (int) $today['openai_calls'] >= $limits['max_daily_openai_calls'] ) {
				self::increment_stat_in_memory( $stats, 'rejected_by_limits', 1 );
				self::write_stats_raw( $stats );

				return new WP_Error(
					'aiea_daily_calls_limit',
					'El asistente ha alcanzado su límite diario de consultas. Por favor, inténtalo de nuevo mañana o contacta con la tienda.',
					[ 'status' => 429 ]
				);
			}

			if ( $limits['max_daily_tokens'] > 0 && (int) $today['total_tokens'] >= $limits['max_daily_tokens'] ) {
				self::increment_stat_in_memory( $stats, 'rejected_by_limits', 1 );
				self::write_stats_raw( $stats );

				return new WP_Error(
					'aiea_daily_tokens_limit',
					'El asistente ha alcanzado su capacidad diaria de atención. Por favor, inténtalo de nuevo más tarde.',
					[ 'status' => 429 ]
				);
			}
		} finally {
			self::release_global_lock( $global_lock );
		}

		// 2. Comprobar límite de sesiones nuevas por IP y por hora (sin depender del historial de mensajes).
		$sess_reg_key  = 'aiea_sreg_' . $base_id;
		$is_registered = ( false !== get_transient( $sess_reg_key ) );

		if ( $is_new_token || ! $is_registered ) {
			if ( $limits['max_new_sessions_per_ip_hour'] > 0 ) {
				$now         = time();
				$ns_key      = 'aiea_nsess_' . md5( $client_ip );
				$ns_data     = get_transient( $ns_key );

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

				set_transient( $ns_key, $ns_data, $ns_ttl );
			}

			set_transient( $sess_reg_key, 1, AI_Ecommerce_Agent_Session::SESSION_TTL );
		}

		// 3. Comprobar límite de mensajes por sesión (almacenado independientemente del historial truncado).
		$sess_cnt_key = 'aiea_scnt_' . $base_id;
		$sess_count   = (int) get_transient( $sess_cnt_key );

		if ( $limits['max_messages_per_session'] > 0 && $sess_count >= $limits['max_messages_per_session'] ) {
			self::record_limit_rejection();
			return new WP_Error(
				'aiea_session_messages_limit',
				'Has alcanzado el número máximo de mensajes permitidos en esta conversación.',
				[ 'status' => 429 ]
			);
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

		$limits = self::get_limits();
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

	// =========================================================
	// 3. Registro atómico de estadísticas y consumo de OpenAI
	// =========================================================

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
	 * Registra un error de conexión o respuesta HTTP de OpenAI.
	 *
	 * @return void
	 */
	public static function record_openai_error(): void {
		self::update_stats_atomic(
			function ( array &$stats ): void {
				self::increment_stat_in_memory( $stats, 'openai_errors', 1 );
			}
		);
	}

	/**
	 * Registra una llamada real completada a la Responses API de OpenAI y los tokens informados en `usage`.
	 *
	 * Si la respuesta no incluye bloque `usage`, incrementa `unreported_usage_calls` sin inventar cantidades.
	 *
	 * @param array $response_body Cuerpo JSON decodificado devuelto por OpenAI.
	 * @return void
	 */
	public static function record_openai_call_response( array $response_body ): void {
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
			function ( array &$stats ) use ( $has_usage, $input_tokens, $output_tokens, $total_tokens ): void {
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
	 *
	 * @return array{total:array<string,int>,daily:array<string,array<string,int>>}
	 */
	private static function read_stats_raw(): array {
		$raw = get_option( self::OPTION_STATS, [] );
		$raw = is_array( $raw ) ? $raw : [];

		$total = isset( $raw['total'] ) && is_array( $raw['total'] )
			? wp_parse_args( $raw['total'], self::get_empty_counters() )
			: self::get_empty_counters();

		$daily = isset( $raw['daily'] ) && is_array( $raw['daily'] ) ? $raw['daily'] : [];

		return [
			'total' => $total,
			'daily' => $daily,
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
