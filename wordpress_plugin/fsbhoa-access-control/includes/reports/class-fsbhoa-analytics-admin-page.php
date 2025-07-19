<?php
/**
 * Creates the admin page for viewing usage analytics reports.
 */
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Analytics_Admin_Page {

    /**
     * Handles the display of the analytics page.
     */
    public function render_page() {
        ?>
        <div class="wrap fsbhoa-frontend-wrap">
            <div id="usage-analytics" class="fsbhoa-tab-content">
                <div class="fsbhoa-filter-container">
                     <div class="filter-group">
                        <label for="analytics-month">Month</label>
                        <select id="analytics-month">
                            <?php
                            $current_month = date('m');
                            for ($i = 1; $i <= 12; $i++) {
                                $month_val = str_pad($i, 2, '0', STR_PAD_LEFT);
                                $month_name = date('F', mktime(0, 0, 0, $i, 10));
                                echo '<option value="' . esc_attr($month_val) . '" ' . selected($current_month, $month_val, false) . '>' . esc_html($month_name) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="analytics-year">Year</label>
                        <select id="analytics-year">
                            <?php
                            $current_year = date('Y');
                            for ($i = $current_year; $i >= $current_year - 5; $i--) {
                                echo '<option value="' . esc_attr($i) . '">' . esc_html($i) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <div class="fsbhoa-charts-container">
                    <div class="chart-wrapper" id="gate-usage-chart-container">
                         <h3>Usage by Gate</h3>
                        <canvas id="gate-usage-chart"></canvas>
                    </div>
                    <div class="chart-wrapper" id="peak-hours-chart-container">
                        <h3>Peak Usage Hours</h3>
                        <canvas id="peak-hours-chart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

