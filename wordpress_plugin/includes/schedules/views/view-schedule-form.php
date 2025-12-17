<?php
if ( ! defined( 'WPINC' ) ) { die; }

function fsbhoa_render_schedule_form() {
    $schedules_page_url = get_permalink(get_page_by_path('schedules'));
    ?>
    <form id="fsbhoa-schedule-form" method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="fsbhoa_save_schedule" />
        <?php wp_nonce_field('fsbhoa_save_schedule_nonce'); ?>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="schedule_name">Schedule Name</label></th>
                    <td><input name="schedule_name" type="text" id="schedule_name" class="regular-text" required placeholder="e.g., Christmas Day"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="start_date">Start Date</label></th>
                    <td><input name="start_date" type="date" id="start_date" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="end_date">End Date</label></th>
                    <td>
                        <input name="end_date" type="date" id="end_date" required>
                        <p class="description">For single-day holidays, the Start and End dates should be the same.</p>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <p class="submit">
            <button type="submit" class="button button-primary">Save Schedule</button>
            <a href="<?php echo esc_url($schedules_page_url); ?>" class="button button-secondary">Cancel</a>
        </p>
    </form>
    <?php
}
