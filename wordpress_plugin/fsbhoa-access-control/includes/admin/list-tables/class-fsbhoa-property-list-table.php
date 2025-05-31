<?php
/**
 * Creates the WP_List_Table for Properties.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin/list-tables
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Fsbhoa_Property_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct( array(
            'singular' => __( 'Property', 'fsbhoa-ac' ),
            'plural'   => __( 'Properties', 'fsbhoa-ac' ),
            'ajax'     => false,
        ) );
    }

    public static function get_properties( $per_page = 20, $page_number = 1 ) {
        global $wpdb;
        $table_name = 'ac_property';

        $sql = "SELECT property_id, street_address, notes FROM {$table_name}";

        $orderby = isset( $_REQUEST['orderby'] ) ? sanitize_sql_orderby( $_REQUEST['orderby'] ) : 'street_address';
        $order   = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_key( $_REQUEST['order'] ) ) : 'ASC';
        
        $allowed_orderby = array('property_id', 'street_address');
        if ( !in_array(strtolower($orderby), $allowed_orderby) ) {
            $orderby = 'street_address';
        }
        if ( !in_array($order, array('ASC', 'DESC')) ) {
            $order = 'ASC';
        }
        $sql .= ' ORDER BY ' . $orderby . ' ' . $order;

        $sql .= " LIMIT $per_page";
        $sql .= ' OFFSET ' . ( $page_number - 1 ) * $per_page;

        $result = $wpdb->get_results( $sql, 'ARRAY_A' );
        return $result;
    }

    public static function record_count() {
        global $wpdb;
        $table_name = 'ac_property';
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
    }

    public function no_items() {
        _e( 'No properties found.', 'fsbhoa-ac' );
    }

    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'notes':
                return esc_html( $item[ $column_name ] );
            // case 'property_id': // If we decide to show it
            //      return absint( $item[ $column_name ] );
            default:
                return print_r( $item, true );
        }
    }

    /**
     * Method for Street Address column
     *
     * @param array $item an array of DB data
     * @return string
     */
    function column_street_address($item) {
        // Now just returns the title. Actions are moved to their own column.
        return '<strong>' . esc_html($item['street_address']) . '</strong>';
    }

    /**
     * Method for Actions column
     *
     * @param array $item an array of DB data (has 'property_id')
     * @return string
     */
    function column_actions($item) {
        $page_slug = sanitize_text_field(wp_unslash($_REQUEST['page'])); // e.g., 'fsbhoa_ac_properties'

        // Edit link with pencil icon
        $edit_url = sprintf(
            '?page=%s&action=%s&property_id=%s',
            $page_slug,
            'edit',
            absint($item['property_id'])
        );
        $edit_link = sprintf(
            '<a href="%s" title="%s"><span class="dashicons dashicons-edit"></span><span class="screen-reader-text">%s</span></a>',
            esc_url($edit_url),
            esc_attr__('Edit Property', 'fsbhoa-ac'),
            esc_html__('Edit', 'fsbhoa-ac')
        );

        // Placeholder for Delete link with trash icon (we'll implement delete functionality later)
        // $delete_nonce = wp_create_nonce('fsbhoa_delete_property_' . $item['property_id']); // More specific nonce
        // $delete_url = sprintf(
        //     '?page=%s&action=%s&property_id=%s&_wpnonce=%s',
        //     $page_slug,
        //     'delete',
        //     absint($item['property_id']),
        //     $delete_nonce
        // );
        // $delete_link = sprintf(
        //     '<a href="%s" title="%s" onclick="return confirm(\'%s\');" style="color:#a00;"><span class="dashicons dashicons-trash"></span><span class="screen-reader-text">%s</span></a>',
        //     esc_url($delete_url),
        //     esc_attr__('Delete Property', 'fsbhoa-ac'),
        //     esc_js(__('Are you sure you want to delete this property? This cannot be undone.', 'fsbhoa-ac')),
        //     esc_html__('Delete', 'fsbhoa-ac')
        // );

        // return $edit_link . '&nbsp;&nbsp;' . $delete_link; // When delete is ready
        return $edit_link;
    }


    function get_columns() {
        $columns = array(
            // 'cb'             => '<input type="checkbox" />', // For bulk actions (later)
            'actions'        => __( 'Actions', 'fsbhoa-ac' ), // New Actions column
            'street_address' => __( 'Street Address', 'fsbhoa-ac' ),
            'notes'          => __( 'Notes', 'fsbhoa-ac' ),
        );
        return $columns;
    }

    public function get_sortable_columns() {
        $sortable_columns = array(
            'street_address' => array( 'street_address', true ),
        );
        return $sortable_columns;
    }

    public function prepare_items() {
        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns(), $this->get_primary_column_name());
        
        $per_page     = $this->get_items_per_page( 'properties_per_page', 20 );
        $current_page = $this->get_pagenum();
        $total_items  = self::record_count();

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
        ) );
        $this->items = self::get_properties( $per_page, $current_page );
    }
    
    public function get_primary_column_name() {
        // If 'street_address' is primary, hover actions would appear there.
        // Since we have a dedicated 'actions' column, a primary column for hover actions is less critical.
        // But it's still good to define one for responsive views.
        return 'street_address'; 
    }
}

