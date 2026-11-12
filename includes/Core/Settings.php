<?php
declare(strict_types=1);

namespace PolylangUp\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Панель настроек плагина Polylang Divi 5 AI Auto-Translator & Same Slug.
 */
final class Settings {

    public const OPTION_NAME  = 'plu_settings';
    public const PAGE_SLUG    = 'polylang-up';
    public const OPTION_GROUP = 'plu_settings_group';

    /**
     * Значения по умолчанию для опций плагина.
     *
     * @param string $key Ключ опции.
     * @return mixed Значение по умолчанию.
     */
    public static function get_default( string $key ): mixed {
        $defaults = [
            'enable_same_slug'   => 1,
            'default_translator' => 'gtx',
            'gemini_api_key'     => '',
            'gemini_model'       => 'gemini-2.5-flash',
            'system_prompt'      => "Ты — профессиональный технический SEO-локализатор для корпоративного сайта. Переводи тексты точно по смыслу, сохраняя деловой и экспертный тон. СТРОГИЕ ПРАВИЛА: 1) Сохраняй все HTML-теги, шорткоды и переменные Rank Math (%sep%, %sitename%, %title%) без изменений. 2) При переводе на английский (EN) или другие латинские языки полностью исключай остаточную кириллицу как в видимом тексте, так и в атрибутах alt/title. 3) Соблюдай терминологию из глоссария ниже.",
            'github_token'       => '',
        ];

        return $defaults[ $key ] ?? '';
    }

    /**
     * Регистрация хуков админ-панели и AJAX.
     */
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register_menu' ] );
        add_action( 'admin_init', [ self::class, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'wp_ajax_plu_test_api', [ self::class, 'ajax_test_api' ] );
        add_filter( 'plugin_action_links_' . PLU_BASENAME, [ self::class, 'add_action_links' ] );
    }

    /**
     * Добавление ссылки «Настройки» в список плагинов.
     *
     * @param array<string> $links Ссылки действий плагина.
     * @return array<string>
     */
    public static function add_action_links( array $links ): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
            esc_html__( 'Настройки', 'polyland-up' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Регистрация отдельного пункта меню верхнего уровня в админ-панели WordPress.
     */
    public static function register_menu(): void {
        add_menu_page(
            __( 'Polylang Divi 5 AI Auto-Translator & Same Slug', 'polyland-up' ),
            __( 'Polylang AI', 'polyland-up' ),
            'manage_options',
            self::PAGE_SLUG,
            [ self::class, 'render_page' ],
            'dashicons-translation',
            81
        );
    }

    /**
     * Подключение CSS и JS только на странице настроек плагина и на экранах редактирования переводимых записей.
     *
     * @param string $hook_suffix Идентификатор текущего экрана.
     */
    public static function enqueue_assets( string $hook_suffix ): void {
        $is_settings_screen = ( 'toplevel_page_' . self::PAGE_SLUG === $hook_suffix );
        $is_post_screen     = in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true );

        if ( ! $is_settings_screen && ! $is_post_screen ) {
            return;
        }

        if ( $is_post_screen ) {
            if ( ! function_exists( 'pll_is_translated_post_type' ) ) {
                return;
            }
            $screen = get_current_screen();
            if (
                ! $screen ||
                empty( $screen->post_type ) ||
                in_array( $screen->post_type, [ 'attachment', 'revision', 'nav_menu_item' ], true ) ||
                ! pll_is_translated_post_type( $screen->post_type )
            ) {
                return;
            }
        }

        wp_enqueue_style(
            'plu-admin-css',
            PLU_URL . 'assets/css/admin.css',
            [],
            PLU_VERSION
        );

        wp_enqueue_script(
            'plu-admin-js',
            PLU_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            PLU_VERSION,
            true
        );

        wp_localize_script(
            'plu-admin-js',
            'pluAdminData',
            [
                'ajax_url'    => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( 'plu_admin_nonce' ),
                'clone_nonce' => wp_create_nonce( 'plu_clone_nonce' ),
                'i18n'        => [
                    'no_langs_selected'  => __( 'Выберите хотя бы один целевой язык.', 'polyland-up' ),
                    'overwrite_required' => __( 'Вы выбрали язык(и), для которых перевод уже существует. Отметьте галочку перезаписи или снимите выделение.', 'polyland-up' ),
                    'processing_lang'    => __( 'Обработка языка', 'polyland-up' ),
                    'of'                 => __( 'из', 'polyland-up' ),
                    'done_created'       => __( 'готов!', 'polyland-up' ),
                    'done_updated'       => __( 'обновлён!', 'polyland-up' ),
                    'btn_wp_edit'        => __( '✏️ Редактор WP', 'polyland-up' ),
                    'btn_divi_builder'   => __( '🎨 Divi Builder', 'polyland-up' ),
                    'btn_view_page'      => __( '👁 Открыть страницу', 'polyland-up' ),
                    'finished_all'       => __( 'Обработка выбранных языков завершена.', 'polyland-up' ),
                ],
            ]
        );
    }

