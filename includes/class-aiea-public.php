<?php
/**
 * AI Ecommerce Agent — Widget público de chat flotante para el frontend.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Ecommerce_Agent_Public {

	const OPTION_APPEARANCE = 'aiea_appearance';

	/**
	 * Inicializa los hooks del frontend público si no estamos en el panel de administración.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( is_admin() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'wp_footer', [ __CLASS__, 'render_widget' ] );
	}

	/**
	 * Comprueba si el agente público está activado en la configuración del plugin.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$options = get_option( 'aiea_settings', [] );

		return is_array( $options ) && ! empty( $options['enabled'] ) && '0' !== (string) $options['enabled'];
	}

	/**
	 * Devuelve los valores predeterminados de apariencia que reproducen exactamente el diseño original.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_appearance_defaults(): array {
		$options    = get_option( 'aiea_settings', [] );
		$agent_name = is_array( $options ) && ! empty( $options['agent_name'] )
			? trim( sanitize_text_field( (string) $options['agent_name'] ) )
			: 'Asistente de ventas';

		return [
			// Identidad
			'agent_name'              => $agent_name,
			'welcome_message'         => '',
			'subtitle'                => 'Asesoría de productos en línea',
			'toggle_text'             => '¿Necesitas ayuda?',
			'input_placeholder'       => 'Pregunta por un producto o precio…',
			'send_button_text'        => '',
			'typing_text'             => 'Respondiendo…',
			'generic_error_message'   => 'El asistente no está disponible en este momento. Por favor, inténtalo de nuevo en unos instantes.',
			'connection_status_text'  => '',
			// Colores (reproducen exactamente el CSS original)
			'primary_color'           => '#2563eb',
			'toggle_bg_color'         => '#111827',
			'header_bg_color'         => '#111827',
			'window_bg_color'         => '#ffffff',
			'text_primary_color'      => '#1d2327',
			'text_secondary_color'    => '#9ca3af',
			'user_msg_bg_color'       => '#2563eb',
			'assistant_msg_bg_color'  => '#ffffff',
			'user_msg_text_color'     => '#ffffff',
			'assistant_msg_text_color'=> '#1f2937',
			'send_btn_bg_color'       => '#2563eb',
			'send_btn_text_color'     => '#ffffff',
			'border_color'            => '#e5e7eb',
			'link_color'              => '#2563eb',
			// Diseño
			'position'                => 'right',
			'border_radius'           => 16,
			'max_width'               => 370,
			'max_height'              => 520,
			'style_variant'           => 'standard',
		];
	}

	/**
	 * Obtiene la configuración de apariencia validada y escapada en el servidor.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_appearance(): array {
		$defaults = self::get_appearance_defaults();
		$saved    = get_option( self::OPTION_APPEARANCE, [] );
		$saved    = is_array( $saved ) ? $saved : [];
		$merged   = wp_parse_args( $saved, $defaults );

		// Sincronizar nombre del agente si no fue sobreescrito específicamente.
		$agent_name = trim( sanitize_text_field( (string) $merged['agent_name'] ) );
		if ( '' === $agent_name ) {
			$agent_name = $defaults['agent_name'];
		}

		$color_keys = [
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
		];

		foreach ( $color_keys as $ckey ) {
			$merged[ $ckey ] = self::sanitize_hex_color_strict( (string) $merged[ $ckey ], (string) $defaults[ $ckey ] );
		}

		$merged['agent_name']             = $agent_name;
		$merged['welcome_message']        = trim( sanitize_textarea_field( (string) $merged['welcome_message'] ) );
		$merged['subtitle']               = trim( sanitize_text_field( (string) $merged['subtitle'] ) );
		$merged['toggle_text']            = trim( sanitize_text_field( (string) $merged['toggle_text'] ) );
		$merged['input_placeholder']      = trim( sanitize_text_field( (string) $merged['input_placeholder'] ) );
		$merged['send_button_text']       = trim( sanitize_text_field( (string) $merged['send_button_text'] ) );
		$merged['typing_text']            = trim( sanitize_text_field( (string) $merged['typing_text'] ) );
		$merged['generic_error_message']  = trim( sanitize_text_field( (string) $merged['generic_error_message'] ) );
		$merged['connection_status_text'] = trim( sanitize_text_field( (string) $merged['connection_status_text'] ) );

		$merged['position']      = in_array( $merged['position'], [ 'right', 'left' ], true ) ? $merged['position'] : 'right';
		$merged['style_variant'] = in_array( $merged['style_variant'], [ 'standard', 'compact' ], true ) ? $merged['style_variant'] : 'standard';
		$merged['border_radius'] = max( 0, min( 32, (int) $merged['border_radius'] ) );
		$merged['max_width']     = max( 280, min( 600, (int) $merged['max_width'] ) );
		$merged['max_height']    = max( 360, min( 800, (int) $merged['max_height'] ) );

		return $merged;
	}

	/**
	 * Valida estrictamente un color hexadecimal (#RGB o #RRGGBB). Si no es válido, devuelve el predeterminado.
	 *
	 * @param string $color   Color recibido.
	 * @param string $default Color hexadecimal por defecto.
	 * @return string
	 */
	public static function sanitize_hex_color_strict( string $color, string $default ): string {
		$color = trim( $color );
		if ( preg_match( '/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $color ) ) {
			return strtolower( $color );
		}
		return $default;
	}

	/**
	 * Encola los estilos y scripts del chat flotante únicamente cuando el agente está activo.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( is_admin() || ! self::is_enabled() ) {
			return;
		}

		$appearance = self::get_appearance();
		$agent_name = $appearance['agent_name'];
		$welcome    = '' !== $appearance['welcome_message']
			? $appearance['welcome_message']
			: sprintf( '¡Hola! Soy %s. ¿En qué puedo ayudarte hoy con nuestros productos?', $agent_name );
		$error_503  = '' !== $appearance['generic_error_message']
			? $appearance['generic_error_message']
			: 'El asistente no está disponible en este momento. Por favor, inténtalo de nuevo en unos instantes.';

		if ( wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
			wp_enqueue_script( 'wc-cart-fragments' );
		}

		wp_enqueue_style(
			'aiea-public-chat',
			AIEA_URL . 'assets/css/public-chat.css',
			[],
			AIEA_VERSION
		);

		$inline_vars = sprintf(
			'.aiea-chat-widget{' .
			'--aiea-primary:%1$s;' .
			'--aiea-toggle-bg:%2$s;' .
			'--aiea-header-bg:%3$s;' .
			'--aiea-window-bg:%4$s;' .
			'--aiea-text-primary:%5$s;' .
			'--aiea-text-secondary:%6$s;' .
			'--aiea-user-message-bg:%7$s;' .
			'--aiea-assistant-message-bg:%8$s;' .
			'--aiea-user-message-text:%9$s;' .
			'--aiea-assistant-message-text:%10$s;' .
			'--aiea-send-bg:%11$s;' .
			'--aiea-send-text:%12$s;' .
			'--aiea-border-color:%13$s;' .
			'--aiea-link-color:%14$s;' .
			'--aiea-radius:%15$dpx;' .
			'--aiea-max-width:%16$dpx;' .
			'--aiea-max-height:%17$dpx;' .
			'}',
			$appearance['primary_color'],
			$appearance['toggle_bg_color'],
			$appearance['header_bg_color'],
			$appearance['window_bg_color'],
			$appearance['text_primary_color'],
			$appearance['text_secondary_color'],
			$appearance['user_msg_bg_color'],
			$appearance['assistant_msg_bg_color'],
			$appearance['user_msg_text_color'],
			$appearance['assistant_msg_text_color'],
			$appearance['send_btn_bg_color'],
			$appearance['send_btn_text_color'],
			$appearance['border_color'],
			$appearance['link_color'],
			(int) $appearance['border_radius'],
			(int) $appearance['max_width'],
			(int) $appearance['max_height']
		);

		wp_add_inline_style( 'aiea-public-chat', $inline_vars );

		wp_enqueue_script(
			'aiea-public-chat',
			AIEA_URL . 'assets/js/public-chat.js',
			[],
			AIEA_VERSION,
			true
		);

		$effective_limits = AI_Ecommerce_Agent_Usage::get_effective_limits();
		$max_length       = (int) ( $effective_limits['max_message_length'] ?? AI_Ecommerce_Agent_API::PUBLIC_MAX_MESSAGE_LENGTH );
		$is_demo          = AI_Ecommerce_Agent_Usage::is_demo_mode();

		wp_localize_script(
			'aiea-public-chat',
			'AIEAPublic',
			[
				'restUrl'        => esc_url_raw( rest_url( 'aiea/v1/chat/public' ) ),
				'addToCartUrl'   => esc_url_raw( rest_url( 'aiea/v1/cart/add' ) ),
				'wcFragmentsUrl' => esc_url_raw( add_query_arg( 'wc-ajax', 'get_refreshed_fragments', home_url( '/' ) ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'agentName'      => $agent_name,
				'maxLength'      => $max_length,
				'isDemoMode'     => $is_demo,
				'demoCtas'       => $is_demo ? AI_Ecommerce_Agent_Usage::get_demo_ctas() : [],
				'strings'        => [
					'welcome'          => $welcome,
					'emptyMessage'     => 'Por favor, escribe un mensaje antes de enviar.',
					'tooLong'          => sprintf( 'El mensaje no puede superar los %d caracteres.', $max_length ),
					'error400'         => 'No se pudo procesar la solicitud. Verifica tu mensaje e inténtalo de nuevo.',
					'errorSession'     => 'Tu sesión anterior expiró. Hemos iniciado una nueva sesión; por favor, envía tu mensaje otra vez.',
					'error403'         => 'El asistente no está disponible en este momento.',
					'error429'         => 'Estás enviando mensajes demasiado rápido. Por favor, espera unos segundos.',
					'error503'         => $error_503,
					'errorNetwork'     => 'No se pudo conectar con la tienda. Comprueba tu conexión e inténtalo de nuevo.',
					'demoSessionLimit' => AI_Ecommerce_Agent_Usage::DEMO_SESSION_LIMIT_MESSAGE,
					'demoDailyLimit'   => AI_Ecommerce_Agent_Usage::DEMO_DAILY_LIMIT_MESSAGE,
				],
			]
		);
	}

	/**
	 * Renderiza el contenedor HTML del widget flotante en el pie de página del frontend.
	 *
	 * @return void
	 */
	public static function render_widget(): void {
		if ( is_admin() || ! self::is_enabled() ) {
			return;
		}

		$appearance  = self::get_appearance();
		$agent_name  = $appearance['agent_name'];
		$welcome     = '' !== $appearance['welcome_message']
			? $appearance['welcome_message']
			: sprintf( '¡Hola! Soy %s. ¿En qué puedo ayudarte hoy con nuestros productos?', $agent_name );
		$subtitle    = '' !== $appearance['subtitle'] ? $appearance['subtitle'] : 'Asesoría de productos en línea';
		$toggle_text = '' !== $appearance['toggle_text'] ? $appearance['toggle_text'] : '¿Necesitas ayuda?';
		$placeholder = '' !== $appearance['input_placeholder'] ? $appearance['input_placeholder'] : 'Pregunta por un producto o precio…';
		$typing_text = '' !== $appearance['typing_text'] ? $appearance['typing_text'] : 'Respondiendo…';
		$send_text   = $appearance['send_button_text'];
		$status_text = $appearance['connection_status_text'];

		$widget_classes = [ 'aiea-chat-widget' ];
		if ( 'left' === $appearance['position'] ) {
			$widget_classes[] = 'aiea-chat-widget--left';
		}
		if ( 'compact' === $appearance['style_variant'] ) {
			$widget_classes[] = 'aiea-chat-widget--compact';
		}
		?>
		<div id="aiea-chat-widget" class="<?php echo esc_attr( implode( ' ', $widget_classes ) ); ?>" aria-live="polite">
			<div
				id="aiea-chat-window"
				class="aiea-chat-window"
				role="dialog"
				aria-modal="false"
				aria-labelledby="aiea-chat-title"
				hidden
			>
				<header class="aiea-chat-header">
					<div class="aiea-chat-header-info">
						<span
							class="aiea-chat-status-dot"
							aria-hidden="true"
							<?php if ( '' !== $status_text ) : ?>
								title="<?php echo esc_attr( $status_text ); ?>"
							<?php endif; ?>
						></span>
						<div>
							<h2 id="aiea-chat-title" class="aiea-chat-title"><?php echo esc_html( $agent_name ); ?></h2>
							<p class="aiea-chat-subtitle">
								<?php echo esc_html( $subtitle ); ?>
								<?php if ( '' !== $status_text ) : ?>
									<span class="aiea-chat-status-label"> · <?php echo esc_html( $status_text ); ?></span>
								<?php endif; ?>
							</p>
						</div>
					</div>
					<button
						type="button"
						id="aiea-chat-close"
						class="aiea-chat-close"
						aria-label="<?php esc_attr_e( 'Cerrar chat', 'ai-ecommerce-agent' ); ?>"
					>
						<span aria-hidden="true">&times;</span>
					</button>
				</header>

				<div id="aiea-chat-messages" class="aiea-chat-messages" role="log" aria-live="polite" aria-relevant="additions">
					<div class="aiea-chat-msg aiea-chat-msg--assistant" data-aiea-welcome="1">
						<div class="aiea-chat-bubble"><?php echo esc_html( $welcome ); ?></div>
					</div>
				</div>

				<div id="aiea-chat-typing" class="aiea-chat-typing" role="status" aria-live="polite" hidden>
					<span class="aiea-chat-typing-dots" aria-hidden="true">
						<span></span><span></span><span></span>
					</span>
					<span class="aiea-chat-typing-text"><?php echo esc_html( $typing_text ); ?></span>
				</div>

				<div id="aiea-chat-notice" class="aiea-chat-notice" role="alert" hidden></div>

				<?php
				$effective_limits = AI_Ecommerce_Agent_Usage::get_effective_limits();
				$max_length       = (int) ( $effective_limits['max_message_length'] ?? AI_Ecommerce_Agent_API::PUBLIC_MAX_MESSAGE_LENGTH );
				?>
				<form id="aiea-chat-form" class="aiea-chat-form" novalidate>
					<label for="aiea-chat-input" class="aiea-sr-only"><?php esc_html_e( 'Escribe tu mensaje', 'ai-ecommerce-agent' ); ?></label>
					<textarea
						id="aiea-chat-input"
						class="aiea-chat-input"
						rows="1"
						maxlength="<?php echo esc_attr( (string) $max_length ); ?>"
						placeholder="<?php echo esc_attr( $placeholder ); ?>"
					></textarea>
					<button
						type="submit"
						id="aiea-chat-send"
						class="aiea-chat-send<?php echo '' !== $send_text ? ' aiea-chat-send--with-text' : ''; ?>"
						aria-label="<?php echo esc_attr( '' !== $send_text ? $send_text : __( 'Enviar mensaje', 'ai-ecommerce-agent' ) ); ?>"
					>
						<svg class="aiea-chat-send-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
							<path fill="currentColor" d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
						</svg>
						<?php if ( '' !== $send_text ) : ?>
							<span class="aiea-chat-send-label"><?php echo esc_html( $send_text ); ?></span>
						<?php endif; ?>
					</button>
				</form>
			</div>

			<button
				type="button"
				id="aiea-chat-toggle"
				class="aiea-chat-toggle"
				aria-expanded="false"
				aria-controls="aiea-chat-window"
				aria-label="<?php esc_attr_e( 'Abrir chat de asistencia', 'ai-ecommerce-agent' ); ?>"
			>
				<svg class="aiea-chat-toggle-icon aiea-chat-toggle-icon--open" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">
					<path fill="currentColor" d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H5.17L4 17.17V4h16v12z"/>
				</svg>
				<span class="aiea-chat-toggle-label"><?php echo esc_html( $toggle_text ); ?></span>
			</button>
		</div>
		<?php
	}
}
