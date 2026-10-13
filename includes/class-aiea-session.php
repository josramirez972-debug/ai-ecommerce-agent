<?php
/**
 * AI Ecommerce Agent — Gestión de sesiones y memoria de conversación.
 *
 * Utiliza Transients de WordPress para almacenar el historial de conversación
 * de forma temporal y aislada por visitante, sin session_start() de PHP.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Ecommerce_Agent_Session {

	/**
	 * Duración de la sesión en segundos (2 horas).
	 */
	const SESSION_TTL = 7200;

	/**
	 * Número máximo de mensajes guardados en el historial (3 turnos: 3 usuario + 3 asistente).
	 */
	const MAX_MESSAGES = 6;

	/**
	 * Prefijo para las claves de transients.
	 */
	const TRANSIENT_PREFIX = 'aiea_sess_';

	/**
	 * Longitud de la firma HMAC en caracteres hexadecimales.
	 */
	const SIGNATURE_LENGTH = 24;

	/**
	 * Valida y sanitiza un identificador de sesión (simple o firmado).
	 *
	 * Acepta identificadores alfanuméricos seguros (16–64 caracteres) y tokens
	 * firmados con formato <id>.<hmac_24>.
	 *
	 * @param string $session_id Identificador recibido.
	 * @return string Identificador válido o cadena vacía si no cumple el formato.
	 */
	public static function sanitize_id( string $session_id ): string {
		$session_id = trim( sanitize_text_field( $session_id ) );

		if ( preg_match( '/^[a-zA-Z0-9_\-]{16,64}(\.[a-f0-9]{' . self::SIGNATURE_LENGTH . '})?$/', $session_id ) ) {
			return $session_id;
		}

		return '';
	}

	/**
	 * Genera un identificador base aleatorio y seguro (UUID v4 o hex de 32 caracteres).
	 *
	 * @return string Identificador UUID v4 o hash aleatorio seguro.
	 */
	public static function generate_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			return wp_hash( uniqid( (string) wp_rand(), true ) );
		}
	}

	/**
	 * Genera un token de sesión firmado criptográficamente por el servidor (<uuid>.<hmac>).
	 *
	 * @param string $fingerprint Huella del visitante (ej. IP del cliente) para vincular la sesión.
	 * @return string Token firmado.
	 */
	public static function generate_signed_token( string $fingerprint = '' ): string {
		$base_id   = self::generate_id();
		$signature = self::compute_signature( $base_id, $fingerprint );

		return $base_id . '.' . $signature;
	}

	/**
	 * Valida que un token de sesión haya sido emitido por el servidor y coincida con la huella del visitante.
	 *
	 * @param string $token       Token recibido del cliente (<uuid>.<hmac>).
	 * @param string $fingerprint Huella del visitante actual.
	 * @return bool True si el formato y la firma HMAC son válidos, false en caso contrario.
	 */
	public static function validate_signed_token( string $token, string $fingerprint = '' ): bool {
		$token = trim( sanitize_text_field( $token ) );

		if ( ! preg_match( '/^([a-zA-Z0-9_\-]{16,64})\.([a-f0-9]{' . self::SIGNATURE_LENGTH . '})$/', $token, $matches ) ) {
			return false;
		}

		$base_id      = $matches[1];
		$provided_sig = $matches[2];
		$expected_sig = self::compute_signature( $base_id, $fingerprint );

		return hash_equals( $expected_sig, $provided_sig );
	}

	/**
	 * Obtiene el historial de conversación asociado a una sesión.
	 *
	 * @param string $session_id Identificador o token de sesión.
	 * @return array Array de mensajes normalizados [ ['role' => string, 'content' => string], ... ].
	 */
	public static function get_history( string $session_id ): array {
		$storage_key = self::get_storage_key( $session_id );
		if ( '' === $storage_key ) {
			return [];
		}

		$history = get_transient( $storage_key );
		if ( ! is_array( $history ) ) {
			return [];
		}

		// Validar estructura interna de los mensajes guardados.
		$valid_history = [];
		foreach ( $history as $msg ) {
			if (
				is_array( $msg )
				&& isset( $msg['role'], $msg['content'] )
				&& in_array( $msg['role'], [ 'user', 'assistant' ], true )
				&& is_string( $msg['content'] )
			) {
				$valid_history[] = [
					'role'    => $msg['role'],
					'content' => $msg['content'],
				];
			}
		}

		return array_slice( $valid_history, -self::MAX_MESSAGES );
	}

	/**
	 * Guarda un turno completo (mensaje del usuario + respuesta del asistente) en la sesión.
	 *
	 * Solo se debe invocar tras una respuesta exitosa de OpenAI para evitar estados inconsistentes.
	 *
	 * @param string $session_id        Identificador o token de sesión.
	 * @param string $user_message      Mensaje enviado por el usuario.
	 * @param string $assistant_message Respuesta generada por el asistente.
	 * @return bool True si se guardó correctamente, false en caso contrario.
	 */
	public static function save_turn( string $session_id, string $user_message, string $assistant_message ): bool {
		$storage_key = self::get_storage_key( $session_id );
		if ( '' === $storage_key ) {
			return false;
		}

		$history = self::get_history( $session_id );

		$history[] = [
			'role'    => 'user',
			'content' => $user_message,
		];

		$history[] = [
			'role'    => 'assistant',
			'content' => $assistant_message,
		];

		// Limitar a la ventana máxima configurada (últimos 6 mensajes).
		$history = array_slice( $history, -self::MAX_MESSAGES );

		return set_transient( $storage_key, $history, self::SESSION_TTL );
	}

	/**
	 * Elimina el historial de una sesión.
	 *
	 * @param string $session_id Identificador o token de sesión.
	 * @return bool
	 */
	public static function clear_history( string $session_id ): bool {
		$storage_key = self::get_storage_key( $session_id );
		if ( '' === $storage_key ) {
			return false;
		}

		return delete_transient( $storage_key );
	}

	/**
	 * Extrae la clave de almacenamiento para Transients a partir del identificador o token firmado.
	 *
	 * @param string $session_id Identificador o token de sesión.
	 * @return string Clave de transient o cadena vacía si no es válido.
	 */
	private static function get_storage_key( string $session_id ): string {
		$clean_id = self::sanitize_id( $session_id );
		if ( '' === $clean_id ) {
			return '';
		}

		$parts   = explode( '.', $clean_id );
		$base_id = $parts[0];

		return self::TRANSIENT_PREFIX . $base_id;
	}

	/**
	 * Calcula la firma HMAC-SHA256 truncada para un identificador de sesión y huella.
	 *
	 * @param string $base_id     Identificador base (UUID).
	 * @param string $fingerprint Huella del visitante.
	 * @return string Firma hexadecimal de longitud SIGNATURE_LENGTH.
	 */
	private static function compute_signature( string $base_id, string $fingerprint ): string {
		$data   = $base_id . '|' . $fingerprint;
		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : AUTH_KEY;
		$hmac   = hash_hmac( 'sha256', $data, $secret );

		return substr( $hmac, 0, self::SIGNATURE_LENGTH );
	}

	/**
	 * Transforma el historial guardado al formato requerido por la Responses API de OpenAI.
	 *
	 * En la Responses API, cada ítem dentro del array 'input' debe tener 'type' => 'message'.
	 *
	 * @param array $history Historial retornado por get_history().
	 * @return array Array de mensajes compatible con Responses API.
	 */
	public static function to_responses_api_input( array $history ): array {
		$formatted = [];

		foreach ( $history as $msg ) {
			if ( isset( $msg['role'], $msg['content'] ) ) {
				$formatted[] = [
					'type'    => 'message',
					'role'    => $msg['role'],
					'content' => $msg['content'],
				];
			}
		}

		return $formatted;
	}
}
