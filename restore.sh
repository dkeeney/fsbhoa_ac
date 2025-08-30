#!/bin/bash

# =================================================================
# WordPress Restore Script (Final Version)
#
# WARNING: THIS SCRIPT IS DESTRUCTIVE.
# It will overwrite your WordPress database and wp-content/uploads
# directory with the contents of the backup files provided.
# It relies on a ~/.my.cnf file for secure database credentials.
# =================================================================

# --- BEGIN CONFIGURATION ---

# Your WordPress database name
DB_NAME="fsbhoa_db"

# The full path to your WordPress installation's root directory
WP_PATH="/var/www/html"

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
# This command decompresses the backup and pipes it to the mysql client,
# which securely gets credentials from ~/.my.cnf.
gunzip < "$DB_BACKUP_FILE" | mysql "$DB_NAME"

# This correctly checks the exit status of the mysql command (the second
# command in the pipe) to ensure the database import was successful.
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
mv "$UPLOADS_DIR" "$UPLOADS_OLD_DIR" 2>/dev/null

echo "Restoring media library from: $UPLOADS_BACKUP_FILE..."
tar -xzf "$UPLOADS_BACKUP_FILE" -C "$WP_PATH/wp-content/"

if [ $? -ne 0 ]; then
  echo "ERROR: Media library restore failed. You may need to manually restore from $UPLOADS_OLD_DIR."
  exit 1
else
  echo "Media library restore complete."
fi

echo "-----------------------------------"
echo "WordPress restore process finished successfully."

exit 0

