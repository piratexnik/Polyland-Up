<?php
declare(strict_types=1);

namespace PolylangUp\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Панель настроек плагина Polylang Up на базе Settings API.
 */
final class Settings {

    public const OPTION_NAME = 'plu_settings';
    public const PAGE_SLUG   = 'polylang-up';
    public const OPTION_GROUP = 'plu_settings_group';

    /**
     * Регистрация хуков админ-панели.
     */
    public static function init(): void {
        if ( ! is_admin() ) {
            return;
        }

        add_action( 'admin_menu', [ self::class, 'register_menu' ] );
        add_action( 'admin_init', [ self::class, 'register_settings' ] );
    }

    /**
     * Добавление страницы плагина в меню «Настройки».
     */
    public static function register_menu(): void {
        add_options_page(
            __( 'Polylang Up', 'polylang-up' ),
            __( 'Polylang Up', 'polylang-up' ),
            'manage_options',
            self::PAGE_SLUG,
            [ self::class, 'render_page' ]
        );
    }

    /**
     * Регистрация настроек через Settings API.
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

        // Секция основных настроек
        add_settings_section(
            'plu_section_general',
            __( 'Параметры интеграции и обновлений', 'polylang-up' ),
            [ self::class, 'render_general_section_intro' ],
            self::PAGE_SLUG
        );

        // Поле GitHub Token
        add_settings_field(
            'github_token',
            __( 'GitHub Personal Access Token', 'polylang-up' ),
            [ self::class, 'render_field_github_token' ],
            self::PAGE_SLUG,
            'plu_section_general'
        );
    }

    /**
     * Валидация и санитизация входящих данных формы.
     *
     * @param mixed $input Сырые данные из формы.
     * @return array<string, mixed> Очищенные данные.
     */
    public static function sanitize( mixed $input ): array {
        if ( ! is_array( $input ) ) {
            return [];
        }

        $sanitized = [];

        // Санитизация GitHub токена
        if ( isset( $input['github_token'] ) ) {
            $raw_token = trim( (string) $input['github_token'] );
            // Сохраняем только валидные символы токена GitHub (classic ghp_... или fine-grained github_pat_...)
            $sanitized['github_token'] = preg_replace( '/[^A-Za-z0-9_]/', '', $raw_token ) ?? '';
        } else {
            $sanitized['github_token'] = '';
        }

        return $sanitized;
    }

    /**
     * Описание секции основных настроек.
     */
    public static function render_general_section_intro(): void {
        echo '<p class="description">' . esc_html__( 'Базовые параметры взаимодействия плагина с удаленным репозиторием и WordPress.', 'polylang-up' ) . '</p>';
    }

    /**
     * Рендер поля github_token.
     */
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
            <?php esc_html_e( 'Опционально. Укажите GitHub Personal Access Token, если репозиторий приватный.', 'polylang-up' ); ?>
        </p>
        <?php
    }

    /**
     * Отрисовка страницы настроек с вкладками и статусом Polylang.
     */
    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'У вас недостаточно прав для доступа к этой странице.', 'polylang-up' ) );
        }

        // Поддержка переключения вкладок
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
        $tabs = [
            'general' => __( 'Основные', 'polylang-up' ),
        ];

        $is_polylang_active = defined( 'POLYLANG_VERSION' );
        $polylang_version   = $is_polylang_active ? POLYLANG_VERSION : '';
        ?>
        <div class="wrap plu-settings-wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <!-- Индикатор статуса Polylang -->
            <div class="plu-status-bar" style="margin: 15px 0 20px; padding: 12px 16px; background: #fff; border-left: 4px solid <?php echo $is_polylang_active ? '#00a32a' : '#dba617'; ?>; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-radius: 2px;">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <strong><?php esc_html_e( 'Статус Polylang:', 'polylang-up' ); ?></strong>
                        <?php if ( $is_polylang_active ) : ?>
                            <span style="display: inline-block; margin-left: 8px; padding: 3px 8px; background: #edfaef; color: #1a7a28; border-radius: 3px; font-weight: 600; font-size: 13px;">
                                &#10003; <?php printf( esc_html__( 'Активен (v%s)', 'polylang-up' ), esc_html( $polylang_version ) ); ?>
                            </span>
                        <?php else : ?>
                            <span style="display: inline-block; margin-left: 8px; padding: 3px 8px; background: #fcf9e8; color: #996800; border-radius: 3px; font-weight: 600; font-size: 13px;">
                                &#9888; <?php esc_html_e( 'Плагин Polylang не активен или не установлен. Для полной функциональности активируйте Polylang.', 'polylang-up' ); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <span style="color: #646970; font-size: 12px;">
                            <?php printf( esc_html__( 'Polylang Up v%s', 'polylang-up' ), esc_html( PLU_VERSION ) ); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Навигация по вкладкам -->
            <nav class="nav-tab-wrapper wp-clearfix">
                <?php foreach ( $tabs as $tab_key => $tab_title ) : ?>
                    <a
                        href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => $tab_key ], admin_url( 'options-general.php' ) ) ); ?>"
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
                        submit_button( __( 'Сохранить изменения', 'polylang-up' ) );
                        ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
