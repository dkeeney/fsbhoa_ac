#!/bin/bash

# =================================================================
# WordPress Restore Script
#
# WARNING: THIS SCRIPT IS DESTRUCTIVE.
# It will overwrite your WordPress database and wp-content/uploads
# directory with the contents of the backup files provided.
# =================================================================

# --- BEGIN CONFIGURATION ---

# Your WordPress database name
DB_NAME="your_db_name"

# Your WordPress database username
DB_USER="your_db_user"

# Your WordPress database password
DB_PASS="your_db_password"

# The full path to your WordPress installation's root directory
WP_PATH="/path/to/your/wordpress"

# --- END CONFIGURATION ---


# --- SCRIPT LOGIC (Do not edit below this line) ---

# Check for required arguments
if [ "$#" -ne 2 ]; then
    echo "Usage: $0 <path_to_database_backup.sql.gz> <path_to_uploads_backup.tar.gz>"
    exit 1
fi

DB_BACKUP_FILE="$1"
UPLOADS_BACKUP_FILE="$2"

# Check if backup files exist
if [ ! -f "$DB_BACKUP_FILE" ]; then
    echo "ERROR: Database backup file not found: $DB_BACKUP_FILE"
    exit 1
fi
if [ ! -f "$UPLOADS_BACKUP_FILE" ]; then
    echo "ERROR: Uploads backup file not found: $UPLOADS_BACKUP_FILE"
    exit 1
fi

# CRITICAL: Final confirmation prompt
echo "!!!!!!!!!!!!!!!!!!!!!!!!!! WARNING !!!!!!!!!!!!!!!!!!!!!!!!!!"
echo "This script will PERMANENTLY OVERWRITE the following:"
echo "1. Database: '$DB_NAME'"
echo "2. Media Files: '$WP_PATH/wp-content/uploads'"
echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
read -p "Are you absolutely sure you want to continue? (y/n) " -n 1 -r
echo ""
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Restore operation cancelled."
    exit 1
fi

echo "Starting WordPress restore process..."
echo "-----------------------------------"

# 1. Restore the Database
echo "Restoring database from: $DB_BACKUP_FILE..."
gunzip < "$DB_BACKUP_FILE" | mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME"

# Check if mysql import was successful
if [ ${PIPESTATUS[1]} -ne 0 ]; then
  echo "ERROR: Database restore failed."
  exit 1
else
  echo "Database restore complete."
fi

echo "-----------------------------------"

# 2. Restore the Media Library
UPLOADS_DIR="$WP_PATH/wp-content/uploads"
UPLOADS_OLD_DIR="$WP_PATH/wp-content/uploads_old_$(date +"%F_%H%M%S")"

echo "Moving existing uploads directory to $UPLOADS_OLD_DIR..."
mv "$UPLOADS_DIR" "$UPLOADS_OLD_DIR"

echo "Restoring media library from: $UPLOADS_BACKUP_FILE..."
tar -xzf "$UPLOADS_BACKUP_FILE" -C "$WP_PATH/wp-content/"

if [ $? -ne 0 ]; then
  echo "ERROR: Media library restore failed. You may need to manually restore from $UPLOADS_OLD_DIR."
  exit 1
else
  echo "Media library restore complete."
fi

# Optional but highly recommended: Set correct file permissions
# The user/group (e.g., www-data) depends on your server setup.
# echo "Setting file permissions..."
# chown -R www-data:www-data "$UPLOADS_DIR"
# find "$UPLOADS_DIR" -type d -exec chmod 755 {} \;
# find "$UPLOADS_DIR" -type f -exec chmod 644 {} \;
# echo "Permissions set."

echo "-----------------------------------"
echo "WordPress restore process finished successfully."

exit 0

