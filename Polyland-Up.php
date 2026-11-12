<?php
/**
 * Plugin Name:       Polylang Divi 5 AI Auto-Translator & Same Slug
 * Plugin URI:        https://github.com/piratexnik/Polyland-Up
 * Description:       Автоматический переводчик для Divi 5 и Polylang с поддержкой одинаковых слагов (Same Slug), Google Translate и Gemini AI.
 * Version:           1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            piratexnik
 * Author URI:        https://github.com/piratexnik
 * Text Domain:       polyland-up
 * Domain Path:       /languages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Константы плагина
define( 'PLU_VERSION', '1.0' );
define( 'PLU_FILE', __FILE__ );
define( 'PLU_PATH', plugin_dir_path( __FILE__ ) );
define( 'PLU_URL', plugin_dir_url( __FILE__ ) );
define( 'PLU_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4 автозагрузчик классов пространства имен PolylangUp\.
 */
spl_autoload_register( static function ( string $class ): void {
    $prefix = 'PolylangUp\\';
    if ( ! str_starts_with( $class, $prefix ) ) {
        return;
    }

    $relative_class = substr( $class, strlen( $prefix ) );
    $file = PLU_PATH . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';

    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

/**
 * Получение значения настройки плагина из plu_settings со статическим кешированием в RAM.
 *
 * @param string $key     Ключ опции.
 * @param mixed  $default Значение по умолчанию.
 * @param bool   $reset   Флаг сброса статического кеша.
 * @return mixed
 */
function plu_get_option( string $key, mixed $default = '', bool $reset = false ): mixed {
    static $options = null;

    if ( $reset || null === $options ) {
        $raw = get_option( 'plu_settings', [] );
        $options = is_array( $raw ) ? $raw : [];
    }

    if ( '' === $key && $reset ) {
        return null;
    }

    if ( array_key_exists( $key, $options ) ) {
        return $options[ $key ];
    }

    if ( '' === $default && class_exists( \PolylangUp\Core\Settings::class ) ) {
        return \PolylangUp\Core\Settings::get_default( $key );
    }

    return $default;
}

// Сброс статического кеша при обновлении настроек
add_action( 'update_option_plu_settings', static function (): void {
    plu_get_option( '', null, true );
} );

// Инициализация ядра плагина
add_action( 'plugins_loaded', static function (): void {
    \PolylangUp\Core\Init::init();
} );
