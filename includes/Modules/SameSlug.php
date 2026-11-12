<?php
declare(strict_types=1);

namespace PolylangUp\Modules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Модуль поддержки одинаковых URL-слагов (Same Slug) между языками Polylang.
 */
final class SameSlug {

    /**
     * Принудительный код языка при программном клонировании/переводе (Этап 3).
     */
    public static ?string $forced_lang = null;

    /**
     * Кеш соответствия slug языка -> term_taxonomy_id.
     *
     * @var array<string, int>
     */
    private static array $lang_tt_cache = [];

    /**
     * Флаг защиты от рекурсии в фильтре url_to_postid.
     */
    private static bool $in_url_to_postid = false;

    /**
     * Инициализация хуков модуля.
     */
    public static function init(): void {
        if ( ! function_exists( 'pll_languages_list' ) ) {
            return;
        }

        if ( ! (bool) plu_get_option( 'enable_same_slug', 1 ) ) {
            return;
        }

        // 1. Генерация уникального слага с учетом языка
        add_filter( 'wp_unique_post_slug', [ self::class, 'filter_unique_post_slug' ], 10, 6 );

        // 2. Разрешение маршрутов на фронтенде строго в главном запросе (без конфликта с Divi Theme Builder)
        add_filter( 'request', [ self::class, 'filter_request' ], 10, 1 );

        // 3. Корректировка url_to_postid для внутренних ссылок и парсеров
        add_filter( 'url_to_postid', [ self::class, 'filter_url_to_postid' ], 20, 1 );

        // 4. Защита от ложных канонических редиректов Polylang и ядра WP
        add_filter( 'pll_check_canonical_url', [ self::class, 'filter_pll_canonical_url' ], 10, 2 );
        add_filter( 'redirect_canonical', [ self::class, 'filter_redirect_canonical' ], 10, 2 );
    }

    /**
     * Определение целевого языка записи при сохранении по цепочке приоритетов.
     *
     * @param int $post_ID ID сохраняемой записи.
     * @return string|null Slug языка или null, если определить не удалось.
     */
    public static function get_target_post_language( int $post_ID ): ?string {
        $valid_langs = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list( [ 'fields' => 'slug' ] ) : [];

        // Приоритет 1: Программно заданный язык ($forced_lang)
        if ( null !== self::$forced_lang && '' !== self::$forced_lang ) {
            $forced = sanitize_key( self::$forced_lang );
            if ( empty( $valid_langs ) || in_array( $forced, $valid_langs, true ) ) {
                return $forced;
            }
        }

        // Приоритет 2: POST/GET параметры админки Polylang / Quick Edit / Новый перевод
        $request_keys = [
            'post_lang_choice'   => $_POST['post_lang_choice'] ?? null,
            'inline_lang_choice' => $_REQUEST['inline_lang_choice'] ?? null,
            'new_lang'           => $_GET['new_lang'] ?? null,
            'lang'               => $_REQUEST['lang'] ?? null,
        ];

        foreach ( $request_keys as $raw_val ) {
            if ( is_string( $raw_val ) && '' !== $raw_val ) {
                $candidate = sanitize_key( wp_unslash( $raw_val ) );
                if ( in_array( $candidate, $valid_langs, true ) ) {
                    return $candidate;
                }
            }
        }

        // Приоритет 3: Существующий язык записи в БД
        if ( $post_ID > 0 && function_exists( 'pll_get_post_language' ) ) {
            $post_lang = pll_get_post_language( $post_ID, 'slug' );
            if ( is_string( $post_lang ) && '' !== $post_lang ) {
                return $post_lang;
            }
        }

        // Приоритет 4: Текущий активный язык Polylang или язык по умолчанию
        if ( function_exists( 'pll_current_language' ) ) {
            $current = pll_current_language( 'slug' );
            if ( is_string( $current ) && '' !== $current ) {
                return $current;
            }
        }

        if ( function_exists( 'pll_default_language' ) ) {
            $default = pll_default_language( 'slug' );
            if ( is_string( $default ) && '' !== $default ) {
                return $default;
            }
        }

        return null;
    }

