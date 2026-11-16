# Технический паспорт: Polylang Divi 5 AI Auto-Translator & Same Slug

## 1. Общие сведения
- **Название:** Polylang Divi 5 AI Auto-Translator & Same Slug
- **Slug плагина:** `Polyland-Up`
- **Главный файл:** `Polyland-Up.php`
- **Пространство имен (Namespace):** `PolylangUp\` (директория `includes/`)
- **Префикс функций и опций:** `plu_`
- **Префикс констант:** `PLU_`
- **Текущая версия:** `3.8`
- **Минимальная версия PHP:** `8.1`
- **Минимальная версия WordPress:** `6.5`
- **Репозиторий GitHub:** `https://github.com/piratexnik/Polyland-Up`

---

## 2. Архитектура и структура файлов
```
Polyland-Up/
├── AGENTS.md                 # Строгие правила разработки и протокол релизов
├── DOCS.md                   # Технический паспорт проекта
├── Polyland-Up.php           # Точка входа, константы, автозагрузчик, plu_get_option(), публичный API-мост
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
        ├── MenuDuplicator.php # AI Дубликатор и Переводчик Меню WordPress / Polylang
        ├── StringsManager.php # UI-Строки для шаблонов Divi 5 Theme Builder
        ├── LoopPagination.php # Мультиязычная пагинация и кнопки для Divi 5 Loop
        └── BulkArchiveTranslator.php # Пакетный перевод и ревизия языкового архива статей
```

### Константы плагина
- `PLU_VERSION` — '3.8'
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
| `system_prompt` | string | *(SEO промпт)* | Универсальный системный промпт для сохранения HTML, блоков/шорткодов Divi 5 и лимитов Rank Math SEO (60/160 символов). |
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
5. **Очистка мигрированных слагов (`SameSlug::normalize_clean_slug( string $slug ): string`):**
   - Удаляет языковые суффиксы и инфиксы миграторов (`-ru`, `-en`, `-uk`, `-ua`) из URL-слагов вида `article-2019-04-17-ru-2` -> `article-2019-04-17-2`, `article-2019-04-17-2-en` -> `article-2019-04-17-2`, `article-2019-04-17-uk` -> `article-2019-04-17`, сохраняя порядковый номер статьи за день (`-2`, `-3`).

---

## 7. Метабокс редактора и клонирование (`MetaboxUI.php`)
Регистрирует метабокс `plu_ai_duplicator_box` в правой колонке (`side`, `high`) для всех переводимых типов записей Polylang (`post`, `page`, CPT).
1. **Интерфейс (`render_metabox`):**
   - Отображает текущий язык записи и список всех целевых языков со статусом (`Нет перевода` / `Уже есть: #ID` + быстрые ссылки редактирования и просмотра).
   - Поддерживает подтверждение перезаписи (`overwrite_existing`), выбор режима (`clone_only`, `translate_gtx`, `translate_gemini`), сохранение и очистку оригинального слага (`keep_same_slug` через `SameSlug::normalize_clean_slug()`), перелинковку (`rewrite_links`) и статус новой записи (`draft`/`publish`).
2. **Последовательный AJAX-конвейер (`wp_ajax_plu_duplicate_post`):**
   - Клиент (`admin.js`) отправляет запросы по одному языку за раз, отображая живой прогресс и итоговые кнопки («✏️ Редактор WP», «🎨 Divi Builder», «👁 Открыть страницу»).
   - Обязательно сохраняет оригинальную историческую дату публикации записи (`post_date`, `post_date_gmt`, `'edit_date' => true`) как при создании (`wp_insert_post`), так и при перезаписи (`wp_update_post`) перевода.
   - Определяет переведенного родителя (`post_parent`) и фиксирует `SameSlug::$forced_lang` на время вставки/обновления записи.
   - Оборачивает `post_content` (а также `post_title`, `post_excerpt` и мета-значения) в `wp_slash()`, сохраняя JSON-экранирование блоков Divi 5.
   - Привязывает язык и группу переводов Polylang (`pll_set_post_language`, `pll_save_post_translations`).
   - Копирует мета-поля (Divi, Rank Math, `_thumbnail_id`, `_wp_page_template`), исключая `_edit_lock`, `_edit_last`, `_wp_old_slug`, `_wp_old_date` и кеш стилей Divi (`_et_pb_static_css_file`, `_et_core_cached_shortcodes`, `_et_dynamic_cached_shortcodes`, `_et_builder_module_features_cache`).
   - Синхронизирует все таксономии с автоматической подстановкой переведенных термов (`pll_get_term`).
   - Вызывает `DiviParser::translate_post_data()` при выборе `translate_gtx` или `translate_gemini`, перезаписывает переведенные SEO-метаполя Rank Math и возвращает сводку статистики перевода (`translated_count`, `rewritten_links_count`, `provider_label`, `time_sec`, `translation_summary`) в лог интерфейса.
   - Оборачивает AJAX-обработчик в `try / catch (\Throwable)` для вывода точной PHP-ошибки вместо обезличенного `HTTP 500`, а также сохраняет и восстанавливает `PLL()->curlang` в блоке `finally`, предотвращая переключение языка админки и ложные срабатывания Rank Math о смене `home_url()`.
