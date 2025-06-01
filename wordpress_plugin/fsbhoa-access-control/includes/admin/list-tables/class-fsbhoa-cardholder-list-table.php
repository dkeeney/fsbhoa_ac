<?php
/**
 * Creates the WP_List_Table for Cardholders.
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

class Fsbhoa_Cardholder_List_Table extends WP_List_Table {

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct( array(
            'singular' => __( 'Cardholder', 'fsbhoa-ac' ),
            'plural'   => __( 'Cardholders', 'fsbhoa-ac' ),
            'ajax'     => false, 
        ) );
    }

    /**
     * Retrieve cardholders data from the database.
     *
     * @param int $per_page
     * @param int $page_number
     * @return array
     */
    public static function get_cardholders( $per_page = 20, $page_number = 1 ) {
        global $wpdb;
        $cardholders_table = 'ac_cardholders';
        $properties_table = 'ac_property';

        // Columns to select: c.* for all from cardholders, p.street_address for property
        $sql = "SELECT c.*, p.street_address 
                FROM {$cardholders_table} c
                LEFT JOIN {$properties_table} p ON c.property_id = p.property_id";

        // Handle sorting
        $orderby = isset( $_REQUEST['orderby'] ) ? sanitize_sql_orderby( $_REQUEST['orderby'] ) : 'last_name'; // Default sort
        $order   = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_key( $_REQUEST['order'] ) ) : 'ASC';
        
        $allowed_orderby = array('last_name', 'first_name', 'street_address', 'resident_type', 'email'); // Add more as needed
        if ( $orderby === 'full_name') { // Special handling for combined name sort
            $orderby_sql = 'c.last_name ' . $order . ', c.first_name ' . $order;
        } elseif ( $orderby === 'street_address') {
            $orderby_sql = 'p.street_address ' . $order;
        } elseif (in_array(strtolower($orderby), $allowed_orderby) ) {
            $orderby_sql = 'c.' . $orderby . ' ' . $order; // Prefix with 'c.' for cardholder fields
        } else {
            $orderby_sql = 'c.last_name ASC, c.first_name ASC'; // Default
        }
        $sql .= ' ORDER BY ' . $orderby_sql;

        $sql .= " LIMIT $per_page";
        $sql .= ' OFFSET ' . ( $page_number - 1 ) * $per_page;

        $result = $wpdb->get_results( $sql, 'ARRAY_A' );
        return $result;
    }

    /**
     * Get the total number of cardholders.
     *
     * @return int
     */
    public static function record_count() {
        global $wpdb;
        $table_name = 'ac_cardholders';
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
    }

    public function no_items() {
        _e( 'No cardholders found.', 'fsbhoa-ac' );
    }

    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'email':
            case 'phone':
            case 'resident_type':
                return esc_html( $item[ $column_name ] );
            // case 'property_id': // if you were displaying raw ID
            //    return $item[ $column_name ] ? absint( $item[ $column_name ] ) : 'N/A';
            default:
                return print_r( $item, true ); // For troubleshooting
        }
    }

    /**
     * Render the "Full Name" column (custom column).
     * It combines first_name and last_name.
     *
     * @param array $item
     * @return string
     */
    public function column_full_name( $item ) {
        $name_parts = array();
        if ( ! empty( $item['first_name'] ) ) {
            $name_parts[] = $item['first_name'];
        }
        if ( ! empty( $item['last_name'] ) ) {
            $name_parts[] = $item['last_name'];
        }
        $full_name = implode( ' ', $name_parts );
        return '<strong>' . esc_html( $full_name ) . '</strong>';
    }
    
    /**
     * Render the "Property" column (displays street_address via JOIN).
     *
     * @param array $item
     * @return string
     */
    public function column_property( $item ) {
        return isset($item['street_address']) ? esc_html($item['street_address']) : '<em>' . __('N/A', 'fsbhoa-ac') . '</em>';
    }


    public function column_actions($item) {
        $page_slug = isset($_REQUEST['page']) ? sanitize_text_field(wp_unslash($_REQUEST['page'])) : ''; 

        // Edit link (placeholder functionality for now)
        $edit_url = sprintf('?page=%s&action=%s&cardholder_id=%s', $page_slug, 'edit_cardholder', absint($item['id']));
        $edit_link = sprintf('<a href="%s" title="%s"><span class="dashicons dashicons-edit"></span><span class="screen-reader-text">%s</span></a>',
            esc_url($edit_url),
            esc_attr__('Edit Cardholder', 'fsbhoa-ac'),
            esc_html__('Edit', 'fsbhoa-ac')
        );

        // Delete link (placeholder functionality for now)
        $delete_nonce = wp_create_nonce('fsbhoa_delete_cardholder_nonce_' . $item['id']);
        $delete_url_params = array(
            'action'        => 'fsbhoa_delete_cardholder', // Custom admin_post action
            'cardholder_id' => absint($item['id']),
            '_wpnonce'      => $delete_nonce
        );
        $delete_url = add_query_arg($delete_url_params, admin_url('admin-post.php'));
        $delete_link = sprintf('<a href="%s" title="%s" onclick="return confirm(\'%s\');" style="color:#a00;"><span class="dashicons dashicons-trash"></span><span class="screen-reader-text">%s</span></a>',
            esc_url($delete_url),
            esc_attr__('Delete Cardholder', 'fsbhoa-ac'),
            esc_js(__('Are you sure you want to delete this cardholder?', 'fsbhoa-ac')),
            esc_html__('Delete', 'fsbhoa-ac')
        );

        return $edit_link . '&nbsp;&nbsp;' . $delete_link;
    }

    function get_columns() {
        $columns = array(
            'actions'       => __( 'Actions', 'fsbhoa-ac' ),
            'full_name'     => __( 'Name', 'fsbhoa-ac' ),
            'property'      => __( 'Property', 'fsbhoa-ac' ),
            'resident_type' => __( 'Resident Type', 'fsbhoa-ac' ),
            'email'         => __( 'Email', 'fsbhoa-ac' ),
            'phone'         => __( 'Phone', 'fsbhoa-ac' ),
        );
        return $columns;
    }

    public function get_sortable_columns() {
        $sortable_columns = array(
            'full_name'     => array( 'last_name', true ), // Sort by last_name initially
            'property'      => array( 'street_address', false ),
            'resident_type' => array( 'resident_type', false ),
            'email'         => array( 'email', false ),
        );
        return $sortable_columns;
    }

    public function prepare_items() {
        $this->_column_headers = array($this->get_columns(), array() /*hidden*/, $this->get_sortable_columns(), $this->get_primary_column_name());
        
        $per_page     = $this->get_items_per_page( 'cardholders_per_page', 20 );
        $current_page = $this->get_pagenum();
        $total_items  = self::record_count();

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
        ) );
        $this->items = self::get_cardholders( $per_page, $current_page );
    }
    
    public function get_primary_column_name() {
        return 'full_name'; 
    }
}

