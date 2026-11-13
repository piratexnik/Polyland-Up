# Технический паспорт: Polylang Divi 5 AI Auto-Translator & Same Slug

## 1. Общие сведения
- **Название:** Polylang Divi 5 AI Auto-Translator & Same Slug
- **Slug плагина:** `Polyland-Up`
- **Главный файл:** `Polyland-Up.php`
- **Пространство имен (Namespace):** `PolylangUp\` (директория `includes/`)
- **Префикс функций и опций:** `plu_`
- **Префикс констант:** `PLU_`
- **Текущая версия:** `2.0`
- **Минимальная версия PHP:** `8.1`
- **Минимальная версия WordPress:** `6.5`
- **Репозиторий GitHub:** `https://github.com/piratexnik/Polyland-Up`

---

## 2. Архитектура и структура файлов
```
Polyland-Up/
├── AGENTS.md                 # Строгие правила разработки и протокол релизов
├── DOCS.md                   # Технический паспорт проекта
├── Polyland-Up.php           # Точка входа, константы, автозагрузчик, plu_get_option()
├── assets/
│   ├── css/
│   │   └── admin.css         # Стили статусов, бейджей, метабокса и лога перевода
│   └── js/
│       └── admin.js          # AJAX-тестирование API и последовательный конвейер дубликатора
└── includes/
    ├── Core/
    │   ├── Init.php          # Первичная инициализация компонентов ядра
    │   ├── Settings.php      # Settings API, меню «Polylang AI», AJAX-тест API
    │   └── Updater.php       # Автономный механизм обновлений с GitHub piratexnik/Polyland-Up
    └── Modules/
        ├── SameSlug.php      # Модуль одинаковых URL-слагов между языками (Этап 2)
        ├── MetaboxUI.php     # Метабокс в сайдбаре редактора и клонирование (Этап 3)
        ├── DiviParser.php    # Рекурсивный парсер блоков Divi 5, шорткодов и Rank Math (Этап 4)
        ├── TranslatorAPI.php # Шлюз Google Translate GTX и Gemini API (Этап 5)
        ├── LinkRewriter.php  # Умная перелинковка внутренних ссылок (Этап 6)
        └── MenuDuplicator.php # AI Дубликатор и Переводчик Меню WordPress / Polylang
```

### Константы плагина
- `PLU_VERSION` — '2.0'
- `PLU_FILE` — абсолютный путь к `Polyland-Up.php`
- `PLU_PATH` — путь к корневой папке плагина с завершающим слешем
- `PLU_URL` — URL к корневой папке плагина с завершающим слешем
- `PLU_BASENAME` — базовое имя плагина (`Polyland-Up/Polyland-Up.php`)

---

## 3. База данных и опции (`plu_settings`)
Все настройки хранятся в единственной опции `plu_settings` (сериализованный массив) в таблице `wp_options`.
Чтение настроек осуществляется через глобальный хелпер:
```php
plu_get_option( string $key, mixed $default = '' ): mixed
```
Значения статически кешируются в RAM процесса и автоматически инвалидируются при экшене `update_option_plu_settings`.

| Ключ опции | Тип | По умолчанию | Описание |
| :--- | :--- | :--- | :--- |
| `enable_same_slug` | int (0/1) | `1` | Разрешить идентичные URL-слаги (Same Slug) между языками (например, `/en/about/` и `/ru/about/`). |
| `default_translator` | string | `'gtx'` | Движок перевода по умолчанию: `gtx` (Google Translate Free) или `gemini` (Google AI Studio). |
| `gemini_api_key` | string | `''` | API-ключ Gemini от Google AI Studio. |
| `gemini_model` | string | `'gemini-2.5-flash'` | Рабочая модель Gemini (`gemini-3.8-flash`, `gemini-2.5-flash`, `gemini-2.5-pro` или кастомная). |
| `system_prompt` | string | *(SEO промпт)* | Системный промпт и глоссарий для качественного сохранения HTML, шорткодов Divi 5 и переменных Rank Math. |
| `github_token` | string | `''` | GitHub Personal Access Token для авторизации запросов к приватным репозиториям. |

