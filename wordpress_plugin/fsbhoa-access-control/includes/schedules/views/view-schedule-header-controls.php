<?php
if ( ! defined( 'WPINC' ) ) { die; }

function fsbhoa_render_schedule_header_controls( $schedule_data, $all_schedules, $group_data ) {
    $schedules_page_url = get_permalink(get_page_by_path('schedules'));
    ?>
    <style>
        .schedule-header-controls {
            display: flex !important;
            flex-wrap: wrap !important;
            align-items: flex-end !important; /* This forces bottom-alignment */
            gap: 15px !important;
            padding: 1em;
            background-color: #f6f7f7;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            margin-bottom: 2em;
        }
        .schedule-header-controls .form-field-group {
            display: flex;
            flex-direction: column;
            margin: 0;
        }
        .schedule-header-controls .form-field-group label {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .schedule-header-controls .form-field-group input,
        .schedule-header-controls .form-field-group select {
            margin: 0;
        }
        .schedule-header-controls .form-field-group.separator {
            border-left: 1px solid #ddd;
            padding-left: 15px;
            margin-left: 5px;
        }
        .schedule-header-controls .checkbox-options-inline {
            display: flex;
            gap: 15px;
            padding-top: 5px;
        }
        .schedule-header-controls .checkbox-options-inline label {
            font-weight: normal;
            display: flex;
            align-items: center;
            gap: 5px;
        }
    </style>
    <div class="schedule-header-controls">
        <form id="fsbhoa-schedule-edit-form" method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: contents;">
            <input type="hidden" name="action" value="fsbhoa_save_schedule_group" />
            <input type="hidden" name="group_id" value="<?php echo esc_attr($group_data->group_id); ?>" />
            <input type="hidden" name="schedule_id" value="<?php echo esc_attr($schedule_data->schedule_id); ?>" />
            <?php wp_nonce_field('fsbhoa_save_group', 'fsbhoa_group_nonce'); ?>

            <div class="form-field-group">
                <label for="group_name">Group Name</label>
                <input name="group_name" type="text" id="group_name" required value="<?php echo esc_attr($group_data->group_name ?? ''); ?>">
            </div>
            <div class="form-field-group separator">
                <label>Options</label>
                <div class="checkbox-options-inline">
                     <label title="Grants 24/7 access to all doors, overriding any specific rules below.">
                        <input type="checkbox" name="has_all_access" value="1" <?php checked($group_data->has_all_access ?? 0, 1); ?>> All Access
                    </label>
                     <label title="Assigns this group to all new cardholders by default.">
                        <input type="checkbox" name="is_default" value="1" <?php checked($group_data->is_default ?? 0, 1); ?>> Default Group
                    </label>
                </div>
            </div>
            <div class="form-field-group" style="margin-left: auto;">
                 <label>&nbsp;</label>
                 <button type="submit" class="button button-primary">Save Group Details</button>
            </div>
        </form>

        <form id="fsbhoa-schedule-copy-form" style="display: contents;">
            <div class="form-field-group separator">
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
                <label>&nbsp;</label>
                <input type="hidden" name="destination_schedule_id" value="<?php echo esc_attr($schedule_data->schedule_id); ?>" />
                <button type="button" id="fsbhoa-copy-rules-button" class="button button-secondary">Copy Rules</button>
            </div>
        </form>
    </div>
    <?php
}
