<?php

use Glpi\Plugin\Hooks;

define('PLUGIN_GEOLOCATION_VERSION', '1.0.0');
define('PLUGIN_GEOLOCATION_MIN_GLPI', '10.0.0');
define('PLUGIN_GEOLOCATION_MAX_GLPI', '11.99.99');

function plugin_init_geolocation() {
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['geolocation'] = true;

    // Add tab to Computer
    Plugin::registerClass('PluginGeolocationComputer', ['addtabon' => ['Computer']]);

    // Hook: after agent inventory to auto-resolve location
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['geolocation'] = [
        'Computer' => 'plugin_geolocation_item_update',
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['geolocation'] = [
        'Computer' => 'plugin_geolocation_item_add',
    ];

    // Config page
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['geolocation'] = 'front/config.php';
}

function plugin_version_geolocation() {
    return [
        'name'           => 'Geolocation',
        'version'        => PLUGIN_GEOLOCATION_VERSION,
        'author'         => 'Custom',
        'license'        => 'GPLv3',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_GEOLOCATION_MIN_GLPI,
                'max' => PLUGIN_GEOLOCATION_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_geolocation_check_prerequisites() {
    return true;
}

function plugin_geolocation_check_config($verbose = false) {
    return true;
}
