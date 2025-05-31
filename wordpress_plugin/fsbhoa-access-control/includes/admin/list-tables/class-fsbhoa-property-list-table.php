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

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct( array(
            'singular' => __( 'Property', 'fsbhoa-ac' ), // Singular name of the listed records
            'plural'   => __( 'Properties', 'fsbhoa-ac' ), // Plural name of the listed records
            'ajax'     => false, // Does this table support ajax?
        ) );
    }

    /**
     * Retrieve properties data from the database.
     *
     * @param int $per_page
     * @param int $page_number
     * @return array
     */
    public static function get_properties( $per_page = 20, $page_number = 1 ) {
        global $wpdb;
        $table_name = 'ac_property'; // Our property table name

        $sql = "SELECT property_id, street_address, notes FROM {$table_name}";

        // Handle sorting
        $orderby = isset( $_REQUEST['orderby'] ) ? sanitize_sql_orderby( $_REQUEST['orderby'] ) : 'street_address';
        $order   = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_key( $_REQUEST['order'] ) ) : 'ASC';
        
        // Ensure $orderby is a valid column name to prevent SQL injection
        $allowed_orderby = array('property_id', 'street_address');
        if ( !in_array(strtolower($orderby), $allowed_orderby) ) {
            $orderby = 'street_address'; // Default to a safe column
        }
        if ( !in_array($order, array('ASC', 'DESC')) ) {
            $order = 'ASC'; // Default to ASC
        }
        $sql .= ' ORDER BY ' . $orderby . ' ' . $order;


        $sql .= " LIMIT $per_page";
        $sql .= ' OFFSET ' . ( $page_number - 1 ) * $per_page;

        $result = $wpdb->get_results( $sql, 'ARRAY_A' );

        return $result;
    }

    /**
     * Get the total number of properties.
     *
     * @return int
     */
    public static function record_count() {
        global $wpdb;
        $table_name = 'ac_property';
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
    }

    /**
     * Text displayed when no property data is available.
     */
    public function no_items() {
        _e( 'No properties found.', 'fsbhoa-ac' );
    }

    /**
     * Render a column when no custom render function exists.
     *
     * @param array  $item
     * @param string $column_name
     * @return mixed
     */
    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'notes':
                return esc_html( $item[ $column_name ] );
            case 'property_id': // If we decide to show it
                 return absint( $item[ $column_name ] );
            default:
                return print_r( $item, true ); // Show the whole array for troubleshooting
        }
    }

    /**
     * Method for Name column (Street Address)
     *
     * @param array $item an array of DB data
     * @return string
     */
    function column_street_address($item) {
        $title = '<strong>' . esc_html($item['street_address']) . '</strong>';

        // TODO: Add Edit/Delete actions later
        $actions = array(
            // 'edit' => sprintf('<a href="?page=%s&action=%s&property_id=%s">' . __('Edit', 'fsbhoa-ac') . '</a>', esc_attr($_REQUEST['page']), 'edit', absint($item['property_id'])),
            // 'delete' => sprintf('<a href="?page=%s&action=%s&property_id=%s&_wpnonce=%s">' . __('Delete', 'fsbhoa-ac') . '</a>', esc_attr($_REQUEST['page']), 'delete', absint($item['property_id']), wp_create_nonce('fsbhoa_delete_property')),
        );
        // For now, no actions, just title
        // return $title . $this->row_actions($actions);
        return $title;
    }


    /**
     * Associative array of columns
     *
     * @return array
     */
    function get_columns() {
        $columns = array(
            // 'cb'             => '<input type="checkbox" />', // For bulk actions (later)
            'street_address' => __( 'Street Address', 'fsbhoa-ac' ),
            'notes'          => __( 'Notes', 'fsbhoa-ac' ),
            // 'property_id'    => __( 'ID', 'fsbhoa-ac' ) // Optional: display ID
        );
        return $columns;
    }

    /**
     * Columns to make sortable.
     *
     * @return array
     */
    public function get_sortable_columns() {
        $sortable_columns = array(
            'street_address' => array( 'street_address', true ), // true means it's already sorted by this column initially
            // 'property_id'    => array( 'property_id', false ),
        );
        return $sortable_columns;
    }

    /**
     * Handles data query and filter, sorting, and pagination.
     */
    public function prepare_items() {
        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns(), $this->get_primary_column_name());
        
        $per_page     = $this->get_items_per_page( 'properties_per_page', 20 );
        $current_page = $this->get_pagenum();
        $total_items  = self::record_count();

        $this->set_pagination_args( array(
            'total_items' => $total_items, // WE have to calculate the total number of items
            'per_page'    => $per_page, // WE have to determine how many items to show on a page
        ) );

        $this->items = self::get_properties( $per_page, $current_page );
    }
    
    /**
     * Define which column is primary
     *
     * @return string
     */
    public function get_primary_column_name() {
        return 'street_address'; // The 'street_address' column will have the row actions
    }
}

