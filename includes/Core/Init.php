<?php
declare(strict_types=1);

namespace PolylangUp\Core;

use PolylangUp\Modules\MenuDuplicator;
use PolylangUp\Modules\MetaboxUI;
use PolylangUp\Modules\SameSlug;
use PolylangUp\Modules\StringsManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс первичной инициализации компонентов плагина.
 */
final class Init {

	/**
	 * Точка входа в ядро плагина.
	 */
	public static function init(): void {
		$is_admin = is_admin();
		$is_cron  = defined( 'DOING_CRON' ) && DOING_CRON;
		$is_cli   = defined( 'WP_CLI' ) && WP_CLI;

		if ( $is_admin || $is_cron || $is_cli ) {
			Settings::init();
			Updater::init();
			MetaboxUI::init();
			MenuDuplicator::init();
		}

		// Модуль одинаковых слагов и UI-строк работают как в админке, так и на фронтенде
		SameSlug::init();
		StringsManager::init();
	}
}
