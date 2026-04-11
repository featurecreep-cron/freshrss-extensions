#!/bin/sh
# Extension Manager — queue processor
# Processes queued installs and removals from the UI when the extensions
# directory was read-only. Runs as root via docker exec.
#
# Usage:
#   docker exec freshrss sh /var/www/FreshRSS/extensions/xExtension-ExtensionManager/install-queued.sh

set -e

# Auto-detect FreshRSS data path
if [ -n "$EXTMGR_DATA_PATH" ]; then
    DATA_PATH="$EXTMGR_DATA_PATH"
elif [ -d "/var/www/FreshRSS/data" ]; then
    DATA_PATH="/var/www/FreshRSS/data"
elif [ -d "/config/www/freshrss/data" ]; then
    DATA_PATH="/config/www/freshrss/data"
fi

# Auto-detect extensions path
if [ -n "$EXTMGR_EXT_PATH" ]; then
    EXT_PATH="$EXTMGR_EXT_PATH"
elif [ -d "/var/www/FreshRSS/extensions" ]; then
    EXT_PATH="/var/www/FreshRSS/extensions"
elif [ -d "/config/www/freshrss/extensions" ]; then
    EXT_PATH="/config/www/freshrss/extensions"
fi

QUEUE_DIR="${DATA_PATH:-}/extmgr/queue"
MANIFEST="${DATA_PATH:-}/extmgr/manifest.json"

if [ ! -f "$MANIFEST" ]; then
    echo "[ExtMgr] No queued operations"
    exit 0
fi

if [ -z "$EXT_PATH" ]; then
    echo "[ExtMgr] Cannot detect extensions path"
    exit 1
fi

echo "[ExtMgr] Processing queued operations..."

# Process installs from queue directory
if [ -d "$QUEUE_DIR" ]; then
    for ext_dir in "$QUEUE_DIR"/xExtension-*; do
        [ -d "$ext_dir" ] || continue
        dir_name="$(basename "$ext_dir")"

        if [ ! -f "$ext_dir/metadata.json" ] || [ ! -f "$ext_dir/extension.php" ]; then
            echo "[ExtMgr] Skipping $dir_name — missing required files"
            continue
        fi

        if [ "$dir_name" = "xExtension-ExtensionManager" ]; then
            echo "[ExtMgr] Skipping $dir_name — cannot self-update via queue"
            continue
        fi

        target="$EXT_PATH/$dir_name"

        if [ -d "$target" ]; then
            echo "[ExtMgr] Updating $dir_name"
            rm -rf "${target}.bak"
            cp -r "$target" "${target}.bak"
            rm -rf "$target"
        else
            echo "[ExtMgr] Installing $dir_name"
        fi

        cp -r "$ext_dir" "$target"

        if [ -f "$target/metadata.json" ]; then
            echo "[ExtMgr] $dir_name — done"
            rm -rf "${target}.bak" 2>/dev/null || true
        else
            echo "[ExtMgr] $dir_name — FAILED, restoring backup"
            rm -rf "$target"
            if [ -d "${target}.bak" ]; then
                mv "${target}.bak" "$target"
            fi
        fi
    done
fi

# Process removals from manifest (entries with "action":"remove")
removals=$(php -r "
\$m = json_decode(file_get_contents('$MANIFEST'), true) ?: [];
foreach (\$m as \$k => \$v) {
    if ((\$v['action'] ?? '') === 'remove') echo \$k . PHP_EOL;
}
" 2>/dev/null) || true

if [ -n "$removals" ]; then
    for dir_name in $removals; do
        dir_name="$(basename "$dir_name")"
        target="$EXT_PATH/$dir_name"

        if [ "$dir_name" = "xExtension-ExtensionManager" ]; then
            echo "[ExtMgr] Skipping $dir_name — cannot remove Extension Manager"
            continue
        fi

        if [ -d "$target" ]; then
            echo "[ExtMgr] Removing $dir_name"
            rm -rf "$target"
            if [ ! -d "$target" ]; then
                echo "[ExtMgr] $dir_name — removed"
            else
                echo "[ExtMgr] $dir_name — FAILED to remove"
            fi
        else
            echo "[ExtMgr] $dir_name — not found, skipping"
        fi
    done
fi

rm -rf "$QUEUE_DIR"
rm -f "$MANIFEST"
echo "[ExtMgr] Queue processing complete — refresh FreshRSS in your browser"
