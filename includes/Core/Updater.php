<?php
declare(strict_types=1);

namespace PolylangUp\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Автономная система проверки и установки обновлений с GitHub.
 */
final class Updater {

    public const REPO_OWNER      = 'piratexnik';
    public const REPO_NAME       = 'Polyland-Up';
    public const REPO_FULL       = 'piratexnik/Polyland-Up';
    public const MAIN_FILE       = 'Polyland-Up.php';
    public const PLUGIN_SLUG     = 'Polyland-Up';
    public const TRANSIENT_CHECK = 'plu_github_update_check';
    public const TRANSIENT_ERROR = 'plu_github_update_error';

    /**
     * Инициализация хуков механизма обновлений.
     */
    public static function init(): void {
        // Хуки встраивания в ядро обновлений WordPress
        add_filter( 'pre_set_site_transient_update_plugins', [ self::class, 'check_update' ] );
        add_filter( 'site_transient_update_plugins', [ self::class, 'filter_update_plugins_read' ] );
        add_filter( 'plugins_api', [ self::class, 'plugin_info' ], 20, 3 );
        add_filter( 'upgrader_source_selection', [ self::class, 'fix_source_directory' ], 10, 4 );
        add_filter( 'http_request_args', [ self::class, 'authorize_download_request' ], 10, 2 );

        // Интерфейс ручной проверки в списке плагинов
        if ( is_admin() ) {
            add_filter( 'plugin_row_meta', [ self::class, 'add_row_meta' ], 10, 2 );
            add_action( 'admin_init', [ self::class, 'handle_force_check' ] );
            add_action( 'admin_notices', [ self::class, 'render_admin_notices' ] );
        }
    }

    /**
     * Чтение локальной версии напрямую с диска без кеширования в RAM или get_plugin_data.
     *
     * @return string Текущая версия плагина на диске.
     */
    public static function get_local_version(): string {
        if ( ! defined( 'PLU_FILE' ) || ! file_exists( PLU_FILE ) ) {
            return defined( 'PLU_VERSION' ) ? PLU_VERSION : '1.0';
        }

        $content = @file_get_contents( PLU_FILE, false, null, 0, 8192 );
        if ( false !== $content && preg_match( '/Version:\s*([0-9.]+)/i', $content, $matches ) ) {
            return trim( $matches[1] );
        }

        return defined( 'PLU_VERSION' ) ? PLU_VERSION : '1.0';
    }

    /**
     * Запрос удаленной версии с GitHub (кеш 12 часов, при ошибке пауза 10 минут).
     *
     * @param bool $force Принудительный запрос в обход кеша.
     * @return string|null Удаленная версия или null при ошибке.
     */
    public static function get_remote_version( bool $force = false ): ?string {
        if ( ! $force ) {
            // Если была ошибка, не спамим запросами следующие 10 минут
            if ( false !== get_transient( self::TRANSIENT_ERROR ) ) {
                return null;
            }

            $cached = get_transient( self::TRANSIENT_CHECK );
            if ( is_string( $cached ) && '' !== $cached ) {
                return $cached;
            }
        }

        $url = 'https://raw.githubusercontent.com/' . self::REPO_FULL . '/main/' . self::MAIN_FILE . '?t=' . time();

        $args = [
            'timeout'    => 10,
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            'headers'    => [
                'Accept' => 'text/plain',
            ],
        ];

        $token = trim( (string) plu_get_option( 'github_token', '' ) );
        if ( '' !== $token ) {
            $args['headers']['Authorization'] = 'token ' . $token;
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            $err_diag = sprintf( 'Сетевая ошибка: %s (URL: %s)', $response->get_error_message(), $url );
            set_transient( self::TRANSIENT_ERROR, $err_diag, 10 * MINUTE_IN_SECONDS );
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            $err_diag = sprintf( 'HTTP %d: URL %s — проверьте, что репозиторий Public или укажите токен', $code, $url );
            set_transient( self::TRANSIENT_ERROR, $err_diag, 10 * MINUTE_IN_SECONDS );
            return null;
        }

        $body = (string) wp_remote_retrieve_body( $response );
        if ( preg_match( '/Version:\s*([0-9.]+)/i', $body, $matches ) ) {
            $remote_version = trim( $matches[1] );
            set_transient( self::TRANSIENT_CHECK, $remote_version, 12 * HOUR_IN_SECONDS );
            delete_transient( self::TRANSIENT_ERROR );
            return $remote_version;
        }

        $err_diag = sprintf( 'В файле не найден заголовок Version (URL: %s)', $url );
        set_transient( self::TRANSIENT_ERROR, $err_diag, 10 * MINUTE_IN_SECONDS );
        return null;
    }

