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

// WP_List_Table is not loaded automatically so we need to load it if it doesn't exist
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
    
        $sql = "SELECT property_id, house_number, street_name, notes FROM {$table_name}";
    
        $orderby = isset( $_REQUEST['orderby'] ) ? sanitize_sql_orderby( $_REQUEST['orderby'] ) : 'street_name';
        $order   = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_key( $_REQUEST['order'] ) ) : 'ASC';
    
        if ( 'street_address' === $orderby ) {
            // If the sort request is for the old 'street_address' column, apply our new multi-column sort.
            // CAST ensures numeric sorting for house numbers (e.g., 2, 10, 100).
            $sql .= ' ORDER BY street_name ' . $order . ', CAST(house_number AS UNSIGNED) ' . $order;
        } else {
            // Fallback for any other sortable columns you might add later
            $allowed_orderby = array('property_id', 'street_name', 'house_number');
            if ( !in_array(strtolower($orderby), $allowed_orderby) ) {
                $orderby = 'street_name';
            }
            $sql .= ' ORDER BY ' . $orderby . ' ' . $order;
        }
    
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
            // case 'property_id': // If you decide to show ID column
            //     return absint( $item[ $column_name ] );
            default:
                return print_r( $item, true ); 
        }
    }

    function column_street_address($item) {
        $address = trim(($item['house_number'] ?? '') . ' ' . ($item['street_name'] ?? ''));
        return '<strong>' . esc_html($address) . '</strong>';
    }

    function column_actions($item) {
        // Edit link remains the same for now
        $edit_url = sprintf('?page=%s&action=%s&property_id=%s',
            isset($_REQUEST['page']) ? sanitize_text_field(wp_unslash($_REQUEST['page'])) : '',
            'edit',
            absint($item['property_id'])
        );
        $edit_link = sprintf('<a href="%s" title="%s"><span class="dashicons dashicons-edit"></span><span class="screen-reader-text">%s</span></a>',
            esc_url($edit_url),
            esc_attr__('Edit Property', 'fsbhoa-ac'),
            esc_html__('Edit', 'fsbhoa-ac')
        );

        // --- MODIFIED Delete link ---
        $delete_nonce = wp_create_nonce('fsbhoa_delete_property_nonce_' . $item['property_id']); // Nonce action
        
        // Point to admin-post.php with a custom action
        $delete_url_params = array(
            'action'      => 'fsbhoa_delete_property', // Our custom admin_post action
            'property_id' => absint($item['property_id']),
            '_wpnonce'    => $delete_nonce
        );
        $delete_url = add_query_arg($delete_url_params, admin_url('admin-post.php'));

        $delete_link = sprintf(
            '<a href="%s" title="%s" onclick="return confirm(\'%s\');" style="color:#a00;"><span class="dashicons dashicons-trash"></span><span class="screen-reader-text">%s</span></a>',
            esc_url($delete_url),
            esc_attr__('Delete Property', 'fsbhoa-ac'),
            esc_js(__('Are you sure you want to delete this property? This action cannot be undone.', 'fsbhoa-ac')),
            esc_html__('Delete', 'fsbhoa-ac')
        );
        // --- END MODIFIED Delete link ---

        return $edit_link . '&nbsp;&nbsp;' . $delete_link;
    }

    function get_columns() {
        $columns = array(
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
        return 'street_address'; 
    }
   // NOTE: The next line is the end of the file. 
}
