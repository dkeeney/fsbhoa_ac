<?php
/**
 * Creates the WP_List_Table for displaying deleted cardholders.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin/list-tables
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

// Load the base WP_List_Table class if it's not already loaded
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Fsbhoa_Deleted_Cardholder_List_Table extends WP_List_Table {

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct( [
            'singular' => __( 'Deleted Cardholder', 'fsbhoa-ac' ), // Singular name of the listed records
            'plural'   => __( 'Deleted Cardholders', 'fsbhoa-ac' ),// Plural name of the listed records
            'ajax'     => false // Does this table support AJAX?
        ] );
    }

    /**
     * Get a list of columns.
     *
     * @return array An associative array of columns.
     */
    public function get_columns() {
        $columns = [
            // The 'full_name' column will be custom rendered to include actions
            'full_name'  => __( 'Name', 'fsbhoa-ac' ),
            'rfid_id'    => __( 'RFID ID', 'fsbhoa-ac' ),
            'email'      => __( 'Email', 'fsbhoa-ac' ),
            'phone'      => __( 'Phone', 'fsbhoa-ac' ),
            'deleted_at' => __( 'Date Deleted', 'fsbhoa-ac' )
        ];
        return $columns;
    }

    /**
     * Get a list of sortable columns.
     *
     * @return array An associative array of sortable columns.
     */
    protected function get_sortable_columns() {
        return [
            'full_name'  => [ 'last_name', true ], // Sort by 'last_name' when 'Name' is clicked
            'rfid_id'    => [ 'rfid_id', false ],
            'deleted_at' => [ 'deleted_at', true ] // Default sort column
        ];
    }

    /**
     * Handles the rendering of the 'Name' column to include row actions.
     *
     * @param array $item The item data for the current row.
     * @return string The content for the column.
     */
    protected function column_full_name( $item ) {
        // Combine first and last name for display
        $name = '<strong>' . esc_html( $item['first_name'] . ' ' . $item['last_name'] ) . '</strong>';

        // Create a nonce for the restore action
        $restore_nonce = wp_create_nonce( 'fsbhoa_restore_cardholder_' . $item['id'] );

        // Build the URL for the restore action, pointing to admin-post.php
        $restore_url = add_query_arg(
            [
                'action'        => 'fsbhoa_restore_deleted_cardholder',
                'cardholder_id' => absint( $item['id'] ),
                '_wpnonce'      => $restore_nonce
            ],
            admin_url( 'admin-post.php' )
        );

        // Define the actions
        $actions = [
            'preview' => sprintf(
                '<a href="?page=%s&action=%s&cardholder_id=%s">Preview</a>',
                esc_attr( $_REQUEST['page'] ),
                'preview_deleted', // Use a unique action name
                absint( $item['id'] )
            ),
            'restore' => sprintf(
                '<a href="%s" onclick="return confirm(\'Are you sure you want to restore this cardholder?\');">Restore</a>',
                esc_url( $restore_url )
            )
        ];

        return $name . $this->row_actions( $actions );
    }


    /**
     * Default column rendering.
     *
     * @param array  $item The item data for the current row.
     * @param string $column_name The name of the column.
     * @return string The content for the column.
     */
    protected function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'rfid_id':
            case 'email':
            case 'phone':
                return esc_html( $item[ $column_name ] );
            case 'deleted_at':
                // Format the date for better readability
                return date( 'Y-m-d H:i:s', strtotime( $item[ $column_name ] ) );
            default:
                return print_r( $item, true ); // Show the whole array for any other column
        }
    }

    /**
     * Prepares the list of items for display.
     */
    public function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ac_deleted_cardholders';

        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

        // --- Pagination ---
        $per_page     = $this->get_items_per_page( 'deleted_cardholders_per_page', 20 );
        $current_page = $this->get_pagenum();
        $total_items  = $wpdb->get_var( "SELECT COUNT(id) FROM $table_name" );

        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page
        ] );

        // --- Sorting ---
        $orderby = ( ! empty( $_GET['orderby'] ) ) ? sanitize_sql_orderby( $_GET['orderby'] ) : 'deleted_at';
        $order   = ( ! empty( $_GET['order'] ) ) ? sanitize_key( $_GET['order'] ) : 'desc';

        // --- Fetching Data ---
        $offset = ( $current_page - 1 ) * $per_page;
        $query = $wpdb->prepare(
            "SELECT * FROM {$table_name} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );
        
        $this->items = $wpdb->get_results( $query, ARRAY_A );
    }
}