---

## 4. Автономный механизм обновлений (`Updater.php`)
1. **Чтение локальной версии:** Метод `Updater::get_local_version()` считывает первые 8192 байта файла `PLU_FILE` напрямую с диска, исключая устаревший кеш `get_plugin_data()` и значения в RAM.
2. **Проверка удаленной версии:**
   - Прямой запрос к `https://raw.githubusercontent.com/piratexnik/Polyland-Up/main/Polyland-Up.php`.
   - Результат кешируется в transient `plu_github_update_check` на 12 часов.
   - При сбое сети или HTTP-ошибке создается маркер ошибки `plu_github_update_error` на 10 минут, предотвращая торможение админки.
3. **Пакет скачивания:**
   - Публичный репозиторий: архив релизного тега `.../archive/refs/tags/v{remote_version}.zip`.
   - Приватный репозиторий: API zipball `api.github.com/repos/piratexnik/Polyland-Up/zipball/v{remote_version}` с передачей `Authorization: token {token}` и `Accept: application/vnd.github+json` через фильтр `http_request_args`.
4. **Фильтры WordPress:**
   - `pre_set_site_transient_update_plugins`: сохраняет чужие ключи в `checked`, проверяет версию и наполняет `response` или `no_update`.
   - `site_transient_update_plugins`: мгновенное снятие бейджа обновления на чтении без HTTP-запросов, если версия на диске `>=` удаленной.
   - `upgrader_source_selection`: переименование распакованной GitHub-папки в канонический slug `Polyland-Up`.
   - `plugins_api`: предоставление карточки плагина в модальном окне «Детали».
5. **Действия в списке плагинов и ручная проверка:**
   - Фильтр `plugin_action_links`: быстрая ссылка «Настройки» слева от кнопки деактивации.
   - Ссылка «🔄 Проверить обновления» в `plugin_row_meta` с nonce-защитой.
   - Сброс transient-кешей, принудительный запрос к GitHub, обновление системного транзиента и цветные уведомления (`notice-success`, `notice-warning`, `notice-error`).
   - При сбое запроса к GitHub сохраняется и выводится точная диагностика: HTTP-код ошибки или текст сетевого сбоя и запрашиваемый URL.

---

## 5. Тестирование API перевода (AJAX) и каскад GTX
На странице настроек (пункт главного меню **«Polylang AI»**, `admin.php?page=polylang-up`, позиция 81) доступна кнопка **«🔌 Проверить соединение с API»**, отправляющая AJAX-запрос `wp_ajax_plu_test_api` с проверкой прав `manage_options` и Nonce:
- **Для GTX (каскад из 3 бесплатных каналов с браузерным User-Agent):**
  1. `clients5.google.com/translate_a/t?client=dict-chrome-ex` (Google GTX / clients5).
  2. `translate.googleapis.com/translate_a/single?client=gtx` (Google GTX / gtx).
  3. `api.mymemory.translated.net/get` (MyMemory API Fallback).
  При HTTP 429 или ошибке канала система автоматически переключается на следующий узел и выводит канал, через который прошел ответ, и задержку в миллисекундах.
- **Для Gemini API:** отправляется запрос `v1beta/models/{model}:generateContent` с передачей ключа строго в заголовке `x-goog-api-key` (поддержка новых ключей `AQ.Ab...` и `AIza...`) и очисткой префикса `models/`. При успехе возвращается перевод и время отклика модели, при ошибке (400, 403, 404, 429) парсится реальный `error.message` Google API и выводится понятная причина без двойного экранирования.
- **Загрузка доступных моделей:** кнопка **«📋 Загрузить доступные модели по ключу»** (`wp_ajax_plu_fetch_gemini_models`) запрашивает `v1beta/models?pageSize=100` с заголовком `x-goog-api-key`, фильтрует модели по `generateContent` (исключая `embedding`, `imagen`, `veo`, `audio`, `tts`, `aqa`), обновляет `<datalist>` и выводит кликабельные чипсы для быстрой подстановки в поле модели.
- Проверка работает по текущим значениям полей на форме даже до сохранения настроек в базу данных.

