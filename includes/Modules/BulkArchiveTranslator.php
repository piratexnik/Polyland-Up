<?php
declare(strict_types=1);

namespace PolylangUp\Modules;

use PolylangUp\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Модуль пакетной ревизии, устранения пересортицы языков и дозированного перевода архива записей.
 */
final class BulkArchiveTranslator {

	/**
	 * Инициализация AJAX-обработчиков модуля.
	 */
	public static function init(): void {
		$is_admin = is_admin();
		$is_cli   = defined( 'WP_CLI' ) && WP_CLI;

		if ( ! $is_admin && ! $is_cli ) {
			return;
		}

		add_action( 'wp_ajax_plu_bulk_scan_category', [ self::class, 'ajax_scan_category' ] );
		add_action( 'wp_ajax_plu_bulk_fix_category_terms', [ self::class, 'ajax_fix_category_terms' ] );
		add_action( 'wp_ajax_plu_bulk_process_post', [ self::class, 'ajax_process_post' ] );
	}

	/**
	 * Быстрый лингвистический PHP-детектор реального языка текста (0 токенов API).
	 *
	 * @param string $title   Заголовок записи.
	 * @param string $content Контент записи (HTML / Divi 5 / шорткоды).
	 * @return string 'uk', 'ru', 'en', 'cyrillic_neutral', 'universal' или 'empty'.
	 */
	public static function detect_real_text_language( string $title, string $content ): string {
		$raw = $title . ' ' . $content;

		// Если контент содержит Divi 5 JSON или экранированный Unicode (\u0430), декодируем его для точного анализа букв
		if ( str_contains( $raw, '\\u0' ) || str_contains( $raw, '\\u04' ) ) {
			$raw = (string) preg_replace_callback(
				'/\\\\u([0-9a-fA-F]{4})/',
				static fn( array $m ): string => html_entity_decode( '&#x' . $m[1] . ';', ENT_NOQUOTES, 'UTF-8' ),
				$raw
			);
		}

		// Удаляем HTML-комментарии блоков Divi/Gutenberg, шорткоды, HTML-теги и URL
		$clean = (string) preg_replace( '/<!--[\s\S]*?-->/', ' ', $raw );
		$clean = (string) preg_replace( '/\[\/?[a-zA-Z0-9_:-]+[^\]]*\]/', ' ', $clean );
		$clean = wp_strip_all_tags( $clean );
		$clean = html_entity_decode( $clean, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$clean = (string) preg_replace( '#https?://\S+#i', ' ', $clean );
		$clean = (string) preg_replace( '/\s+/u', ' ', trim( $clean ) );

		if ( '' === $clean ) {
			return 'empty';
		}

		// Предварительно вырезаем защищённые бренды и названия выставок (elcomUkraine, TM SCaT, SCaT и т.д.)
		$clean_no_brands = $clean;
		$protected_terms = \PolylangUp\Core\Settings::get_protected_terms();
		if ( ! empty( $protected_terms ) ) {
			$quoted_terms    = array_map( static fn( string $t ): string => preg_quote( $t, '/' ), $protected_terms );
			$terms_pattern   = '/(?<![\p{L}\p{N}])(' . implode( '|', $quoted_terms ) . ')(?![\p{L}\p{N}])/iu';
			$clean_no_brands = (string) preg_replace( $terms_pattern, ' ', $clean_no_brands );
			$clean_no_brands = (string) preg_replace( '/\s+/u', ' ', trim( $clean_no_brands ) );
		}

		$letters_only = (string) preg_replace( '/[^\p{L}]+/u', '', $clean_no_brands );

		// Если после вырезания брендов букв не осталось (например, «elcomUkraine 2014» + галерея),
		// но сам заголовок/очищенный текст не пустой — проверяем подписи внутри .scat-migrated-gallery
		if ( '' === $letters_only ) {
			$has_any_alnum = (bool) preg_match( '/[\p{L}\p{N}]/u', $clean );
			if ( ! $has_any_alnum ) {
				return 'empty';
			}

			if ( false !== stripos( $content, 'scat-migrated-gallery' ) ) {
				$gal_pos = stripos( $content, 'scat-migrated-gallery' );
				$gal_sub = false !== $gal_pos ? substr( $content, $gal_pos, 4000 ) : '';
				if ( '' !== $gal_sub && preg_match( '/\b(?:alt|title)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/iu', $gal_sub, $gm ) ) {
					$first_attr = html_entity_decode( ( '' !== $gm[1] ? $gm[1] : ( $gm[2] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					if ( preg_match( '/(?<![\p{L}])фото(?![\p{L}])|\p{Cyrillic}/iu', $first_attr ) ) {
						return 'cyrillic_neutral';
					}
					if ( preg_match( '/\bphoto\b/iu', $first_attr ) ) {
						return 'en';
					}
				}
			}

			return 'universal';
		}

		$cyr_count   = (int) preg_match_all( '/\p{Cyrillic}/u', $clean_no_brands );
		$latin_count = (int) preg_match_all( '/[a-zA-Z]/', $clean_no_brands );
		$total_alpha = $latin_count + $cyr_count;

		// Если латинских букв явно большинство — единичные кириллические артикулы/аббревиатуры (ДСТУ, УкрСЕПРО, СММ-18) не мешают распознать EN
		if ( $latin_count >= 15 && $total_alpha > 0 && ( $latin_count / $total_alpha ) >= 0.70 ) {
			return 'en';
		}

		$clean_title = (string) preg_replace( '/\s+/u', ' ', trim( wp_strip_all_tags( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
		if ( '' !== $clean_title ) {
			$t_lat   = (int) preg_match_all( '/[a-zA-Z]/', $clean_title );
			$t_cyr   = (int) preg_match_all( '/\p{Cyrillic}/u', $clean_title );
			$t_total = $t_lat + $t_cyr;
			if ( $t_lat >= 6 && $t_total > 0 && ( $t_lat / $t_total ) > 0.60 && ( 0 === $total_alpha || ( $latin_count / $total_alpha ) >= 0.50 ) ) {
				return 'en';
			}
		}

		if ( $cyr_count > 0 ) {
			// Подсчет уникальных украинских и русских маркеров
			$uk_unique_chars = (int) preg_match_all( '/[іїєґІЇЄҐ]/u', $clean_no_brands );
			$ru_unique_chars = (int) preg_match_all( '/[ыэъёЫЭЪЁ]/u', $clean_no_brands );

			$uk_words = (int) preg_match_all( '/(?<![\p{L}])(ми|на\s+виставці|виставці|у\s+місті|місті|підсумки|новинки|семінар|виставка|та|що|для|від|про|року|рік|або|якщо|буде|вони|його|неї|між|під|після|зараз|також|лише|більше|шановні|повідомляємо|працює|відкриття|наших|клієнтів)(?![\p{L}])/iu', $clean_no_brands );
			$ru_words = (int) preg_match_all( '/(?<![\p{L}])(мы|на\s+выставке|выставке|выставка|итоги|городе|семинар|или|что|был|была|были|году|год|также|только|если|будет|они|его|между|после|сейчас|более|котор[а-яё]*|этом|этого|наша|компания|лучшие|направления|поздравляем|приглашаем|сети|бизнес|строительство|реконструкция|производитель|рынка)(?![\p{L}])/iu', $clean_no_brands );
			$ru_conj  = (int) preg_match_all( '/(?<=^|[\s«"(])[ис](?=[\s»"),.]|$)/ui', $clean_no_brands );

			$uk_score = ( $uk_unique_chars * 4 ) + ( $uk_words * 3 );
			$ru_score = ( $ru_unique_chars * 4 ) + ( ( $ru_words + $ru_conj ) * 3 );

			if ( $uk_score > $ru_score ) {
				return 'uk';
			}
			if ( $ru_score > $uk_score ) {
				return 'ru';
			}

			// Ни специфических русских (ы, э, ъ, ё), ни украинских (і, ї, є, ґ) маркеров нет
			return 'cyrillic_neutral';
		}

		// Кириллицы нет, анализируем латиницу (бренды уже вырезаны)
		if ( $latin_count >= 2 ) {
			return 'en';
		}

		return 'empty';
	}

	/**
	 * Проверяет, совместим ли определённый детектором язык текста с паспортным языком поста в Polylang.
	 *
	 * @param string $assigned_lang Паспортный код языка Polylang ('uk', 'ru', 'en' и т.д.).
	 * @param string $detected_lang Результат detect_real_text_language().
	 * @return bool
	 */
	public static function is_lang_compatible_with_detected( string $assigned_lang, string $detected_lang ): bool {
		if ( 'universal' === $detected_lang ) {
			return true;
		}

		if ( 'cyrillic_neutral' === $detected_lang ) {
			return in_array( strtolower( substr( $assigned_lang, 0, 2 ) ), [ 'uk', 'ru', 'be', 'bg', 'sr', 'mk' ], true );
		}

		return $assigned_lang === $detected_lang;
	}

	/**
	 * Получить список активных языков Polylang.
	 *
	 * @return array<int, array{slug: string, name: string}>
	 */
	public static function get_active_languages(): array {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return [
				[ 'slug' => 'uk', 'name' => 'Українська' ],
				[ 'slug' => 'en', 'name' => 'English' ],
				[ 'slug' => 'ru', 'name' => 'Русский' ],
			];
		}

		$raw_langs = (array) pll_languages_list( [ 'fields' => '' ] );
		$result    = [];
		foreach ( $raw_langs as $lang_obj ) {
			if ( is_object( $lang_obj ) && ! empty( $lang_obj->slug ) ) {
				$slug     = (string) $lang_obj->slug;
				$name     = (string) ( $lang_obj->name ?? strtoupper( $slug ) );
				$result[] = [
					'slug' => $slug,
					'name' => $name,
				];
			}
		}

		if ( empty( $result ) ) {
			$slugs = (array) pll_languages_list( [ 'fields' => 'slug' ] );
			foreach ( $slugs as $slug ) {
				$s = (string) $slug;
				if ( '' !== $s ) {
					$result[] = [
						'slug' => $s,
						'name' => strtoupper( $s ),
					];
				}
			}
		}

		return $result;
	}

	/**
	 * Получение списка всех годов публикации из БД.
	 *
	 * @return array<int>
	 */
	private static function get_available_years(): array {
		global $wpdb;

		$years = $wpdb->get_col(
			"SELECT DISTINCT YEAR(post_date) AS yr
			FROM {$wpdb->posts}
			WHERE post_type IN ('post', 'page')
			AND post_status IN ('publish', 'draft', 'future', 'private', 'pending')
			AND post_date > '1970-01-01 00:00:00'
			ORDER BY yr DESC"
		);

		if ( ! is_array( $years ) ) {
			return [];
		}

		return array_values( array_filter( array_map( 'absint', $years ) ) );
	}

	/**
	 * Отрисовка содержимого вкладки «🗂 Пакетный перевод архива».
	 */
	public static function render_tab_content(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$languages = self::get_active_languages();
		$years     = self::get_available_years();

		$categories = get_terms( [
			'taxonomy'   => 'category',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
			'lang'       => '', // Все языки Polylang
		] );
		if ( is_wp_error( $categories ) || ! is_array( $categories ) ) {
			$categories = [];
		}

		$lang_slugs = array_map( static fn( array $l ): string => $l['slug'], $languages );
		?>
		<div class="plu-bulk-archive-wrap" id="plu-bulk-archive-box" data-langs="<?php echo esc_attr( (string) wp_json_encode( $lang_slugs ) ); ?>">

			<!-- 1. Карточка фильтрации и проверки связей Рубрики -->
			<div class="plu-bulk-card plu-bulk-filter-card">
				<h2 style="margin-top:0;"><?php esc_html_e( '🗂 1. Выбор архива и ревизия языковых связей', 'polyland-up' ); ?></h2>
				<p class="description" style="margin-bottom:16px;">
					<?php esc_html_e( 'Сканирование определяет реальный язык текста внутри каждой статьи (UK / RU / EN) средствами PHP без расхода токенов API, находит несвязанные статьи по URL-слагу и позволяет пакетно устранить пересортицу.', 'polyland-up' ); ?>
				</p>

				<div class="plu-bulk-filters-grid">
					<div class="plu-bulk-filter-item">
						<label for="plu-bulk-source-filter" class="plu-section-label">
							<?php esc_html_e( 'Рубрика / Тип записей:', 'polyland-up' ); ?>
						</label>
						<select id="plu-bulk-source-filter" class="widefat">
							<option value="all_posts"><?php esc_html_e( '📰 Все записи (post) без фильтра рубрики', 'polyland-up' ); ?></option>
							<option value="all_pages"><?php esc_html_e( '📄 Все страницы (page)', 'polyland-up' ); ?></option>
							<?php if ( ! empty( $categories ) ) : ?>
								<optgroup label="<?php esc_attr_e( 'Рубрики (category)', 'polyland-up' ); ?>">
									<?php foreach ( $categories as $cat ) : ?>
										<?php
										if ( ! $cat instanceof \WP_Term ) {
											continue;
										}
										$cat_lang = function_exists( 'pll_get_term_language' ) ? (string) pll_get_term_language( $cat->term_id, 'slug' ) : '';
										$lang_tag = '' !== $cat_lang ? '[' . strtoupper( $cat_lang ) . '] ' : '';
										?>
										<option value="cat_<?php echo esc_attr( (string) $cat->term_id ); ?>">
											<?php
											printf(
												'%s%s (ID: %d — %d зап.)',
												esc_html( $lang_tag ),
												esc_html( $cat->name ),
												(int) $cat->term_id,
												(int) $cat->count
											);
											?>
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endif; ?>
						</select>
					</div>

					<div class="plu-bulk-filter-item">
						<span class="plu-section-label">
							<?php esc_html_e( 'Период публикации (год):', 'polyland-up' ); ?>
						</span>
						<div class="plu-bulk-year-pair">
							<label class="plu-year-sublabel">
								<span><?php esc_html_e( 'Год с (от):', 'polyland-up' ); ?></span>
								<select id="plu-bulk-year-from" class="widefat">
									<option value="0"><?php esc_html_e( 'Все годы', 'polyland-up' ); ?></option>
									<?php foreach ( $years as $yr ) : ?>
										<option value="<?php echo esc_attr( (string) $yr ); ?>"><?php echo esc_html( (string) $yr ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<label class="plu-year-sublabel">
								<span><?php esc_html_e( 'по (до):', 'polyland-up' ); ?></span>
								<select id="plu-bulk-year-to" class="widefat">
									<option value="0"><?php esc_html_e( 'Все годы', 'polyland-up' ); ?></option>
									<?php foreach ( $years as $yr ) : ?>
										<option value="<?php echo esc_attr( (string) $yr ); ?>"><?php echo esc_html( (string) $yr ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						</div>
					</div>

					<div class="plu-bulk-filter-item">
						<label for="plu-bulk-problem-filter" class="plu-section-label">
							<?php esc_html_e( 'Фильтр по статусу проблемы:', 'polyland-up' ); ?>
						</label>
						<select id="plu-bulk-problem-filter" class="widefat">
							<option value="needs_action" selected="selected">
								<?php esc_html_e( '⚠️ Требуют перевода или исправления пересортицы', 'polyland-up' ); ?>
							</option>
							<option value="mismatch_only">
								<?php esc_html_e( '🔄 Только пересортица (язык Polylang != реальный язык текста)', 'polyland-up' ); ?>
							</option>
							<option value="all">
								<?php esc_html_e( '📋 Все статьи', 'polyland-up' ); ?>
							</option>
							<option value="complete">
								<?php esc_html_e( '✅ Полностью переведены на все языки', 'polyland-up' ); ?>
							</option>
						</select>
					</div>

					<div class="plu-bulk-filter-item plu-bulk-filter-actions">
						<button type="button" id="plu-bulk-scan-btn" class="button button-primary button-large">
							<?php esc_html_e( '🔍 Просканировать статьи', 'polyland-up' ); ?>
						</button>
						<span class="spinner" id="plu-bulk-scan-spinner" style="float:none; margin:0;"></span>
					</div>
				</div>

				<!-- Плашка языковых связей выбранной рубрики -->
				<div id="plu-bulk-category-status-bar" class="plu-bulk-cat-bar" style="display:none;"></div>
			</div>

			<!-- 2. Панель настроек пакетного перевода и Экономии токенов -->
			<div class="plu-bulk-card plu-bulk-settings-card">
				<h2 style="margin-top:0;"><?php esc_html_e( '⚙️ 2. Настройки пакетной обработки и защита лимитов API', 'polyland-up' ); ?></h2>

				<div class="plu-bulk-settings-grid">
					<div class="plu-bulk-setting-col">
						<label for="plu-bulk-session-limit" class="plu-section-label">
							<?php esc_html_e( 'Сессионный лимит (за 1 запуск):', 'polyland-up' ); ?>
						</label>
						<select id="plu-bulk-session-limit" class="widefat">
							<option value="5"><?php esc_html_e( '5 статей', 'polyland-up' ); ?></option>
							<option value="10" selected="selected"><?php esc_html_e( '10 статей (рекомендуется)', 'polyland-up' ); ?></option>
							<option value="20"><?php esc_html_e( '20 статей', 'polyland-up' ); ?></option>
							<option value="50"><?php esc_html_e( '50 статей', 'polyland-up' ); ?></option>
							<option value="0"><?php esc_html_e( 'Все отмеченные', 'polyland-up' ); ?></option>
						</select>
					</div>

					<div class="plu-bulk-setting-col">
						<label for="plu-bulk-delay-sec" class="plu-section-label">
							<?php esc_html_e( 'Пауза между статьями:', 'polyland-up' ); ?>
						</label>
						<select id="plu-bulk-delay-sec" class="widefat">
							<option value="0"><?php esc_html_e( 'Без паузы (для GTX)', 'polyland-up' ); ?></option>
							<option value="2"><?php esc_html_e( '2 секунды', 'polyland-up' ); ?></option>
							<option value="4" selected="selected"><?php esc_html_e( '4 секунды (защита от 429 в Gemini)', 'polyland-up' ); ?></option>
							<option value="6"><?php esc_html_e( '6 секунд', 'polyland-up' ); ?></option>
						</select>
					</div>

					<div class="plu-bulk-setting-col">
						<label for="plu-bulk-engine" class="plu-section-label">
							<?php esc_html_e( 'Движок перевода:', 'polyland-up' ); ?>
						</label>
						<select id="plu-bulk-engine" class="widefat">
							<option value="gtx" selected="selected">
								<?php esc_html_e( 'Google Translate (Free GTX) — БЕСПЛАТНО, 0 токенов, идеально для архива', 'polyland-up' ); ?>
							</option>
							<option value="gemini">
								<?php esc_html_e( 'Google Gemini AI — для важных/свежих статей', 'polyland-up' ); ?>
							</option>
						</select>
					</div>

					<div class="plu-bulk-setting-col">
						<label for="plu-bulk-overwrite-policy" class="plu-section-label">
							<?php esc_html_e( 'Режим перезаписи:', 'polyland-up' ); ?>
						</label>
						<select id="plu-bulk-overwrite-policy" class="widefat">
							<option value="smart" selected="selected">
								<?php esc_html_e( 'Умный режим (экономия токенов — пропускать готовые)', 'polyland-up' ); ?>
							</option>
							<option value="force_all">
								<?php esc_html_e( 'Принудительно перезаписать все выбранные языковые версии', 'polyland-up' ); ?>
							</option>
						</select>
					</div>

					<div class="plu-bulk-setting-col">
						<label for="plu-bulk-date-policy" class="plu-section-label">
							<?php esc_html_e( 'Дата публикации переводов:', 'polyland-up' ); ?>
						</label>
						<select id="plu-bulk-date-policy" class="widefat">
							<option value="preserve" selected="selected">
								<?php esc_html_e( 'Сохранять оригинальную дату статьи (post_date)', 'polyland-up' ); ?>
							</option>
							<option value="current">
								<?php esc_html_e( 'Ставить текущую дату', 'polyland-up' ); ?>
							</option>
						</select>
					</div>

					<div class="plu-bulk-setting-col">
						<span class="plu-section-label"><?php esc_html_e( 'Целевые языки для создания/перевода:', 'polyland-up' ); ?></span>
						<div class="plu-bulk-langs-checks">
							<?php foreach ( $languages as $lang ) : ?>
								<label class="plu-bulk-lang-cb-label">
									<input
										type="checkbox"
										class="plu-bulk-target-lang-cb"
										value="<?php echo esc_attr( $lang['slug'] ); ?>"
										checked="checked"
									/>
									<strong><?php echo esc_html( strtoupper( $lang['slug'] ) ); ?></strong>
									<span>(<?php echo esc_html( $lang['name'] ); ?>)</span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<div class="plu-bulk-Checkboxes-row">
					<label class="plu-option-checkbox">
						<input type="checkbox" id="plu-bulk-fix-mismatch" value="1" checked="checked" />
						<span>
							<strong><?php esc_html_e( 'Автоматически разруливать пересортицу:', 'polyland-up' ); ?></strong>
							<?php esc_html_e( 'если в посте UK лежит русский текст, а поста RU нет или он пустой — сначала сохранить русский оригинал в RU без траты токенов, а сам пост UK и пост EN перевести с русского!', 'polyland-up' ); ?>
						</span>
					</label>

					<label class="plu-option-checkbox">
						<input type="checkbox" id="plu-bulk-clean-slugs" value="1" checked="checked" />
						<span>
							<strong><?php esc_html_e( 'Приводить URL-слаги всех версий к единому чистому слагу:', 'polyland-up' ); ?></strong>
							<?php esc_html_e( 'вырезать суффиксы/инфиксы мигратора (-ru, -en, -uk, -ua) с сохранением номера статьи за день (-2, -3).', 'polyland-up' ); ?>
						</span>
					</label>
				</div>

				<div class="plu-bulk-run-bar">
					<button type="button" id="plu-bulk-start-btn" class="button button-primary button-hero" disabled="disabled">
						<?php esc_html_e( '🚀 Запустить пакетную обработку', 'polyland-up' ); ?>
					</button>
					<button type="button" id="plu-bulk-stop-btn" class="button button-secondary button-hero" style="display:none;">
						<?php esc_html_e( '⏸ Остановить', 'polyland-up' ); ?>
					</button>
					<span id="plu-bulk-summary-counter" class="plu-bulk-summary-counter"></span>
				</div>

				<!-- Визуальный прогресс-бар -->
				<div id="plu-bulk-progress-wrap" class="plu-bulk-progress-wrap" style="display:none;">
					<div class="plu-bulk-progress-header">
						<span id="plu-bulk-progress-label"><?php esc_html_e( 'Подготовка...', 'polyland-up' ); ?></span>
						<span id="plu-bulk-progress-percent">0%</span>
					</div>
					<div class="plu-bulk-progress-track">
						<div id="plu-bulk-progress-bar" class="plu-bulk-progress-bar" style="width:0%;"></div>
					</div>
				</div>

				<!-- Подробный лог пакетной сессии -->
				<div id="plu-bulk-log-toolbar" class="plu-bulk-log-toolbar" style="display:none;">
					<button type="button" id="plu-bulk-clear-log-btn" class="button button-small button-secondary">
						<?php esc_html_e( '🧹 Очистить лог выполнения', 'polyland-up' ); ?>
					</button>
				</div>
				<div id="plu-bulk-process-log" class="plu-duplicator-log plu-bulk-log" style="display:none;"></div>
			</div>

			<!-- 3. Таблица ревизии статей -->
			<div class="plu-bulk-card plu-bulk-table-card">
				<div class="plu-bulk-table-header">
					<h2 style="margin:0;"><?php esc_html_e( '📋 3. Таблица ревизии статей', 'polyland-up' ); ?></h2>
					<div class="plu-bulk-table-controls">
						<label class="plu-bulk-auto-hide-label">
							<input type="checkbox" id="plu-bulk-auto-hide-done" value="1" checked="checked" />
							<span><?php esc_html_e( '🧹 Автоматически убирать готовые статьи из списка после обработки', 'polyland-up' ); ?></span>
						</label>
						<button type="button" id="plu-bulk-hide-done-btn" class="button button-small button-secondary" style="display:none;">
							<?php esc_html_e( 'Скрыть выполненные (✅) сейчас', 'polyland-up' ); ?>
						</button>
						<div id="plu-bulk-scan-stats" class="plu-bulk-scan-stats"></div>
					</div>
				</div>

				<div class="plu-strings-table-scroll">
					<table class="wp-list-table widefat fixed striped plu-bulk-table" id="plu-bulk-results-table">
						<thead>
							<tr>
								<td class="manage-column column-cb check-column" style="width:36px;">
									<input type="checkbox" id="plu-bulk-select-all" />
								</td>
								<th style="width:26%;"><?php esc_html_e( 'ID / Дата / Заголовок', 'polyland-up' ); ?></th>
								<th style="width:18%;"><?php esc_html_e( 'URL-слаг (текущий → чистый)', 'polyland-up' ); ?></th>
								<th style="width:16%;"><?php esc_html_e( 'Паспорт Polylang vs Текст', 'polyland-up' ); ?></th>
								<?php foreach ( $languages as $lang ) : ?>
									<th class="plu-bulk-lang-col">
										<?php echo esc_html( strtoupper( $lang['slug'] ) ); ?>
									</th>
								<?php endforeach; ?>
								<th style="width:120px; text-align:center;"><?php esc_html_e( 'Действие', 'polyland-up' ); ?></th>
							</tr>
						</thead>
						<tbody id="plu-bulk-tbody">
							<tr>
								<td colspan="<?php echo esc_attr( (string) ( 5 + count( $languages ) ) ); ?>" style="text-align:center; padding:24px; color:#646970;">
									<?php esc_html_e( 'Выберите рубрику или фильтр выше и нажмите «🔍 Просканировать статьи».', 'polyland-up' ); ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Собирает информацию о языковых связях рубрики (category) в Polylang.
	 *
	 * @param int $cat_id ID терма рубрики.
	 * @return array<string, mixed>|null
	 */
	public static function inspect_category_translations( int $cat_id ): ?array {
		if ( $cat_id <= 0 ) {
			return null;
		}

		$term = get_term( $cat_id, 'category' );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		$languages    = self::get_active_languages();
		$translations = function_exists( 'pll_get_term_translations' ) ? (array) pll_get_term_translations( $cat_id ) : [];
		$term_lang    = function_exists( 'pll_get_term_language' ) ? (string) pll_get_term_language( $cat_id, 'slug' ) : '';
		if ( '' !== $term_lang && empty( $translations[ $term_lang ] ) ) {
			$translations[ $term_lang ] = $cat_id;
		}

		$matrix      = [];
		$missing_cnt = 0;

		foreach ( $languages as $lang ) {
			$l_slug = $lang['slug'];
			$t_id   = isset( $translations[ $l_slug ] ) ? (int) $translations[ $l_slug ] : 0;
			$t_obj  = $t_id > 0 ? get_term( $t_id, 'category' ) : null;

			if ( $t_obj instanceof \WP_Term ) {
				$matrix[ $l_slug ] = [
					'exists'  => true,
					'term_id' => (int) $t_obj->term_id,
					'name'    => $t_obj->name,
					'slug'    => $t_obj->slug,
				];
			} else {
				$missing_cnt++;
				$matrix[ $l_slug ] = [
					'exists'  => false,
					'term_id' => 0,
					'name'    => '',
					'slug'    => '',
				];
			}
		}

		return [
			'cat_id'       => (int) $term->term_id,
			'cat_name'     => $term->name,
			'cat_lang'     => $term_lang,
			'matrix'       => $matrix,
			'has_missing'  => $missing_cnt > 0,
			'missing_cnt'  => $missing_cnt,
		];
	}

	/**
	 * AJAX: Сканирование выбранной рубрики / типа записей и построение матрицы ревизии.
	 */
	public static function ajax_scan_category(): void {
		check_ajax_referer( 'plu_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Недостаточно прав.', 'polyland-up' ) ] );
		}

		$source_filter  = sanitize_text_field( wp_unslash( (string) ( $_POST['source_filter'] ?? 'all_posts' ) ) );
		$year_from      = absint( $_POST['year_from'] ?? ( $_POST['year'] ?? 0 ) );
		$year_to        = absint( $_POST['year_to'] ?? 0 );
		$problem_filter = sanitize_key( wp_unslash( (string) ( $_POST['problem_filter'] ?? 'needs_action' ) ) );

		$result = self::scan_archive_items( $source_filter, $year_from, $problem_filter, $year_to );
		wp_send_json_success( $result );
	}

	/**
	 * Сканирование записей и группировка по связям Polylang / чистому слагу.
	 *
	 * @param string $source_filter  'all_posts', 'all_pages' или 'cat_{ID}'.
	 * @param int    $year_from      Год начала периода (0 — без ограничения снизу).
	 * @param string $problem_filter 'all', 'needs_action', 'mismatch_only', 'complete'.
	 * @param int    $year_to        Год конца периода (0 — без ограничения сверху).
	 * @return array<string, mixed>
	 */
	public static function scan_archive_items(
		string $source_filter,
		int $year_from = 0,
		string $problem_filter = 'needs_action',
		int $year_to = 0
	): array {
		$post_type = 'post';
		$cat_id    = 0;

		if ( 'all_pages' === $source_filter ) {
			$post_type = 'page';
		} elseif ( str_starts_with( $source_filter, 'cat_' ) ) {
			$cat_id = absint( substr( $source_filter, 4 ) );
		}

		$cat_info = $cat_id > 0 ? self::inspect_category_translations( $cat_id ) : null;

		$query_args = [
			'post_type'              => $post_type,
			'post_status'            => [ 'publish', 'draft', 'future', 'private', 'pending' ],
			'posts_per_page'         => 1000,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'lang'                   => '', // Запрашиваем без фильтра текущего языка Polylang
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
		];

		if ( $cat_id > 0 ) {
			$query_args['tax_query'] = [
				[
					'taxonomy'         => 'category',
					'field'            => 'term_id',
					'terms'            => [ $cat_id ],
					'include_children' => false,
				],
			];
		}

		if ( $year_from > 0 && $year_to > 0 ) {
			$min_year = min( $year_from, $year_to );
			$max_year = max( $year_from, $year_to );
			if ( $min_year === $max_year ) {
				$query_args['date_query'] = [
					[
						'year' => $min_year,
					],
				];
			} else {
				$query_args['date_query'] = [
					[
						'after'     => [
							'year'  => $min_year,
							'month' => 1,
							'day'   => 1,
						],
						'before'    => [
							'year'  => $max_year,
							'month' => 12,
							'day'   => 31,
						],
						'inclusive' => true,
					],
				];
			}
		} elseif ( $year_from > 0 || $year_to > 0 ) {
			$single_year              = $year_from > 0 ? $year_from : $year_to;
			$query_args['date_query'] = [
				[
					'year' => $single_year,
				],
			];
		}

		$query = new \WP_Query( $query_args );
		$posts = is_array( $query->posts ) ? $query->posts : [];

		$languages  = self::get_active_languages();
		$lang_slugs = array_map( static fn( array $l ): string => $l['slug'], $languages );

		$seen_post_ids = [];
		$rows          = [];
		$counts        = [
			'total_scanned' => 0,
			'needs_action'  => 0,
			'mismatch_only' => 0,
			'complete'      => 0,
		];

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			if ( isset( $seen_post_ids[ $post->ID ] ) ) {
				continue;
			}

			$row = self::build_post_audit_row( $post, $lang_slugs );

			// Помечаем все задействованные в группе ID как просмотренные, чтобы не дублировать строки одной группы
			foreach ( $row['group_post_ids'] as $gid ) {
				$seen_post_ids[ (int) $gid ] = true;
			}

			$counts['total_scanned']++;
			if ( $row['needs_action'] ) {
				$counts['needs_action']++;
			}
			if ( $row['has_mismatch'] ) {
				$counts['mismatch_only']++;
			}
			if ( $row['is_complete'] ) {
				$counts['complete']++;
			}

			// Фильтрация вывода
			if ( 'needs_action' === $problem_filter && ! $row['needs_action'] ) {
				continue;
			}
			if ( 'mismatch_only' === $problem_filter && ! $row['has_mismatch'] ) {
				continue;
			}
			if ( 'complete' === $problem_filter && ! $row['is_complete'] ) {
				continue;
			}

			$rows[] = $row;
		}

		return [
			'category_info' => $cat_info,
			'rows'          => $rows,
			'counts'        => $counts,
			'languages'     => $lang_slugs,
		];
	}

	/**
	 * Ищет в БД несвязанные посты-однофамильцы других языков с тем же базовым чистым слагом.
	 *
	 * @param string             $clean_slug     Нормализованный базовый слаг (например, 'article-2019-04-17-2').
	 * @param string             $post_type      Тип записи.
	 * @param array<int, string> $lang_slugs     Список активных кодов языков.
	 * @param int                $source_post_id ID исходной записи, для которой ищутся осиротевшие однофамильцы.
	 * @param string             $source_lang    Код исходного языка записи.
	 * @return array<string, int> Карта lang_slug => post_id.
	 */
	public static function find_slug_siblings_by_clean_slug( string $clean_slug, string $post_type, array $lang_slugs, int $source_post_id = 0, string $source_lang = '' ): array {
		global $wpdb;

		if ( '' === $clean_slug ) {
			return [];
		}

		$candidates = [ $clean_slug ];
		foreach ( [ 'ru', 'en', 'uk', 'ua' ] as $sfx ) {
			$candidates[] = $clean_slug . '-' . $sfx;
			if ( preg_match( '/^(.+)-(\d+)$/', $clean_slug, $m ) ) {
				$candidates[] = $m[1] . '-' . $sfx . '-' . $m[2];
			}
		}
		$candidates   = array_values( array_unique( $candidates ) );
		$placeholders = implode( ', ', array_fill( 0, count( $candidates ), '%s' ) );

		$query_params = array_merge( [ $post_type ], $candidates );
		$sql          = $wpdb->prepare(
			"SELECT ID, post_name
			FROM {$wpdb->posts}
			WHERE post_type = %s
			AND post_status IN ('publish', 'draft', 'future', 'private', 'pending')
			AND post_name IN ($placeholders)",
			...$query_params
		);

		$found_rows = $wpdb->get_results( $sql );
		if ( ! is_array( $found_rows ) || empty( $found_rows ) ) {
			return [];
		}

		$siblings = [];
		foreach ( $found_rows as $r ) {
			$pid   = (int) $r->ID;
			$pname = (string) $r->post_name;
			if ( SameSlug::normalize_clean_slug( $pname ) !== $clean_slug ) {
				continue;
			}
			if ( $source_post_id > 0 && $pid === $source_post_id ) {
				continue;
			}
			$plang = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $pid, 'slug' ) : '';
			if ( '' === $plang || ! in_array( $plang, $lang_slugs, true ) || ! empty( $siblings[ $plang ] ) ) {
				continue;
			}

			// КРИТИЧНО: Проверяем, не принадлежит ли кандидат уже другой статье в Polylang
			if ( $source_post_id > 0 && function_exists( 'pll_get_post_translations' ) ) {
				$cand_translations = (array) pll_get_post_translations( $pid );
				$eff_source_lang   = '' !== $source_lang
					? $source_lang
					: ( function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $source_post_id, 'slug' ) : '' );

				if ( '' !== $eff_source_lang && ! empty( $cand_translations[ $eff_source_lang ] ) && (int) $cand_translations[ $eff_source_lang ] !== $source_post_id ) {
					continue;
				}
				if ( count( $cand_translations ) > 1 && ! in_array( $source_post_id, array_map( 'intval', $cand_translations ), true ) ) {
					continue;
				}
			}

			$siblings[ $plang ] = $pid;
		}

		return $siblings;
	}

	/**
	 * Формирует полную диагностическую карточку одной статьи (и её языковой группы) для таблицы ревизии.
	 *
	 * @param \WP_Post           $post       Основной проверяемый пост.
	 * @param array<int, string> $lang_slugs Список активных языков Polylang.
	 * @return array<string, mixed>
	 */
	public static function build_post_audit_row( \WP_Post $post, array $lang_slugs = [] ): array {
		if ( empty( $lang_slugs ) ) {
			$lang_slugs = array_map( static fn( array $l ): string => $l['slug'], self::get_active_languages() );
		}

		$assigned_lang = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post->ID, 'slug' ) : '';
		$real_lang     = self::detect_real_text_language( $post->post_title, $post->post_content );
		$raw_slug      = '' !== $post->post_name ? $post->post_name : sanitize_title( $post->post_title );
		$clean_slug    = SameSlug::normalize_clean_slug( $raw_slug );

		$pll_translations = function_exists( 'pll_get_post_translations' ) ? (array) pll_get_post_translations( $post->ID ) : [];
		if ( '' !== $assigned_lang && empty( $pll_translations[ $assigned_lang ] ) ) {
			$pll_translations[ $assigned_lang ] = $post->ID;
		}

		// Проверяем, есть ли несвязанные осиротевшие однофамильцы по базовому чистому слагу
		$slug_siblings    = self::find_slug_siblings_by_clean_slug( $clean_slug, $post->post_type, $lang_slugs, (int) $post->ID, $assigned_lang );
		$unlinked_matches = [];

		foreach ( $slug_siblings as $s_lang => $s_id ) {
			if ( empty( $pll_translations[ $s_lang ] ) && (int) $s_id !== (int) $post->ID ) {
				if ( function_exists( 'pll_get_post_translations' ) ) {
					$cand_translations = (array) pll_get_post_translations( (int) $s_id );
					if ( '' !== $assigned_lang && ! empty( $cand_translations[ $assigned_lang ] ) && (int) $cand_translations[ $assigned_lang ] !== (int) $post->ID ) {
						continue;
					}
					if ( count( $cand_translations ) > 1 && ! in_array( (int) $post->ID, array_map( 'intval', $cand_translations ), true ) ) {
						continue;
					}
				}
				$unlinked_matches[ $s_lang ] = (int) $s_id;
			}
		}

		$matrix         = [];
		$group_post_ids = [ $post->ID ];
		$has_mismatch   = ( '' !== $assigned_lang && 'empty' !== $real_lang && ! self::is_lang_compatible_with_detected( $assigned_lang, $real_lang ) );
		$missing_langs  = 0;
		$empty_langs    = 0;
		$slug_dirty     = ( $raw_slug !== $clean_slug );

		foreach ( $lang_slugs as $l_slug ) {
			$linked_id   = isset( $pll_translations[ $l_slug ] ) ? (int) $pll_translations[ $l_slug ] : 0;
			$unlinked_id = isset( $unlinked_matches[ $l_slug ] ) ? (int) $unlinked_matches[ $l_slug ] : 0;
			$target_id   = $linked_id > 0 ? $linked_id : $unlinked_id;
			$is_unlinked = ( 0 === $linked_id && $unlinked_id > 0 );

			if ( $target_id > 0 ) {
				$t_post = ( $target_id === $post->ID ) ? $post : get_post( $target_id );
			} else {
				$t_post = null;
			}

			if ( ! $t_post instanceof \WP_Post ) {
				$missing_langs++;
				$matrix[ $l_slug ] = [
					'state'       => 'missing',
					'post_id'     => 0,
					'real_lang'   => '',
					'slug'        => '',
					'edit_url'    => '',
					'view_url'    => '',
					'is_unlinked' => false,
				];
				continue;
			}

			$group_post_ids[] = (int) $t_post->ID;
			$t_real_lang      = ( $t_post->ID === $post->ID )
				? $real_lang
				: self::detect_real_text_language( $t_post->post_title, $t_post->post_content );

			if ( $t_post->post_name !== $clean_slug ) {
				$slug_dirty = true;
			}

			if ( 'empty' === $t_real_lang ) {
				$state = 'empty';
				$empty_langs++;
			} elseif ( ! self::is_lang_compatible_with_detected( $l_slug, $t_real_lang ) ) {
				$state        = 'mismatch';
				$has_mismatch = true;
			} else {
				$state = 'ok';
			}

			$matrix[ $l_slug ] = [
				'state'       => $state,
				'post_id'     => (int) $t_post->ID,
				'real_lang'   => $t_real_lang,
				'slug'        => $t_post->post_name,
				'edit_url'    => (string) get_edit_post_link( $t_post->ID, 'raw' ),
				'view_url'    => (string) get_permalink( $t_post->ID ),
				'is_unlinked' => $is_unlinked,
			];
		}

		$has_unlinked = ! empty( $unlinked_matches );
		$is_complete  = ( ! $has_mismatch && 0 === $missing_langs && 0 === $empty_langs && ! $has_unlinked );
		$needs_action = ! $is_complete;

		return [
			'post_id'          => (int) $post->ID,
			'title'            => '' !== $post->post_title ? $post->post_title : __( '(Без заголовка)', 'polyland-up' ),
			'date'             => substr( (string) $post->post_date, 0, 10 ),
			'post_date_full'   => (string) $post->post_date,
			'status'           => (string) $post->post_status,
			'edit_url'         => (string) get_edit_post_link( $post->ID, 'raw' ),
			'view_url'         => (string) get_permalink( $post->ID ),
			'current_slug'     => $raw_slug,
			'clean_slug'       => $clean_slug,
			'slug_needs_clean' => ( $raw_slug !== $clean_slug ),
			'assigned_lang'    => $assigned_lang,
			'real_lang'        => $real_lang,
			'has_mismatch'     => $has_mismatch,
			'has_unlinked'     => $has_unlinked,
			'unlinked_matches' => $unlinked_matches,
			'is_complete'      => $is_complete,
			'needs_action'     => $needs_action,
			'matrix'           => $matrix,
			'group_post_ids'   => array_values( array_unique( $group_post_ids ) ),
		];
	}

	/**
	 * AJAX: Автоматическое создание и связывание недостающих языковых рубрик в 1 клик.
	 */
	public static function ajax_fix_category_terms(): void {
		check_ajax_referer( 'plu_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Недостаточно прав.', 'polyland-up' ) ] );
		}

