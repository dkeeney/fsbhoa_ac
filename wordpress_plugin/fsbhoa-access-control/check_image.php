<?php
// Bootstrap WordPress
require_once('/var/www/html/wp-load.php');

global $wpdb;

// Get the first cardholder with a photo
$photo_blob = $wpdb->get_var("SELECT photo FROM ac_cardholders WHERE photo IS NOT NULL AND photo != '' LIMIT 1");

if (empty($photo_blob)) {
    echo "Could not find a cardholder with a photo.\n";
    exit;
}

// Get just the first 16 bytes of the blob
$image_header = substr($photo_blob, 0, 16);

// Save the header to a temporary file
$bytes_written = file_put_contents('/tmp/image_header.bin', $image_header);

if ($bytes_written) {
    echo "Successfully saved image header to /tmp/image_header.bin\n";
    echo "Please run the next command: file /tmp/image_header.bin\n";
} else {
    echo "Failed to write to /tmp/image_header.bin\n";
}