---

## 6. Модуль одинаковых URL-слагов (`SameSlug.php`)
Активируется при наличии Polylang (`function_exists('pll_languages_list')`) и включенной опции `enable_same_slug`.
1. **Определение целевого языка (`get_target_post_language`):**
   - Приоритет 1: Статическое свойство `SameSlug::$forced_lang` (для программного клонирования).
   - Приоритет 2: POST/GET параметры админки Polylang (`post_lang_choice`, `inline_lang_choice`, `new_lang`, `lang`).
   - Приоритет 3: Существующий язык записи (`pll_get_post_language( $post_ID, 'slug' )`).
   - Приоритет 4: Текущий язык Polylang или язык по умолчанию.
2. **Генерация уникального слага (`wp_unique_post_slug`):**
   - Проверяет занятость `post_name` только среди записей с тем же `term_taxonomy_id` языка в таблице `wp_term_relationships`.
   - Сохраняет чистый `$original_slug` для переводов на других языках без суффикса `-2`.
3. **Разрешение URL на фронтенде (`filter_request` и `resolve_post_by_path_and_lang`):**
   - Работает исключительно на хуке `request` главного запроса браузера, не затрагивая внутренние `WP_Query` шаблонов Divi 5 Theme Builder (`et_header_layout`, `et_body_layout`, `et_footer_layout`, `et_pb_layout`, `et_theme_builder`).
   - Определяет язык строго по первому сегменту `$_SERVER['REQUEST_URI']` (или `pll_default_language`), разрешает иерархический путь и заменяет `pagename`/`name` на точный `page_id` / `p` (с очисткой `pagename` и `name`).
   - Дополнительно корректирует `url_to_postid`.
4. **Защита от ложных редиректов:**
   - Фильтры `pll_check_canonical_url` и `redirect_canonical` блокируют сброс языкового префикса или редирект на чужой язык при открытии корректно локализованной страницы.

---

## 7. Метабокс редактора и клонирование (`MetaboxUI.php`)
Регистрирует метабокс `plu_ai_duplicator_box` в правой колонке (`side`, `high`) для всех переводимых типов записей Polylang (`post`, `page`, CPT).
1. **Интерфейс (`render_metabox`):**
   - Отображает текущий язык записи и список всех целевых языков со статусом (`Нет перевода` / `Уже есть: #ID` + быстрые ссылки редактирования и просмотра).
   - Поддерживает подтверждение перезаписи (`overwrite_existing`), выбор режима (`clone_only`, `translate_gtx`, `translate_gemini`), сохранение оригинального слага (`keep_same_slug`), перелинковку (`rewrite_links`) и статус новой записи (`draft`/`publish`).
2. **Последовательный AJAX-конвейер (`wp_ajax_plu_duplicate_post`):**
   - Клиент (`admin.js`) отправляет запросы по одному языку за раз, отображая живой прогресс и итоговые кнопки («✏️ Редактор WP», «🎨 Divi Builder», «👁 Открыть страницу»).
   - Определяет переведенного родителя (`post_parent`) и фиксирует `SameSlug::$forced_lang` на время вставки/обновления записи.
   - Оборачивает `post_content` (а также `post_title`, `post_excerpt` и мета-значения) в `wp_slash()`, сохраняя JSON-экранирование блоков Divi 5.
   - Привязывает язык и группу переводов Polylang (`pll_set_post_language`, `pll_save_post_translations`).
   - Копирует мета-поля (Divi, Rank Math, `_thumbnail_id`, `_wp_page_template`), исключая `_edit_lock`, `_edit_last`, `_wp_old_slug`, `_wp_old_date` и кеш стилей Divi (`_et_pb_static_css_file`, `_et_core_cached_shortcodes`, `_et_dynamic_cached_shortcodes`, `_et_builder_module_features_cache`).
   - Синхронизирует все таксономии с автоматической подстановкой переведенных термов (`pll_get_term`).
   - Вызывает `DiviParser::translate_post_data()` при выборе `translate_gtx` или `translate_gemini`, перезаписывает переведенные SEO-метаполя Rank Math и возвращает сводку статистики перевода (`translated_count`, `rewritten_links_count`, `provider_label`, `time_sec`, `translation_summary`) в лог интерфейса.
   - Оборачивает AJAX-обработчик в `try / catch (\Throwable)` для вывода точной PHP-ошибки вместо обезличенного `HTTP 500`, а также сохраняет и восстанавливает `PLL()->curlang` в блоке `finally`, предотвращая переключение языка админки и ложные срабатывания Rank Math о смене `home_url()`.

