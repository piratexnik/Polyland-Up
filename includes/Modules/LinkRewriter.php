<?php
declare(strict_types=1);

namespace PolylangUp\Modules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Модуль умной перелинковки внутренних ссылок под целевой язык Polylang
 * для HTML (<a href>), JSON-атрибутов Divi 5 и шорткодов Divi 4.
 */
final class LinkRewriter {

    /**
     * Количество реально перелинкованных ссылок в последнем вызове rewrite_content().
     */
    private static int $last_rewritten_count = 0;

    /**
     * Ключи JSON-атрибутов Divi 5, отвечающие за ссылки модулей и кнопок (но не медиа-src).
     *
     * @var string[]
     */
    private const DIVI5_LINK_ATTR_KEYS = [
        'url',
        'linkUrl',
        'buttonUrl',
        'titleUrl',
        'imageUrl',
        'buttonOneUrl',
        'buttonTwoUrl',
        'logoUrl',
        'readMoreUrl',
    ];

    /**
     * Атрибуты шорткодов Divi 4, содержащие ссылки перехода.
     *
     * @var string[]
     */
    private const DIVI4_LINK_ATTRS = [
        'url',
        'button_url',
        'link_url',
        'button_one_url',
        'button_two_url',
        'title_link_url',
    ];

    /**
     * Расширения статических файлов и медиа, которые не должны перелинковываться.
     *
     * @var string[]
     */
    private const EXCLUDED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'avif', 'ico', 'bmp', 'tiff', 'tif',
        'pdf', 'zip', 'rar', '7z', 'tar', 'gz',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv',
        'mp4', 'webm', 'ogg', 'mp3', 'wav', 'flac',
        'css', 'js', 'json', 'xml', 'woff', 'woff2', 'ttf', 'eot', 'map',
    ];

    /**
     * Возвращает количество ссылок, измененных при последнем вызове rewrite_content().
     */
    public static function get_last_rewritten_count(): int {
        return self::$last_rewritten_count;
    }

    /**
     * Переписывает все внутренние ссылки в контенте записи под целевой язык Polylang.
     *
     * @param string $content     Исходный контент (Divi 5 блоки, Divi 4 шорткоды или HTML).
     * @param string $target_lang Код целевого языка Polylang.
     * @return string Обновленный контент.
     */
    public static function rewrite_content( string $content, string $target_lang ): string {
        self::$last_rewritten_count = 0;

        if ( '' === trim( $content ) || '' === trim( $target_lang ) ) {
            return $content;
        }

        if ( ! function_exists( 'pll_languages_list' ) ) {
            return $content;
        }

        $has_blocks = function_exists( 'parse_blocks' )
            && function_exists( 'serialize_blocks' )
            && ( ( function_exists( 'has_blocks' ) && has_blocks( $content ) ) || 1 === preg_match( '/<!--\s+wp:/', $content ) );

        if ( $has_blocks ) {
            $blocks = parse_blocks( $content );
            self::rewrite_blocks_recursive( $blocks, $target_lang );
            return serialize_blocks( $blocks );
        }

        return self::rewrite_html_and_shortcodes( $content, $target_lang );
    }

    /**
     * Рекурсивный обход массива блоков Divi 5 / Gutenberg для замены ссылок в attrs и innerContent.
     *
     * @param array<int, array<string, mixed>> $blocks      Блоки (по ссылке).
     * @param string                           $target_lang Целевой язык.
     */
    private static function rewrite_blocks_recursive( array &$blocks, string $target_lang ): void {
        foreach ( $blocks as &$block ) {
            if ( ! is_array( $block ) ) {
                continue;
            }

            if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
                self::rewrite_attrs_recursive( $block['attrs'], $target_lang );
            }

            if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
                $string_parts = [];
                foreach ( $block['innerContent'] as $idx => $chunk ) {
                    if ( is_string( $chunk ) ) {
                        $updated                       = self::rewrite_html_and_shortcodes( $chunk, $target_lang );
                        $block['innerContent'][ $idx ] = $updated;
                        $string_parts[]                = $updated;
                    }
                }
                if ( ! empty( $string_parts ) ) {
                    $block['innerHTML'] = implode( '', $string_parts );
                }
            } elseif ( ! empty( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
                $block['innerHTML'] = self::rewrite_html_and_shortcodes( $block['innerHTML'], $target_lang );
            }

            if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                self::rewrite_blocks_recursive( $block['innerBlocks'], $target_lang );
            }
        }
        unset( $block );
    }

    /**
     * Рекурсивный обход JSON-атрибутов блока Divi 5 для замены ссылок в строковых значениях.
     *
     * @param array<string|int, mixed> $attrs       Массив атрибутов (по ссылке).
     * @param string                   $target_lang Целевой язык.
     * @param array<int, string|int>   $path        Текущий путь ключей.
     */
    private static function rewrite_attrs_recursive( array &$attrs, string $target_lang, array $path = [] ): void {
        foreach ( $attrs as $key => &$value ) {
            $str_key      = (string) $key;
            $current_path = array_merge( $path, [ $key ] );

            if ( is_array( $value ) ) {
                self::rewrite_attrs_recursive( $value, $target_lang, $current_path );
                continue;
            }

            if ( ! is_string( $value ) || '' === trim( $value ) ) {
                continue;
            }

            // 1. Явные ключи ссылок модулей и кнопок Divi 5
            if ( in_array( $str_key, self::DIVI5_LINK_ATTR_KEYS, true ) ) {
                // Исключаем прямые медиа-источники (image -> innerContent -> ... -> url или background)
                if ( 'url' === $str_key && self::is_media_context_path( $current_path ) ) {
                    continue;
                }
                $value = self::rewrite_url( $value, $target_lang );
                continue;
            }

            // 2. HTML/шорткод контент внутри атрибутов (content, text, body и др.)
            if ( str_contains( $value, 'href=' ) || str_contains( $value, '[et_pb_' ) ) {
                $value = self::rewrite_html_and_shortcodes( $value, $target_lang );
            }
        }
        unset( $value );
    }

    /**
     * Проверяет, относится ли ключ `url` к медиа-источнику (картинка/видео/фон), а не к ссылке перехода.
     *
     * @param array<int, string|int> $path Путь ключей.
     */
    private static function is_media_context_path( array $path ): bool {
        foreach ( [ 'background', 'video', 'audio', 'mp4', 'webm', 'ogg' ] as $media_key ) {
            if ( in_array( $media_key, $path, true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Заменяет ссылки в HTML-атрибутах <a href="..."> и шорткодах Divi 4 (url, button_url, link_url).
     *
     * @param string $html        HTML или текст с шорткодами.
     * @param string $target_lang Целевой язык.
     * @return string
     */
    private static function rewrite_html_and_shortcodes( string $html, string $target_lang ): string {
        if ( '' === $html ) {
            return $html;
        }

        // 1. Замена href="..." и href='...' внутри тегов <a>
        if ( false !== stripos( $html, '<a' ) && false !== stripos( $html, 'href' ) ) {
            $html = (string) preg_replace_callback(
                '/(<a\b[^>]*?\shref\s*=\s*)(?:"([^"]*)"|\'([^\']*)\')/iu',
                static function ( array $m ) use ( $target_lang ): string {
                    $raw_url = '' !== $m[2] ? $m[2] : ( $m[3] ?? '' );
                    $quote   = '' !== $m[2] ? '"' : "'";
                    if ( '' === $raw_url ) {
                        return $m[0];
                    }

                    $decoded = html_entity_decode( $raw_url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                    $updated = self::rewrite_url( $decoded, $target_lang );
                    if ( $updated === $decoded ) {
                        return $m[0];
                    }

                    $escaped = htmlspecialchars( $updated, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
                    return $m[1] . $quote . $escaped . $quote;
                },
                $html
            );
        }

        // 2. Замена атрибутов ссылок в шорткодах Divi 4 ([et_pb_... url="..." button_url="..." link_url="..."])
        if ( str_contains( $html, '[et_pb_' ) ) {
            $attrs_pattern = implode( '|', array_map( 'preg_quote', self::DIVI4_LINK_ATTRS ) );
            $html          = (string) preg_replace_callback(
                '/(\[et_pb_[a-zA-Z0-9_]+\b[^\]]*?\s(?:' . $attrs_pattern . ')\s*=\s*)(?:"([^"]*)"|\'([^\']*)\')/iu',
                static function ( array $m ) use ( $target_lang ): string {
                    $raw_url = '' !== $m[2] ? $m[2] : ( $m[3] ?? '' );
                    $quote   = '' !== $m[2] ? '"' : "'";
                    if ( '' === $raw_url ) {
                        return $m[0];
                    }

                    $updated = self::rewrite_url( $raw_url, $target_lang );
                    if ( $updated === $raw_url ) {
                        return $m[0];
                    }

                    return $m[1] . $quote . $updated . $quote;
                },
                $html
            );
        }

        return $html;
    }

    /**
     * Алгоритм умной замены одного URL под целевой язык Polylang.
     *
     * @param string $url         Исходный URL.
     * @param string $target_lang Целевой язык Polylang.
     * @return string Обновленный или исходный URL.
     */
    public static function rewrite_url( string $url, string $target_lang ): string {
        $trimmed = trim( $url );
        if ( '' === $trimmed || ! self::is_rewritable_internal_url( $trimmed ) ) {
            return $url;
        }

        $parsed = wp_parse_url( $trimmed );
        if ( ! is_array( $parsed ) ) {
            return $url;
        }

        $was_relative = ! isset( $parsed['host'] );
        $query        = isset( $parsed['query'] ) && '' !== $parsed['query'] ? '?' . $parsed['query'] : '';
        $fragment     = isset( $parsed['fragment'] ) && '' !== $parsed['fragment'] ? '#' . $parsed['fragment'] : '';

        $path         = (string) ( $parsed['path'] ?? '/' );
        $absolute_url = self::build_absolute_url( $trimmed, $path );

        // Шаг А: Определяем ID записи через url_to_postid() и запасной резолвер SameSlug
        $linked_post_id = self::resolve_linked_post_id( $absolute_url, $path );

        // Шаг Б и В: Если запись найдена — берем ее перевод в Polylang
        if ( $linked_post_id > 0 && function_exists( 'pll_get_post' ) ) {
            $translated_id = (int) pll_get_post( $linked_post_id, $target_lang );
            if ( $translated_id > 0 ) {
                $target_permalink = (string) get_permalink( $translated_id );
                if ( '' !== $target_permalink ) {
                    $final_base = $was_relative ? self::to_relative_url( $target_permalink ) : $target_permalink;
                    $final_url  = $final_base . $query . $fragment;

                    if ( $final_url !== $url ) {
                        self::$last_rewritten_count++;
                    }
                    return $final_url;
                }
            }
        }

        // Шаг Г: Фоллбэк по языковому префиксу Polylang
        $fallback_abs = self::switch_language_prefix( $absolute_url, $target_lang );
        $final_base   = $was_relative ? self::to_relative_url( $fallback_abs ) : $fallback_abs;
        $final_url    = $final_base . $query . $fragment;

        if ( $final_url !== $url ) {
            self::$last_rewritten_count++;
        }

        return $final_url;
    }

    /**
     * Проверяет, содержит ли строка динамическую переменную или JSON-шаблон Divi 5 / Divi 4.
     */
    private static function is_dynamic_divi_value( string $value ): bool {
        return str_contains( $value, '$variable(' )
            || str_contains( $value, '@ET-DC@' )
            || str_contains( $value, '{' )
            || str_contains( $value, '}' );
    }

    /**
     * Проверяет, является ли URL внутренней ссылкой страницы/записи, пригодной к перелинковке.
     */
    public static function is_rewritable_internal_url( string $url ): bool {
        $trimmed = trim( $url );
        if ( '' === $trimmed || str_starts_with( $trimmed, '#' ) || self::is_dynamic_divi_value( $trimmed ) ) {
            return false;
        }

        // Исключаем не-HTTP схемы
        if ( 1 === preg_match( '/^(?:mailto|tel|sms|javascript|data|blob|skype|viber|whatsapp):/i', $trimmed ) ) {
            return false;
        }

        $parsed = wp_parse_url( $trimmed );
        if ( ! is_array( $parsed ) ) {
            return false;
        }

        // Проверяем домен (если URL абсолютный или protocol-relative)
        if ( isset( $parsed['host'] ) ) {
            $home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
            $link_host = strtolower( (string) $parsed['host'] );
            $norm_home = preg_replace( '/^www\./i', '', strtolower( $home_host ) );
            $norm_link = preg_replace( '/^www\./i', '', $link_host );

            if ( '' !== $norm_home && $norm_home !== $norm_link ) {
                return false;
            }
        }

        $path = strtolower( (string) ( $parsed['path'] ?? '' ) );

        // Исключаем системные пути WordPress и папку загрузок
        if (
            str_contains( $path, '/wp-content/uploads/' )
            || str_contains( $path, '/wp-admin' )
            || str_contains( $path, '/wp-login.php' )
            || str_contains( $path, '/wp-json/' )
        ) {
            return false;
        }

        // Исключаем ссылки на файлы/медиа по расширению
        $ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
        if ( '' !== $ext && in_array( $ext, self::EXCLUDED_EXTENSIONS, true ) ) {
            return false;
        }

        return true;
    }

    /**
     * Определяет ID записи по URL через url_to_postid() и запасной резолвер SameSlug.
     */
    private static function resolve_linked_post_id( string $absolute_url, string $path ): int {
        $post_id = 0;

        if ( function_exists( 'url_to_postid' ) ) {
            $post_id = (int) url_to_postid( $absolute_url );
        }

        if ( $post_id > 0 ) {
            return $post_id;
        }

        // Запасной поиск через SameSlug::resolve_post_by_path_and_lang()
        if ( class_exists( SameSlug::class ) && method_exists( SameSlug::class, 'resolve_post_by_path_and_lang' ) ) {
            $langs        = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list( [ 'fields' => 'slug' ] ) : [];
            $default_lang = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';

            $clean_path    = self::strip_home_base_path( $path );
            $segments      = array_values( array_filter( explode( '/', trim( $clean_path, '/' ) ), static fn( string $s ): bool => '' !== $s ) );
            $detected_lang = $default_lang;

            if ( ! empty( $segments ) && in_array( $segments[0], $langs, true ) ) {
                $detected_lang = array_shift( $segments );
            }

            $lookup_path = implode( '/', $segments );
            if ( '' !== $lookup_path && '' !== $detected_lang ) {
                $public_types = function_exists( 'get_post_types' )
                    ? array_values( (array) get_post_types( [ 'public' => true ], 'names' ) )
                    : [ 'page', 'post' ];

                $found = SameSlug::resolve_post_by_path_and_lang( $lookup_path, $detected_lang, $public_types );
                if ( null !== $found && $found > 0 ) {
                    return $found;
                }
            }
        }

        return 0;
    }

    /**
     * Переключает языковой префикс в абсолютном URL через нативный API Polylang или ручной разбор пути.
     */
    private static function switch_language_prefix( string $absolute_url, string $target_lang ): string {
        // Приоритет: нативный метод Polylang, если доступен
        if (
            function_exists( 'PLL' )
            && is_object( PLL() )
            && isset( PLL()->links_model, PLL()->model )
            && is_object( PLL()->links_model )
            && is_object( PLL()->model )
            && method_exists( PLL()->links_model, 'switch_language_in_link' )
            && method_exists( PLL()->model, 'get_language' )
        ) {
            $lang_obj = PLL()->model->get_language( $target_lang );
            if ( is_object( $lang_obj ) ) {
                $switched = (string) PLL()->links_model->switch_language_in_link( $absolute_url, $lang_obj );
                if ( '' !== $switched ) {
                    return $switched;
                }
            }
        }

        $parsed       = wp_parse_url( $absolute_url );
        $scheme       = (string) ( $parsed['scheme'] ?? 'https' );
        $host         = (string) ( $parsed['host'] ?? '' );
        $port         = isset( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '';
        $path         = (string) ( $parsed['path'] ?? '/' );

        $langs        = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list( [ 'fields' => 'slug' ] ) : [];
        $default_lang = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';
        $hide_default = true;

        if ( function_exists( 'PLL' ) && is_object( PLL() ) && isset( PLL()->options['hide_default'] ) ) {
            $hide_default = (bool) PLL()->options['hide_default'];
        }

        $home_base  = self::get_home_base_path();
        $clean_path = self::strip_home_base_path( $path );
        $has_trail  = str_ends_with( $path, '/' );

        $segments = array_values( array_filter( explode( '/', trim( $clean_path, '/' ) ), static fn( string $s ): bool => '' !== $s ) );
        if ( ! empty( $segments ) && in_array( $segments[0], $langs, true ) ) {
            array_shift( $segments );
        }

        if ( $target_lang !== $default_lang || ! $hide_default ) {
            array_unshift( $segments, $target_lang );
        }

        $new_rel_path = '/' . implode( '/', $segments );
        if ( '/' !== $new_rel_path && ( $has_trail || empty( $segments ) ) ) {
            $new_rel_path .= '/';
        } elseif ( '/' === $new_rel_path && ! empty( $segments ) ) {
            $new_rel_path .= '/';
        }

        $full_path = rtrim( $home_base, '/' ) . $new_rel_path;
        if ( '' === $host ) {
            return $full_path;
        }

        return "{$scheme}://{$host}{$port}{$full_path}";
    }

    /**
     * Строит абсолютный URL без query/fragment.
     */
    private static function build_absolute_url( string $trimmed_url, string $path ): string {
        $parsed = wp_parse_url( $trimmed_url );
        if ( is_array( $parsed ) && isset( $parsed['host'] ) ) {
            $scheme = (string) ( $parsed['scheme'] ?? wp_parse_url( home_url(), PHP_URL_SCHEME ) ?? 'https' );
            $host   = (string) $parsed['host'];
            $port   = isset( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '';
            return "{$scheme}://{$host}{$port}{$path}";
        }

        $home_base = self::get_home_base_path();
        $rel_path  = str_starts_with( $path, '/' ) ? $path : '/' . $path;
        if ( '' !== $home_base && str_starts_with( $rel_path, $home_base . '/' ) ) {
            $rel_path = substr( $rel_path, strlen( $home_base ) );
        }

        return rtrim( home_url(), '/' ) . $rel_path;
    }

    /**
     * Преобразует абсолютный URL сайта в относительный путь от корня.
     */
    private static function to_relative_url( string $url ): string {
        $parsed = wp_parse_url( $url );
        if ( ! is_array( $parsed ) ) {
            return $url;
        }
        $path = (string) ( $parsed['path'] ?? '/' );
        return '' !== $path ? $path : '/';
    }

    /**
     * Возвращает базовый путь сайта (если WP установлен в подпапке), без завершающего слеша.
     */
    private static function get_home_base_path(): string {
        $home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
        return rtrim( $home_path, '/' );
    }

    /**
     * Удаляет базовый путь подпапки WP из начала пути.
     */
    private static function strip_home_base_path( string $path ): string {
        $base = self::get_home_base_path();
        if ( '' !== $base && ( $path === $base || str_starts_with( $path, $base . '/' ) ) ) {
            $stripped = substr( $path, strlen( $base ) );
            return '' !== $stripped ? $stripped : '/';
        }
        return $path;
    }
}
