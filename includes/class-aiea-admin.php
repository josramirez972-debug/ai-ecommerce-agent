<?php
/**
 * AI Ecommerce Agent — Panel de administración multipestaña.
 *
 * Gestiona las 8 secciones de configuración de la tienda:
 * 1. General
 * 2. Información del negocio
 * 3. Envíos y cobertura
 * 4. Políticas oficiales (por ID real de página publicada)
 * 5. Preguntas frecuentes (gestor CRUD con orden, estado activo/inactivo y buscador)
 * 6. Contacto y horarios
 * 7. Apariencia del chat
 * 8. Consumo y límites + Estadísticas básicas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Ecommerce_Agent_Admin {

	const OPTION_NAME = 'aiea_settings';

	/**
	 * Inicializa los hooks del panel de administración.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Registra la página principal de administración del plugin.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			'AI Ecommerce Agent',
			'AI Ecommerce Agent',
			'manage_options',
			'ai-ecommerce-agent',
			array( __CLASS__, 'render_settings_page' ),
			'dashicons-format-chat',
			56
		);
	}

	/**
	 * Registra todas las opciones del plugin en el grupo `aiea_settings_group`.
	 *
	 * @return void
	 */
	public static function register_settings() {
		// 1. Configuración general (compatible con versiones anteriores).
		register_setting(
			'aiea_settings_group',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::get_general_defaults(),
			)
		);

		// 2. Información del negocio.
		register_setting(
			'aiea_settings_group',
			AI_Ecommerce_Agent_Knowledge::OPTION_BUSINESS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_business_info' ),
				'default'           => AI_Ecommerce_Agent_Knowledge::get_business_defaults(),
			)
		);

		// 3. Envíos y cobertura.
		register_setting(
			'aiea_settings_group',
			AI_Ecommerce_Agent_Knowledge::OPTION_SHIPPING,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_shipping_coverage' ),
				'default'           => AI_Ecommerce_Agent_Knowledge::get_shipping_defaults(),
			)
		);

		// 4. Políticas oficiales (IDs de páginas publicadas).
		register_setting(
			'aiea_settings_group',
			AI_Ecommerce_Agent_Policies::OPTION_POLICY_PAGES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_policy_pages' ),
				'default'           => array(),
			)
		);

		// 5. Preguntas frecuentes (FAQ).
		register_setting(
			'aiea_settings_group',
			AI_Ecommerce_Agent_Knowledge::OPTION_FAQ,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_faq_items' ),
				'default'           => array(),
			)
		);

		// 6. Contacto y horarios.
		register_setting(
			'aiea_settings_group',
			AI_Ecommerce_Agent_Knowledge::OPTION_CONTACT,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_contact_settings' ),
				'default'           => AI_Ecommerce_Agent_Knowledge::get_contact_defaults(),
			)
		);

		// 7. Apariencia del chat.
		register_setting(
			'aiea_settings_group',
			AI_Ecommerce_Agent_Public::OPTION_APPEARANCE,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_appearance' ),
				'default'           => AI_Ecommerce_Agent_Public::get_appearance_defaults(),
			)
		);

		// 8. Límites de consumo.
		register_setting(
			'aiea_settings_group',
			AI_Ecommerce_Agent_Usage::OPTION_LIMITS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_usage_limits' ),
				'default'           => AI_Ecommerce_Agent_Usage::get_limits_defaults(),
			)
		);

		// 9. Modo Demostración Pública (Jora IA).
		register_setting(
			'aiea_settings_group',
			AI_Ecommerce_Agent_Usage::OPTION_DEMO,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_demo_settings' ),
				'default'           => AI_Ecommerce_Agent_Usage::get_demo_defaults(),
			)
		);
	}

	// =========================================================
	// 1. Sanitización de todas las opciones del plugin
	// =========================================================

	/**
	 * Sanitiza la opción general `aiea_settings` manteniendo compatibilidad hacia atrás.
	 *
	 * @param mixed $input Datos enviados en el formulario.
	 * @return array<string,mixed>
	 */
	public static function sanitize_settings( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = get_option( self::OPTION_NAME, array() );
		$current = is_array( $current ) ? $current : array();
		$output  = array();

		$key               = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
		$output['api_key'] = '' !== $key
			? sanitize_text_field( $key )
			: ( isset( $current['api_key'] ) ? (string) $current['api_key'] : '' );

		$models          = array( 'gpt-4.1-mini', 'gpt-4.1', 'gpt-4o-mini' );
		$model           = isset( $input['model'] ) ? sanitize_text_field( (string) $input['model'] ) : 'gpt-4.1-mini';
		$output['model'] = in_array( $model, $models, true ) ? $model : 'gpt-4.1-mini';

		$agent_name           = isset( $input['agent_name'] ) ? sanitize_text_field( (string) $input['agent_name'] ) : '';
		$output['agent_name'] = '' !== $agent_name
			? $agent_name
			: ( isset( $current['agent_name'] ) && '' !== $current['agent_name'] ? (string) $current['agent_name'] : 'Asistente de ventas' );

		$output['enabled'] = ! empty( $input['enabled'] ) ? 1 : 0;

		$output['support_contact'] = isset( $input['support_contact'] )
			? sanitize_text_field( (string) $input['support_contact'] )
			: ( isset( $current['support_contact'] ) ? sanitize_text_field( (string) $current['support_contact'] ) : '' );

		$supported_policies = class_exists( 'AI_Ecommerce_Agent_Policies' )
			? array_keys( AI_Ecommerce_Agent_Policies::get_supported_policies() )
			: array( 'envios', 'devoluciones', 'reembolsos', 'garantia', 'cancelaciones', 'terminos', 'privacidad', 'faq', 'empresa', 'contacto', 'pagos' );

		$input_policies   = isset( $input['policy_pages'] ) && is_array( $input['policy_pages'] ) ? $input['policy_pages'] : array();
		$current_policies = isset( $current['policy_pages'] ) && is_array( $current['policy_pages'] ) ? $current['policy_pages'] : array();
		$clean_policies   = array();

		foreach ( $supported_policies as $pkey ) {
			if ( array_key_exists( $pkey, $input_policies ) ) {
				$raw_ref = (string) $input_policies[ $pkey ];
			} elseif ( isset( $current_policies[ $pkey ] ) ) {
				$raw_ref = (string) $current_policies[ $pkey ];
			} else {
				$raw_ref = '';
			}

			$clean_policies[ $pkey ] = self::normalize_policy_page_id( $raw_ref );
		}

		$output['policy_pages'] = $clean_policies;

		return $output;
	}

	/**
	 * Sanitiza la información comercial del negocio (`aiea_business_info`).
	 *
	 * @param mixed $input Datos enviados.
	 * @return array<string,string>
	 */
	public static function sanitize_business_info( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = AI_Ecommerce_Agent_Knowledge::get_business_defaults();
		$clean    = array();

		$text_fields = array(
			'business_name',
			'category_sector',
			'country',
			'state_region',
			'city',
			'address',
			'phone',
			'whatsapp',
		);

		foreach ( $text_fields as $field ) {
			$clean[ $field ] = isset( $input[ $field ] )
				? sanitize_text_field( trim( (string) $input[ $field ] ) )
				: $defaults[ $field ];
		}

		$clean['short_description'] = isset( $input['short_description'] )
			? sanitize_textarea_field( trim( (string) $input['short_description'] ) )
			: '';

		$raw_email      = isset( $input['email'] ) ? trim( (string) $input['email'] ) : '';
		$clean['email'] = '' !== $raw_email ? sanitize_email( $raw_email ) : '';

		$raw_web              = isset( $input['website_url'] ) ? trim( (string) $input['website_url'] ) : '';
		$clean['website_url'] = '' !== $raw_web ? esc_url_raw( $raw_web ) : '';

		$raw_contact          = isset( $input['contact_url'] ) ? trim( (string) $input['contact_url'] ) : '';
		$clean['contact_url'] = '' !== $raw_contact ? esc_url_raw( $raw_contact ) : '';

		return $clean;
	}

	/**
	 * Sanitiza la configuración de envíos y cobertura (`aiea_shipping_coverage`).
	 *
	 * @param mixed $input Datos enviados.
	 * @return array<string,mixed>
	 */
	public static function sanitize_shipping_coverage( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = array();

		$allowed_modes = array(
			'',
			'nacional',
			'regional',
			'local',
			'zonas_especificas',
			'recogida_tienda',
			'mixto',
		);

		$mode                   = isset( $input['shipping_mode'] ) ? sanitize_key( (string) $input['shipping_mode'] ) : '';
		$clean['shipping_mode'] = in_array( $mode, $allowed_modes, true ) ? $mode : '';

		$textarea_fields = array(
			'coverage_zones',
			'countries_served',
			'regions_served',
			'cities_served',
			'excluded_zones',
			'geographic_restrictions',
			'special_conditions',
			'shipping_costs_info',
		);

		foreach ( $textarea_fields as $field ) {
			$clean[ $field ] = isset( $input[ $field ] )
				? sanitize_textarea_field( trim( (string) $input[ $field ] ) )
				: '';
		}

		$clean['estimated_delivery_time'] = isset( $input['estimated_delivery_time'] )
			? sanitize_text_field( trim( (string) $input['estimated_delivery_time'] ) )
			: '';

		$raw_url                      = isset( $input['shipping_policy_url'] ) ? trim( (string) $input['shipping_policy_url'] ) : '';
		$clean['shipping_policy_url'] = '' !== $raw_url ? esc_url_raw( $raw_url ) : '';

		$clean['use_wc_shipping_zones'] = ! empty( $input['use_wc_shipping_zones'] ) ? 1 : 0;

		return $clean;
	}

	/**
	 * Sanitiza las páginas de políticas oficiales seleccionadas por su ID real (`aiea_policy_pages`).
	 * También sincroniza `aiea_settings['policy_pages']` para conservar compatibilidad total.
	 *
	 * @param mixed $input Datos enviados.
	 * @return array<string,string>
	 */
	public static function sanitize_policy_pages( $input ): array {
		$input     = is_array( $input ) ? $input : array();
		$supported = array_keys( AI_Ecommerce_Agent_Policies::get_supported_policies() );
		$clean     = array();

		foreach ( $supported as $pkey ) {
			$raw            = isset( $input[ $pkey ] ) ? (string) $input[ $pkey ] : '';
			$clean[ $pkey ] = self::normalize_policy_page_id( $raw );
		}

		// Mantener sincronizada la clave histórica en aiea_settings['policy_pages'].
		$general = get_option( self::OPTION_NAME, array() );
		if ( is_array( $general ) ) {
			$general['policy_pages'] = $clean;
			update_option( self::OPTION_NAME, $general );
		}

		return $clean;
	}

	/**
	 * Normaliza una referencia de página de política priorizando su ID numérico real de WordPress.
	 *
	 * @param string $raw Valor recibido del selector o configuración previa.
	 * @return string ID numérico como cadena o cadena vacía si no está asignada.
	 */
	private static function normalize_policy_page_id( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw || '0' === $raw ) {
			return '';
		}

		if ( ctype_digit( $raw ) ) {
			$page_id = absint( $raw );
			return $page_id > 0 ? (string) $page_id : '';
		}

		if ( class_exists( 'AI_Ecommerce_Agent_Policies' ) ) {
			$resolved = AI_Ecommerce_Agent_Policies::resolve_published_page( $raw );
			if ( $resolved instanceof WP_Post && ! empty( $resolved->ID ) ) {
				return (string) absint( $resolved->ID );
			}
			return AI_Ecommerce_Agent_Policies::sanitize_page_reference( $raw );
		}

		return '';
	}

	/**
	 * Sanitiza la lista estructurada de preguntas frecuentes (`aiea_faq_items`).
	 *
	 * @param mixed $input Lista de FAQs enviada desde el formulario.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sanitize_faq_items( $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$clean = array();
		$index = 1;

		foreach ( $input as $key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$question = isset( $item['question'] ) ? sanitize_text_field( trim( (string) $item['question'] ) ) : '';
			$answer   = isset( $item['answer'] ) ? sanitize_textarea_field( trim( (string) $item['answer'] ) ) : '';

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$raw_id = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
			if ( '' === $raw_id || '__new__' === (string) $key ) {
				$raw_id = 'faq_' . substr( md5( $question . '|' . microtime( true ) . '|' . $index ), 0, 10 );
			}

			$order = isset( $item['order'] ) && is_numeric( $item['order'] )
				? max( 1, (int) $item['order'] )
				: $index;

			$clean[] = array(
				'id'       => $raw_id,
				'question' => $question,
				'answer'   => $answer,
				'active'   => ! empty( $item['active'] ) ? 1 : 0,
				'order'    => $order,
			);

			$index++;
		}

		usort(
			$clean,
			function ( array $a, array $b ): int {
				return (int) $a['order'] <=> (int) $b['order'];
			}
		);

		// Renumerar el orden secuencialmente (1..N) tras ordenar.
		foreach ( $clean as $idx => &$faq ) {
			$faq['order'] = $idx + 1;
		}
		unset( $faq );

		return $clean;
	}

	/**
	 * Sanitiza la configuración de horarios y contacto (`aiea_contact_settings`).
	 *
	 * @param mixed $input Datos enviados.
	 * @return array<string,mixed>
	 */
	public static function sanitize_contact_settings( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = array();

		$allowed_days = array( 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo' );
		$raw_days     = isset( $input['business_days'] ) && is_array( $input['business_days'] ) ? $input['business_days'] : array();
		$days         = array();

		foreach ( $raw_days as $day ) {
			$day_key = sanitize_key( (string) $day );
			if ( in_array( $day_key, $allowed_days, true ) && ! in_array( $day_key, $days, true ) ) {
				$days[] = $day_key;
			}
		}
		$clean['business_days'] = $days;

		$open                  = isset( $input['opening_time'] ) ? trim( (string) $input['opening_time'] ) : '';
		$clean['opening_time'] = preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $open ) ? $open : '';

		$close                 = isset( $input['closing_time'] ) ? trim( (string) $input['closing_time'] ) : '';
		$clean['closing_time'] = preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $close ) ? $close : '';

		$clean['special_closures'] = isset( $input['special_closures'] )
			? sanitize_textarea_field( trim( (string) $input['special_closures'] ) )
			: '';

		$tz                = isset( $input['timezone'] ) ? sanitize_text_field( trim( (string) $input['timezone'] ) ) : '';
		$valid_timezones   = function_exists( 'timezone_identifiers_list' ) ? timezone_identifiers_list() : array();
		$clean['timezone'] = ( '' !== $tz && in_array( $tz, $valid_timezones, true ) ) ? $tz : '';

		$clean['preferred_channel'] = isset( $input['preferred_channel'] )
			? sanitize_text_field( trim( (string) $input['preferred_channel'] ) )
			: '';

		$raw_url              = isset( $input['contact_url'] ) ? trim( (string) $input['contact_url'] ) : '';
		$clean['contact_url'] = '' !== $raw_url ? esc_url_raw( $raw_url ) : '';

		$clean['whatsapp'] = isset( $input['whatsapp'] )
			? sanitize_text_field( trim( (string) $input['whatsapp'] ) )
			: '';

		$clean['address_location'] = isset( $input['address_location'] )
			? sanitize_text_field( trim( (string) $input['address_location'] ) )
			: '';

		$clean['human_advisor_instructions'] = isset( $input['human_advisor_instructions'] )
			? sanitize_textarea_field( trim( (string) $input['human_advisor_instructions'] ) )
			: '';

		return $clean;
	}

	/**
	 * Sanitiza la configuración de apariencia del widget (`aiea_appearance`).
	 *
	 * @param mixed $input Datos enviados.
	 * @return array<string,mixed>
	 */
	public static function sanitize_appearance( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = AI_Ecommerce_Agent_Public::get_appearance_defaults();
		$clean    = array();

		$text_fields = array(
			'agent_name',
			'subtitle',
			'toggle_text',
			'input_placeholder',
			'send_button_text',
			'typing_text',
			'generic_error_message',
			'connection_status_text',
		);

		foreach ( $text_fields as $field ) {
			$clean[ $field ] = isset( $input[ $field ] )
				? sanitize_text_field( trim( (string) $input[ $field ] ) )
				: (string) ( $defaults[ $field ] ?? '' );
		}

		$clean['welcome_message'] = isset( $input['welcome_message'] )
			? sanitize_textarea_field( trim( (string) $input['welcome_message'] ) )
			: (string) ( $defaults['welcome_message'] ?? '' );

		$color_fields = array(
			'primary_color',
			'toggle_bg_color',
			'header_bg_color',
			'window_bg_color',
			'text_primary_color',
			'text_secondary_color',
			'user_msg_bg_color',
			'assistant_msg_bg_color',
			'user_msg_text_color',
			'assistant_msg_text_color',
			'send_btn_bg_color',
			'send_btn_text_color',
			'border_color',
			'link_color',
		);

		foreach ( $color_fields as $cfield ) {
			$raw_color        = isset( $input[ $cfield ] ) ? (string) $input[ $cfield ] : '';
			$default_color    = isset( $defaults[ $cfield ] ) ? (string) $defaults[ $cfield ] : '#111827';
			$clean[ $cfield ] = AI_Ecommerce_Agent_Public::sanitize_hex_color_strict( $raw_color, $default_color );
		}

		$pos               = isset( $input['position'] ) ? sanitize_key( (string) $input['position'] ) : 'right';
		$clean['position'] = in_array( $pos, array( 'right', 'left' ), true ) ? $pos : 'right';

		$style_variant          = isset( $input['style_variant'] ) ? sanitize_key( (string) $input['style_variant'] ) : 'standard';
		$clean['style_variant'] = in_array( $style_variant, array( 'standard', 'compact' ), true ) ? $style_variant : 'standard';

		$clean['max_width'] = isset( $input['max_width'] ) && is_numeric( $input['max_width'] )
			? max( 280, min( 600, (int) $input['max_width'] ) )
			: (int) $defaults['max_width'];

		$clean['max_height'] = isset( $input['max_height'] ) && is_numeric( $input['max_height'] )
			? max( 360, min( 800, (int) $input['max_height'] ) )
			: (int) $defaults['max_height'];

		$clean['border_radius'] = isset( $input['border_radius'] ) && is_numeric( $input['border_radius'] )
			? max( 0, min( 32, (int) $input['border_radius'] ) )
			: (int) $defaults['border_radius'];

		return $clean;
	}

	/**
	 * Sanitiza los límites de consumo configurables (`aiea_usage_limits`).
	 *
	 * @param mixed $input Datos enviados.
	 * @return array<string,int>
	 */
	public static function sanitize_usage_limits( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = AI_Ecommerce_Agent_Usage::get_limits_defaults();

		return array(
			'max_messages_per_session'     => isset( $input['max_messages_per_session'] ) && is_numeric( $input['max_messages_per_session'] )
				? max( 0, min( 1000, (int) $input['max_messages_per_session'] ) )
				: $defaults['max_messages_per_session'],
			'max_new_sessions_per_ip_hour' => isset( $input['max_new_sessions_per_ip_hour'] ) && is_numeric( $input['max_new_sessions_per_ip_hour'] )
				? max( 0, min( 1000, (int) $input['max_new_sessions_per_ip_hour'] ) )
				: $defaults['max_new_sessions_per_ip_hour'],
			'max_daily_openai_calls'       => isset( $input['max_daily_openai_calls'] ) && is_numeric( $input['max_daily_openai_calls'] )
				? max( 0, min( 1000000, (int) $input['max_daily_openai_calls'] ) )
				: $defaults['max_daily_openai_calls'],
			'max_daily_tokens'             => isset( $input['max_daily_tokens'] ) && is_numeric( $input['max_daily_tokens'] )
				? max( 0, min( 100000000, (int) $input['max_daily_tokens'] ) )
				: $defaults['max_daily_tokens'],
			'duplicate_window_seconds'     => isset( $input['duplicate_window_seconds'] ) && is_numeric( $input['duplicate_window_seconds'] )
				? max( 0, min( 600, (int) $input['duplicate_window_seconds'] ) )
				: $defaults['duplicate_window_seconds'],
		);
	}

	/**
	 * Sanitiza la configuración específica para modo Demostración Pública (`aiea_demo_settings`).
	 *
	 * @param mixed $input Datos enviados.
	 * @return array<string,mixed>
	 */
	public static function sanitize_demo_settings( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = AI_Ecommerce_Agent_Usage::get_demo_defaults();

		$raw_pro_url     = isset( $input['cta_pro_url'] ) ? trim( (string) $input['cta_pro_url'] ) : '';
		$raw_install_url = isset( $input['cta_install_url'] ) ? trim( (string) $input['cta_install_url'] ) : '';

		return array(
			'enabled'                      => ! empty( $input['enabled'] ) ? 1 : 0,
			'max_messages_per_session'     => isset( $input['max_messages_per_session'] ) && is_numeric( $input['max_messages_per_session'] )
				? max( 1, min( 500, (int) $input['max_messages_per_session'] ) )
				: (int) $defaults['max_messages_per_session'],
			'max_new_sessions_per_ip_hour' => isset( $input['max_new_sessions_per_ip_hour'] ) && is_numeric( $input['max_new_sessions_per_ip_hour'] )
				? max( 1, min( 500, (int) $input['max_new_sessions_per_ip_hour'] ) )
				: (int) $defaults['max_new_sessions_per_ip_hour'],
			'min_interval_seconds'         => isset( $input['min_interval_seconds'] ) && is_numeric( $input['min_interval_seconds'] )
				? max( 1, min( 120, (int) $input['min_interval_seconds'] ) )
				: (int) $defaults['min_interval_seconds'],
			'max_messages_per_ip_hour'     => isset( $input['max_messages_per_ip_hour'] ) && is_numeric( $input['max_messages_per_ip_hour'] )
				? max( 1, min( 1000, (int) $input['max_messages_per_ip_hour'] ) )
				: (int) $defaults['max_messages_per_ip_hour'],
			'max_daily_openai_calls'       => isset( $input['max_daily_openai_calls'] ) && is_numeric( $input['max_daily_openai_calls'] )
				? max( 1, min( 100000, (int) $input['max_daily_openai_calls'] ) )
				: (int) $defaults['max_daily_openai_calls'],
			'max_daily_tokens'             => isset( $input['max_daily_tokens'] ) && is_numeric( $input['max_daily_tokens'] )
				? max( 500, min( 10000000, (int) $input['max_daily_tokens'] ) )
				: (int) $defaults['max_daily_tokens'],
			'max_message_length'           => isset( $input['max_message_length'] ) && is_numeric( $input['max_message_length'] )
				? max( 50, min( 2000, (int) $input['max_message_length'] ) )
				: (int) $defaults['max_message_length'],
			'max_output_tokens'            => isset( $input['max_output_tokens'] ) && is_numeric( $input['max_output_tokens'] )
				? max( 100, min( 1500, (int) $input['max_output_tokens'] ) )
				: (int) $defaults['max_output_tokens'],
			'cta_pro_url'                  => '' !== $raw_pro_url ? esc_url_raw( $raw_pro_url ) : '',
			'cta_install_url'              => '' !== $raw_install_url ? esc_url_raw( $raw_install_url ) : '',
		);
	}

	// =========================================================
	// 2. Carga de recursos CSS y JS del panel administrativo
	// =========================================================

	/**
	 * Encola estilos y scripts de administración.
	 *
	 * @param string $hook Identificador de la pantalla actual.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_ai-ecommerce-agent' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'aiea-admin', AIEA_URL . 'assets/css/admin.css', array(), AIEA_VERSION );
		wp_enqueue_script( 'aiea-admin', AIEA_URL . 'assets/js/admin.js', array(), AIEA_VERSION, true );
		wp_localize_script(
			'aiea-admin',
			'AIEAAdmin',
			array(
				'restUrl'            => esc_url_raw( rest_url( 'aiea/v1/chat' ) ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				'appearanceDefaults' => AI_Ecommerce_Agent_Public::get_appearance_defaults(),
				'strings'            => array(
					'loading' => 'Enviando mensaje a OpenAI…',
					'empty'   => 'Escribe un mensaje antes de probar.',
					'error'   => 'No se pudo completar la prueba.',
				),
			)
		);
	}

	// =========================================================
	// 3. Renderizado de la interfaz de administración por pestañas
	// =========================================================

	/**
	 * Renderiza la página completa de administración con sus 8 pestañas.
	 *
	 * @return void
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = array(
			'general'    => 'General',
			'business'   => 'Información del negocio',
			'shipping'   => 'Envíos y cobertura',
			'policies'   => 'Políticas oficiales',
			'faq'        => 'Preguntas frecuentes',
			'contact'    => 'Contacto y horarios',
			'appearance' => 'Apariencia del chat',
			'usage'      => 'Consumo y límites',
		);

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		if ( ! array_key_exists( $active_tab, $tabs ) ) {
			$active_tab = 'general';
		}

		$general    = self::get_options();
		$business   = AI_Ecommerce_Agent_Knowledge::get_business_info();
		$shipping   = AI_Ecommerce_Agent_Knowledge::get_shipping_coverage();
		$policies   = AI_Ecommerce_Agent_Policies::get_policy_pages_map();
		$faqs       = AI_Ecommerce_Agent_Knowledge::get_all_faqs();
		$contact    = AI_Ecommerce_Agent_Knowledge::get_contact_settings();
		$appearance = AI_Ecommerce_Agent_Public::get_appearance();
		$limits     = AI_Ecommerce_Agent_Usage::get_limits();
		$stats      = AI_Ecommerce_Agent_Usage::get_stats_summary();
		?>
		<div class="wrap aiea-admin">
			<h1>AI Ecommerce Agent</h1>

			<div class="aiea-header">
				<div>
					<h2>Agente de Ventas e Información Comercial con IA</h2>
					<p>Configura la base de conocimiento de la tienda, políticas oficiales, preguntas frecuentes, apariencia del chat y controles de consumo.</p>
				</div>
				<span class="aiea-version"><?php echo esc_html( 'v' . AIEA_VERSION ); ?></span>
			</div>

			<?php settings_errors(); ?>

			<nav class="nav-tab-wrapper aiea-nav-tabs" role="tablist" aria-label="Secciones de configuración del agente">
				<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
					<?php $is_active = ( $active_tab === $tab_key ); ?>
					<a
						href="<?php echo esc_url( add_query_arg( 'tab', $tab_key, admin_url( 'admin.php?page=ai-ecommerce-agent' ) ) ); ?>"
						class="nav-tab aiea-tab-link<?php echo $is_active ? ' nav-tab-active' : ''; ?>"
						data-tab="<?php echo esc_attr( $tab_key ); ?>"
						role="tab"
						aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
						aria-controls="aiea-tab-panel-<?php echo esc_attr( $tab_key ); ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php" id="aiea-settings-form">
				<?php settings_fields( 'aiea_settings_group' ); ?>

				<!-- PESTAÑA 1: GENERAL -->
				<div
					id="aiea-tab-panel-general"
					class="aiea-tab-panel<?php echo 'general' === $active_tab ? ' is-active' : ''; ?>"
					data-tab-panel="general"
					role="tabpanel"
					<?php echo 'general' === $active_tab ? '' : 'hidden'; ?>>
					<?php self::render_tab_general( $general ); ?>
				</div>

				<!-- PESTAÑA 2: INFORMACIÓN DEL NEGOCIO -->
				<div
					id="aiea-tab-panel-business"
					class="aiea-tab-panel<?php echo 'business' === $active_tab ? ' is-active' : ''; ?>"
					data-tab-panel="business"
					role="tabpanel"
					<?php echo 'business' === $active_tab ? '' : 'hidden'; ?>>
					<?php self::render_tab_business( $business ); ?>
				</div>

				<!-- PESTAÑA 3: ENVÍOS Y COBERTURA -->
				<div
					id="aiea-tab-panel-shipping"
					class="aiea-tab-panel<?php echo 'shipping' === $active_tab ? ' is-active' : ''; ?>"
					data-tab-panel="shipping"
					role="tabpanel"
					<?php echo 'shipping' === $active_tab ? '' : 'hidden'; ?>>
					<?php self::render_tab_shipping( $shipping ); ?>
				</div>

				<!-- PESTAÑA 4: POLÍTICAS OFICIALES -->
				<div
					id="aiea-tab-panel-policies"
					class="aiea-tab-panel<?php echo 'policies' === $active_tab ? ' is-active' : ''; ?>"
					data-tab-panel="policies"
					role="tabpanel"
					<?php echo 'policies' === $active_tab ? '' : 'hidden'; ?>>
					<?php self::render_tab_policies( $policies ); ?>
				</div>

				<!-- PESTAÑA 5: PREGUNTAS FRECUENTES -->
				<div
					id="aiea-tab-panel-faq"
					class="aiea-tab-panel<?php echo 'faq' === $active_tab ? ' is-active' : ''; ?>"
					data-tab-panel="faq"
					role="tabpanel"
					<?php echo 'faq' === $active_tab ? '' : 'hidden'; ?>>
					<?php self::render_tab_faq( $faqs ); ?>
				</div>

				<!-- PESTAÑA 6: CONTACTO Y HORARIOS -->
				<div
					id="aiea-tab-panel-contact"
					class="aiea-tab-panel<?php echo 'contact' === $active_tab ? ' is-active' : ''; ?>"
					data-tab-panel="contact"
					role="tabpanel"
					<?php echo 'contact' === $active_tab ? '' : 'hidden'; ?>>
					<?php self::render_tab_contact( $contact ); ?>
				</div>

				<!-- PESTAÑA 7: APARIENCIA DEL CHAT -->
				<div
					id="aiea-tab-panel-appearance"
					class="aiea-tab-panel<?php echo 'appearance' === $active_tab ? ' is-active' : ''; ?>"
					data-tab-panel="appearance"
					role="tabpanel"
					<?php echo 'appearance' === $active_tab ? '' : 'hidden'; ?>>
					<?php self::render_tab_appearance( $appearance ); ?>
				</div>

				<!-- PESTAÑA 8: CONSUMO Y LÍMITES -->
				<div
					id="aiea-tab-panel-usage"
					class="aiea-tab-panel<?php echo 'usage' === $active_tab ? ' is-active' : ''; ?>"
					data-tab-panel="usage"
					role="tabpanel"
					<?php echo 'usage' === $active_tab ? '' : 'hidden'; ?>>
					<?php self::render_tab_usage( $limits, $stats ); ?>
				</div>

				<div class="aiea-submit-bar">
					<?php submit_button( 'Guardar configuración', 'primary', 'submit', false ); ?>
				</div>
			</form>

			<!-- Panel administrativo de pruebas de conexión con OpenAI -->
			<section class="aiea-test-panel">
				<h2>Prueba de conexión y herramientas con OpenAI</h2>
				<p>Guarda primero la configuración. Puedes probar aquí consultas sobre el catálogo, políticas, horarios, FAQs o cobertura de envíos.</p>
				<label for="aiea-test-message"><strong>Mensaje de prueba</strong></label>
				<textarea id="aiea-test-message" rows="3" maxlength="2000" placeholder="Ej.: Hola, ¿a qué ciudades hacen envíos y qué métodos de pago aceptan?"></textarea>
				<p><button type="button" class="button button-primary" id="aiea-test-send">Enviar mensaje de prueba</button></p>
				<div id="aiea-test-status" class="aiea-test-status" role="status" aria-live="polite"></div>
				<div id="aiea-test-result" class="aiea-test-result" hidden></div>
			</section>
		</div>
		<?php
	}

	/**
	 * Pestaña 1: General.
	 *
	 * @param array<string,mixed> $general Opciones generales actuales.
	 * @return void
	 */
	private static function render_tab_general( array $general ): void {
		$has_key = ! empty( $general['api_key'] );
		$models  = array(
			'gpt-4.1-mini' => 'GPT-4.1 mini (recomendado)',
			'gpt-4.1'      => 'GPT-4.1',
			'gpt-4o-mini'  => 'GPT-4o mini',
		);
		?>
		<div class="aiea-panel-card">
			<h3>Configuración principal del agente</h3>
			<p class="description">Activa o desactiva el widget público y configura las credenciales de OpenAI.</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Estado del agente</th>
					<td>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enabled]"
								value="1"
								<?php checked( ! empty( $general['enabled'] ) ); ?>>
							Activar el chat flotante del agente en la tienda pública
						</label>
						<p class="description">Si está desmarcado, el widget no se carga en el frontend y el endpoint público devuelve HTTP 403 sin consumir tokens.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-general-agent-name">Nombre del agente</label></th>
					<td>
						<input
							type="text"
							id="aiea-general-agent-name"
							class="regular-text"
							maxlength="80"
							name="<?php echo esc_attr( self::OPTION_NAME ); ?>[agent_name]"
							value="<?php echo esc_attr( (string) $general['agent_name'] ); ?>">
						<p class="description">Nombre base del asistente. También puedes personalizar el nombre visible en el encabezado desde la pestaña «Apariencia del chat».</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-general-api-key">OpenAI API Key</label></th>
					<td>
						<input
							type="password"
							id="aiea-general-api-key"
							class="regular-text"
							autocomplete="new-password"
							spellcheck="false"
							name="<?php echo esc_attr( self::OPTION_NAME ); ?>[api_key]"
							value=""
							placeholder="<?php echo esc_attr( $has_key ? 'API Key guardada; deja vacío para conservarla' : 'Pega aquí tu API Key (sk-...)' ); ?>">
						<p class="description">
							<?php
							echo esc_html(
								$has_key
									? '✓ Hay una API Key guardada en WordPress. No se vuelve a mostrar por seguridad; deja este campo vacío para conservarla.'
									: 'Introduce tu clave de API de OpenAI. Nunca se expone en el frontend.'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-general-model">Modelo de OpenAI</label></th>
					<td>
						<select id="aiea-general-model" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[model]">
							<?php foreach ( $models as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $general['model'], $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">Modelo utilizado tanto en el chat público como en la prueba administrativa mediante la Responses API de OpenAI.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-general-support-contact">Canal rápido de soporte</label></th>
					<td>
						<input
							type="text"
							id="aiea-general-support-contact"
							class="regular-text"
							maxlength="200"
							name="<?php echo esc_attr( self::OPTION_NAME ); ?>[support_contact]"
							value="<?php echo esc_attr( (string) $general['support_contact'] ); ?>"
							placeholder="Ej.: WhatsApp +57 300 000 0000 o soporte@tutienda.com">
						<p class="description">Referencia de respaldo para atención humana (puedes detallar horarios y canales completos en la pestaña «Contacto y horarios»).</p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Pestaña 2: Información del negocio.
	 *
	 * @param array<string,string> $business Datos del negocio.
	 * @return void
	 */
	private static function render_tab_business( array $business ): void {
		$opt = AI_Ecommerce_Agent_Knowledge::OPTION_BUSINESS;
		?>
		<div class="aiea-panel-card">
			<h3>Información comercial del negocio</h3>
			<p class="description">Estos datos permiten al agente responder con exactitud quién es la tienda, dónde está ubicada y cuáles son sus canales oficiales. Los campos que dejes vacíos no serán inventados por el agente.</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aiea-biz-name">Nombre comercial del negocio</label></th>
					<td>
						<input type="text" id="aiea-biz-name" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[business_name]" value="<?php echo esc_attr( $business['business_name'] ); ?>" placeholder="Ej.: NUTRIX Colombia">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-biz-desc">Descripción breve del negocio</label></th>
					<td>
						<textarea id="aiea-biz-desc" class="large-text" rows="3" name="<?php echo esc_attr( $opt ); ?>[short_description]" placeholder="Ej.: Tienda especializada en suplementación deportiva y bienestar con registro sanitario."><?php echo esc_textarea( $business['short_description'] ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-biz-sector">Categoría o sector comercial</label></th>
					<td>
						<input type="text" id="aiea-biz-sector" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[category_sector]" value="<?php echo esc_attr( $business['category_sector'] ); ?>" placeholder="Ej.: Nutrición deportiva y suplementos">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-biz-country">País</label></th>
					<td>
						<input type="text" id="aiea-biz-country" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[country]" value="<?php echo esc_attr( $business['country'] ); ?>" placeholder="Ej.: Colombia">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-biz-region">Departamento, estado o región</label></th>
					<td>
						<input type="text" id="aiea-biz-region" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[state_region]" value="<?php echo esc_attr( $business['state_region'] ); ?>" placeholder="Ej.: Antioquia / Cundinamarca">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-biz-city">Ciudad</label></th>
					<td>
						<input type="text" id="aiea-biz-city" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[city]" value="<?php echo esc_attr( $business['city'] ); ?>" placeholder="Ej.: Medellín / Bogotá">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-biz-address">Dirección física</label></th>
					<td>
						<input type="text" id="aiea-biz-address" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[address]" value="<?php echo esc_attr( $business['address'] ); ?>" placeholder="Deja vacío si es tienda 100 % virtual sin sede abierta al público">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-biz-email">Correo electrónico de contacto</label></th>
					<td>
						<input type="email" id="aiea-biz-email" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[email]" value="<?php echo esc_attr( $business['email'] ); ?>" placeholder="contacto@tutienda.com">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-biz-phone">Teléfono</label></th>
					<td>
						<input type="text" id="aiea-biz-phone" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[phone]" value="<?php echo esc_attr( $business['phone'] ); ?>" placeholder="Ej.: +57 (604) 000 0000">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-biz-whatsapp">WhatsApp</label></th>
					<td>
						<input type="text" id="aiea-biz-whatsapp" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[whatsapp]" value="<?php echo esc_attr( $business['whatsapp'] ); ?>" placeholder="Ej.: +57 300 000 0000">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-biz-web">URL del sitio web</label></th>
					<td>
						<input type="url" id="aiea-biz-web" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[website_url]" value="<?php echo esc_attr( $business['website_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-biz-contact-url">URL de la página de contacto</label></th>
					<td>
						<input type="url" id="aiea-biz-contact-url" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[contact_url]" value="<?php echo esc_attr( $business['contact_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/contacto/' ) ); ?>">
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Pestaña 3: Envíos y cobertura.
	 *
	 * @param array<string,mixed> $shipping Configuración de envíos.
	 * @return void
	 */
	private static function render_tab_shipping( array $shipping ): void {
		$opt   = AI_Ecommerce_Agent_Knowledge::OPTION_SHIPPING;
		$modes = array(
			''                  => '— Sin especificar —',
			'nacional'          => 'Cobertura nacional',
			'regional'          => 'Cobertura regional',
			'local'             => 'Cobertura local',
			'zonas_especificas' => 'Zonas específicas',
			'recogida_tienda'   => 'Solo recogida en tienda',
			'mixto'             => 'Mixto (envío a domicilio y recogida en tienda)',
		);
		?>
		<div class="aiea-panel-card">
			<h3>Configuración de envíos y cobertura geográfica</h3>
			<p class="description">El agente verificará esta configuración antes de responder si existe envío a una ciudad, región o país. Nunca asumirá cobertura nacional si solo indicas determinadas ciudades o regiones.</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aiea-ship-mode">Modalidad de envío</label></th>
					<td>
						<select id="aiea-ship-mode" name="<?php echo esc_attr( $opt ); ?>[shipping_mode]">
							<?php foreach ( $modes as $m_val => $m_label ) : ?>
								<option value="<?php echo esc_attr( $m_val ); ?>" <?php selected( $shipping['shipping_mode'], $m_val ); ?>>
									<?php echo esc_html( $m_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-ship-zones">Zonas de cobertura (resumen general)</label></th>
					<td>
						<textarea id="aiea-ship-zones" class="large-text" rows="2" name="<?php echo esc_attr( $opt ); ?>[coverage_zones]" placeholder="Ej.: Principales ciudades y áreas metropolitanas (separa por comas o saltos de línea)."><?php echo esc_textarea( (string) $shipping['coverage_zones'] ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-ship-countries">Países atendidos</label></th>
					<td>
						<textarea id="aiea-ship-countries" class="large-text" rows="2" name="<?php echo esc_attr( $opt ); ?>[countries_served]" placeholder="Ej.: Colombia"><?php echo esc_textarea( (string) $shipping['countries_served'] ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-ship-regions">Departamentos, estados o regiones atendidas</label></th>
					<td>
						<textarea id="aiea-ship-regions" class="large-text" rows="2" name="<?php echo esc_attr( $opt ); ?>[regions_served]" placeholder="Ej.: Antioquia, Cundinamarca, Valle del Cauca, Atlántico"><?php echo esc_textarea( (string) $shipping['regions_served'] ); ?></textarea>
						<p class="description">Separa cada departamento o región por comas o en líneas distintas.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-ship-cities">Ciudades o municipios atendidos</label></th>
					<td>
						<textarea id="aiea-ship-cities" class="large-text" rows="3" name="<?php echo esc_attr( $opt ); ?>[cities_served]" placeholder="Ej.: Bogotá, Medellín, Cali, Barranquilla, Bucaramanga"><?php echo esc_textarea( (string) $shipping['cities_served'] ); ?></textarea>
						<p class="description">Si la cobertura es local o por zonas específicas, enumera las ciudades atendidas.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-ship-excluded">Zonas excluidas o sin cobertura</label></th>
					<td>
						<textarea id="aiea-ship-excluded" class="large-text" rows="2" name="<?php echo esc_attr( $opt ); ?>[excluded_zones]" placeholder="Ej.: San Andrés y Providencia, Amazonas, veredas sin nomenclatura urbana"><?php echo esc_textarea( (string) $shipping['excluded_zones'] ); ?></textarea>
						<p class="description">Las ubicaciones incluidas aquí nunca serán confirmadas como atendidas, incluso en modalidad nacional.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-ship-restrictions">Restricciones geográficas o de acceso</label></th>
					<td>
						<textarea id="aiea-ship-restrictions" class="large-text" rows="2" name="<?php echo esc_attr( $opt ); ?>[geographic_restrictions]" placeholder="Ej.: No se realizan entregas en apartados postales ni zonas rurales de difícil acceso sin cobertura de transportadora."><?php echo esc_textarea( (string) $shipping['geographic_restrictions'] ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-ship-special">Condiciones especiales de entrega</label></th>
					<td>
						<textarea id="aiea-ship-special" class="large-text" rows="2" name="<?php echo esc_attr( $opt ); ?>[special_conditions]" placeholder="Ej.: Los pedidos realizados después de las 2:00 p. m. se despachan al siguiente día hábil."><?php echo esc_textarea( (string) $shipping['special_conditions'] ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-ship-costs">Costos de envío o cómo se calculan</label></th>
					<td>
						<textarea id="aiea-ship-costs" class="large-text" rows="2" name="<?php echo esc_attr( $opt ); ?>[shipping_costs_info]" placeholder="Ej.: Envío estándar $12.000 COP. Envío gratis en compras superiores a $150.000 COP."><?php echo esc_textarea( (string) $shipping['shipping_costs_info'] ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-ship-time">Tiempo estimado de entrega</label></th>
					<td>
						<input type="text" id="aiea-ship-time" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[estimated_delivery_time]" value="<?php echo esc_attr( (string) $shipping['estimated_delivery_time'] ); ?>" placeholder="Ej.: 1 a 3 días hábiles en ciudades principales; 3 a 5 días hábiles en el resto del país">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-ship-url">URL de la página oficial de envíos</label></th>
					<td>
						<input type="url" id="aiea-ship-url" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[shipping_policy_url]" value="<?php echo esc_attr( (string) $shipping['shipping_policy_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/envios-y-entregas/' ) ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row">Zonas de envío de WooCommerce</th>
					<td>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( $opt ); ?>[use_wc_shipping_zones]"
								value="1"
								<?php checked( ! empty( $shipping['use_wc_shipping_zones'] ) ); ?>>
							Incluir las zonas y métodos de envío activos de WooCommerce como fuente complementaria de solo lectura
						</label>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Pestaña 4: Políticas oficiales (selección mediante ID real de página publicada en WordPress).
	 *
	 * @param array<string,string> $policies Mapa de clave de política => referencia guardada.
	 * @return void
	 */
	private static function render_tab_policies( array $policies ): void {
		$opt       = AI_Ecommerce_Agent_Policies::OPTION_POLICY_PAGES;
		$supported = AI_Ecommerce_Agent_Policies::get_supported_policies();
		$pages     = function_exists( 'get_pages' )
			? get_pages(
				array(
					'post_status' => 'publish',
					'sort_column' => 'post_title',
					'sort_order'  => 'ASC',
				)
			)
			: array();
		$pages     = is_array( $pages ) ? $pages : array();
		?>
		<div class="aiea-panel-card">
			<h3>Páginas oficiales de políticas e información legal</h3>
			<p class="description">Selecciona la página publicada de WordPress para cada política oficial. El plugin almacena el <strong>ID real de la página (`page_id`)</strong> y extrae su contenido real en el servidor cuando el cliente hace una consulta. Si una política queda sin página asignada, el agente nunca la inventará.</p>

			<table class="form-table" role="presentation">
				<?php foreach ( $supported as $pkey => $plabel ) : ?>
					<?php
					$current_ref = isset( $policies[ $pkey ] ) ? (string) $policies[ $pkey ] : '';
					$resolved    = ( '' !== $current_ref )
						? AI_Ecommerce_Agent_Policies::resolve_published_page( $current_ref )
						: null;
					$selected_id = ( $resolved instanceof WP_Post ) ? (int) $resolved->ID : 0;
					?>
					<tr>
						<th scope="row">
							<label for="aiea-policy-select-<?php echo esc_attr( $pkey ); ?>">
								<?php echo esc_html( $plabel ); ?>
							</label>
						</th>
						<td>
							<select
								id="aiea-policy-select-<?php echo esc_attr( $pkey ); ?>"
								name="<?php echo esc_attr( $opt ); ?>[<?php echo esc_attr( $pkey ); ?>]">
								<option value="0">— Sin página asignada —</option>
								<?php foreach ( $pages as $page ) : ?>
									<?php if ( $page instanceof WP_Post && '' === (string) $page->post_password ) : ?>
										<option
											value="<?php echo esc_attr( (string) $page->ID ); ?>"
											<?php selected( $selected_id, (int) $page->ID ); ?>>
											<?php
											echo esc_html(
												sprintf(
													'%s (ID: %d)',
													$page->post_title,
													(int) $page->ID
												)
											);
											?>
										</option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>

							<?php if ( $resolved instanceof WP_Post ) : ?>
								<p class="description aiea-policy-status is-verified">
									<?php
									echo esc_html(
										sprintf(
											'✓ Página publicada vinculada: «%s» (ID: %d)',
											get_the_title( $resolved ),
											(int) $resolved->ID
										)
									);
									?>
									— <a href="<?php echo esc_url( (string) get_permalink( $resolved ) ); ?>" target="_blank" rel="noopener">Ver página</a>
								</p>
							<?php elseif ( '' !== $current_ref && '0' !== $current_ref ) : ?>
								<p class="description aiea-policy-status is-warning">
									⚠ La referencia anterior («<?php echo esc_html( $current_ref ); ?>») no corresponde a una página publicada. Selecciona una página válida de la lista.
								</p>
							<?php else : ?>
								<p class="description">Sin página asignada. El agente indicará que no dispone de confirmación oficial si le preguntan por este tema.</p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>
		<?php
	}

	/**
	 * Pestaña 5: Gestor de preguntas frecuentes (FAQ).
	 *
	 * @param array<int,array<string,mixed>> $faqs Lista actual de FAQs.
	 * @return void
	 */
	private static function render_tab_faq( array $faqs ): void {
		$opt = AI_Ecommerce_Agent_Knowledge::OPTION_FAQ;
		?>
		<div class="aiea-panel-card">
			<h3>Gestor de preguntas frecuentes (FAQ)</h3>
			<p class="description">Crea, edita, activa o desactiva y ordena las preguntas frecuentes de la tienda. El agente solo consultará las preguntas marcadas como <strong>Activas</strong>.</p>

			<!-- Formulario rápido para añadir o editar una FAQ -->
			<div class="aiea-faq-editor" id="aiea-faq-editor">
				<h4 id="aiea-faq-editor-title">Añadir nueva pregunta frecuente</h4>
				<input type="hidden" id="aiea-faq-editor-id" value="">

				<div class="aiea-faq-editor-row">
					<label for="aiea-faq-editor-question"><strong>Pregunta</strong></label>
					<input
						type="text"
						id="aiea-faq-editor-question"
						class="large-text"
						name="<?php echo esc_attr( $opt ); ?>[__new__][question]"
						placeholder="Ej.: ¿Cuánto tarda en llegar mi pedido?">
				</div>

				<div class="aiea-faq-editor-row">
					<label for="aiea-faq-editor-answer"><strong>Respuesta oficial</strong></label>
					<textarea
						id="aiea-faq-editor-answer"
						class="large-text"
						rows="3"
						name="<?php echo esc_attr( $opt ); ?>[__new__][answer]"
						placeholder="Escribe aquí la respuesta exacta que debe dar el asistente."></textarea>
				</div>

				<div class="aiea-faq-editor-actions">
					<label>
						<input
							type="checkbox"
							id="aiea-faq-editor-active"
							name="<?php echo esc_attr( $opt ); ?>[__new__][active]"
							value="1"
							checked>
						Pregunta activa (disponible para el agente)
					</label>

					<div class="aiea-faq-editor-buttons">
						<button type="button" class="button button-secondary" id="aiea-faq-add-btn">Añadir a la lista</button>
						<button type="button" class="button" id="aiea-faq-cancel-btn" hidden>Cancelar edición</button>
					</div>
				</div>
				<p class="description">Recuerda pulsar <strong>«Guardar configuración»</strong> al finalizar tus cambios.</p>
			</div>

			<!-- Buscador y contador de FAQs -->
			<div class="aiea-faq-toolbar">
				<input
					type="search"
					id="aiea-faq-search"
					class="regular-text"
					placeholder="Buscar en las preguntas frecuentes…">
				<span class="aiea-faq-count" id="aiea-faq-count">
					<?php echo esc_html( sprintf( '%d preguntas registradas', count( $faqs ) ) ); ?>
				</span>
			</div>

			<!-- Listado editable y ordenable de FAQs -->
			<div id="aiea-faq-list" class="aiea-faq-list" data-option-name="<?php echo esc_attr( $opt ); ?>">
				<?php if ( empty( $faqs ) ) : ?>
					<p class="aiea-faq-empty" id="aiea-faq-empty">Todavía no hay preguntas frecuentes configuradas. Usa el formulario superior para crear la primera.</p>
				<?php else : ?>
					<?php foreach ( $faqs as $idx => $faq ) : ?>
						<div class="aiea-faq-item<?php echo empty( $faq['active'] ) ? ' is-inactive' : ''; ?>" data-faq-id="<?php echo esc_attr( (string) $faq['id'] ); ?>">
							<input type="hidden" class="aiea-faq-input-id" name="<?php echo esc_attr( $opt ); ?>[<?php echo (int) $idx; ?>][id]" value="<?php echo esc_attr( (string) $faq['id'] ); ?>">
							<input type="hidden" class="aiea-faq-input-order" name="<?php echo esc_attr( $opt ); ?>[<?php echo (int) $idx; ?>][order]" value="<?php echo (int) $faq['order']; ?>">

							<div class="aiea-faq-item-header">
								<span class="aiea-faq-order-badge">#<?php echo (int) ( $idx + 1 ); ?></span>
								<input
									type="text"
									class="aiea-faq-input-question large-text"
									name="<?php echo esc_attr( $opt ); ?>[<?php echo (int) $idx; ?>][question]"
									value="<?php echo esc_attr( (string) $faq['question'] ); ?>">
								<label class="aiea-faq-active-toggle">
									<input
										type="checkbox"
										class="aiea-faq-input-active"
										name="<?php echo esc_attr( $opt ); ?>[<?php echo (int) $idx; ?>][active]"
										value="1"
										<?php checked( ! empty( $faq['active'] ) ); ?>>
									<span>Activa</span>
								</label>
							</div>

							<div class="aiea-faq-item-body">
								<textarea
									class="aiea-faq-input-answer large-text"
									rows="2"
									name="<?php echo esc_attr( $opt ); ?>[<?php echo (int) $idx; ?>][answer]"><?php echo esc_textarea( (string) $faq['answer'] ); ?></textarea>
							</div>

							<div class="aiea-faq-item-footer">
								<button type="button" class="button button-small aiea-faq-move-up" title="Subir posición">↑ Subir</button>
								<button type="button" class="button button-small aiea-faq-move-down" title="Bajar posición">↓ Bajar</button>
								<button type="button" class="button button-small aiea-faq-edit-focus">Editar arriba</button>
								<button type="button" class="button button-small button-link-delete aiea-faq-delete">Eliminar</button>
							</div>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Pestaña 6: Contacto y horarios.
	 *
	 * @param array<string,mixed> $contact Configuración de contacto y horarios.
	 * @return void
	 */
	private static function render_tab_contact( array $contact ): void {
		$opt       = AI_Ecommerce_Agent_Knowledge::OPTION_CONTACT;
		$days_map  = array(
			'lunes'     => 'Lunes',
			'martes'    => 'Martes',
			'miercoles' => 'Miércoles',
			'jueves'    => 'Jueves',
			'viernes'   => 'Viernes',
			'sabado'    => 'Sábado',
			'domingo'   => 'Domingo',
		);
		$sel_days  = isset( $contact['business_days'] ) && is_array( $contact['business_days'] ) ? $contact['business_days'] : array();
		$timezones = function_exists( 'timezone_identifiers_list' ) ? timezone_identifiers_list() : array( 'America/Bogota', 'UTC' );
		$wp_tz     = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : '';
		?>
		<div class="aiea-panel-card">
			<h3>Horarios de atención y canales de contacto</h3>
			<p class="description">El agente calculará si el negocio está abierto o cerrado en este instante únicamente si configuras los días de atención, hora de apertura, hora de cierre y una zona horaria válida.</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Días de atención</th>
					<td>
						<fieldset class="aiea-days-fieldset">
							<?php foreach ( $days_map as $day_key => $day_label ) : ?>
								<label class="aiea-day-checkbox">
									<input
										type="checkbox"
										name="<?php echo esc_attr( $opt ); ?>[business_days][]"
										value="<?php echo esc_attr( $day_key ); ?>"
										<?php checked( in_array( $day_key, $sel_days, true ) ); ?>>
									<?php echo esc_html( $day_label ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-contact-open">Hora de apertura (24 h)</label></th>
					<td>
						<input
							type="time"
							id="aiea-contact-open"
							name="<?php echo esc_attr( $opt ); ?>[opening_time]"
							value="<?php echo esc_attr( (string) $contact['opening_time'] ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-contact-close">Hora de cierre (24 h)</label></th>
					<td>
						<input
							type="time"
							id="aiea-contact-close"
							name="<?php echo esc_attr( $opt ); ?>[closing_time]"
							value="<?php echo esc_attr( (string) $contact['closing_time'] ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-contact-tz">Zona horaria del negocio</label></th>
					<td>
						<select id="aiea-contact-tz" name="<?php echo esc_attr( $opt ); ?>[timezone]">
							<option value="">— Sin configurar (no calcular estado abierto/cerrado) —</option>
							<?php foreach ( $timezones as $tz ) : ?>
								<option value="<?php echo esc_attr( $tz ); ?>" <?php selected( $contact['timezone'], $tz ); ?>>
									<?php echo esc_html( $tz ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php if ( '' !== $wp_tz ) : ?>
							<p class="description">Zona horaria actual de WordPress: <code><?php echo esc_html( $wp_tz ); ?></code>.</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-contact-closures">Festivos o cierres especiales</label></th>
					<td>
						<textarea
							id="aiea-contact-closures"
							class="large-text"
							rows="2"
							name="<?php echo esc_attr( $opt ); ?>[special_closures]"
							placeholder="Ej.: Cerrado en días festivos nacionales de Colombia, 25 de diciembre y 1 de enero (o indica fechas en formato YYYY-MM-DD)."><?php echo esc_textarea( (string) $contact['special_closures'] ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-contact-channel">Canal preferido de atención humana</label></th>
					<td>
						<input
							type="text"
							id="aiea-contact-channel"
							class="regular-text"
							name="<?php echo esc_attr( $opt ); ?>[preferred_channel]"
							value="<?php echo esc_attr( (string) $contact['preferred_channel'] ); ?>"
							placeholder="Ej.: WhatsApp de Servicio al Cliente">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-contact-whatsapp">WhatsApp de atención</label></th>
					<td>
						<input
							type="text"
							id="aiea-contact-whatsapp"
							class="regular-text"
							name="<?php echo esc_attr( $opt ); ?>[whatsapp]"
							value="<?php echo esc_attr( (string) $contact['whatsapp'] ); ?>"
							placeholder="Ej.: +57 300 000 0000">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-contact-url">Enlace de contacto</label></th>
					<td>
						<input
							type="url"
							id="aiea-contact-url"
							class="regular-text"
							name="<?php echo esc_attr( $opt ); ?>[contact_url]"
							value="<?php echo esc_attr( (string) $contact['contact_url'] ); ?>"
							placeholder="<?php echo esc_attr( home_url( '/contacto/' ) ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-contact-address">Dirección o ubicación física</label></th>
					<td>
						<input
							type="text"
							id="aiea-contact-address"
							class="regular-text"
							name="<?php echo esc_attr( $opt ); ?>[address_location]"
							value="<?php echo esc_attr( (string) $contact['address_location'] ); ?>"
							placeholder="Ej.: Calle 10 # 40-20, Medellín, Colombia">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-contact-human">Instrucciones para hablar con un asesor humano</label></th>
					<td>
						<textarea
							id="aiea-contact-human"
							class="large-text"
							rows="3"
							name="<?php echo esc_attr( $opt ); ?>[human_advisor_instructions]"
							placeholder="Ej.: Puedes comunicarte con un asesor humano escribiendo a nuestro WhatsApp +57 300 000 0000 o al correo soporte@tutienda.com indicando tu número de pedido."><?php echo esc_textarea( (string) $contact['human_advisor_instructions'] ); ?></textarea>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Pestaña 7: Apariencia del chat.
	 *
	 * @param array<string,mixed> $appearance Configuración visual del widget.
	 * @return void
	 */
	private static function render_tab_appearance( array $appearance ): void {
		$opt          = AI_Ecommerce_Agent_Public::OPTION_APPEARANCE;
		$color_fields = array(
			'primary_color'            => 'Color principal del widget (acento y foco)',
			'toggle_bg_color'          => 'Color de fondo del botón flotante',
			'header_bg_color'          => 'Color de fondo del encabezado',
			'window_bg_color'          => 'Color de fondo de la ventana del chat',
			'text_primary_color'       => 'Color de texto principal',
			'text_secondary_color'     => 'Color de texto secundario / subtítulo',
			'user_msg_bg_color'        => 'Color de fondo de los mensajes del usuario',
			'user_msg_text_color'      => 'Color del texto de los mensajes del usuario',
			'assistant_msg_bg_color'   => 'Color de fondo de los mensajes del asistente',
			'assistant_msg_text_color' => 'Color del texto de los mensajes del asistente',
			'send_btn_bg_color'        => 'Color de fondo del botón de enviar',
			'send_btn_text_color'      => 'Color del icono/texto del botón de enviar',
			'border_color'             => 'Color de los bordes',
			'link_color'               => 'Color de los enlaces',
		);
		?>
		<div class="aiea-panel-card">
			<div class="aiea-card-header-flex">
				<div>
					<h3>Personalización visual e identidad del chat</h3>
					<p class="description">Adapta el widget flotante a la identidad visual de la tienda mediante variables CSS (`--aiea-*`). Si no modificas estos valores, se conserva exactamente el diseño original.</p>
				</div>
				<button type="button" class="button" id="aiea-reset-appearance">Restaurar diseño original</button>
			</div>

			<h4>1. Identidad y textos del chat</h4>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aiea-app-name">Nombre visible del asistente</label></th>
					<td>
						<input type="text" id="aiea-app-name" class="regular-text" maxlength="80" name="<?php echo esc_attr( $opt ); ?>[agent_name]" value="<?php echo esc_attr( (string) ( $appearance['agent_name'] ?? '' ) ); ?>" placeholder="Deja vacío para usar el nombre configurado en la pestaña General">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-app-subtitle">Subtítulo del encabezado</label></th>
					<td>
						<input type="text" id="aiea-app-subtitle" class="regular-text" maxlength="80" name="<?php echo esc_attr( $opt ); ?>[subtitle]" value="<?php echo esc_attr( (string) ( $appearance['subtitle'] ?? '' ) ); ?>" placeholder="Ej.: Asesoría de productos en línea">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-app-status-text">Texto de estado de conexión (opcional)</label></th>
					<td>
						<input type="text" id="aiea-app-status-text" class="regular-text" maxlength="60" name="<?php echo esc_attr( $opt ); ?>[connection_status_text]" value="<?php echo esc_attr( (string) ( $appearance['connection_status_text'] ?? '' ) ); ?>" placeholder="Ej.: En línea">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-app-welcome">Mensaje inicial de bienvenida</label></th>
					<td>
						<textarea id="aiea-app-welcome" class="large-text" rows="2" maxlength="300" name="<?php echo esc_attr( $opt ); ?>[welcome_message]" placeholder="Deja vacío para usar el saludo predeterminado con el nombre del asistente."><?php echo esc_textarea( (string) ( $appearance['welcome_message'] ?? '' ) ); ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-app-placeholder">Placeholder del campo de escritura</label></th>
					<td>
						<input type="text" id="aiea-app-placeholder" class="regular-text" maxlength="120" name="<?php echo esc_attr( $opt ); ?>[input_placeholder]" value="<?php echo esc_attr( (string) ( $appearance['input_placeholder'] ?? '' ) ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-app-send-text">Texto del botón de envío (opcional)</label></th>
					<td>
						<input type="text" id="aiea-app-send-text" class="regular-text" maxlength="30" name="<?php echo esc_attr( $opt ); ?>[send_button_text]" value="<?php echo esc_attr( (string) ( $appearance['send_button_text'] ?? '' ) ); ?>" placeholder="Deja vacío para mostrar únicamente el icono de enviar">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-app-launcher-label">Texto del botón flotante cuando está cerrado</label></th>
					<td>
						<input type="text" id="aiea-app-launcher-label" class="regular-text" maxlength="40" name="<?php echo esc_attr( $opt ); ?>[toggle_text]" value="<?php echo esc_attr( (string) ( $appearance['toggle_text'] ?? '' ) ); ?>" placeholder="Ej.: ¿Necesitas ayuda?">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-app-typing-text">Texto del indicador «escribiendo…»</label></th>
					<td>
						<input type="text" id="aiea-app-typing-text" class="regular-text" maxlength="40" name="<?php echo esc_attr( $opt ); ?>[typing_text]" value="<?php echo esc_attr( (string) ( $appearance['typing_text'] ?? '' ) ); ?>" placeholder="Ej.: Respondiendo…">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-app-error-msg">Mensaje genérico de error de conexión</label></th>
					<td>
						<input type="text" id="aiea-app-error-msg" class="large-text" maxlength="200" name="<?php echo esc_attr( $opt ); ?>[generic_error_message]" value="<?php echo esc_attr( (string) ( $appearance['generic_error_message'] ?? '' ) ); ?>">
					</td>
				</tr>
			</table>

			<h4>2. Paleta de colores (Hexadecimal)</h4>
			<table class="form-table" role="presentation">
				<?php foreach ( $color_fields as $ckey => $clabel ) : ?>
					<?php $cval = isset( $appearance[ $ckey ] ) ? (string) $appearance[ $ckey ] : '#111827'; ?>
					<tr>
						<th scope="row"><label for="aiea-color-<?php echo esc_attr( $ckey ); ?>"><?php echo esc_html( $clabel ); ?></label></th>
						<td>
							<div class="aiea-color-field-group">
								<input
									type="color"
									class="aiea-color-picker"
									data-target="aiea-color-<?php echo esc_attr( $ckey ); ?>"
									value="<?php echo esc_attr( $cval ); ?>">
								<input
									type="text"
									id="aiea-color-<?php echo esc_attr( $ckey ); ?>"
									class="small-text aiea-color-hex"
									maxlength="7"
									pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$"
									name="<?php echo esc_attr( $opt ); ?>[<?php echo esc_attr( $ckey ); ?>]"
									value="<?php echo esc_attr( $cval ); ?>">
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h4>3. Diseño, dimensiones y posición</h4>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aiea-app-position">Posición del botón flotante</label></th>
					<td>
						<select id="aiea-app-position" name="<?php echo esc_attr( $opt ); ?>[position]">
							<option value="right" <?php selected( $appearance['position'] ?? 'right', 'right' ); ?>>Esquina inferior derecha (predeterminado)</option>
							<option value="left" <?php selected( $appearance['position'] ?? 'right', 'left' ); ?>>Esquina inferior izquierda</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-app-style-variant">Variante de tamaño</label></th>
					<td>
						<select id="aiea-app-style-variant" name="<?php echo esc_attr( $opt ); ?>[style_variant]">
							<option value="standard" <?php selected( $appearance['style_variant'] ?? 'standard', 'standard' ); ?>>Estándar (predeterminado)</option>
							<option value="compact" <?php selected( $appearance['style_variant'] ?? 'standard', 'compact' ); ?>>Compacto</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-app-width">Ancho máximo de la ventana en escritorio (px)</label></th>
					<td>
						<input type="number" id="aiea-app-width" class="small-text" min="280" max="600" name="<?php echo esc_attr( $opt ); ?>[max_width]" value="<?php echo (int) ( $appearance['max_width'] ?? 370 ); ?>"> px (280 – 600)
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-app-height">Alto máximo de la ventana en escritorio (px)</label></th>
					<td>
						<input type="number" id="aiea-app-height" class="small-text" min="360" max="800" name="<?php echo esc_attr( $opt ); ?>[max_height]" value="<?php echo (int) ( $appearance['max_height'] ?? 520 ); ?>"> px (360 – 800)
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-app-radius">Radio de bordes de la ventana (px)</label></th>
					<td>
						<input type="number" id="aiea-app-radius" class="small-text" min="0" max="32" name="<?php echo esc_attr( $opt ); ?>[border_radius]" value="<?php echo (int) ( $appearance['border_radius'] ?? 16 ); ?>"> px (0 – 32)
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Pestaña 8: Consumo, límites y estadísticas básicas.
	 *
	 * @param array<string,int>   $limits Límites actuales.
	 * @param array<string,mixed> $stats  Resumen de estadísticas (día actual y acumulado total).
	 * @return void
	 */
	private static function render_tab_usage( array $limits, array $stats ): void {
		$opt      = AI_Ecommerce_Agent_Usage::OPTION_LIMITS;
		$demo_opt = AI_Ecommerce_Agent_Usage::OPTION_DEMO;
		$demo     = AI_Ecommerce_Agent_Usage::get_demo_settings();
		$today    = isset( $stats['today'] ) && is_array( $stats['today'] ) ? $stats['today'] : array();
		$total    = isset( $stats['total'] ) && is_array( $stats['total'] ) ? $stats['total'] : array();
		?>
		<div class="aiea-panel-card">
			<h3>Modo Demostración Pública (Jora IA — Tienda Demo)</h3>
			<p class="description">Activa este modo cuando la instalación funcione como <strong>tienda de demostración pública</strong> (por ejemplo, NUTRIX). El modo demo se aplica exclusivamente en el servidor y utiliza sus propios topes estrictos de seguridad sin alterar los límites configurados para instalaciones comerciales.</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Estado del modo demostración</th>
					<td>
						<label>
							<input
								type="checkbox"
								id="aiea-demo-enabled"
								name="<?php echo esc_attr( $demo_opt ); ?>[enabled]"
								value="1"
								<?php checked( ! empty( $demo['enabled'] ) ); ?>>
							<strong>Activar Modo Demostración Pública en esta tienda</strong>
						</label>
						<p class="description">Cuando está activo, el endpoint público aplica los límites de demostración indicados a continuación y muestra los enlaces de conversión al alcanzar el límite por sesión.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-demo-session-msgs">Máximo de mensajes por sesión (Demo)</label></th>
					<td>
						<input
							type="number"
							id="aiea-demo-session-msgs"
							class="small-text"
							min="1"
							max="500"
							name="<?php echo esc_attr( $demo_opt ); ?>[max_messages_per_session]"
							value="<?php echo (int) $demo['max_messages_per_session']; ?>">
						<p class="description">Valor recomendado para demo pública: <code>10</code>.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-demo-new-sessions">Máximo de sesiones nuevas por IP por hora (Demo)</label></th>
					<td>
						<input
							type="number"
							id="aiea-demo-new-sessions"
							class="small-text"
							min="1"
							max="500"
							name="<?php echo esc_attr( $demo_opt ); ?>[max_new_sessions_per_ip_hour]"
							value="<?php echo (int) $demo['max_new_sessions_per_ip_hour']; ?>">
						<p class="description">Valor recomendado para demo pública: <code>3</code>.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-demo-min-interval">Intervalo mínimo entre mensajes de la misma sesión/IP (segundos)</label></th>
					<td>
						<input
							type="number"
							id="aiea-demo-min-interval"
							class="small-text"
							min="1"
							max="120"
							name="<?php echo esc_attr( $demo_opt ); ?>[min_interval_seconds]"
							value="<?php echo (int) $demo['min_interval_seconds']; ?>"> s
						<p class="description">Valor recomendado para demo pública: <code>3</code> segundos.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-demo-ip-hour">Máximo de mensajes por IP por hora (Demo)</label></th>
					<td>
						<input
							type="number"
							id="aiea-demo-ip-hour"
							class="small-text"
							min="1"
							max="1000"
							name="<?php echo esc_attr( $demo_opt ); ?>[max_messages_per_ip_hour]"
							value="<?php echo (int) $demo['max_messages_per_ip_hour']; ?>">
						<p class="description">Valor recomendado para demo pública: <code>15</code>.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-demo-daily-calls">Máximo global diario de llamadas a OpenAI (Demo)</label></th>
					<td>
						<input
							type="number"
							id="aiea-demo-daily-calls"
							class="regular-text"
							min="1"
							max="100000"
							name="<?php echo esc_attr( $demo_opt ); ?>[max_daily_openai_calls]"
							value="<?php echo (int) $demo['max_daily_openai_calls']; ?>">
						<p class="description">Valor recomendado para demo pública: <code>100</code>.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-demo-daily-tokens">Máximo global diario de tokens totales (Demo)</label></th>
					<td>
						<input
							type="number"
							id="aiea-demo-daily-tokens"
							class="regular-text"
							min="500"
							max="10000000"
							name="<?php echo esc_attr( $demo_opt ); ?>[max_daily_tokens]"
							value="<?php echo (int) $demo['max_daily_tokens']; ?>">
						<p class="description">Valor recomendado para demo pública: <code>30000</code>.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-demo-msg-len">Longitud máxima de mensaje del usuario (Demo)</label></th>
					<td>
						<input
							type="number"
							id="aiea-demo-msg-len"
							class="small-text"
							min="50"
							max="2000"
							name="<?php echo esc_attr( $demo_opt ); ?>[max_message_length]"
							value="<?php echo (int) $demo['max_message_length']; ?>"> caracteres
						<p class="description">Valor recomendado para demo pública: <code>500</code>.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-demo-output-tokens">Máximo de tokens de salida por respuesta de OpenAI (Demo)</label></th>
					<td>
						<input
							type="number"
							id="aiea-demo-output-tokens"
							class="small-text"
							min="100"
							max="1500"
							name="<?php echo esc_attr( $demo_opt ); ?>[max_output_tokens]"
							value="<?php echo (int) $demo['max_output_tokens']; ?>"> tokens
						<p class="description">Valor recomendado para demo pública: <code>350</code>.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-demo-cta-pro">URL del botón «Conocer Jora IA Pro»</label></th>
					<td>
						<input
							type="url"
							id="aiea-demo-cta-pro"
							class="regular-text"
							name="<?php echo esc_attr( $demo_opt ); ?>[cta_pro_url]"
							value="<?php echo esc_attr( (string) $demo['cta_pro_url'] ); ?>"
							placeholder="Opcional: configura aquí la URL de planes cuando esté disponible">
						<p class="description">Se muestra en el chat cuando el visitante alcanza el límite de mensajes por sesión de la demo.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-demo-cta-install">URL del botón «Solicitar instalación»</label></th>
					<td>
						<input
							type="url"
							id="aiea-demo-cta-install"
							class="regular-text"
							name="<?php echo esc_attr( $demo_opt ); ?>[cta_install_url]"
							value="<?php echo esc_attr( (string) $demo['cta_install_url'] ); ?>"
							placeholder="Opcional: configura aquí la URL de contacto/instalación cuando esté disponible">
						<p class="description">Se muestra junto al botón de planes cuando el visitante alcanza el límite de consultas por sesión.</p>
					</td>
				</tr>
			</table>
		</div>

		<div class="aiea-panel-card">
			<h3>Límites operativos para tiendas comerciales</h3>
			<p class="description">Configura los topes de uso del endpoint público para instalaciones comerciales estándar (cuando el modo demostración pública no está activo). Introduce <strong><code>0</code></strong> en cualquier campo si deseas dejar ese control <strong>sin límite</strong>.</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aiea-limit-session-msgs">Máximo de mensajes por sesión</label></th>
					<td>
						<input
							type="number"
							id="aiea-limit-session-msgs"
							class="small-text"
							min="0"
							max="1000"
							name="<?php echo esc_attr( $opt ); ?>[max_messages_per_session]"
							value="<?php echo (int) $limits['max_messages_per_session']; ?>">
						<p class="description">Contabilizado de forma independiente al historial truncado de la conversación (recomendado: 20; <code>0</code> = sin límite).</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-limit-new-sessions">Máximo de sesiones nuevas por IP por hora</label></th>
					<td>
						<input
							type="number"
							id="aiea-limit-new-sessions"
							class="small-text"
							min="0"
							max="1000"
							name="<?php echo esc_attr( $opt ); ?>[max_new_sessions_per_ip_hour]"
							value="<?php echo (int) $limits['max_new_sessions_per_ip_hour']; ?>">
						<p class="description">Evita que un visitante eluda el límite por conversación descartando su <code>session_id</code> en cada petición (recomendado: 10; <code>0</code> = sin límite).</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-limit-daily-calls">Límite diario de llamadas a OpenAI</label></th>
					<td>
						<input
							type="number"
							id="aiea-limit-daily-calls"
							class="regular-text"
							min="0"
							max="1000000"
							name="<?php echo esc_attr( $opt ); ?>[max_daily_openai_calls]"
							value="<?php echo (int) $limits['max_daily_openai_calls']; ?>">
						<p class="description">Tope global diario de llamadas HTTP a la Responses API de OpenAI (recomendado inicial: 300; <code>0</code> = sin límite).</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-limit-daily-tokens">Límite diario de tokens totales</label></th>
					<td>
						<input
							type="number"
							id="aiea-limit-daily-tokens"
							class="regular-text"
							min="0"
							max="100000000"
							name="<?php echo esc_attr( $opt ); ?>[max_daily_tokens]"
							value="<?php echo (int) $limits['max_daily_tokens']; ?>">
						<p class="description">Tope global diario de tokens (entrada + salida) informados por OpenAI (recomendado inicial: 150000; <code>0</code> = sin límite).</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiea-limit-dup-window">Ventana de detección de mensajes duplicados (segundos)</label></th>
					<td>
						<input
							type="number"
							id="aiea-limit-dup-window"
							class="small-text"
							min="0"
							max="600"
							name="<?php echo esc_attr( $opt ); ?>[duplicate_window_seconds]"
							value="<?php echo (int) $limits['duplicate_window_seconds']; ?>"> s
						<p class="description">Bloquea el reenvío consecutivo de la misma consulta dentro de esta ventana sin afectar respuestas cortas naturales como «sí», «no», «ok» o «gracias» (<code>0</code> = desactivado).</p>
					</td>
				</tr>
			</table>

			<div class="aiea-rate-limits-info">
				<strong>Protecciones base por IP siempre activas en el endpoint público:</strong>
				<ul>
					<li>Bloqueo exclusivo de peticiones simultáneas por IP (<code>flock</code>).</li>
					<li>Cooldown de <strong><?php echo (int) AI_Ecommerce_Agent_API::RATE_LIMIT_COOLDOWN; ?> segundos</strong> desde la finalización de cada respuesta.</li>
					<li>Máximo de <strong><?php echo (int) AI_Ecommerce_Agent_API::RATE_LIMIT_PER_MINUTE; ?> mensajes por minuto</strong> y <strong><?php echo (int) AI_Ecommerce_Agent_API::RATE_LIMIT_PER_HOUR; ?> mensajes por hora</strong> por IP.</li>
					<li>Longitud máxima de mensaje público: <strong><?php echo (int) AI_Ecommerce_Agent_API::PUBLIC_MAX_MESSAGE_LENGTH; ?> caracteres</strong>.</li>
				</ul>
			</div>

			<h4>Estadísticas reales de uso y consumo</h4>
			<p class="description">Datos contabilizados directamente de las respuestas reales de OpenAI (<code>usage.input_tokens</code>, <code>usage.output_tokens</code> y <code>usage.total_tokens</code>). El historial diario conserva automáticamente los últimos <?php echo (int) AI_Ecommerce_Agent_Usage::MAX_DAILY_HISTORY_DAYS; ?> días.</p>

			<table class="widefat striped aiea-stats-table">
				<thead>
					<tr>
						<th>Métrica</th>
						<th>Hoy (<?php echo esc_html( (string) $stats['today_date'] ); ?>)</th>
						<th>Acumulado total</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><strong>Solicitudes al endpoint público</strong></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $today['public_requests'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $total['public_requests'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<td><strong>Llamadas reales a OpenAI</strong> (incluye rondas de herramientas)</td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $today['openai_calls'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $total['openai_calls'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<td><strong>Tokens de entrada (<code>input_tokens</code>)</strong></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $today['input_tokens'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $total['input_tokens'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<td><strong>Tokens de salida (<code>output_tokens</code>)</strong></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $today['output_tokens'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $total['output_tokens'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<td><strong>Tokens totales (<code>total_tokens</code>)</strong></td>
						<td><strong><?php echo esc_html( number_format_i18n( (int) ( $today['total_tokens'] ?? 0 ) ) ); ?></strong></td>
						<td><strong><?php echo esc_html( number_format_i18n( (int) ( $total['total_tokens'] ?? 0 ) ) ); ?></strong></td>
					</tr>
					<tr>
						<td><strong>Llamadas sin bloque <code>usage</code> informado</strong></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $today['unreported_usage_calls'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $total['unreported_usage_calls'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<td><strong>Errores de OpenAI</strong></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $today['openai_errors'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $total['openai_errors'] ?? 0 ) ) ); ?></td>
					</tr>
					<tr>
						<td><strong>Solicitudes rechazadas por límites</strong></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $today['rejected_by_limits'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $total['rejected_by_limits'] ?? 0 ) ) ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	// =========================================================
	// 4. Lectura de opciones generales con valores por defecto
	// =========================================================

	/**
	 * Devuelve los valores por defecto para la opción general `aiea_settings`.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_general_defaults(): array {
		return array(
			'api_key'         => '',
			'model'           => 'gpt-4.1-mini',
			'agent_name'      => 'Asistente de ventas',
			'enabled'         => 0,
			'support_contact' => '',
			'policy_pages'    => array(
				'envios'        => '',
				'devoluciones'  => '',
				'reembolsos'    => '',
				'garantia'      => '',
				'cancelaciones' => '',
				'terminos'      => '',
				'privacidad'    => '',
				'faq'           => '',
				'empresa'       => '',
				'contacto'      => '',
				'pagos'         => '',
			),
		);
	}

	/**
	 * Obtiene la opción general `aiea_settings` combinada con sus valores por defecto.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_options(): array {
		$defaults = self::get_general_defaults();
		$o        = get_option( self::OPTION_NAME, array() );
		$parsed   = wp_parse_args( is_array( $o ) ? $o : array(), $defaults );

		$parsed['policy_pages'] = wp_parse_args(
			is_array( $parsed['policy_pages'] ) ? $parsed['policy_pages'] : array(),
			$defaults['policy_pages']
		);

		return $parsed;
	}
}
