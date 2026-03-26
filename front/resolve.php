<?php

include('../../../inc/includes.php');

Session::checkRight('computer', READ);

$computers_id = intval($_POST['computers_id'] ?? 0);
if (!$computers_id) {
    Html::redirect('/front/computer.php');
}

// ── Resolve location from agent IP ──
if (isset($_POST['resolve'])) {
    global $DB;

    // Get agent IP
    $agents = $DB->request([
        'FROM'  => 'glpi_agents',
        'WHERE' => [
            'itemtype' => 'Computer',
            'items_id' => $computers_id,
        ],
        'LIMIT' => 1,
    ]);

    if (count($agents)) {
        $agent = $agents->current();
        $ip = $agent['remote_addr'] ?? '';

        if (!empty($ip) && $ip !== '127.0.0.1' && $ip !== '::1') {
            if (PluginGeolocationComputer::resolveFromIp($computers_id, $ip)) {
                Session::addMessageAfterRedirect(
                    __('Location resolved successfully from IP: ', 'geolocation') . $ip,
                    true, INFO
                );
            } else {
                Session::addMessageAfterRedirect(
                    __('Failed to resolve location. The geolocation API may be unavailable.', 'geolocation'),
                    false, ERROR
                );
            }
        } else {
            Session::addMessageAfterRedirect(
                __('No valid IP found for this computer\'s agent (IP is localhost or empty).', 'geolocation'),
                false, WARNING
            );
        }
    } else {
        Session::addMessageAfterRedirect(
            __('No GLPI agent found for this computer. Make sure the agent is installed and has reported.', 'geolocation'),
            false, WARNING
        );
    }
}

// ── Reverse geocode (get street from coordinates via OpenStreetMap) ──
if (isset($_POST['reverse_geocode'])) {
    $geo = PluginGeolocationComputer::getForComputer($computers_id);

    if ($geo && !empty($geo['latitude']) && !empty($geo['longitude'])) {
        $result = PluginGeolocationComputer::reverseGeocode($geo['latitude'], $geo['longitude']);

        if ($result) {
            global $DB;

            $updates = ['date_mod' => date('Y-m-d H:i:s')];
            if (!empty($result['road'])) {
                $address_parts = array_filter([
                    $result['road'],
                    $result['neighborhood'],
                    $result['city'],
                    $result['state'],
                ]);
                $updates['address'] = implode(', ', $address_parts);
            }
            if (!empty($result['neighborhood'])) {
                $updates['neighborhood'] = $result['neighborhood'];
            }
            if (!empty($result['city'])) {
                $updates['city'] = $result['city'];
            }
            if (!empty($result['state'])) {
                $updates['state'] = $result['state'];
            }
            if (!empty($result['postcode'])) {
                $updates['zip'] = $result['postcode'];
            }

            $DB->update(PluginGeolocationComputer::getTable(), $updates, ['id' => $geo['id']]);

            Session::addMessageAfterRedirect(
                __('Street address resolved via OpenStreetMap.', 'geolocation'),
                true, INFO
            );
        } else {
            Session::addMessageAfterRedirect(
                __('Failed to resolve street address from coordinates.', 'geolocation'),
                false, ERROR
            );
        }
    }
}

Html::redirect('/front/computer.form.php?id=' . $computers_id . '&forcetab=PluginGeolocationComputer$1');
