<?php

namespace EPFL\Menus\CLI;

if (! defined( 'ABSPATH' )) {
    die( 'Access denied.' );
}

use \WP_CLI;
use \WP_CLI_Command;

require_once(__DIR__ . '/lib/i18n.php');
use function EPFL\I18N\___;

require_once(__DIR__ . '/epfl-menus.php');
use \EPFL\Menus\ExternalMenuItem;

require_once(__DIR__ . '/lib/pubsub.php');
use function \EPFL\Pubsub\ping_all_subscribers;

function log_success ($details = NULL) {
    if ($details) {
        WP_CLI::log(sprintf('✓ %s', $details));
    } else {
        WP_CLI::log('✓');
    }
}

function log_failure ($details = NULL) {
    if ($details) {
        WP_CLI::log(sprintf('\u001b[31m✗ %s\u001b[0m', $details));
    } else {
        WP_CLI::log(sprintf('\u001b[31m✗\u001b[0m'));
    }
}

class EPFLMenusCLICommand extends WP_CLI_Command
{
    public static function hook () {
        $class = get_called_class();
        WP_CLI::add_command('epfl menus refresh', [$class, 'refresh' ]);
        WP_CLI::add_command('epfl menus add-external-menu-item', [$class, 'add_external_menu_item' ]);
    }

    public function refresh () {
        WP_CLI::log(___('Enumerating menus on filesystem...'));
        $local = ExternalMenuItem::load_from_inventory();
        WP_CLI::log(sprintf(___('... Success, found %d local menus'),
                            count($local)));

        WP_CLI::log(___('Enumerating menus in config file...'));
        $local = ExternalMenuItem::load_from_config_file();
        if ($local === NULL) {
            WP_CLI::log(sprintf(___('... Not found')));
        } else {
            WP_CLI::log(sprintf(___('... Success, found %d site-configured menus'),
                                count($local)));
        }

        $all = ExternalMenuItem::all();
        WP_CLI::log(sprintf(___('Refreshing %d instances...'),
                            count($all)));
        foreach ($all as $emi) {
            try {
                $emi->refresh();
                log_success($emi);
            } catch (\Throwable $t) {
                log_failure($emi);
            }
        }

        WP_CLI::log(sprintf(___('Pinging and expiring subscribers...')));

        $successes = 0;
        $notfounds = 0;
        $errors    = 0;

        ping_all_subscribers(
            /* $success = */ function($sub) use (&$successes) {
                log_success();
                $successes++;
            },
            /* $fail = */ function($sub, $exn) use (&$notfounds, &$errors) {
                log_failure($exn->http_code);
                if ($exn->http_code === 404) {
                    $notfounds++;
                } else {
                    $errors++;
                }
            }
        );

        WP_CLI::log(sprintf(___('%d subscriber(s) updated, %d 404 error(s) (dropped), %d other error(s)'),
                            $successes, $notfounds, $errors));
    }

    /**
     * @example wp epfl menus add-external-menu-item --menu-location-slug=top urn:epfl:labs "laboratoires"
     */
    public function add_external_menu_item ($args, $assoc_args) {
        list($urn, $title) = $args;

        $menu_location_slug = $assoc_args['menu-location-slug'];
        if (!empty($menu_location_slug)) $menu_location_slug = "top";

        # todo: check that params is format urn:epfl
        WP_CLI::log(___('Add a new external menu item...'));

        $external_menu_item = ExternalMenuItem::get_or_create($urn);
        $external_menu_item->set_title($title);
        $external_menu_item->meta()->set_remote_slug($menu_location_slug);
        $external_menu_item->meta()->set_items_json('[]');

        WP_CLI::log(sprintf(___('External menu item ID %d...'),$external_menu_item->ID));
    }
}

EPFLMenusCLICommand::hook();
