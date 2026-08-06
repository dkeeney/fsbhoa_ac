<?php
/**
 * Handles all AJAX and admin-post actions for Cardholder management.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}


class Fsbhoa_Cardholder_Actions {

    public function __construct() {
        add_action('wp_ajax_fsbhoa_search_properties', array($this, 'ajax_search_properties_callback'));
        add_action('admin_post_fsbhoa_delete_cardholder', array($this, 'handle_delete_cardholder_action'));
        add_action('admin_post_fsbhoa_do_add_cardholder', array($this, 'handle_add_or_update_cardholder'));
        add_action('admin_post_fsbhoa_do_update_cardholder', array($this, 'handle_add_or_update_cardholder'));
        add_action('admin_post_fsbhoa_export_selected', array($this, 'handle_export_selected'));
        add_action('admin_post_fsbhoa_print_report', array($this, 'handle_print_report'));
        add_action('wp_ajax_fsbhoa_search_cardholders', array($this, 'ajax_search_cardholders_callback'));
        add_action('admin_post_fsbhoa_get_cardholder_photo', array($this, 'handle_get_cardholder_photo'));
    }

    public function ajax_search_properties_callback() {
        check_ajax_referer('fsbhoa_property_search_nonce', 'security');
        global $wpdb;
        $table_name = 'ac_property';
        $search_term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
        $results = array();
        if (strlen($search_term) >= 1) {
            $wildcard_search_term = '%' . $wpdb->esc_like($search_term) . '%';
            $properties = $wpdb->get_results( $wpdb->prepare( "SELECT property_id, street_address FROM {$table_name} WHERE street_address LIKE %s ORDER BY street_address ASC LIMIT 20", $wildcard_search_term ), ARRAY_A );

            if ( $wpdb->last_error ) {
                error_log('FSBHOA AJAX Property Search DB Error: ' . $wpdb->last_error);
                wp_send_json_error(array('message' => 'Database query failed.'));
                return;
            }

            if ($properties) {
                foreach ($properties as $property) {
                    $results[] = array( 'id' => $property['property_id'], 'label' => $property['street_address'], 'value' => $property['street_address'] );
                }
            }
        }
        wp_send_json_success($results);
    }

    public function handle_delete_cardholder_action() {
        global $wpdb;
        if ( ! isset($_GET['cardholder_id']) || ! is_numeric($_GET['cardholder_id']) ) {
            wp_die( esc_html__( 'Invalid cardholder ID specified.', 'fsbhoa-ac' ), esc_html__( 'Error', 'fsbhoa-ac' ), array( 'response' => 400, 'back_link' => true ) );
        }

        $item_id_to_delete = absint( $_GET['cardholder_id'] );
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';


        if ( ! wp_verify_nonce( $nonce, 'fsbhoa_delete_cardholder_nonce_' . $item_id_to_delete ) ) {
            wp_die( esc_html__( 'Security check failed. Could not archive cardholder.', 'fsbhoa-ac' ), esc_html__( 'Error', 'fsbhoa-ac' ), array( 'response' => 403, 'back_link' => true ) );
        }

        $rfid_id = $wpdb->get_var($wpdb->prepare("SELECT rfid_id FROM ac_cardholders WHERE id = %d", $item_id_to_delete));

        $result = fsbhoa_archive_and_delete_cardholder( $item_id_to_delete );

        $redirect_url = wp_get_referer();
        if ( ! $redirect_url ) {
            $page_object = get_page_by_path('cardholder');
            $redirect_url = $page_object ? get_permalink($page_object->ID) : home_url('/');
        }

        $redirect_url = remove_query_arg( array( 'action', 'cardholder_id', '_wpnonce' ), $redirect_url );

        if ( is_wp_error( $result ) ) {
            $error_string = $result->get_error_message();
            $redirect_url = add_query_arg( array( 'message' => 'cardholder_archive_error', 'error' => urlencode($error_string) ), $redirect_url );
        } else {
            $log_data = json_encode(['rfid_id' => $rfid_id, 'action' => 'delete']);
            fsbhoa_log_pending_change('cardholder', $item_id_to_delete, $log_data );
            $redirect_url = add_query_arg( array( 'message' => 'cardholder_archived_successfully' ), $redirect_url );
        }

        wp_safe_redirect( $redirect_url );
        exit;
    }

    public function handle_add_or_update_cardholder() {
        global $wpdb;
        $table_name = 'ac_cardholders';
        $is_update = ( isset($_POST['action']) && $_POST['action'] === 'fsbhoa_do_update_cardholder' );

        $form_page_url = wp_get_referer() ? wp_get_referer() : home_url('/');
        $list_page_url = remove_query_arg( array('action', 'cardholder_id', 'message'), $form_page_url );
        $item_id = $is_update ? (isset($_POST['cardholder_id']) ? absint($_POST['cardholder_id']) : 0) : 0;
        $nonce_action = $is_update ? 'fsbhoa_update_cardholder_action_' . $item_id : 'fsbhoa_add_cardholder_action';
        check_admin_referer($nonce_action, '_wpnonce');

        $existing_data = array();
        $submitted_groups = array();
        if ($is_update) {
            $existing_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $item_id), ARRAY_A);
            if ($wpdb->last_error) {
                wp_die(esc_html__('Database error: Could not retrieve cardholder data for editing. Please go back and try again. Error: ') . esc_html($wpdb->last_error), 'Database Error', array('back_link' => true));
            }
            if ($existing_data === null) {
                 wp_die(esc_html__('Error: The cardholder you are trying to edit could not be found. It may have been deleted.'), 'Not Found', array('back_link' => true));
            }
            $existing_groups = get_cardholder_group_memberships($item_id);
            $existing_data['groups_csv'] = implode(',', $existing_groups);
        }

        $view_path = FSBHOA_AC_PLUGIN_DIR . 'includes/cardholder/views/';
        require_once $view_path . 'view-cardholder-profile-section.php';
        require_once $view_path . 'view-cardholder-address-section.php';
        require_once $view_path . 'view-cardholder-photo-section.php';
        require_once $view_path . 'view-cardholder-rfid-section.php';

        $profile_results = fsbhoa_validate_profile_data($_POST);
        $address_results = fsbhoa_validate_address_data($_POST);
        $photo_results   = fsbhoa_validate_photo_data($_POST, $_FILES);
        $rfid_results    = fsbhoa_validate_rfid_data($_POST, $existing_data, $item_id, $is_update);

        $errors = array_merge($profile_results['errors'], $address_results['errors'], $photo_results['errors'], $rfid_results['errors']);
        $data_to_save = array_merge($existing_data, $profile_results['data'], $address_results['data'], $photo_results['data'], $rfid_results['data']);


        // Unset the 'active_rfid' key before saving. We cannot explicitly set the
        // value of a generated column, the database calculates it automatically.
        unset($data_to_save['active_rfid']);

        $sync_needed = false;
        if ( empty($errors) ) {
            // Note: when a cardholder is Live the values in ac_cardholder_groups table is authoritative.
            //       But when cardholder is archived, the 'groups_csv' is authoritative.
            $submitted_groups = isset($_POST['cardholder_groups']) ? (array) array_map('absint', $_POST['cardholder_groups']) : [];
            sort($submitted_groups);
            $data_to_save['groups_csv'] = implode(',', $submitted_groups);

            if ( $is_update ) {
                // Update condition
                // ---  RFID OVERWRITE DETECTION ---
                $new_rfid = (string) $data_to_save['rfid_id'];
                $old_rfid = (string) $existing_data['rfid_id'];

                if ( ! empty($old_rfid) && $old_rfid !== $new_rfid ) {
                    // Log the OLD RFID to be removed from the controller
                    $old_data = json_encode(['rfid_id' => $old_rfid, 'action' => 'delete']);
                    fsbhoa_log_pending_change('cardholder', $item_id, $old_data);
                }
                // Changes affect sync?  Compare strings for efficiency
                if ($new_rfid !== $old_rfid || 
                    $data_to_save['card_status'] !== $existing_data['card_status'] ||
                    $data_to_save['groups_csv'] !== $existing_data['groups_csv'])
                {
                    $sync_needed = true;
                }

                // Did the email change? If so, trigger the 5-second web sync
                if ( (string)$data_to_save['email'] !== (string)$existing_data['email'] ) {
                    if ( ! wp_next_scheduled( 'fsbhoa_instant_web_sync_event' ) ) {
                        wp_schedule_single_event( time() + 5, 'fsbhoa_instant_web_sync_event' );
                    }
                }

                $result = $wpdb->update( $table_name, $data_to_save, array('id' => $item_id) );
                if ($result === false) {
                    $errors['db_error'] = 'A database error occurred while updating. Please try again. Error: ' . $wpdb->last_error;
                    error_log('FSBHOA DB Update Error: ' . $wpdb->last_error);
                }
            } else {
                // Add Condition
                $result = $wpdb->insert( $table_name, $data_to_save );
                if ($result === false) {
                    $errors['db_error'] = 'A database error occurred while adding. Error: ' . $wpdb->last_error;
                    error_log('FSBHOA DB Insert Error: ' . $wpdb->last_error);
                } else {
                    $item_id = $wpdb->insert_id;
                    //  If a new user was added AND they have an email, trigger the 5-second web sync
                    if ( ! empty( $data_to_save['email'] ) ) {
                        if ( ! wp_next_scheduled( 'fsbhoa_instant_web_sync_event' ) ) {
                            wp_schedule_single_event( time() + 5, 'fsbhoa_instant_web_sync_event' );
                        }
                    }
                }
                if (isset($data_to_save['rfid_id']) && !empty($data_to_save['rfid_id'])) {
                    $sync_needed = true;
                }
            }
            if (empty($errors)) {
                $this->save_cardholder_groups($item_id, $submitted_groups);
            }
        }

        if ( ! empty($errors) ) {
            $user_id = get_current_user_id();
            $transient_key = 'fsbhoa_form_feedback_' . ($is_update ? 'edit_' . $item_id . '_' : 'add_') . $user_id;
            set_transient($transient_key, array('errors' => $errors, 'data' =>  wp_unslash($_POST)), MINUTE_IN_SECONDS * 5);
            wp_redirect( add_query_arg( array('message' => 'validation_error'), $form_page_url ) );
            exit;
        }

        $message_code = $is_update ? 'cardholder_updated' : 'cardholder_added';
        if ($sync_needed) {
            fsbhoa_log_pending_change('cardholder', $item_id);
        }

        if ( isset($_POST['fsbhoa_after_save_action']) && $_POST['fsbhoa_after_save_action'] === 'print' ) {
            $print_page = get_page_by_path('print-photo-id');
            if ($print_page) {
                $print_url = add_query_arg('cardholder_id', $item_id, get_permalink($print_page->ID));
                wp_redirect($print_url);
            } else {
                wp_redirect( admin_url('admin.php?page=fsbhoa_cardholder') );
            }
        } else {
            wp_redirect( add_query_arg( array('message' => $message_code), $list_page_url ) );
        }
        exit;
    }

    private function save_cardholder_groups($cardholder_id, $group_ids) {
        global $wpdb;
        $group_ids = array_map('absint', $group_ids);
        $wpdb->delete('ac_cardholder_groups', ['cardholder_id' => $cardholder_id]);
        if ($wpdb->last_error) {
            error_log('FSBHOA DB Error: Failed to delete old groups for cardholder ' . $cardholder_id . ': ' . $wpdb->last_error);
            return;
        }
        if (!empty($group_ids)) {
            foreach ($group_ids as $group_id) {
                $wpdb->insert('ac_cardholder_groups',
                    ['cardholder_id' => $cardholder_id, 'group_id' => $group_id,],
                    ['%d', '%d']
                );
                if ($wpdb->last_error) {
                    error_log('FSBHOA DB Error: Failed to insert group ' . $group_id . ' for cardholder ' . $cardholder_id . ': ' . $wpdb->last_error);
                }
            }
        }
    }

    public function handle_export_selected() {
        if ( empty( $_POST['cardholder'] ) ) { return; }
        $nonce = isset($_POST['_wpnonce']) ? $_POST['_wpnonce'] : '';
        if ( ! wp_verify_nonce($nonce, 'fsbhoa_export_nonce') ) {
            wp_die( 'Security check failed.' );
        }
        $cardholder_ids = array_map('absint', $_POST['cardholder']);
        if ( empty( $cardholder_ids ) ) { return; }

        global $wpdb;
        $ids_string = implode( ',', $cardholder_ids );
        $sql = "SELECT c.*, p.street_address
                FROM ac_cardholders c
                LEFT JOIN ac_property p ON c.property_id = p.property_id
                WHERE c.id IN ($ids_string)";

        $items_to_export = $wpdb->get_results( $sql, ARRAY_A );

        if ($wpdb->last_error) {
            wp_die('Database error while fetching cardholders for export: ' . esc_html($wpdb->last_error));
        }
        if ( empty( $items_to_export ) ) { return; }

        $default_group_names = $wpdb->get_col("SELECT group_name FROM ac_groups WHERE is_default = 1");

        foreach ($items_to_export as $key => $item) {
            $groups_query = $wpdb->prepare("SELECT g.group_name FROM ac_groups g JOIN ac_cardholder_groups cg ON g.group_id = cg.group_id WHERE cg.cardholder_id = %d", $item['id']);
            $explicit_group_names = $wpdb->get_col($groups_query);
            $all_group_names = array_merge($default_group_names, $explicit_group_names);
            $items_to_export[$key]['groups'] = implode(', ', array_unique($all_group_names));
            $photo_url = add_query_arg(['action' => 'fsbhoa_get_cardholder_photo', 'id' => $item['id']], admin_url('admin-post.php'));
            $items_to_export[$key]['photo_url'] = $photo_url;
        }

        $filename = 'cardholder-export-' . date('Y-m-d') . '.csv';
        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        $output = fopen( 'php://output', 'w' );
        $headers = array_keys( $items_to_export[0] );
        $photo_key = array_search('photo', $headers);
        if ($photo_key !== false) { unset($headers[$photo_key]); }
        fputcsv( $output, $headers );
        foreach ( $items_to_export as $item ) {
            if ( isset( $item['photo'] ) ) { unset( $item['photo'] ); }
            fputcsv( $output, $item );
        }
        fclose( $output );
        exit();
    }

    public function handle_print_report() {
        check_admin_referer('fsbhoa_print_report_nonce');
        if (empty($_POST['cardholder_ids']) || !is_array($_POST['cardholder_ids'])) {
            wp_die('Error: No cardholders were selected.');
        }
        $cardholder_ids = array_map('absint', $_POST['cardholder_ids']);
        $orderby_col = isset($_POST['orderby_col']) ? absint($_POST['orderby_col']) : 2;
        $order_dir = isset($_POST['order_dir']) && in_array(strtolower($_POST['order_dir']), ['asc', 'desc']) ? strtolower($_POST['order_dir']) : 'asc';

        $print_page_url = get_permalink(get_page_by_path('cardholder-pages'));
        if (!$print_page_url) {
            wp_die('Configuration Error: The "Cardholder Pages" page has not been created.');
        }

        $url_with_params = add_query_arg(array(
            'selected_ids' => implode(',', $cardholder_ids),
            'orderby_col'  => $orderby_col,
            'order_dir'    => $order_dir
        ), $print_page_url);

        wp_safe_redirect($url_with_params);
        exit;
    }

    /**
     * AJAX callback to search for live cardholders by name or address.
     * REFACTORED to exclude archived/purged users.
     */
    public function ajax_search_cardholders_callback() {
        check_ajax_referer('fsbhoa_cardholder_search_nonce', 'security');

        $term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
        if (strlen($term) < 2) {
            wp_send_json_success([]);
            return;
        }

        global $wpdb;
        $cardholders_table = 'ac_cardholders';
        $properties_table = 'ac_property';
        $wildcard_term = '%' . $wpdb->esc_like($term) . '%';

        // UPDATED QUERY: Added a WHERE clause to ensure we only search for active,
        // inactive, or disabled users, excluding archived and purged ones.
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.first_name, c.last_name, c.rfid_id, p.street_address
             FROM {$cardholders_table} c
             LEFT JOIN {$properties_table} p ON c.property_id = p.property_id
             WHERE (c.first_name LIKE %s
                OR c.last_name LIKE %s
                OR p.house_number LIKE %s
                OR p.street_name LIKE %s)
               AND c.card_status NOT IN ('archived', 'purged')
             LIMIT 10",
            $wildcard_term,
            $wildcard_term,
            $wildcard_term,
            $wildcard_term
        ));

        $suggestions = [];
        if ($results) {
            foreach ($results as $result) {
                $label = trim($result->first_name . ' ' . $result->last_name) . ' (' . ($result->street_address ?: 'No Address') . ')';
                $suggestions[] = [
                    'id' => $result->id,
                    'label' => $label,
                    'value' => $label,
                    'first_name' => $result->first_name,
                    'last_name' => $result->last_name,
                    'street_address' => $result->street_address,
                ];
            }
        }
        wp_send_json_success($suggestions);
    }

    public function handle_get_cardholder_photo() {
        if ( ! is_user_logged_in() || ! current_user_can('manage_options') ) {
            status_header(403);
            die('Access Denied.');
        }

        $cardholder_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if ( ! $cardholder_id ) {
            status_header(404);
            die('Not Found: Invalid ID.');
        }

        global $wpdb;
        $photo_data = $wpdb->get_var($wpdb->prepare("SELECT photo FROM ac_cardholders WHERE id = %d", $cardholder_id));

        if ( empty($photo_data) ) {
            status_header(404);
            die('Not Found: No photo available.');
        }

        header('Content-Type: image/jpeg');
        header('Content-Length: ' . strlen($photo_data));
        echo $photo_data;
        exit;
    }
}


