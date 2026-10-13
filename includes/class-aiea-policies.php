<?php
/**
 * AI Ecommerce Agent — Recuperación verificable de políticas oficiales de la tienda.
 *
 * Consulta páginas oficiales publicadas en WordPress a partir de un mapa configurable
 * de IDs o slugs reales, conserva la estructura relevante (títulos, listas, plazos,
 * excepciones y enlaces) y registra internamente la fuente utilizada sin exponer
 * datos sensibles.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Ecommerce_Agent_Policies {

	/**
	 * Nombre del transient donde se registran internamente las fuentes de políticas consultadas.
	 */
	const SOURCE_LOG_TRANSIENT = 'aiea_policy_sources_log';

	/**
	 * Máximo de entradas conservadas en el registro interno de fuentes.
	 */
	const MAX_LOG_ENTRIES = 50;

	/**
	 * Longitud máxima de caracteres de contenido de política entregados al modelo por consulta.
	 */
	const MAX_POLICY_CHARS = 4000;

	/**
	 * Nombre de la opción de WordPress para el mapa de páginas oficiales seleccionadas por ID real.
	 */
	const OPTION_POLICY_PAGES = 'aiea_policy_pages';

	/**
	 * Catálogo de políticas y páginas oficiales soportadas por el agente.
	 *
	 * @return array<string,string> Mapa clave_politica => nombre legible.
	 */
	public static function get_supported_policies(): array {
		return [
			'envios'        => 'Política de envíos',
			'devoluciones'  => 'Política de devoluciones',
			'reembolsos'    => 'Política de reembolsos',
			'garantia'      => 'Política de garantías',
			'cancelaciones' => 'Política de cancelaciones',
			'terminos'      => 'Términos y condiciones',
			'privacidad'    => 'Política de privacidad',
			'faq'           => 'Preguntas frecuentes (página general)',
			'empresa'       => 'Información de la empresa',
			'contacto'      => 'Contacto',
			'pagos'         => 'Política de métodos de pago',
		];
	}

	/**
	 * Obtiene el mapa configurado de política => ID real (o slug compatible) de página de WordPress.
	 *
	 * Lee de `aiea_policy_pages` y mantiene compatibilidad hacia atrás con `aiea_settings['policy_pages']`.
	 * No asume que una página exista por su nombre ni inventa URLs por defecto.
	 *
	 * @return array<string,string>
	 */
	public static function get_policy_pages_map(): array {
		$supported = self::get_supported_policies();
		$defaults  = array_fill_keys( array_keys( $supported ), '' );

		$dedicated = get_option( self::OPTION_POLICY_PAGES, [] );
		$dedicated = is_array( $dedicated ) ? $dedicated : [];

		$options = get_option( 'aiea_settings', [] );
		$legacy  = ( is_array( $options ) && isset( $options['policy_pages'] ) && is_array( $options['policy_pages'] ) )
			? $options['policy_pages']
			: [];

		$map = [];
		foreach ( $supported as $key => $label ) {
			$raw = '';
			if ( isset( $dedicated[ $key ] ) && '' !== trim( (string) $dedicated[ $key ] ) ) {
				$raw = trim( (string) $dedicated[ $key ] );
			} elseif ( isset( $legacy[ $key ] ) && '' !== trim( (string) $legacy[ $key ] ) ) {
				$raw = trim( (string) $legacy[ $key ] );
			}
			$map[ $key ] = self::sanitize_page_reference( $raw );
		}

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'aiea_policy_pages_map', $map );
			if ( is_array( $filtered ) ) {
				foreach ( $supported as $key => $label ) {
					$map[ $key ] = isset( $filtered[ $key ] )
						? self::sanitize_page_reference( (string) $filtered[ $key ] )
						: $defaults[ $key ];
				}
			}
		}

		return $map;
	}

	/**
	 * Alias compatible de get_policy_pages_map() para obtener el mapa de páginas de políticas configuradas.
	 *
	 * @return array<string,string>
	 */
	public static function get_configured_policy_pages(): array {
		return self::get_policy_pages_map();
	}

	/**
	 * Obtiene el canal de atención humana configurado en el plugin (si existe).
	 *
	 * @return string
	 */
	public static function get_support_contact(): string {
		if ( class_exists( 'AI_Ecommerce_Agent_Knowledge' ) ) {
			$contact = AI_Ecommerce_Agent_Knowledge::get_contact_settings();
			$parts   = [];
			if ( ! empty( $contact['human_advisor_instructions'] ) ) {
				$parts[] = trim( (string) $contact['human_advisor_instructions'] );
			}
			if ( ! empty( $contact['whatsapp'] ) ) {
				$parts[] = 'WhatsApp: ' . trim( (string) $contact['whatsapp'] );
			}
			if ( ! empty( $contact['contact_url'] ) ) {
				$parts[] = 'Contacto: ' . trim( (string) $contact['contact_url'] );
			}
			if ( ! empty( $parts ) ) {
				return implode( ' | ', array_unique( $parts ) );
			}
		}

		$options = get_option( 'aiea_settings', [] );
		if ( ! is_array( $options ) || empty( $options['support_contact'] ) ) {
			return '';
		}

		return trim( sanitize_text_field( (string) $options['support_contact'] ) );
	}

	/**
	 * Sanitiza una referencia a página oficial (ID numérico o slug/ruta de página de WordPress).
	 *
	 * @param string $value Valor introducido en configuración.
	 * @return string ID numérico como cadena o slug/ruta limpia.
	 */
	public static function sanitize_page_reference( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		// ID numérico de página de WordPress.
		if ( ctype_digit( $value ) ) {
			return (int) $value > 0 ? (string) (int) $value : '';
		}

		// Si el administrador pegó una URL interna del propio sitio, extraer su ID o ruta relativa.
		if ( preg_match( '#^https?://#i', $value ) ) {
			if ( function_exists( 'url_to_postid' ) ) {
				$post_id = (int) url_to_postid( $value );
				if ( $post_id > 0 ) {
					return (string) $post_id;
				}
			}

			$parsed_path = function_exists( 'wp_parse_url' )
				? (string) wp_parse_url( $value, PHP_URL_PATH )
				: (string) parse_url( $value, PHP_URL_PATH );

			if ( '' !== $parsed_path && function_exists( 'home_url' ) ) {
				$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
				if ( '' !== $home_path && '/' !== $home_path && 0 === strpos( $parsed_path, $home_path ) ) {
					$parsed_path = substr( $parsed_path, strlen( $home_path ) );
				}
			}
			$value = $parsed_path;
		}

		$segments       = array_filter( explode( '/', trim( $value, '/' ) ), 'strlen' );
		$clean_segments = [];
		foreach ( $segments as $segment ) {
			$clean = sanitize_title( $segment );
			if ( '' !== $clean ) {
				$clean_segments[] = $clean;
			}
		}

		return implode( '/', $clean_segments );
	}

	/**
	 * Consulta una política oficial de la tienda y devuelve los fragmentos pertinentes verificables.
	 *
	 * @param string $tipo     Tipo de política ('envios', 'pagos', 'garantia', 'devoluciones', 'privacidad', 'terminos').
	 * @param string $consulta Pregunta o término específico dentro de la política (ej. 'Medellín', 'retracto', 'vitaminas').
	 * @return array Resultado estructurado para OpenAI con estado de verificación, fuente y fragmentos.
	 */
	public static function consultar_politica( string $tipo, string $consulta = '' ): array {
		$supported       = self::get_supported_policies();
		$normalized_type = self::normalize_policy_type( $tipo );
		$consulta        = trim( sanitize_text_field( $consulta ) );
		$canal_soporte   = self::get_support_contact();

		if ( '' === $normalized_type || ! isset( $supported[ $normalized_type ] ) ) {
			return [
				'exito'               => false,
				'verificada'          => false,
				'codigo'              => 'tipo_politica_invalido',
				'tipos_validos'       => array_keys( $supported ),
				'canal_atencion'      => $canal_soporte,
				'mensaje'             => 'El tipo de política solicitado no es válido. Usa uno de: ' . implode( ', ', array_keys( $supported ) ) . '.',
				'instruccion_agente'  => 'No inventes información. Indica que no pudiste verificar la política solicitada.',
			];
		}

		$etiqueta   = $supported[ $normalized_type ];
		$map        = self::get_policy_pages_map();
		$reference  = isset( $map[ $normalized_type ] ) ? $map[ $normalized_type ] : '';

		// 1. Verificar que la política tenga un ID o slug configurado.
		if ( '' === $reference ) {
			self::record_source_usage( $normalized_type, false, 0, '', '', 'politica_no_configurada' );

			return [
				'exito'              => false,
				'verificada'         => false,
				'codigo'             => 'politica_no_configurada',
				'politica'           => $normalized_type,
				'etiqueta'           => $etiqueta,
				'canal_atencion'     => $canal_soporte,
				'mensaje'            => sprintf(
					'La página oficial de "%s" aún no está configurada en el plugin.',
					$etiqueta
				),
				'instruccion_agente' => 'Reconoce explícitamente que no puedes confirmar esta política porque no tienes acceso al documento oficial verificado en este momento. No inventes plazos, condiciones, tarifas ni garantías. Si hay un canal_atencion configurado, ofrécelo al cliente.',
			];
		}

		// 2. Resolver la página publicada en WordPress por ID o slug real.
		$page = self::resolve_published_page( $reference );
		if ( ! $page instanceof WP_Post ) {
			self::record_source_usage( $normalized_type, false, 0, $reference, '', 'pagina_no_encontrada_o_no_publicada' );

			return [
				'exito'              => false,
				'verificada'         => false,
				'codigo'             => 'pagina_no_encontrada',
				'politica'           => $normalized_type,
				'etiqueta'           => $etiqueta,
				'referencia_buscada' => $reference,
				'canal_atencion'     => $canal_soporte,
				'mensaje'            => sprintf(
					'No se encontró una página publicada en WordPress para la política "%s" (referencia: %s).',
					$etiqueta,
					$reference
				),
				'instruccion_agente' => 'Informa al cliente que no puedes verificar esta condición en este momento y abstente de responder con conocimiento general. Si existe canal_atencion, derívalo a dicho canal.',
			];
		}

		// 3. Extraer y limpiar el contenido conservando estructura (títulos, listas, plazos, enlaces).
		$raw_content    = self::extract_raw_page_content( $page );
		$structured_txt = self::clean_and_structure_html( $raw_content );
		$page_title     = html_entity_decode( wp_strip_all_tags( get_the_title( $page ) ), ENT_QUOTES, 'UTF-8' );
		$page_url       = (string) get_permalink( $page );
		$modified       = (string) get_post_modified_time( 'Y-m-d H:i:s', false, $page );

		// 3.1 Filtrar secciones internas pendientes de validación ("Pendiente de validar antes de publicar", "Referencias para revisión") y marcadores en borrador.
		$filtered_policy = self::filter_unvalidated_policy_sections( $structured_txt );
		$confirmed_txt   = $filtered_policy['confirmed_text'];

		if ( '' === $confirmed_txt ) {
			self::record_source_usage( $normalized_type, false, (int) $page->ID, (string) $page->post_name, $page_title, 'pagina_vacia' );

			return [
				'exito'              => false,
				'verificada'         => false,
				'codigo'             => 'pagina_vacia',
				'politica'           => $normalized_type,
				'etiqueta'           => $etiqueta,
				'fuente_oficial'     => [
					'page_id' => (int) $page->ID,
					'slug'    => (string) $page->post_name,
					'titulo'  => $page_title,
					'url'     => $page_url,
				],
				'canal_atencion'     => $canal_soporte,
				'mensaje'            => sprintf(
					'La página oficial "%s" está publicada pero no contiene texto confirmado verificable.',
					$page_title
				),
				'instruccion_agente' => 'Reconoce que la página oficial aún no contiene el detalle solicitado, evita inventar una respuesta, facilita el enlace oficial si resulta útil y ofrece el canal de atención.',
			];
		}

		// 3.2 Verificar coherencia temática: impedir usar una página equivocada para responder otra política (ej. privacidad para cancelaciones, envíos para garantías o garantías para reembolsos).
		$coherence = self::validate_page_topic_coherence(
			$normalized_type,
			$page_title,
			(string) $page->post_name,
			$confirmed_txt
		);

		if ( ! $coherence['coherent'] ) {
			// Comprobar si existe una sección verificada específica en una política oficial estrechamente relacionada (únicamente reembolsos dentro de devoluciones/pagos).
			$related = self::find_related_verified_sections( $normalized_type, $map, (int) $page->ID );

			if ( ! empty( $related['found'] ) ) {
				self::record_source_usage(
					$normalized_type,
					true,
					(int) $related['primary_page']['page_id'],
					(string) $related['primary_page']['slug'],
					(string) $related['primary_page']['titulo'],
					'seccion_verificada_en_politica_relacionada'
				);

				return [
					'exito'                               => true,
					'verificada'                          => true,
					'pagina_asignada_corresponde_al_tema' => false,
					'codigo'                              => 'politica_verificada_en_seccion_oficial_relacionada',
					'politica'                            => $normalized_type,
					'etiqueta'                            => $etiqueta,
					'consulta_recibida'                   => $consulta,
					'pagina_asignada_descartada'          => [
						'page_id'        => (int) $page->ID,
						'titulo'         => $page_title,
						'tema_detectado' => $coherence['detected_page_topic'],
						'motivo'         => $coherence['reason'],
					],
					'fuente_oficial'                      => $related['primary_page'],
					'fuentes_oficiales_consultadas'       => $related['sources'],
					'contenido_oficial'                   => $related['content'],
					'canal_atencion'                      => $canal_soporte,
					'reglas_aplicables'                   => 'IMPORTANTE: La página asignada directamente a "' . $normalized_type . '" ("' . $page_title . '") fue descartada porque corresponde a "' . $coherence['detected_page_topic'] . '". NUNCA uses la política de garantías ni la de envíos para responder sobre reembolsos. Responde únicamente con los apartados oficiales de reembolsos extraídos de "' . $related['primary_page']['titulo'] . '" incluidos en contenido_oficial, indicando que no existe un plazo operativo específico confirmado más allá del marco legal y del procedimiento allí descrito.',
				];
			}

			self::record_source_usage(
				$normalized_type,
				false,
				(int) $page->ID,
				(string) $page->post_name,
				$page_title,
				'pagina_asignada_no_corresponde_al_tema'
			);

			return [
				'exito'                               => false,
				'verificada'                          => false,
				'pagina_asignada_corresponde_al_tema' => false,
				'codigo'                              => 'politica_no_disponible_en_pagina_asignada',
				'politica'                            => $normalized_type,
				'etiqueta'                            => $etiqueta,
				'pagina_asignada_descartada'          => [
					'page_id'        => (int) $page->ID,
					'slug'           => (string) $page->post_name,
					'titulo'         => $page_title,
					'tema_detectado' => $coherence['detected_page_topic'],
					'motivo'         => $coherence['reason'],
				],
				'canal_atencion'                      => $canal_soporte,
				'mensaje'                             => sprintf(
					'La página asignada a "%s" en la configuración ("%s") corresponde a otro tema (%s) y no contiene información sobre "%s". La tienda no tiene publicada una política oficial confirmada para "%s".',
					$etiqueta,
					$page_title,
					$coherence['detected_page_topic'],
					$etiqueta,
					$etiqueta
				),
				'instruccion_agente'                  => 'PROHIBIDO usar el contenido de "' . $page_title . '" o de otra política equivocada para responder sobre "' . $etiqueta . '", y PROHIBIDO inventar condiciones o plazos. Indica con claridad que actualmente la tienda no dispone de información oficial confirmada sobre "' . $etiqueta . '" y recomienda contactar con la tienda únicamente si el cliente necesita gestionar su caso.',
			];
		}

		// 4. Seleccionar los fragmentos relevantes para la consulta manteniendo contexto general.
		$extraction = self::extract_relevant_fragments( $confirmed_txt, $consulta );

		// 5. Registrar internamente la fuente oficial empleada.
		self::record_source_usage(
			$normalized_type,
			true,
			(int) $page->ID,
			(string) $page->post_name,
			$page_title,
			'ok'
		);

		$policy_specific_rules = self::get_policy_specific_guardrails( $normalized_type );

		$response = [
			'exito'                                 => true,
			'verificada'                            => true,
			'pagina_asignada_corresponde_al_tema'   => true,
			'codigo'                                => 'politica_verificada',
			'politica'                              => $normalized_type,
			'etiqueta'                              => $etiqueta,
			'consulta_recibida'                     => $consulta,
			'coincidencia_especifica'               => $extraction['matched_query'],
			'fuente_oficial'                        => [
				'page_id'             => (int) $page->ID,
				'slug'                => (string) $page->post_name,
				'titulo'              => $page_title,
				'url'                 => $page_url,
				'ultima_modificacion' => $modified,
			],
			'contenido_oficial'                     => $extraction['content'],
			'secciones_borrador_excluidas'          => ! empty( $filtered_policy['removed_draft_sections'] ),
			'contiene_campos_pendientes_confirmar'  => ! empty( $filtered_policy['placeholders_pendientes'] ),
			'canal_atencion'                        => $canal_soporte,
			'reglas_aplicables'                     => $policy_specific_rules,
		];

		// Si se consulta la política de pagos, adjuntar verificación real de pasarelas activas en WooCommerce para evitar que el modelo dé por activos métodos solo informativos.
		if ( 'pagos' === $normalized_type && class_exists( 'AI_Ecommerce_Agent_Woo' ) && method_exists( 'AI_Ecommerce_Agent_Woo', 'consultar_metodos_pago' ) ) {
			$wc_payment_status = AI_Ecommerce_Agent_Woo::consultar_metodos_pago( $consulta );
			$response['verificacion_pasarelas_woocommerce'] = [
				'codigo'              => $wc_payment_status['codigo'] ?? '',
				'metodos_disponibles' => $wc_payment_status['metodos_disponibles'] ?? [],
				'nota_prelacion'      => empty( $wc_payment_status['metodos_disponibles'] )
					? 'ATENCIÓN: Aunque la página informativa mencione ejemplos como PSE, Nequi o Daviplata, en WooCommerce actualmente hay 0 pasarelas con enabled="yes". Prevalece obligatoriamente WooCommerce: NO afirmes que PSE, Nequi, Daviplata ni otro método están habilitados ahora mismo; indica que los métodos disponibles se muestran en el checkout al finalizar la compra.'
					: 'Prevalece obligatoriamente la lista metodos_disponibles de WooCommerce sobre cualquier listado informativo de la página.',
			];
		}

		return $response;
	}

	/**
	 * Normaliza alias comunes del tipo de política al identificador canónico.
	 *
	 * @param string $tipo Entrada del modelo.
	 * @return string Clave canónica o cadena vacía.
	 */
	private static function normalize_policy_type( string $tipo ): string {
		$clean = trim( strtolower( $tipo ) );
		$clean = strtr(
			$clean,
			[
				'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
				'ü' => 'u', 'ñ' => 'n',
			]
		);
		$clean = preg_replace( '/[^a-z0-9_-]/', '', $clean );

		$aliases = [
			'envio'                  => 'envios',
			'envios'                 => 'envios',
			'entrega'                => 'envios',
			'entregas'               => 'envios',
			'envios_y_entregas'      => 'envios',
			'pago'                   => 'pagos',
			'pagos'                  => 'pagos',
			'metodos_de_pago'        => 'pagos',
			'garantia'               => 'garantia',
			'garantias'              => 'garantia',
			'calidad'                => 'garantia',
			'garantia_y_calidad'     => 'garantia',
			'devolucion'             => 'devoluciones',
			'devoluciones'           => 'devoluciones',
			'cambios'                => 'devoluciones',
			'retracto'               => 'devoluciones',
			'devoluciones_reembolsos'=> 'devoluciones',
			'reembolso'              => 'reembolsos',
			'reembolsos'             => 'reembolsos',
			'cancelacion'            => 'cancelaciones',
			'cancelaciones'          => 'cancelaciones',
			'privacidad'             => 'privacidad',
			'datos'                  => 'privacidad',
			'datos_personales'       => 'privacidad',
			'terminos'               => 'terminos',
			'condiciones'            => 'terminos',
			'terminos_y_condiciones' => 'terminos',
			'faq'                    => 'faq',
			'faqs'                   => 'faq',
			'preguntas_frecuentes'   => 'faq',
			'empresa'                => 'empresa',
			'informacion_empresa'    => 'empresa',
			'quienes_somos'          => 'empresa',
			'nosotros'               => 'empresa',
			'contacto'               => 'contacto',
		];

		return $aliases[ $clean ] ?? '';
	}

	/**
	 * Resuelve un objeto WP_Post de tipo 'page' y estado 'publish' a partir de un ID o slug.
	 *
	 * @param string $reference ID numérico o slug/ruta de página.
	 * @return WP_Post|null
	 */
	public static function resolve_published_page( string $reference ): ?WP_Post {
		$post = null;

		if ( ctype_digit( $reference ) && (int) $reference > 0 ) {
			$candidate = get_post( (int) $reference );
			if ( $candidate instanceof WP_Post ) {
				$post = $candidate;
			}
		} else {
			$slug_path = trim( $reference, '/' );
			if ( '' !== $slug_path && function_exists( 'get_page_by_path' ) ) {
				$candidate = get_page_by_path( $slug_path, OBJECT, 'page' );
				if ( $candidate instanceof WP_Post ) {
					$post = $candidate;
				}
			}

			if ( ! $post instanceof WP_Post && '' !== $slug_path && function_exists( 'get_posts' ) ) {
				$found = get_posts(
					[
						'name'           => sanitize_title( basename( $slug_path ) ),
						'post_type'      => 'page',
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'no_found_rows'  => true,
					]
				);
				if ( ! empty( $found ) && isset( $found[0] ) && $found[0] instanceof WP_Post ) {
					$post = $found[0];
				}
			}
		}

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		if ( 'page' !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		if ( ! empty( $post->post_password ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * Obtiene el contenido bruto de la página incluyendo respaldo de Elementor si post_content estuviera vacío.
	 *
	 * @param WP_Post $page Página oficial publicada.
	 * @return string
	 */
	private static function extract_raw_page_content( WP_Post $page ): string {
		$content = (string) $page->post_content;

		if ( '' !== trim( wp_strip_all_tags( $content ) ) ) {
			return $content;
		}

		// Respaldo de solo lectura para páginas maquetadas en Elementor cuyo post_content aún no se haya sincronizado.
		$elementor_data = get_post_meta( $page->ID, '_elementor_data', true );
		if ( is_string( $elementor_data ) && '' !== trim( $elementor_data ) ) {
			$decoded = json_decode( $elementor_data, true );
			if ( is_array( $decoded ) ) {
				$extracted = [];
				self::collect_elementor_text_nodes( $decoded, $extracted );
				if ( ! empty( $extracted ) ) {
					return implode( "\n\n", $extracted );
				}
			}
		}

		return $content;
	}

	/**
	 * Recorre recursivamente la estructura JSON de Elementor extrayendo nodos de texto y encabezados.
	 *
	 * @param array $elements  Nodos de Elementor.
	 * @param array $collected Lista acumulada por referencia.
	 * @return void
	 */
	private static function collect_elementor_text_nodes( array $elements, array &$collected ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$settings = $element['settings'];
				foreach ( [ 'title', 'heading_title', 'editor', 'text', 'description', 'tab_title', 'tab_content' ] as $field ) {
					if ( ! empty( $settings[ $field ] ) && is_string( $settings[ $field ] ) ) {
						$collected[] = $settings[ $field ];
					}
				}
				if ( ! empty( $settings['tabs'] ) && is_array( $settings['tabs'] ) ) {
					foreach ( $settings['tabs'] as $tab ) {
						if ( ! empty( $tab['tab_title'] ) && is_string( $tab['tab_title'] ) ) {
							$collected[] = '<h3>' . $tab['tab_title'] . '</h3>';
						}
						if ( ! empty( $tab['tab_content'] ) && is_string( $tab['tab_content'] ) ) {
							$collected[] = $tab['tab_content'];
						}
					}
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::collect_elementor_text_nodes( $element['elements'], $collected );
			}
		}
	}

	/**
	 * Limpia el HTML conservando títulos, listas, tablas, plazos, excepciones y enlaces oficiales.
	 *
	 * @param string $html Contenido HTML de la página.
	 * @return string Texto estructurado legible.
	 */
	public static function clean_and_structure_html( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		// 1. Eliminar bloques <script>, <style> y comentarios HTML.
		$text = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html );
		$text = preg_replace( '/<!--.*?-->/s', '', (string) $text );

		// 2. Eliminar etiquetas de apertura/cierre de shortcodes conservando su texto interior.
		$text = preg_replace( '/\[\/?[a-zA-Z0-9_-]+(?:\s+[^\]]*)?\]/', '', (string) $text );

		// 3. Conservar enlaces relevantes como "Texto (URL)".
		$text = preg_replace_callback(
			'#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
			function ( array $matches ): string {
				$href  = trim( (string) $matches[2] );
				$label = trim( wp_strip_all_tags( (string) $matches[3] ) );
				if ( '' === $label ) {
					return $href;
				}
				if ( '' === $href || '#' === $href || 0 === stripos( $href, 'javascript:' ) ) {
					return $label;
				}
				if ( $label === $href ) {
					return $label;
				}
				return $label . ' (' . $href . ')';
			},
			(string) $text
		);

		// 4. Conservar encabezados H1-H6 y párrafos que contienen únicamente un título en negrita (<p><strong>Título</strong></p>) como secciones "### ".
		$text = preg_replace_callback(
			'#<h([1-6])\b[^>]*>(.*?)</h\1>#is',
			function ( array $matches ): string {
				$heading = trim( wp_strip_all_tags( (string) $matches[2] ) );
				$heading = ltrim( $heading, "#* \t" );
				return '' !== $heading ? "\n\n### " . $heading . "\n" : "\n";
			},
			(string) $text
		);

		$text = preg_replace_callback(
			'#<p\b[^>]*>\s*<(?:strong|b)\b[^>]*>(.*?)</(?:strong|b)>\s*</p>#is',
			function ( array $matches ): string {
				$heading = trim( wp_strip_all_tags( (string) $matches[1] ) );
				$heading = trim( $heading, "#* \t" );
				return '' !== $heading ? "\n\n### " . $heading . "\n" : "\n";
			},
			(string) $text
		);

		// 5. Conservar listas y tablas.
		$text = preg_replace( '#<li\b[^>]*>#i', "\n- ", (string) $text );
		$text = preg_replace( '#</li>#i', '', (string) $text );
		$text = preg_replace( '#<(td|th)\b[^>]*>#i', ' ', (string) $text );
		$text = preg_replace( '#</(td|th)>#i', ' | ', (string) $text );
		$text = preg_replace( '#</(tr|table|ul|ol)>#i', "\n", (string) $text );

		// 6. Convertir saltos de línea y cierres de bloque en saltos de línea reales.
		$text = preg_replace( '#<br\s*/?>#i', "\n", (string) $text );
		$text = preg_replace( '#</(p|div|section|article|blockquote|header|footer)>#i', "\n\n", (string) $text );

		// 7. Eliminar etiquetas HTML restantes y decodificar entidades.
		$text = wp_strip_all_tags( (string) $text, false );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 8. Normalizar espacios por línea sin perder estructura de párrafos ni viñetas.
		$lines       = preg_split( '/\r\n|\r|\n/', $text );
		$clean_lines = [];
		foreach ( $lines as $line ) {
			$line = trim( preg_replace( '/[ \t]+/u', ' ', $line ) );
			$line = trim( $line, '|' );
			$line = trim( $line );
			$clean_lines[] = $line;
		}

		$joined = implode( "\n", $clean_lines );
		$joined = preg_replace( "/\n{3,}/", "\n\n", (string) $joined );

		return trim( (string) $joined );
	}

	/**
	 * Separa y excluye secciones internas pendientes de validación editorial ("Pendiente de validar antes de publicar",
	 * "Referencias para revisión") y neutraliza marcadores entre corchetes como "[POR CONFIRMAR]".
	 *
	 * @param string $structured_text Texto estructurado.
	 * @return array{confirmed_text:string,removed_draft_sections:string[],placeholders_pendientes:string[]}
	 */
	public static function filter_unvalidated_policy_sections( string $structured_text ): array {
		if ( '' === trim( $structured_text ) ) {
			return [
				'confirmed_text'          => '',
				'removed_draft_sections'  => [],
				'placeholders_pendientes' => [],
			];
		}

		$sections = preg_split( '/(?=\n### )/u', "\n" . $structured_text );
		$sections = array_values( array_filter( array_map( 'trim', is_array( $sections ) ? $sections : [] ), 'strlen' ) );

		$confirmed_sections = [];
		$removed_headings   = [];

		$draft_heading_patterns = [
			'pendiente de validar',
			'por validar antes de publicar',
			'referencias para revision',
			'notas internas',
			'borrador interno',
		];

		foreach ( $sections as $section ) {
			$first_line = strtok( $section, "\n" );
			$norm_head  = self::normalize_for_match( (string) $first_line );
			$is_draft   = false;

			if ( 0 === strpos( trim( (string) $first_line ), '###' ) ) {
				foreach ( $draft_heading_patterns as $pattern ) {
					if ( false !== strpos( $norm_head, $pattern ) ) {
						$is_draft = true;
						$removed_headings[] = trim( ltrim( (string) $first_line, '# ' ) );
						break;
					}
				}
			}

			if ( ! $is_draft ) {
				$confirmed_sections[] = $section;
			}
		}

		$confirmed_text = trim( implode( "\n\n", $confirmed_sections ) );
		$placeholders   = [];

		if ( preg_match_all( '/\[([^\]\r\n]{3,80})\]/u', $confirmed_text, $matches ) && ! empty( $matches[1] ) ) {
			foreach ( $matches[1] as $idx => $inner ) {
				$norm_inner = self::normalize_for_match( (string) $inner );
				if (
					false !== strpos( $norm_inner, 'por confirmar' )
					|| false !== strpos( $norm_inner, 'pendiente' )
					|| false !== strpos( $norm_inner, 'segun ley' )
				) {
					$placeholders[] = $matches[0][ $idx ];
					$confirmed_text = str_replace(
						$matches[0][ $idx ],
						'(dato específico pendiente de confirmación oficial por la tienda)',
						$confirmed_text
					);
				}
			}
		}

		return [
			'confirmed_text'          => trim( $confirmed_text ),
			'removed_draft_sections'  => array_values( array_unique( $removed_headings ) ),
			'placeholders_pendientes' => array_values( array_unique( $placeholders ) ),
		];
	}

	/**
	 * Verifica que la página asignada a una categoría de política corresponda realmente a ese tema
	 * y no sea otra política asignada por error (p. ej. Privacidad asignada a Cancelaciones,
	 * Garantías asignada a Reembolsos, o Envíos asignada a Garantías).
	 *
	 * @param string $policy_type    Clave canónica de la política solicitada.
	 * @param string $page_title     Título de la página asignada.
	 * @param string $page_slug      Slug de la página asignada.
	 * @param string $confirmed_text Contenido confirmado de la página (sin secciones en borrador).
	 * @return array{coherent:bool,detected_page_topic:string,reason:string}
	 */
	public static function validate_page_topic_coherence(
		string $policy_type,
		string $page_title,
		string $page_slug,
		string $confirmed_text
	): array {
		$norm_title   = self::normalize_for_match( $page_title . ' ' . str_replace( '-', ' ', $page_slug ) );
		$norm_content = self::normalize_for_match( $confirmed_text );

		$title_topic_markers = [
			'envios'        => [ 'envio', 'envios', 'entrega', 'entregas', 'despacho' ],
			'devoluciones'  => [ 'devolucion', 'devoluciones', 'retracto', 'cambio', 'cambios' ],
			'reembolsos'    => [ 'reembolso', 'reembolsos', 'reversion' ],
			'garantia'      => [ 'garantia', 'garantias', 'calidad' ],
			'cancelaciones' => [ 'cancelacion', 'cancelaciones', 'cancelar', 'anulacion' ],
			'terminos'      => [ 'termino', 'terminos', 'condicion', 'condiciones' ],
			'privacidad'    => [ 'privacidad', 'datos personales', 'tratamiento de datos', 'cookies' ],
			'pagos'         => [ 'pago', 'pagos', 'metodos de pago', 'medios de pago' ],
		];

		$required_content_markers = [
			'envios'        => [ 'envio', 'envios', 'entrega', 'entregas', 'cobertura', 'transportadora' ],
			'devoluciones'  => [ 'devolucion', 'devoluciones', 'retracto', 'cambio', 'cambios' ],
			'reembolsos'    => [ 'reembolso', 'reembolsos', 'reversion de pago', 'reversion de pagos', 'devolver dinero' ],
			'garantia'      => [ 'garantia', 'garantias', 'calidad', 'idoneidad', 'defecto' ],
			'cancelaciones' => [ 'cancelacion', 'cancelaciones', 'cancelar pedido', 'cancelar un pedido', 'anular pedido', 'antes del despacho' ],
			'terminos'      => [ 'terminos', 'condiciones', 'vendedor', 'comprador' ],
			'privacidad'    => [ 'privacidad', 'datos personales', 'tratamiento', 'ley 1581', 'titular' ],
			'pagos'         => [ 'pago', 'pagos', 'checkout', 'pasarela' ],
		];

		if ( ! isset( $required_content_markers[ $policy_type ] ) ) {
			return [
				'coherent'            => true,
				'detected_page_topic' => $policy_type,
				'reason'              => '',
			];
		}

		// Detectar de qué tema es el título/slug de la página.
		$detected_title_topics = [];
		foreach ( $title_topic_markers as $topic_key => $markers ) {
			foreach ( $markers as $m ) {
				if ( preg_match( '/\b' . preg_quote( $m, '/' ) . '\b/u', $norm_title ) ) {
					$detected_title_topics[ $topic_key ] = true;
					break;
				}
			}
		}

		// Comprobar si el contenido confirmado aborda realmente la política solicitada.
		$has_content_marker = false;
		foreach ( $required_content_markers[ $policy_type ] as $marker ) {
			if ( preg_match( '/\b' . preg_quote( $marker, '/' ) . '\b/u', $norm_content ) || preg_match( '/\b' . preg_quote( $marker, '/' ) . '\b/u', $norm_title ) ) {
				$has_content_marker = true;
				break;
			}
		}

		$primary_detected_topic = ! empty( $detected_title_topics )
			? implode( '/', array_keys( $detected_title_topics ) )
			: 'desconocido';

		// Si la página no contiene ningún término clave del tema solicitado (ej. Privacidad para Cancelaciones, o Garantías para Reembolsos).
		if ( ! $has_content_marker ) {
			return [
				'coherent'            => false,
				'detected_page_topic' => $primary_detected_topic,
				'reason'              => sprintf(
					'La página "%s" trata sobre "%s" y no contiene disposiciones confirmadas sobre "%s".',
					$page_title,
					$primary_detected_topic,
					$policy_type
				),
			];
		}

		// Impedir cruces entre políticas distintas aunque compartan alguna palabra suelta (p. ej. usar Envíos para responder Garantías, o Privacidad para responder Devoluciones/Reembolsos/Cancelaciones).
		if ( ! empty( $detected_title_topics ) && ! isset( $detected_title_topics[ $policy_type ] ) ) {
			// Permitir únicamente que 'reembolsos' esté contenido en una página titulada 'devoluciones' si tiene sección propia de reembolsos.
			$allowed_combined = ( 'reembolsos' === $policy_type && isset( $detected_title_topics['devoluciones'] ) );
			if ( ! $allowed_combined ) {
				return [
					'coherent'            => false,
					'detected_page_topic' => $primary_detected_topic,
					'reason'              => sprintf(
						'La página asignada ("%s") pertenece a la categoría "%s" y no a "%s". No debe usarse una página equivocada para responder otra política.',
						$page_title,
						$primary_detected_topic,
						$policy_type
					),
				];
			}
		}

		return [
			'coherent'            => true,
			'detected_page_topic' => $policy_type,
			'reason'              => '',
		];
	}

	/**
	 * Busca secciones verificadas específicas cuando la página asignada a 'reembolsos' es errónea,
	 * extrayendo únicamente los apartados de reembolsos de las páginas oficiales de 'devoluciones' y 'pagos'.
	 *
	 * @param string              $policy_type     Tipo de política solicitado.
	 * @param array<string,string> $map            Mapa de páginas configuradas.
	 * @param int                 $exclude_page_id ID de la página descartada.
	 * @return array<string,mixed>
	 */
	private static function find_related_verified_sections( string $policy_type, array $map, int $exclude_page_id ): array {
		if ( 'reembolsos' !== $policy_type ) {
			return [ 'found' => false ];
		}

		$candidate_keys = [ 'devoluciones', 'pagos' ];
		$extracted_blocks = [];
		$sources          = [];
		$primary_page     = null;

		foreach ( $candidate_keys as $cand_key ) {
			$ref = isset( $map[ $cand_key ] ) ? $map[ $cand_key ] : '';
			if ( '' === $ref ) {
				continue;
			}
			$cand_page = self::resolve_published_page( $ref );
			if ( ! $cand_page instanceof WP_Post || (int) $cand_page->ID === $exclude_page_id ) {
				continue;
			}

			$cand_title = html_entity_decode( wp_strip_all_tags( get_the_title( $cand_page ) ), ENT_QUOTES, 'UTF-8' );
			$raw        = self::extract_raw_page_content( $cand_page );
			$structured = self::clean_and_structure_html( $raw );
			$filtered   = self::filter_unvalidated_policy_sections( $structured );
			$confirmed  = $filtered['confirmed_text'];

			if ( '' === $confirmed ) {
				continue;
			}

			$coherence = self::validate_page_topic_coherence( $cand_key, $cand_title, (string) $cand_page->post_name, $confirmed );
			if ( ! $coherence['coherent'] ) {
				continue;
			}

			$sections = preg_split( '/(?=\n### )/u', "\n" . $confirmed );
			$sections = array_values( array_filter( array_map( 'trim', is_array( $sections ) ? $sections : [] ), 'strlen' ) );

			$matched_sections = [];
			foreach ( $sections as $sec ) {
				$first_line = strtok( $sec, "\n" );
				$norm_head  = self::normalize_for_match( (string) $first_line );
				if ( false !== strpos( $norm_head, 'reembolso' ) || false !== strpos( $norm_head, 'reversion' ) ) {
					$matched_sections[] = $sec;
				}
			}

			if ( ! empty( $matched_sections ) ) {
				$page_meta = [
					'page_id'             => (int) $cand_page->ID,
					'slug'                => (string) $cand_page->post_name,
					'titulo'              => $cand_title,
					'url'                 => (string) get_permalink( $cand_page ),
					'ultima_modificacion' => (string) get_post_modified_time( 'Y-m-d H:i:s', false, $cand_page ),
				];
				if ( null === $primary_page ) {
					$primary_page = $page_meta;
				}
				$sources[]          = $page_meta;
				$extracted_blocks[] = "Fuente oficial: {$cand_title}\n" . implode( "\n\n", $matched_sections );
			}
		}

		if ( empty( $extracted_blocks ) || null === $primary_page ) {
			return [ 'found' => false ];
		}

		return [
			'found'        => true,
			'primary_page' => $primary_page,
			'sources'      => $sources,
			'content'      => implode( "\n\n", $extracted_blocks ),
		];
	}

	/**
	 * Extrae los fragmentos pertinentes de la política según la consulta del usuario.
	 *
	 * Si el documento completo cabe dentro del límite seguro o no hay palabras clave
	 * específicas, entrega el texto estructurado hasta MAX_POLICY_CHARS para no omitir
	 * condiciones generales ni excepciones.
	 *
	 * @param string $structured_text Texto limpio y estructurado de la política.
	 * @param string $query           Consulta o término específico del usuario.
	 * @return array{content:string,matched_query:bool}
	 */
	public static function extract_relevant_fragments( string $structured_text, string $query ): array {
		$length = function_exists( 'mb_strlen' )
			? mb_strlen( $structured_text, 'UTF-8' )
			: strlen( $structured_text );

		$keywords = self::extract_search_keywords( $query );

		// Dividir por secciones encabezadas con "### " o bloques de párrafos dobles.
		$sections = preg_split( '/(?=\n### )/u', "\n" . $structured_text );
		$sections = array_values( array_filter( array_map( 'trim', is_array( $sections ) ? $sections : [] ), 'strlen' ) );

		if ( empty( $sections ) ) {
			$sections = [ $structured_text ];
		}

		$matched_Query = false;
		if ( ! empty( $keywords ) ) {
			$scored = [];
			foreach ( $sections as $index => $section ) {
				$norm_section = self::normalize_for_match( $section );
				$score        = 0;
				foreach ( $keywords as $kw ) {
					if ( false !== strpos( $norm_section, $kw ) ) {
						$score++;
					}
				}
				if ( $score > 0 ) {
					$matched_Query = true;
				}
				$scored[] = [
					'index'   => $index,
					'score'   => $score,
					'section' => $section,
				];
			}

			// Si el documento supera el límite o hay varias secciones y existen coincidencias, priorizar las relevantes + introducción.
			if ( $matched_Query && $length > self::MAX_POLICY_CHARS && count( $sections ) > 1 ) {
				usort(
					$scored,
					function ( array $a, array $b ): int {
						if ( $b['score'] !== $a['score'] ) {
							return $b['score'] <=> $a['score'];
						}
						return $a['index'] <=> $b['index'];
					}
				);

				$selected_indices = [ 0 => true ]; // Conservar siempre el bloque introductorio/alcance general.
				foreach ( $scored as $item ) {
					if ( $item['score'] > 0 ) {
						$selected_indices[ $item['index'] ] = true;
					}
				}

				ksort( $selected_indices );
				$picked = [];
				$used   = 0;
				foreach ( array_keys( $selected_indices ) as $idx ) {
					$sec     = $sections[ $idx ];
					$sec_len = function_exists( 'mb_strlen' ) ? mb_strlen( $sec, 'UTF-8' ) : strlen( $sec );
					if ( $used + $sec_len > self::MAX_POLICY_CHARS && ! empty( $picked ) ) {
						break;
					}
					$picked[] = $sec;
					$used    += $sec_len + 2;
				}

				return [
					'content'       => implode( "\n\n", $picked ),
					'matched_query' => true,
				];
			}
		}

		if ( $length <= self::MAX_POLICY_CHARS ) {
			return [
				'content'       => $structured_text,
				'matched_query' => $matched_Query,
			];
		}

		$truncated = function_exists( 'mb_substr' )
			? mb_substr( $structured_text, 0, self::MAX_POLICY_CHARS, 'UTF-8' )
			: substr( $structured_text, 0, self::MAX_POLICY_CHARS );

		return [
			'content'       => rtrim( $truncated ) . '…',
			'matched_query' => $matched_Query,
		];
	}

	/**
	 * Devuelve las reglas de obligado cumplimiento específicas para cada categoría de política.
	 *
	 * @param string $policy_type Clave canónica de la política.
	 * @return string
	 */
	private static function get_policy_specific_guardrails( string $policy_type ): string {
		switch ( $policy_type ) {
			case 'envios':
				return 'Usa únicamente la cobertura, rangos de entrega, costos y condiciones que figuren explícitamente en contenido_oficial. Si el texto prohíbe publicar un plazo como "1 a 3 días" o una tarifa fija no activa en checkout, respeta estrictamente esa prohibición aunque otro ajuste mencione esos valores.';
			case 'pagos':
				return 'Para confirmar qué métodos de pago están activos ahora mismo en el checkout, debes verificar siempre con consultar_metodos_pago (y verificacion_pasarelas_woocommerce). Si en WooCommerce no hay pasarelas habilitadas (enabled="yes"), NO afirmes que se aceptan PSE, Nequi, Daviplata u otros listados informativos; indica que los métodos disponibles serán los que aparezcan activos en el checkout al finalizar la compra.';
			case 'garantia':
				return 'Usa únicamente los términos y condiciones de garantía publicados en contenido_oficial. NUNCA respondas sobre garantías utilizando la página de envíos. No inventes plazos universales (5, 15 o 30 días) si no constan en el texto y no menciones canales marcados como pendientes de confirmación.';
			case 'devoluciones':
				return 'Explica solo el procedimiento, el plazo legal de retracto (5 días hábiles cuando aplica según Ley 1480) y las condiciones que consten en contenido_oficial. No afirmes que todos los suplementos o vitaminas están automáticamente excluidos de retracto.';
			case 'reembolsos':
				return 'Indica únicamente el proceso de reembolso verificable en contenido_oficial. No prometas un plazo operativo fijo que no esté confirmado ni uses la política de garantías para responder sobre reembolsos.';
			case 'cancelaciones':
				return 'Indica únicamente las condiciones y plazos de cancelación de pedidos que figuren en contenido_oficial. Nunca uses la política de privacidad ni otra página para responder sobre cancelaciones.';
			case 'privacidad':
				return 'Usa únicamente el contenido oficial sobre tratamiento de datos (Ley 1581 de 2012, responsable y correo reclamos@nutrix.com / info@nutrix.com). Nunca afirmes que los datos nunca salen del sitio, ya que el chat utiliza una API externa de IA (OpenAI).';
			case 'terminos':
				return 'Responde únicamente con las condiciones de compra confirmadas que aparezcan en contenido_oficial. Si un dato aparece como pendiente de confirmación oficial, no lo inventes.';
			case 'faq':
				return 'Esta página general de preguntas frecuentes complementa la atención, pero no anula las restricciones explícitas de las políticas oficiales.';
			case 'empresa':
			case 'contacto':
				return 'Utiliza únicamente los datos institucionales o de contacto presentes en contenido_oficial sin inventar canales no listados.';
			default:
				return 'Responde exclusivamente con datos presentes en contenido_oficial.';
		}
	}

	/**
	 * Extrae palabras clave significativas de la consulta del usuario.
	 *
	 * @param string $query Consulta del usuario.
	 * @return string[]
	 */
	private static function extract_search_keywords( string $query ): array {
		$norm = self::normalize_for_match( $query );
		if ( '' === $norm ) {
			return [];
		}

		$stopwords = [
			'como', 'cual', 'cuales', 'cuanto', 'cuantos', 'donde', 'cuando',
			'para', 'por', 'con', 'sin', 'sobre', 'entre', 'hasta', 'desde',
			'los', 'las', 'del', 'una', 'uno', 'unos', 'unas', 'este', 'esta',
			'tienen', 'tiene', 'hay', 'son', 'esta', 'estan', 'puedo', 'puede',
			'politica', 'politicas', 'nutrix', 'tienda', 'ustedes', 'que',
		];

		$parts    = preg_split( '/\s+/', $norm );
		$keywords = [];
		foreach ( $parts as $word ) {
			$word = trim( $word );
			if ( strlen( $word ) >= 3 && ! in_array( $word, $stopwords, true ) ) {
				$keywords[ $word ] = $word;
			}
		}

		return array_values( $keywords );
	}

	/**
	 * Normaliza texto para comparación sin acentos ni signos de puntuación.
	 *
	 * @param string $text Texto de entrada.
	 * @return string
	 */
	private static function normalize_for_match( string $text ): string {
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

	/**
	 * Registra internamente qué fuente oficial se utilizó para responder una consulta de políticas,
	 * sin almacenar mensajes del usuario ni datos personales.
	 *
	 * @param string $policy_key Clave de la política consultada.
	 * @param bool   $verified   Si la fuente fue recuperada y verificada.
	 * @param int    $page_id    ID de la página de WordPress (0 si no disponible).
	 * @param string $slug       Slug o referencia de la página.
	 * @param string $title      Título de la página oficial.
	 * @param string $status     Código interno de resultado.
	 * @return void
	 */
	private static function record_source_usage(
		string $policy_key,
		bool $verified,
		int $page_id,
		string $slug,
		string $title,
		string $status
	): void {
		$entry = [
			'timestamp'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'politica'   => $policy_key,
			'verificada' => $verified,
			'page_id'    => $page_id,
			'slug'       => $slug,
			'titulo'     => $title,
			'estado'     => $status,
		];

		if ( function_exists( 'get_transient' ) && function_exists( 'set_transient' ) ) {
			$log = get_transient( self::SOURCE_LOG_TRANSIENT );
			$log = is_array( $log ) ? $log : [];
			$log[] = $entry;
			if ( count( $log ) > self::MAX_LOG_ENTRIES ) {
				$log = array_slice( $log, -self::MAX_LOG_ENTRIES );
			}
			set_transient( self::SOURCE_LOG_TRANSIENT, $log, 7 * DAY_IN_SECONDS );
		}

		if ( function_exists( 'do_action' ) ) {
			do_action( 'aiea_policy_source_consulted', $entry );
		}
	}
}
