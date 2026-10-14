<?php
/**
 * AI Ecommerce Agent — Integración con WooCommerce.
 *
 * Proporciona herramientas para consultar datos reales de la tienda.
 * Este archivo no realiza ninguna llamada a APIs externas.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Ecommerce_Agent_Woo {

	/**
	 * Número máximo de resultados permitido por consulta.
	 */
	const MAX_RESULTS = 10;

	/**
	 * Cantidad máxima de unidades permitida por operación de añadir al carrito.
	 */
	const MAX_CART_QUANTITY = 20;

	/**
	 * Busca productos publicados en WooCommerce con soporte de paginación y metadatos.
	 *
	 * Devuelve un array con metadatos (total, página, páginas, hay_más) y productos normalizados,
	 * listo para ser serializado a JSON y enviado a la Responses API de OpenAI.
	 *
	 * @param string $term  Término de búsqueda (nombre, descripción, categoría). Ya sanitizado.
	 * @param int    $limit Número máximo de resultados por página (1–10).
	 * @param int    $page  Número de página (1 en adelante).
	 * @return array Array estructurado con metadatos y lista de productos.
	 */
	public static function buscar_productos( string $term, int $limit = 5, int $page = 1 ): array {

		// --- Guardia: WooCommerce debe estar activo ---
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_products' ) ) {
			return [
				'error'     => 'WooCommerce no está disponible en esta instalación.',
				'total'     => 0,
				'pagina'    => 1,
				'paginas'   => 0,
				'hay_mas'   => false,
				'productos' => [],
			];
		}

		// --- Sanitización y límites ---
		$term  = trim( sanitize_text_field( $term ) );
		$limit = max( 1, min( self::MAX_RESULTS, (int) $limit ) );
		$page  = max( 1, (int) $page );

		$query_args = [
			'status'   => 'publish',
			'limit'    => $limit,
			'page'     => $page,
			'paginate' => true,
		];

		// Mejora 1: Detección robusta de consultas generales del catálogo.
		if ( self::is_generic_query( $term ) ) {
			$raw_result = wc_get_products( $query_args );
			$extracted  = self::extract_query_results( $raw_result, $limit );

			return [
				'total'     => $extracted['total'],
				'pagina'    => $page,
				'paginas'   => $extracted['max_pages'],
				'hay_mas'   => $page < $extracted['max_pages'],
				'productos' => self::normalize_products( $extracted['products'] ),
			];
		}

		// Búsqueda textual específica por término.
		$query_args['s'] = $term;
		$raw_result      = wc_get_products( $query_args );
		$extracted       = self::extract_query_results( $raw_result, $limit );

		// Mejora 3: Si no hay resultados textuales, intentar búsqueda por categoría (product_cat).
		if ( empty( $extracted['products'] ) ) {
			$extracted = self::buscar_por_categoria( $term, $limit, $page );
		}

		return [
			'total'     => $extracted['total'],
			'pagina'    => $page,
			'paginas'   => $extracted['max_pages'],
			'hay_mas'   => $page < $extracted['max_pages'],
			'productos' => self::normalize_products( $extracted['products'] ),
		];
	}

	/**
	 * Detecta si una consulta corresponde a una intención general de consultar el catálogo.
	 *
	 * @param string $term Término de búsqueda.
	 * @return bool True si es consulta general, false si es específica.
	 */
	private static function is_generic_query( string $term ): bool {
		$raw = trim( $term );
		if ( '' === $raw || '*' === $raw ) {
			return true;
		}

		if ( function_exists( 'mb_strtolower' ) ) {
			$normalized = mb_strtolower( $raw, 'UTF-8' );
		} else {
			$normalized = strtolower( $raw );
		}

		// Eliminar signos de puntuación e interrogaciones.
		$normalized = preg_replace( '/[¿?¡!.,;:_\-\/]/u', ' ', $normalized );

		// Normalizar acentos y caracteres especiales comunes.
		$normalized = strtr(
			$normalized,
			[
				'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
				'ü' => 'u', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
			]
		);

		// Colapsar espacios múltiples.
		$normalized = trim( preg_replace( '/\s+/u', ' ', $normalized ) );

		if ( '' === $normalized ) {
			return true;
		}

		$generic_phrases = [
			'todos',
			'todo',
			'todos los productos',
			'todos los articulos',
			'todo el catalogo',
			'catalogo',
			'catalogo general',
			'catalogo completo',
			'catalogo de la tienda',
			'ver catalogo',
			'ver el catalogo',
			'mostrar catalogo',
			'mostrar el catalogo',
			'productos',
			'productos disponibles',
			'productos en stock',
			'productos de la tienda',
			'productos en la tienda',
			'mostrar productos',
			'muestra productos',
			'muestra los productos',
			'ver productos',
			'ver los productos',
			'lista de productos',
			'listado de productos',
			'que productos tienen',
			'que productos hay',
			'que tienen',
			'que tienen disponible',
			'que tienen disponibles',
			'que hay disponible',
			'que hay disponibles',
			'que venden',
			'cuales productos tienen',
			'tienda',
			'disponibles',
			'disponible',
			'articulos',
			'articulos disponibles',
		];

		if ( in_array( $normalized, $generic_phrases, true ) ) {
			return true;
		}

		// Patrones de consulta general en lenguaje natural.
		$patterns = [
			'/^(mostrar|muestra|ver|listar|dame|consultar|consulta)\s+(todos\s+los\s+|el\s+|los\s+)?(productos|catalogo|articulos)(\s+(disponibles?|de\s+la\s+tienda|en\s+la\s+tienda|en\s+stock|general|completo))?$/u',
			'/^(que|cuales)\s+(productos|articulos)?\s*(tienen|hay|venden)(\s+(en\s+la\s+tienda|disponibles?|en\s+stock))?$/u',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $normalized ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Busca productos por categoría de WooCommerce (product_cat) cuando la búsqueda textual no da resultados.
	 *
	 * @param string $term  Término de búsqueda sanitizado.
	 * @param int    $limit Límite de resultados por página.
	 * @param int    $page  Número de página.
	 * @return array array( 'products' => array, 'total' => int, 'max_pages' => int )
	 */
	private static function buscar_por_categoria( string $term, int $limit, int $page ): array {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return [ 'products' => [], 'total' => 0, 'max_pages' => 0 ];
		}

		$category  = null;
		$term_slug = sanitize_title( $term );

		// 1. Coincidencia por slug (ej. "aminoacidos" para slug "aminoacidos").
		if ( '' !== $term_slug ) {
			$category = get_term_by( 'slug', $term_slug, 'product_cat' );
		}

		// 2. Coincidencia por nombre exacto si no coincidió el slug.
		if ( ! $category instanceof WP_Term ) {
			$category = get_term_by( 'name', $term, 'product_cat' );
		}

		// 3. Coincidencia por búsqueda parcial en términos de la taxonomía.
		if ( ! $category instanceof WP_Term && function_exists( 'get_terms' ) ) {
			$found_terms = get_terms( [
				'taxonomy'   => 'product_cat',
				'search'     => $term,
				'hide_empty' => false,
				'number'     => 1,
			] );

			if ( ! empty( $found_terms ) && ! is_wp_error( $found_terms ) && isset( $found_terms[0] ) && $found_terms[0] instanceof WP_Term ) {
				$category = $found_terms[0];
			}
		}

		// Si no existe una categoría coincidente real, no inventar ni hacer fallback al catálogo general.
		if ( ! $category instanceof WP_Term ) {
			return [ 'products' => [], 'total' => 0, 'max_pages' => 0 ];
		}

		// Consultar productos publicados de la categoría encontrada con paginación.
		$cat_query_args = [
			'status'   => 'publish',
			'limit'    => $limit,
			'page'     => $page,
			'paginate' => true,
			'category' => [ $category->slug ],
		];

		$raw_result = wc_get_products( $cat_query_args );

		return self::extract_query_results( $raw_result, $limit );
	}

	/**
	 * Extrae productos, total y número de páginas de un resultado de wc_get_products().
	 *
	 * Soporta tanto el retorno como objeto stdClass (paginate=>true) como array directo.
	 *
	 * @param mixed $query_result Objeto stdClass o array de WC_Product.
	 * @param int   $limit        Límite por página.
	 * @return array array( 'products' => array, 'total' => int, 'max_pages' => int )
	 */
	private static function extract_query_results( $query_result, int $limit ): array {
		if ( is_object( $query_result ) && isset( $query_result->products ) ) {
			$products  = is_array( $query_result->products ) ? $query_result->products : [];
			$total     = isset( $query_result->total ) ? (int) $query_result->total : count( $products );
			$max_pages = isset( $query_result->max_num_pages )
				? (int) $query_result->max_num_pages
				: ( $limit > 0 ? (int) ceil( $total / $limit ) : 1 );
		} elseif ( is_array( $query_result ) ) {
			$products  = $query_result;
			$total     = count( $products );
			$max_pages = 1;
		} else {
			$products  = [];
			$total     = 0;
			$max_pages = 0;
		}

		return [
			'products'  => $products,
			'total'     => $total,
			'max_pages' => $max_pages,
		];
	}

	/**
	 * Normaliza una lista de objetos WC_Product a un array estructurado para OpenAI.
	 *
	 * @param array $products Array de objetos WC_Product.
	 * @return array[]         Array de productos normalizados.
	 */
	private static function normalize_products( array $products ): array {
		$results  = [];
		$seen_ids = [];

		foreach ( $products as $product ) {

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$id = $product->get_id();
			if ( isset( $seen_ids[ $id ] ) ) {
				continue;
			}
			$seen_ids[ $id ] = true;

			// Precio formateado con el símbolo de moneda de la tienda, sin HTML.
			$price_raw  = $product->get_price();
			$price_text = html_entity_decode( wp_strip_all_tags( $product->get_price_html() ), ENT_QUOTES, 'UTF-8' );
			if ( '' === $price_text ) {
				$price_text = '' !== (string) $price_raw
					? html_entity_decode( wp_strip_all_tags( wc_price( $price_raw ) ), ENT_QUOTES, 'UTF-8' )
					: 'Precio no disponible';
			}

			// Descripción corta acotada (máx 35 palabras). Si está vacía, usar las primeras 30 palabras de la descripción completa.
			$raw_short   = wp_strip_all_tags( $product->get_short_description() );
			$description = function_exists( 'wp_trim_words' ) ? wp_trim_words( $raw_short, 35, '…' ) : $raw_short;
			if ( '' === $description ) {
				$raw_full    = wp_strip_all_tags( $product->get_description() );
				$description = function_exists( 'wp_trim_words' ) ? wp_trim_words( $raw_full, 30, '…' ) : $raw_full;
			}

			// URL de la imagen en tamaño thumbnail.
			$image_id  = $product->get_image_id();
			$image_url = $image_id
				? (string) wp_get_attachment_image_url( $image_id, 'thumbnail' )
				: '';

			$item = [
				'id'          => $id,
				'tipo'        => $product->get_type(),
				'nombre'      => $product->get_name(),
				'precio'      => $price_text,
				'descripcion' => '' !== $description ? $description : 'Sin descripción.',
				'disponible'  => $product->is_in_stock() && $product->is_purchasable(),
				'url'         => (string) get_permalink( $id ),
				'imagen'      => $image_url,
			];

			if ( $product instanceof WC_Product_Variable ) {
				$item['atributos_obligatorios'] = self::get_variable_attributes_info( $product );
				$item['variaciones']            = self::get_product_variations_summary( $product );
			}

			$results[] = $item;
		}

		return $results;
	}

	/**
	 * Alias en código sin tilde para invocar añadir_al_carrito().
	 *
	 * @param mixed $product_id   ID del producto.
	 * @param mixed $quantity     Cantidad solicitada.
	 * @param mixed $variation_id ID de la variación (opcional).
	 * @param array $attributes   Atributos de variación seleccionados (opcional).
	 * @return array Resultado estructurado de la operación.
	 */
	public static function anadir_al_carrito( $product_id, $quantity = 1, $variation_id = 0, array $attributes = [] ): array {
		return self::añadir_al_carrito( $product_id, $quantity, $variation_id, $attributes );
	}

	/**
	 * Añade un producto o variación verificada al carrito real de WooCommerce.
	 *
	 * Realiza todas las validaciones obligatorias de existencia, publicación,
	 * disponibilidad, inventario y variaciones antes de ejecutar add_to_cart().
	 *
	 * @param mixed $product_id   ID del producto en WooCommerce.
	 * @param mixed $quantity     Cantidad solicitada (entero positivo).
	 * @param mixed $variation_id ID de variación (cuando corresponda).
	 * @param array $attributes   Atributos de variación seleccionados.
	 * @return array Resultado estructurado indicando éxito o motivo del rechazo.
	 */
	public static function añadir_al_carrito( $product_id, $quantity = 1, $variation_id = 0, array $attributes = [] ): array {
		// 1. Verificar disponibilidad de WooCommerce y cargar sesión/carrito.
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			return [
				'exito'   => false,
				'codigo'  => 'woocommerce_no_disponible',
				'mensaje' => 'WooCommerce no está disponible en esta instalación.',
			];
		}

		if ( ! self::ensure_cart_loaded() ) {
			return [
				'exito'   => false,
				'codigo'  => 'carrito_no_disponible',
				'mensaje' => 'No fue posible inicializar el carrito de compras en este momento.',
			];
		}

		// 2. Validar que la cantidad sea un entero positivo y razonable.
		if ( ! is_numeric( $quantity ) || (int) $quantity != $quantity || (int) $quantity < 1 ) {
			return [
				'exito'   => false,
				'codigo'  => 'cantidad_invalida',
				'mensaje' => 'La cantidad solicitada no es válida. Debe ser un número entero mayor o igual a 1.',
			];
		}

		$quantity = (int) $quantity;
		if ( $quantity > self::MAX_CART_QUANTITY ) {
			return [
				'exito'           => false,
				'codigo'          => 'cantidad_excesiva',
				'maximo_permitido'=> self::MAX_CART_QUANTITY,
				'mensaje'         => sprintf( 'La cantidad máxima permitida por operación es de %d unidades.', self::MAX_CART_QUANTITY ),
			];
		}

		// 3. Validar existencia y estado del producto.
		if ( ! is_numeric( $product_id ) || (int) $product_id <= 0 ) {
			return [
				'exito'   => false,
				'codigo'  => 'producto_invalido',
				'mensaje' => 'El identificador del producto no es válido.',
			];
		}

		$product_id   = (int) $product_id;
		$variation_id = is_numeric( $variation_id ) && (int) $variation_id > 0 ? (int) $variation_id : 0;
		$product      = wc_get_product( $product_id );

		// Si el modelo envió el ID de una variación en product_id sin variation_id, resolver su producto padre para validarlo íntegramente.
		if ( $product instanceof WC_Product_Variation && 0 === $variation_id ) {
			$variation_id = $product->get_id();
			$product_id   = $product->get_parent_id();
			$product      = wc_get_product( $product_id );
		}

		if ( ! $product instanceof WC_Product ) {
			return [
				'exito'   => false,
				'codigo'  => 'producto_inexistente',
				'mensaje' => 'El producto solicitado no existe en el catálogo de la tienda.',
			];
		}

		if ( 'publish' !== $product->get_status() ) {
			return [
				'exito'   => false,
				'codigo'  => 'producto_no_publicado',
				'mensaje' => 'El producto solicitado no está disponible para la venta.',
			];
		}

		$product_type = $product->get_type();
		if ( ! in_array( $product_type, [ 'simple', 'variable' ], true ) ) {
			return [
				'exito'   => false,
				'codigo'  => 'tipo_producto_no_soportado',
				'mensaje' => 'Este tipo de producto no se puede añadir directamente desde el chat.',
			];
		}

		$resolved_variation_id    = 0;
		$resolved_variation_attrs = [];
		$target_product           = $product;

		// 4. Validación de productos simples frente a variaciones espurias.
		if ( 'simple' === $product_type ) {
			if ( $variation_id > 0 ) {
				return [
					'exito'   => false,
					'codigo'  => 'variacion_inexistente',
					'mensaje' => sprintf( 'El producto "%s" es un producto simple y no tiene variaciones.', $product->get_name() ),
				];
			}
		}

		// 5. Validación estricta de productos variables y sus atributos obligatorios.
		if ( $product instanceof WC_Product_Variable ) {
			$variation_check = self::resolve_and_validate_variation( $product, $variation_id, $attributes );
			if ( empty( $variation_check['ok'] ) ) {
				return $variation_check['error'];
			}

			$target_product           = $variation_check['variation'];
			$resolved_variation_id    = (int) $target_product->get_id();
			$resolved_variation_attrs = $variation_check['attributes'];
		}

		// 6. Verificar que WooCommerce permite comprar el producto/variación y que tiene stock.
		if ( ! $product->is_purchasable() || ! $target_product->is_purchasable() ) {
			return [
				'exito'   => false,
				'codigo'  => 'producto_no_comprable',
				'mensaje' => sprintf( 'El producto "%s" no está habilitado para la compra en este momento.', $target_product->get_name() ),
			];
		}

		if ( ! $target_product->is_in_stock() ) {
			return [
				'exito'        => false,
				'codigo'       => 'producto_agotado',
				'mensaje'      => sprintf( 'El producto "%s" está agotado actualmente y no se puede añadir al carrito.', $target_product->get_name() ),
				'url_producto' => (string) get_permalink( $product->get_id() ),
			];
		}

		// 7. Validar compra individual y límites de cantidad del producto.
		$in_cart_qty = self::get_quantity_in_cart( $product->get_id(), $resolved_variation_id, $target_product );

		if ( $target_product->is_sold_individually() && ( $quantity > 1 || $in_cart_qty >= 1 ) ) {
			return [
				'exito'   => false,
				'codigo'  => 'venta_individual',
				'mensaje' => sprintf( 'Solo se puede comprar 1 unidad de "%s" por pedido.', $target_product->get_name() ),
			];
		}

		$min_qty = max( 1, (int) $target_product->get_min_purchase_quantity() );
		if ( $quantity < $min_qty ) {
			return [
				'exito'   => false,
				'codigo'  => 'cantidad_menor_al_minimo',
				'mensaje' => sprintf( 'La cantidad mínima de compra para "%s" es de %d unidades.', $target_product->get_name(), $min_qty ),
			];
		}

		$max_qty = (int) $target_product->get_max_purchase_quantity();
		if ( $max_qty > 0 && ( $in_cart_qty + $quantity ) > $max_qty ) {
			$remaining = max( 0, $max_qty - $in_cart_qty );
			return [
				'exito'            => false,
				'codigo'           => 'stock_insuficiente',
				'stock_disponible' => $remaining,
				'en_carrito'       => $in_cart_qty,
				'mensaje'          => sprintf(
					'No hay suficientes unidades disponibles de "%s". Disponibles para añadir: %d (ya tienes %d en el carrito).',
					$target_product->get_name(),
					$remaining,
					$in_cart_qty
				),
			];
		}

		if ( $target_product->managing_stock() && ! $target_product->backorders_allowed() ) {
			$stock_qty = (int) $target_product->get_stock_quantity();
			if ( ! $target_product->has_enough_stock( $in_cart_qty + $quantity ) ) {
				$remaining = max( 0, $stock_qty - $in_cart_qty );
				return [
					'exito'            => false,
					'codigo'           => 'stock_insuficiente',
					'stock_disponible' => $remaining,
					'en_carrito'       => $in_cart_qty,
					'mensaje'          => sprintf(
						'Stock insuficiente para "%s". Unidades disponibles: %d (en tu carrito: %d).',
						$target_product->get_name(),
						$remaining,
						$in_cart_qty
					),
				];
			}
		}

		// 8. Ejecutar la operación en el carrito oficial de WooCommerce.
		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}

		try {
			$cart_item_key = WC()->cart->add_to_cart(
				$product->get_id(),
				$quantity,
				$resolved_variation_id,
				$resolved_variation_attrs
			);
		} catch ( Throwable $e ) {
			$cart_item_key = false;
		}

		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}

		if ( false === $cart_item_key || '' === (string) $cart_item_key ) {
			return [
				'exito'   => false,
				'codigo'  => 'error_al_anadir',
				'mensaje' => sprintf( 'WooCommerce no pudo añadir "%s" al carrito. Verifica la disponibilidad e inténtalo de nuevo.', $target_product->get_name() ),
			];
		}

		// 9. Persistir la sesión y las cookies del carrito para el visitante actual.
		self::persist_cart_session();

		$unit_price    = html_entity_decode( wp_strip_all_tags( wc_price( $target_product->get_price() ) ), ENT_QUOTES, 'UTF-8' );
		$cart_subtotal = html_entity_decode( wp_strip_all_tags( WC()->cart->get_cart_subtotal() ), ENT_QUOTES, 'UTF-8' );

		return [
			'exito'               => true,
			'codigo'              => 'producto_anadido',
			'mensaje'             => 'El producto fue añadido correctamente al carrito de WooCommerce.',
			'producto_id'         => $product->get_id(),
			'variacion_id'        => $resolved_variation_id,
			'nombre'              => $target_product->get_name(),
			'atributos'           => $resolved_variation_attrs,
			'cantidad_anadida'    => $quantity,
			'precio_unitario'     => $unit_price,
			'total_items_carrito' => (int) WC()->cart->get_cart_contents_count(),
			'subtotal_carrito'    => $cart_subtotal,
			'url_carrito'         => function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : '',
			'url_checkout'        => function_exists( 'wc_get_checkout_url' ) ? (string) wc_get_checkout_url() : '',
			'url_producto'        => (string) get_permalink( $product->get_id() ),
		];
	}

	/**
	 * Asegura que la sesión y el carrito de WooCommerce estén cargados en peticiones REST.
	 *
	 * @return bool True si WC()->cart está disponible.
	 */
	private static function ensure_cart_loaded(): bool {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return false;
		}

		if ( ( ! isset( WC()->cart ) || null === WC()->cart ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( property_exists( WC(), 'session' ) && null === WC()->session && method_exists( WC(), 'initialize_session' ) ) {
			WC()->initialize_session();
		}

		if ( property_exists( WC(), 'cart' ) && null === WC()->cart && method_exists( WC(), 'initialize_cart' ) ) {
			WC()->initialize_cart();
		}

		if ( isset( WC()->cart ) && WC()->cart instanceof WC_Cart ) {
			if ( method_exists( WC()->cart, 'get_cart' ) ) {
				WC()->cart->get_cart();
			}
			return true;
		}

		return false;
	}

	/**
	 * Persiste los totales, la sesión de cliente y las cookies del carrito de WooCommerce.
	 *
	 * @return void
	 */
	private static function persist_cart_session(): void {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart instanceof WC_Cart ) {
			return;
		}

		if ( WC()->session && method_exists( WC()->session, 'has_session' ) && ! WC()->session->has_session() ) {
			if ( method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
				WC()->session->set_customer_session_cookie( true );
			}
		}

		WC()->cart->calculate_totals();

		if ( method_exists( WC()->cart, 'set_session' ) ) {
			WC()->cart->set_session();
		}

		if ( method_exists( WC()->cart, 'maybe_set_cart_cookies' ) ) {
			WC()->cart->maybe_set_cart_cookies();
		}

		if ( WC()->session && method_exists( WC()->session, 'save_data' ) ) {
			WC()->session->save_data();
		}
	}

	/**
	 * Obtiene cuántas unidades de un producto o variación están ya en el carrito actual.
	 *
	 * @param int        $product_id   ID del producto padre.
	 * @param int        $variation_id ID de la variación (0 si es simple).
	 * @param WC_Product $target       Producto o variación objetivo.
	 * @return int Cantidad actual en el carrito.
	 */
	private static function get_quantity_in_cart( int $product_id, int $variation_id, WC_Product $target ): int {
		if ( ! WC()->cart instanceof WC_Cart ) {
			return 0;
		}

		$managed_id = method_exists( $target, 'get_stock_managed_by_id' ) ? (int) $target->get_stock_managed_by_id() : (int) $target->get_id();
		$count      = 0;

		foreach ( WC()->cart->get_cart() as $item ) {
			$item_prod_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$item_var_id  = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
			$item_qty     = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;

			if ( isset( $item['data'] ) && $item['data'] instanceof WC_Product ) {
				$item_managed_id = method_exists( $item['data'], 'get_stock_managed_by_id' ) ? (int) $item['data']->get_stock_managed_by_id() : (int) $item['data']->get_id();
				if ( $item_managed_id === $managed_id ) {
					$count += $item_qty;
					continue;
				}
			}

			if ( $variation_id > 0 && $item_var_id === $variation_id ) {
				$count += $item_qty;
			} elseif ( 0 === $variation_id && $item_prod_id === $product_id ) {
				$count += $item_qty;
			}
		}

		return $count;
	}

	/**
	 * Devuelve la lista de atributos de variación obligatorios y sus opciones legibles para un producto variable.
	 *
	 * @param WC_Product_Variable $product Producto variable.
	 * @return array
	 */
	private static function get_variable_attributes_info( WC_Product_Variable $product ): array {
		$variation_attrs = $product->get_variation_attributes();
		$info            = [];

		foreach ( $variation_attrs as $attr_name => $options ) {
			$label   = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $attr_name, $product ) : ucfirst( str_replace( [ 'pa_', '-' ], [ '', ' ' ], $attr_name ) );
			$choices = [];

			if ( is_array( $options ) ) {
				foreach ( $options as $opt ) {
					$opt_str = (string) $opt;
					if ( taxonomy_exists( $attr_name ) ) {
						$term = get_term_by( 'slug', $opt_str, $attr_name );
						if ( $term instanceof WP_Term ) {
							$choices[] = $term->name;
							continue;
						}
					}
					$choices[] = $opt_str;
				}
			}

			$info[] = [
				'atributo' => $attr_name,
				'etiqueta' => $label,
				'opciones' => $choices,
			];
		}

		return $info;
	}

	/**
	 * Devuelve el resumen de variaciones hijas publicadas de un producto variable.
	 *
	 * @param WC_Product_Variable $product Producto variable.
	 * @return array
	 */
	private static function get_product_variations_summary( WC_Product_Variable $product ): array {
		$children  = $product->get_children();
		$summaries = [];

		foreach ( $children as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof WC_Product_Variation || 'publish' !== $variation->get_status() ) {
				continue;
			}

			$raw_attrs      = method_exists( $variation, 'get_variation_attributes' ) ? $variation->get_variation_attributes() : ( method_exists( $variation, 'get_attributes' ) ? $variation->get_attributes() : [] );
			$readable_attrs = [];

			foreach ( $raw_attrs as $attr_key => $attr_val ) {
				$taxonomy = preg_replace( '/^attribute_/', '', (string) $attr_key );
				$label    = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $taxonomy, $product ) : ucfirst( str_replace( [ 'pa_', '-' ], [ '', ' ' ], $taxonomy ) );
				$val_str  = (string) $attr_val;

				if ( '' !== $val_str && taxonomy_exists( $taxonomy ) ) {
					$term = get_term_by( 'slug', $val_str, $taxonomy );
					if ( $term instanceof WP_Term ) {
						$val_str = $term->name;
					}
				}

				$readable_attrs[ $label ] = '' !== $val_str ? $val_str : 'Cualquiera';
			}

			$price_raw  = $variation->get_price();
			$price_text = '' !== (string) $price_raw
				? html_entity_decode( wp_strip_all_tags( wc_price( $price_raw ) ), ENT_QUOTES, 'UTF-8' )
				: 'Precio no disponible';

			$summaries[] = [
				'variation_id' => (int) $variation->get_id(),
				'atributos'    => $readable_attrs,
				'precio'       => $price_text,
				'disponible'   => $variation->is_in_stock() && $variation->is_purchasable(),
			];
		}

		return $summaries;
	}

	/**
	 * Valida y resuelve la variación y atributos obligatorios para un producto variable.
	 *
	 * Impide elegir una variación arbitrariamente cuando el cliente no ha especificado
	 * todas las opciones obligatorias y verifica que la variación pertenezca al producto.
	 *
	 * @param WC_Product_Variable $product      Producto padre variable.
	 * @param int                 $variation_id ID de variación proporcionado (0 si no se indicó).
	 * @param array               $attributes   Atributos proporcionados por el modelo.
	 * @return array array( 'ok' => bool, 'variation' => WC_Product_Variation, 'attributes' => array, 'error' => array )
	 */
	private static function resolve_and_validate_variation( WC_Product_Variable $product, int $variation_id, array $attributes ): array {
		$parent_attrs = $product->get_variation_attributes();
		$attr_info    = self::get_variable_attributes_info( $product );
		$var_summary  = self::get_product_variations_summary( $product );

		// Normalizar los atributos recibidos tratando de mapear cada uno a los atributos reales del producto padre.
		$mapped_attrs = self::map_input_attributes_to_product( $product, $parent_attrs, $attributes );

		// Si había atributos enviados por el modelo pero alguno no corresponde a ninguna opción existente en el producto, rechazar como variación inexistente.
		if ( ! empty( $mapped_attrs['invalid'] ) ) {
			return [
				'ok'    => false,
				'error' => [
					'exito'                   => false,
					'codigo'                  => 'variacion_inexistente',
					'mensaje'                 => sprintf(
						'La opción o variación solicitada (%s) no existe para "%s".',
						implode( ', ', $mapped_attrs['invalid'] ),
						$product->get_name()
					),
					'atributos_obligatorios'  => $attr_info,
					'variaciones_disponibles' => $var_summary,
				],
			];
		}

		$provided_attrs = $mapped_attrs['valid'];

		// Si no se especificó ni variation_id ni todos los atributos obligatorios, exigir preguntar al cliente.
		if ( $variation_id <= 0 && count( $provided_attrs ) < count( $parent_attrs ) ) {
			return [
				'ok'    => false,
				'error' => [
					'exito'                   => false,
					'codigo'                  => 'requiere_seleccion_variacion',
					'requiere_aclaracion'     => true,
					'mensaje'                 => sprintf(
						'El producto "%s" es variable. Antes de añadirlo al carrito, debes preguntar al cliente qué opción prefiere.',
						$product->get_name()
					),
					'atributos_obligatorios'  => $attr_info,
					'variaciones_disponibles' => $var_summary,
				],
			];
		}

		// Caso A: Se proporcionó variation_id. Verificar que exista, sea variación y pertenezca al producto.
		if ( $variation_id > 0 ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product_Variation ) {
				return [
					'ok'    => false,
					'error' => [
						'exito'                   => false,
						'codigo'                  => 'variacion_inexistente',
						'mensaje'                 => 'La variación solicitada no existe.',
						'variaciones_disponibles' => $var_summary,
					],
				];
			}

			if ( (int) $variation->get_parent_id() !== (int) $product->get_id() ) {
				return [
					'ok'    => false,
					'error' => [
						'exito'   => false,
						'codigo'  => 'variacion_no_pertenece',
						'mensaje' => sprintf( 'La variación indicada no pertenece al producto "%s".', $product->get_name() ),
					],
				];
			}

			if ( 'publish' !== $variation->get_status() || ( method_exists( $variation, 'variation_is_visible' ) && ! $variation->variation_is_visible() ) ) {
				return [
					'ok'    => false,
					'error' => [
						'exito'   => false,
						'codigo'  => 'variacion_no_disponible',
						'mensaje' => 'La variación solicitada no está disponible para la venta.',
					],
				];
			}

			$var_attrs      = $variation->get_variation_attributes();
			$final_attrs    = [];

			foreach ( $var_attrs as $attr_key => $attr_val ) {
				$val_str = (string) $attr_val;

				// Si el modelo envió también atributos explícitos, comprobar que coincidan con los de la variación.
				if ( '' !== $val_str && isset( $provided_attrs[ $attr_key ] ) ) {
					if ( self::normalize_compare( $provided_attrs[ $attr_key ] ) !== self::normalize_compare( $val_str ) ) {
						return [
							'ok'    => false,
							'error' => [
								'exito'                   => false,
								'codigo'                  => 'variacion_inexistente',
								'mensaje'                 => 'Los atributos solicitados no coinciden con la variación indicada.',
								'variaciones_disponibles' => $var_summary,
							],
						];
					}
				}

				if ( '' === $val_str ) {
					// La variación admite "Cualquier valor" en este atributo: el cliente debe haberlo especificado.
					if ( empty( $provided_attrs[ $attr_key ] ) ) {
						return [
							'ok'    => false,
							'error' => [
								'exito'                   => false,
								'codigo'                  => 'requiere_seleccion_variacion',
								'requiere_aclaracion'     => true,
								'mensaje'                 => 'Falta especificar un atributo obligatorio de la variación. Pregunta al cliente cuál prefiere.',
								'atributos_obligatorios'  => $attr_info,
								'variaciones_disponibles' => $var_summary,
							],
						];
					}
					$final_attrs[ $attr_key ] = $provided_attrs[ $attr_key ];
				} else {
					$final_attrs[ $attr_key ] = $val_str;
				}
			}

			return [
				'ok'         => true,
				'variation'  => $variation,
				'attributes' => $final_attrs,
			];
		}

		// Caso B: No se indicó variation_id pero sí todos los atributos obligatorios; buscar la variación hija que coincida.
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof WC_Product_Variation || 'publish' !== $variation->get_status() || ( method_exists( $variation, 'variation_is_visible' ) && ! $variation->variation_is_visible() ) ) {
				continue;
			}

			$var_attrs   = $variation->get_variation_attributes();
			$matches_all = true;
			$final_attrs = [];

			foreach ( $var_attrs as $attr_key => $attr_val ) {
				$val_str = (string) $attr_val;
				if ( ! isset( $provided_attrs[ $attr_key ] ) ) {
					$matches_all = false;
					break;
				}

				if ( '' !== $val_str && self::normalize_compare( $val_str ) !== self::normalize_compare( $provided_attrs[ $attr_key ] ) ) {
					$matches_all = false;
					break;
				}

				$final_attrs[ $attr_key ] = '' !== $val_str ? $val_str : $provided_attrs[ $attr_key ];
			}

			if ( $matches_all ) {
				return [
					'ok'         => true,
					'variation'  => $variation,
					'attributes' => $final_attrs,
				];
			}
		}

		return [
			'ok'    => false,
			'error' => [
				'exito'                   => false,
				'codigo'                  => 'variacion_inexistente',
				'mensaje'                 => sprintf( 'No existe una variación disponible con las opciones solicitadas para "%s".', $product->get_name() ),
				'atributos_obligatorios'  => $attr_info,
				'variaciones_disponibles' => $var_summary,
			],
		];
	}

	/**
	 * Mapea los atributos enviados por el modelo a las claves oficiales `attribute_<slug>` y valores reales de WooCommerce.
	 *
	 * @param WC_Product_Variable $product      Producto variable.
	 * @param array               $parent_attrs Retorno de $product->get_variation_attributes().
	 * @param array               $raw_input    Atributos recibidos.
	 * @return array array( 'valid' => array, 'invalid' => array )
	 */
	private static function map_input_attributes_to_product( WC_Product_Variable $product, array $parent_attrs, array $raw_input ): array {
		$valid   = [];
		$invalid = [];

		if ( empty( $raw_input ) ) {
			return [ 'valid' => [], 'invalid' => [] ];
		}

		foreach ( $raw_input as $raw_key => $raw_val ) {
			if ( ! is_scalar( $raw_val ) ) {
				continue;
			}
			$val_str = trim( sanitize_text_field( (string) $raw_val ) );
			if ( '' === $val_str ) {
				continue;
			}

			$key_str        = trim( sanitize_text_field( (string) $raw_key ) );
			$clean_key      = preg_replace( '/^attribute_/', '', $key_str );
			$norm_input_key = self::normalize_compare( $clean_key );
			$norm_input_val = self::normalize_compare( $val_str );

			$matched_attr_key = null;
			$matched_opt_val  = null;

			foreach ( $parent_attrs as $attr_name => $options ) {
				$label          = wc_attribute_label( $attr_name, $product );
				$bare_attr      = preg_replace( '/^pa_/', '', (string) $attr_name );
				$key_Matches    = (
					self::normalize_compare( (string) $attr_name ) === $norm_input_key
					|| self::normalize_compare( $bare_attr ) === $norm_input_key
					|| self::normalize_compare( $label ) === $norm_input_key
				);

				if ( ! $key_Matches && count( $parent_attrs ) > 1 ) {
					continue;
				}

				if ( is_array( $options ) ) {
					foreach ( $options as $opt ) {
						$opt_str  = (string) $opt;
						$opt_name = $opt_str;

						if ( taxonomy_exists( $attr_name ) ) {
							$term = get_term_by( 'slug', $opt_str, $attr_name );
							if ( $term instanceof WP_Term ) {
								$opt_name = $term->name;
							}
						}

						if (
							self::normalize_compare( $opt_str ) === $norm_input_val
							|| self::normalize_compare( $opt_name ) === $norm_input_val
						) {
							$matched_attr_key = 'attribute_' . sanitize_title( $attr_name );
							$matched_opt_val  = $opt_str;
							break 2;
						}
					}
				}
			}

			if ( null !== $matched_attr_key && null !== $matched_opt_val ) {
				$valid[ $matched_attr_key ] = $matched_opt_val;
			} else {
				$invalid[] = $key_str . ': ' . $val_str;
			}
		}

		return [
			'valid'   => $valid,
			'invalid' => $invalid,
		];
	}

	/**
	 * Normaliza una cadena para comparaciones insensibles a mayúsculas y acentos.
	 *
	 * @param string $value Cadena de entrada.
	 * @return string
	 */
	private static function normalize_compare( string $value ): string {
		$value = trim( $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = strtr(
			$value,
			[
				'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
				'ü' => 'u', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
			]
		);

		return trim( preg_replace( '/\s+/u', ' ', $value ) );
	}

	/**
	 * Consulta en modo de solo lectura los métodos de pago habilitados y disponibles en WooCommerce.
	 *
	 * No considera suficiente que un plugin de pasarela esté instalado: verifica que la pasarela
	 * tenga `enabled === 'yes'`, que `$gateway->is_available()` sea verdadero en el contexto
	 * actual de compra (país, moneda, importe del carrito) y nunca accede a claves ni secretos.
	 *
	 * @param string $metodo_consultado Nombre o término de un método específico consultado por el cliente (opcional).
	 * @return array Estructura verificable con los métodos disponibles o mensaje de abstención.
	 */
	public static function consultar_metodos_pago( string $metodo_consultado = '' ): array {
		$abstention_msg = 'Los métodos disponibles son los que aparecen en el checkout al finalizar tu compra. No puedo confirmar desde aquí cuáles están habilitados ahora mismo.';

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) || ! WC() || ! method_exists( WC(), 'payment_gateways' ) ) {
			return [
				'exito'              => false,
				'verificado'         => false,
				'codigo'             => 'woocommerce_no_verificable',
				'metodos_disponibles'=> [],
				'mensaje_abstencion' => $abstention_msg,
				'instruccion_agente' => 'No inventes ni supongas métodos de pago. Utiliza el mensaje_abstencion.',
			];
		}

		// Inicializar el contexto de sesión/carrito/cliente para evaluar restricciones por país, moneda e importe.
		self::ensure_cart_loaded();

		try {
			$gateways_controller = WC()->payment_gateways();
			if ( ! is_object( $gateways_controller ) || ! method_exists( $gateways_controller, 'get_available_payment_gateways' ) ) {
				return [
					'exito'              => false,
					'verificado'         => false,
					'codigo'             => 'controlador_pagos_no_disponible',
					'metodos_disponibles'=> [],
					'mensaje_abstencion' => $abstention_msg,
					'instruccion_agente' => 'No inventes ni supongas métodos de pago. Utiliza el mensaje_abstencion.',
				];
			}

			$available_gateways = $gateways_controller->get_available_payment_gateways();
		} catch ( Throwable $e ) {
			return [
				'exito'              => false,
				'verificado'         => false,
				'codigo'             => 'error_consulta_pasarelas',
				'metodos_disponibles'=> [],
				'mensaje_abstencion' => $abstention_msg,
				'instruccion_agente' => 'No inventes ni supongas métodos de pago. Utiliza el mensaje_abstencion.',
			];
		}

		if ( ! is_array( $available_gateways ) ) {
			$available_gateways = [];
		}

		$currency        = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
		$billing_country = '';
		if ( isset( WC()->customer ) && is_object( WC()->customer ) && method_exists( WC()->customer, 'get_billing_country' ) ) {
			$billing_country = (string) WC()->customer->get_billing_country();
		}
		if ( '' === $billing_country && function_exists( 'wc_get_base_location' ) ) {
			$base_loc        = wc_get_base_location();
			$billing_country = isset( $base_loc['country'] ) ? (string) $base_loc['country'] : '';
		}

		$cart_total = 0.0;
		if ( isset( WC()->cart ) && WC()->cart instanceof WC_Cart ) {
			$cart_total = (float) WC()->cart->get_total( 'edit' );
		}

		$verified_methods = [];

		foreach ( $available_gateways as $gateway ) {
			if ( ! is_object( $gateway ) || ! ( $gateway instanceof WC_Payment_Gateway ) ) {
				continue;
			}

			// 1. Comprobar explícitamente que la pasarela está habilitada ('yes').
			$is_enabled = isset( $gateway->enabled ) && 'yes' === (string) $gateway->enabled;
			if ( ! $is_enabled ) {
				continue;
			}

			// 2. Comprobar disponibilidad efectiva para la compra actual.
			$is_available = method_exists( $gateway, 'is_available' ) ? (bool) $gateway->is_available() : false;
			if ( ! $is_available ) {
				continue;
			}

			// 3. Comprobar restricción por países permitidos en la pasarela si está definida.
			if (
				! empty( $gateway->countries )
				&& is_array( $gateway->countries )
				&& '' !== $billing_country
				&& ! in_array( $billing_country, $gateway->countries, true )
			) {
				continue;
			}

			// 4. Comprobar importe máximo de la pasarela frente al carrito actual cuando hay productos en el carrito.
			if (
				$cart_total > 0
				&& isset( $gateway->max_amount )
				&& is_numeric( $gateway->max_amount )
				&& (float) $gateway->max_amount > 0
				&& $cart_total > (float) $gateway->max_amount
			) {
				continue;
			}

			// Extraer únicamente datos públicos visibles para el comprador (nunca ajustes internos ni claves).
			$title = method_exists( $gateway, 'get_title' )
				? html_entity_decode( wp_strip_all_tags( (string) $gateway->get_title() ), ENT_QUOTES, 'UTF-8' )
				: (string) ( $gateway->title ?? '' );

			$description = method_exists( $gateway, 'get_description' )
				? html_entity_decode( wp_strip_all_tags( (string) $gateway->get_description() ), ENT_QUOTES, 'UTF-8' )
				: '';

			$verified_methods[] = [
				'id'          => sanitize_key( (string) $gateway->id ),
				'titulo'      => trim( $title ),
				'descripcion' => trim( $description ),
				'habilitado'  => true,
				'disponible'  => true,
			];
		}

		$metodo_consultado = trim( sanitize_text_field( $metodo_consultado ) );
		$specific_eval     = null;
		if ( '' !== $metodo_consultado ) {
			$specific_eval = self::evaluate_queried_payment_method( $metodo_consultado, $verified_methods );
		}

		if ( empty( $verified_methods ) ) {
			return [
				'exito'                        => true,
				'verificado'                   => true,
				'codigo'                       => 'sin_metodos_habilitados_disponibles',
				'contexto_verificado'          => [
					'moneda'         => $currency,
					'pais'           => $billing_country,
					'total_carrito'  => $cart_total,
				],
				'metodos_disponibles'          => [],
				'evaluacion_metodo_consultado' => $specific_eval,
				'mensaje_abstencion'           => $abstention_msg,
				'instruccion_agente'           => 'Actualmente WooCommerce no reporta pasarelas de pago con enabled="yes" disponibles para esta sesión. Aunque una página informativa mencione ejemplos como PSE, Nequi o Daviplata, prevalece WooCommerce: NO confirmes ningún método de pago (como PSE, Nequi, Daviplata, Bancolombia, PayPal, tarjetas, contraentrega o transferencias) y responde indicando que los métodos disponibles se muestran en el checkout al finalizar la compra.',
			];
		}

		return [
			'exito'                        => true,
			'verificado'                   => true,
			'codigo'                       => 'metodos_pago_verificados',
			'contexto_verificado'          => [
				'moneda'         => $currency,
				'pais'           => $billing_country,
				'total_carrito'  => $cart_total,
			],
			'total_disponibles'            => count( $verified_methods ),
			'metodos_disponibles'          => $verified_methods,
			'evaluacion_metodo_consultado' => $specific_eval,
			'instruccion_agente'           => 'Menciona ÚNICAMENTE los métodos con enabled="yes" incluidos en metodos_disponibles. No asumas ni agregues PSE, Nequi, Daviplata, Bancolombia, tarjetas, transferencias, contraentrega, PayPal, Addi ni Sistecrédito si no figuran explícitamente en metodos_disponibles.',
		];
	}

	/**
	 * Comprueba si un método de pago específico consultado por el usuario coincide con alguna pasarela verificada.
	 *
	 * @param string $queried          Nombre del método consultado por el usuario.
	 * @param array  $verified_methods Lista de métodos verificados como habilitados y disponibles.
	 * @return array Resultado de la verificación específica.
	 */
	private static function evaluate_queried_payment_method( string $queried, array $verified_methods ): array {
		$norm_query = self::normalize_compare( $queried );
		$matches    = [];

		// Mapa de sinónimos comunes solo para correlacionar con pasarelas realmente presentes en $verified_methods.
		$gateway_aliases = [
			'bacs'   => [ 'transferencia', 'transferencias', 'transferencia bancaria', 'consignacion', 'banco' ],
			'cod'    => [ 'contraentrega', 'contra entrega', 'pago contra entrega', 'efectivo al recibir' ],
			'cheque' => [ 'cheque', 'pago con cheque' ],
			'paypal' => [ 'paypal', 'pay pal' ],
		];

		foreach ( $verified_methods as $method ) {
			$id_norm    = self::normalize_compare( (string) ( $method['id'] ?? '' ) );
			$title_norm = self::normalize_compare( (string) ( $method['titulo'] ?? '' ) );
			$desc_norm  = self::normalize_compare( (string) ( $method['descripcion'] ?? '' ) );

			$is_match = (
				( '' !== $id_norm && false !== strpos( $id_norm, $norm_query ) )
				|| ( '' !== $title_norm && false !== strpos( $title_norm, $norm_query ) )
				|| ( '' !== $desc_norm && false !== strpos( $desc_norm, $norm_query ) )
			);

			if ( ! $is_match && isset( $gateway_aliases[ $id_norm ] ) ) {
				foreach ( $gateway_aliases[ $id_norm ] as $alias ) {
					if ( false !== strpos( $norm_query, $alias ) || false !== strpos( $alias, $norm_query ) ) {
						$is_match = true;
						break;
					}
				}
			}

			if ( $is_match ) {
				$matches[] = $method;
			}
		}

		$confirmed = ! empty( $matches );

		return [
			'metodo_consultado'        => $queried,
			'confirmado_disponible'    => $confirmed,
			'coincidencias_verificadas'=> $matches,
			'instruccion'              => $confirmed
				? 'El método consultado está confirmado como habilitado y disponible en WooCommerce.'
				: 'El método consultado NO figura como habilitado y disponible en WooCommerce. Responde que no está confirmado como disponible e informa solo los métodos listados en metodos_disponibles.',
		];
	}
}