---

## 8. Парсер и сборщик контента (`DiviParser.php`)
Обеспечивает единый конвейер извлечения плоского словаря (`k_0`, `k_1`, ...) и обратной сборки записи через `translate_post_data()`, `extract_post_dictionary()` и `apply_translated_dictionary()`:
1. **Заголовки и Rank Math SEO:**
   - Извлекает `post_title`, `post_excerpt` и метаполя `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword` (пропуская строки, состоящие исключительно из технических переменных `%title% %sep% %sitename%`).
2. **Divi 5 (Gutenberg JSON-блоки):**
   - Использует `parse_blocks()` и `serialize_blocks()` с предварительной очисткой битых UTF-8 последовательностей и сохранением экранирования `\"` через `wp_slash()` на этапе сохранения поста.
   - Полностью игнорирует `core/code` и технические ветки атрибутов (`decoration`, `advanced`, `builderVersion`, `css`, `meta`, `font`, `background`, `border`, `spacing`, `link`, `adminLabel`, `socialNetwork`, `modulePreset` и др.), **за исключением** панели `attributes` (внутри `decoration`/`advanced`): если элемент содержит `attributeName` (`title`, `alt`, `aria-label`, `placeholder`, `data-title`, `data-caption`, `label`) и `attributeValue`, значение переводится и подставляется обратно.
   - Извлекает строки из `innerContent -> {desktop|tablet|phone} -> {value|hover}` и белого списка ключей (`text`, `title`, `heading`, `subhead`, `buttonText`, `alt`, `titleText`, `caption`, `description`, `label`, `placeholder` и др.), а также из `innerContent` / `innerHTML` без дублирования запросов.
3. **HTML, `divi/code`, `<img alt/title>` и Divi 4 шорткоды:**
   - Линейный $O(N)$ сканер `split_protected_html_blocks()` изолирует `<script>`, `<style>`, `<svg>` и HTML-комментарии `<!-- ... -->` без риска переполнения `pcre.backtrack_limit` на больших CSS/JS-блоках; все `preg_replace_callback()` защищены фоллбэком на исходную строку.
   - Для блоков `divi/code`, `divi/fullwidth-code`, `core/html` и крупных HTML-фрагментов (>800 байт) применяет поузловой токенизатор: переводит текстовые узлы и атрибуты `alt`/`title`/`placeholder`/`aria-label` раздельно без превышения лимита GTX и без искажения HTML-структуры.
   - Посимвольный токенизатор шорткодов `[et_pb_...]` переводит разрешенные атрибуты (`title`, `button_text`, `subhead`, `alt`, `heading` и др.) с безопасным экранированием `"`, `[`, `]`, игнорируя содержимое `[et_pb_code]` и `[et_pb_fullwidth_code]`.
4. **Строгая фильтрация (`is_translatable_string`):**
   - Отсекает динамические переменные Divi (`$variable(...)`, `@ET-DC@...`), URL-ы, пути, домены, имена файлов, CSS-значения/цвета/единицы, JSON-строки, булевы/технические константы (`on`, `off`, `true`, `false`, `h1`–`h6`, `left`/`center`/`right`), snake_case идентификаторы и строки без букв (`\p{L}`).

---

