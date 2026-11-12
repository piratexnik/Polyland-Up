<?php
declare(strict_types=1);

namespace PolylangUp\Modules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Парсер и сборщик контента для Divi 5 (Gutenberg JSON-блоки), Divi 4 (шорткоды),
 * классического HTML (<img alt/title>) и SEO-метаданных Rank Math.
 */
final class DiviParser {

    /**
     * Типы блоков, которые категорически запрещено переводить.
     *
     * @var string[]
     */
    private const SKIP_BLOCKS = [
        'divi/code',
        'divi/fullwidth-code',
        'core/code',
    ];

    /**
     * Ветки атрибутов Divi 5, содержащие только стили, позиционирование и служебные настройки.
     * Их поддеревья пропускаются целиком.
     *
     * @var string[]
     */
    private const EXCLUDED_ATTR_BRANCHES = [
        'decoration',
        'advanced',
        'builderVersion',
        'css',
        'meta',
        'font',
        'background',
        'border',
        'boxShadow',
        'textShadow',
        'spacing',
        'sizing',
        'transform',
        'filters',
        'animation',
        'sticky',
        'scroll',
        'position',
        'zIndex',
        'overflow',
        'link',
        'idClasses',
        'attributes',
        'conditions',
        'transition',
        'icon',
        'hoverIcon',
        'buttonIcon',
        'moduleClass',
        'cssClass',
        'cssId',
        'adminLabel',
        'iconFont',
        'disabledOn',
        'layout',
        'grid',
        'flexType',
        'src',
        'url',
        'linkUrl',
        'linkTarget',
        'mp4',
        'webm',
        'ogg',
        'audio',
        'video',
        'imageId',
        'attachmentId',
        'id',
        'width',
        'height',
        'maxWidth',
        'minHeight',
        'gutterWidth',
        'socialNetwork',
        'modulePreset',
        'preset',
        'postType',
        'taxonomy',
        'includeCategories',
        'orderBy',
        'layoutStyle',
    ];

    /**
     * Родительские ключи перед innerContent, чьи прямые строковые значения являются техническими.
     *
     * @var string[]
     */
    private const TECHNICAL_INNER_PARENTS = [
        'code',
        'customCss',
        'adminLabel',
        'video',
        'audio',
        'link',
        'icon',
        'menuId',
        'sidebarId',
        'divider',
        'socialNetwork',
        'network',
        'postType',
        'orderBy',
        'order',
    ];

    /**
     * Брейкпоинты Divi 5 внутри innerContent.
     *
     * @var string[]
     */
    private const BREAKPOINTS = [
        'desktop',
        'tablet',
        'phone',
        'mobile',
    ];

    /**
     * Состояния значений Divi 5 внутри innerContent -> {breakpoint}.
     *
     * @var string[]
     */
    private const INNER_STATES = [
        'value',
        'hover',
        'sticky',
    ];

    /**
     * Белый список переводимых ключей внутри JSON-атрибутов блоков Divi 5 / Gutenberg.
     *
     * @var string[]
     */
    private const TRANSLATABLE_ATTR_KEYS = [
        'text',
        'title',
        'heading',
        'subhead',
        'subtitle',
        'content',
        'body',
        'buttonText',
        'button_text',
        'buttonOneText',
        'buttonTwoText',
        'label',
        'alt',
        'titleText',
        'title_text',
        'caption',
        'description',
        'name',
        'author',
        'jobTitle',
        'job_title',
        'company',
        'companyName',
        'company_name',
        'prefix',
        'suffix',
        'placeholder',
        'fullwidthPlaceholder',
        'quote',
        'cite',
        'question',
        'answer',
        'badge',
        'ribbon',
        'per',
        'price_note',
        'priceNote',
        'fieldTitle',
        'field_title',
        'submitButton',
        'successMessage',
        'success_message',
        'checkboxText',
        'checkbox_text',
        'firstNameLabel',
        'lastNameLabel',
        'emailLabel',
        'footerContent',
        'artistName',
        'albumName',
        'logoAlt',
        'imageAlt',
        'emptyMessage',
        'readMoreText',
        'moreText',
        'prevText',
        'nextText',
        'closeText',
    ];

    /**
     * Белый список переводимых атрибутов в шорткодах Divi 4 ([et_pb_...]).
     *
     * @var string[]
     */
    private const TRANSLATABLE_SHORTCODE_ATTRS = [
        'title',
        'button_text',
        'subhead',
        'alt',
        'title_text',
        'heading',
        'author',
        'job_title',
        'company_name',
        'prefix',
        'suffix',
        'label',
        'caption',
        'subtitle',
        'name',
        'description',
        'button_one_text',
        'button_two_text',
        'image_alt',
        'logo_alt',
        'empty_results_text',
        'placeholder',
        'more_text',
        'prev_text',
        'next_text',
        'success_message',
        'submit_button_text',
        'header',
        'footer_content',
        'badge_text',
        'ribbon_text',
        'per',
        'price_note',
    ];

    /**
     * Ключи мета-полей Rank Math SEO для перевода.
     *
     * @var string[]
     */
    private const RANK_MATH_META_KEYS = [
        'rank_math_title',
        'rank_math_description',
        'rank_math_focus_keyword',
    ];

