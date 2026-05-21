<?php
/**
 * Flight Tracker admin page — powered by Airlabs API.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Admin;

if (!defined('ABSPATH')) {
    exit;
}

final class FlightTrackerPage {
    private const API_BASE = 'https://airlabs.co/api/v9/schedules';
    private const CACHE_TTL = 300; // 5 minutes

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view this page.', 'ridefleet-ai-chatbot'));
        }

        $api_key  = trim((string) \RideFleetAIChatbot\Support\Options::get('airlabs_api_key', ''));
        $iata     = strtoupper(sanitize_text_field((string) ($_GET['iata'] ?? '')));
        $type     = in_array($_GET['type'] ?? '', ['arr', 'dep'], true) ? (string) $_GET['type'] : 'arr';
        $status_f = sanitize_key((string) ($_GET['status_f'] ?? ''));
        $refresh  = !empty($_GET['refresh']);

        $flights    = [];
        $error      = '';
        $last_fetch = '';

        if ('' !== $iata && '' !== $api_key) {
            $cache_key = 'rfac_flights_' . md5($iata . $type);
            if ($refresh) {
                delete_transient($cache_key);
            }
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                $flights    = $cached['flights'];
                $last_fetch = $cached['fetched'];
            } else {
                $param = 'arr' === $type ? 'arr_iata' : 'dep_iata';
                $url   = add_query_arg([$param => $iata, 'api_key' => $api_key], self::API_BASE);
                $resp  = wp_remote_get($url, ['timeout' => 12, 'redirection' => 0]);
                if (is_wp_error($resp)) {
                    $error = $resp->get_error_message();
                } else {
                    $code = (int) wp_remote_retrieve_response_code($resp);
                    $body = json_decode((string) wp_remote_retrieve_body($resp), true);
                    if (200 === $code && is_array($body['response'] ?? null)) {
                        $flights    = $body['response'];
                        $last_fetch = current_time('H:i:s');
                        set_transient($cache_key, ['flights' => $flights, 'fetched' => $last_fetch], self::CACHE_TTL);
                    } else {
                        $error = (string) ($body['error']['message'] ?? sprintf('API error %d', $code));
                    }
                }
            }
        }

        // Sort by scheduled time ascending
        usort($flights, static function (array $a, array $b) use ($type): int {
            $ta = 'arr' === $type ? (string) ($a['arr_time'] ?? '') : (string) ($a['dep_time'] ?? '');
            $tb = 'arr' === $type ? (string) ($b['arr_time'] ?? '') : (string) ($b['dep_time'] ?? '');
            return strcmp($ta, $tb);
        });

        // Apply status filter
        if ('' !== $status_f) {
            $flights = array_values(array_filter($flights, static fn(array $f): bool => (string)($f['status'] ?? '') === $status_f));
        }

        // Status counts
        $status_counts = [];
        foreach ($flights as $f) {
            $s = (string) ($f['status'] ?? 'unknown');
            $status_counts[$s] = ($status_counts[$s] ?? 0) + 1;
        }

        $base_url = admin_url('admin.php?page=ridefleet-ai-chatbot-flights');
        ?>
        <div class="wrap rfac-admin">
            <h1 style="display:flex;align-items:center;gap:10px;">
                ✈ <?php esc_html_e('Flight Tracker', 'ridefleet-ai-chatbot'); ?>
                <?php if ('' !== $iata): ?>
                    <span class="rfac-iata-badge"><?php echo esc_html($iata); ?></span>
                <?php endif; ?>
                <?php if ($last_fetch): ?>
                    <span style="font-size:12px;color:#94a3b8;font-weight:400;margin-left:4px;">
                        <?php printf(esc_html__('Fetched at %s · refreshes every 5 min', 'ridefleet-ai-chatbot'), esc_html($last_fetch)); ?>
                    </span>
                <?php endif; ?>
            </h1>

            <?php if ('' === $api_key): ?>
                <div class="notice notice-warning is-dismissible">
                    <p><?php printf(esc_html__('No Airlabs API key configured. %sAdd it in Settings.%s', 'ridefleet-ai-chatbot'), '<a href="' . esc_url(admin_url('admin.php?page=ridefleet-ai-chatbot')) . '">', '</a>'); ?></p>
                </div>
            <?php endif; ?>

            <?php if ('' !== $error): ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <!-- Search / filter bar -->
            <section class="rfac-panel rfac-panel-wide" style="margin-top:18px;">
                <form method="get" class="rfac-filters">
                    <input type="hidden" name="page" value="ridefleet-ai-chatbot-flights">
                    <div class="rfac-filter-row">
                        <input type="text" name="iata" value="<?php echo esc_attr($iata); ?>"
                               placeholder="<?php esc_attr_e('Airport IATA code, e.g. BRU', 'ridefleet-ai-chatbot'); ?>"
                               class="rfac-search" maxlength="4" style="text-transform:uppercase;max-width:200px;">
                        <select name="type">
                            <option value="arr" <?php selected($type,'arr'); ?>><?php esc_html_e('Arrivals', 'ridefleet-ai-chatbot'); ?></option>
                            <option value="dep" <?php selected($type,'dep'); ?>><?php esc_html_e('Departures', 'ridefleet-ai-chatbot'); ?></option>
                        </select>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Load flights', 'ridefleet-ai-chatbot'); ?></button>
                        <?php if ('' !== $iata): ?>
                            <a href="<?php echo esc_url(add_query_arg(['iata'=>$iata,'type'=>$type,'refresh'=>'1'], $base_url)); ?>" class="button"><?php esc_html_e('↻ Refresh now', 'ridefleet-ai-chatbot'); ?></a>
                        <?php endif; ?>
                    </div>

                    <?php if ($flights || '' !== $status_f): ?>
                    <div class="rfac-state-chips" style="margin-top:10px;">
                        <a href="<?php echo esc_url(add_query_arg(['iata'=>$iata,'type'=>$type,'status_f'=>''], $base_url)); ?>"
                           class="rfac-chip <?php echo '' === $status_f ? 'is-active' : ''; ?>">
                            <?php esc_html_e('All', 'ridefleet-ai-chatbot'); ?>
                        </a>
                        <?php foreach ($status_counts as $st => $cnt): ?>
                        <a href="<?php echo esc_url(add_query_arg(['iata'=>$iata,'type'=>$type,'status_f'=>$st], $base_url)); ?>"
                           class="rfac-chip rfac-chip--status-<?php echo esc_attr($st); ?> <?php echo $status_f===$st?'is-active':''; ?>">
                            <?php echo esc_html(ucfirst($st)); ?>
                            <span class="rfac-chip-count"><?php echo esc_html((string)$cnt); ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </form>
            </section>

            <!-- Flight list -->
            <?php if ('' === $iata): ?>
                <section class="rfac-panel rfac-panel-wide" style="margin-top:12px;text-align:center;padding:48px 24px;color:#94a3b8;">
                    <div style="font-size:48px;margin-bottom:12px;">✈</div>
                    <p style="font-size:16px;margin:0;"><?php esc_html_e('Enter an airport IATA code above and click Load flights.', 'ridefleet-ai-chatbot'); ?></p>
                    <p style="font-size:13px;margin-top:6px;"><?php esc_html_e('Examples: BRU (Brussels), AMS (Amsterdam), CDG (Paris), LHR (London)', 'ridefleet-ai-chatbot'); ?></p>
                </section>
            <?php elseif (!$flights && '' === $error): ?>
                <section class="rfac-panel rfac-panel-wide" style="margin-top:12px;text-align:center;padding:40px;color:#64748b;">
                    <p><?php esc_html_e('No flights found for this airport and filter.', 'ridefleet-ai-chatbot'); ?></p>
                </section>
            <?php elseif ($flights): ?>
                <section class="rfac-panel rfac-panel-wide" style="margin-top:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                        <h2 style="margin:0;">
                            <?php if ('arr' === $type): ?>
                                <?php printf(esc_html__('Arrivals at %s', 'ridefleet-ai-chatbot'), '<strong>' . esc_html($iata) . '</strong>'); ?>
                            <?php else: ?>
                                <?php printf(esc_html__('Departures from %s', 'ridefleet-ai-chatbot'), '<strong>' . esc_html($iata) . '</strong>'); ?>
                            <?php endif; ?>
                            <span class="title-count theme-count"><?php echo esc_html(number_format_i18n(count($flights))); ?></span>
                        </h2>
                    </div>

                    <div class="rfac-flight-grid">
                        <?php foreach ($flights as $f):
                            $airline    = strtoupper((string)($f['airline_iata']??'??'));
                            $fnum       = (string)($f['flight_iata'] ?? $f['flight_icao'] ?? '—');
                            $dep_iata   = strtoupper((string)($f['dep_iata']??'—'));
                            $arr_iata   = strtoupper((string)($f['arr_iata']??'—'));
                            $sched_time = 'arr'===$type ? (string)($f['arr_time']??'') : (string)($f['dep_time']??'');
                            $sched_disp = $sched_time ? date_i18n('H:i', strtotime($sched_time)) : '—';
                            $sched_date = $sched_time ? date_i18n('d M', strtotime($sched_time)) : '';
                            $status     = strtolower((string)($f['status']??'scheduled'));
                            $delay      = (int)('arr'===$type ? ($f['arr_delayed']??0) : ($f['dep_delayed']??0));
                            $terminal   = (string)('arr'===$type ? ($f['arr_terminal']??'') : ($f['dep_terminal']??''));
                            $gate       = (string)('arr'===$type ? ($f['arr_gate']??'') : ($f['dep_gate']??''));
                            $duration   = (int)($f['duration']??0);
                        ?>
                        <div class="rfac-flight-card rfac-flight-card--<?php echo esc_attr($status); ?>">
                            <div class="rfac-flight-card__header">
                                <span class="rfac-airline-badge"><?php echo esc_html($airline); ?></span>
                                <span class="rfac-flight-num"><?php echo esc_html($fnum); ?></span>
                                <span class="rfac-flight-status rfac-flight-status--<?php echo esc_attr($status); ?>">
                                    <?php echo esc_html(ucfirst($status)); ?>
                                </span>
                            </div>
                            <div class="rfac-flight-card__route">
                                <span class="rfac-route-iata"><?php echo esc_html($dep_iata); ?></span>
                                <span class="rfac-route-arrow">→</span>
                                <span class="rfac-route-iata"><?php echo esc_html($arr_iata); ?></span>
                            </div>
                            <div class="rfac-flight-card__time">
                                <span class="rfac-time-big"><?php echo esc_html($sched_disp); ?></span>
                                <?php if ($sched_date): ?>
                                    <span class="rfac-time-date"><?php echo esc_html($sched_date); ?></span>
                                <?php endif; ?>
                                <?php if ($delay > 0): ?>
                                    <span class="rfac-delay-badge">+<?php echo esc_html((string)$delay); ?> min</span>
                                <?php endif; ?>
                            </div>
                            <div class="rfac-flight-card__meta">
                                <?php if ($terminal): ?>
                                    <span class="rfac-meta-item">🏛 <?php printf(esc_html__('T%s', 'ridefleet-ai-chatbot'), esc_html($terminal)); ?></span>
                                <?php endif; ?>
                                <?php if ($gate): ?>
                                    <span class="rfac-meta-item">🚪 <?php printf(esc_html__('Gate %s', 'ridefleet-ai-chatbot'), esc_html($gate)); ?></span>
                                <?php endif; ?>
                                <?php if ($duration > 0): ?>
                                    <?php $dh = intdiv($duration, 60); $dm = $duration % 60; ?>
                                    <span class="rfac-meta-item">⏱ <?php echo $dh > 0 ? esc_html(sprintf('%dh %02dmin', $dh, $dm)) : esc_html(sprintf('%dmin', $dm)); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>
        <style>
        /* Flight Tracker inline styles (keep scoped to not pollute global admin) */
        .rfac-iata-badge{background:#0f766e;color:#fff;padding:2px 10px;border-radius:12px;font-size:14px;font-weight:700;letter-spacing:1px;}
        .rfac-flight-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;}
        .rfac-flight-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:8px;transition:box-shadow .15s;}
        .rfac-flight-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);}
        .rfac-flight-card--cancelled{opacity:.6;border-color:#fca5a5;}
        .rfac-flight-card--active{border-color:#86efac;background:#f0fdf4;}
        .rfac-flight-card--landed{border-color:#cbd5e1;}
        .rfac-flight-card__header{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
        .rfac-airline-badge{background:#0f766e;color:#fff;font-size:11px;font-weight:700;padding:2px 7px;border-radius:6px;letter-spacing:.5px;}
        .rfac-flight-num{font-weight:700;font-size:15px;color:#1e293b;flex:1;}
        .rfac-flight-status{font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;text-transform:capitalize;}
        .rfac-flight-status--scheduled{background:#dbeafe;color:#1d4ed8;}
        .rfac-flight-status--active{background:#dcfce7;color:#166534;}
        .rfac-flight-status--landed{background:#f1f5f9;color:#475569;}
        .rfac-flight-status--cancelled{background:#fee2e2;color:#dc2626;}
        .rfac-flight-status--diverted,.rfac-flight-status--redirected{background:#fef9c3;color:#854d0e;}
        .rfac-flight-status--incident{background:#fde68a;color:#92400e;}
        .rfac-flight-card__route{display:flex;align-items:center;gap:8px;font-size:14px;color:#475569;}
        .rfac-route-iata{font-weight:700;font-size:16px;color:#0f172a;}
        .rfac-route-arrow{color:#94a3b8;}
        .rfac-flight-card__time{display:flex;align-items:baseline;gap:6px;}
        .rfac-time-big{font-size:22px;font-weight:800;color:#0f172a;line-height:1;}
        .rfac-time-date{font-size:12px;color:#64748b;}
        .rfac-delay-badge{background:#fee2e2;color:#dc2626;font-size:11px;font-weight:600;padding:2px 7px;border-radius:10px;margin-left:4px;}
        .rfac-flight-card__meta{display:flex;flex-wrap:wrap;gap:6px;margin-top:2px;}
        .rfac-meta-item{font-size:11px;color:#64748b;background:#f1f5f9;padding:2px 8px;border-radius:8px;}
        .rfac-chip--status-active.is-active,.rfac-chip--status-active:hover{background:#dcfce7;color:#166534;border-color:#86efac;}
        .rfac-chip--status-landed.is-active,.rfac-chip--status-landed:hover{background:#f1f5f9;color:#475569;}
        .rfac-chip--status-cancelled.is-active,.rfac-chip--status-cancelled:hover{background:#fee2e2;color:#dc2626;border-color:#fca5a5;}
        .rfac-chip--status-scheduled.is-active,.rfac-chip--status-scheduled:hover{background:#dbeafe;color:#1d4ed8;border-color:#93c5fd;}
        </style>
        <?php
    }
}