    /**
     * Формирование URL скачивания zip-пакета.
     *
     * @param string $remote_version Версия для скачивания.
     * @return string Прямая ссылка на архив.
     */
    public static function get_download_package( string $remote_version ): string {
        $token = trim( (string) plu_get_option( 'github_token', '' ) );
        if ( '' !== $token ) {
            return 'https://api.github.com/repos/' . self::REPO_FULL . '/zipball/v' . $remote_version;
        }

        return 'https://github.com/' . self::REPO_FULL . '/archive/refs/tags/v' . $remote_version . '.zip';
    }

    /**
     * Авторизация HTTP-запросов скачивания архива для приватных репозиториев.
     *
     * @param array<string, mixed> $args Параметры запроса.
     * @param string               $url  Запрашиваемый URL.
     * @return array<string, mixed>
     */
    public static function authorize_download_request( array $args, string $url ): array {
        $token = trim( (string) plu_get_option( 'github_token', '' ) );
        if ( '' === $token ) {
            if ( isset( $args['headers']['Authorization'] ) ) {
                unset( $args['headers']['Authorization'] );
            }
            return $args;
        }

        $is_repo_request = false;
        if (
            stripos( $url, 'api.github.com/repos/' . self::REPO_FULL ) !== false ||
            stripos( $url, 'github.com/' . self::REPO_FULL ) !== false ||
            stripos( $url, 'raw.githubusercontent.com/' . self::REPO_FULL ) !== false
        ) {
            $is_repo_request = true;
        }

        if ( $is_repo_request ) {
            if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
                $args['headers'] = [];
            }
            $args['headers']['Authorization'] = 'token ' . $token;

            if ( stripos( $url, 'api.github.com' ) !== false ) {
                $args['headers']['Accept'] = 'application/vnd.github+json';
            }
        }

