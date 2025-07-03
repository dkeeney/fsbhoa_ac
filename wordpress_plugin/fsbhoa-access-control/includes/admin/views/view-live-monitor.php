<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the HTML structure for the Live Activity Monitor page.
 */
function fsbhoa_render_live_monitor_view() {
    ?>
    <div class="fsbhoa-frontend-wrap monitor-page-override">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-900">Live Activity Monitor</h1>
            <div id="connection-status" class="flex items-center space-x-2 px-3 py-1 rounded-full bg-yellow-200 text-yellow-800 text-sm font-medium">
                <div class="w-2 h-2 bg-yellow-500 rounded-full"></div>
                <span>Connecting...</span>
            </div>
        </div>

        <!-- Top Section: Live Map -->
        <div class="bg-white rounded-xl shadow-md p-6 mb-8 max-w-2xl mx-auto">
            <h2 class="text-xl font-semibold mb-4">Community Status Map</h2>
                <div id="map-container" class="relative w-full bg-gray-200 rounded-lg overflow-hidden">
                    <img src="<?php echo esc_url(get_option('fsbhoa_monitor_map_url', '')); ?>" alt="Community Map" style="display: block; width: 100%; height: auto;">

                </div>
        </div>

        <!-- Bottom Section: Event Log -->
        <div>
            <h2 class="text-xl font-semibold mb-4">Today's Activity Log</h2>
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div id="event-log-container" class="h-[36rem] overflow-y-auto">
                    <ul id="event-list" class="divide-y divide-gray-200">
                        <li id="log-placeholder" class="p-4 text-center text-gray-500">
                            Waiting for live events...
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php
}

