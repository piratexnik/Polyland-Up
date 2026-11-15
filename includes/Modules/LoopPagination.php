<?php
declare(strict_types=1);

namespace PolylangUp\Modules;

use PolylangUp\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Модуль «🔢 Мультиязычная Пагинация и Кнопки для Divi 5 Loop».
 *
 * Синхронизирует posts_per_page основного запроса WordPress с настройками Divi 5 Loop,
 * предотвращает ошибки 404 на дальних страницах архивов и предоставляет шорткоды
 * [plu_pagination], [scat_pagination] и [plu_read_more] с Zero Bloat инлайн-стилями.
 */
final class LoopPagination {

	/**
	 * Статический кеш определенного per_page по ключу контекста.
	 *
	 * @var array<string, int|null>
	 */
	private static array $per_page_cache = [];

	/**
	 * Защита от рекурсии внутри pre_get_posts при обращении к API шаблонов Divi.
	 */
	private static bool $in_pre_get_posts = false;

	/**
	 * Флаг активности рендера блока Divi 5 с включенным Loop.
	 */
	public static bool $in_divi_loop_block = false;

	/**
	 * Стек вложенных блоков для точного отслеживания границ Loop-блока.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private static array $block_stack = [];

	/**
	 * Количество найденных записей из последнего выполненного запроса Divi 5 Loop.
	 */
	public static ?int $last_loop_found_posts = null;

	/**
	 * Шаг выборки (posts_per_page) из последнего выполненного запроса Divi 5 Loop.
	 */
	public static ?int $last_loop_per_page = null;

	/**
	 * Флаг однократного вывода инлайн-стилей на странице.
	 */
	private static bool $styles_printed = false;

	/**
	 * Ключи настроек, содержащие количество записей на страницу в Divi 5 / Divi 4.
	 *
	 * @var string[]
	 */
	private const PER_PAGE_KEYS = [
		'posts_per_page',
		'postsPerPage',
		'post_number',
		'posts_number',
		'postsNumber',
	];

	/**
	 * Системные типы записей WordPress и Divi, которые не являются пользовательским циклом Loop.
	 *
	 * @var string[]
	 */
	private const SYSTEM_POST_TYPES = [
		'et_pb_layout',
		'et_header_layout',
		'et_body_layout',
		'et_footer_layout',
		'et_theme_builder',
		'et_template',
		'nav_menu_item',
		'revision',
		'attachment',
		'wp_navigation',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'custom_css',
		'customize_changeset',
	];

	/**
	 * Инициализация хуков и шорткодов модуля.
	 */
	public static function init(): void {
		// Отслеживание границ рендера блоков Divi 5 Loop
		add_filter( 'pre_render_block', [ self::class, 'track_loop_block_start' ], 5, 2 );
		add_filter( 'render_block', [ self::class, 'track_loop_block_end' ], 999, 2 );

		// Синхронизация основного запроса WP и вторичного WP_Query внутри Divi 5 Loop
		add_action( 'pre_get_posts', [ self::class, 'sync_main_query_posts_per_page' ], 20 );
		add_filter( 'the_posts', [ self::class, 'capture_loop_query_stats' ], 10, 2 );

		// Шорткоды пагинации и кнопки «Читать далее»
		add_shortcode( 'plu_pagination', [ self::class, 'render_shortcode_pagination' ] );
		add_shortcode( 'scat_pagination', [ self::class, 'render_shortcode_pagination' ] );
		add_shortcode( 'plu_read_more', [ self::class, 'render_shortcode_read_more' ] );

		// Гарантированное раскрытие шорткодов внутри модулей и блоков Divi 5
		add_filter( 'et_pb_module_content', [ self::class, 'process_divi_shortcodes' ], 20 );
		add_filter( 'et_module_shortcode_output', [ self::class, 'process_divi_shortcodes' ], 20 );
		add_filter( 'render_block', [ self::class, 'process_divi_shortcodes' ], 12 );
	}

	/**
	 * Возвращает значения по умолчанию для всех настроек пагинации и кнопки.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [
			'pg_accent_color'      => '#006d77',
			'pg_active_text_color' => '#ffffff',
			'pg_bg_color'          => '#ffffff',
			'pg_text_color'        => '#1e293b',
			'pg_border_color'      => '#cbd5e1',
			'pg_border_radius'     => 6,
			'pg_align'             => 'center',
			'pg_mid_size'          => 2,
			'pg_end_size'          => 1,
			'pg_show_arrows'       => 1,
			'pg_show_status'       => 1,
			'rm_style'             => 'outline',
		];
	}

	/**
	 * Отслеживает начало рендера блока Divi 5 и поднимает флаг контекста Loop-блока.
	 *
	 * @param mixed $pre_render   Предварительный вывод блока (null — продолжить стандартный рендер).
	 * @param mixed $parsed_block Данные распарсенного блока.
	 * @return mixed
	 */
	public static function track_loop_block_start( mixed $pre_render, mixed $parsed_block ): mixed {
		if ( null !== $pre_render ) {
			return $pre_render;
		}

		$loop_context = null;

		if ( is_array( $parsed_block ) ) {
			$block_name = strtolower( (string) ( $parsed_block['blockName'] ?? '' ) );
			$attrs      = is_array( $parsed_block['attrs'] ?? null ) ? $parsed_block['attrs'] : [];

			$is_loop_block = ( ! empty( $attrs ) && self::is_divi5_loop_enabled( $attrs ) )
				|| str_contains( $block_name, 'divi/blog' )
				|| str_contains( $block_name, 'divi/post-slider' )
				|| str_contains( $block_name, 'divi/loop' );

			if ( $is_loop_block ) {
				$loop_context = [
					'per_page'    => self::find_per_page_in_attrs( $attrs ),
					'base_offset' => self::find_offset_in_attrs( $attrs ),
				];
			}
		}

		self::$block_stack[]      = $loop_context;
		self::$in_divi_loop_block = ! empty( array_filter( self::$block_stack ) );

		return null;
	}

	/**
	 * Отслеживает завершение рендера блока Divi 5 и обновляет стек контекста Loop.
	 *
	 * @param mixed $block_content HTML-содержимое блока.
	 * @param mixed $block         Данные блока.
	 * @return mixed
	 */
	public static function track_loop_block_end( mixed $block_content, mixed $block = [] ): mixed {
		if ( ! empty( self::$block_stack ) ) {
			array_pop( self::$block_stack );
		}

		self::$in_divi_loop_block = ! empty( array_filter( self::$block_stack ) );

		return $block_content;
	}

