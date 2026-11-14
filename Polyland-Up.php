<?php
/**
 * Plugin Name:       Polylang Divi 5 AI Auto-Translator & Same Slug
 * Plugin URI:        https://github.com/piratexnik/Polyland-Up
 * Description:       Автоматический переводчик для Divi 5 и Polylang с поддержкой одинаковых слагов (Same Slug), Google Translate и Gemini AI.
 * Version:           2.5
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
define( 'PLU_VERSION', '2.5' );
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

/**
 * Публичный API-мост: проверяет, что плагин активен и шлюз перевода готов к работе.
 *
 * @return bool
 */
function plu_is_translator_ready(): bool {
    return defined( 'PLU_VERSION' )
        && class_exists( \PolylangUp\Core\Settings::class )
        && class_exists( \PolylangUp\Modules\TranslatorAPI::class );
}

/**
 * Публичный API-мост: перевод ассоциативного словаря строк через настроенный шлюз Polylang AI.
 *
 * @param array<string, string> $dictionary    Словарь вида ['key' => 'Исходный текст'].
 * @param string                $source_lang   Код исходного языка (например, 'ru', 'uk', 'en').
 * @param string                $target_lang   Код целевого языка.
 * @param string                $engine        Движок ('gtx', 'gemini' или '' для использования настройки по умолчанию).
 * @param string                $custom_prompt Дополнительная системная инструкция / глоссарий для Gemini AI.
 * @return array<string, string> Переведенный массив ['key' => 'translated_value'] или пустой массив при ошибке.
 */
function plu_translate_dictionary(
    array $dictionary,
    string $source_lang,
    string $target_lang,
    string $engine = '',
    string $custom_prompt = ''
): array {
    if ( ! class_exists( \PolylangUp\Modules\TranslatorAPI::class ) ) {
        return [];
    }

    $result = \PolylangUp\Modules\TranslatorAPI::translate(
        $dictionary,
        $source_lang,
        $target_lang,
        $engine,
        $custom_prompt
    );

    if ( is_wp_error( $result ) ) {
        return [];
    }

    return $result;
}

/**
 * Публичный API-мост: возвращает статистику последнего выполненного запроса на перевод.
 *
 * @return array<string, mixed> Ключи: engine, provider_label, translated_count, total_count, time_ms, time_sec, error.
 */
function plu_get_translator_last_stats(): array {
    if ( ! class_exists( \PolylangUp\Modules\TranslatorAPI::class ) ) {
        return [];
    }

    return \PolylangUp\Modules\TranslatorAPI::get_last_stats();
}

// Сброс статического кеша при обновлении настроек
add_action( 'update_option_plu_settings', static function (): void {
    plu_get_option( '', null, true );
} );

// Инициализация ядра плагина
add_action( 'plugins_loaded', static function (): void {
    \PolylangUp\Core\Init::init();
} );
