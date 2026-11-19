<?php
declare(strict_types=1);

namespace PolylangUp\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Модуль «🔤 UI-Строки для шаблонов Divi 5 Theme Builder».
 *
 * Позволяет использовать один общий шаблон Divi 5 Theme Builder для всех языков Polylang
 * через шорткоды [plu_str], [plu_date] и виртуальные мета-ключи plu_str_{id}
 * без засорения wp_postmeta и с нулевыми дополнительными запросами к БД.
 */
final class StringsManager {

	public const OPTION_NAME        = 'plu_ui_strings';
	public const SEED_FOOTER_OPTION = 'plu_ui_footer_links_seeded';
	public const PLL_GROUP          = 'Polylang AI UI';
	public const META_PREFIX        = 'plu_str_';

	/**
	 * Статический кеш словаря UI-строк в RAM.
	 *
	 * @var array<string, array<string, string>>|null
	 */
	private static ?array $strings_cache = null;

	/**
	 * Инициализация хуков модуля (фронтенд + админка).
	 */
	public static function init(): void {
		// Регистрация строк в Polylang и гарантированная инициализация словаря
		add_action( 'init', [ self::class, 'register_polylang_strings' ], 20 );

		// Шорткоды для Divi 5 Theme Builder и Loop
		add_shortcode( 'plu_str', [ self::class, 'render_shortcode_str' ] );
		add_shortcode( 'plu_date', [ self::class, 'render_shortcode_date' ] );

		// Гарантированное раскрытие шорткодов и нераскрытых $variable(...plu_str_...)$ на архивах в Divi 5
		add_filter( 'et_pb_module_content', [ self::class, 'process_divi_shortcodes' ], 20 );
		add_filter( 'et_module_shortcode_output', [ self::class, 'process_divi_shortcodes' ], 20 );
		add_filter( 'render_block', [ self::class, 'process_divi_block_output' ], 12, 2 );

		// Виртуальные мета-ключи plu_str_{id} для Dynamic Content (бочонок) в Divi 5 (в т.ч. при $object_id = 0)
		add_filter( 'get_post_metadata', [ self::class, 'filter_virtual_post_metadata' ], 10, 4 );

		// Сброс статического кеша при обновлении опции
		add_action( 'update_option_' . self::OPTION_NAME, [ self::class, 'reset_cache' ] );

		// Админ-обработчики (сохранение и AI-перевод)
		if ( is_admin() ) {
			add_action( 'wp_ajax_plu_save_ui_strings', [ self::class, 'ajax_save_ui_strings' ] );
			add_action( 'wp_ajax_plu_translate_ui_strings', [ self::class, 'ajax_translate_ui_strings' ] );
		}
	}

	/**
	 * Сброс статического кеша в памяти.
	 */
	public static function reset_cache(): void {
		self::$strings_cache = null;
	}

