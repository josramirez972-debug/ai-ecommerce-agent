<?php
/**
 * Plugin Name: AI Ecommerce Agent
 * Description: Agente de IA para tiendas WooCommerce.
 * Version: 1.3.1
 * Author: AI Ecommerce Agent
 * Text Domain: ai-ecommerce-agent
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'AIEA_VERSION', '1.3.1' );
define( 'AIEA_PATH', plugin_dir_path( __FILE__ ) );
define( 'AIEA_URL', plugin_dir_url( __FILE__ ) );
require_once AIEA_PATH . 'includes/class-aiea-woo.php';
require_once AIEA_PATH . 'includes/class-aiea-knowledge.php';
require_once AIEA_PATH . 'includes/class-aiea-usage.php';
require_once AIEA_PATH . 'includes/class-aiea-policies.php';
require_once AIEA_PATH . 'includes/class-aiea-session.php';
require_once AIEA_PATH . 'includes/class-aiea-openai.php';
require_once AIEA_PATH . 'includes/class-aiea-api.php';
require_once AIEA_PATH . 'includes/class-aiea-admin.php';
require_once AIEA_PATH . 'includes/class-aiea-public.php';
add_action( 'plugins_loaded', array( 'AI_Ecommerce_Agent_Admin', 'init' ) );
add_action( 'plugins_loaded', array( 'AI_Ecommerce_Agent_Public', 'init' ) );
add_action( 'rest_api_init', array( 'AI_Ecommerce_Agent_API', 'register_routes' ) );
