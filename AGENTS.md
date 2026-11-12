# AGENTS.md — Правила разработки Polylang Divi 5 AI Auto-Translator & Same Slug

## 1. Zero Fluff & Экономия токенов
- Никаких приветствий, вводных слов и воды. Ответы строго по делу на русском языке.
- Комментарии в коде и документация — на русском или английском языке.
- Запрещено сканировать все файлы проекта без необходимости — используй Карту файлов ниже.
- При внесении правок изменяй исключительно затронутые методы и блоки кода.

## 2. Карта файлов (File Map)
- `Polyland-Up.php` — Точка входа в плагин, определение констант, PSR-4 автозагрузчик, хелпер `plu_get_option()`.
- `includes/Core/Init.php` — Инициализация ядра и компонентов (`PolylangUp\Core\Init`).
- `includes/Core/Updater.php` — Автономная система обновлений с GitHub `piratexnik/Polyland-Up` (`PolylangUp\Core\Updater`).
- `includes/Core/Settings.php` — Страница настроек и AJAX-тест подключения к API перевода (`PolylangUp\Core\Settings`).
- *(Будущие модули Этапов 2-6)*:
  - `includes/Modules/SameSlug.php` — Модуль одинаковых URL-слагов.
  - `includes/Modules/MetaboxUI.php` — Метабокс редактора и клонирование записи.
  - `includes/Modules/DiviParser.php` — Парсер блоков Divi 5 / шорткодов / Rank Math.
  - `includes/Modules/TranslatorAPI.php` — Шлюз Google Translate GTX и Gemini API.
  - `includes/Modules/LinkRewriter.php` — Умная перелинковка внутренних ссылок.
- `assets/js/admin.js`, `assets/css/admin.css` — Ассеты админки.
- `DOCS.md` — Технический паспорт проекта и архитектурная документация.

## 3. Команда «Аудит кода»
По фразе пользователя **«Аудит кода»** временно снимаются ограничения на чтение файлов для проведения полной ревизии:
- Безопасность: Nonce, Capabilities checks, Sanitization входящих данных, Escaping при выводе.
- Архитектура: соблюдение DRY, PHP 8.1 строгая типизация (`declare(strict_types=1);`, типизация свойств и аргументов).
- Совместимость: совместимость с кешированием (Page Cache), отсутствие прямого рендера кук на сервере.

## 4. Протокол версионирования (формат X.Y — два числа)
- Номер версии состоит строго из двух чисел: `X.Y` (например, `1.0`, `1.1`, `2.0`).
- Версия **НЕ** изменяется при обычных доработках и фиксах в рамках спринта.
- По команде **«Релиз 2»**:
  1. Увеличь второе число версии (`1.0` -> `1.1`).
  2. Обнови заголовок `Version:` в `Polyland-Up.php`, значение константы `PLU_VERSION` и запись в `DOCS.md`.
  3. Выполни коммит: `git add -A && git commit -m "Release vX.Y"`.
  4. Создай тег: `git tag vX.Y`.
  5. Отправь изменения в удаленный репозиторий: `git push origin main --tags`.
- По команде **«Релиз 1»**:
  1. Увеличь первое число и обнули второе (`1.1` -> `2.0`).
  2. Обнови заголовок `Version:` в `Polyland-Up.php`, константу `PLU_VERSION` и `DOCS.md`.
  3. Выполни аналогичный цикл коммита, создания тега `vX.Y` и пуша в `origin main --tags`.

## 5. Стандарты кода
- Каждый PHP-файл обязан содержать:
  ```php
  <?php
  declare(strict_types=1);

  if ( ! defined( 'ABSPATH' ) ) {
      exit;
  }
  ```
- **Проверка Polylang**: `function_exists( 'pll_current_language' ) || defined( 'POLYLANG_VERSION' )`.
- **Divi 5 Compatibility**: проверка активности конструктора Divi выполняется через:
  ```php
  function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled()
  ```
- **Page Cache Compatibility (FlyingPress, WP Rocket, LiteSpeed)**:
  - Никакого серверного рендеринга на основе `$_COOKIE` на фронтенде.
  - Динамика языков и переключения реализуется исключительно через клиентский JavaScript/AJAX/REST.
  - Условная загрузка ассетов: скрипты и стили подключаются только там, где они действительно требуются.
