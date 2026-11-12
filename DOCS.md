# Технический паспорт: Polylang Up

## 1. Общие сведения
- **Название:** Polylang Up
- **Slug плагина:** `polylang-up`
- **Главный файл:** `polylang-up.php`
- **Пространство имен (Namespace):** `PolylangUp\` (директория `includes/`)
- **Префикс функций и опций:** `plu_`
- **Префикс констант:** `PLU_`
- **Текущая версия:** `1.0`
- **Минимальная версия PHP:** `8.1`
- **Минимальная версия WordPress:** `6.5`
- **Репозиторий:** `https://github.com/piratexnik/polylang-up`

---

## 2. Архитектура и структура файлов
```
polylang-up/
├── AGENTS.md                 # Строгие правила разработки и протокол релизов
├── DOCS.md                   # Технический паспорт проекта
├── polylang-up.php           # Точка входа, константы, автозагрузчик, plu_get_option()
└── includes/
    └── Core/
        ├── Init.php          # Запуск компонентов ядра (Settings, Updater)
        ├── Settings.php      # Settings API, экран опций с вкладками
        └── Updater.php       # Автономный механизм обновлений с GitHub
```

### Константы плагина
- `PLU_VERSION` — '1.0'
- `PLU_FILE` — абсолютный путь к `polylang-up.php`
- `PLU_PATH` — путь к корневой папке плагина с завершающим слешем
- `PLU_URL` — URL к корневой папке плагина с завершающим слешем
- `PLU_BASENAME` — базовое имя плагина (`polylang-up/polylang-up.php`)

---

## 3. База данных и опции
- Опция в таблице `wp_options`: `plu_settings` (сериализованный массив).
- Чтение настроек: `plu_get_option( string $key, mixed $default = '' ): mixed`.
  - Значения кешируются в статической переменной `static $options` в памяти процесса.
  - При срабатывании экшена `update_option_plu_settings` кеш сбрасывается автоматически.
- Зарегистрированные поля:
  - `github_token` (string): Personal Access Token для авторизации запросов к приватным репозиториям GitHub.

---

## 4. Механизм обновлений (Updater.php)
Плагин содержит автономный механизм обновлений без сторонних тяжелых библиотек:
1. **Чтение локальной версии:** Метод `Updater::get_local_version()` считывает первые 8192 байта `PLU_FILE` напрямую с диска, исключая устаревший кеш `get_plugin_data()` или значения в RAM.
2. **Проверка удаленной версии:**
   - Запрос к `raw.githubusercontent.com/piratexnik/polylang-up/main/polylang-up.php`.
   - Результат кешируется в transient `plu_github_update_check` на 12 часов.
   - При сбое сети или HTTP-ошибке выставляется transient `plu_github_update_error` на 10 минут во избежание замедления админки.
3. **Пакет скачивания:**
   - Публичный репозиторий: архив тега `.../archive/refs/tags/v{remote_version}.zip`.
   - Приватный репозиторий: API zipball `api.github.com/repos/.../zipball/v{remote_version}` с инъекцией заголовков `Authorization: Bearer {token}` и `Accept: application/vnd.github+json` через фильтр `http_request_args`.
4. **Фильтры WordPress:**
   - `pre_set_site_transient_update_plugins`: проверка версии и добавление в `response` или `no_update`.
   - `site_transient_update_plugins`: мгновенное снятие бейджа обновления при совпадении версий на чтении без HTTP-запросов.
   - `upgrader_source_selection`: переименование распакованной GitHub-папки в строго канонический slug `polylang-up`.
   - `plugins_api`: предоставление метаданных плагина для модального окна подробностей.
5. **Ручная проверка:**
   - Действие в списке плагинов с nonce-защитой `🔄 Проверить обновления`.
   - Сброс кеша, немедленный опрос репозитория и вывод уведомлений (`notice-success`, `notice-warning`, `notice-error`).

---

## 5. Стандарты интеграции
- **Divi 5 Builder:** проверка активности редактора через `function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled()`.
- **Page Cache:** отсутствие серверного рендера `$_COOKIE` на фронтенде; динамика только через JS/AJAX.
