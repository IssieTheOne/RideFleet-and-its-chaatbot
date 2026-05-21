<?php
/**
 * Flight Tracker admin page — powered by Airlabs API.
 * Lives in the RideFleet Booking plugin so it sits alongside operational data.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Admin;

use RideFleetBooking\Support\Options;

if (!defined('ABSPATH')) {
    exit;
}

final class FlightTrackerPage {
    private const API_BASE  = 'https://airlabs.co/api/v9/schedules';
    private const CACHE_TTL = DAY_IN_SECONDS;

    // ── Static lookups (top 60 airlines / airports — extend as needed) ─────
    private const AIRLINES = [
        'SN'=>'Brussels Airlines','LH'=>'Lufthansa','BA'=>'British Airways',
        'AF'=>'Air France','KL'=>'KLM','IB'=>'Iberia','AZ'=>'ITA Airways',
        'EK'=>'Emirates','QR'=>'Qatar Airways','TK'=>'Turkish Airlines',
        'EW'=>'Eurowings','FR'=>'Ryanair','U2'=>'easyJet','W6'=>'Wizz Air',
        'VY'=>'Vueling','PC'=>'Pegasus','HV'=>'Transavia','DY'=>'Norwegian',
        'SK'=>'SAS','AY'=>'Finnair','OS'=>'Austrian','LX'=>'SWISS',
        'TP'=>'TAP Air Portugal','RO'=>'TAROM','OK'=>'Czech Airlines',
        'LO'=>'LOT Polish','BT'=>'airBaltic','EI'=>'Aer Lingus',
        'UA'=>'United Airlines','AA'=>'American Airlines','DL'=>'Delta',
        'WN'=>'Southwest','B6'=>'JetBlue','AS'=>'Alaska Airlines',
        'AC'=>'Air Canada','AM'=>'Aeroméxico','LA'=>'LATAM',
        'G3'=>'GOL','AD'=>'Azul','AV'=>'Avianca',
        'CX'=>'Cathay Pacific','SQ'=>'Singapore Airlines','MH'=>'Malaysia Airlines',
        'TG'=>'Thai Airways','GA'=>'Garuda Indonesia','CI'=>'China Airlines',
        'BR'=>'EVA Air','JL'=>'Japan Airlines','NH'=>'ANA',
        'OZ'=>'Asiana Airlines','KE'=>'Korean Air','CZ'=>'China Southern',
        'MU'=>'China Eastern','CA'=>'Air China','AI'=>'Air India',
        'ET'=>'Ethiopian Airlines','SA'=>'South African','MS'=>'EgyptAir',
        'AT'=>'Royal Air Maroc','RJ'=>'Royal Jordanian','GF'=>'Gulf Air',
        'WY'=>'Oman Air','SV'=>'Saudi Arabian','FZ'=>'flydubai','G9'=>'Air Arabia',
    ];

    private const AIRPORTS = [
        'BRU'=>['name'=>'Brussels Airport','city'=>'Brussels'],
        'AMS'=>['name'=>'Schiphol Airport','city'=>'Amsterdam'],
        'CDG'=>['name'=>'Charles de Gaulle','city'=>'Paris'],
        'LHR'=>['name'=>'Heathrow','city'=>'London'],
        'LGW'=>['name'=>'Gatwick','city'=>'London'],
        'FRA'=>['name'=>'Frankfurt Airport','city'=>'Frankfurt'],
        'MUC'=>['name'=>'Munich Airport','city'=>'Munich'],
        'VIE'=>['name'=>'Vienna Airport','city'=>'Vienna'],
        'ZRH'=>['name'=>'Zürich Airport','city'=>'Zürich'],
        'BCN'=>['name'=>'El Prat','city'=>'Barcelona'],
        'MAD'=>['name'=>'Barajas','city'=>'Madrid'],
        'FCO'=>['name'=>'Fiumicino','city'=>'Rome'],
        'MXP'=>['name'=>'Malpensa','city'=>'Milan'],
        'ATH'=>['name'=>'Eleftherios Venizelos','city'=>'Athens'],
        'IST'=>['name'=>'Istanbul Airport','city'=>'Istanbul'],
        'DXB'=>['name'=>'Dubai International','city'=>'Dubai'],
        'DOH'=>['name'=>'Hamad International','city'=>'Doha'],
        'JFK'=>['name'=>'John F. Kennedy','city'=>'New York'],
        'LAX'=>['name'=>'LAX','city'=>'Los Angeles'],
        'ORD'=>['name'=>"O'Hare International",'city'=>'Chicago'],
        'SYD'=>['name'=>'Kingsford Smith','city'=>'Sydney'],
        'SIN'=>['name'=>'Changi Airport','city'=>'Singapore'],
        'HKG'=>['name'=>'Hong Kong International','city'=>'Hong Kong'],
        'NRT'=>['name'=>'Narita International','city'=>'Tokyo'],
        'ICN'=>['name'=>'Incheon International','city'=>'Seoul'],
        'DUB'=>['name'=>'Dublin Airport','city'=>'Dublin'],
        'LIS'=>['name'=>'Humberto Delgado','city'=>'Lisbon'],
        'CPH'=>['name'=>'Kastrup','city'=>'Copenhagen'],
        'ARN'=>['name'=>'Arlanda','city'=>'Stockholm'],
        'HEL'=>['name'=>'Helsinki Airport','city'=>'Helsinki'],
        'WAW'=>['name'=>'Chopin Airport','city'=>'Warsaw'],
        'PRG'=>['name'=>'Václav Havel','city'=>'Prague'],
        'BUD'=>['name'=>'Liszt Ferenc','city'=>'Budapest'],
        'BUH'=>['name'=>'Henri Coandă','city'=>'Bucharest'],
        'SOF'=>['name'=>'Sofia Airport','city'=>'Sofia'],
        'LJU'=>['name'=>'Jože Pučnik','city'=>'Ljubljana'],
        'ZAG'=>['name'=>'Franjo Tuđman','city'=>'Zagreb'],
        'LUX'=>['name'=>'Luxembourg Airport','city'=>'Luxembourg'],
        'GVA'=>['name'=>'Geneva Airport','city'=>'Geneva'],
        'BLQ'=>['name'=>'Guglielmo Marconi','city'=>'Bologna'],
        'MRS'=>['name'=>'Provence Airport','city'=>'Marseille'],
        'NCE'=>['name'=>"Côte d'Azur",'city'=>'Nice'],
        'TLS'=>['name'=>'Blagnac','city'=>'Toulouse'],
        'LYS'=>['name'=>'Saint-Exupéry','city'=>'Lyon'],
        'HAM'=>['name'=>'Hamburg Airport','city'=>'Hamburg'],
        'DUS'=>['name'=>'Düsseldorf Airport','city'=>'Düsseldorf'],
        'STR'=>['name'=>'Stuttgart Airport','city'=>'Stuttgart'],
        'BER'=>['name'=>'Brandenburg Airport','city'=>'Berlin'],
    ];

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied.', 'ridefleet-booking'));
        }

        $api_key     = trim((string) Options::get('airlabs_api_key', ''));
        $def_iata    = strtoupper(trim((string) Options::get('airlabs_default_iata', '')));
        $iata        = strtoupper(sanitize_text_field(wp_unslash($_GET['iata'] ?? $def_iata)));
        $type        = in_array($_GET['type'] ?? '', ['arr','dep'], true) ? (string) wp_unslash($_GET['type']) : 'arr';
        $status_f    = sanitize_key(wp_unslash($_GET['status_f'] ?? ''));
        $time_filter = sanitize_key(wp_unslash($_GET['time_f'] ?? '')); // upcoming|past|all
        $refresh     = !empty($_GET['refresh']) && wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'] ?? '')), 'rfb_flight_refresh');

        $flights    = [];
        $error      = '';
        $last_fetch = '';
        $cache_key  = 'rfb_flights_' . md5($iata . $type . wp_date('Ymd'));

        if ($refresh) {
            delete_transient($cache_key);
        }

        if ('' !== $iata && '' !== $api_key) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                $flights    = $cached['flights'];
                $last_fetch = $cached['fetched'];
            } else {
                $param = 'arr' === $type ? 'arr_iata' : 'dep_iata';
                $url   = add_query_arg([$param => $iata, 'api_key' => $api_key], self::API_BASE);
                $resp  = wp_remote_get($url, ['timeout' => 15, 'redirection' => 0]);
                if (is_wp_error($resp)) {
                    $error = $resp->get_error_message();
                } else {
                    $code = (int) wp_remote_retrieve_response_code($resp);
                    $body = json_decode((string) wp_remote_retrieve_body($resp), true);
                    if (200 === $code && is_array($body['response'] ?? null)) {
                        $flights    = $body['response'];
                        $last_fetch = wp_date('H:i') . ' (today\'s data)';
                        set_transient($cache_key, ['flights' => $flights, 'fetched' => $last_fetch], self::CACHE_TTL);
                    } else {
                        $error = (string) ($body['error']['message'] ?? sprintf('API error %d', $code));
                    }
                }
            }
        }

        $now_ts = current_time('timestamp');

        // Classify each flight: past / active / upcoming
        foreach ($flights as &$f) {
            $t = 'arr' === $type ? (string)($f['arr_time'] ?? '') : (string)($f['dep_time'] ?? '');
            $ts = $t ? strtotime($t) : 0;
            $f['_ts']      = $ts;
            $f['_is_past'] = $ts > 0 && $ts < $now_ts;
            $f['_is_now']  = strtolower((string)($f['status'] ?? '')) === 'active';
        }
        unset($f);

        // Sort: past flights reversed (most recent past first), then upcoming ascending
        $past_flights     = array_filter($flights, static fn($f) => $f['_is_past'] && !$f['_is_now']);
        $upcoming_flights = array_filter($flights, static fn($f) => !$f['_is_past'] || $f['_is_now']);
        usort($past_flights, static fn($a, $b) => $b['_ts'] - $a['_ts']);
        usort($upcoming_flights, static fn($a, $b) => $a['_ts'] - $b['_ts']);

        // Status counts
        $all_flights   = array_merge(array_values($upcoming_flights), array_values($past_flights));
        $status_counts = [];
        foreach ($all_flights as $f) {
            $s = strtolower((string)($f['status'] ?? 'scheduled'));
            $status_counts[$s] = ($status_counts[$s] ?? 0) + 1;
        }

        // Apply filters
        $display_flights = $all_flights;
        if ('upcoming' === $time_filter) $display_flights = array_values($upcoming_flights);
        elseif ('past' === $time_filter) $display_flights = array_values($past_flights);
        if ('' !== $status_f) {
            $display_flights = array_values(array_filter($display_flights, static fn($f) => strtolower((string)($f['status']??'')) === $status_f));
        }

        $airport_info   = self::AIRPORTS[$iata] ?? null;
        $base_url       = admin_url('admin.php?page=ridefleet-flights');
        $refresh_nonce  = wp_create_nonce('rfb_flight_refresh');

        $total_today      = count($all_flights);
        $total_upcoming   = count($upcoming_flights);
        $total_active     = count(array_filter($all_flights, static fn($f) => strtolower((string)($f['status']??'')) === 'active'));
        $total_cancelled  = $status_counts['cancelled'] ?? 0;
        ?>
        <div class="wrap rfb-admin">

        <div class="rfb-hero">
            <div>
                <p class="rfb-kicker"><?php echo esc_html('arr' === $type ? __('Arrivals', 'ridefleet-booking') : __('Departures', 'ridefleet-booking')); ?></p>
                <h1>
                    ✈ <?php esc_html_e('Flight Tracker', 'ridefleet-booking'); ?>
                    <?php if ($iata): ?>
                        — <span style="color:#7dd3c7"><?php echo esc_html($iata); ?></span>
                        <?php if ($airport_info): ?>
                            <span style="font-size:16px;font-weight:400;opacity:.75"> <?php echo esc_html($airport_info['city']); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </h1>
                <p>
                    <?php esc_html_e('Real-time airport schedule data. Cached daily — use Refresh to force a new fetch.', 'ridefleet-booking'); ?>
                    <?php if ($last_fetch): ?>
                        <span style="opacity:.7;font-size:12px;"> · <?php echo esc_html(sprintf(__('Last fetched: %s', 'ridefleet-booking'), $last_fetch)); ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="rfb-hero-actions">
                <?php if ($iata): ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg(['iata'=>$iata,'type'=>$type,'refresh'=>'1','_wpnonce'=>$refresh_nonce], $base_url)); ?>">
                        ↻ <?php esc_html_e('Refresh', 'ridefleet-booking'); ?>
                    </a>
                <?php endif; ?>
                <a class="button" href="<?php echo esc_url(add_query_arg(['iata'=>$iata,'type'=>'arr'===($type)?'dep':'arr'], $base_url)); ?>">
                    ⇄ <?php echo esc_html('arr'===$type ? __('Switch to Departures','ridefleet-booking') : __('Switch to Arrivals','ridefleet-booking')); ?>
                </a>
            </div>
        </div>

        <?php if ($total_today > 0): ?>
        <div class="rfb-stat-grid">
            <?php DashboardPage::stat(__('Total Flights','ridefleet-booking'), number_format_i18n($total_today), __('All flights in today\'s schedule.','ridefleet-booking')); ?>
            <?php DashboardPage::stat(__('Upcoming','ridefleet-booking'), number_format_i18n($total_upcoming), __('Flights not yet landed or departed.','ridefleet-booking')); ?>
            <?php DashboardPage::stat(__('Active','ridefleet-booking'), number_format_i18n($total_active), __('Flights currently in the air.','ridefleet-booking')); ?>
            <?php DashboardPage::stat(__('Cancelled','ridefleet-booking'), number_format_i18n($total_cancelled), __('Cancelled flights today.','ridefleet-booking')); ?>
        </div>
        <?php endif; ?>

        <?php if ('' === $api_key): ?>
            <div class="notice notice-warning"><p>
                <?php printf(esc_html__('No Airlabs API key configured. %sAdd it in Settings.%s', 'ridefleet-booking'), '<a href="' . esc_url(admin_url('admin.php?page=ridefleet-settings')) . '">', '</a>'); ?>
            </p></div>
        <?php endif; ?>

        <?php if ('' !== $error): ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <!-- Search / Filter bar -->
        <form method="get" class="rfb-panel rfb-filter-bar rfb-filter-bar-labeled" style="margin-top:18px;">
            <input type="hidden" name="page" value="ridefleet-flights">
            <label>
                <span><?php esc_html_e('Airport IATA', 'ridefleet-booking'); ?></span>
                <input type="text" name="iata" value="<?php echo esc_attr($iata); ?>"
                       placeholder="BRU" maxlength="4" style="width:80px;text-transform:uppercase;">
            </label>
            <label>
                <span><?php esc_html_e('Type', 'ridefleet-booking'); ?></span>
                <select name="type">
                    <option value="arr" <?php selected($type,'arr'); ?>><?php esc_html_e('Arrivals', 'ridefleet-booking'); ?></option>
                    <option value="dep" <?php selected($type,'dep'); ?>><?php esc_html_e('Departures', 'ridefleet-booking'); ?></option>
                </select>
            </label>
            <label>
                <span><?php esc_html_e('Time', 'ridefleet-booking'); ?></span>
                <select name="time_f">
                    <option value="" <?php selected($time_filter,''); ?>><?php esc_html_e('All flights', 'ridefleet-booking'); ?></option>
                    <option value="upcoming" <?php selected($time_filter,'upcoming'); ?>><?php esc_html_e('Upcoming only', 'ridefleet-booking'); ?></option>
                    <option value="past" <?php selected($time_filter,'past'); ?>><?php esc_html_e('Past only', 'ridefleet-booking'); ?></option>
                </select>
            </label>
            <label>
                <span><?php esc_html_e('Status', 'ridefleet-booking'); ?></span>
                <select name="status_f">
                    <option value=""><?php esc_html_e('All statuses', 'ridefleet-booking'); ?></option>
                    <?php foreach(array_keys($status_counts) as $sc): ?>
                        <option value="<?php echo esc_attr($sc); ?>" <?php selected($status_f,$sc); ?>>
                            <?php echo esc_html(ucfirst($sc)); ?> (<?php echo esc_html((string)($status_counts[$sc]??0)); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div style="display:flex;align-items:flex-end;gap:8px;">
                <button type="submit" class="button button-primary"><?php esc_html_e('Apply', 'ridefleet-booking'); ?></button>
                <?php if ($iata || $status_f || $time_filter): ?>
                    <a href="<?php echo esc_url($base_url); ?>" class="button"><?php esc_html_e('Reset', 'ridefleet-booking'); ?></a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Flight board -->
        <?php if ('' === $iata): ?>
            <div class="rfb-panel" style="text-align:center;padding:60px 24px;color:#94a3b8;margin-top:0;">
                <div style="font-size:56px;margin-bottom:12px;">✈</div>
                <h2 style="color:#64748b;"><?php esc_html_e('No airport selected', 'ridefleet-booking'); ?></h2>
                <p><?php esc_html_e('Enter an airport IATA code above (e.g. BRU, AMS, LHR) and click Apply.', 'ridefleet-booking'); ?></p>
                <p style="font-size:12px;"><?php esc_html_e('You can set a default airport in Settings → Flight Tracker.', 'ridefleet-booking'); ?></p>
            </div>
        <?php elseif (!$display_flights && '' === $error): ?>
            <div class="rfb-panel" style="text-align:center;padding:48px;color:#64748b;margin-top:0;">
                <p><?php esc_html_e('No flights found for this filter combination.', 'ridefleet-booking'); ?></p>
            </div>
        <?php else: ?>
            <div class="rfb-panel rfb-flights-board" style="margin-top:0;">
                <table class="rfb-fids-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Airline', 'ridefleet-booking'); ?></th>
                            <th><?php esc_html_e('Flight #', 'ridefleet-booking'); ?></th>
                            <th><?php echo esc_html('arr'===$type ? __('From', 'ridefleet-booking') : __('To', 'ridefleet-booking')); ?></th>
                            <th><?php esc_html_e('Sched.', 'ridefleet-booking'); ?></th>
                            <th><?php esc_html_e('Actual', 'ridefleet-booking'); ?></th>
                            <th><?php esc_html_e('Remarks', 'ridefleet-booking'); ?></th>
                            <th><?php esc_html_e('Gate', 'ridefleet-booking'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($display_flights as $f):
                            $is_past      = (bool)($f['_is_past']??false);
                            $is_active    = strtolower((string)($f['status']??'')) === 'active';
                            $is_cancelled = strtolower((string)($f['status']??'')) === 'cancelled';
                            $airline_code = strtoupper((string)($f['airline_iata'] ?? '??'));
                            $airline_name = self::AIRLINES[$airline_code] ?? $airline_code;

                            // Strip the airline prefix from flight_iata to get just the number
                            $raw_fnum = (string)($f['flight_iata'] ?? $f['flight_icao'] ?? '');
                            $flight_number = preg_replace('/^[A-Z0-9]{2,3}(?=\d)/i', '', $raw_fnum);
                            if (!$flight_number) $flight_number = $raw_fnum ?: '—';

                            $other_iata   = strtoupper((string)('arr'===$type ? ($f['dep_iata']??'—') : ($f['arr_iata']??'—')));
                            $other_info   = self::AIRPORTS[$other_iata] ?? null;
                            $sched_t      = 'arr'===$type ? (string)($f['arr_time']??'') : (string)($f['dep_time']??'');
                            $sched_disp   = $sched_t ? wp_date('H:i', strtotime($sched_t)) : '—';
                            $delay        = (int)('arr'===$type ? ($f['arr_delayed']??0) : ($f['dep_delayed']??0));
                            $sched_ts     = $f['_ts'] ?? 0;
                            $gate         = (string)('arr'===$type ? ($f['arr_gate']??'') : ($f['dep_gate']??''));

                            // Actual time calculation
                            if ($sched_ts > 0 && $delay !== 0) {
                                $actual_ts    = $sched_ts + ($delay * 60);
                                $actual_disp  = wp_date('H:i', $actual_ts);
                                $actual_class = $delay < 0 ? 'rfb-fids-actual--early' : 'rfb-fids-actual--late';
                            } elseif ($sched_ts > 0) {
                                $actual_disp  = wp_date('H:i', $sched_ts);
                                $actual_class = 'rfb-fids-actual--ontime';
                            } else {
                                $actual_disp  = '—';
                                $actual_class = '';
                            }

                            // Remarks calculation
                            $status_lc = strtolower((string)($f['status'] ?? 'scheduled'));
                            if ('cancelled' === $status_lc) {
                                $remark = __('Cancelled', 'ridefleet-booking'); $remark_class = 'rfb-remark--cancelled';
                            } elseif ('active' === $status_lc) {
                                $remark = __('In Flight', 'ridefleet-booking'); $remark_class = 'rfb-remark--active';
                            } elseif ('landed' === $status_lc) {
                                $remark = __('Landed', 'ridefleet-booking'); $remark_class = 'rfb-remark--landed';
                            } elseif ('diverted' === $status_lc || 'redirected' === $status_lc) {
                                $remark = __('Diverted', 'ridefleet-booking'); $remark_class = 'rfb-remark--diverted';
                            } elseif ($delay > 5) {
                                $remark = sprintf(__('Delayed +%dmin', 'ridefleet-booking'), $delay); $remark_class = 'rfb-remark--delayed';
                            } elseif ($delay < -2) {
                                $remark = __('Early', 'ridefleet-booking'); $remark_class = 'rfb-remark--early';
                            } else {
                                $remark = __('On Time', 'ridefleet-booking'); $remark_class = 'rfb-remark--ontime';
                            }
                            // Override remark for past/landed
                            if ($is_past && !$is_active && 'cancelled' !== $status_lc && 'diverted' !== $status_lc && 'redirected' !== $status_lc) {
                                $remark = __('Landed', 'ridefleet-booking'); $remark_class = 'rfb-remark--landed';
                            }

                            $row_class = 'rfb-fids-row';
                            if ($is_past)      $row_class .= ' rfb-fids-row--past';
                            if ($is_active)    $row_class .= ' rfb-fids-row--active';
                            if ($is_cancelled) $row_class .= ' rfb-fids-row--cancelled';
                        ?>
                        <tr class="<?php echo esc_attr($row_class); ?>">
                            <td class="rfb-fids-airline">
                                <?php if ($is_active): ?><span class="rfb-fids-live-dot"></span><?php endif; ?>
                                <?php echo esc_html($airline_name); ?>
                            </td>
                            <td class="rfb-fids-flightnum"><?php echo esc_html($flight_number); ?></td>
                            <td>
                                <strong><?php echo esc_html($other_iata); ?></strong>
                                <?php if ($other_info): ?>
                                    <span class="rfb-fids-city"><?php echo esc_html($other_info['city']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="rfb-fids-time"><strong><?php echo esc_html($sched_disp); ?></strong></td>
                            <td><span class="rfb-fids-actual <?php echo esc_attr($actual_class); ?>"><?php echo esc_html($actual_disp); ?></span></td>
                            <td><span class="rfb-fids-remark <?php echo esc_attr($remark_class); ?>"><?php echo esc_html($remark); ?></span></td>
                            <td><?php echo $gate ? '<span class="rfb-fids-gate">' . esc_html($gate) . '</span>' : '<span class="rfb-fids-none">—</span>'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Mini widget — up to 8 upcoming flights for the dashboard.
     * Called from DashboardPage when airlabs_api_key is set.
     */
    public static function dashboard_widget(): void {
        $api_key  = trim((string) Options::get('airlabs_api_key', ''));
        $iata     = strtoupper(trim((string) Options::get('airlabs_default_iata', '')));
        if ('' === $api_key || '' === $iata) {
            echo '<p style="color:#94a3b8;padding:16px;text-align:center;font-size:13px;">' . esc_html__('Configure Airlabs API key and default IATA code in Settings.', 'ridefleet-booking') . '</p>';
            return;
        }

        $cache_key = 'rfb_flights_' . md5($iata . 'arr' . wp_date('Ymd'));
        $cached    = get_transient($cache_key);
        $flights   = is_array($cached) ? ($cached['flights'] ?? []) : [];
        $fetched   = is_array($cached) ? ($cached['fetched'] ?? '') : '';

        // If not cached, fetch now
        if (!$flights) {
            $url  = add_query_arg(['arr_iata' => $iata, 'api_key' => $api_key], self::API_BASE);
            $resp = wp_remote_get($url, ['timeout' => 8, 'redirection' => 0]);
            if (!is_wp_error($resp) && 200 === (int) wp_remote_retrieve_response_code($resp)) {
                $body = json_decode((string) wp_remote_retrieve_body($resp), true);
                if (is_array($body['response'] ?? null)) {
                    $flights = $body['response'];
                    $fetched = wp_date('H:i');
                    set_transient($cache_key, ['flights' => $flights, 'fetched' => $fetched], self::CACHE_TTL);
                }
            }
        }

        $now_ts = current_time('timestamp');
        // Filter to upcoming arrivals only, sort ascending
        $upcoming = array_filter($flights, static fn($f) => ($t = strtotime((string)($f['arr_time']??''))) && $t >= $now_ts);
        usort($upcoming, static fn($a,$b) => strtotime((string)($a['arr_time']??'')) - strtotime((string)($b['arr_time']??'')));
        $upcoming = array_slice(array_values($upcoming), 0, 8);

        if (!$upcoming) {
            echo '<p style="color:#64748b;padding:16px 0;text-align:center;font-size:13px;">' . esc_html__('No upcoming arrivals today.','ridefleet-booking') . '</p>';
        } else {
            echo '<div class="rfb-widget-flights">';
            foreach ($upcoming as $f) {
                $airline  = strtoupper((string)($f['airline_iata']??'?'));
                $aname    = self::AIRLINES[$airline] ?? $airline;
                $fnum     = (string)($f['flight_iata'] ?? '—');
                $from     = strtoupper((string)($f['dep_iata']??'—'));
                $city     = self::AIRPORTS[$from]['city'] ?? $from;
                $time     = wp_date('H:i', strtotime((string)($f['arr_time']??'')));
                $status   = strtolower((string)($f['status']??'scheduled'));
                $delay    = (int)($f['arr_delayed']??0);
                $gate     = (string)($f['arr_gate']??'');
                echo '<div class="rfb-widget-flight">';
                echo '<span class="rfb-widget-flight__time">' . esc_html($time) . ($delay > 0 ? ' <span class="rfb-fids-delay">+' . esc_html((string)$delay) . '</span>' : '') . '</span>';
                echo '<span class="rfb-airline-badge" style="font-size:10px;padding:1px 5px;">' . esc_html($airline) . '</span>';
                echo '<span class="rfb-widget-flight__info"><strong>' . esc_html($fnum) . '</strong> <span style="color:#94a3b8;">' . esc_html($city) . '</span></span>';
                if ($gate) echo '<span class="rfb-fids-gate" style="font-size:10px;">' . esc_html('Gate ' . $gate) . '</span>';
                echo '<span class="rfb-fids-status rfb-fids-status--' . esc_attr($status) . '" style="font-size:10px;">' . esc_html(ucfirst($status)) . '</span>';
                echo '</div>';
            }
            echo '</div>';
            if ($fetched) echo '<p style="font-size:11px;color:#94a3b8;margin-top:10px;text-align:right;">Last updated: ' . esc_html($fetched) . ' · <a href="' . esc_url(admin_url('admin.php?page=ridefleet-flights&iata=' . $iata)) . '">View all</a></p>';
        }
    }
}