        return $args;
    }

    /**
     * Интеграция в pre_set_site_transient_update_plugins.
     *
     * @param mixed $transient Системный транзиент обновлений плагинов.
     * @return mixed
     */
    public static function check_update( mixed $transient ): mixed {
        if ( ! is_object( $transient ) ) {
            $transient = new \stdClass();
        }

        $local_version = self::get_local_version();

        // Аккуратно дополняем checked, сохраняя чужие плагины
        if ( ! isset( $transient->checked ) || ! is_array( $transient->checked ) ) {
            $transient->checked = [];
        }
        $transient->checked[ PLU_BASENAME ] = $local_version;

        $remote_version = self::get_remote_version();

        if ( $remote_version && version_compare( $remote_version, $local_version, '>' ) ) {
            $update_item = new \stdClass();
            $update_item->id            = self::REPO_FULL;
            $update_item->slug          = self::PLUGIN_SLUG;
            $update_item->plugin        = PLU_BASENAME;
            $update_item->new_version   = $remote_version;
            $update_item->url           = 'https://github.com/' . self::REPO_FULL;
            $update_item->package       = self::get_download_package( $remote_version );
            $update_item->requires_php  = '8.1';
            $update_item->requires      = '6.5';
            $update_item->tested        = '6.7';

            if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
                $transient->response = [];
            }
            $transient->response[ PLU_BASENAME ] = $update_item;

            if ( isset( $transient->no_update[ PLU_BASENAME ] ) ) {
                unset( $transient->no_update[ PLU_BASENAME ] );
            }
        } else {
            if ( isset( $transient->response[ PLU_BASENAME ] ) ) {
                unset( $transient->response[ PLU_BASENAME ] );
            }

            $no_update = new \stdClass();
            $no_update->id           = self::REPO_FULL;
            $no_update->slug         = self::PLUGIN_SLUG;
            $no_update->plugin       = PLU_BASENAME;
            $no_update->new_version  = $local_version;
            $no_update->url          = 'https://github.com/' . self::REPO_FULL;
            $no_update->package      = '';
            $no_update->requires_php = '8.1';
            $no_update->requires     = '6.5';

            if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
                $transient->no_update = [];
            }
            $transient->no_update[ PLU_BASENAME ] = $no_update;
        }

        return $transient;
    }

    /**
     * Фильтр site_transient_update_plugins на чтение (без HTTP-запросов!).
     * Если локальная версия на диске уже >= новой версии в кеше — удаляет плагин из response.
     *
     * @param mixed $transient Транзиент плагинов.
     * @return mixed
     */
    public static function filter_update_plugins_read( mixed $transient ): mixed {
        if ( ! is_object( $transient ) || empty( $transient->response[ PLU_BASENAME ] ) ) {
            return $transient;
        }

        $item = $transient->response[ PLU_BASENAME ];
        $new_version = is_object( $item ) ? ( $item->new_version ?? '' ) : ( $item['new_version'] ?? '' );

        if ( ! empty( $new_version ) ) {
            $local_version = self::get_local_version();
            if ( version_compare( $local_version, (string) $new_version, '>=' ) ) {
                unset( $transient->response[ PLU_BASENAME ] );
            }
        }

        return $transient;
    }

    /**
     * Корректировка директории после распаковки архива GitHub в правильный slug "Polyland-Up".
     *
     * @param string      $source        Текущий путь распакованной папки.
     * @param string      $remote_source Базовая временная директория распаковки.
     * @param mixed       $upgrader      Экземпляр WP_Upgrader.
     * @param array|null  $hook_extra    Дополнительные данные хука.
     * @return string Исправленный путь к папке плагина.
     */
    public static function fix_source_directory( string $source, string $remote_source, mixed $upgrader, ?array $hook_extra = null ): string {
        global $wp_filesystem;

        $is_our_plugin = false;
        if ( is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === PLU_BASENAME ) {
            $is_our_plugin = true;
        } elseif ( stripos( $source, self::PLUGIN_SLUG ) !== false ) {
            $is_our_plugin = true;
        }

        if ( ! $is_our_plugin || ! is_object( $wp_filesystem ) ) {
            return $source;
        }

        $corrected_source = trailingslashit( $remote_source ) . self::PLUGIN_SLUG . '/';

        if ( $source === $corrected_source ) {
            return $source;
        }

        if ( $wp_filesystem->exists( $corrected_source ) ) {
            $wp_filesystem->delete( $corrected_source, true );
        }

        $moved = $wp_filesystem->move( $source, $corrected_source );
        if ( $moved ) {
            return $corrected_source;
        }

        return $source;
    }

    /**
     * Карточка плагина в модальном окне «Детали».
     *
     * @param mixed  $res    Результат по умолчанию.
     * @param string $action Запрашиваемое действие API.
     * @param object $args   Аргументы запроса.
     * @return mixed
     */
    public static function plugin_info( mixed $res, string $action, object $args ): mixed {
        if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::PLUGIN_SLUG !== $args->slug ) {
            return $res;
        }

        $remote_version = self::get_remote_version() ?: self::get_local_version();

        $info = new \stdClass();
        $info->name           = 'Polylang Divi 5 AI Auto-Translator & Same Slug';
        $info->slug           = self::PLUGIN_SLUG;
        $info->version        = $remote_version;
        $info->author         = '<a href="https://github.com/' . self::REPO_OWNER . '">piratexnik</a>';
        $info->author_profile = 'https://github.com/' . self::REPO_OWNER;
        $info->homepage       = 'https://github.com/' . self::REPO_FULL;
        $info->requires       = '6.5';
        $info->requires_php   = '8.1';
        $info->download_link  = self::get_download_package( $remote_version );
        $info->sections       = [
            'description' => esc_html__( 'Автоматический переводчик для Divi 5 и Polylang с поддержкой одинаковых слагов (Same Slug), Google Translate и Gemini AI.', 'polyland-up' ),
            'changelog'   => sprintf(
                __( 'Посетите <a href="%s" target="_blank">GitHub Releases</a> для просмотра полной истории версий и изменений.', 'polyland-up' ),
                esc_url( 'https://github.com/' . self::REPO_FULL . '/releases' )
            ),
        ];

        return $info;
    }

    /**
     * Добавление кнопки принудительной проверки обновлений в строку плагина.
     *
     * @param array<string> $links Ссылки плагина.
     * @param string        $file  Базовый путь к файлу плагина.
     * @return array<string>
     */
    public static function add_row_meta( array $links, string $file ): array {
        if ( PLU_BASENAME === $file ) {
            $check_url = wp_nonce_url(
                admin_url( 'plugins.php?plu_action=force_check_update' ),
                'plu_force_check_update'
            );
            $links[] = sprintf(
                '<a href="%s" style="color:#00a32a;font-weight:600;">%s</a>',
                esc_url( $check_url ),
                esc_html__( '🔄 Проверить обновления', 'polyland-up' )
            );
        }

        return $links;
    }

    /**
     * Обработка действия ручной проверки обновлений по кнопке.
     */
    public static function handle_force_check(): void {
        if ( ! isset( $_GET['plu_action'] ) || 'force_check_update' !== $_GET['plu_action'] ) {
            return;
        }

        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die( esc_html__( 'У вас недостаточно прав для выполнения этого действия.', 'polyland-up' ) );
        }

        check_admin_referer( 'plu_force_check_update' );

        delete_transient( self::TRANSIENT_CHECK );
        delete_transient( self::TRANSIENT_ERROR );

        $remote_version = self::get_remote_version( true );
        $local_version  = self::get_local_version();

        $redirect_params = [
            'plu_remote_ver' => $remote_version ?? '',
            'plu_local_ver'  => $local_version,
        ];

        if ( null === $remote_version ) {
            $status = 'error';
            $error_info = get_transient( self::TRANSIENT_ERROR );
            if ( is_string( $error_info ) && '' !== $error_info ) {
                $redirect_params['plu_err_diag'] = rawurlencode( $error_info );
            }
        } elseif ( version_compare( $remote_version, $local_version, '>' ) ) {
            $status = 'available';
        } else {
            $status = 'latest';
        }

        $redirect_params['plu_update_status'] = $status;

        // Принудительно обновляем общесистемный transient update_plugins
        $update_plugins = get_site_transient( 'update_plugins' );
        if ( is_object( $update_plugins ) ) {
            $update_plugins = self::check_update( $update_plugins );
            set_site_transient( 'update_plugins', $update_plugins );
        }

        wp_safe_redirect( add_query_arg( $redirect_params, admin_url( 'plugins.php' ) ) );
        exit;
    }

    /**
     * Вывод всплывающих уведомлений в панели плагинов после проверки.
     */
    public static function render_admin_notices(): void {
        if ( empty( $_GET['plu_update_status'] ) ) {
            return;
        }

        $status     = sanitize_key( $_GET['plu_update_status'] );
        $remote_ver = isset( $_GET['plu_remote_ver'] ) ? sanitize_text_field( wp_unslash( $_GET['plu_remote_ver'] ) ) : '';
        $local_ver  = isset( $_GET['plu_local_ver'] ) ? sanitize_text_field( wp_unslash( $_GET['plu_local_ver'] ) ) : '';

        if ( 'latest' === $status ) {
            $version_display = $local_ver ?: self::get_local_version();
            $message = sprintf(
                esc_html__( 'Polylang Up: У вас установлена актуальная версия (v%s).', 'polyland-up' ),
                esc_html( $version_display )
            );
            printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', $message );
        } elseif ( 'available' === $status ) {
            $local_display = $local_ver ?: self::get_local_version();
            $message = sprintf(
                esc_html__( 'Polylang Up: Доступна новая версия v%s (текущая v%s). Обновите плагин ниже.', 'polyland-up' ),
                esc_html( $remote_ver ),
                esc_html( $local_display )
            );
            printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', $message );
        } elseif ( 'error' === $status ) {
            $diag_msg = '';
            if ( ! empty( $_GET['plu_err_diag'] ) ) {
                $diag_msg = sanitize_text_field( wp_unslash( rawurldecode( (string) $_GET['plu_err_diag'] ) ) );
            } else {
                $cached_err = get_transient( self::TRANSIENT_ERROR );
                if ( is_string( $cached_err ) && '' !== $cached_err ) {
                    $diag_msg = $cached_err;
                }
            }

            if ( '' !== $diag_msg ) {
                $message = sprintf(
                    esc_html__( 'Polylang Up: Не удалось проверить обновления на GitHub (%s).', 'polyland-up' ),
                    esc_html( $diag_msg )
                );
            } else {
                $message = esc_html__( 'Polylang Up: Не удалось проверить обновления на GitHub (проверьте соединение или токен).', 'polyland-up' );
            }
            printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', $message );
        }
    }
}