	/**
	 * Возвращает параметры самого внутреннего активного Loop-блока из стека.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function get_active_loop_context(): ?array {
		for ( $i = count( self::$block_stack ) - 1; $i >= 0; $i-- ) {
			if ( is_array( self::$block_stack[ $i ] ) ) {
				return self::$block_stack[ $i ];
			}
		}

		return null;
	}

	/**
	 * Универсально определяет текущую страницу пагинации из query_var, GET-параметров и REQUEST_URI.
	 *
	 * @return int Номер страницы (>= 1).
	 */
	public static function get_current_page(): int {
		$current_page = max(
			1,
			(int) get_query_var( 'paged' ),
			(int) get_query_var( 'page' )
		);

		foreach ( [ 'paged', 'et_loop_page', 'loop_page' ] as $get_key ) {
			if ( isset( $_GET[ $get_key ] ) ) {
				$get_page = absint( wp_unslash( $_GET[ $get_key ] ) );
				if ( $get_page > 0 ) {
					$current_page = $get_page;
					break;
				}
			}
		}

		if ( $current_page <= 1 && ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$request_uri = sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) );
			$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
			if ( '' !== $path && preg_match( '#/page/(\d+)(?:/|$)#i', $path, $matches ) ) {
				$uri_page = (int) $matches[1];
				if ( $uri_page > 1 ) {
					$current_page = $uri_page;
				}
			}
		}

		return max( 1, $current_page );
	}

	/**
	 * Проверяет, содержит ли запрос исключительно системные типы записей WordPress / Divi.
	 *
	 * @param mixed $post_type Значение query_var 'post_type'.
	 * @return bool
	 */
	private static function is_only_system_post_types( mixed $post_type ): bool {
		if ( empty( $post_type ) ) {
			return false;
		}

		$types = is_array( $post_type ) ? $post_type : [ (string) $post_type ];
		$types = array_filter( array_map( 'strval', $types ) );

		if ( empty( $types ) ) {
			return false;
		}

		foreach ( $types as $pt ) {
			if ( ! in_array( $pt, self::SYSTEM_POST_TYPES, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Эвристически определяет, является ли вторичный WP_Query циклом записей Divi 5 Loop.
	 *
	 * @param \WP_Query $query Экземпляр WP_Query.
	 * @return bool
	 */
	private static function is_divi_loop_secondary_query( \WP_Query $query ): bool {
		if ( self::$in_divi_loop_block ) {
			return true;
		}

		// Исключаем запросы до основного этапа рендера шаблона
		if ( ! did_action( 'wp' ) && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			return false;
		}

		// Исключаем точечные выборки по ID/slug (например, single lookup)
		if ( $query->get( 'p' ) || $query->get( 'page_id' ) || $query->get( 'name' ) || $query->get( 'pagename' ) ) {
			return false;
		}

		$fields = (string) $query->get( 'fields' );
		$pp     = (int) $query->get( 'posts_per_page' );
		if ( 'ids' === $fields && 1 === $pp && (bool) $query->get( 'no_found_rows' ) ) {
			return false;
		}

		// Если мы на архиве/главной/поиске и идет рендер контента Divi Theme Builder
		$is_archive_context = is_archive() || is_home() || is_search();
		$in_render_phase    = doing_filter( 'the_content' )
			|| doing_filter( 'render_block' )
			|| ( did_action( 'et_before_main_content' ) > 0 && ! did_action( 'et_after_main_content' ) );

		if ( $is_archive_context && $in_render_phase ) {
			$pt = $query->get( 'post_type' );
			if ( empty( $pt ) || 'post' === $pt || ( is_array( $pt ) && in_array( 'post', $pt, true ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Синхронизирует основной запрос WordPress (предотвращает 404 на дальних страницах)
	 * и инжектирует paged / offset во вторичные WP_Query внутри блоков Divi 5 Loop.
	 *
	 * @param mixed $query Экземпляр WP_Query.
	 */
	public static function sync_main_query_posts_per_page( mixed $query ): void {
		if ( self::$in_pre_get_posts || ! $query instanceof \WP_Query ) {
			return;
		}

		if ( is_admin() || (bool) $query->get( 'suppress_filters' ) ) {
			return;
		}

		if ( self::is_only_system_post_types( $query->get( 'post_type' ) ) ) {
			return;
		}

		self::$in_pre_get_posts = true;
		try {
			// 1. Основной запрос WordPress
			if ( $query->is_main_query() ) {
				if ( $query->is_archive() || $query->is_home() || $query->is_search() ) {
					$per_page = self::detect_divi_loop_per_page();
					if ( null !== $per_page && $per_page > 0 ) {
						$query->set( 'posts_per_page', $per_page );
					}
				}
				return;
			}

			// 2. Вторичный запрос внутри блока Divi 5 Loop
			if ( ! self::is_divi_loop_secondary_query( $query ) ) {
				return;
			}

			$ctx      = self::get_active_loop_context();
			$paged    = self::get_current_page();
			$q_pp     = (int) $query->get( 'posts_per_page' );
			$per_page = $q_pp > 0
				? $q_pp
				: ( $ctx['per_page'] ?? self::detect_divi_loop_per_page() ?? (int) get_option( 'posts_per_page', 10 ) );

			if ( $per_page <= 0 ) {
				$per_page = 10;
			}

			if ( $paged > 1 ) {
				$query->set( 'paged', $paged );

				// КРИТИЧНО: в WordPress любой числовой offset (даже 0) отключает paged,
				// поэтому пересчитываем реальный сдвиг с учетом текущей страницы.
				if (
					array_key_exists( 'offset', $query->query_vars )
					&& '' !== $query->query_vars['offset']
					&& false !== $query->query_vars['offset']
					&& null !== $query->query_vars['offset']
				) {
					$raw_offset  = max( 0, (int) $query->get( 'offset' ) );
					$ctx_offset  = isset( $ctx['base_offset'] ) ? (int) $ctx['base_offset'] : null;
					$base_offset = ( null !== $ctx_offset && 0 === $raw_offset ) ? $ctx_offset : $raw_offset;
					$calc_offset = $base_offset + ( ( $paged - 1 ) * $per_page );

					$query->set( 'offset', $calc_offset );
				}
			}

			// Синхронизируем таксономию текущего архива, если Divi Loop не передал её явно
			if (
				self::$in_divi_loop_block
				&& ( is_category() || is_tag() || is_tax() )
				&& empty( $query->get( 'tax_query' ) )
				&& empty( $query->get( 'cat' ) )
				&& empty( $query->get( 'category_name' ) )
				&& empty( $query->get( 'tag' ) )
				&& empty( $query->get( 'tag_id' ) )
			) {
				$queried_obj = get_queried_object();
				if ( $queried_obj instanceof \WP_Term ) {
					$query->set(
						'tax_query',
						[
							[
								'taxonomy' => $queried_obj->taxonomy,
								'field'    => 'term_id',
								'terms'    => [ $queried_obj->term_id ],
							],
						]
					);
				}
			}

			$query->set( 'no_found_rows', false );
			$query->set( '_plu_is_divi_loop', true );
		} finally {
			self::$in_pre_get_posts = false;
		}
	}

	/**
	 * Перехватывает реальные found_posts и posts_per_page отработавшего запроса Divi 5 Loop.
	 *
	 * @param mixed $posts Массив найденных записей.
	 * @param mixed $query Экземпляр WP_Query.
	 * @return mixed
	 */
	public static function capture_loop_query_stats( mixed $posts, mixed $query = null ): mixed {
		if ( $query instanceof \WP_Query && true === $query->get( '_plu_is_divi_loop' ) ) {
			self::$last_loop_found_posts = max( 0, (int) $query->found_posts );
			$q_pp                        = (int) $query->get( 'posts_per_page' );
			if ( $q_pp > 0 ) {
				self::$last_loop_per_page = $q_pp;
			}
		}

		return $posts;
	}

	/**
	 * Автоматически определяет количество записей на страницу (per_page) из активного шаблона Divi 5 / 4.
	 *
	 * @param int|null $post_id Опциональный ID конкретной записи/страницы.
	 * @return int|null Найденное значение posts_per_page или null, если не обнаружено.
	 */
	public static function detect_divi_loop_per_page( ?int $post_id = null ): ?int {
		$cache_key = null !== $post_id && $post_id > 0 ? 'post_' . $post_id : 'main';
		if ( array_key_exists( $cache_key, self::$per_page_cache ) ) {
			return self::$per_page_cache[ $cache_key ];
		}

		$detected = null;

		// 1. Если передан конкретный post_id (обычная страница/запись с Loop-блоком)
		if ( null !== $post_id && $post_id > 0 ) {
			$post_content = (string) get_post_field( 'post_content', $post_id );
			if ( '' !== trim( $post_content ) ) {
				$detected = self::extract_per_page_from_content( $post_content );
			}
		}

		// 2. Поиск активного макета et_body_layout в Divi Theme Builder
		if ( null === $detected ) {
			$body_layout_id = self::resolve_active_body_layout_id( $post_id );
			if ( $body_layout_id > 0 ) {
				$layout_content = (string) get_post_field( 'post_content', $body_layout_id );
				if ( '' !== trim( $layout_content ) ) {
					$detected = self::extract_per_page_from_content( $layout_content );
				}
			}
		}

		self::$per_page_cache[ $cache_key ] = $detected;
		return $detected;
	}

	/**
	 * Определяет ID активного шаблона тела (et_body_layout) из Divi Theme Builder.
	 *
	 * @param int|null $post_id Опциональный ID записи.
	 * @return int ID поста et_body_layout или 0.
	 */
	private static function resolve_active_body_layout_id( ?int $post_id = null ): int {
		// Способ А: Нативный API Divi Theme Builder
		if ( function_exists( 'et_theme_builder_get_template_layouts' ) ) {
			try {
				$layouts  = et_theme_builder_get_template_layouts();
				$body_key = defined( 'ET_THEME_BUILDER_BODY_LAYOUT_POST_TYPE' ) ? ET_THEME_BUILDER_BODY_LAYOUT_POST_TYPE : 'et_body_layout';

				if ( is_array( $layouts ) && ! empty( $layouts[ $body_key ]['enabled'] ) && ! empty( $layouts[ $body_key ]['id'] ) ) {
					return (int) $layouts[ $body_key ]['id'];
				}
			} catch ( \Throwable ) {
				// Игнорируем возможные ранние ошибки API Divi до wp_loaded
			}
		}

		// Способ Б: Прямое чтение структуры _et_theme_builder / _et_template / _et_body_layout_id
		$tb_posts = get_posts(
			[
				'post_type'      => 'et_theme_builder',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		if ( empty( $tb_posts ) ) {
			return 0;
		}

		$tb_id        = (int) $tb_posts[0];
		$template_ids = get_post_meta( $tb_id, '_et_template', false );
		if ( empty( $template_ids ) || ! is_array( $template_ids ) ) {
			return 0;
		}

		$default_layout_id = 0;

		foreach ( $template_ids as $tpl_id ) {
			$tpl_id = (int) $tpl_id;
			if ( $tpl_id <= 0 ) {
				continue;
			}

			$enabled = (string) get_post_meta( $tpl_id, '_et_enabled', true );
			if ( '1' !== $enabled ) {
				continue;
			}

			$body_id = (int) get_post_meta( $tpl_id, '_et_body_layout_id', true );
			if ( $body_id <= 0 ) {
				continue;
			}

			$is_default = ( '1' === (string) get_post_meta( $tpl_id, '_et_default', true ) );
			if ( $is_default ) {
				$default_layout_id = $body_id;
				continue;
			}

			$use_on = get_post_meta( $tpl_id, '_et_use_on', false );
			if ( is_array( $use_on ) && self::matches_theme_builder_rules( $use_on, $post_id ) ) {
				$exclude_from = get_post_meta( $tpl_id, '_et_exclude_from', false );
				if ( is_array( $exclude_from ) && self::matches_theme_builder_rules( $exclude_from, $post_id ) ) {
					continue;
				}
				return $body_id;
			}
		}

		return $default_layout_id;
	}

	/**
	 * Проверяет соответствие правил _et_use_on / _et_exclude_from текущему запросу WordPress.
	 *
	 * @param array<int, mixed> $rules   Массив правил Divi Theme Builder.
	 * @param int|null          $post_id Опциональный ID записи.
	 * @return bool
	 */
	private static function matches_theme_builder_rules( array $rules, ?int $post_id = null ): bool {
		foreach ( $rules as $rule ) {
			if ( ! is_string( $rule ) || '' === $rule ) {
				continue;
			}

			if ( 'archive:all' === $rule && ( is_archive() || is_home() || is_search() ) ) {
				return true;
			}

			if ( 'home' === $rule && is_home() ) {
				return true;
			}

			if ( 'search' === $rule && is_search() ) {
				return true;
			}

			if ( 'archive:taxonomy:category' === $rule && is_category() ) {
				return true;
			}

			if ( 'archive:taxonomy:post_tag' === $rule && is_tag() ) {
				return true;
			}

			if ( str_starts_with( $rule, 'archive:taxonomy:' ) ) {
				$parts = explode( ':', $rule );
				$tax   = $parts[2] ?? '';
				if ( '' !== $tax && is_tax( $tax ) ) {
					if ( isset( $parts[4] ) && 'id' === ( $parts[3] ?? '' ) ) {
						if ( is_tax( $tax, (int) $parts[4] ) ) {
							return true;
						}
					} else {
						return true;
					}
				}
			}

			if ( str_starts_with( $rule, 'archive:post_type:' ) ) {
				$pt = substr( $rule, strlen( 'archive:post_type:' ) );
				if ( 'post' === $pt && ( is_home() || is_category() || is_tag() || is_date() ) ) {
					return true;
				}
				if ( '' !== $pt && is_post_type_archive( $pt ) ) {
					return true;
				}
			}

			if ( null !== $post_id && $post_id > 0 && str_starts_with( $rule, 'singular:' ) ) {
				$pt = (string) get_post_type( $post_id );
				if ( "singular:post_type:{$pt}:all" === $rule || "singular:post_type:{$pt}:id:{$post_id}" === $rule ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Извлекает значение posts_per_page из контента блоков Divi 5 или шорткодов Divi 4.
	 *
	 * @param string $content Содержимое поста или макета.
	 * @return int|null
	 */
	public static function extract_per_page_from_content( string $content ): ?int {
		if ( '' === trim( $content ) ) {
			return null;
		}

		// 1. Парсинг блоков Gutenberg / Divi 5
		if ( function_exists( 'parse_blocks' ) && ( str_contains( $content, '<!-- wp:' ) || ( function_exists( 'has_blocks' ) && has_blocks( $content ) ) ) ) {
			$blocks = parse_blocks( $content );
			$found  = self::find_per_page_in_blocks( $blocks );
			if ( null !== $found ) {
				return $found;
			}
		}

		// 2. Фоллбэк на шорткоды Divi 4 ([et_pb_blog posts_number="10" ...])
		if ( str_contains( $content, '[et_pb_' ) ) {
			if ( preg_match( '/\[et_pb_[^\]]*\b(?:posts_per_page|posts_number|post_number)\s*=\s*["\']?(\d+)["\']?/i', $content, $m ) ) {
				$val = (int) $m[1];
				if ( $val > 0 && $val <= 500 ) {
					return $val;
				}
			}
		}

		return null;
	}

	/**
	 * Рекурсивный поиск настройки количества записей на страницу в дереве блоков Divi 5.
	 *
	 * @param array<int, array<string, mixed>> $blocks Массив блоков.
	 * @return int|null
	 */
	private static function find_per_page_in_blocks( array $blocks ): ?int {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$block_name = strtolower( (string) ( $block['blockName'] ?? '' ) );
			$attrs      = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];

			if ( ! empty( $attrs ) ) {
				$is_loop_enabled = self::is_divi5_loop_enabled( $attrs );
				$is_post_module  = str_contains( $block_name, 'blog' )
					|| str_contains( $block_name, 'post' )
					|| str_contains( $block_name, 'loop' );

				if ( $is_loop_enabled || $is_post_module ) {
					$found = self::find_per_page_in_attrs( $attrs );
					if ( null !== $found ) {
						return $found;
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$nested = self::find_per_page_in_blocks( $block['innerBlocks'] );
				if ( null !== $nested ) {
					return $nested;
				}
			}
		}

		return null;
	}

	/**
	 * Проверяет, включен ли режим Loop в JSON-атрибутах блока Divi 5.
	 *
	 * @param array<string|int, mixed> $attrs Атрибуты блока.
	 * @return bool
	 */
	private static function is_divi5_loop_enabled( array $attrs ): bool {
		if (
			isset( $attrs['loop']['enable']['desktop']['value'] )
			&& 'on' === strtolower( (string) $attrs['loop']['enable']['desktop']['value'] )
		) {
			return true;
		}

		if (
			isset( $attrs['loop']['enable'] )
			&& is_string( $attrs['loop']['enable'] )
			&& 'on' === strtolower( $attrs['loop']['enable'] )
		) {
			return true;
		}

		foreach ( $attrs as $key => $val ) {
			if ( 'loop' === (string) $key && is_array( $val ) && self::is_divi5_loop_enabled( [ 'loop' => $val ] ) ) {
				return true;
			}
			if ( is_array( $val ) && 'loop' !== (string) $key && isset( $val['loop'] ) && is_array( $val['loop'] ) ) {
				if ( self::is_divi5_loop_enabled( [ 'loop' => $val['loop'] ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Рекурсивно ищет числовое значение posts_per_page / postsPerPage / posts_number в массиве атрибутов.
	 *
	 * @param array<string|int, mixed> $node Узел атрибутов.
	 * @return int|null
	 */
	private static function find_per_page_in_attrs( array $node ): ?int {
		foreach ( $node as $key => $value ) {
			if ( in_array( (string) $key, self::PER_PAGE_KEYS, true ) ) {
				$extracted = self::extract_positive_int( $value );
				if ( null !== $extracted ) {
					return $extracted;
				}
			}

			if ( is_array( $value ) ) {
				$nested = self::find_per_page_in_attrs( $value );
				if ( null !== $nested ) {
					return $nested;
				}
			}
		}

		return null;
	}

	/**
	 * Извлекает положительное целое число из скаляра или вложенной структуры Divi 5 (desktop -> value).
	 *
	 * @param mixed $value Значение атрибута.
	 * @return int|null
	 */
	private static function extract_positive_int( mixed $value ): ?int {
		if ( is_int( $value ) || ( is_string( $value ) && is_numeric( trim( $value ) ) ) ) {
			$num = (int) trim( (string) $value );
			return ( $num > 0 && $num <= 500 ) ? $num : null;
		}

		if ( is_array( $value ) ) {
			if ( isset( $value['desktop']['value'] ) ) {
				return self::extract_positive_int( $value['desktop']['value'] );
			}
			if ( isset( $value['value'] ) ) {
				return self::extract_positive_int( $value['value'] );
			}
		}

		return null;
	}

	/**
	 * Рекурсивно ищет стартовый сдвиг (offset / post_offset) в массиве атрибутов блока Divi 5.
	 *
	 * @param array<string|int, mixed> $node Узел атрибутов.
	 * @return int|null
	 */
	private static function find_offset_in_attrs( array $node ): ?int {
		$offset_keys = [ 'offset', 'post_offset', 'postOffset', 'posts_offset', 'postsOffset' ];

		foreach ( $node as $key => $value ) {
			if ( in_array( (string) $key, $offset_keys, true ) ) {
				$extracted = self::extract_non_negative_int( $value );
				if ( null !== $extracted ) {
					return $extracted;
				}
			}

			if ( is_array( $value ) ) {
				$nested = self::find_offset_in_attrs( $value );
				if ( null !== $nested ) {
					return $nested;
				}
			}
		}

		return null;
	}

	/**
	 * Извлекает неотрицательное целое число из скаляра или вложенной структуры Divi 5 (desktop -> value).
	 *
	 * @param mixed $value Значение атрибута.
	 * @return int|null
	 */
	private static function extract_non_negative_int( mixed $value ): ?int {
		if ( is_int( $value ) || ( is_string( $value ) && is_numeric( trim( $value ) ) ) ) {
			$num = (int) trim( (string) $value );
			return $num >= 0 ? $num : null;
		}

		if ( is_array( $value ) ) {
			if ( isset( $value['desktop']['value'] ) ) {
				return self::extract_non_negative_int( $value['desktop']['value'] );
			}
			if ( isset( $value['value'] ) ) {
				return self::extract_non_negative_int( $value['value'] );
			}
		}

		return null;
	}

	/**
	 * Гарантированное раскрытие шорткодов модуля в выводе Divi 5 / Theme Builder.
	 *
	 * @param mixed $output HTML-вывод модуля или блока.
	 * @return mixed
	 */
	public static function process_divi_shortcodes( mixed $output ): mixed {
		if ( ! is_string( $output ) || '' === $output ) {
			return $output;
		}

		if (
			str_contains( $output, '[plu_pagination' )
			|| str_contains( $output, '[scat_pagination' )
			|| str_contains( $output, '[plu_read_more' )
		) {
			return do_shortcode( $output );
		}

		return $output;
	}

	/**
	 * Проверяет, выполняется ли рендер внутри Divi Visual Builder (фронтенд-билдер, AJAX или REST edit).
	 *
	 * @return bool
	 */
	private static function is_builder_preview(): bool {
		if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && isset( $_GET['context'] ) && 'edit' === sanitize_key( wp_unslash( (string) $_GET['context'] ) ) ) {
			return true;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() && isset( $_POST['action'] ) ) {
			$action = sanitize_key( wp_unslash( (string) $_POST['action'] ) );
			if ( str_contains( $action, 'et_fb' ) || str_contains( $action, 'et_builder' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Возвращает мультиязычную строку из StringsManager с локализованным фоллбэком.
	 *
	 * @param string $key Ключ строки.
	 * @return string
	 */
	private static function get_ui_label( string $key ): string {
		$lang = StringsManager::get_current_lang_slug();

		$fallbacks = [
			'pagination_prev' => [
				'uk' => '« Попередня',
				'ua' => '« Попередня',
				'en' => '« Previous',
				'ru' => '« Предыдущая',
			],
			'pagination_next' => [
				'uk' => 'Наступна »',
				'ua' => 'Наступна »',
				'en' => 'Next »',
				'ru' => 'Следующая »',
			],
			'pagination_status' => [
				'uk' => 'Сторінка %1$d з %2$d',
				'ua' => 'Сторінка %1$d з %2$d',
				'en' => 'Page %1$d of %2$d',
				'ru' => 'Страница %1$d из %2$d',
			],
			'read_more' => [
				'uk' => 'Читати далі',
				'ua' => 'Читати далі',
				'en' => 'Read more',
				'ru' => 'Читать далее',
			],
		];

		$default = $fallbacks[ $key ][ $lang ] ?? ( $fallbacks[ $key ]['uk'] ?? '' );
		$val     = StringsManager::get_string( $key, $default );

		return '' !== trim( $val ) ? $val : $default;
	}

	/**
	 * Шорткод [plu_pagination] и алиас [scat_pagination].
	 *
	 * @param mixed $atts Атрибуты шорткода.
	 * @return string
	 */
	public static function render_shortcode_pagination( mixed $atts = [] ): string {
		global $wp_query;

		$defaults = self::get_defaults();

		$atts = shortcode_atts(
			[
				'per_page'    => '',
				'mid_size'    => (string) plu_get_option( 'pg_mid_size', $defaults['pg_mid_size'] ),
				'end_size'    => (string) plu_get_option( 'pg_end_size', $defaults['pg_end_size'] ),
				'show_arrows' => (string) plu_get_option( 'pg_show_arrows', $defaults['pg_show_arrows'] ),
				'show_status' => (string) plu_get_option( 'pg_show_status', $defaults['pg_show_status'] ),
				'align'       => (string) plu_get_option( 'pg_align', $defaults['pg_align'] ),
			],
			is_array( $atts ) ? $atts : [],
			'plu_pagination'
		);

		$mid_size    = max( 1, min( 10, (int) $atts['mid_size'] ) );
		$end_size    = max( 1, min( 5, (int) $atts['end_size'] ) );
		$show_arrows = '0' !== (string) $atts['show_arrows'] && 'false' !== strtolower( (string) $atts['show_arrows'] );
		$show_status = '0' !== (string) $atts['show_status'] && 'false' !== strtolower( (string) $atts['show_status'] );
		$align       = sanitize_key( (string) $atts['align'] );
		if ( ! in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
			$align = 'center';
		}

		// Определяем текущую страницу
		$current_page = self::get_current_page();

		// Определяем per_page и total_pages (с приоритетом реальных данных отработавшего Divi 5 Loop)
		$custom_per_page = ( '' !== trim( (string) $atts['per_page'] ) ) ? (int) $atts['per_page'] : 0;
		$found_posts     = self::$last_loop_found_posts ?? ( ( $wp_query instanceof \WP_Query ) ? (int) $wp_query->found_posts : 0 );

		if ( $custom_per_page > 0 ) {
			$per_page    = $custom_per_page;
			$total_pages = $found_posts > 0 ? (int) ceil( $found_posts / $per_page ) : 1;
		} else {
			$detected = self::$last_loop_per_page ?? self::detect_divi_loop_per_page();
			$query_pp = ( $wp_query instanceof \WP_Query ) ? (int) $wp_query->get( 'posts_per_page' ) : 0;
			$per_page = $detected ?? ( $query_pp > 0 ? $query_pp : (int) get_option( 'posts_per_page', 10 ) );

			if ( null !== self::$last_loop_found_posts && $per_page > 0 ) {
				$total_pages = $found_posts > 0 ? (int) ceil( $found_posts / $per_page ) : 1;
			} else {
				$total_pages = ( $wp_query instanceof \WP_Query ) ? (int) $wp_query->max_num_pages : 1;
				if ( $total_pages < 1 && $found_posts > 0 && $per_page > 0 ) {
					$total_pages = (int) ceil( $found_posts / $per_page );
				}
			}
		}

		$is_preview = false;
		if ( $total_pages < 2 ) {
			if ( self::is_builder_preview() ) {
				$is_preview   = true;
				$current_page = 2;
				$total_pages  = 10;
			} else {
				return '';
			}
		}

		$prev_text   = self::get_ui_label( 'pagination_prev' );
		$next_text   = self::get_ui_label( 'pagination_next' );
		$status_tpl  = self::get_ui_label( 'pagination_status' );
		$status_text = sprintf( $status_tpl, $current_page, $total_pages );

		if ( $is_preview ) {
			$items_html = self::build_preview_items_html( $current_page, $total_pages, $show_arrows, $prev_text, $next_text );
		} else {
			$links = paginate_links(
				[
					'type'      => 'array',
					'total'     => $total_pages,
					'current'   => min( $current_page, $total_pages ),
					'mid_size'  => $mid_size,
					'end_size'  => $end_size,
					'prev_next' => $show_arrows,
					'prev_text' => esc_html( $prev_text ),
					'next_text' => esc_html( $next_text ),
				]
			);

			if ( ! is_array( $links ) || empty( $links ) ) {
				return '';
			}

			$items_html = '';
			foreach ( $links as $link_html ) {
				$items_html .= '<li class="plu-page-item">' . $link_html . '</li>';
			}
		}

		$styles = self::render_inline_styles_once();

		$status_html = $show_status
			? '<div class="plu-pagination-status">' . esc_html( $status_text ) . '</div>'
			: '';

		return sprintf(
			'%s<nav class="plu-pagination-wrap plu-align-%s" aria-label="%s">%s<ul class="plu-pagination-list">%s</ul></nav>',
			$styles,
			esc_attr( $align ),
			esc_attr__( 'Навигация по страницам', 'polyland-up' ),
			$status_html,
			$items_html
		);
	}

	/**
	 * Строит демонстрационный HTML элементов пагинации для превью внутри Divi 5 Visual Builder.
	 */
	private static function build_preview_items_html(
		int $current_page,
		int $total_pages,
		bool $show_arrows,
		string $prev_text,
		string $next_text
	): string {
		$items = [];

		if ( $show_arrows ) {
			$items[] = '<a class="prev page-numbers" href="#prev">' . esc_html( $prev_text ) . '</a>';
		}

		$items[] = '<a class="page-numbers" href="#page-1">1</a>';
		$items[] = '<span aria-current="page" class="page-numbers current">' . esc_html( (string) $current_page ) . '</span>';
		$items[] = '<a class="page-numbers" href="#page-3">3</a>';
		$items[] = '<span class="page-numbers dots">&hellip;</span>';
		$items[] = '<a class="page-numbers" href="#page-10">' . esc_html( (string) $total_pages ) . '</a>';

		if ( $show_arrows ) {
			$items[] = '<a class="next page-numbers" href="#next">' . esc_html( $next_text ) . '</a>';
		}

		$html = '';
		foreach ( $items as $item ) {
			$html .= '<li class="plu-page-item">' . $item . '</li>';
		}
		return $html;
	}

	/**
	 * Шорткод мультиязычной кнопки [plu_read_more] для карточек Divi 5 Loop.
	 *
	 * @param mixed $atts Атрибуты шорткода.
	 * @return string
	 */
	public static function render_shortcode_read_more( mixed $atts = [] ): string {
		$defaults = self::get_defaults();

		$atts = shortcode_atts(
			[
				'id'    => '',
				'text'  => '',
				'icon'  => '→',
				'class' => '',
			],
			is_array( $atts ) ? $atts : [],
			'plu_read_more'
		);

		$post_id = absint( $atts['id'] );
		if ( $post_id <= 0 ) {
			$post_id = (int) get_the_ID();
		}

		$url = $post_id > 0 ? (string) get_permalink( $post_id ) : '#';
		if ( '' === $url ) {
			$url = '#';
		}

		$label = '' !== trim( (string) $atts['text'] )
			? (string) $atts['text']
			: self::get_ui_label( 'read_more' );

		$rm_style = sanitize_key( (string) plu_get_option( 'rm_style', $defaults['rm_style'] ) );
		if ( ! in_array( $rm_style, [ 'solid', 'outline', 'link' ], true ) ) {
			$rm_style = 'outline';
		}

		$extra_classes = [];
		if ( '' !== trim( (string) $atts['class'] ) ) {
			foreach ( preg_split( '/\s+/', trim( (string) $atts['class'] ) ) ?: [] as $cls ) {
				$clean_cls = sanitize_html_class( $cls );
				if ( '' !== $clean_cls ) {
					$extra_classes[] = $clean_cls;
				}
			}
		}

		$all_classes = trim( 'plu-read-more-btn plu-rm-style-' . $rm_style . ' ' . implode( ' ', $extra_classes ) );
		$icon        = trim( (string) $atts['icon'] );
		$icon_html   = '' !== $icon ? ' <span class="plu-rm-icon" aria-hidden="true">' . esc_html( $icon ) . '</span>' : '';

		$styles = self::render_inline_styles_once();

		return sprintf(
			'%s<a href="%s" class="%s"><span class="plu-rm-text">%s</span>%s</a>',
			$styles,
			esc_url( $url ),
			esc_attr( $all_classes ),
			esc_html( $label ),
			$icon_html
		);
	}

	/**
	 * Генерирует компактный инлайн-блок стилей с CSS-переменными однократно за запрос (Zero Bloat).
	 *
	 * @return string
	 */
	private static function render_inline_styles_once(): string {
		if ( self::$styles_printed ) {
			return '';
		}
		self::$styles_printed = true;

		$defaults     = self::get_defaults();
		$accent       = sanitize_hex_color( (string) plu_get_option( 'pg_accent_color', $defaults['pg_accent_color'] ) ) ?: '#006d77';
		$active_text  = sanitize_hex_color( (string) plu_get_option( 'pg_active_text_color', $defaults['pg_active_text_color'] ) ) ?: '#ffffff';
		$bg_color     = sanitize_hex_color( (string) plu_get_option( 'pg_bg_color', $defaults['pg_bg_color'] ) ) ?: '#ffffff';
		$text_color   = sanitize_hex_color( (string) plu_get_option( 'pg_text_color', $defaults['pg_text_color'] ) ) ?: '#1e293b';
		$border_color = sanitize_hex_color( (string) plu_get_option( 'pg_border_color', $defaults['pg_border_color'] ) ) ?: '#cbd5e1';
		$radius       = max( 0, min( 50, (int) plu_get_option( 'pg_border_radius', $defaults['pg_border_radius'] ) ) );

		$css = sprintf(
			':root{--plu-pg-accent:%1$s;--plu-pg-active-text:%2$s;--plu-pg-bg:%3$s;--plu-pg-text:%4$s;--plu-pg-border:%5$s;--plu-pg-radius:%6$dpx;}',
			$accent,
			$active_text,
			$bg_color,
			$text_color,
			$border_color,
			$radius
		);

		$css .= '.plu-pagination-wrap{display:flex;flex-direction:column;gap:10px;margin:24px 0;width:100%;}'
			. '.plu-pagination-wrap.plu-align-left{align-items:flex-start;}'
			. '.plu-pagination-wrap.plu-align-center{align-items:center;}'
			. '.plu-pagination-wrap.plu-align-right{align-items:flex-end;}'
			. '.plu-pagination-status{font-size:13px;color:var(--plu-pg-text);opacity:.78;font-weight:500;}'
			. '.plu-pagination-list{display:flex;flex-wrap:wrap;align-items:center;gap:6px;list-style:none;margin:0;padding:0;}'
			. '.plu-pagination-wrap.plu-align-center .plu-pagination-list{justify-content:center;}'
			. '.plu-pagination-wrap.plu-align-right .plu-pagination-list{justify-content:flex-end;}'
			. '.plu-page-item{margin:0;padding:0;list-style:none;}'
			. '.plu-page-item .page-numbers{display:inline-flex;align-items:center;justify-content:center;min-width:38px;height:38px;padding:0 12px;border-radius:var(--plu-pg-radius);background:var(--plu-pg-bg);color:var(--plu-pg-text);border:1px solid var(--plu-pg-border);text-decoration:none;font-size:14px;font-weight:600;line-height:1;transition:all .18s ease;box-sizing:border-box;}'
			. '.plu-page-item a.page-numbers:hover,.plu-page-item a.page-numbers:focus-visible{background:var(--plu-pg-accent);color:var(--plu-pg-active-text);border-color:var(--plu-pg-accent);}'
			. '.plu-page-item .page-numbers.current{background:var(--plu-pg-accent);color:var(--plu-pg-active-text);border-color:var(--plu-pg-accent);}'
			. '.plu-page-item .page-numbers.dots{background:transparent;border-color:transparent;min-width:auto;padding:0 4px;}'
			. '.plu-read-more-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:var(--plu-pg-radius);font-size:14px;font-weight:600;line-height:1.2;text-decoration:none;transition:all .18s ease;cursor:pointer;box-sizing:border-box;}'
			. '.plu-rm-style-solid{background:var(--plu-pg-accent);color:var(--plu-pg-active-text);border:1px solid var(--plu-pg-accent);}'
			. '.plu-rm-style-solid:hover{opacity:.9;transform:translateY(-1px);color:var(--plu-pg-active-text);}'
			. '.plu-rm-style-outline{background:transparent;color:var(--plu-pg-accent);border:1px solid var(--plu-pg-accent);}'
			. '.plu-rm-style-outline:hover{background:var(--plu-pg-accent);color:var(--plu-pg-active-text);}'
			. '.plu-rm-style-link{background:transparent;color:var(--plu-pg-accent);border:1px solid transparent;padding-left:0;padding-right:0;}'
			. '.plu-rm-style-link:hover{text-decoration:underline;color:var(--plu-pg-accent);}'
			. '.plu-read-more-btn .plu-rm-icon{transition:transform .18s ease;display:inline-block;}'
			. '.plu-read-more-btn:hover .plu-rm-icon{transform:translateX(3px);}';

		return '<style id="plu-loop-pagination-css">' . $css . '</style>';
	}

	/**
	 * Отрисовка интерфейса вкладки «🔢 Пагинация и Loop» в админ-панели.
	 */
	public static function render_tab_content(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$defaults     = self::get_defaults();
		$accent       = sanitize_hex_color( (string) plu_get_option( 'pg_accent_color', $defaults['pg_accent_color'] ) ) ?: '#006d77';
		$active_text  = sanitize_hex_color( (string) plu_get_option( 'pg_active_text_color', $defaults['pg_active_text_color'] ) ) ?: '#ffffff';
		$bg_color     = sanitize_hex_color( (string) plu_get_option( 'pg_bg_color', $defaults['pg_bg_color'] ) ) ?: '#ffffff';
		$text_color   = sanitize_hex_color( (string) plu_get_option( 'pg_text_color', $defaults['pg_text_color'] ) ) ?: '#1e293b';
		$border_color = sanitize_hex_color( (string) plu_get_option( 'pg_border_color', $defaults['pg_border_color'] ) ) ?: '#cbd5e1';
		$radius       = max( 0, min( 50, (int) plu_get_option( 'pg_border_radius', $defaults['pg_border_radius'] ) ) );
		$align        = (string) plu_get_option( 'pg_align', $defaults['pg_align'] );
		$mid_size     = max( 1, min( 10, (int) plu_get_option( 'pg_mid_size', $defaults['pg_mid_size'] ) ) );
		$end_size     = max( 1, min( 5, (int) plu_get_option( 'pg_end_size', $defaults['pg_end_size'] ) ) );
		$show_arrows  = (int) plu_get_option( 'pg_show_arrows', $defaults['pg_show_arrows'] );
		$show_status  = (int) plu_get_option( 'pg_show_status', $defaults['pg_show_status'] );
		$rm_style     = (string) plu_get_option( 'rm_style', $defaults['rm_style'] );

		$shortcodes = [
			[
				'code' => '[plu_pagination]',
				'desc' => __( 'Универсальная пагинация с авто-определением количества записей из настроек Loop в Divi 5.', 'polyland-up' ),
			],
			[
				'code' => '[plu_pagination per_page="10" mid_size="2" show_status="1"]',
				'desc' => __( 'Пример с ручным переопределением параметров (количество на страницу, глубина и статус).', 'polyland-up' ),
			],
			[
				'code' => '[scat_pagination]',
				'desc' => __( 'Поддерживаемый алиас для совместимости с шаблонами SCaT.', 'polyland-up' ),
			],
			[
				'code' => '[plu_read_more]',
				'desc' => __( 'Мультиязычная кнопка «Читати далі» со ссылкой на текущую статью внутри карточки цикла Divi 5 Loop.', 'polyland-up' ),
			],
		];

		$strings_url = admin_url( 'admin.php?page=' . Settings::PAGE_SLUG . '&tab=strings' );
		?>
		<div class="plu-pagination-admin-wrap" id="plu-pagination-admin-box">
			<!-- Блок 1: Инструкция и шпаргалка по шорткодам -->
			<div class="plu-pg-card plu-pg-guide-card">
				<h2 style="margin-top:0;"><?php esc_html_e( '🔢 Мультиязычная Пагинация и Кнопки для Divi 5 Loop', 'polyland-up' ); ?></h2>
				<p class="description" style="font-size:13px; margin-bottom:14px;">
					<?php
					printf(
						esc_html__( 'Плагин автоматически сканирует активный шаблон Divi 5 Theme Builder, находит шаг цикла Loop и синхронизирует его с основным запросом WordPress (pre_get_posts), защищая дальние страницы (/page/40/, /page/66/) от ошибки 404. Переводы надписей («Попередня», «Наступна», «Сторінка X з Y», «Читати далі») редактируются во вкладке %s.', 'polyland-up' ),
						'<a href="' . esc_url( $strings_url ) . '"><strong>' . esc_html__( '🔤 Строки шаблонов (UI)', 'polyland-up' ) . '</strong></a>'
					);
					?>
				</p>

				<div style="background:#fffbeb;border-left:4px solid #d97706;padding:12px 14px;border-radius:4px;margin-bottom:14px;font-size:13px;line-height:1.5;color:#1e293b;">
					<strong><?php esc_html_e( '⚠️ Куда вставлять шорткоды в Divi 5:', 'polyland-up' ); ?></strong>
					<?php esc_html_e( 'Шорткод кнопки [plu_read_more] вставляется ВНУТРЬ карточки с включенным Loop. А шорткод самой пагинации [plu_pagination] вставляется в ОТДЕЛЬНЫЙ ряд/модуль НИЖЕ ряда с циклом Loop (не включайте Loop на самом блоке пагинации!). Пагинация автоматически связывается с циклом выше и переключает его записи.', 'polyland-up' ); ?>
				</div>

				<div class="plu-pg-cheatsheet">
					<?php foreach ( $shortcodes as $sc ) : ?>
						<div class="plu-pg-sc-row">
							<button
								type="button"
								class="button button-small plu-pg-copy-btn"
								data-copy-value="<?php echo esc_attr( $sc['code'] ); ?>"
								title="<?php esc_attr_e( 'Копировать шорткод', 'polyland-up' ); ?>"
							>
								&#128203; <code><?php echo esc_html( $sc['code'] ); ?></code>
							</button>
							<span class="plu-pg-sc-desc"><?php echo esc_html( $sc['desc'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Блок 2: Живое превью (Live Preview) -->
			<div class="plu-pg-card">
				<h3 style="margin-top:0;"><?php esc_html_e( '👁 Живое превью (Live Preview)', 'polyland-up' ); ?></h3>
				<div
					class="plu-pg-live-preview"
					id="plu-pg-live-preview"
					style="--plu-pg-accent:<?php echo esc_attr( $accent ); ?>;--plu-pg-active-text:<?php echo esc_attr( $active_text ); ?>;--plu-pg-bg:<?php echo esc_attr( $bg_color ); ?>;--plu-pg-text:<?php echo esc_attr( $text_color ); ?>;--plu-pg-border:<?php echo esc_attr( $border_color ); ?>;--plu-pg-radius:<?php echo esc_attr( (string) $radius ); ?>px;"
				>
					<div class="plu-pagination-wrap plu-align-<?php echo esc_attr( $align ); ?>" id="plu-preview-pg-wrap">
						<div class="plu-pagination-status" id="plu-preview-pg-status" <?php echo $show_status ? '' : 'style="display:none;"'; ?>>
							Сторінка 6 з 66
						</div>
						<ul class="plu-pagination-list">
							<li class="plu-page-item plu-preview-arrow" <?php echo $show_arrows ? '' : 'style="display:none;"'; ?>>
								<a class="prev page-numbers" href="#preview" onclick="return false;">« Попередня</a>
							</li>
							<li class="plu-page-item"><a class="page-numbers" href="#preview" onclick="return false;">1</a></li>
							<li class="plu-page-item"><span class="page-numbers dots">&hellip;</span></li>
							<li class="plu-page-item"><a class="page-numbers" href="#preview" onclick="return false;">4</a></li>
							<li class="plu-page-item"><a class="page-numbers" href="#preview" onclick="return false;">5</a></li>
							<li class="plu-page-item"><span aria-current="page" class="page-numbers current">6</span></li>
							<li class="plu-page-item"><a class="page-numbers" href="#preview" onclick="return false;">7</a></li>
							<li class="plu-page-item"><a class="page-numbers" href="#preview" onclick="return false;">8</a></li>
							<li class="plu-page-item"><span class="page-numbers dots">&hellip;</span></li>
							<li class="plu-page-item"><a class="page-numbers" href="#preview" onclick="return false;">66</a></li>
							<li class="plu-page-item plu-preview-arrow" <?php echo $show_arrows ? '' : 'style="display:none;"'; ?>>
								<a class="next page-numbers" href="#preview" onclick="return false;">Наступна »</a>
							</li>
						</ul>
					</div>

					<div class="plu-preview-rm-box">
						<span class="description" style="margin-right:10px;"><?php esc_html_e( 'Кнопка [plu_read_more]:', 'polyland-up' ); ?></span>
						<a
							href="#preview"
							onclick="return false;"
							id="plu-preview-rm-btn"
							class="plu-read-more-btn plu-rm-style-<?php echo esc_attr( $rm_style ); ?>"
						>
							<span class="plu-rm-text">Читати далі</span>
							<span class="plu-rm-icon" aria-hidden="true">→</span>
						</a>
					</div>
				</div>
			</div>

			<!-- Блок 3: Настройки дизайна и параметров по умолчанию -->
			<div class="plu-pg-card">
				<h3 style="margin-top:0;"><?php esc_html_e( '🎨 Настройки дизайна и параметров по умолчанию', 'polyland-up' ); ?></h3>
				<form method="post" action="options.php">
					<?php settings_fields( Settings::OPTION_GROUP ); ?>
					<input type="hidden" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[_plu_tab]" value="pagination" />

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="plu_pg_accent_color"><?php esc_html_e( 'Основной акцентный цвет', 'polyland-up' ); ?></label></th>
								<td>
									<input type="color" id="plu_pg_accent_color" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[pg_accent_color]" value="<?php echo esc_attr( $accent ); ?>" class="plu-pg-live-input" data-css-var="--plu-pg-accent" />
									<p class="description"><?php esc_html_e( 'Активная страница, ховер кнопок пагинации и цвет [plu_read_more].', 'polyland-up' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="plu_pg_active_text_color"><?php esc_html_e( 'Цвет текста активной кнопки', 'polyland-up' ); ?></label></th>
								<td>
									<input type="color" id="plu_pg_active_text_color" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[pg_active_text_color]" value="<?php echo esc_attr( $active_text ); ?>" class="plu-pg-live-input" data-css-var="--plu-pg-active-text" />
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="plu_pg_bg_color"><?php esc_html_e( 'Фон обычных кнопок', 'polyland-up' ); ?></label></th>
								<td>
									<input type="color" id="plu_pg_bg_color" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[pg_bg_color]" value="<?php echo esc_attr( $bg_color ); ?>" class="plu-pg-live-input" data-css-var="--plu-pg-bg" />
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="plu_pg_text_color"><?php esc_html_e( 'Цвет текста обычных кнопок', 'polyland-up' ); ?></label></th>
								<td>
									<input type="color" id="plu_pg_text_color" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[pg_text_color]" value="<?php echo esc_attr( $text_color ); ?>" class="plu-pg-live-input" data-css-var="--plu-pg-text" />
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="plu_pg_border_color"><?php esc_html_e( 'Цвет рамки кнопок', 'polyland-up' ); ?></label></th>
								<td>
									<input type="color" id="plu_pg_border_color" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[pg_border_color]" value="<?php echo esc_attr( $border_color ); ?>" class="plu-pg-live-input" data-css-var="--plu-pg-border" />
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="plu_pg_border_radius"><?php esc_html_e( 'Скругление углов кнопок (border-radius, px)', 'polyland-up' ); ?></label></th>
								<td>
									<input type="number" min="0" max="50" id="plu_pg_border_radius" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[pg_border_radius]" value="<?php echo esc_attr( (string) $radius ); ?>" class="small-text plu-pg-live-input" data-css-var="--plu-pg-radius" data-css-unit="px" /> px
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="plu_pg_align"><?php esc_html_e( 'Выравнивание блока пагинации', 'polyland-up' ); ?></label></th>
								<td>
									<select id="plu_pg_align" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[pg_align]">
										<option value="left" <?php selected( 'left', $align ); ?>><?php esc_html_e( 'По левому краю (left)', 'polyland-up' ); ?></option>
										<option value="center" <?php selected( 'center', $align ); ?>><?php esc_html_e( 'По центру (center)', 'polyland-up' ); ?></option>
										<option value="right" <?php selected( 'right', $align ); ?>><?php esc_html_e( 'По правому краю (right)', 'polyland-up' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="plu_pg_mid_size"><?php esc_html_e( 'Глубина пагинации (mid_size)', 'polyland-up' ); ?></label></th>
								<td>
									<input type="number" min="1" max="10" id="plu_pg_mid_size" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[pg_mid_size]" value="<?php echo esc_attr( (string) $mid_size ); ?>" class="small-text" />
									<p class="description"><?php esc_html_e( 'Сколько номеров показывать слева и справа от текущей страницы.', 'polyland-up' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="plu_pg_end_size"><?php esc_html_e( 'Номеров по краям (end_size)', 'polyland-up' ); ?></label></th>
								<td>
									<input type="number" min="1" max="5" id="plu_pg_end_size" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[pg_end_size]" value="<?php echo esc_attr( (string) $end_size ); ?>" class="small-text" />
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Элементы пагинации', 'polyland-up' ); ?></th>
								<td>
									<fieldset>
										<label for="plu_pg_show_arrows" style="display:block; margin-bottom:8px;">
											<input type="checkbox" id="plu_pg_show_arrows" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[pg_show_arrows]" value="1" <?php checked( 1, $show_arrows ); ?> />
											<?php esc_html_e( 'Показывать кнопки «Попередня» / «Наступна»', 'polyland-up' ); ?>
										</label>
										<label for="plu_pg_show_status" style="display:block;">
											<input type="checkbox" id="plu_pg_show_status" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[pg_show_status]" value="1" <?php checked( 1, $show_status ); ?> />
											<?php esc_html_e( 'Показывать статус «Сторінка X з Y»', 'polyland-up' ); ?>
										</label>
									</fieldset>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="plu_rm_style"><?php esc_html_e( 'Стиль кнопки [plu_read_more]', 'polyland-up' ); ?></label></th>
								<td>
									<select id="plu_rm_style" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[rm_style]">
										<option value="solid" <?php selected( 'solid', $rm_style ); ?>><?php esc_html_e( 'Залитая акцентным цветом (Solid)', 'polyland-up' ); ?></option>
										<option value="outline" <?php selected( 'outline', $rm_style ); ?>><?php esc_html_e( 'Контурная с заливкой при наведении (Outline)', 'polyland-up' ); ?></option>
										<option value="link" <?php selected( 'link', $rm_style ); ?>><?php esc_html_e( 'Текстовая ссылка со стрелкой (Link)', 'polyland-up' ); ?></option>
									</select>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Сохранить настройки пагинации', 'polyland-up' ) ); ?>
				</form>
			</div>
		</div>
		<?php
	}
}
