<?php

class PluginGeolocationComputer extends CommonDBTM {

    static $rightname = 'computer';

    static function getTypeName($nb = 0): string {
        return __('Geolocation', 'geolocation');
    }

    static function getTable($classname = null): string {
        return 'glpi_plugin_geolocation_computers';
    }

    // ── Tab on Computer ─────────────────────────────────────────────

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
        if ($item instanceof Computer) {
            $count = countElementsInTable(self::getTable(), ['computers_id' => $item->getID()]);
            return self::createTabEntry(__('Geolocation', 'geolocation'), $count, null, 'ti ti-map-pin');
        }
        return '';
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
        if ($item instanceof Computer) {
            self::showForComputer($item);
        }
        return true;
    }

    // ── Get location for a computer ─────────────────────────────────

    static function getForComputer($computers_id) {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['computers_id' => $computers_id],
            'LIMIT' => 1,
        ]);
        return count($iterator) ? $iterator->current() : null;
    }

    // ── Resolve IP via geolocation API ──────────────────────────────

    static function resolveFromIp($computers_id, $ip) {
        global $DB;

        $data = self::callGeoApi($ip);
        if (!$data) {
            return false;
        }

        $table = self::getTable();
        $now   = date('Y-m-d H:i:s');
        $display_ip = $ip . ($data['note'] ?? '');
        $fields = [
            'computers_id' => $computers_id,
            'ip_address'   => $display_ip,
            'latitude'     => $data['lat'] ?? null,
            'longitude'    => $data['lon'] ?? null,
            'address'      => $data['address'] ?? null,
            'neighborhood' => $data['neighborhood'] ?? null,
            'city'         => $data['city'] ?? null,
            'state'        => $data['state'] ?? null,
            'country'      => $data['country'] ?? null,
            'zip'          => $data['zip'] ?? null,
            'isp'          => $data['isp'] ?? null,
            'status'       => 'resolved',
            'date_mod'     => $now,
        ];

        $existing = self::getForComputer($computers_id);
        if ($existing) {
            $DB->update($table, $fields, ['id' => $existing['id']]);
        } else {
            $fields['date_creation'] = $now;
            $DB->insert($table, $fields);
        }

        return true;
    }

    // ── Call IP geolocation API ─────────────────────────────────────

    /**
     * Check if an IP is private/reserved (not geolocatable).
     */
    static function isPrivateIp($ip) {
        if (empty($ip)) return true;
        // IPv6 loopback
        if ($ip === '::1') return true;
        // Filter: returns false if private/reserved
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * Get the public IP of the GLPI server (used as fallback when agent IP is private).
     */
    static function getPublicIp() {
        $services = [
            'https://api.ipify.org',
            'https://ifconfig.me/ip',
            'https://icanhazip.com',
        ];
        $context = stream_context_create([
            'http' => ['timeout' => 5, 'method' => 'GET', 'header' => "User-Agent: GLPI-Geolocation-Plugin/1.0\r\n"],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        foreach ($services as $url) {
            $response = @file_get_contents($url, false, $context);
            if ($response !== false) {
                $ip = trim($response);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return null;
    }

    static function callGeoApi($ip) {
        // If IP is private, get the server's public IP as fallback
        $original_ip = $ip;
        if (self::isPrivateIp($ip)) {
            $public_ip = self::getPublicIp();
            if (!$public_ip) {
                return null;
            }
            $ip = $public_ip;
        }

        // ip-api.com — free, no key required, 45 requests/minute
        $url = "http://ip-api.com/json/" . urlencode($ip) . "?fields=status,message,country,regionName,city,zip,lat,lon,isp,district,query";

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'method'  => 'GET',
                'header'  => "User-Agent: GLPI-Geolocation-Plugin/1.0\r\n",
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }

        $json = json_decode($response, true);
        if (!$json || ($json['status'] ?? '') !== 'success') {
            return null;
        }

        $note = '';
        if ($original_ip !== $ip) {
            $note = ' (via public IP: ' . $ip . ')';
        }

        return [
            'lat'          => $json['lat'] ?? null,
            'lon'          => $json['lon'] ?? null,
            'city'         => $json['city'] ?? null,
            'state'        => $json['regionName'] ?? null,
            'country'      => $json['country'] ?? null,
            'zip'          => $json['zip'] ?? null,
            'isp'          => $json['isp'] ?? null,
            'neighborhood' => $json['district'] ?? null,
            'address'      => trim(($json['district'] ?? '') . ', ' . ($json['city'] ?? '') . ' - ' . ($json['regionName'] ?? ''), ', -'),
            'resolved_ip'  => $ip,
            'note'         => $note,
        ];
    }

    // ── Reverse geocode for street address (optional, uses OSM) ────

    static function reverseGeocode($lat, $lon) {
        if (empty($lat) || empty($lon)) {
            return null;
        }

        $url = "https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=" . urlencode($lat) . "&lon=" . urlencode($lon) . "&zoom=18&addressdetails=1";

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'method'  => 'GET',
                'header'  => "User-Agent: GLPI-Geolocation-Plugin/1.0\r\n",
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }

        $json = json_decode($response, true);
        if (!$json || !isset($json['address'])) {
            return null;
        }

        $addr = $json['address'];
        return [
            'road'         => $addr['road'] ?? null,
            'neighborhood' => $addr['suburb'] ?? $addr['neighbourhood'] ?? $addr['district'] ?? null,
            'city'         => $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? null,
            'state'        => $addr['state'] ?? null,
            'country'      => $addr['country'] ?? null,
            'postcode'     => $addr['postcode'] ?? null,
            'display'      => $json['display_name'] ?? null,
        ];
    }

    // ── Display tab content for Computer ────────────────────────────

    static function showForComputer(Computer $computer) {
        $computers_id = $computer->getID();
        $geo = self::getForComputer($computers_id);
        $action = Plugin::getWebDir('geolocation') . '/front/resolve.php';

        echo '<div class="container-lg">';

        // ── Action buttons ──
        echo '<form method="post" action="' . $action . '">';
        echo '<input type="hidden" name="computers_id" value="' . (int)$computers_id . '">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . Session::getNewCSRFToken() . '">';

        echo '<div class="d-flex justify-content-between align-items-center mb-4">';
        echo '<h3 class="mb-0"><i class="ti ti-map-pin me-2"></i>' . __('Geolocation', 'geolocation') . '</h3>';
        echo '<div class="d-flex gap-2">';
        echo '<button type="submit" name="resolve" class="btn btn-primary"><i class="ti ti-refresh me-1"></i>' . __('Resolve Location', 'geolocation') . '</button>';
        if ($geo && !empty($geo['latitude'])) {
            echo '<button type="submit" name="reverse_geocode" class="btn btn-outline-primary"><i class="ti ti-map-search me-1"></i>' . __('Get Street Address', 'geolocation') . '</button>';
        }
        echo '</div></div>';
        Html::closeForm();

        if (!$geo || $geo['status'] !== 'resolved') {
            // No data yet
            echo '<div class="card">';
            echo '<div class="card-body text-center p-5">';
            echo '<i class="ti ti-map-pin-off" style="font-size:3rem;color:#94a3b8;"></i>';
            echo '<h4 class="mt-3 text-muted">' . __('No location data available', 'geolocation') . '</h4>';
            echo '<p class="text-muted">' . __('Click "Resolve Location" to detect this computer\'s location from its agent IP address.', 'geolocation') . '</p>';
            echo '</div></div>';
            echo '</div>';
            return;
        }

        // ── Location cards ──
        echo '<div class="row g-3 mb-4">';

        // Map card
        if (!empty($geo['latitude']) && !empty($geo['longitude'])) {
            $lat = htmlspecialchars($geo['latitude']);
            $lon = htmlspecialchars($geo['longitude']);
            $osm_url = "https://www.openstreetmap.org/?mlat={$lat}&mlon={$lon}#map=15/{$lat}/{$lon}";
            $gmaps_url = "https://www.google.com/maps?q={$lat},{$lon}";

            echo '<div class="col-lg-6">';
            echo '<div class="card h-100">';
            echo '<div class="card-body p-0" style="min-height:300px;border-radius:inherit;overflow:hidden;">';
            echo '<iframe width="100%" height="300" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" style="border-radius:inherit;" ';
            echo 'src="https://www.openstreetmap.org/export/embed.html?bbox=' . ($lon - 0.01) . ',' . ($lat - 0.01) . ',' . ($lon + 0.01) . ',' . ($lat + 0.01) . '&layer=mapnik&marker=' . $lat . ',' . $lon . '">';
            echo '</iframe>';
            echo '</div>';
            echo '<div class="card-footer d-flex gap-2">';
            echo '<a href="' . $osm_url . '" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="ti ti-map me-1"></i>OpenStreetMap</a>';
            echo '<a href="' . $gmaps_url . '" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="ti ti-brand-google me-1"></i>Google Maps</a>';
            echo '<span class="ms-auto text-muted small">' . $lat . ', ' . $lon . '</span>';
            echo '</div></div></div>';
        }

        // Info card
        echo '<div class="col-lg-6">';
        echo '<div class="card h-100">';
        echo '<div class="card-header"><h4 class="card-title mb-0"><i class="ti ti-info-circle me-2"></i>' . __('Location Details', 'geolocation') . '</h4></div>';
        echo '<div class="card-body p-0">';
        echo '<table class="table table-striped table-vcenter card-table">';

        $fields = [
            ['ti-network',     __('IP Address', 'geolocation'),   $geo['ip_address']],
            ['ti-map-pin',     __('Address', 'geolocation'),      $geo['address']],
            ['ti-building',    __('Neighborhood', 'geolocation'), $geo['neighborhood']],
            ['ti-building-community', __('City', 'geolocation'),  $geo['city']],
            ['ti-map',         __('State', 'geolocation'),        $geo['state']],
            ['ti-world',       __('Country', 'geolocation'),      $geo['country']],
            ['ti-mail',        __('ZIP', 'geolocation'),          $geo['zip']],
            ['ti-wifi',        __('ISP', 'geolocation'),          $geo['isp']],
            ['ti-gps',         __('Coordinates', 'geolocation'),  ($geo['latitude'] && $geo['longitude']) ? $geo['latitude'] . ', ' . $geo['longitude'] : ''],
        ];

        foreach ($fields as [$icon, $label, $value]) {
            if (empty($value)) continue;
            echo '<tr>';
            echo '<td class="w-50"><i class="ti ' . $icon . ' me-2 text-muted"></i>' . $label . '</td>';
            echo '<td><strong>' . htmlspecialchars($value) . '</strong></td>';
            echo '</tr>';
        }

        echo '</table>';
        echo '</div>';

        // Last updated
        if (!empty($geo['date_mod'])) {
            echo '<div class="card-footer text-muted small">';
            echo '<i class="ti ti-clock me-1"></i>' . __('Last updated:', 'geolocation') . ' ' . htmlspecialchars($geo['date_mod']);
            echo '</div>';
        }

        echo '</div></div>';
        echo '</div>'; // row

        echo '</div>'; // container
    }
}
