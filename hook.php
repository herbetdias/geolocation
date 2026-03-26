<?php

function plugin_geolocation_install() {
    global $DB;

    $table = 'glpi_plugin_geolocation_computers';

    if (!$DB->tableExists($table)) {
        $DB->doQuery("CREATE TABLE `$table` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `computers_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `latitude` VARCHAR(30) DEFAULT NULL,
            `longitude` VARCHAR(30) DEFAULT NULL,
            `address` TEXT DEFAULT NULL,
            `neighborhood` VARCHAR(255) DEFAULT NULL,
            `city` VARCHAR(255) DEFAULT NULL,
            `state` VARCHAR(255) DEFAULT NULL,
            `country` VARCHAR(255) DEFAULT NULL,
            `zip` VARCHAR(20) DEFAULT NULL,
            `isp` VARCHAR(255) DEFAULT NULL,
            `status` VARCHAR(20) DEFAULT 'pending',
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `computers_id` (`computers_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // Config table
    $cfg_table = 'glpi_plugin_geolocation_configs';
    if (!$DB->tableExists($cfg_table)) {
        $DB->doQuery("CREATE TABLE `$cfg_table` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `auto_resolve` TINYINT(1) NOT NULL DEFAULT 1,
            `api_provider` VARCHAR(50) DEFAULT 'ip-api',
            `api_key` VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $DB->doQuery("INSERT INTO `$cfg_table` (`auto_resolve`, `api_provider`) VALUES (1, 'ip-api')");
    }

    // Upgrade paths
    $new_columns = [
        'neighborhood' => "VARCHAR(255) DEFAULT NULL AFTER `address`",
    ];
    foreach ($new_columns as $col => $def) {
        if ($DB->tableExists($table) && !$DB->fieldExists($table, $col)) {
            $DB->doQuery("ALTER TABLE `$table` ADD `$col` $def");
        }
    }

    return true;
}

function plugin_geolocation_uninstall() {
    global $DB;

    foreach (['glpi_plugin_geolocation_computers', 'glpi_plugin_geolocation_configs'] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    return true;
}

/**
 * Hook: triggered when a Computer is updated (e.g., by agent inventory)
 */
function plugin_geolocation_item_update(Computer $item) {
    plugin_geolocation_resolve_for_computer($item->getID());
}

function plugin_geolocation_item_add(Computer $item) {
    plugin_geolocation_resolve_for_computer($item->getID());
}

/**
 * Resolve geolocation for a computer by finding its agent IP.
 */
function plugin_geolocation_resolve_for_computer($computers_id) {
    global $DB;

    // Check if auto_resolve is enabled
    $cfg = $DB->request([
        'FROM'  => 'glpi_plugin_geolocation_configs',
        'LIMIT' => 1,
    ]);
    $config = count($cfg) ? $cfg->current() : null;
    if (!$config || !$config['auto_resolve']) {
        return;
    }

    // Get agent IP for this computer
    $agents = $DB->request([
        'FROM'  => 'glpi_agents',
        'WHERE' => [
            'itemtype' => 'Computer',
            'items_id' => $computers_id,
        ],
        'LIMIT' => 1,
    ]);
    if (!count($agents)) {
        return;
    }
    $agent = $agents->current();
    $ip = $agent['remote_addr'] ?? '';

    if (empty($ip) || $ip === '127.0.0.1' || $ip === '::1') {
        return;
    }

    // Check if already resolved with same IP
    $existing = $DB->request([
        'FROM'  => 'glpi_plugin_geolocation_computers',
        'WHERE' => ['computers_id' => $computers_id],
        'LIMIT' => 1,
    ]);
    if (count($existing)) {
        $row = $existing->current();
        if ($row['ip_address'] === $ip && $row['status'] === 'resolved') {
            return; // already resolved, same IP
        }
    }

    // Resolve via API (async would be better, but for now sync)
    PluginGeolocationComputer::resolveFromIp($computers_id, $ip);
}
