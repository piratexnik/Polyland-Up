<?php
declare(strict_types=1);

namespace PolylangUp\Modules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Модуль метабокса редактора и полного клонирования записи (Divi 5, Polylang, Rank Math).
 */
final class MetaboxUI {

    /**
     * Инициализация хуков метабокса и AJAX-клонирования.
     */
    public static function init(): void {
        if ( ! is_admin() || ! function_exists( 'pll_languages_list' ) ) {
            return;
        }

        add_action( 'add_meta_boxes', [ self::class, 'register_metabox' ], 10, 2 );
        add_action( 'wp_ajax_plu_duplicate_post', [ self::class, 'ajax_duplicate_post' ] );
    }

    /**
     * Регистрация метабокса для всех переводимых типов записей Polylang.
     *
     * @param string $post_type Тип текущей записи.
     * @param mixed  $post      Объект WP_Post.
     */
    public static function register_metabox( string $post_type, mixed $post = null ): void {
        if ( in_array( $post_type, [ 'attachment', 'revision', 'nav_menu_item' ], true ) ) {
            return;
        }

        if ( ! function_exists( 'pll_is_translated_post_type' ) || ! pll_is_translated_post_type( $post_type ) ) {
            return;
        }

        add_meta_box(
            'plu_ai_duplicator_box',
            __( '🚀 AI Дубликатор и Перевод (Polylang)', 'polyland-up' ),
            [ self::class, 'render_metabox' ],
            $post_type,
            'side',
            'high'
        );
    }

    /**
     * Отрисовка интерфейса метабокса в сайдбаре редактора.
     *
     * @param \WP_Post $post Текущий объект записи.
     */
    public static function render_metabox( \WP_Post $post ): void {
        if ( 'auto-draft' === $post->post_status || $post->ID <= 0 ) {
            self::render_unready_notice();
            return;
        }

        $source_lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post->ID, 'slug' ) : '';
        if ( ! is_string( $source_lang ) || '' === $source_lang ) {
            self::render_unready_notice();
            return;
        }

        $languages = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list( [ 'fields' => '' ] ) : [];
        if ( empty( $languages ) ) {
            self::render_unready_notice();
            return;
        }

        $source_lang_name = $source_lang;
        $target_languages = [];

        foreach ( $languages as $lang ) {
            if ( ! is_object( $lang ) || empty( $lang->slug ) ) {
                continue;
            }
            if ( $lang->slug === $source_lang ) {
                $source_lang_name = (string) ( $lang->name ?? $source_lang );
            } else {
                $target_languages[] = $lang;
            }
        }

        $default_translator = (string) plu_get_option( 'default_translator', 'gtx' );
        $default_mode       = 'gemini' === $default_translator ? 'translate_gemini' : 'translate_gtx';
        $enable_same_slug   = (bool) plu_get_option( 'enable_same_slug', 1 );
        $post_slug          = '' !== $post->post_name ? $post->post_name : sanitize_title( $post->post_title );
        ?>
        <div class="plu-metabox-wrap" id="plu-duplicator-box" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
            <div class="plu-source-lang-banner">
                <strong><?php esc_html_e( 'Исходный язык:', 'polyland-up' ); ?></strong>
                <span class="plu-lang-pill">
                    <?php echo esc_html( $source_lang_name ); ?> (<?php echo esc_html( $source_lang ); ?>)
                </span>
            </div>

