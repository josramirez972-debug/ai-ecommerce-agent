<?php
/**
 * AI Ecommerce Agent — Capa de recuperación de conocimiento del negocio.
 *
 * Gestiona la lectura, validación y consulta estructurada de:
 * - Información comercial del negocio (aiea_business_info).
 * - Cobertura y condiciones de envío (aiea_shipping_coverage + zonas de WooCommerce como fuente complementaria).
 * - Preguntas frecuentes activas (aiea_faq_items).
 * - Horarios de atención y canales de contacto (aiea_contact_settings).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Ecommerce_Agent_Knowledge {

	const OPTION_BUSINESS = 'aiea_business_info';
	const OPTION_SHIPPING = 'aiea_shipping_coverage';
	const OPTION_FAQ      = 'aiea_faq_items';
	const OPTION_CONTACT  = 'aiea_contact_settings';

	/**
	 * Longitud máxima de texto devuelto por una consulta de FAQ para evitar payloads excesivos.
	 */
	const MAX_FAQ_RESPONSE_CHARS = 3500;

	// =========================================================
	// 1. Valores por defecto y lectura segura de opciones
	// =========================================================

	/**
	 * Devuelve los valores por defecto de la información del negocio (vacíos para no inventar datos).
	 *
	 * @return array<string,string>
	 */
	public static function get_business_defaults(): array {
		return [
			'business_name'     => '',
			'short_description' => '',
			'category_sector'   => '',
			'country'           => '',
			'state_region'      => '',
			'city'              => '',
			'address'           => '',
			'email'             => '',
			'claims_email'      => '',
			'phone'             => '',
			'whatsapp'          => '',
			'website_url'       => '',
			'contact_url'       => '',
		];
	}

	/**
	 * Obtiene el correo oficial configurado específicamente para reclamos y quejas.
	 * Valida que tenga formato de correo electrónico y no inventa ni asume correos sin confirmar.
	 *
	 * @return string Dirección de correo válida o cadena vacía si no está configurada.
	 */
	public static function get_claims_email(): string {
		$biz   = self::get_business_info();
		$email = isset( $biz['claims_email'] ) ? trim( (string) $biz['claims_email'] ) : '';

		if ( '' !== $email && is_email( $email ) ) {
			return sanitize_email( $email );
		}

		return '';
	}

	/**
	 * Obtiene la información del negocio guardada en WordPress.
	 *
	 * @return array<string,string>
	 */
	public static function get_business_info(): array {
		$saved = get_option( self::OPTION_BUSINESS, [] );
		$saved = is_array( $saved ) ? $saved : [];

		return wp_parse_args( $saved, self::get_business_defaults() );
	}

	/**
	 * Devuelve los valores por defecto de envíos y cobertura (sin asumir cobertura nacional).
	 *
	 * @return array<string,mixed>
	 */
	public static function get_shipping_defaults(): array {
		return [
			'shipping_mode'           => '',
			'coverage_zones'          => '',
			'countries_served'        => '',
			'regions_served'          => '',
			'cities_served'           => '',
			'excluded_zones'          => '',
			'geographic_restrictions' => '',
			'special_conditions'      => '',
			'shipping_costs_info'     => '',
			'estimated_delivery_time' => '',
			'shipping_policy_url'     => '',
			'use_wc_shipping_zones'   => 1,
		];
	}

	/**
	 * Obtiene la configuración de envíos y cobertura guardada en WordPress.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_shipping_coverage(): array {
		$saved = get_option( self::OPTION_SHIPPING, [] );
		$saved = is_array( $saved ) ? $saved : [];

		return wp_parse_args( $saved, self::get_shipping_defaults() );
	}

	/**
	 * Devuelve todos los ítems de preguntas frecuentes almacenados (activos e inactivos), ordenados por 'order'.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_all_faqs(): array {
		$items = get_option( self::OPTION_FAQ, [] );
		if ( ! is_array( $items ) ) {
			return [];
		}

		$normalized = [];
		foreach ( $items as $idx => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$question = isset( $item['question'] ) ? trim( (string) $item['question'] ) : '';
			$answer   = isset( $item['answer'] ) ? trim( (string) $item['answer'] ) : '';
			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$id = isset( $item['id'] ) && '' !== trim( (string) $item['id'] )
				? sanitize_key( (string) $item['id'] )
				: 'faq_' . ( (int) $idx + 1 );

			$normalized[] = [
				'id'       => $id,
				'question' => $question,
				'answer'   => $answer,
				'active'   => ! empty( $item['active'] ) ? 1 : 0,
				'order'    => isset( $item['order'] ) ? (int) $item['order'] : ( (int) $idx + 1 ),
			];
		}

		usort(
			$normalized,
			function ( array $a, array $b ): int {
				return (int) $a['order'] <=> (int) $b['order'];
			}
		);

		return $normalized;
	}

	/**
	 * Devuelve los valores por defecto de contacto y horarios.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_contact_defaults(): array {
		$general = get_option( 'aiea_settings', [] );
		$legacy_support = is_array( $general ) && ! empty( $general['support_contact'] )
			? sanitize_text_field( (string) $general['support_contact'] )
			: '';

		return [
			'business_days'              => [],
			'opening_time'               => '',
			'closing_time'               => '',
			'special_closures'           => '',
			'timezone'                   => '',
			'preferred_channel'          => '',
			'contact_url'                => '',
			'whatsapp'                   => '',
			'address_location'           => '',
			'human_advisor_instructions' => $legacy_support,
		];
	}

	/**
	 * Obtiene la configuración de contacto y horarios guardada en WordPress.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_contact_settings(): array {
		$saved = get_option( self::OPTION_CONTACT, [] );
		$saved = is_array( $saved ) ? $saved : [];

		$merged = wp_parse_args( $saved, self::get_contact_defaults() );
		if ( ! is_array( $merged['business_days'] ) ) {
			$merged['business_days'] = [];
		}

		return $merged;
	}

	// =========================================================
	// 2. Herramientas de consulta para el agente de IA
	// =========================================================

	/**
	 * Herramienta: consultar_informacion_negocio()
	 *
	 * Devuelve los datos comerciales configurados de la tienda sin inventar campos vacíos.
	 *
	 * @return array<string,mixed>
	 */
	public static function consultar_informacion_negocio(): array {
		$info      = self::get_business_info();
		$available = [];

		$labels = [
			'business_name'     => 'nombre_comercial',
			'short_description' => 'descripcion_breve',
			'category_sector'   => 'categoria_o_sector',
			'country'           => 'pais',
			'state_region'      => 'departamento_o_region',
			'city'              => 'ciudad',
			'address'           => 'direccion_fisica',
			'email'             => 'correo_contacto',
			'phone'             => 'telefono',
			'whatsapp'          => 'whatsapp',
			'website_url'       => 'url_sitio_web',
			'contact_url'       => 'url_pagina_contacto',
		];

		foreach ( $labels as $opt_key => $out_key ) {
			$val = isset( $info[ $opt_key ] ) ? trim( (string) $info[ $opt_key ] ) : '';
			if ( '' !== $val ) {
				$available[ $out_key ] = $val;
			}
		}

		if ( empty( $available ) ) {
			return [
				'exito'              => true,
				'configurado'        => false,
				'codigo'             => 'informacion_negocio_no_configurada',
				'datos_negocio'      => [],
				'instruccion_agente' => 'El administrador aún no ha registrado los datos generales del negocio. Indica que no puedes confirmar ese dato con la información disponible y no inventes dirección, ciudad, teléfono ni descripción.',
			];
		}

		return [
			'exito'              => true,
			'configurado'        => true,
			'codigo'             => 'informacion_negocio_disponible',
			'datos_negocio'      => $available,
			'instruccion_agente' => 'Responde utilizando únicamente los campos presentes en datos_negocio. Si preguntan por un dato que no aparece en datos_negocio, indica que no dispones de confirmación sobre ese dato.',
		];
	}

	/**
	 * Herramienta: consultar_cobertura_envios()
	 *
	 * Evalúa la cobertura geográfica, zonas atendidas, zonas excluidas, costos y tiempos configurados.
	 * Nunca interpreta una lista parcial como cobertura nacional.
	 *
	 * @param string $ubicacion_consultada Ciudad, municipio, departamento o país consultado por el cliente (opcional).
	 * @return array<string,mixed>
	 */
	public static function consultar_cobertura_envios( string $ubicacion_consultada = '' ): array {
		$cfg   = self::get_shipping_coverage();
		$query = trim( sanitize_text_field( $ubicacion_consultada ) );

		$mode          = trim( (string) $cfg['shipping_mode'] );
		$zones_raw     = trim( (string) $cfg['coverage_zones'] );
		$countries_raw = trim( (string) $cfg['countries_served'] );
		$regions_raw   = trim( (string) $cfg['regions_served'] );
		$cities_raw    = trim( (string) $cfg['cities_served'] );
		$excluded_raw  = trim( (string) $cfg['excluded_zones'] );
		$restrict_raw  = trim( (string) $cfg['geographic_restrictions'] );
		$special_raw   = trim( (string) $cfg['special_conditions'] );
		$costs_raw     = trim( (string) $cfg['shipping_costs_info'] );
		$time_raw      = trim( (string) $cfg['estimated_delivery_time'] );
		$policy_url    = trim( (string) $cfg['shipping_policy_url'] );

		$countries_list = self::parse_location_list( $countries_raw );
		$regions_list   = self::parse_location_list( $regions_raw );
		$cities_list    = self::parse_location_list( $cities_raw );
		$zones_list     = self::parse_location_list( $zones_raw );
		$excluded_list  = self::parse_location_list( $excluded_raw );

		$wc_zones = [];
		if ( ! empty( $cfg['use_wc_shipping_zones'] ) ) {
			$wc_zones = self::get_woocommerce_shipping_zones_summary();
		}

		$policy_info = self::inspect_official_shipping_policy();
		if ( '' === $policy_url && ! empty( $policy_info['url'] ) ) {
			$policy_url = (string) $policy_info['url'];
		}

		$has_manual_coverage = (
			'' !== $mode
			|| ! empty( $countries_list )
			|| ! empty( $regions_list )
			|| ! empty( $cities_list )
			|| ! empty( $zones_list )
			|| ! empty( $excluded_list )
			|| '' !== $restrict_raw
			|| '' !== $costs_raw
			|| '' !== $time_raw
		);

		// 4. Fuente de cobertura no disponible: si ninguna fuente tiene datos de cobertura.
		if ( ! $has_manual_coverage && empty( $wc_zones ) && empty( $policy_info['cities'] ) ) {
			return [
				'exito'                           => true,
				'cobertura_definida'              => false,
				'estado_ubicacion'                => 'fuente_cobertura_no_disponible',
				'ubicacion_consultada'            => $query,
				'codigo'                          => 'fuente_cobertura_no_disponible',
				'evaluacion_ubicacion_consultada' => '' !== $query ? [
					'ubicacion'                       => $query,
					'estado'                          => 'fuente_cobertura_no_disponible',
					'tiene_restricciones_especificas' => false,
					'detalle'                         => 'No hay ninguna fuente de cobertura de envíos disponible o configurada en la tienda.',
				] : null,
				'mensaje'                         => 'La fuente de cobertura de envíos no está disponible en este momento.',
				'instruccion_agente'              => 'Indica que la información sobre cobertura, costos y tiempos de envío no está disponible y recomienda contactar con la tienda antes de realizar el pedido. NO inventes ni deduzcas cobertura.',
			];
		}

		// Regla Fase B: Nunca deducir cobertura nacional solo por el modo "nacional" si la lista de ciudades, regiones o zonas está limitada.
		$has_limited_location_list   = ( ! empty( $cities_list ) || ! empty( $regions_list ) || ! empty( $zones_list ) || ! empty( $policy_info['cities'] ) );
		$national_coverage_confirmed = ( 'nacional' === $mode && ! $has_limited_location_list );
		$has_restrictions            = ( '' !== $restrict_raw );

		// Validación cruzada de costos de envío (Fase A): verificar si el costo configurado contradice la política oficial o WooCommerce.
		$cost_validation = self::validate_shipping_costs_source( $costs_raw, $wc_zones, $policy_info );

		// Validación cruzada de tiempos de entrega (Fase A): verificar si el tiempo configurado contradice la política oficial.
		$time_validation = self::validate_delivery_time_source( $time_raw, $policy_info );

		// Calcular qué ciudades están confirmadas sin contradicción y cuáles presentan discrepancia entre el plugin y la página oficial.
		$coverage_comparison = self::compare_coverage_cities( $cities_list, $zones_list, $policy_info['cities'] );

		$location_evaluation = null;
		if ( '' !== $query ) {
			$location_evaluation = self::evaluate_shipping_location(
				$query,
				$mode,
				$countries_list,
				$regions_list,
				$cities_list,
				$zones_list,
				$excluded_list,
				$restrict_raw,
				$policy_info
			);
		}

		return [
			'exito'                                       => true,
			'cobertura_definida'                          => true,
			'modalidad_envio_configurada'                 => '' !== $mode ? $mode : 'no_especificada',
			'cobertura_nacional_confirmada'               => $national_coverage_confirmed,
			'cobertura_limitada_a_ubicaciones_listadas'   => $has_limited_location_list,
			'nota_alcance_geografico'                     => $has_limited_location_list
				? 'Aunque la modalidad esté marcada como "' . $mode . '", la tienda tiene una lista limitada de ciudades y departamentos específicos. PROHIBIDO deducir o afirmar que hay cobertura nacional en toda Colombia.'
				: 'Cobertura según modalidad configurada.',
			'paises_atendidos'                            => $countries_list,
			'departamentos_regiones_atendidos'            => $regions_list,
			'ciudades_atendidas_confirmadas'              => $coverage_comparison['confirmed_cities'],
			'ciudades_con_contradiccion_entre_fuentes'    => $coverage_comparison['contradictory_cities'],
			'zonas_excluidas_explicitamente'              => $excluded_list,
			'tiene_restricciones_geograficas'             => $has_restrictions,
			'restricciones_geograficas'                   => $has_restrictions ? $restrict_raw : null,
			'condiciones_especiales_entrega'              => '' !== $special_raw ? $special_raw : null,
			'transportadoras_mencionadas_en_politica'     => $policy_info['carriers'],
			'validacion_costos_envio'                     => $cost_validation,
			'costos_envio_confirmados'                    => $cost_validation['confirmado'],
			'costos_envio_configurados'                   => $cost_validation['valor_confirmado'],
			'validacion_tiempos_entrega'                  => $time_validation,
			'tiempos_entrega_confirmados'                 => $time_validation['confirmado'],
			'tiempo_estimado_entrega'                     => $time_validation['valor_confirmado'],
			'url_politica_envios'                         => $policy_url,
			'evaluacion_ubicacion_consultada'             => $location_evaluation,
			'zonas_operativas_woocommerce_comp'           => $wc_zones,
			'instruccion_agente'                          => 'REGLAS OBLIGATORIAS DE PRECISIÓN COMERCIAL: '
				. '(1) Respeta estrictamente evaluacion_ubicacion_consultada.estado: '
				. 'si es "ciudad_atendida_confirmada", confirma de forma clara y directa que sí se realizan envíos a esa ubicación; si tiene_restricciones_especificas es false, PROHIBIDO mencionar posibles restricciones geográficas y NO pidas contactar a la tienda para confirmar cobertura de esa ciudad. '
				. 'Si es "ciudad_excluida_explicitamente", indica directamente que esa ubicación está excluida de cobertura. '
				. 'Si es "cobertura_no_especificada", indica que la cobertura para esa ubicación no está especificada (distinguiéndolo de una prohibición), menciona las ciudades confirmadas (' . implode( ', ', $coverage_comparison['confirmed_cities'] ) . ') y sugiere consultar con la tienda. '
				. 'Si es "fuentes_contradictorias", explica que existe discrepancia entre la configuración y la política de envíos para esa ciudad y recomienda confirmarlo con la tienda. '
				. '(2) COSTOS DE ENVÍO: Si costos_envio_confirmados es false (estado "' . $cost_validation['estado'] . '"), PROHIBIDO presentar 12.000 COP o cualquier tarifa fija como hecho confirmado; explica que según la política oficial el costo varía por destino, peso y volumen y se muestra en el checkout antes de pagar. '
				. '(3) TIEMPOS DE ENTREGA: Si tiempos_entrega_confirmados es false (estado "' . $time_validation['estado'] . '"), PROHIBIDO presentar "1 a 3 días hábiles" o "2 a 3 días hábiles" como plazo confirmado; indica que la política oficial contempla 24 horas de preparación de referencia y que el plazo de entrega definitivo está sujeto a confirmación logística en el checkout.',
		];
	}

	/**
	 * Herramienta: consultar_faq()
	 *
	 * Busca y devuelve únicamente preguntas frecuentes ACTIVAS (`active === 1`).
	 * Las preguntas desactivadas se ignoran por completo.
	 *
	 * @param string $consulta Término o pregunta del cliente para filtrar las FAQ relevantes.
	 * @return array<string,mixed>
	 */
	public static function consultar_faq( string $consulta = '' ): array {
		$all_faqs    = self::get_all_faqs();
		$active_faqs = array_values(
			array_filter(
				$all_faqs,
				function ( array $item ): bool {
					return ! empty( $item['active'] );
				}
			)
		);

		$query = trim( sanitize_text_field( $consulta ) );

		if ( empty( $active_faqs ) ) {
			return [
				'exito'               => true,
				'hay_faq_activas'     => false,
				'total_activas'       => 0,
				'preguntas_frecuentes'=> [],
				'instruccion_agente'  => 'No hay preguntas frecuentes activas configuradas. Consulta las políticas oficiales o la información del negocio según corresponda y no inventes respuestas.',
			];
		}

		$keywords = self::extract_keywords( $query );
		$matched  = [];

		if ( ! empty( $keywords ) ) {
			foreach ( $active_faqs as $faq ) {
				$haystack = self::normalize_text( $faq['question'] . ' ' . $faq['answer'] );
				$score    = 0;
				foreach ( $keywords as $kw ) {
					if ( false !== strpos( $haystack, $kw ) ) {
						$score++;
					}
				}
				if ( $score > 0 ) {
					$matched[] = [
						'score' => $score,
						'faq'   => $faq,
					];
				}
			}

			usort(
				$matched,
				function ( array $a, array $b ): int {
					if ( $b['score'] !== $a['score'] ) {
						return $b['score'] <=> $a['score'];
					}
					return (int) $a['faq']['order'] <=> (int) $b['faq']['order'];
				}
			);
		}

		$selected_list = ! empty( $matched )
			? array_map(
				function ( array $entry ): array {
					return $entry['faq'];
				},
				$matched
			)
			: $active_faqs;

		$shipping_policy = self::inspect_official_shipping_policy();

		// Limitar el tamaño total devuelto para no enviar payloads innecesariamente grandes.
		$output_faqs = [];
		$used_chars  = 0;
		foreach ( $selected_list as $faq ) {
			$entry_text = $faq['question'] . ' ' . $faq['answer'];
			$len        = function_exists( 'mb_strlen' ) ? mb_strlen( $entry_text, 'UTF-8' ) : strlen( $entry_text );
			if ( $used_chars + $len > self::MAX_FAQ_RESPONSE_CHARS && ! empty( $output_faqs ) ) {
				break;
			}

			$norm_faq = self::normalize_text( $entry_text );
			$faq_item = [
				'id'        => $faq['id'],
				'pregunta'  => $faq['question'],
				'respuesta' => $faq['answer'],
				'orden'     => (int) $faq['order'],
			];

			// Si la FAQ menciona días de entrega pero la política oficial de envíos prohíbe publicar plazos en días sin confirmación logística, marcar la contradicción.
			if (
				! empty( $shipping_policy['forbids_unverified_time'] )
				&& ( false !== strpos( $norm_faq, 'envio' ) || false !== strpos( $norm_faq, 'tarda' ) || false !== strpos( $norm_faq, 'entrega' ) )
				&& false !== strpos( $norm_faq, 'dias' )
			) {
				$faq_item['contradice_politica_oficial_envios'] = true;
				$faq_item['advertencia_prelacion']              = 'ATENCIÓN: Esta respuesta de FAQ contradice la Política oficial de envíos y entregas (que prohíbe publicar plazos como "1 a 3 días" u otros rangos en días hábiles hasta verificarlos con la operación logística). Prevalece la política oficial: NO presentes este plazo de días hábiles como confirmado.';
			}

			$output_faqs[] = $faq_item;
			$used_chars   += $len;
		}

		return [
			'exito'                   => true,
			'hay_faq_activas'         => true,
			'total_activas'           => count( $active_faqs ),
			'coincidencia_especifica' => ! empty( $matched ),
			'preguntas_frecuentes'    => $output_faqs,
			'instruccion_agente'      => 'Utiliza estas FAQ activas como apoyo para responder al cliente, pero recuerda que las FAQ NUNCA anulan las restricciones explícitas de las políticas oficiales. Si un ítem tiene contradice_politica_oficial_envios=true, no presentes ese plazo como hecho confirmado.',
		];
	}

	/**
	 * Herramienta: consultar_horarios_contacto()
	 *
	 * Devuelve los días y horarios de atención, zona horaria y canales de contacto configurados.
	 * Solo calcula si el negocio está abierto ahora mismo cuando existen días, hora de apertura,
	 * hora de cierre y zona horaria válida.
	 *
	 * @return array<string,mixed>
	 */
	public static function consultar_horarios_contacto(): array {
		$contact  = self::get_contact_settings();
		$business = self::get_business_info();

		$days          = is_array( $contact['business_days'] ) ? array_values( array_filter( $contact['business_days'], 'strlen' ) ) : [];
		$opening_time  = trim( (string) $contact['opening_time'] );
		$closing_time  = trim( (string) $contact['closing_time'] );
		$closures      = trim( (string) $contact['special_closures'] );
		$timezone_str  = trim( (string) $contact['timezone'] );
		$pref_channel  = trim( (string) $contact['preferred_channel'] );
		$contact_url   = '' !== trim( (string) $contact['contact_url'] ) ? trim( (string) $contact['contact_url'] ) : trim( (string) $business['contact_url'] );
		$whatsapp      = '' !== trim( (string) $contact['whatsapp'] ) ? trim( (string) $contact['whatsapp'] ) : trim( (string) $business['whatsapp'] );
		$address       = '' !== trim( (string) $contact['address_location'] ) ? trim( (string) $contact['address_location'] ) : trim( (string) $business['address'] );
		$advisor_notes = trim( (string) $contact['human_advisor_instructions'] );
		$email         = trim( (string) $business['email'] );
		$phone         = trim( (string) $business['phone'] );

		$schedule_complete = (
			! empty( $days )
			&& self::is_valid_time_24h( $opening_time )
			&& self::is_valid_time_24h( $closing_time )
			&& self::is_valid_timezone( $timezone_str )
		);

		$current_status = [
			'puede_confirmar_estado_actual' => false,
			'abierto_ahora'                 => null,
			'hora_local_negocio'            => '',
			'motivo'                        => 'No se han configurado horarios completos (días, hora de apertura, hora de cierre y zona horaria válida).',
		];

		if ( $schedule_complete ) {
			$current_status = self::evaluate_open_now( $days, $opening_time, $closing_time, $timezone_str, $closures );
		}

		$canales = array_filter(
			[
				'canal_preferido'            => $pref_channel,
				'whatsapp'                   => $whatsapp,
				'telefono'                   => $phone,
				'correo'                     => $email,
				'correo_reclamaciones'       => self::get_claims_email(),
				'url_contacto'               => $contact_url,
				'direccion_ubicacion'        => $address,
				'indicaciones_asesor_humano' => $advisor_notes,
			],
			'strlen'
		);

		return [
			'exito'                => true,
			'horarios_confirmados' => $schedule_complete,
			'horario_configurado'  => [
				'dias_atencion'               => $days,
				'hora_apertura'               => $opening_time,
				'hora_cierre'                 => $closing_time,
				'cierres_especiales_no_lab'   => $closures,
				'zona_horaria'                => $timezone_str,
			],
			'estado_actual'        => $current_status,
			'canales_contacto'     => $canales,
			'instruccion_agente'   => 'Reglas estrictas: (1) Si horarios_confirmados es false o estado_actual.puede_confirmar_estado_actual es false, NUNCA afirmes que el negocio está abierto o cerrado en este momento ni conviertas horarios incompletos en horarios confirmados. (2) Facilita únicamente los canales que aparezcan en canales_contacto sin inventar números, correos ni enlaces.',
		];
	}

	// =========================================================
	// 3. Métodos auxiliares privados
	// =========================================================

	/**
	 * Convierte un texto separado por comas, puntos y coma o saltos de línea en una lista limpia de ubicaciones.
	 *
	 * @param string $raw Texto bruto.
	 * @return string[]
	 */
	private static function parse_location_list( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return [];
		}

		$parts = preg_split( '/[\r\n,;]+/u', $raw );
		$clean = [];
		if ( is_array( $parts ) ) {
			foreach ( $parts as $part ) {
				$item = trim( $part );
				if ( '' !== $item ) {
					$clean[] = $item;
				}
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Inspecciona la página oficial de Política de envíos en WordPress para cruzar cobertura, costos y tiempos.
	 *
	 * @return array<string,mixed>
	 */
	private static function inspect_official_shipping_policy(): array {
		$default = [
			'verified'                => false,
			'page_id'                 => 0,
			'title'                   => '',
			'url'                     => '',
			'cities'                  => [],
			'carriers'                => [],
			'forbids_fixed_cost'      => false,
			'forbids_unverified_time' => false,
			'prep_time_note'          => '',
			'cost_policy_note'        => '',
		];

		if ( ! class_exists( 'AI_Ecommerce_Agent_Policies' ) || ! method_exists( 'AI_Ecommerce_Agent_Policies', 'consultar_politica' ) ) {
			return $default;
		}

		$res = AI_Ecommerce_Agent_Policies::consultar_politica( 'envios', '' );
		if ( empty( $res['verificada'] ) || empty( $res['contenido_oficial'] ) ) {
			return $default;
		}

		$content      = (string) $res['contenido_oficial'];
		$norm_content = self::normalize_text( $content );

		$cities   = [];
		$carriers = [];

		if ( preg_match( '/cobertura\s*:\s*([^\r\n]+)/iu', $content, $cov_match ) ) {
			$cov_line = trim( (string) $cov_match[1] );
			if ( preg_match( '/^(.*?)\.\s*transportadoras\s*:\s*(.+)$/iu', $cov_line, $sub ) ) {
				$cities   = self::parse_location_list( trim( (string) $sub[1], ". \t" ) );
				$carriers = self::parse_location_list( trim( (string) $sub[2], ". \t" ) );
			} else {
				$cities = self::parse_location_list( trim( $cov_line, ". \t" ) );
			}
		}

		if ( empty( $carriers ) && preg_match( '/transportadoras\s*:\s*([^\r\n.]+)/iu', $content, $car_match ) ) {
			$carriers = self::parse_location_list( trim( (string) $car_match[1], ". \t" ) );
		}

		$forbids_fixed_cost = (
			false !== strpos( $norm_content, 'no anunciar envio gratis ni tarifa fija' )
			|| ( false !== strpos( $norm_content, 'puede variar por destino' ) && false !== strpos( $norm_content, 'antes de finalizar el pago' ) )
		);

		$forbids_unverified_time = (
			false !== strpos( $norm_content, 'no publicar 1 a 3 dias' )
			|| false !== strpos( $norm_content, 'ni otro plazo hasta verificarlo' )
		);

		$prep_note = '';
		if ( preg_match( '/(tiempo de preparaci[oó]n\s*:[^\r\n]+)/iu', $content, $prep_m ) ) {
			$prep_note = trim( (string) $prep_m[1] );
		}

		$cost_note = 'El costo de envío se muestra antes de finalizar el pago en el checkout y puede variar según destino, peso, volumen y promociones vigentes.';

		return [
			'verified'                => true,
			'page_id'                 => (int) ( $res['fuente_oficial']['page_id'] ?? 0 ),
			'title'                   => (string) ( $res['fuente_oficial']['titulo'] ?? '' ),
			'url'                     => (string) ( $res['fuente_oficial']['url'] ?? '' ),
			'cities'                  => $cities,
			'carriers'                => $carriers,
			'forbids_fixed_cost'      => $forbids_fixed_cost,
			'forbids_unverified_time' => $forbids_unverified_time,
			'prep_time_note'          => $prep_note,
			'cost_policy_note'        => $cost_note,
		];
	}

	/**
	 * Valida si el costo de envío configurado está respaldado o contradice la política oficial y WooCommerce.
	 *
	 * @param string              $costs_raw   Texto de costos en la configuración del plugin.
	 * @param array               $wc_zones    Zonas activas en WooCommerce.
	 * @param array<string,mixed> $policy_info Datos extraídos de la política oficial de envíos.
	 * @return array<string,mixed>
	 */
	private static function validate_shipping_costs_source( string $costs_raw, array $wc_zones, array $policy_info ): array {
		if ( '' !== $costs_raw && ! empty( $policy_info['forbids_fixed_cost'] ) && empty( $wc_zones ) ) {
			return [
				'confirmado'        => false,
				'estado'            => 'fuentes_contradictorias',
				'valor_confirmado'  => null,
				'criterio_aplicable'=> $policy_info['cost_policy_note'],
				'detalle'           => sprintf(
					'Existe contradicción entre el ajuste interno del plugin ("%s") y la Política oficial de envíos ("%s"), la cual establece que el costo varía por destino, peso y volumen, se informa en el checkout antes del pago y prohíbe anunciar una tarifa fija o envío gratis salvo que esté activo y visible en WooCommerce (actualmente hay 0 tarifas activas en WooCommerce). Por tanto, NO debe presentarse 12.000 COP ni otra tarifa fija como hecho confirmado.',
					$costs_raw,
					$policy_info['title']
				),
			];
		}

		if ( '' !== $costs_raw ) {
			return [
				'confirmado'       => true,
				'estado'           => 'confirmado_en_configuracion',
				'valor_confirmado' => $costs_raw,
				'detalle'          => $costs_raw,
			];
		}

		return [
			'confirmado'       => false,
			'estado'           => 'no_disponible',
			'valor_confirmado' => null,
			'detalle'          => ! empty( $policy_info['cost_policy_note'] )
				? $policy_info['cost_policy_note']
				: 'No hay costos de envío fijos confirmados; el costo aplicable se calcula en el checkout antes del pago.',
		];
	}

	/**
	 * Valida si el tiempo estimado de entrega configurado está respaldado o contradice la política oficial de envíos.
	 *
	 * @param string              $time_raw    Texto de tiempos en la configuración del plugin.
	 * @param array<string,mixed> $policy_info Datos extraídos de la política oficial de envíos.
	 * @return array<string,mixed>
	 */
	private static function validate_delivery_time_source( string $time_raw, array $policy_info ): array {
		if ( '' !== $time_raw && ! empty( $policy_info['forbids_unverified_time'] ) ) {
			return [
				'confirmado'                 => false,
				'estado'                     => 'fuentes_contradictorias',
				'valor_confirmado'           => null,
				'referencia_politica_oficial'=> $policy_info['prep_time_note'],
				'detalle'                    => sprintf(
					'Existe contradicción entre el ajuste del plugin ("%s") y la Política oficial de envíos ("%s"), la cual ordena expresamente no publicar "1 a 3 días" ni otro plazo hasta verificarlo con la operación logística (indicando tiempo de preparación de referencia de 24 horas y que la fecha o rango válido es el que aparezca en el checkout o confirmación de compra). NO debe presentarse "1 a 3 días hábiles" como tiempo confirmado.',
					$time_raw,
					$policy_info['title']
				),
			];
		}

		if ( '' !== $time_raw ) {
			return [
				'confirmado'       => true,
				'estado'           => 'confirmado_en_configuracion',
				'valor_confirmado' => $time_raw,
				'detalle'          => $time_raw,
			];
		}

		return [
			'confirmado'       => false,
			'estado'           => 'no_disponible',
			'valor_confirmado' => null,
			'detalle'          => 'No hay un tiempo de entrega estimado confirmado.',
		];
	}

	/**
	 * Compara dos nombres de ubicación de forma tolerante a tildes, mayúsculas y erratas de 1 letra al final (ej. "Medelli" vs "Medellín").
	 *
	 * @param string $loc_a Primera ubicación.
	 * @param string $loc_b Segunda ubicación.
	 * @return bool
	 */
	private static function locations_match( string $loc_a, string $loc_b ): bool {
		$a = self::normalize_location_token( $loc_a );
		$b = self::normalize_location_token( $loc_b );

		if ( '' === $a || '' === $b ) {
			return false;
		}

		if ( $a === $b ) {
			return true;
		}

		// Coincidencia de palabra exacta (p. ej. "valle" en "valle del cauca" o "bogota" en "bogota d c").
		if ( strlen( $a ) >= 4 && strlen( $b ) >= 4 ) {
			if ( preg_match( '/\b' . preg_quote( $a, '/' ) . '\b/u', $b ) || preg_match( '/\b' . preg_quote( $b, '/' ) . '\b/u', $a ) ) {
				return true;
			}
		}

		// Tolerancia para errata tipográfica de truncamiento de 1 carácter en nombres largos (ej. "medelli" vs "medellin").
		$min_len = min( strlen( $a ), strlen( $b ) );
		$max_len = max( strlen( $a ), strlen( $b ) );
		if ( $min_len >= 6 && ( $max_len - $min_len ) === 1 ) {
			if ( 0 === strpos( $a, $b ) || 0 === strpos( $b, $a ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normaliza un nombre de ubicación eliminando palabras accesorias.
	 *
	 * @param string $raw Ubicación en bruto.
	 * @return string
	 */
	private static function normalize_location_token( string $raw ): string {
		$norm = self::normalize_text( $raw );
		$norm = preg_replace( '/\b(unicamente|solo|solamente|departamento|municipio|ciudad|de|del|el|la|los|las|d c|dc)\b/u', ' ', $norm );

		return trim( (string) preg_replace( '/\s+/', ' ', (string) $norm ) );
	}

	/**
	 * Compara las ciudades configuradas en el plugin frente a las listadas en la página oficial de envíos.
	 *
	 * @param string[] $plugin_cities Ciudades en aiea_shipping_coverage.
	 * @param string[] $plugin_zones  Zonas en aiea_shipping_coverage.
	 * @param string[] $policy_cities Ciudades en la página oficial de envíos.
	 * @return array{confirmed_cities:string[],contradictory_cities:string[]}
	 */
	private static function compare_coverage_cities( array $plugin_cities, array $plugin_zones, array $policy_cities ): array {
		$plugin_all = array_values( array_unique( array_merge( $plugin_cities, $plugin_zones ) ) );

		if ( empty( $policy_cities ) ) {
			return [
				'confirmed_cities'     => $plugin_all,
				'contradictory_cities' => [],
			];
		}

		if ( empty( $plugin_all ) ) {
			return [
				'confirmed_cities'     => $policy_cities,
				'contradictory_cities' => [],
			];
		}

		$confirmed     = [];
		$contradictory = [];

		foreach ( $plugin_all as $p_city ) {
			$matched_in_policy = false;
			foreach ( $policy_cities as $pol_city ) {
				if ( self::locations_match( $p_city, $pol_city ) ) {
					$matched_in_policy = true;
					break;
				}
			}
			if ( $matched_in_policy ) {
				$confirmed[] = $p_city;
			} else {
				$contradictory[] = $p_city;
			}
		}

		foreach ( $policy_cities as $pol_city ) {
			$matched_in_plugin = false;
			foreach ( $plugin_all as $p_city ) {
				if ( self::locations_match( $pol_city, $p_city ) ) {
					$matched_in_plugin = true;
					break;
				}
			}
			if ( ! $matched_in_plugin ) {
				$contradictory[] = $pol_city;
			}
		}

		return [
			'confirmed_cities'     => array_values( array_unique( $confirmed ) ),
			'contradictory_cities' => array_values( array_unique( $contradictory ) ),
		];
	}

	/**
	 * Evalúa una ubicación consultada clasificándola estrictamente en uno de los 5 estados de Fase B:
	 * 1. ciudad_atendida_confirmada
	 * 2. ciudad_excluida_explicitamente
	 * 3. cobertura_no_especificada
	 * 4. fuente_cobertura_no_disponible
	 * 5. fuentes_contradictorias
	 *
	 * @param string              $query        Ubicación consultada.
	 * @param string              $mode         Modalidad de envío configurada.
	 * @param string[]            $countries    Países atendidos.
	 * @param string[]            $regions      Departamentos/regiones atendidos.
	 * @param string[]            $cities       Ciudades/municipios atendidos.
	 * @param string[]            $zones        Zonas de cobertura.
	 * @param string[]            $excluded     Zonas excluidas.
	 * @param string              $restrict_raw Restricciones geográficas configuradas.
	 * @param array<string,mixed> $policy_info  Información de la política oficial de envíos.
	 * @return array<string,mixed>
	 */
	private static function evaluate_shipping_location(
		string $query,
		string $mode,
		array $countries,
		array $regions,
		array $cities,
		array $zones,
		array $excluded,
		string $restrict_raw = '',
		array $policy_info = []
	): array {
		$has_restrictions = ( '' !== trim( $restrict_raw ) );
		$policy_cities    = isset( $policy_info['cities'] ) && is_array( $policy_info['cities'] ) ? $policy_info['cities'] : [];
		$policy_carriers  = isset( $policy_info['carriers'] ) && is_array( $policy_info['carriers'] ) ? $policy_info['carriers'] : [];

		$matched_excluded = null;
		foreach ( $excluded as $ex ) {
			if ( self::locations_match( $ex, $query ) ) {
				$matched_excluded = $ex;
				break;
			}
		}

		$matched_plugin_city = null;
		foreach ( array_merge( $cities, $zones ) as $c ) {
			if ( self::locations_match( $c, $query ) ) {
				$matched_plugin_city = $c;
				break;
			}
		}

		$matched_plugin_region = null;
		foreach ( $regions as $r ) {
			if ( self::locations_match( $r, $query ) ) {
				$matched_plugin_region = $r;
				break;
			}
		}

		$matched_policy_city = null;
		foreach ( $policy_cities as $pc ) {
			if ( self::locations_match( $pc, $query ) ) {
				$matched_policy_city = $pc;
				break;
			}
		}

		// Caso 5a: Aparece simultáneamente en zonas excluidas y en ciudades atendidas.
		if ( null !== $matched_excluded && ( null !== $matched_plugin_city || null !== $matched_plugin_region || null !== $matched_policy_city ) ) {
			return [
				'ubicacion'                       => $query,
				'estado'                          => 'fuentes_contradictorias',
				'tiene_restricciones_especificas' => $has_restrictions,
				'detalle'                         => sprintf(
					'Fuentes contradictorias para "%s": figura tanto en zonas excluidas (%s) como en ubicaciones atendidas.',
					$query,
					$matched_excluded
				),
			];
		}

		// Caso 2: Ciudad o zona excluida explícitamente.
		if ( null !== $matched_excluded ) {
			return [
				'ubicacion'                       => $query,
				'estado'                          => 'ciudad_excluida_explicitamente',
				'tiene_restricciones_especificas' => $has_restrictions,
				'detalle'                         => sprintf(
					'La ubicación "%s" está explícitamente excluida de la cobertura de envíos (%s). No se realizan envíos a esta zona.',
					$query,
					$matched_excluded
				),
			];
		}

		// Caso 5b: Discrepancia entre la lista de ciudades del plugin y la lista de ciudades de la página oficial de Política de envíos (ej. Bucaramanga o Barranquilla).
		if ( ! empty( $cities ) && ! empty( $policy_cities ) ) {
			if ( ( null !== $matched_policy_city && null === $matched_plugin_city && null === $matched_plugin_region )
				|| ( null !== $matched_plugin_city && null === $matched_policy_city )
			) {
				return [
					'ubicacion'                       => $query,
					'estado'                          => 'fuentes_contradictorias',
					'en_configuracion_plugin'         => ( null !== $matched_plugin_city ),
					'en_politica_oficial_envios'      => ( null !== $matched_policy_city ),
					'tiene_restricciones_especificas' => $has_restrictions,
					'detalle'                         => sprintf(
						'Fuentes contradictorias para "%s": la Política oficial de envíos lista (%s), mientras que la configuración del plugin limita las ciudades atendidas a (%s). Recomienda confirmar con la tienda antes de realizar el pedido.',
						$query,
						implode( ', ', $policy_cities ),
						implode( ', ', $cities )
					),
				];
			}
		}

		// Caso 1: Ciudad o región atendida confirmada.
		if ( null !== $matched_plugin_city || null !== $matched_plugin_region || ( empty( $cities ) && null !== $matched_policy_city ) ) {
			$matched_label = $matched_plugin_city ?? ( $matched_plugin_region ?? $matched_policy_city );
			return [
				'ubicacion'                       => $query,
				'estado'                          => 'ciudad_atendida_confirmada',
				'coincidencia_configurada'        => $matched_label,
				'tiene_restricciones_especificas' => $has_restrictions,
				'restricciones_geograficas'       => $has_restrictions ? $restrict_raw : null,
				'transportadoras_referencia'      => $policy_carriers,
				'detalle'                         => $has_restrictions
					? sprintf( 'La ubicación "%s" es una ciudad/región atendida confirmada. Restricciones geográficas aplicables: %s.', $query, $restrict_raw )
					: sprintf( 'La ubicación "%s" es una ciudad/región atendida confirmada y NO existen restricciones geográficas específicas configuradas. Responde de forma clara y directa confirmando que sí se realizan envíos a "%s".', $query, $query ),
			];
		}

		// Caso 3: Ciudad cuya cobertura no está especificada (o consulta por todo el país cuando existe una lista limitada de ciudades/regiones).
		$specific_locations = array_values( array_unique( array_merge( $cities, $regions, $zones ) ) );
		if ( ! empty( $specific_locations ) || ! empty( $policy_cities ) ) {
			$matched_country = false;
			foreach ( $countries as $country ) {
				if ( self::locations_match( $country, $query ) ) {
					$matched_country = true;
					break;
				}
			}

			if ( $matched_country ) {
				return [
					'ubicacion'                       => $query,
					'estado'                          => 'cobertura_no_especificada',
					'alcance'                         => 'cobertura_limitada_dentro_del_pais',
					'tiene_restricciones_especificas' => $has_restrictions,
					'ciudades_atendidas_confirmadas'  => $cities,
					'regiones_atendidas_confirmadas'  => $regions,
					'zonas_excluidas'                 => $excluded,
					'detalle'                         => sprintf(
						'La tienda opera en Colombia, pero NO tiene cobertura nacional general confirmada para todo el país porque la cobertura está limitada a ciudades y regiones específicas (ciudades confirmadas: %s; departamentos: %s; zonas excluidas: %s). No deduzcas cobertura nacional solo por el modo "%s".',
						implode( ', ', $cities ),
						implode( ', ', $regions ),
						implode( ', ', $excluded ),
						$mode
					),
				];
			}

			return [
				'ubicacion'                       => $query,
				'estado'                          => 'cobertura_no_especificada',
				'tiene_restricciones_especificas' => false,
				'ciudades_atendidas_confirmadas'  => $cities,
				'regiones_atendidas_confirmadas'  => $regions,
				'zonas_excluidas'                 => $excluded,
				'detalle'                         => sprintf(
					'La cobertura de envíos para "%s" no está especificada en la configuración de la tienda (no figura entre las ciudades atendidas confirmadas [%s] ni entre las zonas excluidas [%s]). Distingue entre "información no especificada" y "prohibido": indica qué ciudades sí están confirmadas y sugiere consultar con la tienda para envíos a "%s".',
					$query,
					implode( ', ', $cities ),
					implode( ', ', $excluded ),
					$query
				),
			];
		}

		// Solo si NO hay ninguna lista limitada de ciudades/regiones y la modalidad es nacional.
		if ( 'nacional' === $mode ) {
			return [
				'ubicacion'                       => $query,
				'estado'                          => 'ciudad_atendida_confirmada',
				'tiene_restricciones_especificas' => $has_restrictions,
				'restricciones_geograficas'       => $has_restrictions ? $restrict_raw : null,
				'detalle'                         => $has_restrictions
					? sprintf( 'Cobertura nacional activa para "%s" con las siguientes restricciones: %s.', $query, $restrict_raw )
					: sprintf( 'Cobertura nacional activa para "%s" sin restricciones geográficas específicas configuradas.', $query ),
			];
		}

		return [
			'ubicacion'                       => $query,
			'estado'                          => 'cobertura_no_especificada',
			'tiene_restricciones_especificas' => false,
			'detalle'                         => sprintf( 'La cobertura para "%s" no está especificada en las fuentes de la tienda.', $query ),
		];
	}

	/**
	 * Obtiene un resumen de solo lectura de las zonas de envío reales configuradas en WooCommerce.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_woocommerce_shipping_zones_summary(): array {
		if ( ! class_exists( 'WC_Shipping_Zones' ) || ! method_exists( 'WC_Shipping_Zones', 'get_zones' ) ) {
			return [];
		}

		try {
			$raw_zones = WC_Shipping_Zones::get_zones();
			if ( ! is_array( $raw_zones ) ) {
				return [];
			}

			$summary = [];
			foreach ( $raw_zones as $zone_data ) {
				if ( ! is_array( $zone_data ) ) {
					continue;
				}
				$zone_name = isset( $zone_data['zone_name'] ) ? sanitize_text_field( (string) $zone_data['zone_name'] ) : '';
				$locations = [];
				if ( ! empty( $zone_data['zone_locations'] ) && is_array( $zone_data['zone_locations'] ) ) {
					foreach ( $zone_data['zone_locations'] as $loc ) {
						if ( is_object( $loc ) && ! empty( $loc->code ) ) {
							$locations[] = sanitize_text_field( (string) $loc->code );
						}
					}
				}

				$methods = [];
				if ( ! empty( $zone_data['shipping_methods'] ) && is_array( $zone_data['shipping_methods'] ) ) {
					foreach ( $zone_data['shipping_methods'] as $method ) {
						if ( is_object( $method ) && isset( $method->enabled ) && 'yes' === $method->enabled ) {
							$m_title = method_exists( $method, 'get_title' ) ? (string) $method->get_title() : (string) ( $method->title ?? '' );
							if ( '' !== trim( $m_title ) ) {
								$methods[] = html_entity_decode( wp_strip_all_tags( $m_title ), ENT_QUOTES, 'UTF-8' );
							}
						}
					}
				}

				if ( '' !== $zone_name && ! empty( $methods ) ) {
					$summary[] = [
						'zona'              => $zone_name,
						'codigos_ubicacion' => $locations,
						'metodos_activos'   => $methods,
					];
				}
			}

			return $summary;
		} catch ( Throwable $e ) {
			return [];
		}
	}

	/**
	 * Evalúa si el negocio se encuentra abierto actualmente según su zona horaria y horarios configurados.
	 *
	 * @param string[] $days         Días laborables configurados.
	 * @param string   $opening_time Hora de apertura (HH:MM).
	 * @param string   $closing_time Hora de cierre (HH:MM).
	 * @param string   $timezone_str Identificador de zona horaria.
	 * @param string   $closures     Nota de cierres especiales o festivos.
	 * @return array<string,mixed>
	 */
	private static function evaluate_open_now(
		array $days,
		string $opening_time,
		string $closing_time,
		string $timezone_str,
		string $closures
	): array {
		try {
			$tz  = new DateTimeZone( $timezone_str );
			$now = new DateTimeImmutable( 'now', $tz );
		} catch ( Throwable $e ) {
			return [
				'puede_confirmar_estado_actual' => false,
				'abierto_ahora'                 => null,
				'hora_local_negocio'            => '',
				'motivo'                        => 'La zona horaria configurada no es válida.',
			];
		}

		$day_map = [
			1 => 'lunes',
			2 => 'martes',
			3 => 'miercoles',
			4 => 'jueves',
			5 => 'viernes',
			6 => 'sabado',
			7 => 'domingo',
		];

		$current_day_num = (int) $now->format( 'N' );
		$current_day_key = $day_map[ $current_day_num ] ?? '';
		$current_hm      = $now->format( 'H:i' );
		$current_date    = $now->format( 'Y-m-d' );

		$norm_days = array_map( [ __CLASS__, 'normalize_text' ], $days );
		$is_day_ok = in_array( $current_day_key, $norm_days, true );

		// Si la fecha actual YYYY-MM-DD aparece expresamente en cierres especiales, marcar como cerrado especial.
		if ( '' !== $closures && false !== strpos( $closures, $current_date ) ) {
			return [
				'puede_confirmar_estado_actual' => true,
				'abierto_ahora'                 => false,
				'dia_actual'                    => $current_day_key,
				'hora_local_negocio'            => $current_hm,
				'zona_horaria'                  => $timezone_str,
				'motivo'                        => 'La fecha actual figura en los cierres especiales configurados.',
			];
		}

		$is_time_ok = ( $current_hm >= $opening_time && $current_hm <= $closing_time );
		$is_open    = ( $is_day_ok && $is_time_ok );

		return [
			'puede_confirmar_estado_actual' => true,
			'abierto_ahora'                 => $is_open,
			'dia_actual'                    => $current_day_key,
			'hora_local_negocio'            => $current_hm,
			'zona_horaria'                  => $timezone_str,
			'nota_cierres_especiales'       => $closures,
		];
	}

	/**
	 * Comprueba si una cadena tiene formato de hora 24h HH:MM válido.
	 *
	 * @param string $time Cadena de hora.
	 * @return bool
	 */
	public static function is_valid_time_24h( string $time ): bool {
		return (bool) preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', trim( $time ) );
	}

	/**
	 * Comprueba si una cadena corresponde a una zona horaria PHP válida.
	 *
	 * @param string $tz Zona horaria.
	 * @return bool
	 */
	public static function is_valid_timezone( string $tz ): bool {
		$tz = trim( $tz );
		if ( '' === $tz ) {
			return false;
		}
		return in_array( $tz, timezone_identifiers_list(), true );
	}

	/**
	 * Extrae palabras clave significativas de una consulta para filtrar preguntas frecuentes.
	 *
	 * @param string $query Consulta del usuario.
	 * @return string[]
	 */
	private static function extract_keywords( string $query ): array {
		$norm = self::normalize_text( $query );
		if ( '' === $norm ) {
			return [];
		}

		$stopwords = [
			'como', 'cual', 'cuales', 'cuanto', 'cuantos', 'donde', 'cuando',
			'para', 'por', 'con', 'sin', 'sobre', 'entre', 'hasta', 'desde',
			'los', 'las', 'del', 'una', 'uno', 'unos', 'unas', 'este', 'esta',
			'tienen', 'tiene', 'hay', 'son', 'estan', 'puedo', 'puede', 'que',
		];

		$words = preg_split( '/\s+/', $norm );
		$out   = [];
		if ( is_array( $words ) ) {
			foreach ( $words as $w ) {
				if ( strlen( $w ) >= 3 && ! in_array( $w, $stopwords, true ) ) {
					$out[ $w ] = $w;
				}
			}
		}

		return array_values( $out );
	}

	/**
	 * Normaliza texto a minúsculas sin tildes ni puntuación.
	 *
	 * @param string $text Texto de entrada.
	 * @return string
	 */
	private static function normalize_text( string $text ): string {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = strtr(
			$text,
			[
				'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
				'ü' => 'u', 'ñ' => 'n',
			]
		);
		$text = preg_replace( '/[^a-z0-9\s]/', ' ', $text );

		return trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );
	}
}
