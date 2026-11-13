<?php
declare(strict_types=1);

namespace PolylangUp\Modules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Модуль «📦 Массовый переводчик БД Каталога SCaT» с дедупликацией уникальных текстов.
 */
final class ScatCatalogTranslator {

    /**
     * Фиксированный список таблиц каталога SCaT (без $wpdb->prefix).
     *
     * @var array<string, string>
     */
    public const TABLES = [
        'jos_tovar_lotki'      => 'Кабельные лотки и металлорукав',
        'jos_tovar_aksessuari' => 'Фасонные аксессуары и крышки',
        'jos_tovar_krepleniya' => 'Монтажные системы и кронштейны',
        'jos_tovar_metizy'     => 'Метизы и анкеры',
    ];

    /**
     * Карта языковых колонок таблиц каталога SCaT.
     *
     * @var array<string, array{label: string, short: string, name: string, descr: string}>
     */
    public const LANG_COLUMNS = [
        'ru' => [
            'label' => 'Русский',
            'short' => 'RU',
            'name'  => 'Name',
            'descr' => 'Descr',
        ],
        'uk' => [
            'label' => 'Українська',
            'short' => 'UA',
            'name'  => 'Name3',
            'descr' => 'Descr3',
        ],
        'en' => [
            'label' => 'English',
            'short' => 'EN',
            'name'  => 'Name2',
            'descr' => 'Descr2',
        ],
    ];

    /**
     * Инициализация хуков модуля.
     */
    public static function init(): void {
        if ( ! is_admin() ) {
            return;
        }

        add_action( 'wp_ajax_plu_scat_stats', [ self::class, 'ajax_get_stats' ] );
        add_action( 'wp_ajax_plu_scat_translate_batch', [ self::class, 'ajax_translate_batch' ] );
    }

    /**
     * Проверка существования таблицы каталога SCaT в базе данных.
     *
     * @param string $table Имя таблицы из белого списка self::TABLES.
     */
    public static function table_exists( string $table ): bool {
        if ( ! array_key_exists( $table, self::TABLES ) ) {
            return false;
        }

        global $wpdb;
        if ( ! is_object( $wpdb ) ) {
            return false;
        }

        $like  = $wpdb->esc_like( $table );
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

        return is_string( $found ) && strtolower( $found ) === strtolower( $table );
    }

    /**
     * Возвращает список реально присутствующих в БД таблиц каталога SCaT.
     *
     * @return array<string>
     */
    public static function get_existing_tables(): array {
        $existing = [];
        foreach ( array_keys( self::TABLES ) as $table ) {
            if ( self::table_exists( $table ) ) {
                $existing[] = $table;
            }
        }
        return $existing;
    }

    /**
     * Сбор живой статистики заполненности таблиц каталога SCaT.
     *
     * @return array{tables: array<int, array<string, mixed>>, totals: array<string, mixed>, existing_count: int}
     */
    public static function get_catalog_stats(): array {
        global $wpdb;

        $rows            = [];
        $existing_tables = [];
        $totals          = [
            'total'         => 0,
            'names'         => [ 'ru' => 0, 'uk' => 0, 'en' => 0 ],
            'descrs'        => [ 'ru' => 0, 'uk' => 0, 'en' => 0 ],
            'descrs_unique' => [ 'ru' => 0, 'uk' => 0, 'en' => 0 ],
        ];

        foreach ( self::TABLES as $table => $label ) {
            $exists = self::table_exists( $table );
            $entry  = [
                'table'         => $table,
                'label'         => $label,
                'exists'        => $exists,
                'total'         => 0,
                'names'         => [ 'ru' => 0, 'uk' => 0, 'en' => 0 ],
                'descrs'        => [ 'ru' => 0, 'uk' => 0, 'en' => 0 ],
                'descrs_unique' => [ 'ru' => 0, 'uk' => 0, 'en' => 0 ],
            ];

            if ( $exists && is_object( $wpdb ) ) {
                $existing_tables[] = $table;

                $sql = "SELECT
                    COUNT(*) AS total_rows,
                    COUNT(CASE WHEN `Name` IS NOT NULL AND TRIM(`Name`) != '' THEN 1 END) AS ru_name,
                    COUNT(CASE WHEN `Name3` IS NOT NULL AND TRIM(`Name3`) != '' THEN 1 END) AS uk_name,
                    COUNT(CASE WHEN `Name2` IS NOT NULL AND TRIM(`Name2`) != '' THEN 1 END) AS en_name,
                    COUNT(CASE WHEN `Descr` IS NOT NULL AND TRIM(`Descr`) != '' THEN 1 END) AS ru_descr,
                    COUNT(DISTINCT CASE WHEN `Descr` IS NOT NULL AND TRIM(`Descr`) != '' THEN `Descr` END) AS ru_descr_uniq,
                    COUNT(CASE WHEN `Descr3` IS NOT NULL AND TRIM(`Descr3`) != '' THEN 1 END) AS uk_descr,
                    COUNT(DISTINCT CASE WHEN `Descr3` IS NOT NULL AND TRIM(`Descr3`) != '' THEN `Descr3` END) AS uk_descr_uniq,
                    COUNT(CASE WHEN `Descr2` IS NOT NULL AND TRIM(`Descr2`) != '' THEN 1 END) AS en_descr,
                    COUNT(DISTINCT CASE WHEN `Descr2` IS NOT NULL AND TRIM(`Descr2`) != '' THEN `Descr2` END) AS en_descr_uniq
                FROM `{$table}`";

                $db_row = $wpdb->get_row( $sql, \ARRAY_A );
                if ( is_array( $db_row ) ) {
                    $entry['total'] = (int) ( $db_row['total_rows'] ?? 0 );
                    $entry['names'] = [
                        'ru' => (int) ( $db_row['ru_name'] ?? 0 ),
                        'uk' => (int) ( $db_row['uk_name'] ?? 0 ),
                        'en' => (int) ( $db_row['en_name'] ?? 0 ),
                    ];
                    $entry['descrs'] = [
                        'ru' => (int) ( $db_row['ru_descr'] ?? 0 ),
                        'uk' => (int) ( $db_row['uk_descr'] ?? 0 ),
                        'en' => (int) ( $db_row['en_descr'] ?? 0 ),
                    ];
                    $entry['descrs_unique'] = [
                        'ru' => (int) ( $db_row['ru_descr_uniq'] ?? 0 ),
                        'uk' => (int) ( $db_row['uk_descr_uniq'] ?? 0 ),
                        'en' => (int) ( $db_row['en_descr_uniq'] ?? 0 ),
                    ];

                    $totals['total'] += $entry['total'];
                    foreach ( [ 'ru', 'uk', 'en' ] as $lang_key ) {
                        $totals['names'][ $lang_key ]         += $entry['names'][ $lang_key ];
                        $totals['descrs'][ $lang_key ]        += $entry['descrs'][ $lang_key ];
                        $totals['descrs_unique'][ $lang_key ] += $entry['descrs_unique'][ $lang_key ];
                    }
                }
            }

            $rows[] = $entry;
        }

        // Для строки ИТОГО считаем глобально уникальные описания по всем существующим таблицам через UNION
        if ( ! empty( $existing_tables ) && is_object( $wpdb ) ) {
            foreach ( self::LANG_COLUMNS as $lang_key => $cols ) {
                $descr_col = $cols['descr'];
                $unions    = [];
                foreach ( $existing_tables as $tbl ) {
                    $unions[] = "SELECT `{$descr_col}` AS txt FROM `{$tbl}` WHERE `{$descr_col}` IS NOT NULL AND TRIM(`{$descr_col}`) != ''";
                }
                $union_sql = 'SELECT COUNT(*) FROM (' . implode( ' UNION ', $unions ) . ') AS plu_u';
                $uniq_val  = $wpdb->get_var( $union_sql );
                if ( null !== $uniq_val && '' !== (string) $uniq_val ) {
                    $totals['descrs_unique'][ $lang_key ] = (int) $uniq_val;
                }
            }
        }

        return [
            'tables'         => $rows,
            'totals'         => $totals,
            'existing_count' => count( $existing_tables ),
        ];
    }

