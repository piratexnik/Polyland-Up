# Технический паспорт: Polylang Divi 5 AI Auto-Translator & Same Slug

## 1. Общие сведения
- **Название:** Polylang Divi 5 AI Auto-Translator & Same Slug
- **Slug плагина:** `Polyland-Up`
- **Главный файл:** `Polyland-Up.php`
- **Пространство имен (Namespace):** `PolylangUp\` (директория `includes/`)
- **Префикс функций и опций:** `plu_`
- **Префикс констант:** `PLU_`
- **Текущая версия:** `1.2`
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
│   │   └── admin.css         # Стили статусов, бейджей и админ-панели
│   └── js/
│       └── admin.js          # AJAX-тестирование подключения к API перевода
└── includes/
    ├── Core/
    │   ├── Init.php          # Первичная инициализация компонентов ядра
    │   ├── Settings.php      # Settings API, экран настроек, AJAX-тест API
    │   └── Updater.php       # Автономный механизм обновлений с GitHub piratexnik/Polyland-Up
    └── Modules/
        ├── SameSlug.php      # Модуль одинаковых URL-слагов между языками (Этап 2)
        ├── MetaboxUI.php     # Метабокс в сайдбаре редактора и клонирование (Этап 3)
        ├── DiviParser.php    # Рекурсивный парсер блоков Divi 5, шорткодов и Rank Math (Этап 4)
        ├── TranslatorAPI.php # Шлюз Google Translate GTX и Gemini API (Этап 5)
        └── LinkRewriter.php  # Умная перелинковка внутренних ссылок (Этап 6)
```

### Константы плагина
- `PLU_VERSION` — '1.2'
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
| `gemini_model` | string | `'gemini-2.5-flash'` | Рабочая модель Gemini (`gemini-2.5-flash`, `gemini-2.5-pro`, `gemini-2.0-flash` или кастомная). |
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

## 5. Тестирование API перевода (AJAX)
На странице настроек плагина доступна кнопка **«🔌 Проверить соединение с API»**, отправляющая AJAX-запрос `wp_ajax_plu_test_api` с проверкой прав `manage_options` и Nonce:
- **Для GTX:** проверяется ответ эндпоинта Google Translate с тестовой фразой `"Connection successful"`, измеряется задержка ответа в миллисекундах.
- **Для Gemini API:** отправляется запрос `models/{model}:generateContent` с минимальным JSON-payload. При успехе возвращается перевод и время отклика модели, при ошибке (400, 403, 404, 429) парсится `error.message` Google API и выводится понятная причина на русском языке.
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
3. **Разрешение URL на фронтенде (`resolve_post_by_path_and_lang`):**
   - Обходит ограничение ядра `get_page_by_path()`, пошагово разрешая сегменты иерархического пути (`about`, `catalog/trays`) строго в рамках текущего языка.
   - Интегрируется в хуки `request`, `parse_query` (приоритет 1), `posts_join` / `posts_where` и `url_to_postid`.
4. **Защита от ложных редиректов:**
   - Фильтры `pll_check_canonical_url` и `redirect_canonical` блокируют сброс языкового префикса или редирект на чужой язык при открытии корректно локализованной страницы.