    /**
     * Регистрация полей через Settings API.
     */
    public static function register_settings(): void {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            [
                'type'              => 'array',
                'sanitize_callback' => [ self::class, 'sanitize' ],
                'default'           => [],
            ]
        );

        // 1. Секция URL и Same Slug
        add_settings_section(
            'plu_section_slug',
            __( 'Маршрутизация и структура URL', 'polyland-up' ),
            [ self::class, 'render_slug_section_intro' ],
            self::PAGE_SLUG
        );

        add_settings_field(
            'enable_same_slug',
            __( 'Одинаковые URL-слаги (Same Slug)', 'polyland-up' ),
            [ self::class, 'render_field_enable_same_slug' ],
            self::PAGE_SLUG,
            'plu_section_slug'
        );

        // 2. Секция Движка перевода и AI
        add_settings_section(
            'plu_section_ai',
            __( 'Движок перевода и AI Studio', 'polyland-up' ),
            [ self::class, 'render_ai_section_intro' ],
            self::PAGE_SLUG
        );

        add_settings_field(
            'default_translator',
            __( 'Движок перевода по умолчанию', 'polyland-up' ),
            [ self::class, 'render_field_default_translator' ],
            self::PAGE_SLUG,
            'plu_section_ai'
        );

        add_settings_field(
            'gemini_api_key',
            __( 'Gemini API Key (AI Studio)', 'polyland-up' ),
            [ self::class, 'render_field_gemini_api_key' ],
            self::PAGE_SLUG,
            'plu_section_ai',
            [ 'class' => 'plu-gemini-field' ]
        );

        add_settings_field(
            'gemini_model',
            __( 'Модель Gemini', 'polyland-up' ),
            [ self::class, 'render_field_gemini_model' ],
            self::PAGE_SLUG,
            'plu_section_ai',
            [ 'class' => 'plu-gemini-field' ]
        );

        add_settings_field(
            'system_prompt',
            __( 'Системный промпт и Глоссарий', 'polyland-up' ),
            [ self::class, 'render_field_system_prompt' ],
            self::PAGE_SLUG,
            'plu_section_ai',
            [ 'class' => 'plu-gemini-field' ]
        );

        // Поле проверки подключения к API
        add_settings_field(
            'api_connection_test',
            __( 'Тестирование API', 'polyland-up' ),
            [ self::class, 'render_field_api_test' ],
            self::PAGE_SLUG,
            'plu_section_ai'
        );

        // 3. Секция автономных обновлений
        add_settings_section(
            'plu_section_updater',
            __( 'Автономное обновление с GitHub', 'polyland-up' ),
            [ self::class, 'render_updater_section_intro' ],
            self::PAGE_SLUG
        );

