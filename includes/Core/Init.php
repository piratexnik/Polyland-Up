<?php
declare(strict_types=1);

namespace PolylangUp\Core;

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
        }
    }
}