## 9. Шлюз пакетного перевода (`TranslatorAPI.php`)
Принимает ассоциативный словарь `['k_0' => '...']`, исходный/целевой язык Polylang и движок (`gtx` или `gemini`), возвращая переведенный словарь и собирая метрики (`get_last_stats()`):
1. **Нормализация кодов языков (`normalize_lang_code`):**
   - Преобразует нестандартные слаги Polylang (`ua` -> `uk`, `jp` -> `ja`, `cz` -> `cs`, `zh-cn` -> `zh-CN` и др.) и при необходимости уточняет 2-буквенный код из локали Polylang (`PLL()->model->get_language()`).
2. **Провайдер 1 — Google GTX (`translate_via_gtx`):**
   - Защищает HTML-теги и переменные Rank Math (`%sep%`, `%sitename%`, `%title%`) числовыми токенами `[[9000]]`, `[[9001]]` и т.д. перед отправкой.
   - Упаковывает строки в батчи до ~2200 символов (до 25 элементов) через разделитель `\n[[[PLU_SEP_99]]]\n` для пакетных Google-каналов (`clients5` -> `gtx`).
   - При несовпадении сегментов или сбое переходит на поштучный 3-ступенчатый фоллбэк `Settings::translate_free_cascade()` (`clients5` -> `gtx` -> `mymemory`).
3. **Провайдер 2 — Google Gemini AI (`translate_via_gemini`):**
   - Разбивает словарь на чанки по 40 элементов и отправляет POST в `v1beta/models/{model}:generateContent` (`responseMimeType: application/json`, `temperature: 0.2`, таймаут 60 сек) с пользовательским `system_prompt` и инструкцией сохранения ключей/тегов/Rank Math переменных.
   - Повторяет запрос при HTTP 429/503, очищает возможные markdown-обертки ` ```json ` и сохраняет оригиналы при сбое отдельного ключа.

---

## 10. Умная перелинковка внутренних ссылок (`LinkRewriter.php`)
Вызывается в `MetaboxUI::duplicate_post_to_language()` при активном флаге `rewrite_links`, сохраняет/восстанавливает `PLL()->curlang` и подсчитывает количество измененных ссылок (`get_last_rewritten_count()`):
1. **Точки поиска ссылок (`rewrite_content`):**
   - Атрибуты `href="..."` внутри тегов `<a>` в HTML-контенте.
   - Ключи ссылок в JSON-атрибутах блоков Divi 5 (`url`, `linkUrl`, `buttonUrl`, `titleUrl`, `imageUrl`, `buttonOneUrl`, `buttonTwoUrl`, `logoUrl`, `readMoreUrl`), исключая медиа-контекст (`background`, `video`, `audio`).
   - Атрибуты шорткодов Divi 4 (`url`, `button_url`, `link_url`, `button_one_url`, `button_two_url`, `title_link_url`).
2. **Фильтрация (`is_rewritable_internal_url`):**
   - Пропускает динамические переменные Divi (`$variable(...)`, `@ET-DC@...`, `{`, `}`), внешние домены, пустые ссылки, `#якоря`, не-HTTP схемы (`mailto:`, `tel:`, `javascript:`), системные пути (`/wp-content/uploads/`, `/wp-admin/`, `/wp-login.php`, `/wp-json/`) и ссылки на файлы/медиа (`.jpg`, `.png`, `.svg`, `.webp`, `.pdf`, `.zip`, `.mp4` и др.).
3. **Алгоритм замены (`rewrite_url`):**
   - Сохраняет `?query` и `#fragment`, а также относительность/абсолютность исходного URL.
   - Определяет ID записи через `url_to_postid()` (с защитой от рекурсии `$in_url_to_postid` в `SameSlug`) и запасной резолвер `SameSlug::resolve_post_by_path_and_lang()`.
   - Если запись найдена и существует перевод `pll_get_post( $id, $target_lang )` — подставляет реальный `get_permalink( $translated_id )`.
   - Если перевода еще нет — переключает языковой префикс через `PLL()->links_model->switch_language_in_link()` или ручной разбор пути с учетом `pll_default_language` и `hide_default`.