3. **Сервисный метод самоперевода (`MetaboxUI::self_translate_source_post()`):**
   - Используется модулем пакетной ревизии архива (`BulkArchiveTranslator`) для перевода исходной записи на её паспортный язык Polylang после переноса оригинального текста в его родную языковую версию, с сохранением исторической даты (`edit_date => true`), очисткой слага и перелинковкой.

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
5. **Изоляция и SEO-локализация мигрированных фотогалерей (`.scat-migrated-gallery`):**
   - Линейный сканер `split_protected_html_blocks()` изолирует блоки `<div class="scat-migrated-gallery">...</div>` (с учётом вложенных `<div>`) целиком до разбора HTML, чтобы десятки `alt` и `title` фотографий не попадали в словарь перевода.
   - Метод `DiviParser::localize_migrated_gallery()` обновляет `title` (на `<a>`) и `alt` (на `<img>`) по шаблону `{Title} — фото N` (`uk`/`ru`) или `{Title} — photo N` (`en` и др.) и удаляет все `\r`/`\n` внутри блока, защищая CSS Grid от вставки `<br />` фильтром `wpautop()`.

---

## 9. Шлюз пакетного перевода (`TranslatorAPI.php`)
Принимает ассоциативный словарь `['k_0' => '...']`, исходный/целевой язык, движок (`gtx`, `gemini` или `''` — из настройки `default_translator`) и необязательный `$custom_system_prompt`, возвращая переведенный словарь и собирая метрики (`get_last_stats()` — включая `provider_label`, `translated_count`, `time_sec`, `error`):
1. **Нормализация кодов языков (`normalize_lang_code`):**
   - Преобразует нестандартные слаги Polylang (`ua` -> `uk`, `jp` -> `ja`, `cz` -> `cs`, `zh-cn` -> `zh-CN` и др.) и при необходимости уточняет 2-буквенный код из локали Polylang (`PLL()->model->get_language()`).
2. **Провайдер 1 — Google GTX (`translate_via_gtx`):**
   - Защищает HTML-теги и переменные Rank Math (`%sep%`, `%sitename%`, `%title%`) числовыми токенами `[[9000]]`, `[[9001]]` и т.д. перед отправкой.
   - Упаковывает строки в батчи до ~2200 символов (до 25 элементов) через разделитель `\n[[[PLU_SEP_99]]]\n` для пакетных Google-каналов (`clients5` -> `gtx`).
   - При несовпадении сегментов или сбое переходит на поштучный 3-ступенчатый фоллбэк `Settings::translate_free_cascade()` (`clients5` -> `gtx` -> `mymemory`).
3. **Провайдер 2 — Google Gemini AI (`translate_via_gemini`):**
   - Разбивает словарь на чанки по 90 элементов и отправляет POST в `v1beta/models/{model}:generateContent` (`responseMimeType: application/json`, `temperature: 0.1`, таймаут 90 сек, `thinkingBudget: 0` для `flash`/`hydrogen`).
   - Объединяет общий `system_prompt` из настроек, переданный `$custom_system_prompt` (если задан) и строгую JSON-инструкцию сохранения ключей/тегов/переменных Rank Math.
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

---

## 11. Публичный PHP API для интеграции со сторонними плагинами
Профильные плагины (например, `SCaT Calculator` для перевода кастомных таблиц каталога и др.) могут использовать единый шлюз перевода `Polyland-Up` через три глобальные функции-хелпера, объявленные в `Polyland-Up.php`:

1. **`plu_is_translator_ready(): bool`**
   - Возвращает `true`, если плагин `Polyland-Up` активен и его классы (`Settings`, `TranslatorAPI`) готовы к обработке запросов.
   - Рекомендуется проверять перед вызовом через `function_exists( 'plu_is_translator_ready' ) && plu_is_translator_ready()`.

2. **`plu_translate_dictionary( array $dictionary, string $source_lang, string $target_lang, string $engine = '', string $custom_prompt = '' ): array`**
   - Вызывает `PolylangUp\Modules\TranslatorAPI::translate()` и возвращает переведенный ассоциативный массив `['key' => 'translated_value']` с сохранением исходных ключей.
   - Параметр `$engine`: `'gtx'`, `'gemini'` или `''` (по умолчанию берется движок из настройки `default_translator` плагина `Polylang AI`).
   - Параметр `$custom_prompt`: необязательная доменная инструкция или отраслевой глоссарий, который добавляется к базовому `system_prompt` при работе через Google Gemini AI.
   - При критической ошибке API (например, отсутствует ключ Gemini или произошел сетевой/HTTP сбой) возвращает пустой массив `[]`, а текст ошибки сохраняется в статистике (`plu_get_translator_last_stats()['error']`).

3. **`plu_get_translator_last_stats(): array`**
   - Возвращает статистику последнего выполненного запроса на перевод из `PolylangUp\Modules\TranslatorAPI::get_last_stats()`:
     - `engine` (`string`) — фактически использованный движок (`gtx` / `gemini`);
     - `provider_label` (`string`) — название провайдера/канала или модели (например, `'Google GTX / clients5'` или `'Gemini gemini-2.5-flash'`);
     - `translated_count` (`int`) — количество переведенных ключей;
     - `total_count` (`int`) — общее количество ключей во входном словаре;
     - `time_ms` (`int`) / `time_sec` (`float`) — длительность выполнения;
     - `error` (`string`) — текст ошибки при сбое или пустая строка `''` при успехе.

### Пример интеграции в стороннем плагине
```php
if ( function_exists( 'plu_is_translator_ready' ) && plu_is_translator_ready() ) {
    $glossary = "ТЕХНИЧЕСКИЙ ГЛОССАРИЙ:\n...";
    $translated = plu_translate_dictionary(
        [ 'k_0' => 'Текст 1', 'k_1' => 'Текст 2' ],
        'ru',
        'uk',
        'gemini',
        $glossary
    );
    $stats = plu_get_translator_last_stats();

    if ( ! empty( $stats['error'] ) ) {
        // Обработка ошибки: $stats['error']
    }
}
```

---

## 12. UI-Строки для шаблонов Divi 5 Theme Builder (`StringsManager.php`)
Модуль позволяет использовать единый шаблон Divi 5 Theme Builder (архивы, циклы Loop, шапка, подвал, 404) для всех языков Polylang без дублирования шаблонов:
1. **Хранение и кеширование:**
   - Словарь хранится в опции `plu_ui_strings` (autoload) со статическим кешем в памяти (`$strings_cache`) — **0 дополнительных запросов к БД** в циклах.
   - При первой инициализации предзаполняются 10 базовых ключей (`read_more`, `home`, `published_on`, `updated_on`, `all_news`, `back_to_list`, `search_placeholder`, `no_results`, `share`, `categories`).
   - Все строки синхронизируются с нативным реестром Polylang (`pll_register_string(..., 'Polylang AI UI')` на хуке `init`).
2. **Три способа вывода:**
   - Шорткод `[plu_str id="read_more" before="" after="" default=""]` (с гарантированным раскрытием через `render_block` с приоритетом 12, `et_pb_module_content`, `et_module_shortcode_output`).
   - Виртуальные мета-ключи `plu_str_{id}` через фильтр `get_post_metadata` для `Dynamic Content -> Custom Field` в Divi 5 (работает независимо от `$object_id`, включая `0`/`null` на страницах Архивов / Категорий / Поиска, а также с фоллбэком в `render_block` для нераскрытых плейсхолдеров `$variable(...plu_str_...)$`).
   - Бонусный шорткод мультиязычной даты `[plu_date format="l, j F Y"]` через `wp_date()`.
3. **Интерфейс админки (`admin.php?page=polylang-up&tab=strings`):**
   - Динамические колонки языков через `pll_languages_list(['fields' => ''])`.