    /**
     * Отрисовка вкладки «📦 Каталог SCaT (БД)» на странице настроек Polylang AI.
     */
    public static function render_tab_content(): void {
        $existing_tables = self::get_existing_tables();

        if ( empty( $existing_tables ) ) {
            ?>
            <div class="plu-menu-duplicator-card">
                <h2 style="margin-top:0;"><?php esc_html_e( '📦 Массовый переводчик БД Каталога SCaT', 'polyland-up' ); ?></h2>
                <div class="notice notice-info inline" style="margin: 10px 0 0;">
                    <p>
                        <strong><?php esc_html_e( 'Таблицы каталога SCaT не обнаружены в текущей базе данных.', 'polyland-up' ); ?></strong>
                    </p>
                    <p>
                        <?php esc_html_e( 'Модуль активируется автоматически, когда в базе данных WordPress присутствует хотя бы одна из следующих таблиц (имена фиксированные без префикса БД):', 'polyland-up' ); ?>
                    </p>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <?php foreach ( self::TABLES as $tbl_name => $tbl_label ) : ?>
                            <li>
                                <code><?php echo esc_html( $tbl_name ); ?></code> — <?php echo esc_html( $tbl_label ); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p style="margin-top: 8px; color: #50575e;">
                        <?php esc_html_e( 'Ожидаемые колонки: Id (PK), Sku, Name / Descr (RU), Name3 / Descr3 (UK), Name2 / Descr2 (EN).', 'polyland-up' ); ?>
                    </p>
                </div>
            </div>
            <?php
            return;
        }

        $stats              = self::get_catalog_stats();
        $default_translator = (string) plu_get_option( 'default_translator', 'gtx' );
        $default_engine     = 'gemini' === $default_translator ? 'gemini' : 'gtx';
        ?>
        <div class="plu-scat-wrap" id="plu-scat-catalog-box">
            <!-- Блок 1: Живая статистика заполненности БД -->
            <div class="plu-menu-duplicator-card" style="margin-bottom: 20px;">
                <div class="plu-scat-card-header">
                    <div>
                        <h2 style="margin:0 0 4px;"><?php esc_html_e( '📊 Статистика заполненности БД Каталога SCaT', 'polyland-up' ); ?></h2>
                        <p class="description" style="margin:0;">
                            <?php esc_html_e( 'Отображает количество заполненных названий и описаний (в скобках — число уникальных непустых описаний для оценки экономии дедупликации).', 'polyland-up' ); ?>
                        </p>
                    </div>
                    <div class="plu-scat-header-actions">
                        <span class="spinner" id="plu-scat-stats-spinner" style="float:none; margin:0;"></span>
                        <button type="button" id="plu-scat-refresh-stats" class="button button-secondary">
                            <?php esc_html_e( '🔄 Обновить статистику', 'polyland-up' ); ?>
                        </button>
                    </div>
                </div>

                <div class="plu-scat-table-scroll">
                    <table class="widefat striped plu-scat-stats-table" id="plu-scat-stats-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Таблица каталога', 'polyland-up' ); ?></th>
                                <th class="num"><?php esc_html_e( 'Всего позиций', 'polyland-up' ); ?></th>
                                <th class="num"><?php esc_html_e( 'RU Name', 'polyland-up' ); ?></th>
                                <th class="num"><?php esc_html_e( 'UA Name3', 'polyland-up' ); ?></th>
                                <th class="num"><?php esc_html_e( 'EN Name2', 'polyland-up' ); ?></th>
                                <th class="num"><?php esc_html_e( 'RU Descr (уник.)', 'polyland-up' ); ?></th>
                                <th class="num"><?php esc_html_e( 'UA Descr3 (уник.)', 'polyland-up' ); ?></th>
                                <th class="num"><?php esc_html_e( 'EN Descr2 (уник.)', 'polyland-up' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="plu-scat-stats-tbody">
                            <?php self::render_stats_rows( $stats['tables'] ); ?>
                        </tbody>
                        <tfoot id="plu-scat-stats-tfoot">
                            <?php self::render_stats_total_row( $stats['totals'] ); ?>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Блок 2: Панель настроек пакетного перевода -->
            <div class="plu-menu-duplicator-card">
                <h2 style="margin-top:0;"><?php esc_html_e( '📦 Пакетный перевод БД Каталога SCaT', 'polyland-up' ); ?></h2>
                <p class="description" style="margin-bottom: 16px;">
                    <?php esc_html_e( 'Выполняет пошаговый перевод с группировкой идентичных текстов (дедупликацией): каждый уникальный текст переводится один раз и сразу записывается во все совпадающие товары выбранных таблиц.', 'polyland-up' ); ?>
                </p>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Таблицы для обработки', 'polyland-up' ); ?></th>
                            <td>
                                <div class="plu-scat-tables-grid">
                                    <?php foreach ( $stats['tables'] as $tbl_info ) : ?>
                                        <?php
                                        $t_name   = (string) $tbl_info['table'];
                                        $t_label  = (string) $tbl_info['label'];
                                        $t_exists = ! empty( $tbl_info['exists'] );
                                        $t_count  = (int) $tbl_info['total'];
                                        ?>
                                        <label class="plu-scat-check-card <?php echo $t_exists ? '' : 'is-disabled'; ?>">
                                            <input
                                                type="checkbox"
                                                name="tables[]"
                                                class="plu-scat-table-cb"
                                                value="<?php echo esc_attr( $t_name ); ?>"
                                                <?php checked( $t_exists ); ?>
                                                <?php disabled( ! $t_exists ); ?>
                                            />
                                            <span>
                                                <strong><code><?php echo esc_html( $t_name ); ?></code></strong>
                                                <small style="display:block; color:#50575e;">
                                                    <?php echo esc_html( $t_label ); ?>
                                                    <?php if ( $t_exists ) : ?>
                                                        (<?php echo esc_html( number_format_i18n( $t_count ) ); ?>)
                                                    <?php else : ?>
                                                        — <?php esc_html_e( 'таблица не найдена', 'polyland-up' ); ?>
                                                    <?php endif; ?>
                                                </small>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="plu_scat_source_lang"><?php esc_html_e( 'Исходный язык (откуда)', 'polyland-up' ); ?></label>
                            </th>
                            <td>
                                <select id="plu_scat_source_lang" name="source_lang" class="regular-text">
                                    <option value="ru" selected="selected">
                                        <?php esc_html_e( 'Русский (Name / Descr) — старая база', 'polyland-up' ); ?>
                                    </option>
                                    <option value="uk">
                                        <?php esc_html_e( 'Українська (Name3 / Descr3) — новые товары', 'polyland-up' ); ?>
                                    </option>
                                    <option value="en">
                                        <?php esc_html_e( 'English (Name2 / Descr2)', 'polyland-up' ); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e( 'Целевые языки (куда)', 'polyland-up' ); ?></th>
                            <td>
                                <div class="plu-menu-langs-grid">
                                    <label class="plu-menu-lang-item" data-scat-lang="uk">
                                        <input type="checkbox" name="target_langs[]" class="plu-scat-target-lang-cb" value="uk" checked="checked" />
                                        <span><strong>UK</strong> — <?php esc_html_e( 'Українська (Name3 / Descr3)', 'polyland-up' ); ?></span>
                                    </label>
                                    <label class="plu-menu-lang-item is-disabled" data-scat-lang="ru">
                                        <input type="checkbox" name="target_langs[]" class="plu-scat-target-lang-cb" value="ru" disabled="disabled" />
                                        <span><strong>RU</strong> — <?php esc_html_e( 'Русский (Name / Descr)', 'polyland-up' ); ?></span>
                                    </label>
                                    <label class="plu-menu-lang-item" data-scat-lang="en">
                                        <input type="checkbox" name="target_langs[]" class="plu-scat-target-lang-cb" value="en" checked="checked" />
                                        <span><strong>EN</strong> — <?php esc_html_e( 'English (Name2 / Descr2)', 'polyland-up' ); ?></span>
                                    </label>
                                </div>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="plu_scat_fields_mode"><?php esc_html_e( 'Поля для перевода', 'polyland-up' ); ?></label>
                            </th>
                            <td>
                                <select id="plu_scat_fields_mode" name="fields_mode" class="regular-text">
                                    <option value="descr_only" selected="selected">
                                        <?php esc_html_e( 'Только описания (Descr -> Descr3 / Descr2)', 'polyland-up' ); ?>
                                    </option>
                                    <option value="name_only">
                                        <?php esc_html_e( 'Только названия (Name -> Name3 / Name2)', 'polyland-up' ); ?>
                                    </option>
                                    <option value="both">
                                        <?php esc_html_e( 'И описания, и названия', 'polyland-up' ); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="plu_scat_overwrite_mode"><?php esc_html_e( 'Режим перезаписи', 'polyland-up' ); ?></label>
                            </th>
                            <td>
                                <select id="plu_scat_overwrite_mode" name="overwrite_mode" class="large-text" style="max-width: 520px;">
                                    <option value="empty_only" selected="selected">
                                        <?php esc_html_e( 'Переводить ТОЛЬКО пустые поля (не трогать уже существующие переводы)', 'polyland-up' ); ?>
                                    </option>
                                    <option value="overwrite_all">
                                        <?php esc_html_e( 'Перезаписать все поля заново', 'polyland-up' ); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="plu_scat_engine"><?php esc_html_e( 'Движок перевода', 'polyland-up' ); ?></label>
                            </th>
                            <td>
                                <select id="plu_scat_engine" name="engine" class="regular-text">
                                    <option value="gemini" <?php selected( 'gemini', $default_engine ); ?>>
                                        <?php esc_html_e( 'Google Gemini AI (с инженерным глоссарием SCaT)', 'polyland-up' ); ?>
                                    </option>
                                    <option value="gtx" <?php selected( 'gtx', $default_engine ); ?>>
                                        <?php esc_html_e( 'Google Translate (Free GTX)', 'polyland-up' ); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="plu_scat_batch_size"><?php esc_html_e( 'Размер пачки за 1 шаг', 'polyland-up' ); ?></label>
                            </th>
                            <td>
                                <select id="plu_scat_batch_size" name="batch_size">
                                    <option value="20">20 <?php esc_html_e( 'уникальных текстов за запрос', 'polyland-up' ); ?></option>
                                    <option value="35" selected="selected">35 <?php esc_html_e( 'уникальных текстов за запрос', 'polyland-up' ); ?></option>
                                    <option value="50">50 <?php esc_html_e( 'уникальных текстов за запрос', 'polyland-up' ); ?></option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="plu-scat-actions">
                    <button type="button" id="plu-scat-start" class="button button-primary button-large">
                        <?php esc_html_e( '🚀 Запустить перевод каталога', 'polyland-up' ); ?>
                    </button>
                    <button type="button" id="plu-scat-stop" class="button button-secondary button-large" disabled="disabled">
                        <?php esc_html_e( '⏸ Остановить', 'polyland-up' ); ?>
                    </button>
                    <span class="spinner" id="plu-scat-spinner" style="float:none; margin:0;"></span>
                </div>

                <!-- Прогресс-бар -->
                <div id="plu-scat-progress-wrap" class="plu-scat-progress-wrap" style="display:none;">
                    <div class="plu-scat-progress-meta">
                        <span id="plu-scat-progress-label"><?php esc_html_e( 'Подготовка…', 'polyland-up' ); ?></span>
                        <strong id="plu-scat-progress-percent">0%</strong>
                    </div>
                    <div class="plu-scat-progress-track">
                        <div id="plu-scat-progress-bar" class="plu-scat-progress-bar" style="width: 0%;"></div>
                    </div>
                    <div id="plu-scat-progress-counters" class="plu-scat-progress-counters"></div>
                </div>

                <!-- Лог выполнения -->
                <div id="plu-scat-log" class="plu-duplicator-log" style="display:none; margin-top:14px; max-width:100%;"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Рендер строк таблицы статистики (используется при первичной загрузке).
     *
     * @param array<int, array<string, mixed>> $tables_stats Данные по таблицам.
     */
    private static function render_stats_rows( array $tables_stats ): void {
        foreach ( $tables_stats as $row ) {
            $exists = ! empty( $row['exists'] );
            $total  = (int) ( $row['total'] ?? 0 );
            ?>
            <tr data-scat-table-row="<?php echo esc_attr( (string) $row['table'] ); ?>">
                <td>
                    <strong><code><?php echo esc_html( (string) $row['table'] ); ?></code></strong>
                    <div style="font-size:12px; color:#50575e;"><?php echo esc_html( (string) $row['label'] ); ?></div>
                </td>
                <?php if ( ! $exists ) : ?>
                    <td colspan="7" style="color:#8c8f94; font-style:italic;">
                        <?php esc_html_e( 'Таблица не найдена в базе данных', 'polyland-up' ); ?>
                    </td>
                <?php else : ?>
                    <td class="num"><strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong></td>
                    <td class="num"><?php echo esc_html( self::format_count_cell( (int) $row['names']['ru'], $total ) ); ?></td>
                    <td class="num"><?php echo esc_html( self::format_count_cell( (int) $row['names']['uk'], $total ) ); ?></td>
                    <td class="num"><?php echo esc_html( self::format_count_cell( (int) $row['names']['en'], $total ) ); ?></td>
                    <td class="num"><?php echo esc_html( self::format_descr_cell( (int) $row['descrs']['ru'], (int) $row['descrs_unique']['ru'], $total ) ); ?></td>
                    <td class="num"><?php echo esc_html( self::format_descr_cell( (int) $row['descrs']['uk'], (int) $row['descrs_unique']['uk'], $total ) ); ?></td>
                    <td class="num"><?php echo esc_html( self::format_descr_cell( (int) $row['descrs']['en'], (int) $row['descrs_unique']['en'], $total ) ); ?></td>
                <?php endif; ?>
            </tr>
            <?php
        }
    }

    /**
     * Рендер итоговой строки таблицы статистики.
     *
     * @param array<string, mixed> $totals Итоговые данные.
     */
    private static function render_stats_total_row( array $totals ): void {
        $total = (int) ( $totals['total'] ?? 0 );
        ?>
        <tr class="plu-scat-total-row">
            <th><strong><?php esc_html_e( 'ИТОГО', 'polyland-up' ); ?></strong></th>
            <th class="num"><strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong></th>
            <th class="num"><strong><?php echo esc_html( self::format_count_cell( (int) $totals['names']['ru'], $total ) ); ?></strong></th>
            <th class="num"><strong><?php echo esc_html( self::format_count_cell( (int) $totals['names']['uk'], $total ) ); ?></strong></th>
            <th class="num"><strong><?php echo esc_html( self::format_count_cell( (int) $totals['names']['en'], $total ) ); ?></strong></th>
            <th class="num"><strong><?php echo esc_html( self::format_descr_cell( (int) $totals['descrs']['ru'], (int) $totals['descrs_unique']['ru'], $total ) ); ?></strong></th>
            <th class="num"><strong><?php echo esc_html( self::format_descr_cell( (int) $totals['descrs']['uk'], (int) $totals['descrs_unique']['uk'], $total ) ); ?></strong></th>
            <th class="num"><strong><?php echo esc_html( self::format_descr_cell( (int) $totals['descrs']['en'], (int) $totals['descrs_unique']['en'], $total ) ); ?></strong></th>
        </tr>
        <?php
    }

    /**
     * Форматирование ячейки заполненности названий.
     */
    private static function format_count_cell( int $count, int $total ): string {
        $pct = $total > 0 ? (int) round( ( $count / $total ) * 100 ) : 0;
        return sprintf( '%s (%d%%)', number_format_i18n( $count ), $pct );
    }

    /**
     * Форматирование ячейки заполненности описаний с указанием числа уникальных текстов.
     */
    private static function format_descr_cell( int $count, int $unique, int $total ): string {
        $pct = $total > 0 ? (int) round( ( $count / $total ) * 100 ) : 0;
        return sprintf( '%s / %d%% (уник.: %s)', number_format_i18n( $count ), $pct, number_format_i18n( $unique ) );
    }

    /**
     * AJAX-обработчик получения живой статистики таблиц каталога SCaT.
     */
    public static function ajax_get_stats(): void {
        check_ajax_referer( 'plu_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [
                'message' => __( 'У вас недостаточно прав для выполнения этого действия.', 'polyland-up' ),
            ] );
        }

        try {
            $stats = self::get_catalog_stats();
            wp_send_json_success( $stats );
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
     * AJAX-обработчик планирования и пакетного перевода БД каталога SCaT.
     */
    public static function ajax_translate_batch(): void {
        check_ajax_referer( 'plu_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [
                'message' => __( 'У вас недостаточно прав для выполнения этого действия.', 'polyland-up' ),
            ] );
        }

        @set_time_limit( 300 );
        if ( function_exists( 'ignore_user_abort' ) ) {
            ignore_user_abort( true );
        }

        $phase          = isset( $_POST['phase'] ) ? sanitize_key( (string) $_POST['phase'] ) : 'run';
        $raw_tables     = isset( $_POST['tables'] ) ? (array) wp_unslash( $_POST['tables'] ) : [];
        $source_lang    = isset( $_POST['source_lang'] ) ? sanitize_key( (string) $_POST['source_lang'] ) : 'ru';
        $raw_targets    = isset( $_POST['target_langs'] ) ? (array) wp_unslash( $_POST['target_langs'] ) : [];
        $fields_mode    = isset( $_POST['fields_mode'] ) ? sanitize_key( (string) $_POST['fields_mode'] ) : 'descr_only';
        $overwrite_mode = isset( $_POST['overwrite_mode'] ) ? sanitize_key( (string) $_POST['overwrite_mode'] ) : 'empty_only';
        $engine         = isset( $_POST['engine'] ) ? sanitize_key( (string) $_POST['engine'] ) : 'gtx';
        $batch_size     = isset( $_POST['batch_size'] ) ? (int) $_POST['batch_size'] : 35;

        try {
            if ( 'plan' === $phase ) {
                $plan = self::plan_tasks( [
                    'tables'         => $raw_tables,
                    'source_lang'    => $source_lang,
                    'target_langs'   => $raw_targets,
                    'fields_mode'    => $fields_mode,
                    'overwrite_mode' => $overwrite_mode,
                ] );

                if ( is_wp_error( $plan ) ) {
                    wp_send_json_error( [ 'message' => $plan->get_error_message() ] );
                }

                wp_send_json_success( $plan );
            }

            $target_lang = isset( $_POST['target_lang'] ) ? sanitize_key( (string) $_POST['target_lang'] ) : '';
            $field_type  = isset( $_POST['field_type'] ) ? sanitize_key( (string) $_POST['field_type'] ) : 'descr';
            $table_index = isset( $_POST['table_index'] ) ? max( 0, (int) $_POST['table_index'] ) : 0;
            $last_id     = isset( $_POST['last_id'] ) ? max( 0, (int) $_POST['last_id'] ) : 0;
            $total_rows  = isset( $_POST['task_total_rows'] ) ? max( 0, (int) $_POST['task_total_rows'] ) : 0;
            $tbl_counts  = isset( $_POST['table_counts'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['table_counts'] ) ) : [];

            $raw_hashes = $_POST['processed_hashes'] ?? '';
            if ( is_string( $raw_hashes ) ) {
                $processed_hashes = array_filter( array_map( 'sanitize_key', explode( ',', $raw_hashes ) ) );
            } elseif ( is_array( $raw_hashes ) ) {
                $processed_hashes = array_filter( array_map( 'sanitize_key', (array) wp_unslash( $raw_hashes ) ) );
            } else {
                $processed_hashes = [];
            }

            $result = self::process_batch( [
                'tables'           => $raw_tables,
                'source_lang'      => $source_lang,
                'target_lang'      => $target_lang,
                'field_type'       => $field_type,
                'overwrite_mode'   => $overwrite_mode,
                'engine'           => $engine,
                'batch_size'       => $batch_size,
                'table_index'      => $table_index,
                'last_id'          => $last_id,
                'processed_hashes' => $processed_hashes,
                'task_total_rows'  => $total_rows,
                'table_counts'     => $tbl_counts,
            ] );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message() ] );
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
     * Валидация выбранных таблиц (должны входить в белый список и существовать в БД).
     *
     * @param array<mixed> $raw_tables Входящий массив имен таблиц.
     * @return array<string>
     */
    private static function sanitize_tables( array $raw_tables ): array {
        $valid = [];
        foreach ( $raw_tables as $tbl ) {
            $name = sanitize_key( (string) $tbl );
            if ( array_key_exists( $name, self::TABLES ) && self::table_exists( $name ) && ! in_array( $name, $valid, true ) ) {
                $valid[] = $name;
            }
        }
        return $valid;
    }

