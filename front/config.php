<?php

include('../../../inc/includes.php');

Session::checkRight('config', READ);

// ── Save config ──
if (isset($_POST['save'])) {
    Session::checkRight('config', UPDATE);
    global $DB;

    $table = 'glpi_plugin_geolocation_configs';
    $DB->update($table, [
        'auto_resolve' => intval($_POST['auto_resolve'] ?? 1),
        'api_provider' => 'ip-api',
    ], ['id' => 1]);

    Session::addMessageAfterRedirect(__('Settings saved.', 'geolocation'), true, INFO);
    Html::redirect(Plugin::getWebDir('geolocation') . '/front/config.php');
}

// ── Bulk resolve ──
if (isset($_POST['bulk_resolve'])) {
    Session::checkRight('config', UPDATE);
    global $DB;

    $agents = $DB->request([
        'FROM'  => 'glpi_agents',
        'WHERE' => ['itemtype' => 'Computer'],
    ]);

    $resolved = 0;
    $failed   = 0;

    foreach ($agents as $agent) {
        $ip = $agent['remote_addr'] ?? '';
        $cid = $agent['items_id'] ?? 0;

        if (empty($ip) || $ip === '127.0.0.1' || $ip === '::1' || !$cid) {
            continue;
        }

        // Skip already resolved
        $existing = $DB->request([
            'FROM'  => 'glpi_plugin_geolocation_computers',
            'WHERE' => ['computers_id' => $cid, 'status' => 'resolved'],
            'LIMIT' => 1,
        ]);
        if (count($existing)) {
            continue;
        }

        if (PluginGeolocationComputer::resolveFromIp($cid, $ip)) {
            $resolved++;
        } else {
            $failed++;
        }

        // ip-api.com rate limit: 45/min — wait a bit
        usleep(1500000); // 1.5s between requests
    }

    Session::addMessageAfterRedirect(
        sprintf(__('Bulk resolve complete: %d resolved, %d failed.', 'geolocation'), $resolved, $failed),
        true, INFO
    );
    Html::redirect(Plugin::getWebDir('geolocation') . '/front/config.php');
}

// ── Display ──
Html::header(
    __('Geolocation', 'geolocation'),
    $_SERVER['PHP_SELF'],
    'config',
    'PluginGeolocationComputer'
);

global $DB;
$cfg = $DB->request(['FROM' => 'glpi_plugin_geolocation_configs', 'LIMIT' => 1]);
$config = count($cfg) ? $cfg->current() : ['auto_resolve' => 1, 'api_provider' => 'ip-api'];

// Count stats
$total_computers = countElementsInTable('glpi_computers');
$total_agents    = countElementsInTable('glpi_agents', ['itemtype' => 'Computer']);
$total_resolved  = countElementsInTable('glpi_plugin_geolocation_computers', ['status' => 'resolved']);

$action = Plugin::getWebDir('geolocation') . '/front/config.php';

echo '<div class="container-lg">';

// Stats cards
echo '<div class="row g-3 mb-4">';
$stats = [
    ['ti-devices-pc', __('Computers', 'geolocation'), $total_computers, 'primary'],
    ['ti-robot',       __('Agents', 'geolocation'),    $total_agents,    'info'],
    ['ti-map-pin',     __('Located', 'geolocation'),   $total_resolved,  'success'],
    ['ti-map-pin-off', __('Pending', 'geolocation'),   max(0, $total_agents - $total_resolved), 'warning'],
];
foreach ($stats as [$icon, $label, $value, $color]) {
    echo '<div class="col-sm-6 col-lg-3">';
    echo '<div class="card"><div class="card-body">';
    echo '<div class="d-flex align-items-center">';
    echo '<span class="avatar avatar-lg bg-' . $color . '-lt me-3"><i class="ti ' . $icon . '"></i></span>';
    echo '<div><div class="fw-bold" style="font-size:1.5rem">' . $value . '</div>';
    echo '<div class="text-muted small">' . $label . '</div></div>';
    echo '</div></div></div></div>';
}
echo '</div>';

// Config + Bulk
echo '<div class="row g-3">';

// Settings card
echo '<div class="col-lg-6">';
echo '<div class="card">';
echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-settings me-2"></i>' . __('Settings', 'geolocation') . '</h3></div>';
echo '<div class="card-body">';
echo '<form method="post" action="' . $action . '">';
echo '<input type="hidden" name="_glpi_csrf_token" value="' . Session::getNewCSRFToken() . '">';

echo '<div class="mb-3">';
echo '<label class="form-label">' . __('Auto-resolve on agent inventory', 'geolocation') . '</label><div>';
Dropdown::showYesNo('auto_resolve', $config['auto_resolve'] ?? 1);
echo '</div>';
echo '<small class="form-hint">' . __('Automatically detect location when a computer reports inventory.', 'geolocation') . '</small>';
echo '</div>';

echo '<div class="mb-3">';
echo '<label class="form-label">' . __('API Provider', 'geolocation') . '</label>';
echo '<input type="text" class="form-control" value="ip-api.com (free, 45 req/min)" disabled>';
echo '<small class="form-hint">' . __('Uses IP geolocation to find approximate location.', 'geolocation') . '</small>';
echo '</div>';

echo '<button type="submit" name="save" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>' . __('Save', 'geolocation') . '</button>';
Html::closeForm();
echo '</div></div></div>';

// Bulk resolve card
echo '<div class="col-lg-6">';
echo '<div class="card">';
echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-map-search me-2"></i>' . __('Bulk Resolve', 'geolocation') . '</h3></div>';
echo '<div class="card-body">';
echo '<p class="text-muted">' . __('Resolve location for all computers that have an agent with a public IP. This may take a while due to API rate limits (~45 requests/minute).', 'geolocation') . '</p>';

echo '<form method="post" action="' . $action . '">';
echo '<input type="hidden" name="_glpi_csrf_token" value="' . Session::getNewCSRFToken() . '">';
echo '<button type="submit" name="bulk_resolve" class="btn btn-outline-primary" onclick="return confirm(\'' . __('This will resolve all pending computers. Continue?', 'geolocation') . '\')">';
echo '<i class="ti ti-map-pin me-1"></i>' . __('Resolve All Pending', 'geolocation') . '</button>';
Html::closeForm();

echo '</div></div></div>';

echo '</div>'; // row
echo '</div>'; // container

Html::footer();
