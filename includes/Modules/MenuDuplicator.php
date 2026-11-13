<?php
declare(strict_types=1);

namespace PolylangUp\Modules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Модуль «🍔 AI Дубликатор и Переводчик Меню» для WordPress, Polylang и Divi 5.
 */
final class MenuDuplicator {

    /**
     * Инициализация хуков модуля.
     */
    public static function init(): void {
        if ( ! is_admin() ) {
            return;
        }

        add_action( 'wp_ajax_plu_duplicate_menu', [ self::class, 'ajax_duplicate_menu' ] );
        add_action( 'admin_notices', [ self::class, 'render_nav_menus_notice' ] );
    }

    /**
     * Компактный баннер-ссылка на экране «Внешний вид -> Меню» (nav-menus.php).
     */
    public static function render_nav_menus_notice(): void {
        global $pagenow;

        if ( 'nav-menus.php' !== $pagenow || ! current_user_can( 'edit_theme_options' ) ) {
            return;
        }

        $url = admin_url( 'admin.php?page=polylang-up&tab=menus' );
        ?>
        <div class="notice notice-info is-dismissible">
            <p style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                <span>
                    <strong><?php esc_html_e( '🍔 Polylang AI — Дубликатор Меню:', 'polyland-up' ); ?></strong>
                    <?php esc_html_e( 'Вы можете автоматически склонировать и перевести любое меню на другие языки с подменой ссылок и привязкой к областям темы.', 'polyland-up' ); ?>
                </span>
                <a href="<?php echo esc_url( $url ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Открыть AI Дубликатор Меню →', 'polyland-up' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Отрисовка интерфейса вкладки «🍔 Дубликатор Меню» на странице настроек плагина.
     */
    public static function render_tab_content(): void {
        if ( ! function_exists( 'pll_languages_list' ) ) {
            ?>
            <div class="notice notice-warning inline">
                <p>
                    <strong><?php esc_html_e( 'Polylang не активен.', 'polyland-up' ); ?></strong>
                    <?php esc_html_e( 'Для работы дубликатора меню активируйте плагин Polylang и настройте хотя бы два языка.', 'polyland-up' ); ?>
                </p>
            </div>
            <?php
            return;
        }

        $menus     = wp_get_nav_menus();
        $languages = (array) pll_languages_list( [ 'fields' => '' ] );
        $def_lang  = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';

        if ( empty( $menus ) ) {
            ?>
            <div class="notice notice-info inline">
                <p>
                    <?php esc_html_e( 'В WordPress пока не создано ни одного меню.', 'polyland-up' ); ?>
                    <a href="<?php echo esc_url( admin_url( 'nav-menus.php' ) ); ?>">
                        <?php esc_html_e( 'Создать меню →', 'polyland-up' ); ?>
                    </a>
                </p>
            </div>
            <?php
            return;
        }

        $default_translator = (string) plu_get_option( 'default_translator', 'gtx' );
        $default_mode       = 'gemini' === $default_translator ? 'gemini' : 'gtx';
        ?>
        <div class="plu-menu-duplicator-card" id="plu-menu-duplicator-box">
            <h2 style="margin-top:0;"><?php esc_html_e( '🍔 AI Дубликатор и Переводчик Меню', 'polyland-up' ); ?></h2>
            <p class="description" style="margin-bottom: 18px;">
                <?php esc_html_e( 'Создает языковые копии выбранного меню с сохранением вложенности (подменю), классов Divi, переключателя языков (#pll_switcher), умной подменой ID переведенных страниц и привязкой к областям темы в Polylang.', 'polyland-up' ); ?>
            </p>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="plu_menu_source_id"><?php esc_html_e( 'Исходное меню', 'polyland-up' ); ?></label>
                        </th>
                        <td>
                            <select id="plu_menu_source_id" class="regular-text">
                                <?php foreach ( $menus as $menu ) : ?>
                                    <?php
                                    $items       = wp_get_nav_menu_items( (int) $menu->term_id, [ 'post_status' => 'any' ] );
                                    $items_count = is_array( $items ) ? count( $items ) : (int) $menu->count;
                                    ?>
                                    <option value="<?php echo esc_attr( (string) $menu->term_id ); ?>">
                                        <?php
                                        printf(
                                            '%s (%d %s)',
                                            esc_html( (string) $menu->name ),
                                            $items_count,
                                            esc_html__( 'пунктов', 'polyland-up' )
                                        );
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="plu_menu_source_lang"><?php esc_html_e( 'Исходный язык меню', 'polyland-up' ); ?></label>
                        </th>
                        <td>
                            <select id="plu_menu_source_lang">
                                <?php foreach ( $languages as $lang ) : ?>
                                    <?php if ( ! is_object( $lang ) || empty( $lang->slug ) ) { continue; } ?>
                                    <option value="<?php echo esc_attr( (string) $lang->slug ); ?>" <?php selected( $def_lang, (string) $lang->slug ); ?>>
                                        <?php echo esc_html( (string) ( $lang->name ?? $lang->slug ) ); ?> (<?php echo esc_html( strtoupper( (string) $lang->slug ) ); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e( 'Целевые языки', 'polyland-up' ); ?></th>
                        <td>
                            <div class="plu-menu-langs-grid">
                                <?php foreach ( $languages as $lang ) : ?>
                                    <?php
                                    if ( ! is_object( $lang ) || empty( $lang->slug ) ) {
                                        continue;
                                    }
                                    $slug    = (string) $lang->slug;
                                    $name    = (string) ( $lang->name ?? $slug );
                                    $checked = ( '' !== $def_lang && $slug !== $def_lang );
                                    ?>
                                    <label class="plu-menu-lang-item" data-lang-slug="<?php echo esc_attr( $slug ); ?>">
                                        <input
                                            type="checkbox"
                                            class="plu-menu-target-lang-cb"
                                            value="<?php echo esc_attr( $slug ); ?>"
                                            data-lang-name="<?php echo esc_attr( $name ); ?>"
                                            <?php checked( $checked ); ?>
                                        />
                                        <span><strong><?php echo esc_html( strtoupper( $slug ) ); ?></strong> — <?php echo esc_html( $name ); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="plu_menu_translate_mode"><?php esc_html_e( 'Режим перевода названий пунктов', 'polyland-up' ); ?></label>
                        </th>
                        <td>
                            <select id="plu_menu_translate_mode" class="regular-text">
                                <option value="gemini" <?php selected( $default_mode, 'gemini' ); ?>>
                                    <?php esc_html_e( 'Перевести через Google Gemini AI', 'polyland-up' ); ?>
                                </option>
                                <option value="gtx" <?php selected( $default_mode, 'gtx' ); ?>>
                                    <?php esc_html_e( 'Перевести через Google Translate (Free GTX)', 'polyland-up' ); ?>
                                </option>
                                <option value="none">
                                    <?php esc_html_e( 'Без перевода текста (только подменить ссылки и привязать переведенные страницы)', 'polyland-up' ); ?>
                                </option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e( 'Дополнительные опции', 'polyland-up' ); ?></th>
                        <td>
                            <fieldset>
                                <label for="plu_menu_translate_custom_labels" style="display:block; margin-bottom:8px;">
                                    <input type="checkbox" id="plu_menu_translate_custom_labels" value="1" checked="checked" />
                                    <?php esc_html_e( 'Переводить короткие названия пунктов меню через AI/GTX (даже если у самой привязанной страницы в БД длинный заголовок)', 'polyland-up' ); ?>
                                </label>

                                <label for="plu_menu_assign_locations" style="display:block; margin-bottom:8px;">
                                    <input type="checkbox" id="plu_menu_assign_locations" value="1" checked="checked" />
                                    <?php esc_html_e( 'Автоматически привязать созданное меню к тем же областям темы (Primary Menu и др.) в Polylang для целевого языка', 'polyland-up' ); ?>
                                </label>

                                <label for="plu_menu_overwrite" style="display:block;">
                                    <input type="checkbox" id="plu_menu_overwrite" value="1" />
                                    <?php esc_html_e( 'Перезаписать пункты, если меню для этого языка уже существует', 'polyland-up' ); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div style="display:flex; align-items:center; gap:12px; margin-top:10px;">
                <button type="button" id="plu-run-menu-duplicator" class="button button-primary button-large">
                    <?php esc_html_e( '🚀 Склонировать и перевести меню', 'polyland-up' ); ?>
                </button>
                <span class="spinner" id="plu-menu-duplicator-spinner" style="float:none; margin:0;"></span>
            </div>

            <div id="plu-menu-duplicator-log" class="plu-duplicator-log" style="margin-top:16px; max-width:780px;"></div>
        </div>
        <?php
    }

    /**
     * AJAX-обработчик клонирования и перевода меню для выбранного целевого языка.
     */
    public static function ajax_duplicate_menu(): void {
        check_ajax_referer( 'plu_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_theme_options' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [
                'message' => __( 'У вас недостаточно прав для управления меню.', 'polyland-up' ),
            ] );
        }

        @set_time_limit( 300 );
        if ( function_exists( 'ignore_user_abort' ) ) {
            ignore_user_abort( true );
        }

        $source_menu_id          = isset( $_POST['source_menu_id'] ) ? (int) $_POST['source_menu_id'] : 0;
        $source_lang             = isset( $_POST['source_lang'] ) ? sanitize_key( (string) $_POST['source_lang'] ) : '';
        $target_lang             = isset( $_POST['target_lang'] ) ? sanitize_key( (string) $_POST['target_lang'] ) : '';
        $translate_mode          = isset( $_POST['translate_mode'] ) ? sanitize_key( (string) $_POST['translate_mode'] ) : 'gtx';
        $translate_custom_labels = ! empty( $_POST['translate_custom_labels'] );
        $assign_locations        = ! empty( $_POST['assign_locations'] );
        $overwrite_menu          = ! empty( $_POST['overwrite_menu'] );

        if ( $source_menu_id <= 0 || '' === $target_lang ) {
            wp_send_json_error( [
                'message' => __( 'Некорректные параметры: не указано исходное меню или целевой язык.', 'polyland-up' ),
            ] );
        }

        if ( ! in_array( $translate_mode, [ 'gemini', 'gtx', 'none' ], true ) ) {
            $translate_mode = 'gtx';
        }

        if ( '' === $source_lang && function_exists( 'pll_default_language' ) ) {
            $source_lang = (string) pll_default_language( 'slug' );
        }

        try {
            $result = self::duplicate_menu_to_language(
                $source_menu_id,
                $source_lang,
                $target_lang,
                $translate_mode,
                $translate_custom_labels,
                $assign_locations,
                $overwrite_menu
            );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [
                    'message' => $result->get_error_message(),
                ] );
            }

            wp_send_json_success( $result );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [
                'message' => sprintf(
                    'PHP Error: %s in %s:%d',
                    $e->getMessage(),
                    basename( $e->getFile() ),
                    $e->getLine()
                ),
            ] );
        }
    }

    /**
     * Полный цикл клонирования и перевода меню WordPress на целевой язык.
     *
     * @param int    $source_menu_id          ID исходного меню.
     * @param string $source_lang             Код исходного языка.
     * @param string $target_lang             Код целевого языка.
     * @param string $translate_mode          Режим перевода ('gemini', 'gtx', 'none').
     * @param bool   $translate_custom_labels Переводить короткие названия пунктов.
     * @param bool   $assign_locations        Автоматически привязывать к областям темы в Polylang.
     * @param bool   $overwrite_menu          Перезаписывать пункты существующего меню.
     * @return array<string, mixed>|\WP_Error
     */
    public static function duplicate_menu_to_language(
        int $source_menu_id,
        string $source_lang,
        string $target_lang,
        string $translate_mode = 'gtx',
        bool $translate_custom_labels = true,
        bool $assign_locations = true,
        bool $overwrite_menu = false
    ): array|\WP_Error {
        $source_menu = wp_get_nav_menu_object( $source_menu_id );
        if ( ! $source_menu ) {
            return new \WP_Error(
                'plu_invalid_source_menu',
                __( 'Исходное меню не найдено.', 'polyland-up' )
            );
        }

        $source_items = wp_get_nav_menu_items( $source_menu_id, [ 'post_status' => 'any' ] );
        if ( ! is_array( $source_items ) ) {
            $source_items = [];
        }

        $base_new_name = sprintf( '%s (%s)', (string) $source_menu->name, strtoupper( $target_lang ) );
        $existing_menu = wp_get_nav_menu_object( $base_new_name );
        $overwritten   = false;
        $new_menu_id   = 0;
        $new_menu_name = $base_new_name;

        if ( $existing_menu && ! is_wp_error( $existing_menu ) ) {
            if ( $overwrite_menu ) {
                $new_menu_id = (int) $existing_menu->term_id;
                $overwritten = true;

                // Удаляем старые пункты существующего меню
                $old_items = wp_get_nav_menu_items( $new_menu_id, [ 'post_status' => 'any' ] );
                if ( is_array( $old_items ) ) {
                    foreach ( $old_items as $old_item ) {
                        wp_delete_post( (int) $old_item->ID, true );
                    }
                }
            } else {
                // Подбираем уникальное имя меню, если перезапись отключена
                $suffix = 2;
                while ( wp_get_nav_menu_object( $base_new_name . ' #' . $suffix ) ) {
                    $suffix++;
                }
                $new_menu_name = $base_new_name . ' #' . $suffix;
            }
        }

        if ( 0 === $new_menu_id ) {
            $created = wp_create_nav_menu( $new_menu_name );
            if ( is_wp_error( $created ) ) {
                return $created;
            }
            $new_menu_id = (int) $created;
        }

        // Шаг А: Сбор словаря для пакетного перевода
        $dict               = [];
        $item_meta_cache    = [];
        $translated_obj_map = [];

        foreach ( $source_items as $item ) {
            $item_id   = (int) $item->ID;
            $raw_label = '' !== trim( (string) $item->post_title ) ? (string) $item->post_title : (string) $item->title;

            // Кэшируем наличие перевода объекта для пункта
            if ( 'post_type' === $item->type ) {
                $translated_obj_map[ $item_id ] = function_exists( 'pll_get_post' )
                    ? (int) pll_get_post( (int) $item->object_id, $target_lang )
                    : 0;
            } elseif ( 'taxonomy' === $item->type ) {
                $translated_obj_map[ $item_id ] = function_exists( 'pll_get_term' )
                    ? (int) pll_get_term( (int) $item->object_id, $target_lang )
                    : 0;
            } else {
                $translated_obj_map[ $item_id ] = 0;
            }

            if ( 'none' === $translate_mode || '#pll_switcher' === (string) $item->url ) {
                continue;
            }

            $has_target_translation = $translated_obj_map[ $item_id ] > 0;
            $should_translate_title = $translate_custom_labels || 'custom' === $item->type || ! $has_target_translation;

            if ( $should_translate_title && '' !== trim( $raw_label ) ) {
                $dict[ 'title_' . $item_id ] = $raw_label;
            }

            if ( '' !== trim( (string) $item->attr_title ) ) {
                $dict[ 'attr_' . $item_id ] = (string) $item->attr_title;
            }

            if ( '' !== trim( (string) $item->description ) ) {
                $dict[ 'desc_' . $item_id ] = (string) $item->description;
            }
        }

        $translated_dict = $dict;
        $provider_label  = __( 'Без перевода', 'polyland-up' );

        if ( 'none' !== $translate_mode && ! empty( $dict ) && class_exists( TranslatorAPI::class ) ) {
            $translated_res = TranslatorAPI::translate( $dict, $source_lang, $target_lang, $translate_mode );
            if ( is_wp_error( $translated_res ) ) {
                return $translated_res;
            }
            $translated_dict = $translated_res;
            $stats           = TranslatorAPI::get_last_stats();
            $provider_label  = (string) ( $stats['provider_label'] ?? strtoupper( $translate_mode ) );
        }

        // Шаг Б: Создание пунктов меню и сохранение карты соответствия ID
        $id_map        = [];
        $parent_links  = [];
        $created_count = 0;

        foreach ( $source_items as $item ) {
            $old_id    = (int) $item->ID;
            $raw_label = '' !== trim( (string) $item->post_title ) ? (string) $item->post_title : (string) $item->title;
            $t_title   = $translated_dict[ 'title_' . $old_id ] ?? $raw_label;
            $t_attr    = $translated_dict[ 'attr_' . $old_id ] ?? (string) $item->attr_title;
            $t_desc    = $translated_dict[ 'desc_' . $old_id ] ?? (string) $item->description;

            $classes = is_array( $item->classes ) ? implode( ' ', array_filter( array_map( 'sanitize_html_class', $item->classes ) ) ) : '';

            $menu_item_data = [
                'menu-item-title'       => $t_title,
                'menu-item-attr-title'  => $t_attr,
                'menu-item-description' => $t_desc,
                'menu-item-classes'     => $classes,
                'menu-item-target'      => (string) $item->target,
                'menu-item-xfn'         => (string) $item->xfn,
                'menu-item-position'    => (int) $item->menu_order,
                'menu-item-parent-id'   => 0,
                'menu-item-status'      => 'publish',
            ];

            if ( 'post_type' === $item->type ) {
                $tr_post_id = $translated_obj_map[ $old_id ] ?? 0;
                if ( $tr_post_id > 0 ) {
                    $orig_post_title  = (string) get_the_title( (int) $item->object_id );
                    $has_custom_label = ( '' !== trim( (string) $item->post_title ) && trim( (string) $item->post_title ) !== trim( $orig_post_title ) );

                    if ( ! $has_custom_label && ! $translate_custom_labels ) {
                        $menu_item_data['menu-item-title'] = (string) get_the_title( $tr_post_id );
                    }

                    $menu_item_data['menu-item-type']      = 'post_type';
                    $menu_item_data['menu-item-object']    = (string) $item->object;
                    $menu_item_data['menu-item-object-id'] = $tr_post_id;
                } else {
                    // Перевод страницы еще не создан — делаем custom-ссылку с перелинкованным URL
                    $rewritten_url = class_exists( LinkRewriter::class )
                        ? LinkRewriter::rewrite_url( (string) $item->url, $target_lang )
                        : (string) $item->url;
                    $menu_item_data['menu-item-type'] = 'custom';
                    $menu_item_data['menu-item-url']  = $rewritten_url;
                }
            } elseif ( 'taxonomy' === $item->type ) {
                $tr_term_id = $translated_obj_map[ $old_id ] ?? 0;
                if ( $tr_term_id > 0 ) {
                    $menu_item_data['menu-item-type']      = 'taxonomy';
                    $menu_item_data['menu-item-object']    = (string) $item->object;
                    $menu_item_data['menu-item-object-id'] = $tr_term_id;

                    if ( ! $translate_custom_labels && '' === trim( (string) $item->post_title ) ) {
                        $term_obj = get_term( $tr_term_id, (string) $item->object );
                        if ( $term_obj instanceof \WP_Term ) {
                            $menu_item_data['menu-item-title'] = $term_obj->name;
                        }
                    }
                } else {
                    $rewritten_url = class_exists( LinkRewriter::class )
                        ? LinkRewriter::rewrite_url( (string) $item->url, $target_lang )
                        : (string) $item->url;
                    $menu_item_data['menu-item-type'] = 'custom';
                    $menu_item_data['menu-item-url']  = $rewritten_url;
                }
            } else {
                // Произвольная ссылка или переключатель языков Polylang (#pll_switcher)
                $menu_item_data['menu-item-type'] = 'custom';
                if ( '#pll_switcher' === (string) $item->url ) {
                    $menu_item_data['menu-item-url']   = '#pll_switcher';
                    $menu_item_data['menu-item-title'] = $raw_label;
                } else {
                    $menu_item_data['menu-item-url'] = class_exists( LinkRewriter::class )
                        ? LinkRewriter::rewrite_url( (string) $item->url, $target_lang )
                        : (string) $item->url;
                }
            }

            $new_item_id = wp_update_nav_menu_item( $new_menu_id, 0, $menu_item_data );
            if ( is_wp_error( $new_item_id ) || $new_item_id <= 0 ) {
                continue;
            }

            $new_item_id             = (int) $new_item_id;
            $id_map[ $old_id ]       = $new_item_id;
            $old_parent_id           = (int) $item->menu_item_parent;
            if ( $old_parent_id > 0 ) {
                $parent_links[ $new_item_id ] = $old_parent_id;
            }

            // Копируем кастомные мета-поля пункта меню (включая _pll_menu_item и настройки Divi)
            $all_meta = get_post_meta( $old_id );
            if ( is_array( $all_meta ) ) {
                foreach ( $all_meta as $meta_key => $meta_values ) {
                    if (
                        str_starts_with( (string) $meta_key, '_menu_item_' ) ||
                        in_array( (string) $meta_key, [ '_edit_lock', '_edit_last' ], true )
                    ) {
                        continue;
                    }
                    delete_post_meta( $new_item_id, (string) $meta_key );
                    foreach ( (array) $meta_values as $meta_val ) {
                        add_post_meta( $new_item_id, (string) $meta_key, maybe_unserialize( $meta_val ) );
                    }
                }
            }

            $created_count++;
        }

        // Второй проход: восстановление иерархии подменю (menu-item-parent-id)
        foreach ( $parent_links as $child_new_id => $old_parent_id ) {
            if ( isset( $id_map[ $old_parent_id ] ) ) {
                update_post_meta( $child_new_id, '_menu_item_menu_item_parent', (string) $id_map[ $old_parent_id ] );
            }
        }

        // Шаг В: Авто-привязка к областям темы в Polylang
        $assigned_locations = [];
        if ( $assign_locations ) {
            $assigned_locations = self::assign_menu_to_polylang_locations(
                $source_menu_id,
                $new_menu_id,
                $source_lang,
                $target_lang
            );
        }

        return [
            'menu_id'            => $new_menu_id,
            'menu_name'          => $new_menu_name,
            'lang_upper'         => strtoupper( $target_lang ),
            'edit_url'           => admin_url( 'nav-menus.php?action=edit&menu=' . $new_menu_id ),
            'items_count'        => $created_count,
            'translated_count'   => count( $dict ),
            'assigned_locations' => $assigned_locations,
            'overwritten'        => $overwritten,
            'provider_label'     => $provider_label,
        ];
    }

    /**
     * Привязка нового меню к тем же областям темы (nav_menus) в опциях Polylang.
     *
     * @param int    $source_menu_id ID исходного меню.
     * @param int    $new_menu_id    ID созданного меню.
     * @param string $source_lang    Код исходного языка.
     * @param string $target_lang    Код целевого языка.
     * @return array<string> Список привязанных локаций.
     */
    public static function assign_menu_to_polylang_locations(
        int $source_menu_id,
        int $new_menu_id,
        string $source_lang,
        string $target_lang
    ): array {
        $theme_slug  = (string) get_option( 'stylesheet' );
        $pll_options = get_option( 'polylang', [] );
        if ( ! is_array( $pll_options ) ) {
            $pll_options = [];
        }

        $matched_locations = [];

        // 1. Поиск в настройках nav_menus самого Polylang
        if (
            isset( $pll_options['nav_menus'][ $theme_slug ] ) &&
            is_array( $pll_options['nav_menus'][ $theme_slug ] )
        ) {
            foreach ( $pll_options['nav_menus'][ $theme_slug ] as $location => $lang_map ) {
                if ( ! is_array( $lang_map ) ) {
                    continue;
                }
                if ( '' !== $source_lang && isset( $lang_map[ $source_lang ] ) && (int) $lang_map[ $source_lang ] === $source_menu_id ) {
                    $matched_locations[ (string) $location ] = true;
                } elseif ( in_array( $source_menu_id, array_map( 'intval', $lang_map ), true ) ) {
                    $matched_locations[ (string) $location ] = true;
                }
            }
        }

        // 2. Поиск в стандартных локациях WordPress (get_nav_menu_locations)
        $wp_locations = get_nav_menu_locations();
        if ( is_array( $wp_locations ) ) {
            foreach ( $wp_locations as $location => $assigned_id ) {
                if ( (int) $assigned_id === $source_menu_id ) {
                    $matched_locations[ (string) $location ] = true;
                }
            }
        }

        if ( empty( $matched_locations ) ) {
            return [];
        }

        if ( ! isset( $pll_options['nav_menus'] ) || ! is_array( $pll_options['nav_menus'] ) ) {
            $pll_options['nav_menus'] = [];
        }
        if ( ! isset( $pll_options['nav_menus'][ $theme_slug ] ) || ! is_array( $pll_options['nav_menus'][ $theme_slug ] ) ) {
            $pll_options['nav_menus'][ $theme_slug ] = [];
        }

        $result_locations = [];
        foreach ( array_keys( $matched_locations ) as $location ) {
            if ( ! isset( $pll_options['nav_menus'][ $theme_slug ][ $location ] ) || ! is_array( $pll_options['nav_menus'][ $theme_slug ][ $location ] ) ) {
                $pll_options['nav_menus'][ $theme_slug ][ $location ] = [];
            }
            $pll_options['nav_menus'][ $theme_slug ][ $location ][ $target_lang ] = $new_menu_id;
            $result_locations[] = $location;
        }

        update_option( 'polylang', $pll_options );

        return $result_locations;
    }
}