	/**
	 * Стартовый предзаполненный словарь UI-строк для шаблонов.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function get_default_presets(): array {
		$base = [
			'read_more' => [
				'uk' => 'Читати далі',
				'en' => 'Read more',
				'ru' => 'Читать далее',
			],
			'home' => [
				'uk' => 'Головна',
				'en' => 'Home',
				'ru' => 'Главная',
			],
			'published_on' => [
				'uk' => 'Опубліковано:',
				'en' => 'Published on:',
				'ru' => 'Опубликовано:',
			],
			'updated_on' => [
				'uk' => 'Оновлено:',
				'en' => 'Updated on:',
				'ru' => 'Обновлено:',
			],
			'all_news' => [
				'uk' => 'Всі новини',
				'en' => 'All news',
				'ru' => 'Все новости',
			],
			'back_to_list' => [
				'uk' => 'Назад до списку',
				'en' => 'Back to list',
				'ru' => 'Назад к списку',
			],
			'search_placeholder' => [
				'uk' => 'Пошук...',
				'en' => 'Search...',
				'ru' => 'Поиск...',
			],
			'no_results' => [
				'uk' => 'За вашим запитом нічого не знайдено',
				'en' => 'No results found',
				'ru' => 'По вашему запросу ничего не найдено',
			],
			'share' => [
				'uk' => 'Поділитися:',
				'en' => 'Share:',
				'ru' => 'Поделиться:',
			],
			'categories' => [
				'uk' => 'Категорії:',
				'en' => 'Categories:',
				'ru' => 'Категории:',
			],
			'pagination_prev' => [
				'uk' => '« Попередня',
				'en' => '« Previous',
				'ru' => '« Предыдущая',
			],
			'pagination_next' => [
				'uk' => 'Наступна »',
				'en' => 'Next »',
				'ru' => 'Следующая »',
			],
			'pagination_status' => [
				'uk' => 'Сторінка %1$d з %2$d',
				'en' => 'Page %1$d of %2$d',
				'ru' => 'Страница %1$d из %2$d',
			],
			'privacy_text' => [
				'uk' => 'Політика конфіденційності',
				'en' => 'Privacy Policy',
				'ru' => 'Политика конфиденциальности',
			],
			'privacy_url' => [
				'uk' => '/privacy-policy/',
				'en' => '/en/privacy-policy/',
				'ru' => '/ru/privacy-policy/',
			],
			'service_text' => [
				'uk' => 'Умови використання',
				'en' => 'Terms of Service',
				'ru' => 'Условия использования',
			],
			'service_url' => [
				'uk' => '/terms-of-service/',
				'en' => '/en/terms-of-service/',
				'ru' => '/ru/terms-of-service/',
			],
		];

		// Адаптируем ключи под реальные слаги языков Polylang (например, ua -> uk)
		$lang_slugs = self::get_polylang_slugs();
		if ( empty( $lang_slugs ) ) {
			return $base;
		}

		$result = [];
		foreach ( $base as $key => $translations ) {
			$row = [];
			foreach ( $lang_slugs as $slug ) {
				if ( isset( $translations[ $slug ] ) ) {
					$row[ $slug ] = $translations[ $slug ];
				} elseif ( 'ua' === $slug && isset( $translations['uk'] ) ) {
					$row[ $slug ] = $translations['uk'];
				} else {
					$row[ $slug ] = '';
				}
			}
			// Сохраняем базовые ключи на случай, если язык добавят позже
			foreach ( $translations as $k => $v ) {
				if ( ! array_key_exists( $k, $row ) && ( 'uk' !== $k || ! in_array( 'ua', $lang_slugs, true ) ) ) {
					$row[ $k ] = $v;
				}
			}
			$result[ $key ] = $row;
		}

		return $result;
	}

	/**
	 * Получение всех UI-строк из опции plu_ui_strings со статическим кешированием в RAM.
	 * При первом запуске предзаполняет стартовый набор.
	 *
	 * @param bool $reset Принудительно перечитать из БД.
	 * @return array<string, array<string, string>>
	 */
	public static function get_strings( bool $reset = false ): array {
		if ( ! $reset && null !== self::$strings_cache ) {
			return self::$strings_cache;
		}

		$raw = get_option( self::OPTION_NAME, false );

		if ( false === $raw || ! is_array( $raw ) ) {
			$defaults = self::get_default_presets();
			// Запись в БД только в админке: на фронтенде (page cache, высокий RPS) пресеты отдаются из памяти без INSERT.
			if ( is_admin() ) {
				update_option( self::OPTION_NAME, $defaults, true );
				update_option( self::SEED_FOOTER_OPTION, '1', true );
			}
			self::$strings_cache = $defaults;

			return self::$strings_cache;
		}

		$clean = [];
		foreach ( $raw as $key => $langs ) {
			$clean_key = self::sanitize_string_id( (string) $key );
			if ( '' === $clean_key || ! is_array( $langs ) ) {
				continue;
			}
			$clean[ $clean_key ] = [];
			foreach ( $langs as $lang_slug => $val ) {
				$clean_lang = sanitize_key( (string) $lang_slug );
				if ( '' !== $clean_lang ) {
					$clean[ $clean_key ][ $clean_lang ] = (string) $val;
				}
			}
		}

		// Мягкое однократное неразрушающее слияние (non-destructive merge) новых предустановленных ключей подвала
		if ( ! get_option( self::SEED_FOOTER_OPTION, false ) ) {
			$defaults    = self::get_default_presets();
			$footer_keys = [ 'privacy_text', 'privacy_url', 'service_text', 'service_url' ];
			$updated     = false;

			foreach ( $footer_keys as $f_key ) {
				if ( ! array_key_exists( $f_key, $clean ) && isset( $defaults[ $f_key ] ) ) {
					$clean[ $f_key ] = $defaults[ $f_key ];
					$updated         = true;
				}
			}

			if ( is_admin() ) {
				if ( $updated ) {
					update_option( self::OPTION_NAME, $clean, true );
				}
				update_option( self::SEED_FOOTER_OPTION, '1', true );
			}
		}

		self::$strings_cache = $clean;
		return self::$strings_cache;
	}

	/**
	 * Получение слагов всех активных языков Polylang.
	 *
	 * @return array<int, string>
	 */
	public static function get_polylang_slugs(): array {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return [];
		}