            <?php if ( empty( $target_languages ) ) : ?>
                <p class="description">
                    <?php esc_html_e( 'В Polylang пока не добавлены другие языки для перевода.', 'polyland-up' ); ?>
                </p>
            <?php else : ?>
                <div class="plu-metabox-section">
                    <div class="plu-section-label"><?php esc_html_e( 'Целевые языки:', 'polyland-up' ); ?></div>
                    <div class="plu-target-langs-list">
                        <?php foreach ( $target_languages as $lang ) : ?>
                            <?php
                            $lang_slug   = (string) $lang->slug;
                            $lang_name   = (string) ( $lang->name ?? $lang_slug );
                            $existing_id = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $post->ID, $lang_slug ) : 0;
                            $exists      = $existing_id > 0;
                            $status_lbl  = '';

                            if ( $exists ) {
                                $raw_status = (string) get_post_status( $existing_id );
                                $status_obj = get_post_status_object( $raw_status );
                                $status_lbl = $status_obj ? $status_obj->label : $raw_status;
                            }
                            ?>
                            <div class="plu-lang-row <?php echo $exists ? 'is-existing' : 'is-missing'; ?>" data-lang-row="<?php echo esc_attr( $lang_slug ); ?>">
                                <label class="plu-lang-checkbox-label">
                                    <input
                                        type="checkbox"
                                        class="plu-target-lang-cb"
                                        name="plu_target_langs[]"
                                        value="<?php echo esc_attr( $lang_slug ); ?>"
                                        data-lang-name="<?php echo esc_attr( $lang_name ); ?>"
                                        data-exists="<?php echo $exists ? '1' : '0'; ?>"
                                        <?php checked( ! $exists ); ?>
                                    />
                                    <span class="plu-lang-title">
                                        <?php if ( ! empty( $lang->flag ) ) : ?>
                                            <span class="plu-lang-flag"><?php echo wp_kses( (string) $lang->flag, [ 'img' => [ 'src' => true, 'alt' => true, 'title' => true, 'class' => true, 'width' => true, 'height' => true, 'style' => true ] ] ); ?></span>
                                        <?php endif; ?>
                                        <strong><?php echo esc_html( $lang_name ); ?></strong>
                                    </span>
                                </label>

                                <div class="plu-lang-meta-row">
                                    <?php if ( $exists ) : ?>
                                        <span class="plu-badge plu-badge-neutral">
                                            <?php printf( esc_html__( 'Уже есть: #%1$d (%2$s)', 'polyland-up' ), $existing_id, esc_html( $status_lbl ) ); ?>
                                        </span>
                                        <span class="plu-lang-quick-links">
                                            <a href="<?php echo esc_url( (string) get_edit_post_link( $existing_id ) ); ?>" title="<?php esc_attr_e( 'Редактировать перевод', 'polyland-up' ); ?>" target="_blank" rel="noopener noreferrer">
                                                <span class="dashicons dashicons-edit"></span>
                                            </a>
                                            <a href="<?php echo esc_url( (string) get_permalink( $existing_id ) ); ?>" title="<?php esc_attr_e( 'Открыть перевод', 'polyland-up' ); ?>" target="_blank" rel="noopener noreferrer">
                                                <span class="dashicons dashicons-visibility"></span>
                                            </a>
                                        </span>
                                    <?php else : ?>
                                        <span class="plu-badge plu-badge-success">
                                            <?php esc_html_e( 'Нет перевода', 'polyland-up' ); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Чекбокс подтверждения перезаписи (показывается JS при выборе существующего перевода) -->
                <div class="plu-metabox-section plu-overwrite-box" id="plu-overwrite-wrapper" style="display:none;">
                    <label class="plu-option-checkbox">
                        <input type="checkbox" id="plu_overwrite_existing" name="plu_overwrite_existing" value="1" />
                        <span><?php esc_html_e( 'Перезаписать существующий перевод (обновить контент и мета)', 'polyland-up' ); ?></span>
                    </label>
                </div>

                <div class="plu-metabox-section">
                    <label for="plu_mode" class="plu-section-label">
                        <?php esc_html_e( 'Режим обработки:', 'polyland-up' ); ?>
                    </label>
                    <select id="plu_mode" name="plu_mode" class="widefat">
                        <option value="clone_only" <?php selected( 'clone_only', $default_mode ); ?>>
                            <?php esc_html_e( 'Только клонировать (без перевода текста)', 'polyland-up' ); ?>
                        </option>
                        <option value="translate_gtx" <?php selected( 'translate_gtx', $default_mode ); ?>>
                            <?php esc_html_e( 'Клонировать + перевести (Google Translate Free)', 'polyland-up' ); ?>
                        </option>
                        <option value="translate_gemini" <?php selected( 'translate_gemini', $default_mode ); ?>>
                            <?php esc_html_e( 'Клонировать + перевести (Gemini AI)', 'polyland-up' ); ?>
                        </option>
                    </select>
                </div>

                <div class="plu-metabox-section plu-options-group">
                    <label class="plu-option-checkbox">
                        <input type="checkbox" id="plu_keep_same_slug" name="plu_keep_same_slug" value="1" <?php checked( $enable_same_slug ); ?> />
                        <span>
                            <?php
                            printf(
                                esc_html__( 'Сохранить оригинальный URL-слаг (%s)', 'polyland-up' ),
                                '<code>' . esc_html( $post_slug ?: '...' ) . '</code>'
                            );
                            ?>
                        </span>
                    </label>

                    <label class="plu-option-checkbox">
                        <input type="checkbox" id="plu_rewrite_links" name="plu_rewrite_links" value="1" checked="checked" />
                        <span><?php esc_html_e( 'Авто-перелинковка внутренних ссылок под язык', 'polyland-up' ); ?></span>
                    </label>
                </div>

                <div class="plu-metabox-section">
                    <label for="plu_target_status" class="plu-section-label">
                        <?php esc_html_e( 'Статус создаваемой страницы:', 'polyland-up' ); ?>
                    </label>
                    <select id="plu_target_status" name="plu_target_status" class="widefat">
                        <option value="draft" selected="selected">
                            <?php esc_html_e( 'Черновик (Draft)', 'polyland-up' ); ?>
                        </option>
                        <option value="publish">
                            <?php esc_html_e( 'Опубликовано (Publish)', 'polyland-up' ); ?>
                        </option>
                    </select>
                </div>

                <div class="plu-metabox-actions">
                    <button type="button" id="plu-run-duplicator" class="button button-primary button-large" style="width:100%;">
                        <?php esc_html_e( '🚀 Создать языковую версию', 'polyland-up' ); ?>
                    </button>
                    <span class="spinner" id="plu-duplicator-spinner" style="float:none; margin: 6px auto 0; display:none;"></span>
                </div>

                <div id="plu-duplicator-log" class="plu-duplicator-log" style="display:none;"></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Заглушка, если запись еще не сохранена или у нее не выбран язык Polylang.
     */
    private static function render_unready_notice(): void {
        ?>
        <div class="plu-metabox-notice">
            <p><?php esc_html_e( 'Сначала сохраните запись и укажите её исходный язык в блоке «Языки».', 'polyland-up' ); ?></p>
        </div>
        <?php
    }

    /**
     * AJAX-обработчик клонирования/перевода записи на выбранный язык (или список языков).
     */
    public static function ajax_duplicate_post(): void {
        check_ajax_referer( 'plu_clone_nonce', 'nonce' );

        $source_post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( $source_post_id <= 0 || ! current_user_can( 'edit_post', $source_post_id ) ) {
            wp_send_json_error( [
                'message' => esc_html__( 'У вас недостаточно прав или указан некорректный ID записи.', 'polyland-up' ),
            ] );
        }

        $source_post = get_post( $source_post_id );
        if ( ! $source_post instanceof \WP_Post ) {
            wp_send_json_error( [
                'message' => esc_html__( 'Исходная запись не найдена.', 'polyland-up' ),
            ] );
        }

        @set_time_limit( 120 );

        $mode = sanitize_key( (string) ( $_POST['mode'] ?? 'clone_only' ) );
        if ( ! in_array( $mode, [ 'clone_only', 'translate_gtx', 'translate_gemini' ], true ) ) {
            $mode = 'clone_only';
        }

        $target_status = sanitize_key( (string) ( $_POST['target_status'] ?? 'draft' ) );
        if ( ! in_array( $target_status, [ 'draft', 'publish' ], true ) ) {
            $target_status = 'draft';
        }

        $options = [
            'mode'               => $mode,
            'keep_same_slug'     => ! empty( $_POST['keep_same_slug'] ),
            'rewrite_links'      => ! empty( $_POST['rewrite_links'] ),
            'target_status'      => $target_status,
            'overwrite_existing' => ! empty( $_POST['overwrite_existing'] ),
        ];

        // Поддержка как одиночного target_lang (пошаговый AJAX из JS), так и массива target_langs
        $single_lang = isset( $_POST['target_lang'] ) ? sanitize_key( (string) $_POST['target_lang'] ) : '';
        if ( '' !== $single_lang ) {
            $result = self::duplicate_post_to_language( $source_post, $single_lang, $options );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [
                    'message' => $result->get_error_message(),
                    'lang'    => $single_lang,
                ] );
            }
            wp_send_json_success( $result );
        }

        $raw_langs    = isset( $_POST['target_langs'] ) && is_array( $_POST['target_langs'] ) ? $_POST['target_langs'] : [];
        $target_langs = array_values( array_filter( array_map( 'sanitize_key', $raw_langs ) ) );

        if ( empty( $target_langs ) ) {
            wp_send_json_error( [
                'message' => esc_html__( 'Не выбран ни один целевой язык для дублирования.', 'polyland-up' ),
            ] );
        }

        $results = [];
        foreach ( $target_langs as $lang_slug ) {
            $res = self::duplicate_post_to_language( $source_post, $lang_slug, $options );
            if ( is_wp_error( $res ) ) {
                $results[] = [
                    'success' => false,
                    'lang'    => $lang_slug,
                    'message' => $res->get_error_message(),
                ];
            } else {
                $results[] = array_merge( [ 'success' => true ], $res );
            }
        }

        wp_send_json_success( [
            'results' => $results,
        ] );
    }

    /**
     * Полное клонирование записи на указанный целевой язык (Divi 5, Polylang, Rank Math, таксономии).
     *
     * @param \WP_Post             $source_post Исходная запись.
     * @param string               $target_lang Код целевого языка.
     * @param array<string, mixed> $options     Опции клонирования и перевода.
     * @return array<string, mixed>|\WP_Error Результат или ошибка.
     */
    public static function duplicate_post_to_language( \WP_Post $source_post, string $target_lang, array $options = [] ): array|\WP_Error {
        if ( ! function_exists( 'pll_languages_list' ) || ! function_exists( 'pll_set_post_language' ) ) {
            return new \WP_Error( 'plu_no_polylang', __( 'Polylang не активен.', 'polyland-up' ) );
        }

        $valid_langs = (array) pll_languages_list( [ 'fields' => 'slug' ] );
        if ( ! in_array( $target_lang, $valid_langs, true ) ) {
            return new \WP_Error( 'plu_invalid_lang', sprintf( __( 'Недопустимый код языка: %s.', 'polyland-up' ), $target_lang ) );
        }

        $source_lang = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $source_post->ID, 'slug' ) : '';
        if ( '' === $source_lang ) {
            return new \WP_Error( 'plu_no_source_lang', __( 'У исходной записи не указан язык Polylang.', 'polyland-up' ) );
        }

        if ( $target_lang === $source_lang ) {
            return new \WP_Error( 'plu_same_lang', __( 'Целевой язык совпадает с исходным языком записи.', 'polyland-up' ) );
        }

        // 1. Проверка существующего перевода
        $existing_id        = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $source_post->ID, $target_lang ) : 0;
        $overwrite_existing = ! empty( $options['overwrite_existing'] );

        if ( $existing_id > 0 && ! $overwrite_existing ) {
            return new \WP_Error(
                'plu_translation_exists',
                sprintf(
                    __( 'Перевод для языка %1$s уже существует (#%2$d). Включите опцию перезаписи, чтобы обновить его.', 'polyland-up' ),
                    strtoupper( $target_lang ),
                    $existing_id
                )
            );
        }

        $is_update = $existing_id > 0;

        // 2. Определение переведенного родителя (post_parent)
        $target_parent = 0;
        if ( $source_post->post_parent > 0 && function_exists( 'pll_get_post' ) ) {
            $translated_parent = (int) pll_get_post( $source_post->post_parent, $target_lang );
            if ( $translated_parent > 0 ) {
                $target_parent = $translated_parent;
            }
        }

        // 3. Подготовка контента и точек расширения (Этапы 4–6)
        $post_title          = $source_post->post_title;
        $post_excerpt        = $source_post->post_excerpt;
        $post_content        = $source_post->post_content;
        $rank_math_meta      = [];
        $translated_count    = 0;
        $translation_stats   = [];
        $translation_summary = '';
        $mode                = (string) ( $options['mode'] ?? 'clone_only' );
        $notes               = [];

        $is_translation_mode = in_array( $mode, [ 'translate_gtx', 'translate_gemini' ], true );
        if ( $is_translation_mode ) {
            $engine = ( 'translate_gemini' === $mode ) ? 'gemini' : 'gtx';

            if (
                class_exists( \PolylangUp\Modules\DiviParser::class ) &&
                class_exists( \PolylangUp\Modules\TranslatorAPI::class ) &&
                method_exists( \PolylangUp\Modules\DiviParser::class, 'translate_post_data' )
            ) {
                $translated = \PolylangUp\Modules\DiviParser::translate_post_data(
                    $source_post,
                    $source_lang,
                    $target_lang,
                    $engine
                );
                if ( is_wp_error( $translated ) ) {
                    return $translated;
                }
                if ( is_array( $translated ) ) {
                    $post_title        = (string) ( $translated['post_title'] ?? $post_title );
                    $post_excerpt      = (string) ( $translated['post_excerpt'] ?? $post_excerpt );
                    $post_content      = (string) ( $translated['post_content'] ?? $post_content );
                    $rank_math_meta    = is_array( $translated['rank_math_meta'] ?? null ) ? $translated['rank_math_meta'] : [];
                    $translated_count  = (int) ( $translated['translated_count'] ?? 0 );
                    $translation_stats = is_array( $translated['stats'] ?? null ) ? $translated['stats'] : [];

                    if ( $translated_count > 0 ) {
                        $provider            = (string) ( $translation_stats['provider_label'] ?? ( 'gemini' === $engine ? 'Google Gemini AI' : 'Google GTX' ) );
                        $time_str            = number_format( (float) ( $translation_stats['time_sec'] ?? 0.0 ), 1, '.', '' );
                        $translation_summary = sprintf(
                            '%s (%s, %s %s)',
                            self::format_translated_blocks_count( $translated_count ),
                            $provider,
                            $time_str,
                            __( 'сек', 'polyland-up' )
                        );
                    } else {
                        $translation_summary = __( 'Текстовых блоков для перевода не найдено (выполнено клонирование)', 'polyland-up' );
                    }
                }
            } else {
                $notes[] = __( 'Клонирование выполнено. Модуль AI-парсера недоступен.', 'polyland-up' );
            }
        }

        // Закладка точки расширения для Этапа 6 (Умная перелинковка)
        if ( ! empty( $options['rewrite_links'] ) ) {
            if (
                class_exists( \PolylangUp\Modules\LinkRewriter::class ) &&
                method_exists( \PolylangUp\Modules\LinkRewriter::class, 'rewrite_content' )
            ) {
                $post_content = \PolylangUp\Modules\LinkRewriter::rewrite_content( $post_content, $target_lang );
            }
        }

        // 4. Фиксация целевого языка для модуля SameSlug перед вставкой/обновлением поста
        SameSlug::$forced_lang = $target_lang;

        try {
            $target_status = (string) ( $options['target_status'] ?? 'draft' );
            if ( ! in_array( $target_status, [ 'draft', 'publish' ], true ) ) {
                $target_status = 'draft';
            }

            // КРИТИЧНО для Divi 5: оборачиваем контент в wp_slash(), чтобы wp_insert_post не уничтожил экранирование \" в JSON блоков!
            $post_data = [
                'post_type'    => $source_post->post_type,
                'post_status'  => $target_status,
                'post_title'   => wp_slash( $post_title ),
                'post_excerpt' => wp_slash( $post_excerpt ),
                'post_content' => wp_slash( $post_content ),
                'post_parent'  => $target_parent,
                'menu_order'   => (int) $source_post->menu_order,
                'post_author'  => get_current_user_id() ?: (int) $source_post->post_author,
            ];

            if ( ! empty( $options['keep_same_slug'] ) && '' !== $source_post->post_name ) {
                $post_data['post_name'] = $source_post->post_name;
            }

            /**
             * Фильтр данных перед созданием или обновлением клонированной записи.
             *
             * @param array<string, mixed> $post_data   Массив данных поста (уже с wp_slash).
             * @param \WP_Post             $source_post Исходный пост.
             * @param string               $target_lang Целевой язык.
             * @param array<string, mixed> $options     Опции дубликатора.
             */
            $post_data = apply_filters( 'plu_before_insert_cloned_post', $post_data, $source_post, $target_lang, $options );

            if ( $is_update ) {
                $post_data['ID'] = $existing_id;
                $result_id       = wp_update_post( $post_data, true );
            } else {
                $result_id = wp_insert_post( $post_data, true );
            }

            if ( is_wp_error( $result_id ) ) {
                return $result_id;
            }

            $new_post_id = (int) $result_id;

            // 5. Привязка языка и группы переводов Polylang
            pll_set_post_language( $new_post_id, $target_lang );

            if ( function_exists( 'pll_get_post_translations' ) && function_exists( 'pll_save_post_translations' ) ) {
                $translations                 = (array) pll_get_post_translations( $source_post->ID );
                $translations[ $source_lang ] = $source_post->ID;
                $translations[ $target_lang ] = $new_post_id;
                pll_save_post_translations( $translations );
            }
        } finally {
            SameSlug::$forced_lang = null;
        }

        // 6. Копирование мета-полей (Divi 5, Rank Math, шаблоны, миниатюра) с очисткой кеша стилей
        self::copy_post_meta( $source_post->ID, $new_post_id, $is_update );

        // Перезаписываем переведенные SEO-поля Rank Math (с wp_slash для защиты спецсимволов)
        if ( ! empty( $rank_math_meta ) ) {
            foreach ( $rank_math_meta as $meta_key => $meta_value ) {
                if ( is_string( $meta_key ) && is_string( $meta_value ) && '' !== trim( $meta_value ) ) {
                    update_post_meta( $new_post_id, $meta_key, wp_slash( $meta_value ) );
                }
            }
        }

        // Хук расширения после копирования и перевода SEO-метаданных
        do_action( 'plu_after_copy_post_meta', $new_post_id, $source_post, $target_lang, $options );

        // 7. Синхронизация таксономий с поиском переведенных термов
        self::copy_post_taxonomies( $source_post, $new_post_id, $target_lang );

        // 8. Формирование результата для UI
        $view_url = (string) get_permalink( $new_post_id );

        return [
            'post_id'             => $new_post_id,
            'lang'                => $target_lang,
            'lang_upper'          => strtoupper( $target_lang ),
            'title'               => get_the_title( $new_post_id ),
            'slug'                => (string) get_post_field( 'post_name', $new_post_id ),
            'status'              => (string) get_post_status( $new_post_id ),
            'updated'             => $is_update,
            'edit_url'            => (string) get_edit_post_link( $new_post_id, 'raw' ),
            'view_url'            => $view_url,
            'divi_url'            => add_query_arg( 'et_fb', '1', $view_url ),
            'notes'               => $notes,
            'translated_count'    => $translated_count,
            'translation_stats'   => $translation_stats,
            'translation_summary' => $translation_summary,
        ];
    }

    /**
     * Копирование мета-полей с исключениями системных ключей и кеша стилей Divi.
     *
     * @param int  $source_id ID исходной записи.
     * @param int  $target_id ID целевой записи.
     * @param bool $is_update Флаг обновления существующего перевода.
     */
    public static function copy_post_meta( int $source_id, int $target_id, bool $is_update = false ): void {
        $excluded_keys = [
            '_edit_lock',
            '_edit_last',
            '_wp_old_slug',
            '_wp_old_date',
            '_pingme',
            '_encloseme',
            // Кеш стилей и шорткодов Divi 5 / Divi 4 (должен перегенерироваться для нового поста)
            '_et_pb_static_css_file',
            '_et_core_cached_shortcodes',
            '_et_dynamic_cached_shortcodes',
            '_et_builder_module_features_cache',
        ];

        // При перезаписи очищаем старый кеш стилей Divi у целевого поста
        if ( $is_update ) {
            foreach ( [
                '_et_pb_static_css_file',
                '_et_core_cached_shortcodes',
                '_et_dynamic_cached_shortcodes',
                '_et_builder_module_features_cache',
            ] as $cache_key ) {
                delete_post_meta( $target_id, $cache_key );
            }
        }

        $all_meta = get_post_custom( $source_id );
        if ( empty( $all_meta ) || ! is_array( $all_meta ) ) {
            return;
        }

        foreach ( $all_meta as $meta_key => $meta_values ) {
            if ( ! is_string( $meta_key ) || ! is_array( $meta_values ) ) {
                continue;
            }

            if ( in_array( $meta_key, $excluded_keys, true ) || str_starts_with( $meta_key, '_oembed_' ) ) {
                continue;
            }

            if ( $is_update ) {
                delete_post_meta( $target_id, $meta_key );
            }

            foreach ( $meta_values as $raw_value ) {
                $unserialized = maybe_unserialize( $raw_value );
                add_post_meta( $target_id, $meta_key, wp_slash( $unserialized ) );
            }
        }
    }

    /**
     * Синхронизация таксономий (рубрик, меток, CPT-таксономий) с привязкой переведенных термов Polylang.
     *
     * @param \WP_Post $source_post Исходный пост.
     * @param int      $target_id   ID нового/обновляемого поста.
     * @param string   $target_lang Код целевого языка.
     */
    public static function copy_post_taxonomies( \WP_Post $source_post, int $target_id, string $target_lang ): void {
        $taxonomies = get_object_taxonomies( $source_post->post_type );
        if ( empty( $taxonomies ) || ! is_array( $taxonomies ) ) {
            return;
        }

        $ignored_taxonomies = [ 'language', 'post_translations', 'term_language', 'term_translations' ];

        foreach ( $taxonomies as $taxonomy ) {
            if ( in_array( $taxonomy, $ignored_taxonomies, true ) ) {
                continue;
            }

            $terms = wp_get_object_terms( $source_post->ID, $taxonomy, [ 'fields' => 'all' ] );
            if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
                continue;
            }

            $is_translated = function_exists( 'pll_is_translated_taxonomy' ) && pll_is_translated_taxonomy( $taxonomy );
            $target_ids    = [];

            foreach ( $terms as $term ) {
                if ( ! $term instanceof \WP_Term ) {
                    continue;
                }

                if ( $is_translated && function_exists( 'pll_get_term' ) ) {
                    $translated_term_id = (int) pll_get_term( $term->term_id, $target_lang );
                    $target_ids[]       = $translated_term_id > 0 ? $translated_term_id : (int) $term->term_id;
                } else {
                    $target_ids[] = (int) $term->term_id;
                }
            }

            wp_set_object_terms( $target_id, array_values( array_unique( $target_ids ) ), $taxonomy, false );
        }
    }

    /**
     * Форматирует количество переведенных текстовых блоков с учетом русского склонения.
     *
     * @param int $count Количество переведенных элементов.
     * @return string
     */
    private static function format_translated_blocks_count( int $count ): string {
        $mod100 = $count % 100;
        $mod10  = $count % 10;

        if ( $mod100 >= 11 && $mod100 <= 14 ) {
            $word = __( 'текстовых блоков', 'polyland-up' );
        } elseif ( 1 === $mod10 ) {
            $word = __( 'текстовый блок', 'polyland-up' );
        } elseif ( $mod10 >= 2 && $mod10 <= 4 ) {
            $word = __( 'текстовых блока', 'polyland-up' );
        } else {
            $word = __( 'текстовых блоков', 'polyland-up' );
        }

        return sprintf( '%s %d %s', __( 'Переведено', 'polyland-up' ), $count, $word );
    }
}