		$cat_id = isset( $_POST['cat_id'] ) ? absint( wp_unslash( $_POST['cat_id'] ) ) : 0;
		if ( $cat_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Некорректный ID рубрики.', 'polyland-up' ) ] );
		}

		$result = self::fix_missing_category_translations( $cat_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Переводит название рубрики и создаёт/связывает недостающие термы для всех языков Polylang.
	 *
	 * @param int $cat_id ID исходной рубрики.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function fix_missing_category_translations( int $cat_id ): array|\WP_Error {
		$source_term = get_term( $cat_id, 'category' );
		if ( ! $source_term instanceof \WP_Term ) {
			return new \WP_Error( 'plu_no_term', __( 'Рубрика не найдена.', 'polyland-up' ) );
		}

		if ( ! function_exists( 'pll_set_term_language' ) || ! function_exists( 'pll_save_term_translations' ) ) {
			return new \WP_Error( 'plu_no_polylang', __( 'Функции Polylang для рубрик недоступны.', 'polyland-up' ) );
		}

		$languages   = self::get_active_languages();
		$source_lang = function_exists( 'pll_get_term_language' ) ? (string) pll_get_term_language( $cat_id, 'slug' ) : '';
		if ( '' === $source_lang ) {
			$source_lang = self::detect_real_text_language( $source_term->name, $source_term->name . ' ' . $source_term->description );
			if ( 'empty' === $source_lang ) {
				$source_lang = 'uk';
			}
			pll_set_term_language( $cat_id, $source_lang );
		}

		$translations = function_exists( 'pll_get_term_translations' ) ? (array) pll_get_term_translations( $cat_id ) : [];
		$translations[ $source_lang ] = $cat_id;

		$clean_base_slug = SameSlug::normalize_clean_slug( $source_term->slug );
		$created_langs   = [];

		foreach ( $languages as $lang ) {
			$target_lang = $lang['slug'];
			if ( ! empty( $translations[ $target_lang ] ) ) {
				$existing_term = get_term( (int) $translations[ $target_lang ], 'category' );
				if ( $existing_term instanceof \WP_Term ) {
					continue;
				}
			}

			// Переводим название рубрики через бесплатный каскад GTX
			$translated_name = $source_term->name;
			if ( $target_lang !== $source_lang ) {
				$cascade = Settings::translate_free_cascade( $source_term->name, $source_lang, $target_lang );
				if ( ! empty( $cascade['success'] ) && '' !== trim( (string) $cascade['translated'] ) ) {
					$translated_name = trim( (string) $cascade['translated'] );
				}
			}

			// Родительская рубрика на целевом языке (если есть)
			$target_parent = 0;
			if ( $source_term->parent > 0 && function_exists( 'pll_get_term' ) ) {
				$parent_tr = (int) pll_get_term( $source_term->parent, $target_lang );
				if ( $parent_tr > 0 ) {
					$target_parent = $parent_tr;
				}
			}

			$desired_slug = $clean_base_slug . '-' . $target_lang;
			$inserted     = wp_insert_term(
				$translated_name,
				'category',
				[
					'slug'   => $desired_slug,
					'parent' => $target_parent,
				]
			);

			if ( is_wp_error( $inserted ) ) {
				// Если терм с таким именем/слагом уже существует, попробуем найти его
				$existing_by_slug = get_term_by( 'slug', $desired_slug, 'category' );
				if ( $existing_by_slug instanceof \WP_Term ) {
					$new_term_id = (int) $existing_by_slug->term_id;
				} else {
					$inserted_retry = wp_insert_term(
						$translated_name . ' (' . strtoupper( $target_lang ) . ')',
						'category',
						[
							'slug'   => $desired_slug . '-' . wp_rand( 10, 99 ),
							'parent' => $target_parent,
						]
					);
					if ( is_wp_error( $inserted_retry ) ) {
						continue;
					}
					$new_term_id = (int) $inserted_retry['term_id'];
				}
			} else {
				$new_term_id = (int) $inserted['term_id'];
			}

			pll_set_term_language( $new_term_id, $target_lang );
			$translations[ $target_lang ] = $new_term_id;
			$created_langs[]              = strtoupper( $target_lang );
		}

		pll_save_term_translations( $translations );

		return [
			'category_info' => self::inspect_category_translations( $cat_id ),
			'created_langs' => $created_langs,
			'message'       => ! empty( $created_langs )
				? sprintf( __( 'Созданы и связаны языковые рубрики: %s', 'polyland-up' ), implode( ', ', $created_langs ) )
				: __( 'Все языковые рубрики уже связаны.', 'polyland-up' ),
		];
	}

	/**
	 * AJAX: Пакетная обработка одной группы статьи (разруливание пересортицы, клонирование 0 токенов, перевод, нормализация слагов и дат).
	 */
	public static function ajax_process_post(): void {
		check_ajax_referer( 'plu_admin_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Недостаточно прав или некорректный ID записи.', 'polyland-up' ) ] );
		}

		@set_time_limit( 300 );
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		$engine = sanitize_key( wp_unslash( (string) ( $_POST['engine'] ?? 'gtx' ) ) );
		if ( ! in_array( $engine, [ 'gtx', 'gemini' ], true ) ) {
			$engine = 'gtx';
		}

		$overwrite_policy = sanitize_key( wp_unslash( (string) ( $_POST['overwrite_policy'] ?? 'smart' ) ) );
		if ( ! in_array( $overwrite_policy, [ 'smart', 'force_all' ], true ) ) {
			$overwrite_policy = 'smart';
		}

		$date_policy = sanitize_key( wp_unslash( (string) ( $_POST['date_policy'] ?? 'preserve' ) ) );
		if ( ! in_array( $date_policy, [ 'preserve', 'current' ], true ) ) {
			$date_policy = 'preserve';
		}

		$raw_langs    = isset( $_POST['target_langs'] ) && is_array( $_POST['target_langs'] ) ? wp_unslash( $_POST['target_langs'] ) : [];
		$target_langs = array_values( array_filter( array_map( 'sanitize_key', $raw_langs ) ) );

		$options = [
			'engine'                => $engine,
			'target_langs'          => $target_langs,
			'overwrite_policy'      => $overwrite_policy,
			'fix_language_mismatch' => ! empty( $_POST['fix_language_mismatch'] ),
			'clean_same_slugs'      => ! empty( $_POST['clean_same_slugs'] ),
			'date_policy'           => $date_policy,
		];

		try {
			$result = self::process_single_post_group( $post_id, $options );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( [
					'message' => $result->get_error_message(),
					'post_id' => $post_id,
				] );
			}
			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [
				'message' => sprintf( 'PHP Error: %s (%s:%d)', $e->getMessage(), basename( $e->getFile() ), $e->getLine() ),
				'post_id' => $post_id,
			] );
		}
	}

	/**
	 * Основной серверный алгоритм обработки одной группы статьи.
	 *
	 * @param int                  $post_id ID выбранного поста из таблицы.
	 * @param array<string, mixed> $options Настройки пакетной обработки.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function process_single_post_group( int $post_id, array $options = [] ): array|\WP_Error {
		$entry_post = get_post( $post_id );
		if ( ! $entry_post instanceof \WP_Post ) {
			return new \WP_Error( 'plu_no_post', __( 'Исходная запись не найдена.', 'polyland-up' ) );
		}

		$languages  = self::get_active_languages();
		$lang_slugs = array_map( static fn( array $l ): string => $l['slug'], $languages );

		$engine                = ( 'gemini' === ( $options['engine'] ?? 'gtx' ) ) ? 'gemini' : 'gtx';
		$mode                  = ( 'gemini' === $engine ) ? 'translate_gemini' : 'translate_gtx';
		$overwrite_policy      = (string) ( $options['overwrite_policy'] ?? 'smart' );
		$fix_language_mismatch = ! isset( $options['fix_language_mismatch'] ) || ! empty( $options['fix_language_mismatch'] );
		$clean_same_slugs      = ! isset( $options['clean_same_slugs'] ) || ! empty( $options['clean_same_slugs'] );
		$preserve_date         = ( 'current' !== ( $options['date_policy'] ?? 'preserve' ) );
		$target_langs          = ! empty( $options['target_langs'] ) && is_array( $options['target_langs'] )
			? $options['target_langs']
			: $lang_slugs;

		// 1. Собираем текущие переводы Polylang + связываем однофамильцев по базовому слагу
		$entry_assigned_lang = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $entry_post->ID, 'slug' ) : '';
		if ( '' === $entry_assigned_lang ) {
			$detected_entry_lang = self::detect_real_text_language( $entry_post->post_title, $entry_post->post_content );
			$entry_assigned_lang = ( 'empty' !== $detected_entry_lang ) ? $detected_entry_lang : ( $lang_slugs[0] ?? 'uk' );
			if ( function_exists( 'pll_set_post_language' ) ) {
				pll_set_post_language( $entry_post->ID, $entry_assigned_lang );
			}
		}

		$raw_slug   = '' !== $entry_post->post_name ? $entry_post->post_name : sanitize_title( $entry_post->post_title );
		$clean_slug = SameSlug::normalize_clean_slug( $raw_slug );

		$translations = function_exists( 'pll_get_post_translations' ) ? (array) pll_get_post_translations( $entry_post->ID ) : [];
		$translations[ $entry_assigned_lang ] = $entry_post->ID;

		$siblings = self::find_slug_siblings_by_clean_slug( $clean_slug, $entry_post->post_type, $lang_slugs, (int) $entry_post->ID, $entry_assigned_lang );
		foreach ( $siblings as $s_lang => $s_id ) {
			if ( empty( $translations[ $s_lang ] ) && (int) $s_id !== (int) $entry_post->ID ) {
				if ( function_exists( 'pll_get_post_translations' ) ) {
					$cand_translations = (array) pll_get_post_translations( (int) $s_id );
					if ( '' !== $entry_assigned_lang && ! empty( $cand_translations[ $entry_assigned_lang ] ) && (int) $cand_translations[ $entry_assigned_lang ] !== (int) $entry_post->ID ) {
						continue;
					}
					if ( count( $cand_translations ) > 1 && ! in_array( (int) $entry_post->ID, array_map( 'intval', $cand_translations ), true ) ) {
						continue;
					}
				}
				$translations[ $s_lang ] = (int) $s_id;
			}
		}

		if ( function_exists( 'pll_save_post_translations' ) ) {
			pll_save_post_translations( $translations );
		}

		// Собираем карту объектов постов и их реальных языков текста ДО любых изменений
		/** @var array<string, \WP_Post> $group_posts */
		$group_posts = [];
		/** @var array<string, string> $group_real_langs */
		$group_real_langs = [];

		foreach ( $translations as $l_slug => $pid ) {
			$p_obj = ( (int) $pid === $entry_post->ID ) ? $entry_post : get_post( (int) $pid );
			if ( $p_obj instanceof \WP_Post ) {
				$group_posts[ $l_slug ]      = clone $p_obj;
				$group_real_langs[ $l_slug ] = self::detect_real_text_language( $p_obj->post_title, $p_obj->post_content );
			}
		}

		// 2. Определяем настоящий эталонный источник текста ($true_source_post и $true_source_lang)
		$entry_real_lang  = $group_real_langs[ $entry_assigned_lang ] ?? 'empty';
		$true_source_post = clone $entry_post;
		$true_source_lang = $entry_real_lang;

		if ( 'empty' === $true_source_lang ) {
			// Если открытый пост пустой, ищем любой непустой пост в группе
			foreach ( $group_real_langs as $l_slug => $r_lang ) {
				if ( 'empty' !== $r_lang && isset( $group_posts[ $l_slug ] ) ) {
					$true_source_post = clone $group_posts[ $l_slug ];
					$true_source_lang = $r_lang;
					break;
				}
			}
		}

		if ( 'empty' === $true_source_lang ) {
			return new \WP_Error( 'plu_empty_post', __( 'В записи и её переводах нет текста для перевода (контент пустой).', 'polyland-up' ) );
		}

		// Если эталонный текст нейтральный ('universal' или 'cyrillic_neutral'),
		// в качестве конкретного языка источника берём совместимый паспортный язык
		$title_only_lang   = self::detect_real_text_language( $entry_post->post_title, '' );
		$is_entry_mismatch = ! self::is_lang_compatible_with_detected( $entry_assigned_lang, $true_source_lang );
		if ( 'universal' === $title_only_lang && false !== stripos( $entry_post->post_content, 'scat-migrated-gallery' ) ) {
			$is_entry_mismatch = false;
			$true_source_lang  = $entry_assigned_lang;
		} elseif ( in_array( $true_source_lang, [ 'universal', 'cyrillic_neutral' ], true ) ) {
			if ( ! $is_entry_mismatch ) {
				$true_source_lang = $entry_assigned_lang;
			} else {
				$true_source_lang = ( 'cyrillic_neutral' === $true_source_lang ) ? 'uk' : $entry_assigned_lang;
			}
		}

		// Определяем эталонную историческую дату публикации (самая ранняя валидная дата в группе или дата исходного поста)
		$canonical_date     = $entry_post->post_date;
		$canonical_date_gmt = ( ! empty( $entry_post->post_date_gmt ) && '0000-00-00 00:00:00' !== $entry_post->post_date_gmt )
			? $entry_post->post_date_gmt
			: get_gmt_from_date( $canonical_date );

		$actions_log = [];

		// 3. ШАГ А: Если обнаружена пересортица (например, в посте UK лежит русский текст $true_source_lang = 'ru')
		// Сначала гарантируем сохранение оригинала в посте языка $true_source_lang в режиме clone_only (0 токенов!)
		if ( $fix_language_mismatch && $is_entry_mismatch && $true_source_lang !== $entry_assigned_lang && in_array( $true_source_lang, $lang_slugs, true ) ) {
			$existing_orig_lang_post = $group_posts[ $true_source_lang ] ?? null;
			$existing_orig_real_lang = $group_real_langs[ $true_source_lang ] ?? 'empty';

			$need_clone_original = (
				null === $existing_orig_lang_post
				|| 'empty' === $existing_orig_real_lang
				|| ! self::is_lang_compatible_with_detected( $true_source_lang, $existing_orig_real_lang )
				|| 'force_all' === $overwrite_policy
			);

			if ( $need_clone_original ) {
				$clone_opts = [
					'mode'               => 'clone_only',
					'keep_same_slug'     => $clean_same_slugs,
					'custom_slug'        => $clean_same_slugs ? $clean_slug : '',
					'rewrite_links'      => true,
					'target_status'      => (string) $entry_post->post_status,
					'overwrite_existing' => true,
					'preserve_date'      => $preserve_date,
					'existing_post_id'   => $existing_orig_lang_post ? (int) $existing_orig_lang_post->ID : 0,
				];

				$cloned_orig = MetaboxUI::duplicate_post_to_language( $true_source_post, $true_source_lang, $clone_opts );
				if ( is_wp_error( $cloned_orig ) ) {
					return $cloned_orig;
				}

				$new_orig_id                         = (int) $cloned_orig['post_id'];
				$translations[ $true_source_lang ]   = $new_orig_id;
				$fresh_orig_post                     = get_post( $new_orig_id );
				if ( $fresh_orig_post instanceof \WP_Post ) {
					$group_posts[ $true_source_lang ]      = clone $fresh_orig_post;
					$group_real_langs[ $true_source_lang ] = $true_source_lang;
				}

				$actions_log[] = sprintf(
					__( '[%1$s] Оригинал сохранён в #%2$d без траты токенов (0 токенов)', 'polyland-up' ),
					strtoupper( $true_source_lang ),
					$new_orig_id
				);
			} else {
				$actions_log[] = sprintf(
					__( '[%1$s] Уже содержит валидный текст (#%2$d) — пропущен', 'polyland-up' ),
					strtoupper( $true_source_lang ),
					(int) $existing_orig_lang_post->ID
				);
			}
		}

		// 4. ШАГ Б: Обработка остальных целевых языков (перевод с $true_source_lang)
		$self_translate_target = null;

		foreach ( $target_langs as $target_lang ) {
			if ( ! in_array( $target_lang, $lang_slugs, true ) ) {
				continue;
			}

			// Если это язык самого эталонного текста — он уже обработан выше или является исходником
			if ( $target_lang === $true_source_lang ) {
				$cur_p = $group_posts[ $target_lang ] ?? null;
				if ( null === $cur_p ) {
					$clone_res = MetaboxUI::duplicate_post_to_language(
						clone $true_source_post,
						$target_lang,
						[
							'mode'               => 'clone_only',
							'keep_same_slug'     => $clean_same_slugs,
							'custom_slug'        => $clean_same_slugs ? $clean_slug : '',
							'rewrite_links'      => true,
							'target_status'      => (string) $entry_post->post_status,
							'overwrite_existing' => true,
							'preserve_date'      => $preserve_date,
						]
					);
					if ( is_wp_error( $clone_res ) ) {
						return $clone_res;
					}
					$translations[ $target_lang ] = (int) $clone_res['post_id'];
					$actions_log[]                = sprintf(
						__( '[%1$s] Создан #%2$d из оригинала (0 токенов)', 'polyland-up' ),
						strtoupper( $target_lang ),
						(int) $clone_res['post_id']
					);
				}
				continue;
			}

			$existing_post      = $group_posts[ $target_lang ] ?? null;
			$existing_real_lang = $group_real_langs[ $target_lang ] ?? 'empty';

			// В умном режиме (smart) пропускаем версии, где уже лежит валидный текст на нужном языке
			if ( 'smart' === $overwrite_policy && null !== $existing_post && self::is_lang_compatible_with_detected( $target_lang, $existing_real_lang ) ) {
				$actions_log[] = sprintf(
					__( '[%1$s] Пропущен (#%2$d уже на %1$s)', 'polyland-up' ),
					strtoupper( $target_lang ),
					(int) $existing_post->ID
				);
				continue;
			}

			// Если нужно перевести саму исходную запись ($entry_post), в которой лежал чужой текст — откладываем в самый конец,
			// чтобы при переводе остальных языков (например, EN) в БД и кеше WP ещё оставался оригинальный текст!
			if ( null !== $existing_post && (int) $existing_post->ID === (int) $true_source_post->ID ) {
				$self_translate_target = $target_lang;
				continue;
			}

			// Обычное создание или перезапись перевода на $target_lang из $true_source_post
			$dup_res = MetaboxUI::duplicate_post_to_language(
				clone $true_source_post,
				$target_lang,
				[
					'mode'                     => $mode,
					'source_lang_override'     => $true_source_lang,
					'allow_same_assigned_lang' => true,
					'existing_post_id'         => $existing_post ? (int) $existing_post->ID : 0,
					'keep_same_slug'           => $clean_same_slugs,
					'custom_slug'              => $clean_same_slugs ? $clean_slug : '',
					'rewrite_links'            => true,
					'target_status'            => (string) $entry_post->post_status,
					'overwrite_existing'       => true,
					'preserve_date'            => $preserve_date,
				]
			);

			if ( is_wp_error( $dup_res ) ) {
				return $dup_res;
			}

			$translations[ $target_lang ] = (int) $dup_res['post_id'];
			$actions_log[]                = sprintf(
				__( '[%1$s] %2$s #%3$d (%4$s → %1$s)', 'polyland-up' ),
				strtoupper( $target_lang ),
				! empty( $dup_res['updated'] ) ? __( 'Обновлён', 'polyland-up' ) : __( 'Создан', 'polyland-up' ),
				(int) $dup_res['post_id'],
				strtoupper( $true_source_lang )
			);
		}

		// Выполняем перевод самой исходной записи последним шагом
		if ( null !== $self_translate_target ) {
			$self_res = MetaboxUI::self_translate_source_post(
				clone $true_source_post,
				$true_source_lang,
				[
					'mode'                   => $mode,
					'assigned_lang_override' => $self_translate_target,
					'keep_same_slug'         => $clean_same_slugs,
					'custom_slug'            => $clean_same_slugs ? $clean_slug : '',
					'rewrite_links'          => true,
					'preserve_date'          => $preserve_date,
				]
			);
			if ( is_wp_error( $self_res ) ) {
				return $self_res;
			}
			$translations[ $self_translate_target ] = (int) $self_res['post_id'];
			$actions_log[]                          = sprintf(
				__( '[%1$s] Переведена сама запись #%2$d (%3$s → %1$s)', 'polyland-up' ),
				strtoupper( $self_translate_target ),
				(int) $self_res['post_id'],
				strtoupper( $true_source_lang )
			);
		}

		// 5. Финальная синхронизация единого чистого слага, оригинальной даты публикации, рубрик и связей Polylang для всей группы
		foreach ( $translations as $l_slug => $pid ) {
			$pid = (int) $pid;
			if ( $pid <= 0 ) {
				continue;
			}

			if ( function_exists( 'pll_set_post_language' ) ) {
				pll_set_post_language( $pid, $l_slug );
			}

			$cur_post = get_post( $pid );
			if ( ! $cur_post instanceof \WP_Post ) {
				continue;
			}

			$need_post_update = false;
			$update_args      = [ 'ID' => $pid ];

			if ( $clean_same_slugs && $cur_post->post_name !== $clean_slug ) {
				$update_args['post_name'] = $clean_slug;
				$need_post_update         = true;
			}

			if ( $preserve_date && $cur_post->post_date !== $canonical_date ) {
				$update_args['post_date']     = $canonical_date;
				$update_args['post_date_gmt'] = $canonical_date_gmt;
				$update_args['edit_date']     = true;
				$need_post_update             = true;
			}

			if ( false !== stripos( (string) $cur_post->post_content, 'scat-migrated-gallery' ) ) {
				$localized_gal = DiviParser::localize_migrated_galleries_in_content(
					(string) $cur_post->post_content,
					(string) $cur_post->post_title,
					$l_slug
				);
				if ( $localized_gal !== (string) $cur_post->post_content ) {
					$update_args['post_content'] = wp_slash( $localized_gal );
					$need_post_update            = true;
				}
			}

			if ( $need_post_update ) {
				SameSlug::$forced_lang = $l_slug;
				try {
					if ( ! isset( $update_args['post_date'] ) && $preserve_date ) {
						$update_args['post_date']     = $canonical_date;
						$update_args['post_date_gmt'] = $canonical_date_gmt;
						$update_args['edit_date']     = true;
					}
					wp_update_post( $update_args );
				} finally {
					SameSlug::$forced_lang = null;
				}
			}

			// Синхронизируем переведенные рубрики и таксономии
			if ( $pid !== (int) $entry_post->ID ) {
				MetaboxUI::copy_post_taxonomies( $entry_post, $pid, $l_slug );
			}
		}

		if ( function_exists( 'pll_save_post_translations' ) ) {
			pll_save_post_translations( $translations );
		}

		$refreshed_entry = get_post( $entry_post->ID );
		$updated_row     = $refreshed_entry instanceof \WP_Post
			? self::build_post_audit_row( $refreshed_entry, $lang_slugs )
			: [];

		return [
			'post_id'      => (int) $entry_post->ID,
			'clean_slug'   => $clean_slug,
			'date'         => substr( $canonical_date, 0, 10 ),
			'translations' => $translations,
			'actions_log'  => $actions_log,
			'summary'      => ! empty( $actions_log ) ? implode( ' | ', $actions_log ) : __( 'Синхронизировано', 'polyland-up' ),
			'row'          => $updated_row,
		];
	}
}