    /**
     * Получение term_taxonomy_id языкового терма для таксономии 'language' со статическим кешем.
     *
     * @param string $lang_slug Код языка (например, 'ru', 'en').
     * @return int|null ID таксономии или null.
     */
    public static function get_language_term_taxonomy_id( string $lang_slug ): ?int {
        if ( isset( self::$lang_tt_cache[ $lang_slug ] ) ) {
            return self::$lang_tt_cache[ $lang_slug ];
        }

        // Попытка получить через внутреннюю модель Polylang
        if ( function_exists( 'PLL' ) && isset( PLL()->model ) && is_object( PLL()->model ) && method_exists( PLL()->model, 'get_language' ) ) {
            $lang_obj = PLL()->model->get_language( $lang_slug );
            if ( is_object( $lang_obj ) && ! empty( $lang_obj->term_taxonomy_id ) ) {
                self::$lang_tt_cache[ $lang_slug ] = (int) $lang_obj->term_taxonomy_id;
                return self::$lang_tt_cache[ $lang_slug ];
            }
        }

        // Прямое получение через get_term_by
        $term = get_term_by( 'slug', $lang_slug, 'language' );
        if ( $term instanceof \WP_Term && $term->term_taxonomy_id > 0 ) {
            self::$lang_tt_cache[ $lang_slug ] = (int) $term->term_taxonomy_id;
            return self::$lang_tt_cache[ $lang_slug ];
        }

        return null;
    }

    /**
     * Перехват проверки уникальности слага (wp_unique_post_slug).
     * Разрешает одинаковый post_name, если записи принадлежат разным языкам Polylang.
     *
     * @param string $slug          Слаг, сгенерированный WP (возможно, уже с суффиксом -2).
     * @param int    $post_ID       ID записи.
     * @param string $post_status   Статус записи.
     * @param string $post_type     Тип записи.
     * @param int    $post_parent   ID родительской записи.
     * @param string $original_slug Исходный желаемый слаг до добавления суффикса.
     * @return string Уникальный слаг в рамках целевого языка.
     */
    public static function filter_unique_post_slug(
        mixed $slug,
        mixed $post_ID,
        mixed $post_status,
        mixed $post_type,
        mixed $post_parent,
        mixed $original_slug
    ): mixed {
        $slug          = (string) $slug;
        $post_ID       = (int) $post_ID;
        $post_status   = (string) $post_status;
        $post_type     = (string) $post_type;
        $post_parent   = (int) $post_parent;
        $original_slug = (string) $original_slug;

        // Пропускаем системные статусы и типы
        if ( in_array( $post_status, [ 'inherit', 'auto-draft' ], true ) ) {
            return $slug;
        }

        if ( in_array( $post_type, [ 'nav_menu_item', 'attachment', 'revision' ], true ) ) {
            return $slug;
        }

        // Проверяем, переводится ли данный post_type в Polylang
        if ( ! function_exists( 'pll_is_translated_post_type' ) || ! pll_is_translated_post_type( $post_type ) ) {
            return $slug;
        }

        $target_lang = self::get_target_post_language( $post_ID );
        if ( ! $target_lang ) {
            return $slug;
        }

        $tt_id = self::get_language_term_taxonomy_id( $target_lang );
        if ( ! $tt_id ) {
            return $slug;
        }

        // Проверяем исходный слаг в рамках целевого языка
        $check_slug = $original_slug;
        if ( ! self::is_slug_taken_in_language( $check_slug, $post_ID, $post_type, $post_parent, $tt_id ) ) {
            return $check_slug;
        }

        // Если занят в том же языке — наращиваем суффикс -2, -3...
        $suffix = 2;
        do {
            $alt_post_name = _truncate_post_slug( $original_slug, 200 - ( strlen( (string) $suffix ) + 1 ) ) . "-$suffix";
            $suffix++;
        } while ( self::is_slug_taken_in_language( $alt_post_name, $post_ID, $post_type, $post_parent, $tt_id ) );

        return $alt_post_name;
    }

