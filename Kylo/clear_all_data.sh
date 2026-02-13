#!/bin/bash
# =====================================================
# KyloDB - CLEAR ALL DATA SCRIPT
# =====================================================
# This script COMPLETELY WIPES all persisted KyloDB data
# Use this when you want a fresh start (similar to DROP DATABASE *)
# =====================================================

echo "⚠️  WARNING: This will DELETE ALL KyloDB data!"
echo "   - All databases"
echo "   - All tables"
echo "   - All triggers/views/procedures/events"
echo "   - All indexes and constraints"
echo ""
read -p "Are you sure? (type 'yes' to confirm): " confirm

if [ "$confirm" != "yes" ]; then
    echo "❌ Aborted. No data was deleted."
    exit 0
fi

echo ""
echo "🗑️  Deleting kylo_system directory..."

# Change to Kylo project directory
cd /home/ivan/proyecto/Kylo

if [ -d "kylo_system" ]; then
    rm -rf kylo_system
    echo "✅ Deleted: kylo_system/"
else
    echo "ℹ️  kylo_system not found (already clean)"
fi

# Also remove any .tbl files in project root (if DiskManager creates them there)
if ls *.tbl 1> /dev/null 2>&1; then
    echo "🗑️  Deleting .tbl files in project root..."
    rm -f *.tbl
    echo "✅ Deleted: *.tbl files"
fi

# Remove database directories (if any exist in project root)
for db_dir in Default kylo_system sys DB*; do
    if [ -d "$db_dir" ]; then
        echo "🗑️  Deleting database directory: $db_dir/"
        rm -rf "$db_dir"
        echo "✅ Deleted: $db_dir/"
    fi
done

echo ""
echo "✅ ALL DATA CLEARED!"
echo ""
echo "Next steps:"
echo "1. Restart KyloDB server"
echo "2. The server will create fresh system tables"
echo "3. Default 'root' user will be recreated"
echo ""