    /**
     * Технические значения настроек, которые нельзя переводить.
     *
     * @var string[]
     */
    private const TECHNICAL_KEYWORDS = [
        'on',
        'off',
        'true',
        'false',
        'yes',
        'no',
        'none',
        'default',
        'auto',
        'inherit',
        'initial',
        'unset',
        'normal',
        'bold',
        'italic',
        'underline',
        'uppercase',
        'lowercase',
        'capitalize',
        'left',
        'center',
        'right',
        'justify',
        'top',
        'bottom',
        'middle',
        'flex',
        'inline-flex',
        'grid',
        'block',
        'inline',
        'inline-block',
        'hidden',
        'visible',
        'scroll',
        'fixed',
        'relative',
        'absolute',
        'sticky',
        'static',
        'cover',
        'contain',
        'repeat',
        'no-repeat',
        'repeat-x',
        'repeat-y',
        'ease',
        'ease-in',
        'ease-out',
        'ease-in-out',
        'linear',
        'fade',
        'slide',
        'zoom',
        'bounce',
        'flip',
        'fold',
        'roll',
        'horizontal',
        'vertical',
        'desktop',
        'tablet',
        'phone',
        'mobile',
        'hover',
        'value',
        'dark',
        'light',
        'primary',
        'secondary',
        'solid',
        'dashed',
        'dotted',
        'double',
        'groove',
        'ridge',
        'inset',
        'outset',
        'row',
        'column',
        'wrap',
        'nowrap',
        'start',
        'end',
        'stretch',
        'baseline',
        'space-between',
        'space-around',
        'space-evenly',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'p',
        'div',
        'span',
        'section',
        'header',
        'footer',
        'article',
        'aside',
        'main',
        'nav',
        'same',
        '_self',
        '_blank',
        '_parent',
        '_top',
        'asc',
        'desc',
        'date',
        'id',
        'slug',
        'rand',
        'menu_order',
        'post',
        'page',
    ];

    /**
     * Полный цикл: извлечение словаря поста, пакетный перевод через TranslatorAPI и обратная сборка.
     *
     * @param \WP_Post $source_post Исходная запись WordPress.
     * @param string   $source_lang Код исходного языка Polylang.
     * @param string   $target_lang Код целевого языка Polylang.
     * @param string   $engine      Движок перевода ('gtx' или 'gemini').
     * @return array<string, mixed>|\WP_Error Массив переведенных полей, Rank Math меты и статистики или WP_Error.
     */
    public static function translate_post_data(
        \WP_Post $source_post,
        string $source_lang,
        string $target_lang,
        string $engine = 'gtx'
    ): array|\WP_Error {
        $context    = self::extract_post_dictionary( $source_post );
        $dictionary = $context['dictionary'];

        if ( empty( $dictionary ) ) {
            return [
                'post_title'       => (string) $source_post->post_title,
                'post_excerpt'     => (string) $source_post->post_excerpt,
                'post_content'     => (string) $source_post->post_content,
                'rank_math_meta'   => [],
                'translated_count' => 0,
                'stats'            => [
                    'engine'           => $engine,
                    'provider_label'   => 'gemini' === $engine ? 'Google Gemini AI' : 'Google GTX',
                    'translated_count' => 0,
                    'total_count'      => 0,
                    'time_ms'          => 0,
                    'time_sec'         => 0.0,
                ],
            ];
        }

        $translated_dict = TranslatorAPI::translate( $dictionary, $source_lang, $target_lang, $engine );
        if ( is_wp_error( $translated_dict ) ) {
            return $translated_dict;
        }

        $assembled = self::apply_translated_dictionary( $context, $translated_dict );
        $stats     = TranslatorAPI::get_last_stats();

        $assembled['translated_count'] = count( $dictionary );
        $assembled['stats']            = $stats;

        return $assembled;
    }

    /**
     * Извлекает плоский словарь переводимых строк и карту обратной сборки из записи WordPress.
     *
     * @param \WP_Post $source_post Исходная запись.
     * @return array<string, mixed> Контекст парсинга с ключами dictionary, рецептами блоков и метаданными.
     */
    public static function extract_post_dictionary( \WP_Post $source_post ): array {
        $state = [
            'dictionary' => [],
            'counter'    => 0,
        ];

        $post_title_key   = null;
        $post_excerpt_key = null;
        $rank_math_keys   = [];

        // 1. Заголовок и краткое описание
        if ( self::is_translatable_string( (string) $source_post->post_title, false ) ) {
            $post_title_key = self::register_text( $state, (string) $source_post->post_title );
        }

        if ( self::is_translatable_string( (string) $source_post->post_excerpt, true ) ) {
            $post_excerpt_key = self::register_text( $state, (string) $source_post->post_excerpt );
        }

        // 2. Метаполя Rank Math SEO
        foreach ( self::RANK_MATH_META_KEYS as $meta_key ) {
            $raw_meta = get_post_meta( (int) $source_post->ID, $meta_key, true );
            if ( is_string( $raw_meta ) && self::is_translatable_string( $raw_meta, false ) ) {
                $rank_math_keys[ $meta_key ] = self::register_text( $state, $raw_meta );
            }
        }

        // 3. Контент (Divi 5 / Gutenberg блоки либо Divi 4 шорткоды / HTML)
        $raw_content = (string) $source_post->post_content;
        $has_blocks  = '' !== trim( $raw_content )
            && function_exists( 'parse_blocks' )
            && function_exists( 'serialize_blocks' )
            && ( ( function_exists( 'has_blocks' ) && has_blocks( $raw_content ) ) || 1 === preg_match( '/<!--\s+wp:/', $raw_content ) );

        $blocks               = [];
        $block_recipes        = [];
        $content_fallback     = null;

        if ( $has_blocks ) {
            $blocks        = parse_blocks( $raw_content );
            $block_recipes = self::extract_blocks_recursive( $blocks, $state );
        } elseif ( '' !== trim( $raw_content ) ) {
            $content_fallback = self::extract_rich_string( $raw_content, $state, true );
        }

        return [
            'dictionary'       => $state['dictionary'],
            'source_post'      => $source_post,
            'post_title_key'   => $post_title_key,
            'post_excerpt_key' => $post_excerpt_key,
            'rank_math_keys'   => $rank_math_keys,
            'has_blocks'       => $has_blocks,
            'blocks'           => $blocks,
            'block_recipes'    => $block_recipes,
            'content_fallback' => $content_fallback,
        ];
    }

