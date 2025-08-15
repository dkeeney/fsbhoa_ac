#!/bin/bash

# =================================================================
# WordPress Backup Script
#
# This script creates a compressed backup of the WordPress database
# and the media library (wp-content/uploads directory).
# =================================================================

# --- BEGIN CONFIGURATION ---

# Your WordPress database name
DB_NAME="your_db_name"

# Your WordPress database username
DB_USER="your_db_user"

# Your WordPress database password
# IMPORTANT: For security, consider using a .my.cnf file to store credentials.
# See: https://dev.mysql.com/doc/refman/8.0/en/option-files.html
DB_PASS="your_db_password"

# The full path to your WordPress installation's root directory
# Example: /var/www/html or /home/user/public_html
WP_PATH="/path/to/your/wordpress"

# The directory where you want to store your backups
# Ensure this directory exists and has the correct permissions.
BACKUP_DIR="/path/to/your/backup_storage"

# --- END CONFIGURATION ---


# --- SCRIPT LOGIC (Do not edit below this line) ---

# Create a timestamp for the backup files (e.g., 2025-08-15)
TIMESTAMP=$(date +"%F")

# Check if backup directory exists, create if it doesn't
if [ ! -d "$BACKUP_DIR" ]; then
  echo "Backup directory $BACKUP_DIR does not exist. Creating it..."
  mkdir -p "$BACKUP_DIR"
fi

# Define backup file paths
DB_BACKUP_FILE="$BACKUP_DIR/wp_database_${TIMESTAMP}.sql.gz"
UPLOADS_BACKUP_FILE="$BACKUP_DIR/wp_uploads_${TIMESTAMP}.tar.gz"

echo "Starting WordPress backup process..."
echo "-----------------------------------"

# 1. Back up the Database
echo "Backing up database: $DB_NAME..."
mysqldump -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" | gzip > "$DB_BACKUP_FILE"

# Check if mysqldump was successful
if [ ${PIPESTATUS[0]} -ne 0 ]; then
  echo "ERROR: Database backup failed."
  exit 1
else
  echo "Database backup complete: $DB_BACKUP_FILE"
fi

echo "-----------------------------------"

# 2. Back up the Media Library (uploads directory)
echo "Backing up media library (wp-content/uploads)..."
tar -czf "$UPLOADS_BACKUP_FILE" -C "$WP_PATH/wp-content/" "uploads"

# Check if tar was successful
if [ $? -ne 0 ]; then
  echo "ERROR: Media library backup failed."
  exit 1
else
  echo "Media library backup complete: $UPLOADS_BACKUP_FILE"
fi

echo "-----------------------------------"
echo "WordPress backup process finished successfully."
echo ""

# Optional: Clean up old backups (older than 30 days)
echo "Cleaning up backups older than 30 days..."
find "$BACKUP_DIR" -type f -name "*.gz" -mtime +30 -exec rm {} \;
echo "Cleanup complete."

exit 0