        add_settings_field(
            'github_token',
            __( 'GitHub Access Token', 'polyland-up' ),
            [ self::class, 'render_field_github_token' ],
            self::PAGE_SLUG,
            'plu_section_updater'
        );
    }

    /**
     * Валидация и санитизация входящих параметров формы.
     *
     * @param mixed $input Входящие данные.
     * @return array<string, mixed> Очищенные данные.
     */
    public static function sanitize( mixed $input ): array {
        if ( ! is_array( $input ) ) {
            return [];
        }

        $sanitized = [];

        // Checkbox Same Slug
        $sanitized['enable_same_slug'] = ! empty( $input['enable_same_slug'] ) ? 1 : 0;

        // Translator select
        $translator = sanitize_key( (string) ( $input['default_translator'] ?? 'gtx' ) );
        $sanitized['default_translator'] = in_array( $translator, [ 'gtx', 'gemini' ], true ) ? $translator : 'gtx';

        // Gemini API Key
        $sanitized['gemini_api_key'] = sanitize_text_field( trim( (string) ( $input['gemini_api_key'] ?? '' ) ) );

        // Gemini Model
        $model = sanitize_text_field( trim( (string) ( $input['gemini_model'] ?? 'gemini-2.5-flash' ) ) );
        $sanitized['gemini_model'] = '' !== $model ? $model : 'gemini-2.5-flash';

        // System Prompt
        $prompt = sanitize_textarea_field( (string) ( $input['system_prompt'] ?? '' ) );
        $sanitized['system_prompt'] = '' !== trim( $prompt ) ? $prompt : self::get_default( 'system_prompt' );

        // GitHub Token
        $raw_token = trim( (string) ( $input['github_token'] ?? '' ) );
        $sanitized['github_token'] = preg_replace( '/[^A-Za-z0-9_]/', '', $raw_token ) ?? '';

        return $sanitized;
    }

    public static function render_slug_section_intro(): void {
        echo '<p class="description">' . esc_html__( 'Управление структурой постоянных ссылок (Permalinks) и слагов страниц.', 'polyland-up' ) . '</p>';
    }

    public static function render_ai_section_intro(): void {
        echo '<p class="description">' . esc_html__( 'Настройка интеграции с бесплатным Google Translate и нейросетевым Gemini API для качественной локализации.', 'polyland-up' ) . '</p>';
    }

    public static function render_updater_section_intro(): void {
        echo '<p class="description">' . esc_html__( 'Интеграция с GitHub для проверки и установки обновлений напрямую из репозитория piratexnik/Polyland-Up.', 'polyland-up' ) . '</p>';
    }

    public static function render_field_enable_same_slug(): void {
        $value = (int) plu_get_option( 'enable_same_slug', self::get_default( 'enable_same_slug' ) );
        ?>
        <label for="plu_enable_same_slug">
            <input
                type="checkbox"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enable_same_slug]"
                id="plu_enable_same_slug"
                value="1"
                <?php checked( 1, $value ); ?>
            />
            <?php esc_html_e( 'Разрешить одинаковые URL-слаги (Same Slug) между языками', 'polyland-up' ); ?>
        </label>
        <p class="description">
            <?php esc_html_e( 'Позволяет страницам на разных языках иметь идентичный URL-slug (например, site.com/en/about/ и site.com/ru/about/).', 'polyland-up' ); ?>
        </p>
        <?php
    }

    public static function render_field_default_translator(): void {
        $selected = (string) plu_get_option( 'default_translator', self::get_default( 'default_translator' ) );
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_translator]" id="plu_default_translator">
            <option value="gtx" <?php selected( 'gtx', $selected ); ?>>
                <?php esc_html_e( 'Google Translate (Free GTX)', 'polyland-up' ); ?>
            </option>
            <option value="gemini" <?php selected( 'gemini', $selected ); ?>>
                <?php esc_html_e( 'Google Gemini API (AI Studio)', 'polyland-up' ); ?>
            </option>
        </select>
        <p class="description">
            <?php esc_html_e( 'Выберите движок, который будет использоваться для пакетного и метабокс-перевода.', 'polyland-up' ); ?>
        </p>
        <?php
    }

    public static function render_field_gemini_api_key(): void {
        $key = (string) plu_get_option( 'gemini_api_key', '' );
        ?>
        <input
            type="password"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gemini_api_key]"
            id="plu_gemini_api_key"
            value="<?php echo esc_attr( $key ); ?>"
            class="regular-text"
            autocomplete="new-password"
        />
        <p class="description">
            <?php
            printf(
                __( 'Бесплатный ключ можно сгенерировать в <a href="%s" target="_blank" rel="noopener noreferrer">Google AI Studio</a>.', 'polyland-up' ),
                esc_url( 'https://aistudio.google.com/app/apikey' )
            );
            ?>
        </p>
        <?php
    }

    public static function render_field_gemini_model(): void {
        $model = (string) plu_get_option( 'gemini_model', self::get_default( 'gemini_model' ) );
        ?>
        <input
            type="text"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gemini_model]"
            id="plu_gemini_model"
            list="plu-gemini-models"
            value="<?php echo esc_attr( $model ); ?>"
            class="regular-text"
        />
        <datalist id="plu-gemini-models">
            <option value="gemini-2.5-flash"></option>
            <option value="gemini-2.5-pro"></option>
            <option value="gemini-2.0-flash"></option>
        </datalist>
        <p class="description">
            <?php esc_html_e( 'Выберите модель из списка или введите любое кастомное имя модели Google Gemini.', 'polyland-up' ); ?>
        </p>
        <?php
    }

    public static function render_field_system_prompt(): void {
        $prompt = (string) plu_get_option( 'system_prompt', self::get_default( 'system_prompt' ) );
        ?>
        <textarea
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[system_prompt]"
            id="plu_system_prompt"
            rows="8"
            class="large-text"
        ><?php echo esc_textarea( $prompt ); ?></textarea>
        <p class="description">
            <?php esc_html_e( 'Инструкция для модели Gemini при переводе контента, шорткодов Divi 5 и переменных Rank Math SEO.', 'polyland-up' ); ?>
        </p>
        <?php
    }

    public static function render_field_api_test(): void {
        ?>
        <div style="display: flex; align-items: center; gap: 10px;">
            <button type="button" class="button button-secondary" id="plu-btn-test-api">
                <?php esc_html_e( '🔌 Проверить соединение с API', 'polyland-up' ); ?>
            </button>
            <span class="spinner" id="plu-api-test-spinner" style="float: none; margin: 0;"></span>
        </div>
        <p class="description">
            <?php esc_html_e( 'Тестирует текущие введенные параметры переводчика (даже без предварительного сохранения настроек).', 'polyland-up' ); ?>
        </p>
        <div id="plu-api-test-result"></div>
        <?php
    }

    public static function render_field_github_token(): void {
        $token = (string) plu_get_option( 'github_token', '' );
        ?>
        <input
            type="password"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[github_token]"
            id="plu_github_token"
            value="<?php echo esc_attr( $token ); ?>"
            class="regular-text"
            autocomplete="new-password"
        />
        <p class="description">
            <?php esc_html_e( 'Опционально. Укажите GitHub Personal Access Token, если репозиторий piratexnik/Polyland-Up является приватным.', 'polyland-up' ); ?>
        </p>
        <?php
    }

    /**
     * AJAX-обработчик тестирования соединения с API перевода.
     */
    public static function ajax_test_api(): void {
        check_ajax_referer( 'plu_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [
                'message' => esc_html__( 'У вас недостаточно прав для выполнения этого действия.', 'polyland-up' ),
            ] );
        }

        $translator = sanitize_key( (string) ( $_POST['translator'] ?? 'gtx' ) );
        $api_key    = sanitize_text_field( wp_unslash( (string) ( $_POST['api_key'] ?? '' ) ) );
        $model      = sanitize_text_field( wp_unslash( (string) ( $_POST['model'] ?? 'gemini-2.5-flash' ) ) );

        if ( '' === $model ) {
            $model = 'gemini-2.5-flash';
        }

        if ( 'gtx' === $translator ) {
            self::test_gtx_api();
        } elseif ( 'gemini' === $translator ) {
            self::test_gemini_api( $api_key, $model );
        } else {
            wp_send_json_error( [
                'message' => esc_html__( 'Неизвестный тип переводчика.', 'polyland-up' ),
            ] );
        }
    }

    /**
     * Каскадный перевод текста через бесплатные каналы (clients5 -> gtx -> mymemory)
     * с реалистичным браузерным User-Agent для обхода HTTP 429 на shared-хостингах.
     *
     * @param string $text Исходный текст.
     * @param string $sl   Исходный язык (по умолчанию 'en').
     * @param string $tl   Целевой язык (по умолчанию 'ru').
     * @return array{success: bool, channel: string, channel_label: string, translated: string, time_ms: int, errors: array<string>}
     */
    public static function translate_free_cascade( string $text, string $sl = 'en', string $tl = 'ru' ): array {
        $browser_ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
        $request_args = [
            'timeout'    => 10,
            'user-agent' => $browser_ua,
            'headers'    => [
                'Accept'          => 'application/json, text/plain, */*',
                'Accept-Language' => 'en-US,en;q=0.9,ru;q=0.8',
            ],
        ];

        $encoded_text = urlencode( $text );

        $channels = [
            'clients5' => [
                'label' => 'Google GTX / clients5',
                'url'   => "https://clients5.google.com/translate_a/t?client=dict-chrome-ex&sl={$sl}&tl={$tl}&q={$encoded_text}",
            ],
            'gtx' => [
                'label' => 'Google GTX / gtx',
                'url'   => "https://translate.googleapis.com/translate_a/single?client=gtx&sl={$sl}&tl={$tl}&dt=t&q={$encoded_text}",
            ],
            'mymemory' => [
                'label' => 'MyMemory API (Fallback)',
                'url'   => "https://api.mymemory.translated.net/get?q={$encoded_text}&langpair={$sl}|{$tl}",
            ],
        ];

        $errors = [];

        foreach ( $channels as $channel_key => $channel_cfg ) {
            $start    = microtime( true );
            $response = wp_remote_get( $channel_cfg['url'], $request_args );
            $elapsed  = (int) round( ( microtime( true ) - $start ) * 1000 );

            if ( is_wp_error( $response ) ) {
                $errors[] = $channel_key . ': ' . $response->get_error_message();
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( 200 !== $code ) {
                $errors[] = $channel_key . ': HTTP ' . $code;
                continue;
            }

            $body = (string) wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );

            if ( ! is_array( $data ) ) {
                $errors[] = $channel_key . ': некорректный JSON';
                continue;
            }

            $translated = self::extract_free_channel_translation( $channel_key, $data );
            if ( '' !== $translated ) {
                return [
                    'success'       => true,
                    'channel'       => $channel_key,
                    'channel_label' => $channel_cfg['label'],
                    'translated'    => $translated,
                    'time_ms'       => $elapsed,
                    'errors'        => $errors,
                ];
            }

            $errors[] = $channel_key . ': пустой ответ или лимит';
        }

        return [
            'success'       => false,
            'channel'       => '',
            'channel_label' => '',
            'translated'    => '',
            'time_ms'       => 0,
            'errors'        => $errors,
        ];
    }

    /**
     * Извлечение переведенной строки из JSON-ответа конкретного канала.
     *
     * @param string               $channel_key Ключ канала ('clients5', 'gtx', 'mymemory').
     * @param array<mixed, mixed>  $data        Декодированный JSON.
     * @return string
     */
    private static function extract_free_channel_translation( string $channel_key, array $data ): string {
        if ( 'clients5' === $channel_key ) {
            // Формат [["Перевод"]] или [["Перевод", "en"]]
            if ( isset( $data[0] ) && is_array( $data[0] ) && isset( $data[0][0] ) && is_string( $data[0][0] ) ) {
                return trim( $data[0][0] );
            }
            // Формат ["Перевод"]
            if ( isset( $data[0] ) && is_string( $data[0] ) ) {
                return trim( $data[0] );
            }
            // Альтернативный формат {"sentences":[{"trans":"..."}]}
            if ( isset( $data['sentences'] ) && is_array( $data['sentences'] ) ) {
                $parts = [];
                foreach ( $data['sentences'] as $sentence ) {
                    if ( is_array( $sentence ) && isset( $sentence['trans'] ) && is_string( $sentence['trans'] ) ) {
                        $parts[] = $sentence['trans'];
                    }
                }
                return trim( implode( '', $parts ) );
            }
            return '';
        }

        if ( 'gtx' === $channel_key ) {
            if ( isset( $data[0] ) && is_array( $data[0] ) ) {
                $parts = [];
                foreach ( $data[0] as $segment ) {
                    if ( is_array( $segment ) && isset( $segment[0] ) && is_string( $segment[0] ) ) {
                        $parts[] = $segment[0];
                    }
                }
                return trim( implode( '', $parts ) );
            }
            return '';
        }

        if ( 'mymemory' === $channel_key ) {
            $status = isset( $data['responseStatus'] ) ? (int) $data['responseStatus'] : 200;
            if ( 200 !== $status ) {
                return '';
            }
            if ( isset( $data['responseData']['translatedText'] ) && is_string( $data['responseData']['translatedText'] ) ) {
                $text = trim( $data['responseData']['translatedText'] );
                if ( '' !== $text && ! str_contains( $text, 'MYMEMORY WARNING' ) ) {
                    return $text;
                }
            }
            return '';
        }

        return '';
    }

    /**
     * Тестирование бесплатного переводчика (каскад clients5 -> gtx -> mymemory).
     */
    private static function test_gtx_api(): void {
        $result = self::translate_free_cascade( 'Connection successful', 'en', 'ru' );

        if ( $result['success'] ) {
            wp_send_json_success( [
                'engine'     => $result['channel_label'],
                'channel'    => $result['channel'],
                'translated' => $result['translated'],
                'time_ms'    => $result['time_ms'],
                'message'    => sprintf(
                    esc_html__( 'Соединение успешно (%1$s, %2$d мс): «%3$s»', 'polyland-up' ),
                    esc_html( $result['channel_label'] ),
                    $result['time_ms'],
                    esc_html( $result['translated'] )
                ),
            ] );
        }

        $errors_summary = ! empty( $result['errors'] ) ? implode( '; ', $result['errors'] ) : __( 'Все узлы недоступны', 'polyland-up' );
        wp_send_json_error( [
            'message' => sprintf(
                esc_html__( 'Не удалось подключиться ни к одному бесплатному каналу (%s).', 'polyland-up' ),
                esc_html( $errors_summary )
            ),
        ] );
    }

    /**
     * Тестирование Google Gemini API.
     *
     * @param string $api_key Ключ Google AI Studio.
     * @param string $model   Имя модели.
     */
    private static function test_gemini_api( string $api_key, string $model ): void {
        // Если ключ не передан в форме, проверяем сохраненный в БД
        if ( '' === $api_key ) {
            $api_key = (string) plu_get_option( 'gemini_api_key', '' );
        }

        if ( '' === $api_key ) {
            wp_send_json_error( [
                'message' => esc_html__( 'API-ключ Gemini не указан. Введите ключ Google AI Studio в поле выше.', 'polyland-up' ),
            ] );
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $api_key );

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => 'Translate "Connection successful" to Russian in JSON: {"result": "..."}',
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'temperature'        => 0.1,
            ],
        ];

        $start = microtime( true );
        $response = wp_remote_post( $url, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
        ] );
        $elapsed = (int) round( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [
                'message' => sprintf(
                    esc_html__( 'Сетевая ошибка при запросе к Gemini API: %s', 'polyland-up' ),
                    $response->get_error_message()
                ),
                'time_ms' => $elapsed,
            ] );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( 200 === $code && is_array( $data ) ) {
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $parsed = is_string( $text ) ? json_decode( $text, true ) : null;
            $translated = ( is_array( $parsed ) && isset( $parsed['result'] ) ) ? (string) $parsed['result'] : trim( (string) $text );

            if ( '' === $translated ) {
                $translated = 'Успешное подключение';
            }

            wp_send_json_success( [
                'engine'     => 'Google Gemini API',
                'model'      => $model,
                'translated' => $translated,
                'time_ms'    => $elapsed,
                'message'    => sprintf(
                    esc_html__( 'Модель %s успешно ответила за %d мс.', 'polyland-up' ),
                    esc_html( $model ),
                    $elapsed
                ),
            ] );
        }

        // Обработка ошибок Google API
        $error_message = $data['error']['message'] ?? '';
        $error_status  = $data['error']['status'] ?? '';

        if ( 400 === $code ) {
            $desc = sprintf(
                esc_html__( 'Некорректный запрос (HTTP 400): %s', 'polyland-up' ),
                esc_html( $error_message ?: 'Проверьте структуру запроса или имя модели.' )
            );
        } elseif ( 403 === $code || 'PERMISSION_DENIED' === $error_status ) {
            $desc = sprintf(
                esc_html__( 'Ошибка доступа (HTTP 403): Неверный API-ключ или доступ к модели %s ограничен. Детали: %s', 'polyland-up' ),
                esc_html( $model ),
                esc_html( $error_message )
            );
        } elseif ( 404 === $code || 'NOT_FOUND' === $error_status ) {
            $desc = sprintf(
                esc_html__( 'Модель не найдена (HTTP 404): Модель "%s" не поддерживается в v1beta API. Выберите gemini-2.5-flash или gemini-2.0-flash.', 'polyland-up' ),
                esc_html( $model )
            );
        } elseif ( 429 === $code || 'RESOURCE_EXHAUSTED' === $error_status ) {
            $desc = sprintf(
                esc_html__( 'Превышен лимит запросов (HTTP 429): %s', 'polyland-up' ),
                esc_html( $error_message )
            );
        } else {
            $desc = sprintf(
                esc_html__( 'Ошибка Gemini API (HTTP %d): %s', 'polyland-up' ),
                $code,
                esc_html( $error_message ?: 'Неизвестный сбой.' )
            );
        }

        wp_send_json_error( [
            'message' => $desc,
            'time_ms' => $elapsed,
        ] );
    }

    /**
     * Отрисовка страницы настроек со статус-баром и формой.
     */
    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'У вас недостаточно прав для доступа к этой странице.', 'polyland-up' ) );
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
        $tabs = [
            'general' => __( 'Основные', 'polyland-up' ),
        ];

        // Статус Polylang
        $is_polylang_active = function_exists( 'pll_current_language' ) || defined( 'POLYLANG_VERSION' );
        $polylang_version   = defined( 'POLYLANG_VERSION' ) ? POLYLANG_VERSION : ( $is_polylang_active ? 'active' : '' );

        // Статус Rank Math SEO
        $is_rankmath_active = defined( 'RANK_MATH_VERSION' );
        $rankmath_version   = $is_rankmath_active ? RANK_MATH_VERSION : '';
        ?>
        <div class="wrap plu-settings-wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <?php settings_errors(); ?>

            <!-- Статус-бар окружения -->
            <div class="plu-status-bar">
                <div class="plu-status-items">
                    <!-- Polylang -->
                    <div class="plu-status-item">
                        <strong><?php esc_html_e( 'Polylang:', 'polyland-up' ); ?></strong>
                        <?php if ( $is_polylang_active ) : ?>
                            <span class="plu-badge plu-badge-success">
                                &#10003; <?php printf( esc_html__( 'Активен (%s)', 'polyland-up' ), esc_html( $polylang_version ) ); ?>
                            </span>
                        <?php else : ?>
                            <span class="plu-badge plu-badge-warning">
                                &#9888; <?php esc_html_e( 'Не активен', 'polyland-up' ); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Rank Math -->
                    <div class="plu-status-item">
                        <strong><?php esc_html_e( 'Rank Math SEO:', 'polyland-up' ); ?></strong>
                        <?php if ( $is_rankmath_active ) : ?>
                            <span class="plu-badge plu-badge-success">
                                &#10003; <?php printf( esc_html__( 'Активен (v%s)', 'polyland-up' ), esc_html( $rankmath_version ) ); ?>
                            </span>
                        <?php else : ?>
                            <span class="plu-badge plu-badge-neutral">
                                &#9675; <?php esc_html_e( 'Не активен', 'polyland-up' ); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="plu-version-info">
                    <?php printf( esc_html__( 'Версия плагина: v%s', 'polyland-up' ), esc_html( PLU_VERSION ) ); ?>
                </div>
            </div>

            <!-- Вкладки настроек -->
            <nav class="nav-tab-wrapper wp-clearfix">
                <?php foreach ( $tabs as $tab_key => $tab_title ) : ?>
                    <a
                        href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => $tab_key ], admin_url( 'admin.php' ) ) ); ?>"
                        class="nav-tab <?php echo ( $active_tab === $tab_key ) ? 'nav-tab-active' : ''; ?>"
                    >
                        <?php echo esc_html( $tab_title ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="plu-tab-content" style="margin-top: 20px;">
                <?php if ( 'general' === $active_tab ) : ?>
                    <form method="post" action="options.php">
                        <?php
                        settings_fields( self::OPTION_GROUP );
                        do_settings_sections( self::PAGE_SLUG );
                        submit_button( __( 'Сохранить изменения', 'polyland-up' ) );
                        ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