    /**
     * Подставляет переведенный словарь обратно в структуру записи, блоков Divi 5 и Rank Math.
     *
     * @param array<string, mixed>  $context         Контекст, возвращенный extract_post_dictionary().
     * @param array<string, string> $translated_dict Переведенный словарь вида ['k_0' => '...'].
     * @return array<string, mixed> Пересобранные post_title, post_excerpt, post_content и rank_math_meta.
     */
    public static function apply_translated_dictionary( array $context, array $translated_dict ): array {
        /** @var \WP_Post $source_post */
        $source_post = $context['source_post'];

        $post_title = ( null !== $context['post_title_key'] )
            ? (string) ( $translated_dict[ $context['post_title_key'] ] ?? $source_post->post_title )
            : (string) $source_post->post_title;

        $post_excerpt = ( null !== $context['post_excerpt_key'] )
            ? (string) ( $translated_dict[ $context['post_excerpt_key'] ] ?? $source_post->post_excerpt )
            : (string) $source_post->post_excerpt;

        $rank_math_meta = [];
        if ( ! empty( $context['rank_math_keys'] ) && is_array( $context['rank_math_keys'] ) ) {
            foreach ( $context['rank_math_keys'] as $meta_key => $dict_key ) {
                if ( isset( $translated_dict[ $dict_key ] ) && '' !== trim( (string) $translated_dict[ $dict_key ] ) ) {
                    $rank_math_meta[ $meta_key ] = self::sanitize_utf8( (string) $translated_dict[ $dict_key ] );
                }
            }
        }

        $post_content = (string) $source_post->post_content;

        if ( ! empty( $context['has_blocks'] ) && function_exists( 'serialize_blocks' ) ) {
            $blocks = is_array( $context['blocks'] ) ? $context['blocks'] : [];
            if ( ! empty( $context['block_recipes'] ) && is_array( $context['block_recipes'] ) ) {
                self::apply_blocks_recursive( $blocks, $context['block_recipes'], $translated_dict );
            }
            $blocks       = self::sanitize_blocks_utf8( $blocks );
            $post_content = serialize_blocks( $blocks );
        } elseif ( is_array( $context['content_fallback'] ?? null ) ) {
            $post_content = self::apply_rich_string( $context['content_fallback'], $translated_dict );
        }

        return [
            'post_title'     => self::sanitize_utf8( $post_title ),
            'post_excerpt'   => self::sanitize_utf8( $post_excerpt ),
            'post_content'   => self::sanitize_utf8( $post_content ),
            'rank_math_meta' => $rank_math_meta,
        ];
    }

    /**
     * Рекурсивный обход массива блоков Gutenberg / Divi 5 для извлечения переводимых текстов.
     *
     * @param array<int, array<string, mixed>> $blocks Массив блоков от parse_blocks().
     * @param array<string, mixed>             $state  Состояние словаря.
     * @return array<int, array<string, mixed>> Карта рецептов восстановления по индексам блоков.
     */
    private static function extract_blocks_recursive( array $blocks, array &$state ): array {
        $recipes = [];

        foreach ( $blocks as $index => $block ) {
            if ( ! is_array( $block ) ) {
                continue;
            }

            $block_name = (string) ( $block['blockName'] ?? '' );

            // Полностью игнорируем код-блоки Divi / WordPress
            if ( '' !== $block_name && in_array( $block_name, self::SKIP_BLOCKS, true ) ) {
                continue;
            }

            $block_recipe = [];

            // 1. Атрибуты блока (основное хранилище данных в Divi 5 JSON)
            if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
                $attr_recipes = [];
                self::walk_divi_attrs( $block['attrs'], [], $attr_recipes, $state );
                if ( ! empty( $attr_recipes ) ) {
                    $block_recipe['attrs'] = $attr_recipes;
                }
            }

            // 2. Внутренний контент блока (innerContent / innerHTML)
            $has_string_inner_content = false;
            if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
                $ic_recipes = [];
                foreach ( $block['innerContent'] as $ic_idx => $chunk ) {
                    if ( ! is_string( $chunk ) ) {
                        continue;
                    }
                    $has_string_inner_content = true;
                    $rich                     = self::extract_rich_string( $chunk, $state, true );
                    if ( null !== $rich ) {
                        $ic_recipes[ $ic_idx ] = $rich;
                    }
                }
                if ( ! empty( $ic_recipes ) ) {
                    $block_recipe['innerContent'] = $ic_recipes;
                }
            }

            // Если innerContent не содержит строк, проверяем innerHTML как запасной источник
            if ( ! $has_string_inner_content && ! empty( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
                $rich = self::extract_rich_string( $block['innerHTML'], $state, true );
                if ( null !== $rich ) {
                    $block_recipe['innerHTML'] = $rich;
                }
            }

            // 3. Вложенные блоки (секция -> строка -> колонка -> модуль)
            if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                $inner_recipes = self::extract_blocks_recursive( $block['innerBlocks'], $state );
                if ( ! empty( $inner_recipes ) ) {
                    $block_recipe['innerBlocks'] = $inner_recipes;
                }
            }

