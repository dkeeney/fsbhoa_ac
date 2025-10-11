<?php
if ( ! defined( 'WPINC' ) ) { die; }

function fsbhoa_render_schedule_header_controls( $schedule_data, $all_schedules ) {
    ?>
    <div class="schedule-header-controls">
        <form id="fsbhoa-schedule-copy-form" style="display: contents;">
            <div class="form-field-group">
                <label for="source_schedule_id">Copy Rules From</label>
                <select name="source_schedule_id" id="source_schedule_id">
                    <?php foreach ($all_schedules as $schedule) : ?>
                        <?php if ($schedule->schedule_id != $schedule_data->schedule_id) : ?>
                            <option value="<?php echo esc_attr($schedule->schedule_id); ?>">
                                <?php echo esc_html($schedule->name); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field-group">
                <label>&nbsp;</label> <input type="hidden" name="destination_schedule_id" value="<?php echo esc_attr($schedule_data->schedule_id); ?>" />
                <button type="button" id="fsbhoa-copy-rules-button" class="button button-secondary">Copy Rules</button>
            </div>
        </form>
    </div>
    <?php
}