    /**
     * Построение плана подзадач (язык × тип поля) с подсчетом строк и уникальных текстов.
     *
     * @param array<string, mixed> $args Параметры запуска.
     * @return array<string, mixed>|\WP_Error
     */
    public static function plan_tasks( array $args ): array|\WP_Error {
        global $wpdb;

        $tables         = self::sanitize_tables( (array) ( $args['tables'] ?? [] ) );
        $source_lang    = sanitize_key( (string) ( $args['source_lang'] ?? 'ru' ) );
        $overwrite_mode = sanitize_key( (string) ( $args['overwrite_mode'] ?? 'empty_only' ) );
        $fields_mode    = sanitize_key( (string) ( $args['fields_mode'] ?? 'descr_only' ) );

        if ( empty( $tables ) ) {
            return new \WP_Error( 'plu_scat_no_tables', __( 'Выберите хотя бы одну существующую таблицу каталога.', 'polyland-up' ) );
        }

        if ( ! array_key_exists( $source_lang, self::LANG_COLUMNS ) ) {
            return new \WP_Error( 'plu_scat_invalid_src', __( 'Указан некорректный исходный язык.', 'polyland-up' ) );
        }

        if ( ! in_array( $overwrite_mode, [ 'empty_only', 'overwrite_all' ], true ) ) {
            $overwrite_mode = 'empty_only';
        }

        $target_langs = [];
        foreach ( (array) ( $args['target_langs'] ?? [] ) as $tl ) {
            $code = sanitize_key( (string) $tl );
            if ( $code !== $source_lang && array_key_exists( $code, self::LANG_COLUMNS ) && ! in_array( $code, $target_langs, true ) ) {
                $target_langs[] = $code;
            }
        }

        if ( empty( $target_langs ) ) {
            return new \WP_Error( 'plu_scat_no_targets', __( 'Выберите хотя бы один целевой язык (отличный от исходного).', 'polyland-up' ) );
        }

        $field_types = match ( $fields_mode ) {
            'name_only' => [ 'name' ],
            'both'      => [ 'name', 'descr' ],
            default     => [ 'descr' ],
        };

        $tasks              = [];
        $grand_total_rows   = 0;
        $grand_total_unique = 0;

        foreach ( $target_langs as $tlang ) {
            foreach ( $field_types as $ftype ) {
                $src_col = self::LANG_COLUMNS[ $source_lang ][ $ftype ];
                $dst_col = self::LANG_COLUMNS[ $tlang ][ $ftype ];

                $task_total_rows = 0;
                $table_counts    = [];
                $unions          = [];
                $fallback_unique = 0;

                foreach ( $tables as $tbl ) {
                    $where = self::build_where_clause( $src_col, $dst_col, $overwrite_mode );
                    $cnt   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tbl}` WHERE {$where}" );

                    $table_counts[]   = $cnt;
                    $task_total_rows += $cnt;

                    if ( $cnt > 0 ) {
                        $unions[]         = "SELECT `{$src_col}` AS txt FROM `{$tbl}` WHERE {$where}";
                        $fallback_unique += (int) $wpdb->get_var( "SELECT COUNT(DISTINCT `{$src_col}`) FROM `{$tbl}` WHERE {$where}" );
                    }
                }

                $task_unique = 0;
                if ( ! empty( $unions ) ) {
                    $union_val   = $wpdb->get_var( 'SELECT COUNT(*) FROM (' . implode( ' UNION ', $unions ) . ') AS plu_u' );
                    $task_unique = ( null !== $union_val && '' !== (string) $union_val ) ? (int) $union_val : $fallback_unique;
                }

                $grand_total_rows   += $task_total_rows;
                $grand_total_unique += $task_unique;

                $tasks[] = [
                    'target_lang'  => $tlang,
                    'lang_short'   => self::LANG_COLUMNS[ $tlang ]['short'],
                    'field_type'   => $ftype,
                    'src_col'      => $src_col,
                    'dst_col'      => $dst_col,
                    'total_rows'   => $task_total_rows,
                    'total_unique' => $task_unique,
                    'table_counts' => $table_counts,
                ];
            }
        }

        return [
            'tables'             => $tables,
            'tasks'              => $tasks,
            'grand_total_rows'   => $grand_total_rows,
            'grand_total_unique' => $grand_total_unique,
        ];
    }

    /**
     * Выполнение одного батча перевода с дедупликацией уникальных текстов и защитой от зацикливания.
     *
     * @param array<string, mixed> $args Параметры шага.
     * @return array<string, mixed>|\WP_Error
     */
    public static function process_batch( array $args ): array|\WP_Error {
        global $wpdb;

        $tables         = self::sanitize_tables( (array) ( $args['tables'] ?? [] ) );
        $source_lang    = sanitize_key( (string) ( $args['source_lang'] ?? 'ru' ) );
        $target_lang    = sanitize_key( (string) ( $args['target_lang'] ?? '' ) );
        $field_type     = sanitize_key( (string) ( $args['field_type'] ?? 'descr' ) );
        $overwrite_mode = sanitize_key( (string) ( $args['overwrite_mode'] ?? 'empty_only' ) );
        $engine         = sanitize_key( (string) ( $args['engine'] ?? 'gtx' ) );
        $batch_size     = max( 1, min( 100, (int) ( $args['batch_size'] ?? 35 ) ) );
        $cur_table_idx  = max( 0, (int) ( $args['table_index'] ?? 0 ) );
        $cur_last_id    = max( 0, (int) ( $args['last_id'] ?? 0 ) );
        $task_total     = max( 0, (int) ( $args['task_total_rows'] ?? 0 ) );
        $table_counts   = array_values( array_map( 'intval', (array) ( $args['table_counts'] ?? [] ) ) );

        if ( empty( $tables ) ) {
            return new \WP_Error( 'plu_scat_no_tables', __( 'Не выбрано ни одной существующей таблицы.', 'polyland-up' ) );
        }

        if (
            ! array_key_exists( $source_lang, self::LANG_COLUMNS ) ||
            ! array_key_exists( $target_lang, self::LANG_COLUMNS ) ||
            $source_lang === $target_lang
        ) {
            return new \WP_Error( 'plu_scat_invalid_langs', __( 'Указаны некорректные языки перевода.', 'polyland-up' ) );
        }

        if ( ! in_array( $field_type, [ 'name', 'descr' ], true ) ) {
            return new \WP_Error( 'plu_scat_invalid_field', __( 'Указан некорректный тип поля для перевода.', 'polyland-up' ) );
        }

        if ( ! in_array( $overwrite_mode, [ 'empty_only', 'overwrite_all' ], true ) ) {
            $overwrite_mode = 'empty_only';
        }

        if ( ! in_array( $engine, [ 'gemini', 'gtx' ], true ) ) {
            $engine = 'gtx';
        }

        $src_col = self::LANG_COLUMNS[ $source_lang ][ $field_type ];
        $dst_col = self::LANG_COLUMNS[ $target_lang ][ $field_type ];
        $where   = self::build_where_clause( $src_col, $dst_col, $overwrite_mode );

        $processed_set = [];
        foreach ( (array) ( $args['processed_hashes'] ?? [] ) as $h ) {
            $clean_h = sanitize_key( (string) $h );
            if ( '' !== $clean_h ) {
                $processed_set[ $clean_h ] = true;
            }
        }

        // 1. Сканируем таблицы и собираем пачку до $batch_size уникальных текстов
        $batch_items     = [];
        $chunk_limit     = 500;
        $batch_full      = false;
        $skipped_dups    = 0;
        $rows_in_batch   = 0;

        while ( $cur_table_idx < count( $tables ) && ! $batch_full ) {
            $table = $tables[ $cur_table_idx ];

            while ( true ) {
                $sql = $wpdb->prepare(
                    "SELECT `Id`, `Sku`, `{$src_col}` AS src_text
                     FROM `{$table}`
                     WHERE `Id` > %d AND {$where}
                     ORDER BY `Id` ASC
                     LIMIT %d",
                    $cur_last_id,
                    $chunk_limit
                );

                $rows = $wpdb->get_results( $sql, \ARRAY_A );
                if ( ! is_array( $rows ) || empty( $rows ) ) {
                    $cur_table_idx++;
                    $cur_last_id = 0;
                    break;
                }

                foreach ( $rows as $row ) {
                    $row_id   = (int) ( $row['Id'] ?? 0 );
                    $row_sku  = (string) ( $row['Sku'] ?? '' );
                    $raw_text = (string) ( $row['src_text'] ?? '' );
                    $trimmed  = trim( $raw_text );

                    if ( '' === $trimmed ) {
                        $cur_last_id = $row_id;
                        continue;
                    }

                    $hash = md5( $trimmed );

                    // Текст уже обрабатывался в предыдущих батчах этой задачи — пропускаем без запроса к API
                    if ( isset( $processed_set[ $hash ] ) ) {
                        $cur_last_id = $row_id;
                        $skipped_dups++;
                        continue;
                    }

                    // Если это новый уникальный текст, а пачка уже достигла $batch_size — останавливаемся ПЕРЕД этой строкой
                    if ( ! isset( $batch_items[ $hash ] ) && count( $batch_items ) >= $batch_size ) {
                        $batch_full = true;
                        break;
                    }

                    if ( ! isset( $batch_items[ $hash ] ) ) {
                        $send_text = function_exists( 'wp_check_invalid_utf8' )
                            ? wp_check_invalid_utf8( $trimmed, true )
                            : $trimmed;
                        if ( '' === trim( $send_text ) ) {
                            $cur_last_id            = $row_id;
                            $processed_set[ $hash ] = true;
                            continue;
                        }

                        $batch_items[ $hash ] = [
                            'hash'         => $hash,
                            'send_text'    => $send_text,
                            'raw_variants' => [],
                            'ids_by_tbl'   => [],
                            'first_sku'    => $row_sku,
                            'row_count'    => 0,
                        ];
                    }

                    $batch_items[ $hash ]['raw_variants'][ $raw_text ] = true;
                    $batch_items[ $hash ]['ids_by_tbl'][ $table ][]    = $row_id;
                    $batch_items[ $hash ]['row_count']++;
                    $cur_last_id = $row_id;
                    $rows_in_batch++;
                }

                if ( $batch_full ) {
                    break;
                }

                if ( count( $rows ) < $chunk_limit ) {
                    $cur_table_idx++;
                    $cur_last_id = 0;
                    break;
                }
            }
        }

        $finished = ( $cur_table_idx >= count( $tables ) );

        if ( empty( $batch_items ) ) {
            return [
                'finished'          => true,
                'next_table_index'  => $cur_table_idx,
                'next_last_id'      => $cur_last_id,
                'new_hashes'        => [],
                'translated_unique' => 0,
                'skipped_empty'     => 0,
                'skipped_duplicate' => $skipped_dups,
                'updated_rows'      => 0,
                'saved_requests'    => 0,
                'subjob_done_rows'  => $task_total,
                'provider_label'    => '',
                'time_sec'          => 0.0,
                'samples'           => [],
            ];
        }

        // 2. Формируем словарь уникальных текстов и отправляем в TranslatorAPI
        $dict     = [];
        $hash_map = [];
        $idx      = 0;

        foreach ( $batch_items as $hash => $item ) {
            $key              = 'k_' . $idx;
            $dict[ $key ]     = $item['send_text'];
            $hash_map[ $key ] = $hash;
            $idx++;
        }

        $translated_dict = TranslatorAPI::translate( $dict, $source_lang, $target_lang, $engine );
        if ( is_wp_error( $translated_dict ) ) {
            return $translated_dict;
        }

        $stats          = TranslatorAPI::get_last_stats();
        $provider_label = (string) ( $stats['provider_label'] ?? strtoupper( $engine ) );
        $time_sec       = (float) ( $stats['time_sec'] ?? 0.0 );

        // Защита от массового возврата оригиналов при полной блокировке всех бесплатных каналов GTX
        if ( 'gtx' === $engine && 'Google GTX (Fallback)' === $provider_label ) {
            return new \WP_Error(
                'plu_scat_gtx_blocked',
                __( 'Все бесплатные каналы Google Translate (clients5, gtx, MyMemory) временно недоступны или ограничили лимит. Попробуйте позже или переключитесь на Gemini AI.', 'polyland-up' )
            );
        }

        // 3. Запись переводов в БД сразу для всех совпадающих записей
        $new_hashes        = [];
        $translated_unique = 0;
        $skipped_empty     = 0;
        $updated_rows      = 0;
        $samples           = [];

        foreach ( $hash_map as $key => $hash ) {
            $item            = $batch_items[ $hash ];
            $translated_text = isset( $translated_dict[ $key ] ) ? trim( (string) $translated_dict[ $key ] ) : '';

            // Обязательно помечаем хэш как пройденный — гарантия отсутствия бесконечного цикла
            $new_hashes[] = $hash;

            if ( '' === $translated_text ) {
                $skipped_empty++;
                continue;
            }

            $translated_unique++;
            $item_updated_rows = 0;

            foreach ( $tables as $tbl ) {
                $tbl_updated = 0;

                foreach ( array_keys( $item['raw_variants'] ) as $raw_variant ) {
                    if ( 'empty_only' === $overwrite_mode ) {
                        $update_sql = $wpdb->prepare(
                            "UPDATE `{$tbl}` SET `{$dst_col}` = %s WHERE `{$src_col}` = %s AND (`{$dst_col}` IS NULL OR TRIM(`{$dst_col}`) = '')",
                            $translated_text,
                            (string) $raw_variant
                        );
                    } else {
                        $update_sql = $wpdb->prepare(
                            "UPDATE `{$tbl}` SET `{$dst_col}` = %s WHERE `{$src_col}` = %s",
                            $translated_text,
                            (string) $raw_variant
                        );
                    }

                    $res = $wpdb->query( $update_sql );
                    if ( is_int( $res ) && $res > 0 ) {
                        $tbl_updated += $res;
                    }
                }

                // Страховка по конкретным Id на случай расхождения коллаций или пробелов в MySQL
                if ( 0 === $tbl_updated && ! empty( $item['ids_by_tbl'][ $tbl ] ) ) {
                    $ids          = array_values( array_unique( array_map( 'intval', $item['ids_by_tbl'][ $tbl ] ) ) );
                    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

                    if ( 'empty_only' === $overwrite_mode ) {
                        $id_sql = $wpdb->prepare(
                            "UPDATE `{$tbl}` SET `{$dst_col}` = %s WHERE `Id` IN ({$placeholders}) AND (`{$dst_col}` IS NULL OR TRIM(`{$dst_col}`) = '')",
                            array_merge( [ $translated_text ], $ids )
                        );
                    } else {
                        $id_sql = $wpdb->prepare(
                            "UPDATE `{$tbl}` SET `{$dst_col}` = %s WHERE `Id` IN ({$placeholders})",
                            array_merge( [ $translated_text ], $ids )
                        );
                    }

                    $id_res = $wpdb->query( $id_sql );
                    if ( is_int( $id_res ) && $id_res > 0 ) {
                        $tbl_updated += $id_res;
                    }
                }

                $item_updated_rows += $tbl_updated;
            }

            if ( 'overwrite_all' === $overwrite_mode && 0 === $item_updated_rows ) {
                $item_updated_rows = (int) $item['row_count'];
            }

            $updated_rows += $item_updated_rows;

            if ( count( $samples ) < 3 ) {
                $samples[] = [
                    'sku'     => $item['first_sku'],
                    'src'     => mb_strimwidth( $item['send_text'], 0, 55, '…', 'UTF-8' ),
                    'dst'     => mb_strimwidth( $translated_text, 0, 55, '…', 'UTF-8' ),
                    'matches' => max( $item_updated_rows, (int) $item['row_count'] ),
                ];
            }
        }

        // 4. Расчет реального прогресса текущей подзадачи для прогресс-бара
        $subjob_done_rows = self::calculate_subjob_done_rows(
            $tables,
            $src_col,
            $dst_col,
            $overwrite_mode,
            $cur_table_idx,
            $cur_last_id,
            $task_total,
            $table_counts,
            $finished
        );

        $saved_requests = max( 0, $updated_rows - $translated_unique );

        return [
            'finished'          => $finished,
            'next_table_index'  => $cur_table_idx,
            'next_last_id'      => $cur_last_id,
            'new_hashes'        => $new_hashes,
            'translated_unique' => $translated_unique,
            'skipped_empty'     => $skipped_empty,
            'skipped_duplicate' => $skipped_dups,
            'updated_rows'      => $updated_rows,
            'saved_requests'    => $saved_requests,
            'subjob_done_rows'  => $subjob_done_rows,
            'provider_label'    => $provider_label,
            'time_sec'          => $time_sec,
            'samples'           => $samples,
        ];
    }

    /**
     * Формирование безопасного SQL-условия отбора строк (колонки валидируются по белому списку).
     */
    private static function build_where_clause( string $src_col, string $dst_col, string $overwrite_mode ): string {
        $base = "`{$src_col}` IS NOT NULL AND TRIM(`{$src_col}`) != ''";
        if ( 'empty_only' === $overwrite_mode ) {
            return $base . " AND (`{$dst_col}` IS NULL OR TRIM(`{$dst_col}`) = '')";
        }
        return $base;
    }

    /**
     * Монотонный расчет выполненного объема строк текущей подзадачи.
     *
     * @param array<string> $tables
     * @param array<int>    $table_counts
     */
    private static function calculate_subjob_done_rows(
        array $tables,
        string $src_col,
        string $dst_col,
        string $overwrite_mode,
        int $cur_table_idx,
        int $cur_last_id,
        int $task_total,
        array $table_counts,
        bool $finished
    ): int {
        if ( $finished ) {
            return $task_total;
        }

        global $wpdb;
        $where = self::build_where_clause( $src_col, $dst_col, $overwrite_mode );

        $scanned_done = 0;
        for ( $i = 0; $i < $cur_table_idx && $i < count( $tables ); $i++ ) {
            $scanned_done += $table_counts[ $i ] ?? 0;
        }

        if ( isset( $tables[ $cur_table_idx ] ) && $cur_last_id > 0 ) {
            $cur_tbl         = $tables[ $cur_table_idx ];
            $cur_tbl_total   = $table_counts[ $cur_table_idx ] ?? 0;
            $after_cursor    = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$cur_tbl}` WHERE `Id` > %d AND {$where}",
                    $cur_last_id
                )
            );
            $scanned_done += max( 0, $cur_tbl_total - $after_cursor );
        }

        if ( 'empty_only' === $overwrite_mode && $task_total > 0 ) {
            $remaining_empty = 0;
            foreach ( $tables as $tbl ) {
                $remaining_empty += (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tbl}` WHERE {$where}" );
            }
            $by_remaining = max( 0, $task_total - $remaining_empty );
            return min( $task_total, max( $scanned_done, $by_remaining ) );
        }

        return $task_total > 0 ? min( $task_total, $scanned_done ) : $scanned_done;
    }
}