            if ( ! empty( $block_recipe ) ) {
                $recipes[ $index ] = $block_recipe;
            }
        }

        return $recipes;
    }

    /**
     * Рекурсивный обход JSON-атрибутов блока Divi 5 / Gutenberg.
     *
     * @param array<string|int, mixed> $node         Текущий узел массива attrs.
     * @param array<int, string|int>   $path         Путь ключей от корня attrs.
     * @param array<int, array<string, mixed>> $attr_recipes Собираемый список рецептов замены.
     * @param array<string, mixed>     $state        Состояние словаря.
     */
    private static function walk_divi_attrs(
        array $node,
        array $path,
        array &$attr_recipes,
        array &$state
    ): void {
        foreach ( $node as $key => $value ) {
            $str_key = (string) $key;

            // Пропускаем исключенные технические/стилистические ветки целиком
            if ( in_array( $str_key, self::EXCLUDED_ATTR_BRANCHES, true ) ) {
                continue;
            }

            $current_path = array_merge( $path, [ $key ] );

            if ( is_array( $value ) ) {
                self::walk_divi_attrs( $value, $current_path, $attr_recipes, $state );
                continue;
            }

            if ( ! is_string( $value ) ) {
                continue;
            }

            if ( ! self::should_inspect_attr_leaf( $current_path ) ) {
                continue;
            }

            $rich = self::extract_rich_string( $value, $state, true );
            if ( null !== $rich ) {
                $attr_recipes[] = [
                    'path'   => $current_path,
                    'recipe' => $rich,
                ];
            }
        }
    }

    /**
     * Определяет, может ли конечное строковое значение в attrs содержать переводимый текст.
     *
     * @param array<int, string|int> $path Полный путь от корня attrs.
     * @return bool
     */
    private static function should_inspect_attr_leaf( array $path ): bool {
        $depth = count( $path );
        if ( 0 === $depth ) {
            return false;
        }

        $leaf_key = (string) $path[ $depth - 1 ];

        // 1. Явно разрешенное имя переводимого ключа (text, title, alt, caption, buttonText и т.д.)
        if ( in_array( $leaf_key, self::TRANSLATABLE_ATTR_KEYS, true ) ) {
            return true;
        }

        // 2. Классический путь Divi 5: {moduleField} -> innerContent -> {breakpoint} -> {state}
        if ( $depth >= 3 ) {
            $maybe_state = (string) $path[ $depth - 1 ];
            $maybe_bp    = (string) $path[ $depth - 2 ];
            $maybe_ic    = (string) $path[ $depth - 3 ];

            if (
                'innerContent' === $maybe_ic
                && in_array( $maybe_bp, self::BREAKPOINTS, true )
                && in_array( $maybe_state, self::INNER_STATES, true )
            ) {
                $parent_before_ic = $depth >= 4 ? (string) $path[ $depth - 4 ] : '';
                if ( ! in_array( $parent_before_ic, self::TECHNICAL_INNER_PARENTS, true ) ) {
                    return true;
                }
            }
        }

        // 3. Элементы списков/опций внутри innerContent (например, опции формы: ... -> innerContent -> desktop -> value -> [0] -> value)
        if ( 'value' === $leaf_key && $depth >= 5 && in_array( 'innerContent', $path, true ) ) {
            return true;
        }

        return false;
    }

    /**
     * Рекурсивное применение переведенного словаря к массиву блоков.
     *
     * @param array<int, array<string, mixed>> $blocks          Блоки (по ссылке).
     * @param array<int, array<string, mixed>> $block_recipes   Рецепты восстановления.
     * @param array<string, string>            $translated_dict Переведенный словарь.
     */
    private static function apply_blocks_recursive(
        array &$blocks,
        array $block_recipes,
        array $translated_dict
    ): void {
        foreach ( $block_recipes as $index => $recipe ) {
            if ( ! isset( $blocks[ $index ] ) || ! is_array( $blocks[ $index ] ) ) {
                continue;
            }

            // 1. Обновление атрибутов блока
            if ( ! empty( $recipe['attrs'] ) && is_array( $recipe['attrs'] ) ) {
                foreach ( $recipe['attrs'] as $attr_entry ) {
                    $new_val = self::apply_rich_string( $attr_entry['recipe'], $translated_dict );
                    self::set_value_by_path( $blocks[ $index ]['attrs'], $attr_entry['path'], $new_val );
                }
            }

            // 2. Обновление innerContent и синхронизация innerHTML
            if ( ! empty( $recipe['innerContent'] ) && is_array( $recipe['innerContent'] ) ) {
                foreach ( $recipe['innerContent'] as $ic_idx => $ic_recipe ) {
                    if ( isset( $blocks[ $index ]['innerContent'][ $ic_idx ] ) ) {
                        $blocks[ $index ]['innerContent'][ $ic_idx ] = self::apply_rich_string( $ic_recipe, $translated_dict );
                    }
                }

                // Пересобираем innerHTML из строковых частей innerContent для полной согласованности
                $string_parts = array_filter(
                    (array) $blocks[ $index ]['innerContent'],
                    static fn( mixed $item ): bool => is_string( $item )
                );
                $blocks[ $index ]['innerHTML'] = implode( '', $string_parts );
            } elseif ( ! empty( $recipe['innerHTML'] ) && is_array( $recipe['innerHTML'] ) ) {
                $blocks[ $index ]['innerHTML'] = self::apply_rich_string( $recipe['innerHTML'], $translated_dict );
            }

            // 3. Рекурсия во вложенные блоки
            if ( ! empty( $recipe['innerBlocks'] ) && is_array( $recipe['innerBlocks'] ) && isset( $blocks[ $index ]['innerBlocks'] ) ) {
                self::apply_blocks_recursive( $blocks[ $index ]['innerBlocks'], $recipe['innerBlocks'], $translated_dict );
            }
        }
    }

    /**
     * Записывает значение по вложенному пути ключей в массив.
     *
     * @param array<string|int, mixed> $target Целевой массив (по ссылке).
     * @param array<int, string|int>   $path   Путь ключей.
     * @param string                   $value  Новое значение.
     */
    private static function set_value_by_path( array &$target, array $path, string $value ): void {
        $ref   = &$target;
        $count = count( $path );

        for ( $i = 0; $i < $count - 1; $i++ ) {
            $key = $path[ $i ];
            if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
                return;
            }
            $ref = &$ref[ $key ];
        }

        $last_key         = $path[ $count - 1 ];
        $ref[ $last_key ] = $value;
    }

    /**
     * Анализирует строку (поддерживает шорткоды Divi 4, HTML с <img alt/title> и <script>/<style>).
     * Возвращает null, если в строке нет переводимых элементов.
     *
     * @param string $raw              Исходная строка.
     * @param array<string, mixed> $state Состояние словаря.
     * @param bool   $parse_shortcodes Разбирать ли шорткоды Divi 4 [et_pb_...].
     * @return array<string, mixed>|null
     */
    private static function extract_rich_string( string $raw, array &$state, bool $parse_shortcodes = true ): ?array {
        if ( '' === trim( $raw ) ) {
            return null;
        }

        $before_count = $state['counter'];
        $segments     = [];

        if ( $parse_shortcodes && str_contains( $raw, '[et_pb_' ) ) {
            $segments = self::extract_shortcode_segments( $raw, $state );
        } else {
            $segments = self::extract_html_segments( $raw, $state );
        }

        // Если ни один ключ словаря не был зарегистрирован — строка не требует перевода
        if ( $state['counter'] === $before_count ) {
            return null;
        }

        return [
            'type'     => 'rich_segments',
            'segments' => $segments,
        ];
    }

    /**
     * Собирает строку обратно из сегментов на основе переведенного словаря.
     *
     * @param array<string, mixed>  $recipe          Рецепт строки.
     * @param array<string, string> $translated_dict Переведенный словарь.
     * @return string
     */
    private static function apply_rich_string( array $recipe, array $translated_dict ): string {
        $segments = $recipe['segments'] ?? [];
        if ( ! is_array( $segments ) ) {
            return '';
        }

        $out = '';

        foreach ( $segments as $seg ) {
            $kind = (string) ( $seg['kind'] ?? 'raw' );

            if ( 'raw' === $kind ) {
                $out .= (string) ( $seg['text'] ?? '' );
                continue;
            }

            if ( 'shortcode_tag' === $kind ) {
                $out .= self::rebuild_shortcode_tag( $seg, $translated_dict );
                continue;
            }

            if ( 'html_chunk' === $kind ) {
                $out .= self::apply_html_chunk( $seg, $translated_dict );
            }
        }

        return self::sanitize_utf8( $out );
    }

    /**
     * Посимвольный токенизатор шорткодов Divi 4 ([et_pb_...] и [/et_pb_...])
     * без риска переполнения PCRE backtrack limit.
     *
     * @param string               $input Входная строка с шорткодами.
     * @param array<string, mixed> $state Состояние словаря.
     * @return array<int, array<string, mixed>>
     */
    private static function extract_shortcode_segments( string $input, array &$state ): array {
        $segments   = [];
        $len        = strlen( $input );
        $pos        = 0;
        $code_depth = 0;

        while ( $pos < $len ) {
            $open_pos = strpos( $input, '[et_pb_', $pos );
            $close_pos = strpos( $input, '[/et_pb_', $pos );

            // Находим ближайшее начало тега [et_pb_ или [/et_pb_
            if ( false === $open_pos && false === $close_pos ) {
                $tail = substr( $input, $pos );
                if ( '' !== $tail ) {
                    if ( $code_depth > 0 ) {
                        $segments[] = [ 'kind' => 'raw', 'text' => $tail ];
                    } else {
                        $segments = array_merge( $segments, self::extract_html_segments( $tail, $state ) );
                    }
                }
                break;
            }

            if ( false === $open_pos ) {
                $tag_start = $close_pos;
            } elseif ( false === $close_pos ) {
                $tag_start = $open_pos;
            } else {
                $tag_start = min( $open_pos, $close_pos );
            }

            // Текст до начала шорткода
            if ( $tag_start > $pos ) {
                $between = substr( $input, $pos, $tag_start - $pos );
                if ( $code_depth > 0 ) {
                    $segments[] = [ 'kind' => 'raw', 'text' => $between ];
                } else {
                    $segments = array_merge( $segments, self::extract_html_segments( $between, $state ) );
                }
            }

            // Ищем закрывающую скобку ']' с учетом кавычек внутри атрибутов
            $in_quote = '';
            $tag_end  = false;
            for ( $i = $tag_start + 1; $i < $len; $i++ ) {
                $ch = $input[ $i ];
                if ( '' !== $in_quote ) {
                    if ( $ch === $in_quote && '\\' !== $input[ $i - 1 ] ) {
                        $in_quote = '';
                    }
                    continue;
                }
                if ( '"' === $ch || "'" === $ch ) {
                    $in_quote = $ch;
                } elseif ( ']' === $ch ) {
                    $tag_end = $i;
                    break;
                }
            }

            if ( false === $tag_end ) {
                // Незакрытый тег — отдаем остаток как текст/HTML
                $rest = substr( $input, $tag_start );
                if ( $code_depth > 0 ) {
                    $segments[] = [ 'kind' => 'raw', 'text' => $rest ];
                } else {
                    $segments = array_merge( $segments, self::extract_html_segments( $rest, $state ) );
                }
                break;
            }

            $raw_tag = substr( $input, $tag_start, $tag_end - $tag_start + 1 );

            if ( preg_match( '/^\[(\/?)(et_pb_[a-zA-Z0-9_]+)/', $raw_tag, $name_match ) ) {
                $is_closing      = '/' === $name_match[1];
                $tag_name        = strtolower( $name_match[2] );
                $is_self_closing = ! $is_closing && 1 === preg_match( '/\/\s*\]$/', $raw_tag );
                $is_code_tag     = in_array( $tag_name, [ 'et_pb_code', 'et_pb_fullwidth_code' ], true );

                if ( $is_code_tag ) {
                    if ( $is_closing ) {
                        $code_depth = max( 0, $code_depth - 1 );
                    } elseif ( ! $is_self_closing ) {
                        $code_depth++;
                    }
                    $segments[] = [ 'kind' => 'raw', 'text' => $raw_tag ];
                } elseif ( $is_closing || $code_depth > 0 ) {
                    $segments[] = [ 'kind' => 'raw', 'text' => $raw_tag ];
                } else {
                    $tag_recipe = self::extract_shortcode_tag_attrs( $raw_tag, strlen( $name_match[0] ), $state );
                    $segments[] = $tag_recipe;
                }
            } else {
                $segments[] = [ 'kind' => 'raw', 'text' => $raw_tag ];
            }

            $pos = $tag_end + 1;
        }

        return $segments;
    }

    /**
     * Извлекает переводимые атрибуты из открывающего шорткода Divi 4 с сохранением точных смещений.
     *
     * @param string               $raw_tag     Полная строка шорткода `[et_pb_... ...]`.
     * @param int                  $name_end    Позиция конца имени тега.
     * @param array<string, mixed> $state       Состояние словаря.
     * @return array<string, mixed>
     */
    private static function extract_shortcode_tag_attrs( string $raw_tag, int $name_end, array &$state ): array {
        $tail_raw     = substr( $raw_tag, $name_end );
        $replacements = [];

        if ( preg_match_all( '/\s+([a-zA-Z0-9_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/u', $tail_raw, $matches, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches[1] as $idx => $attr_info ) {
                $attr_name = strtolower( $attr_info[0] );
                if ( ! in_array( $attr_name, self::TRANSLATABLE_SHORTCODE_ATTRS, true ) ) {
                    continue;
                }

                // Определяем какая группа кавычек сработала ("..." или '...')
                if ( isset( $matches[2][ $idx ] ) && -1 !== $matches[2][ $idx ][1] && '' !== $matches[0][ $idx ][0] ) {
                    // В PREG_OFFSET_CAPTURE несовпавшая группа имеет offset = -1
                    $val_raw = $matches[2][ $idx ][0];
                    $val_off = $matches[2][ $idx ][1];
                    $quote   = '"';
                } elseif ( isset( $matches[3][ $idx ] ) && -1 !== $matches[3][ $idx ][1] ) {
                    $val_raw = $matches[3][ $idx ][0];
                    $val_off = $matches[3][ $idx ][1];
                    $quote   = "'";
                } else {
                    continue;
                }

                // Декодируем спец-экранирование шорткодов Divi перед проверкой и переводом
                $decoded = str_replace(
                    [ '&quot;', '&#039;', '&#39;', '&#91;', '&#93;' ],
                    [ '"', "'", "'", '[', ']' ],
                    $val_raw
                );

                if ( ! self::is_translatable_string( $decoded, str_contains( $decoded, '<' ) ) ) {
                    continue;
                }

                $dict_key       = self::register_text( $state, $decoded );
                $replacements[] = [
                    'offset' => $name_end + $val_off,
                    'length' => strlen( $val_raw ),
                    'quote'  => $quote,
                    'key'    => $dict_key,
                ];
            }
        }

        if ( empty( $replacements ) ) {
            return [
                'kind' => 'raw',
                'text' => $raw_tag,
            ];
        }

        return [
            'kind'         => 'shortcode_tag',
            'raw_tag'      => $raw_tag,
            'replacements' => $replacements,
        ];
    }

    /**
     * Восстанавливает открывающий шорткод Divi 4 с переведенными атрибутами и корректным экранированием.
     *
     * @param array<string, mixed>  $seg             Дескриптор тега шорткода.
     * @param array<string, string> $translated_dict Переведенный словарь.
     * @return string
     */
    private static function rebuild_shortcode_tag( array $seg, array $translated_dict ): string {
        $tag          = (string) ( $seg['raw_tag'] ?? '' );
        $replacements = $seg['replacements'] ?? [];

        if ( empty( $replacements ) || ! is_array( $replacements ) ) {
            return $tag;
        }

        // Заменяем от конца строки к началу, чтобы не сдвигать байтовые смещения
        usort(
            $replacements,
            static fn( array $a, array $b ): int => (int) $b['offset'] <=> (int) $a['offset']
        );

        foreach ( $replacements as $rep ) {
            $dict_key = (string) $rep['key'];
            if ( ! isset( $translated_dict[ $dict_key ] ) ) {
                continue;
            }

            $val   = (string) $translated_dict[ $dict_key ];
            $quote = (string) ( $rep['quote'] ?? '"' );

            // Защищаем синтаксис шорткода: экранируем кавычки и квадратные скобки
            $escaped = str_replace( [ '[', ']' ], [ '&#91;', '&#93;' ], $val );
            if ( '"' === $quote ) {
                $escaped = str_replace( '"', '&quot;', $escaped );
            } else {
                $escaped = str_replace( "'", '&#039;', $escaped );
            }

            $tag = substr_replace( $tag, $escaped, (int) $rep['offset'], (int) $rep['length'] );
        }

        return $tag;
    }

    /**
     * Разбивает HTML на безопасные части, изолируя <script>, <style> и <svg>.
     *
     * @param string               $html  HTML-строка.
     * @param array<string, mixed> $state Состояние словаря.
     * @return array<int, array<string, mixed>>
     */
    private static function extract_html_segments( string $html, array &$state ): array {
        if ( '' === $html ) {
            return [];
        }

        $has_protected_blocks = false !== stripos( $html, '<script' )
            || false !== stripos( $html, '<style' )
            || false !== stripos( $html, '<svg' );

        if ( ! $has_protected_blocks ) {
            return [ self::extract_single_html_chunk( $html, $state ) ];
        }

        $parts = preg_split(
            '/(<(?:script|style|svg)\b[^>]*>.*?<\/(?:script|style|svg)>)/is',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        if ( ! is_array( $parts ) ) {
            return [ self::extract_single_html_chunk( $html, $state ) ];
        }

        $segments = [];
        foreach ( $parts as $part ) {
            if ( 1 === preg_match( '/^<(?:script|style|svg)\b/is', $part ) ) {
                $segments[] = [
                    'kind' => 'raw',
                    'text' => $part,
                ];
            } else {
                $segments[] = self::extract_single_html_chunk( $part, $state );
            }
        }

        return $segments;
    }

    /**
     * Извлекает из HTML-фрагмента атрибуты <img alt/title> и сам видимый HTML-текст.
     *
     * @param string               $chunk Чистый HTML-фрагмент без script/style/svg.
     * @param array<string, mixed> $state Состояние словаря.
     * @return array<string, mixed>
     */
    private static function extract_single_html_chunk( string $chunk, array &$state ): array {
        $img_replacements = [];

        // 1. Извлекаем alt и title из тегов <img> как отдельные ключи словаря
        if ( false !== stripos( $chunk, '<img' ) && preg_match_all( '/<img\b[^>]*>/i', $chunk, $img_matches ) ) {
            foreach ( $img_matches[0] as $img_idx => $img_tag ) {
                if ( preg_match_all( '/(?<=\s)(alt|title)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/iu', $img_tag, $attr_matches ) ) {
                    foreach ( $attr_matches[1] as $a_idx => $attr_name ) {
                        $raw_attr_val = '' !== $attr_matches[2][ $a_idx ]
                            ? $attr_matches[2][ $a_idx ]
                            : ( $attr_matches[3][ $a_idx ] ?? '' );

                        $decoded_attr = html_entity_decode( $raw_attr_val, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                        if ( self::is_translatable_string( $decoded_attr, false ) ) {
                            $img_replacements[ $img_idx ][ strtolower( $attr_name ) ] = self::register_text( $state, $decoded_attr );
                        }
                    }
                }
            }
        }

        // 2. Извлекаем сам HTML / текст, если в нем есть видимые буквы
        $has_tags = 1 === preg_match( '/<[a-zA-Z][^>]*>/', $chunk );
        $text_key = null;

        if ( self::is_translatable_string( $chunk, $has_tags ) ) {
            $text_key = self::register_text( $state, $chunk );
        }

        if ( null === $text_key && empty( $img_replacements ) ) {
            return [
                'kind' => 'raw',
                'text' => $chunk,
            ];
        }

        return [
            'kind'             => 'html_chunk',
            'original'         => $chunk,
            'text_key'         => $text_key,
            'img_replacements' => $img_replacements,
        ];
    }

    /**
     * Восстанавливает HTML-фрагмент и принудительно обновляет переведенные <img alt/title>.
     *
     * @param array<string, mixed>  $seg             Дескриптор HTML-фрагмента.
     * @param array<string, string> $translated_dict Переведенный словарь.
     * @return string
     */
    private static function apply_html_chunk( array $seg, array $translated_dict ): string {
        $text_key = $seg['text_key'] ?? null;
        $original = (string) ( $seg['original'] ?? '' );

        $html = ( null !== $text_key && isset( $translated_dict[ $text_key ] ) )
            ? (string) $translated_dict[ $text_key ]
            : $original;

        $img_replacements = $seg['img_replacements'] ?? [];
        if ( empty( $img_replacements ) || ! is_array( $img_replacements ) ) {
            return $html;
        }

        $img_idx = 0;
        return (string) preg_replace_callback(
            '/<img\b[^>]*>/i',
            static function ( array $matches ) use ( &$img_idx, $img_replacements, $translated_dict ): string {
                $tag         = $matches[0];
                $current_idx = $img_idx++;

                if ( empty( $img_replacements[ $current_idx ] ) || ! is_array( $img_replacements[ $current_idx ] ) ) {
                    return $tag;
                }

                $attr_keys = $img_replacements[ $current_idx ];

                return (string) preg_replace_callback(
                    '/(?<=\s)(alt|title)(\s*=\s*)(?:"([^"]*)"|\'([^\']*)\')/iu',
                    static function ( array $attr_match ) use ( $attr_keys, $translated_dict ): string {
                        $attr_name = strtolower( $attr_match[1] );
                        if ( ! isset( $attr_keys[ $attr_name ] ) ) {
                            return $attr_match[0];
                        }

                        $dict_key = $attr_keys[ $attr_name ];
                        if ( ! isset( $translated_dict[ $dict_key ] ) ) {
                            return $attr_match[0];
                        }

                        $safe_val = htmlspecialchars(
                            (string) $translated_dict[ $dict_key ],
                            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                            'UTF-8'
                        );

                        return $attr_match[1] . $attr_match[2] . '"' . $safe_val . '"';
                    },
                    $tag
                );
            },
            $html
        );
    }

    /**
     * Проверяет, подлежит ли строка переводу (строгая фильтрация URL, CSS, флагов и тех. ключей).
     *
     * @param string $text       Проверяемая строка.
     * @param bool   $allow_html Разрешить HTML-теги (удаляя их при подсчете видимых букв).
     * @return bool
     */
    public static function is_translatable_string( string $text, bool $allow_html = false ): bool {
        $trimmed = trim( $text );
        if ( '' === $trimmed ) {
            return false;
        }

        // Отсекаем вложенные JSON-строки
        if (
            ( str_starts_with( $trimmed, '{' ) && str_ends_with( $trimmed, '}' ) )
            || ( str_starts_with( $trimmed, '[' ) && str_ends_with( $trimmed, ']' ) )
        ) {
            $decoded_json = json_decode( $trimmed, true );
            if ( is_array( $decoded_json ) ) {
                return false;
            }
        }

        // Отсекаем URL, протоколы и email
        if (
            1 === preg_match( '#^(?:https?:)?//#i', $trimmed )
            || 1 === preg_match( '#^(?:mailto|tel|sms|data|javascript|blob):#i', $trimmed )
            || false !== filter_var( $trimmed, FILTER_VALIDATE_EMAIL )
        ) {
            return false;
        }

        // Относительные пути и якоря без пробелов (/about/, #section-1)
        if ( ( str_starts_with( $trimmed, '/' ) || str_starts_with( $trimmed, '#' ) ) && 1 !== preg_match( '/\s/u', $trimmed ) ) {
            return false;
        }

        // Имена файлов / медиа-ресурсов без пробелов
        if (
            1 !== preg_match( '/\s/u', $trimmed )
            && 1 === preg_match( '/\.(?:jpe?g|png|gif|svg|webp|avif|ico|bmp|tiff?|pdf|docx?|xlsx?|pptx?|zip|rar|7z|mp4|webm|ogg|mp3|wav|woff2?|ttf|eot|css|js|json|xml|map)$/i', $trimmed )
        ) {
            return false;
        }

        // Домены без пробелов (example.com, cdn.site.io/file)
        if (
            1 !== preg_match( '/\s/u', $trimmed )
            && 1 === preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9-]{2,})+(?:\/[^\s]*)?$/i', $trimmed )
        ) {
            return false;
        }

        // CSS-функции, цвета и числовые значения с CSS-единицами
        if ( 1 === preg_match( '/^(?:#[0-9a-f]{3,8}|(?:rgb|rgba|hsl|hsla|oklch|oklab|lab|lch|color-mix|calc|var|clamp|min|max|url|linear-gradient|radial-gradient|conic-gradient)\s*\(.*\))$/is', $trimmed ) ) {
            return false;
        }
        if ( 1 === preg_match( '/^-?\d+(?:\.\d+)?(?:px|em|rem|%|vh|vw|vmin|vmax|dvh|svh|lvh|ch|ex|pt|pc|in|cm|mm|deg|rad|turn|ms|s|fr)$/i', $trimmed ) ) {
            return false;
        }
        if (
            ! $allow_html
            && 1 === preg_match( '/^(?:(?:-?\d+(?:\.\d+)?(?:px|em|rem|%|vh|vw|deg|ms|s)?|#[0-9a-f]{3,8}|auto|none|inherit|initial|unset|transparent|currentColor|solid|dashed|dotted|double|important)\s+)+(?:-?\d+(?:\.\d+)?(?:px|em|rem|%|vh|vw|deg|ms|s)?|#[0-9a-f]{3,8}|auto|none|inherit|initial|unset|transparent|currentColor|solid|dashed|dotted|double|important)$/i', $trimmed )
        ) {
            return false;
        }

        // Технические ключевые слова и флаги настроек
        if ( in_array( strtolower( $trimmed ), self::TECHNICAL_KEYWORDS, true ) ) {
            return false;
        }

        // Идентификаторы snake_case и CSS-классы с системными префиксами
        if ( 1 !== preg_match( '/\s/u', $trimmed ) ) {
            if ( 1 === preg_match( '/^[a-z0-9]+_[a-z0-9_]+$/i', $trimmed ) ) {
                return false;
            }
            if ( 1 === preg_match( '/^(?:et[-_]|wp-|fa-|dashicons-|plu[-_]|divi[-_])[a-z0-9_-]*$/i', $trimmed ) ) {
                return false;
            }
        }

        // Проверка наличия букв (\p{L}) в видимом тексте
        $plain = $trimmed;
        if ( $allow_html ) {
            $plain = (string) preg_replace( '/<(?:script|style|svg)\b[^>]*>.*?<\/(?:script|style|svg)>/is', ' ', $plain );
            $plain = strip_tags( $plain );
        }

        $plain = html_entity_decode( $plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $plain = str_replace( "\xC2\xA0", ' ', $plain );
        // Исключаем переменные Rank Math (%title%, %sep%, %sitename% и др.)
        $plain = (string) preg_replace( '/%[a-zA-Z0-9_:-]+%/u', ' ', $plain );

        return 1 === preg_match( '/\p{L}/u', $plain );
    }

    /**
     * Регистрирует строку в словаре и возвращает присвоенный ключ (k_0, k_1, ...).
     *
     * @param array<string, mixed> $state Состояние словаря.
     * @param string               $text  Текст для перевода.
     * @return string
     */
    private static function register_text( array &$state, string $text ): string {
        $key                         = 'k_' . (int) $state['counter'];
        $state['counter']            = (int) $state['counter'] + 1;
        $state['dictionary'][ $key ] = self::sanitize_utf8( $text );

        return $key;
    }

    /**
     * Очищает строку от невалидных UTF-8 последовательностей перед JSON-сериализацией.
     *
     * @param string $str Входная строка.
     * @return string
     */
    private static function sanitize_utf8( string $str ): string {
        if ( '' === $str ) {
            return '';
        }

        if ( function_exists( 'wp_check_invalid_utf8' ) ) {
            $checked = wp_check_invalid_utf8( $str, true );
            if ( '' !== $checked ) {
                return $checked;
            }
        }

        if ( function_exists( 'mb_convert_encoding' ) ) {
            return (string) mb_convert_encoding( $str, 'UTF-8', 'UTF-8' );
        }

        return $str;
    }

    /**
     * Рекурсивная очистка всех строковых полей массива блоков от битого UTF-8.
     *
     * @param array<string|int, mixed> $data Массив блоков.
     * @return array<string|int, mixed>
     */
    private static function sanitize_blocks_utf8( array $data ): array {
        foreach ( $data as $key => $value ) {
            if ( is_string( $value ) ) {
                $data[ $key ] = self::sanitize_utf8( $value );
            } elseif ( is_array( $value ) ) {
                $data[ $key ] = self::sanitize_blocks_utf8( $value );
            }
        }

        return $data;
    }
}
