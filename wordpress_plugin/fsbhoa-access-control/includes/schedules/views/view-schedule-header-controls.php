<?php
if ( ! defined( 'WPINC' ) ) { die; }

function fsbhoa_render_schedule_header_controls( $schedule_data, $all_schedules ) {
    ?>
    <div class="schedule-header-controls">
        <form class="schedule-header-form" method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="fsbhoa_update_schedule" />
            <input type="hidden" name="schedule_id" value="<?php echo esc_attr($schedule_data->schedule_id); ?>" />
            <?php wp_nonce_field('fsbhoa_update_schedule_nonce_' . $schedule_data->schedule_id); ?>

            <div class="form-field-group">
                <label for="schedule_name">Schedule Name</label>
                <input name="schedule_name" type="text" id="schedule_name" required value="<?php echo esc_attr($schedule_data->name); ?>">
            </div>
            <div class="form-field-group">
                <label for="start_date">Start Date</label>
                <input name="start_date" type="date" id="start_date" required value="<?php echo esc_attr($schedule_data->start_date); ?>">
            </div>
            <div class="form-field-group">
                <label for="end_date">End Date</label>
                <input name="end_date" type="date" id="end_date" required value="<?php echo esc_attr($schedule_data->end_date); ?>">
            </div>
            <div class="form-field-group">
                <label>&nbsp;</label>
                <button type="submit" class="button button-primary">Save Details</button>
            </div>
        </form>

        <form id="fsbhoa-schedule-copy-form" class="schedule-header-form">
            <div class="form-field-group">
                <label for="source_schedule_id">Copy Rules From</label>
                <select name="source_schedule_id" id="source_schedule_id">
                    <?php foreach ($all_schedules as $schedule) : ?>
                        <?php if ($schedule->schedule_id != $schedule_data->schedule_id) : ?>
                            <option value="<?php echo esc_attr($schedule->schedule_id); ?>"><?php echo esc_html($schedule->name); ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field-group">
                <label>&nbsp;</label>
                <input type="hidden" name="destination_schedule_id" value="<?php echo esc_attr($schedule_data->schedule_id); ?>" />
                <button type="button" id="fsbhoa-copy-rules-button" class="button button-secondary">Copy Rules</button>
            </div>
        </form>
    </div>
    <?php
}