- Кнопки быстрого копирования `[plu_str id="..."]` и `plu_str_...` в буфер обмена.
   - Пакетный авто-перевод пустых ячеек через `wp_ajax_plu_translate_ui_strings` (`TranslatorAPI::translate()`) и сохранение через `wp_ajax_plu_save_ui_strings`.

---

## 13. AI Дубликатор и Переводчик Меню (`MenuDuplicator.php`)
Работает во вкладке настроек `admin.php?page=polylang-up&tab=menus` через AJAX-обработчик `wp_ajax_plu_duplicate_menu` (проверка Nonce `plu_admin_nonce` и прав `edit_theme_options` / `manage_options`):
1. **Пакетный перевод пунктов меню:**
   - Собирает названия, `attr_title` и `description` в единый словарь (исключая `#pll_switcher`) и переводит через `TranslatorAPI::translate()` (`gemini`, `gtx` или `none`).
2. **Умная подмена объектов и ссылок:**
   - Для пунктов `post_type` и `taxonomy` подставляет переведенные ID через `pll_get_post()` / `pll_get_term()`. Если перевод еще не создан — конвертирует пункт в `custom`-ссылку с перелинкованным URL через `LinkRewriter::rewrite_url()`.
   - Сохраняет иерархию подменю (`_menu_item_menu_item_parent`), классы Divi, кастомные мета-поля (через `wp_slash()`) и переключатель языков `#pll_switcher`.
3. **Авто-привязка к областям темы:**
   - Находит области темы исходного меню (в `polylang['nav_menus']` и `get_nav_menu_locations()`) и автоматически регистрирует созданное меню для целевого языка в опции `polylang`.

---

## 14. Мультиязычная Пагинация и Кнопки для Divi 5 Loop (`LoopPagination.php`)
Обеспечивает единый дизайн и корректную пагинацию для архивов и циклов Divi 5 Theme Builder:
1. **Авто-детект `per_page` и защита от 404 (`detect_divi_loop_per_page()` + `pre_get_posts`):**
   - Находит активный макет `et_body_layout` через `et_theme_builder_get_template_layouts()` или метаданные `_et_theme_builder` / `_et_template` / `_et_body_layout_id`.
   - Сканирует JSON-атрибуты блоков Divi 5 с включенным Loop (`loop -> enable -> desktop -> value === 'on'` или модули Blog/Post) и шорткоды Divi 4 на ключи `posts_per_page`, `postsPerPage`, `post_number`, `posts_number`, `postsNumber`.
   - Синхронизирует `posts_per_page` основного `WP_Query` на хуке `pre_get_posts` (с защитой от рекурсии), исключая 404 на дальних страницах (`/page/40/`, `/page/66/`).
2. **Синхронизация вторичного `WP_Query` внутри Divi 5 Loop (`pre_render_block` / `render_block` / `the_posts`):**
   - Стек блоков (`$block_stack` и флаг `$in_divi_loop_block`) отслеживает границы рендера Loop-блоков (`divi/row`, `divi/blog`, `divi/post-slider`, `divi/loop` и любых блоков с `loop.enable === 'on'`), сохраняя `per_page` и `base_offset`.
   - `get_current_page()` определяет текущую страницу из `query_var`, GET-параметров (`paged`, `et_loop_page`, `loop_page`) и `REQUEST_URI` (`/page/N/`).
   - В `pre_get_posts` для вторичных запросов Loop (исключая системные `SYSTEM_POST_TYPES`) инжектирует `paged`, пересчитывает `offset = base_offset + (paged - 1) * per_page` (т.к. числовой `offset` в WP отключает `paged`), синхронизирует `tax_query` архива и выставляет `no_found_rows = false`.
   - Фильтр `the_posts` (`capture_loop_query_stats()`) сохраняет реальные `found_posts` и `posts_per_page` отработавшего цикла Loop для точного расчета страниц в `[plu_pagination]`.
3. **Шорткоды:**
   - `[plu_pagination]` (алиас `[scat_pagination]`): поддерживает атрибуты `per_page`, `mid_size`, `end_size`, `show_arrows`, `show_status`, `align`, мультиязычные строки (`pagination_prev`, `pagination_next`, `pagination_status` из `StringsManager`) и демонстрационный макет в Divi Visual Builder. Если шорткод размещен выше цикла Loop или на последней странице архива, используется легкий фоллбэк-подсчет `count_archive_found_posts()` (с кешем на запрос) и защита `total_pages >= current_page` на страницах `/page/2+/`.
   - `[plu_read_more]`: выводит кнопку со ссылкой на текущую запись цикла Loop (`id`, `text`, `icon`, `class`) в стиле `solid`, `outline` или `link`.