    /**
     * Проверка, занят ли слаг записью того же языка (с учетом фидов и иерархии).
     *
     * @param string $check_slug  Проверяемый слаг.
     * @param int    $post_ID     ID текущей записи.
     * @param string $post_type   Тип записи.
     * @param int    $post_parent ID родительской записи.
     * @param int    $tt_id       term_taxonomy_id языка.
     * @return bool True, если слаг уже занят в этом языке.
     */
    private static function is_slug_taken_in_language(
        string $check_slug,
        int $post_ID,
        string $post_type,
        int $post_parent,
        int $tt_id
    ): bool {
        global $wpdb, $wp_rewrite;

        // Проверка на совпадение с зарезервированными именами фидов
        $feeds = is_object( $wp_rewrite ) && is_array( $wp_rewrite->feeds ) ? $wp_rewrite->feeds : [];
        if ( in_array( $check_slug, $feeds, true ) ) {
            return true;
        }

        if ( 'page' === $post_type || is_post_type_hierarchical( $post_type ) ) {
            if ( preg_match( "@^($wp_rewrite->pagination_base)?\d+$@", $check_slug ) ) {
                return true;
            }

            $sql = $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} AS p
                INNER JOIN {$wpdb->term_relationships} AS tr ON p.ID = tr.object_id
                WHERE p.post_name = %s
                AND p.post_type IN (%s, 'attachment')
                AND p.ID != %d
                AND p.post_parent = %d
                AND tr.term_taxonomy_id = %d
                LIMIT 1",
                $check_slug,
                $post_type,
                $post_ID,
                $post_parent,
                $tt_id
            );
        } else {
            if ( 'post' === $post_type && preg_match( '/^[0-9]+$/', $check_slug ) ) {
                return true;
            }

            $sql = $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} AS p
                INNER JOIN {$wpdb->term_relationships} AS tr ON p.ID = tr.object_id
                WHERE p.post_name = %s
                AND p.post_type = %s
                AND p.ID != %d
                AND tr.term_taxonomy_id = %d
                LIMIT 1",
                $check_slug,
                $post_type,
                $post_ID,
                $tt_id
            );
        }

        $found = $wpdb->get_var( $sql );
        return ! empty( $found );
    }

    /**
     * Пошаговое разрешение пути страницы/записи с учетом иерархии и языка Polylang.
     *
     * @param string               $path      Путь вида 'about' или 'catalog/trays'.
     * @param string               $lang      Код языка ('ru', 'en').
     * @param string|array<string> $post_type Тип(ы) записи.
     * @return int|null ID найденной записи нужного языка или null.
     */
    public static function resolve_post_by_path_and_lang( string $path, string $lang, string|array $post_type = 'page' ): ?int {
        global $wpdb;

        $path = trim( $path, '/' );
        if ( '' === $path || '' === $lang ) {
            return null;
        }

        $tt_id = self::get_language_term_taxonomy_id( $lang );
        if ( ! $tt_id ) {
            return null;
        }

        $segments = array_values( array_filter( explode( '/', $path ), static fn( string $s ): bool => '' !== $s ) );
        if ( empty( $segments ) ) {
            return null;
        }

        $post_types = (array) $post_type;
        $post_types = array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );
        if ( empty( $post_types ) ) {
            $post_types = [ 'page' ];
        }

        $placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
        $parent_id    = 0;
        $matched_id   = null;

        foreach ( $segments as $index => $raw_segment ) {
            $segment = sanitize_title_for_query( $raw_segment );
            if ( '' === $segment ) {
                return null;
            }

            $is_first      = ( 0 === $index );
            $is_single_seg = ( 1 === count( $segments ) );

            $query_args = array_merge( [ $segment, $tt_id ], $post_types );

            $sql = $wpdb->prepare(
                "SELECT p.ID, p.post_parent, p.post_type
                FROM {$wpdb->posts} AS p
                INNER JOIN {$wpdb->term_relationships} AS tr ON p.ID = tr.object_id
                WHERE p.post_name = %s
                AND tr.term_taxonomy_id = %d
                AND p.post_type IN ($placeholders)
                AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')",
                ...$query_args
            );

            $rows = $wpdb->get_results( $sql );
            if ( empty( $rows ) ) {
                return null;
            }

            $found_for_step = null;
            foreach ( $rows as $row ) {
                $row_id     = (int) $row->ID;
                $row_parent = (int) $row->post_parent;

                if ( $row_parent === $parent_id ) {
                    if ( ! function_exists( 'pll_get_post_language' ) || pll_get_post_language( $row_id, 'slug' ) === $lang ) {
                        $found_for_step = $row_id;
                        break;
                    }
                }
            }

            // Если это одиночный сегмент для плоского CPT, где post_parent может быть не 0
            if ( null === $found_for_step && $is_first && $is_single_seg ) {
                foreach ( $rows as $row ) {
                    $row_id = (int) $row->ID;
                    if ( ! is_post_type_hierarchical( (string) $row->post_type ) ) {
                        if ( ! function_exists( 'pll_get_post_language' ) || pll_get_post_language( $row_id, 'slug' ) === $lang ) {
                            $found_for_step = $row_id;
                            break;
                        }
                    }
                }
            }

            if ( null === $found_for_step ) {
                return null;
            }

            $parent_id  = $found_for_step;
            $matched_id = $found_for_step;
        }

        return $matched_id;
    }

    /**
     * Определение языка текущего URL строго по языковому префиксу в REQUEST_URI (или $custom_url).
     * Если префикс не найден, возвращает язык по умолчанию (pll_default_language).
     *
     * @param array<string, mixed> $query_vars Опциональные переменные запроса.
     * @param string|null          $custom_url Опциональный URL для анализа.
     * @return string|null Код языка.
     */
    public static function detect_request_language( array $query_vars = [], ?string $custom_url = null ): ?string {
        if ( ! function_exists( 'pll_languages_list' ) ) {
            return null;
        }

        $languages = (array) pll_languages_list( [ 'fields' => 'slug' ] );
        if ( empty( $languages ) ) {
            return null;
        }

        // 1. Анализ пути URL на наличие языкового префикса (/en/about/, /ru/about/)
        $uri = $custom_url ?? ( $_SERVER['REQUEST_URI'] ?? '' );
        if ( is_string( $uri ) && '' !== $uri ) {
            $parsed_path = (string) wp_parse_url( $uri, PHP_URL_PATH );
            $home_path   = (string) wp_parse_url( home_url(), PHP_URL_PATH );

            if ( '' !== $home_path && '/' !== $home_path && str_starts_with( $parsed_path, $home_path ) ) {
                $parsed_path = substr( $parsed_path, strlen( $home_path ) );
            }

            $trimmed = trim( $parsed_path, '/' );
            if ( '' !== $trimmed ) {
                $parts      = explode( '/', $trimmed );
                $first_part = sanitize_key( $parts[0] );
                if ( in_array( $first_part, $languages, true ) ) {
                    return $first_part;
                }
            }
        }

        // 2. Явно переданный lang в query_vars (резервно, если префикса нет)
        if ( ! empty( $query_vars['lang'] ) && is_string( $query_vars['lang'] ) ) {
            $q_lang = sanitize_key( $query_vars['lang'] );
            if ( in_array( $q_lang, $languages, true ) ) {
                return $q_lang;
            }
        }

        // 3. Язык по умолчанию (основной язык без префикса)
        if ( function_exists( 'pll_default_language' ) ) {
            $def = pll_default_language( 'slug' );
            if ( is_string( $def ) && '' !== $def ) {
                return $def;
            }
        }

        return null;
    }

    /**
     * Фильтр request: корректировка переменных главного запроса браузера до запуска WP_Query::parse_query().
     * Не затрагивает внутренние WP_Query шапки/подвала Divi Theme Builder.
     *
     * @param array<string, mixed> $query_vars Переменные запроса.
     * @return array<string, mixed>
     */
    public static function filter_request( mixed $query_vars ): mixed {
        if ( ! is_array( $query_vars ) || is_admin() ) {
            return $query_vars;
        }

        if ( empty( $query_vars['pagename'] ) && empty( $query_vars['name'] ) ) {
            return $query_vars;
        }

        // Пропускаем запросы вложений
        if ( ! empty( $query_vars['attachment'] ) ) {
            return $query_vars;
        }

        // Игнорируем системные типы записей Divi 5 / Theme Builder
        $divi_types = [
            'et_pb_layout',
            'et_header_layout',
            'et_body_layout',
            'et_footer_layout',
            'et_theme_builder',
        ];
        if ( ! empty( $query_vars['post_type'] ) ) {
            $req_types = (array) $query_vars['post_type'];
            if ( ! empty( array_intersect( $req_types, $divi_types ) ) ) {
                return $query_vars;
            }
        }

        // Определяем язык строго по префиксу REQUEST_URI (или язык по умолчанию)
        $lang = self::detect_request_language();
        if ( ! $lang ) {
            return $query_vars;
        }

        // 1. Обработка $query_vars['pagename']
        if ( ! empty( $query_vars['pagename'] ) && is_string( $query_vars['pagename'] ) ) {
            $path = trim( $query_vars['pagename'], '/' );
            if ( '' === $path || $path === $lang ) {
                return $query_vars;
            }

            $req_post_type = ! empty( $query_vars['post_type'] ) ? $query_vars['post_type'] : 'page';
            $post_id       = self::resolve_post_by_path_and_lang( $path, $lang, $req_post_type );

            if ( null === $post_id && str_starts_with( $path, $lang . '/' ) ) {
                $stripped = substr( $path, strlen( $lang ) + 1 );
                if ( '' !== $stripped ) {
                    $post_id = self::resolve_post_by_path_and_lang( $stripped, $lang, $req_post_type );
                }
            }

            if ( null !== $post_id ) {
                $actual_type = get_post_type( $post_id );
                if ( 'page' === $actual_type ) {
                    $query_vars['page_id'] = $post_id;
                } else {
                    $query_vars['p'] = $post_id;
                }
                unset( $query_vars['pagename'], $query_vars['name'] );
                return $query_vars;
            }

            // Фоллбэк: при пермалинках /%postname%/ WP может поместить слаг записи в pagename
            if ( 'page' === $req_post_type ) {
                $post_id = self::resolve_post_by_path_and_lang( $path, $lang, 'post' );
                if ( null !== $post_id ) {
                    $query_vars['p'] = $post_id;
                    unset( $query_vars['pagename'], $query_vars['name'] );
                    return $query_vars;
                }
            }

            return $query_vars;
        }

        // 2. Обработка $query_vars['name'] для одиночных записей и CPT
        if ( ! empty( $query_vars['name'] ) && is_string( $query_vars['name'] ) && empty( $query_vars['p'] ) && empty( $query_vars['page_id'] ) ) {
            $slug = trim( $query_vars['name'], '/' );
            if ( '' === $slug || $slug === $lang ) {
                return $query_vars;
            }

            $req_post_type = ! empty( $query_vars['post_type'] ) ? $query_vars['post_type'] : [ 'post', 'page' ];
            $post_id       = self::resolve_post_by_path_and_lang( $slug, $lang, $req_post_type );

            if ( null !== $post_id ) {
                $actual_type = get_post_type( $post_id );
                if ( 'page' === $actual_type ) {
                    $query_vars['page_id'] = $post_id;
                } else {
                    $query_vars['p'] = $post_id;
                    if ( empty( $query_vars['post_type'] ) && $actual_type && 'post' !== $actual_type ) {
                        $query_vars['post_type'] = $actual_type;
                    }
                }
                unset( $query_vars['pagename'], $query_vars['name'] );
            }
        }

        return $query_vars;
    }

    /**
     * Фильтр url_to_postid: возвращает ID записи правильного языка при одинаковых слагах.
     *
     * @param int $post_id Найденный ядром WP post_id.
     * @return int Скорректированный post_id.
     */
    public static function filter_url_to_postid( mixed $post_id ): mixed {
        $raw_post_id = $post_id;
        $post_id     = (int) $post_id;

        if ( self::$in_url_to_postid || $post_id <= 0 || ! function_exists( 'pll_get_post_language' ) ) {
            return $raw_post_id;
        }

        self::$in_url_to_postid = true;

        try {
            // Получаем URL из стека вызовов url_to_postid
            $target_url = null;
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
            $trace = debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT, 5 );
            foreach ( $trace as $frame ) {
                if ( isset( $frame['function'] ) && 'url_to_postid' === $frame['function'] && ! empty( $frame['args'][0] ) && is_string( $frame['args'][0] ) ) {
                    $target_url = $frame['args'][0];
                    break;
                }
            }

            $lang = self::detect_request_language( [], $target_url );
            if ( ! $lang ) {
                return $post_id;
            }

            $found_lang = pll_get_post_language( $post_id, 'slug' );
            if ( $found_lang === $lang ) {
                return $post_id;
            }

            // 1. Если записи связаны как переводы в Polylang
            if ( function_exists( 'pll_get_post' ) ) {
                $translated_id = (int) pll_get_post( $post_id, $lang );
                if ( $translated_id > 0 ) {
                    return $translated_id;
                }
            }

            // 2. Поиск по пути и языку
            $path      = get_page_uri( $post_id ) ?: (string) get_post_field( 'post_name', $post_id );
            $post_type = get_post_type( $post_id ) ?: 'page';

            if ( '' !== $path ) {
                $resolved_id = self::resolve_post_by_path_and_lang( $path, $lang, $post_type );
                if ( null !== $resolved_id ) {
                    return $resolved_id;
                }
            }

            return $post_id;
        } finally {
            self::$in_url_to_postid = false;
        }
    }

    /**
     * Защита от ложного редиректа Polylang (pll_check_canonical_url).
     * Если открыта сингулярная запись, чей язык уже совпадает с языком запроса, отменяем редирект на другой язык.
     *
     * @param string|false $redirect_url Канонический URL, куда Polylang хочет сделать редирект, или false.
     * @param object       $language     Объект языка Polylang ($language->slug).
     * @return string|false
     */
    public static function filter_pll_canonical_url( mixed $redirect_url, mixed $language ): mixed {
        if ( ! is_string( $redirect_url ) || '' === $redirect_url || ! is_singular() ) {
            return $redirect_url;
        }

        $queried_id = get_queried_object_id();
        if ( $queried_id <= 0 || ! function_exists( 'pll_get_post_language' ) ) {
            return $redirect_url;
        }

        $post_lang     = pll_get_post_language( $queried_id, 'slug' );
        $expected_lang = ( is_object( $language ) && isset( $language->slug ) ) ? (string) $language->slug : '';

        if ( '' !== $expected_lang && $post_lang === $expected_lang ) {
            $req_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
            $red_uri = (string) wp_parse_url( $redirect_url, PHP_URL_PATH );

            if ( '' !== $req_uri && rtrim( $req_uri, '/' ) === rtrim( $red_uri, '/' ) ) {
                return false;
            }

            $url_lang = self::detect_request_language();
            if ( $url_lang === $post_lang ) {
                $redirect_lang = self::detect_request_language( [], $redirect_url );
                if ( $redirect_lang !== $post_lang ) {
                    return false;
                }
            }
        }

        return $redirect_url;
    }

    /**
     * Защита от сброса языкового префикса в стандартном redirect_canonical WordPress.
     *
     * @param mixed $redirect_url  Целевой URL редиректа.
     * @param mixed $requested_url Запрошенный URL.
     * @return mixed
     */
    public static function filter_redirect_canonical( mixed $redirect_url, mixed $requested_url ): mixed {
        if ( ! is_string( $redirect_url ) || '' === $redirect_url || ! is_string( $requested_url ) || ! is_singular() ) {
            return $redirect_url;
        }

        $queried_id = get_queried_object_id();
        if ( $queried_id <= 0 || ! function_exists( 'pll_get_post_language' ) ) {
            return $redirect_url;
        }

        $post_lang = pll_get_post_language( $queried_id, 'slug' );
        if ( ! is_string( $post_lang ) || '' === $post_lang ) {
            return $redirect_url;
        }

        $req_lang = self::detect_request_language( [], $requested_url );
        $red_lang = self::detect_request_language( [], $redirect_url );

        // Если запрошенный URL имел правильный язык записи (например /en/about/), а WP пытается редиректнуть на язык по умолчанию (/about/)
        if ( $req_lang === $post_lang && $red_lang !== $post_lang ) {
            return false;
        }

        return $redirect_url;
    }
}