		$slugs = pll_languages_list( [ 'fields' => 'slug' ] );
		if ( ! is_array( $slugs ) ) {
			return [];
		}

		return array_values( array_filter( array_map( 'sanitize_key', $slugs ) ) );
	}

	/**
	 * Получение полных объектов языков Polylang (динамический список колонок).
	 *
	 * @return array<int, object>
	 */
	public static function get_polylang_languages(): array {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return [];
		}

		$langs = pll_languages_list( [ 'fields' => '' ] );
		if ( ! is_array( $langs ) ) {
			return [];
		}

		return array_values( array_filter( $langs, static fn( mixed $item ): bool => is_object( $item ) && ! empty( $item->slug ) ) );
	}

	/**
	 * Определение текущего языка страницы (pll_current_language с фоллбэком по URL и default).
	 *
	 * @return string
	 */
	public static function get_current_lang_slug(): string {
		if ( function_exists( 'pll_current_language' ) ) {
			$current = pll_current_language( 'slug' );
			if ( is_string( $current ) && '' !== $current ) {
				return sanitize_key( $current );
			}
		}

		// Фоллбэк: определение по первому сегменту URL (для нестандартных контекстов / Theme Builder)
		$valid_slugs = self::get_polylang_slugs();
		if ( ! empty( $valid_slugs ) && ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$request_path = (string) wp_parse_url( wp_unslash( (string) $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
			$home_path    = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			$relative     = trim( substr( $request_path, strlen( rtrim( $home_path, '/' ) ) ), '/' );
			$segments     = '' !== $relative ? explode( '/', $relative ) : [];

			if ( ! empty( $segments[0] ) ) {
				$candidate = sanitize_key( $segments[0] );
				if ( in_array( $candidate, $valid_slugs, true ) ) {
					return $candidate;
				}
			}
		}

		if ( function_exists( 'pll_default_language' ) ) {
			$default = pll_default_language( 'slug' );
			if ( is_string( $default ) && '' !== $default ) {
				return sanitize_key( $default );
			}
		}

		return ! empty( $valid_slugs ) ? $valid_slugs[0] : 'uk';
	}

	/**
	 * Получение перевода UI-строки по ключу из статического кеша (0 запросов к БД).
	 *
	 * @param string $id      Идентификатор строки (например, 'read_more').
	 * @param string $default Значение по умолчанию, если перевод не найден.
	 * @param string $lang    Код языка (если пустой — определяется автоматически).
	 * @return string
	 */
	public static function get_string( string $id, string $default = '', string $lang = '' ): string {
		$clean_id = self::sanitize_string_id( $id );
		if ( '' === $clean_id ) {
			return $default;
		}

		$strings = self::get_strings();
		if ( ! isset( $strings[ $clean_id ] ) ) {
			return $default;
		}

		$row  = $strings[ $clean_id ];
		$lang = '' !== $lang ? sanitize_key( $lang ) : self::get_current_lang_slug();

		// 1. Прямое совпадение для текущего языка
		if ( isset( $row[ $lang ] ) && '' !== trim( $row[ $lang ] ) ) {
			return $row[ $lang ];
		}

		// 2. Синоним uk <-> ua
		if ( 'ua' === $lang && isset( $row['uk'] ) && '' !== trim( $row['uk'] ) ) {
			return $row['uk'];
		}
		if ( 'uk' === $lang && isset( $row['ua'] ) && '' !== trim( $row['ua'] ) ) {
			return $row['ua'];
		}

		// 3. Фоллбэк на язык по умолчанию Polylang
		$def_lang = function_exists( 'pll_default_language' ) ? sanitize_key( (string) pll_default_language( 'slug' ) ) : '';
		if ( '' !== $def_lang && isset( $row[ $def_lang ] ) && '' !== trim( $row[ $def_lang ] ) ) {
			return $row[ $def_lang ];
		}

		// 4. Атрибут default, если передан
		if ( '' !== $default ) {
			return $default;
		}

		// 5. Первое непустое значение из других языков
		foreach ( $row as $val ) {
			if ( '' !== trim( (string) $val ) ) {
				return (string) $val;
			}
		}

		return '';
	}

	/**
	 * Регистрация всех UI-строк в нативном реестре Polylang (Мови -> Переклади).
	 */
	public static function register_polylang_strings(): void {
		// Реестр строк Polylang нужен только экрану «Переклады» в админке.
		if ( ! is_admin() || ! function_exists( 'pll_register_string' ) ) {
			return;
		}

		$strings  = self::get_strings();
		$def_lang = function_exists( 'pll_default_language' ) ? sanitize_key( (string) pll_default_language( 'slug' ) ) : 'uk';

		foreach ( $strings as $key => $row ) {
			$default_val = '';
			if ( isset( $row[ $def_lang ] ) && '' !== trim( $row[ $def_lang ] ) ) {
				$default_val = $row[ $def_lang ];
			} else {
				foreach ( $row as $val ) {
					if ( '' !== trim( (string) $val ) ) {
						$default_val = (string) $val;
						break;
					}
				}
			}

			if ( '' !== $default_val ) {
				pll_register_string( $key, $default_val, self::PLL_GROUP );
			}
		}
	}

	/**
	 * Шорткод [plu_str id="read_more" before="" after="" default=""].
	 *
	 * @param mixed $atts Атрибуты шорткода.
	 * @return string
	 */
	public static function render_shortcode_str( mixed $atts = [] ): string {
		$atts = shortcode_atts(
			[
				'id'      => '',
				'before'  => '',
				'after'   => '',
				'default' => '',
			],
			is_array( $atts ) ? $atts : [],
			'plu_str'
		);

		$id = self::sanitize_string_id( (string) $atts['id'] );
		if ( '' === $id ) {
			return '';
		}

		$translated = self::get_string( $id, (string) $atts['default'] );
		if ( '' === $translated ) {
			return '';
		}

		return esc_html( (string) $atts['before'] . $translated . (string) $atts['after'] );
	}

	/**
	 * Бонусный шорткод мультиязычной даты [plu_date format="l, j F Y"].
	 *
	 * @param mixed $atts Атрибуты шорткода.
	 * @return string
	 */
	public static function render_shortcode_date( mixed $atts = [] ): string {
		$atts = shortcode_atts(
			[
				'format' => 'l, j F Y',
			],
			is_array( $atts ) ? $atts : [],
			'plu_date'
		);

		$format = (string) $atts['format'];
		if ( '' === trim( $format ) ) {
			$format = 'l, j F Y';
		}

		return esc_html( (string) wp_date( $format ) );
	}

	/**
	 * Единый фильтр раскрытия шорткодов плагина ([plu_*], [scat_pagination]) в модулях Divi.
	 *
	 * @param mixed $output HTML-вывод модуля.
	 * @return mixed
	 */
	public static function process_divi_shortcodes( mixed $output ): mixed {
		if ( ! is_string( $output ) || '' === $output ) {
			return $output;
		}

		$output = self::expand_encoded_plu_str_in_html( $output );

		if ( ! str_contains( $output, '[plu_' ) && ! str_contains( $output, '[scat_pagination' ) ) {
			return $output;
		}

		return do_shortcode( $output );
	}

	/**
	 * Обработка вывода блоков Divi 5 (фильтр render_block, приоритет 12):
	 * 1) Раскрывает нераскрытые динамические плейсхолдеры $variable(...plu_str_...)$ (включая URL-encoded варианты в href) на страницах Архивов / Категорий / Поиска / Подвала.
	 * 2) Раскрывает шорткоды [plu_str ...] (включая экранированные квадратные скобки и кавычки в атрибутах href модулей Link / Button) и [plu_date ...].
	 *
	 * @param mixed $block_content HTML-вывод блока.
	 * @param mixed $block         Массив данных блока Gutenberg / Divi 5.
	 * @return mixed
	 */
	public static function process_divi_block_output( mixed $block_content, mixed $block = [] ): mixed {
		if ( ! is_string( $block_content ) || '' === $block_content ) {
			return $block_content;
		}

		if (
			( str_contains( $block_content, '$variable(' ) || str_contains( $block_content, '%24variable' ) )
			&& str_contains( $block_content, self::META_PREFIX )
		) {
			$replaced = preg_replace_callback(
				'/(?:https?:\/\/)?(?:\$variable\(|%24variable(?:%28|\())(?:[^$%]|\$(?!\))|%(?!29%24))*?plu_str_([a-z0-9_]+)(?:[^$%]|\$(?!\))|%(?!29%24))*?(?:\)\$|%29%24|\)%24|%29\$)/i',
				static function ( array $matches ): string {
					$str_id = self::sanitize_string_id( (string) $matches[1] );
					if ( '' === $str_id ) {
						return $matches[0];
					}
					$translated = self::get_string( $str_id );
					return '' !== $translated ? esc_attr( $translated ) : $matches[0];
				},
				$block_content
			);

			if ( is_string( $replaced ) ) {
				$block_content = $replaced;
			}
		}

		$block_content = self::expand_encoded_plu_str_in_html( $block_content );

		if ( str_contains( $block_content, '[plu_' ) || str_contains( $block_content, '[scat_pagination' ) ) {
			$block_content = do_shortcode( $block_content );
		}

		return $block_content;
	}

	/**
	 * Раскрывает шорткоды [plu_str id="..."], в которых Divi 5 / esc_url / esc_attr экранировал
	 * квадратные скобки (%5B/%5D, &#91;/&#93;), кавычки (&quot;, &#039;, %22) или добавил префикс http(s):// внутри href.
	 *
	 * @param string $html HTML-фрагмент блока или модуля.
	 * @return string
	 */
	private static function expand_encoded_plu_str_in_html( string $html ): string {
		if ( ! str_contains( $html, 'plu_str' ) ) {
			return $html;
		}

		// 1. Прямое раскрытие [plu_str id="..."] в любых вариациях экранирования (в т.ч. внутри href="http://[plu_str...]" или href="%5Bplu_str...%5D")
		$replaced = preg_replace_callback(
			'/(?:https?:\/\/)?(?:\[|%5B|&#0*91;|&#x0*5B;)plu_str(?:\s|%20|\+)+id\s*=\s*(?:["\']|&quot;|&#0*39;|&#0*34;|%22|%27)?([a-z0-9_-]+)(?:["\']|&quot;|&#0*39;|&#0*34;|%22|%27)?[^\]%]*?(?:\]|%5D|&#0*93;|&#x0*5D;)/i',
			static function ( array $matches ): string {
				$str_id = self::sanitize_string_id( (string) $matches[1] );
				if ( '' === $str_id ) {
					return $matches[0];
				}
				$translated = self::get_string( $str_id );
				return '' !== $translated ? esc_attr( $translated ) : $matches[0];
			},
			$html
		);

		return is_string( $replaced ) ? $replaced : $html;
	}

	/**
	 * Перехват get_post_metadata для виртуальных ключей plu_str_{id}.
	 * На фронтенде отдает перевод мгновенно из статического кеша без запросов в wp_postmeta,
	 * независимо от переданного $object_id (включая 0/null на страницах архивов).
	 *
	 * @param mixed $value     Текущее значение фильтра (обычно null).
	 * @param mixed $object_id ID записи (может быть 0 на архивах).
	 * @param mixed $meta_key  Имя мета-ключа.
	 * @param mixed $single    Одиночное значение или массив.
	 * @return mixed
	 */
	public static function filter_virtual_post_metadata( mixed $value, mixed $object_id, mixed $meta_key, mixed $single ): mixed {
		if ( ! is_string( $meta_key ) || ! str_starts_with( $meta_key, self::META_PREFIX ) ) {
			return $value;
		}

		$str_id = substr( $meta_key, strlen( self::META_PREFIX ) );
		if ( '' === $str_id ) {
			return $value;
		}

		$translated = self::get_string( $str_id );

		return $single ? $translated : [ $translated ];
	}

	/**
	 * Санитизация ID строки (строго латиница, цифры, underscore в snake_case).
	 *
	 * @param string $raw_id Исходный ID.
	 * @return string
	 */
	public static function sanitize_string_id( string $raw_id ): string {
		$id = strtolower( trim( $raw_id ) );
		$id = str_replace( '-', '_', $id );
		$id = (string) preg_replace( '/[^a-z0-9_]/', '', $id );
		$id = (string) preg_replace( '/_+/', '_', $id );
		return trim( $id, '_' );
	}

	/**
	 * Отрисовка интерфейса вкладки «🔤 Строки шаблонов (UI)».
	 */
	public static function render_tab_content(): void {
		$languages = self::get_polylang_languages();
		$strings   = self::get_strings();

		// Если Polylang не активен, формируем базовые колонки по умолчанию, чтобы интерфейс не падал
		$lang_cols = [];
		if ( ! empty( $languages ) ) {
			foreach ( $languages as $lang ) {
				$slug        = sanitize_key( (string) $lang->slug );
				$name        = (string) ( $lang->name ?? strtoupper( $slug ) );
				$flag        = (string) ( $lang->flag ?? '' );
				$lang_cols[] = [
					'slug' => $slug,
					'name' => $name,
					'flag' => $flag,
				];
			}
		} else {
			$lang_cols = [
				[ 'slug' => 'uk', 'name' => 'Українська', 'flag' => '' ],
				[ 'slug' => 'en', 'name' => 'English', 'flag' => '' ],
				[ 'slug' => 'ru', 'name' => 'Русский', 'flag' => '' ],
			];
		}

		$default_translator = (string) plu_get_option( 'default_translator', 'gtx' );
		$engine_label       = 'gemini' === $default_translator ? 'Google Gemini AI' : 'Google Translate (Free GTX)';
		?>
		<div class="plu-strings-manager-wrap" id="plu-strings-manager-box">
			<!-- Блок подробной инструкции-памятки -->
			<div class="plu-strings-guide-card">
				<h2 style="margin-top:0;">
					<?php esc_html_e( '🔤 UI-Строки для шаблонов Divi 5 Theme Builder', 'polyland-up' ); ?>
				</h2>
				<p class="description" style="font-size:13px; margin-bottom:14px;">
					<?php esc_html_e( 'Используйте один общий шаблон в Divi 5 Theme Builder (архивы новостей, карточки цикла Loop, шапка, подвал, хлебные крошки, 404) сразу для всех языков Polylang — без дублирования шаблонов и без засорения wp_postmeta.', 'polyland-up' ); ?>
				</p>

				<div class="plu-guide-grid">
					<div class="plu-guide-item">
						<div class="plu-guide-title">
							<?php esc_html_e( 'Вариант А: Шорткод в любом тексте, заголовке или кнопке Divi 5', 'polyland-up' ); ?>
						</div>
						<p>
							<?php esc_html_e( 'Вставьте шорткод в текстовый модуль, кнопку или заголовок:', 'polyland-up' ); ?>
						</p>
						<code>[plu_str id="read_more"]</code>
						<div class="plu-guide-sub">
							<?php esc_html_e( 'С необязательным префиксом/суффиксом:', 'polyland-up' ); ?>
							<code>[plu_str id="updated_on" after=" "]</code>
						</div>
					</div>

					<div class="plu-guide-item">
						<div class="plu-guide-title">
							<?php esc_html_e( 'Вариант Б: Иконка бочонка Dynamic Content 🛢 → Custom Field', 'polyland-up' ); ?>
						</div>
						<p>
							<?php esc_html_e( 'В шаблонах Theme Builder и циклах Loop выберите Dynamic Content → Custom Field и укажите ключ из списка:', 'polyland-up' ); ?>
						</p>
						<code>plu_str_read_more</code>
						<div class="plu-guide-sub">
							<?php esc_html_e( 'Значение подставляется виртуально из RAM (0 запросов к БД).', 'polyland-up' ); ?>
						</div>
					</div>

					<div class="plu-guide-item">
						<div class="plu-guide-title">
							<?php esc_html_e( 'Вариант В: Мультиязычная дата в шапке или мета-данных', 'polyland-up' ); ?>
						</div>
						<p>
							<?php esc_html_e( 'Выводит текущую дату согласно активной локали Polylang:', 'polyland-up' ); ?>
						</p>
						<code>[plu_date format="l, j F Y"]</code>
						<div class="plu-guide-sub">
							<?php esc_html_e( 'Пример: «Неділя, 15 листопада 2026» / «Sunday, November 15, 2026».', 'polyland-up' ); ?>
						</div>
					</div>
				</div>
			</div>

			<!-- Панель управления таблицей -->
			<div class="plu-strings-table-card">
				<div class="plu-strings-toolbar">
					<div>
						<strong><?php esc_html_e( 'Словарь интерфейсных строк', 'polyland-up' ); ?></strong>
						<span class="description" style="margin-left:8px;">
							<?php
							printf(
								esc_html__( 'Текущий движок AI-перевода: %s (настраивается во вкладке «Основные»).', 'polyland-up' ),
								'<strong>' . esc_html( $engine_label ) . '</strong>'
							);
							?>
						</span>
					</div>
				</div>

				<div class="plu-strings-table-scroll">
					<table
						class="widefat striped plu-strings-table"
						id="plu-strings-table"
						data-langs="<?php echo esc_attr( (string) wp_json_encode( $lang_cols ) ); ?>"
					>
						<thead>
							<tr>
								<th class="plu-col-id"><?php esc_html_e( 'ID ключа (snake_case)', 'polyland-up' ); ?></th>
								<th class="plu-col-copy"><?php esc_html_e( 'Быстрое копирование для Divi 5', 'polyland-up' ); ?></th>
								<?php foreach ( $lang_cols as $col ) : ?>
									<th class="plu-col-lang">
										<?php if ( '' !== $col['flag'] ) : ?>
											<span class="plu-th-flag"><?php echo wp_kses_post( $col['flag'] ); ?></span>
										<?php endif; ?>
										<?php echo esc_html( $col['name'] ); ?>
										<span class="plu-th-slug">(<?php echo esc_html( $col['slug'] ); ?>)</span>
									</th>
								<?php endforeach; ?>
								<th class="plu-col-actions"><?php esc_html_e( 'Удалить', 'polyland-up' ); ?></th>
							</tr>
						</thead>
						<tbody id="plu-strings-tbody">
							<?php foreach ( $strings as $str_id => $row ) : ?>
								<tr class="plu-string-row">
									<td>
										<input
											type="text"
											class="regular-text plu-str-id-input"
											value="<?php echo esc_attr( $str_id ); ?>"
											placeholder="read_more"
										/>
									</td>
									<td>
										<div class="plu-copy-badges">
											<button
												type="button"
												class="button button-small plu-copy-badge"
												data-copy-type="shortcode"
												data-copy-value="<?php echo esc_attr( '[plu_str id="' . $str_id . '"]' ); ?>"
												title="<?php esc_attr_e( 'Копировать шорткод', 'polyland-up' ); ?>"
											>
												&#128203; <code>[plu_str id="<?php echo esc_html( $str_id ); ?>"]</code>
											</button>
											<button
												type="button"
												class="button button-small plu-copy-badge"
												data-copy-type="meta"
												data-copy-value="<?php echo esc_attr( self::META_PREFIX . $str_id ); ?>"
												title="<?php esc_attr_e( 'Копировать мета-ключ для Dynamic Content', 'polyland-up' ); ?>"
											>
												&#128203; <code><?php echo esc_html( self::META_PREFIX . $str_id ); ?></code>
											</button>
										</div>
									</td>
									<?php foreach ( $lang_cols as $col ) : ?>
										<?php
										$slug = $col['slug'];
										$val  = $row[ $slug ] ?? '';
										if ( '' === $val && 'ua' === $slug && isset( $row['uk'] ) ) {
											$val = $row['uk'];
										}
										?>
										<td>
											<input
												type="text"
												class="widefat plu-str-lang-input"
												data-lang="<?php echo esc_attr( $slug ); ?>"
												value="<?php echo esc_attr( (string) $val ); ?>"
												placeholder="<?php echo esc_attr( strtoupper( $slug ) ); ?>"
											/>
										</td>
									<?php endforeach; ?>
									<td class="plu-col-actions">
										<button
											type="button"
											class="button button-small plu-remove-string-row"
											title="<?php esc_attr_e( 'Удалить строку', 'polyland-up' ); ?>"
										>
											&#10005;
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div class="plu-strings-actions">
					<button type="button" id="plu-add-string-row" class="button button-secondary">
						&#10133; <?php esc_html_e( 'Добавить новую строку', 'polyland-up' ); ?>
					</button>

					<button type="button" id="plu-translate-empty-strings" class="button button-secondary">
						&#129302; <?php esc_html_e( 'Авто-перевести пустые ячейки через AI / GTX', 'polyland-up' ); ?>
					</button>

					<button type="button" id="plu-save-ui-strings" class="button button-primary">
						&#128190; <?php esc_html_e( 'Сохранить все строки', 'polyland-up' ); ?>
					</button>

					<span class="spinner" id="plu-strings-spinner" style="float:none; margin:0;"></span>
				</div>

				<div id="plu-strings-status" class="plu-strings-status" style="display:none;"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX-обработчик сохранения словаря UI-строк и синхронизации эталонных мета-ключей Divi 5.
	 */
	public static function ajax_save_ui_strings(): void {
		check_ajax_referer( 'plu_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [
				'message' => __( 'У вас недостаточно прав для выполнения этого действия.', 'polyland-up' ),
			] );
		}

		$raw_json = isset( $_POST['rows'] ) ? wp_unslash( (string) $_POST['rows'] ) : '';
		$rows     = json_decode( $raw_json, true );

		if ( ! is_array( $rows ) ) {
			wp_send_json_error( [
				'message' => __( 'Некорректный формат данных таблицы строк.', 'polyland-up' ),
			] );
		}

		$clean_strings = [];
		foreach ( $rows as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$str_id = self::sanitize_string_id( (string) ( $item['id'] ?? '' ) );
			if ( '' === $str_id ) {
				continue;
			}

			$langs = is_array( $item['langs'] ?? null ) ? $item['langs'] : [];
			$row   = [];
			foreach ( $langs as $lang_slug => $val ) {
				$clean_lang = sanitize_key( (string) $lang_slug );
				if ( '' !== $clean_lang ) {
					$row[ $clean_lang ] = sanitize_text_field( (string) $val );
				}
			}

			$clean_strings[ $str_id ] = $row;
		}

		update_option( self::OPTION_NAME, $clean_strings, true );
		update_option( self::SEED_FOOTER_OPTION, '1', true );
		self::$strings_cache = $clean_strings;

		// Обновляем регистрацию строк в Polylang
		self::register_polylang_strings();

		wp_send_json_success( [
			'count'   => count( $clean_strings ),
			'message' => sprintf(
				__( 'Сохранено UI-строк: %d. Словарь обновлен и синхронизирован с Polylang.', 'polyland-up' ),
				count( $clean_strings )
			),
		] );
	}

	/**
	 * AJAX-обработчик пакетного авто-перевода пустых ячеек таблицы UI-строк через AI / GTX.
	 */
	public static function ajax_translate_ui_strings(): void {
		check_ajax_referer( 'plu_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [
				'message' => __( 'У вас недостаточно прав для выполнения этого действия.', 'polyland-up' ),
			] );
		}

		if ( ! class_exists( TranslatorAPI::class ) ) {
			wp_send_json_error( [
				'message' => __( 'Модуль TranslatorAPI недоступен.', 'polyland-up' ),
			] );
		}

		$raw_json = isset( $_POST['rows'] ) ? wp_unslash( (string) $_POST['rows'] ) : '';
		$rows     = json_decode( $raw_json, true );

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			wp_send_json_error( [
				'message' => __( 'Нет строк для перевода.', 'polyland-up' ),
			] );
		}

		$def_lang = function_exists( 'pll_default_language' ) ? sanitize_key( (string) pll_default_language( 'slug' ) ) : '';

		// Группируем недостающие переводы по парам (source_lang -> target_lang) для пакетного перевода
		$batches = [];

		foreach ( $rows as $row_idx => $item ) {
			if ( ! is_array( $item ) || ! is_array( $item['langs'] ?? null ) ) {
				continue;
			}

			$langs       = $item['langs'];
			$source_lang = '';
			$source_text = '';

			// Приоритет выбора исходного языка: дефолтный язык Polylang, если он заполнен, иначе первый непустой
			if ( '' !== $def_lang && isset( $langs[ $def_lang ] ) && '' !== trim( (string) $langs[ $def_lang ] ) ) {
				$source_lang = $def_lang;
				$source_text = trim( (string) $langs[ $def_lang ] );
			} else {
				foreach ( $langs as $lang_slug => $val ) {
					$trimmed = trim( (string) $val );
					if ( '' !== $trimmed ) {
						$source_lang = sanitize_key( (string) $lang_slug );
						$source_text = $trimmed;
						break;
					}
				}
			}

			if ( '' === $source_lang || '' === $source_text ) {
				continue;
			}

			foreach ( $langs as $target_slug => $val ) {
				$clean_target = sanitize_key( (string) $target_slug );
				if ( '' === $clean_target || $clean_target === $source_lang ) {
					continue;
				}
				if ( '' === trim( (string) $val ) ) {
					$batches[ $source_lang ][ $clean_target ][ 'r_' . $row_idx ] = $source_text;
				}
			}
		}

		if ( empty( $batches ) ) {
			wp_send_json_success( [
				'translations'     => [],
				'translated_count' => 0,
				'message'          => __( 'Все ячейки уже заполнены или нет исходного текста для перевода.', 'polyland-up' ),
			] );
		}

		$translations     = [];
		$translated_count = 0;
		$provider_label   = '';
		$errors           = [];

		foreach ( $batches as $source_lang => $targets ) {
			foreach ( $targets as $target_lang => $dictionary ) {
				$result = TranslatorAPI::translate( $dictionary, (string) $source_lang, (string) $target_lang );
				$stats  = TranslatorAPI::get_last_stats();
				if ( '' === $provider_label && ! empty( $stats['provider_label'] ) ) {
					$provider_label = (string) $stats['provider_label'];
				}

				if ( is_wp_error( $result ) ) {
					$errors[] = strtoupper( (string) $source_lang ) . '→' . strtoupper( (string) $target_lang ) . ': ' . $result->get_error_message();
					continue;
				}

				foreach ( $result as $key => $translated_text ) {
					$row_idx = substr( (string) $key, 2 );
					$translations[ $row_idx ][ $target_lang ] = sanitize_text_field( (string) $translated_text );
					++$translated_count;
				}
			}
		}

		if ( 0 === $translated_count && ! empty( $errors ) ) {
			wp_send_json_error( [
				'message' => implode( '; ', $errors ),
			] );
		}

		wp_send_json_success( [
			'translations'     => $translations,
			'translated_count' => $translated_count,
			'provider_label'   => $provider_label,
			'message'          => sprintf(
				__( 'Переведено пустых ячеек: %1$d (движок: %2$s). Не забудьте нажать «Сохранить все строки».', 'polyland-up' ),
				$translated_count,
				'' !== $provider_label ? $provider_label : 'AI/GTX'
			),
		] );
	}
}