4. **Zero Bloat CSS и вкладка настроек (`admin.php?page=polylang-up&tab=pagination`):**
   - Выводит компактный инлайн `<style>` с CSS-переменными (`--plu-pg-accent`, `--plu-pg-radius` и др.) однократно за запрос только при фактическом рендере шорткодов.
   - Вкладка админки содержит предупреждение о размещении шорткодов (`[plu_read_more]` внутри Loop, `[plu_pagination]` в отдельном блоке ниже), шпаргалку с копированием в 1 клик, интерактивное Live Preview и форму настроек дизайна в `plu_settings`.

---

## 15. Пакетный перевод и Ревизия архива (`BulkArchiveTranslator.php`)
Работает во вкладке `admin.php?page=polylang-up&tab=bulk_archive` и предназначен для ревизии, устранения языковой пересортицы и дозированного перевода больших архивов статей (300+ записей):
1. **Лингвистический PHP-детектор языка (`detect_real_text_language( string $title, string $content ): string`):**
   - Определяет реальный язык текста (`'uk'`, `'ru'`, `'en'`, `'cyrillic_neutral'`, `'universal'` или `'empty'`) за 0 токенов API на основе уникальных символов (`[іїєґІЇЄҐ]`, `[ыэъёЫЭЪЁ]`, латиница) и частотных словарных маркеров.
   - Предварительно вырезает защищённые бренды из опции `protected_terms` (`TM SCaT`, `SCaT`, `elcomUkraine`, `elcom`, `interBUDexpo` и др.); при наличии кириллицы никогда не помечает запись как `en`, а нейтральные заголовки из брендов и цифр возвращает как `'universal'`.
2. **Фильтрация, диагностика рубрик и связывание в 1 клик (`wp_ajax_plu_bulk_scan_category`, `wp_ajax_plu_bulk_fix_category_terms`):**
   - Позволяет сканировать рубрики (`category`), все записи (`post`) или страницы (`page`) с фильтрацией по периоду годов публикации `От — До` (`year_from`, `year_to`, с автоматической нормализацией `min/max`) и статусу проблемы (`needs_action`, `mismatch_only`, `complete`, `all`).
   - Выводит плашку статуса переводов выбранной рубрики в Polylang (`pll_get_term_translations`) и кнопку создания/связывания недостающих языковых рубрик в 1 клик.
   - Находит как связанные переводы Polylang, так и несвязанные посты-однофамильцы других языков с тем же базовым слагом `SameSlug::normalize_clean_slug()`, формируя матрицу `UK | EN | RU` (`✅`, `⚠️`, `⭕`, `❌`).
3. **Умный пакетный процессор (`wp_ajax_plu_bulk_process_post` / `process_single_post_group()`):**
   - Связывает найденные по чистому слагу посты-однофамильцы в единую группу Polylang (`pll_save_post_translations`).
   - Находит истинный источник текста: при пересортице (например, в посте `UK` лежит текст на `RU`) переносит оригинал в целевой языковой пост `RU` за **0 токенов** (`clone_only`), переводит остальные выбранные языки (`EN`) и выполняет самоперевод исходного поста (`MetaboxUI::self_translate_source_post()`) на `UK`.
   - Если пересортицы нет — переводит только отсутствующие, пустые или содержащие чужой язык посты (в режиме `smart`) либо все выбранные языки (`force_all`).
   - Очищает URL-слаги (`SameSlug::normalize_clean_slug()`), синхронизирует языковые рубрики (`pll_get_term`) и жестко сохраняет историческую дату публикации оригинала (`post_date`, `post_date_gmt`, `'edit_date' => true`).
   - Клиентская очередь в `assets/js/admin.js` поддерживает ограничитель пачки (`session_limit`: 5, 10, 20, 50, Все), регулируемую паузу между статьями (`delay_sec`: 0, 2, 4, 6 сек), кнопку «⏸ Пауза / Стоп», живое обновление строк таблицы, автоматическое/ручное скрытие выполненных (`✅`) строк с динамическим пересчётом счётчиков и кнопку очистки лога.
