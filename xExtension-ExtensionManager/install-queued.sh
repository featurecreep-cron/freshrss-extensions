#!/bin/sh
# Extension Manager — entrypoint wrapper
# Processes queued extension installs, then hands off to the real entrypoint.
#
# Usage in docker-compose.yml (official FreshRSS image):
#   entrypoint: /var/www/FreshRSS/extensions/xExtension-ExtensionManager/install-queued.sh
#
# Usage in docker-compose.yml (linuxserver/freshrss):
#   entrypoint: /config/www/freshrss/extensions/xExtension-ExtensionManager/install-queued.sh

set -e

# --- Process queued installs ---

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

if [ -d "$QUEUE_DIR" ] && [ -f "$MANIFEST" ] && [ -n "$EXT_PATH" ]; then
    echo "[ExtMgr] Processing queued extension installs..."

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

    rm -rf "$QUEUE_DIR"
    rm -f "$MANIFEST"
    echo "[ExtMgr] Queue processing complete"
fi

# Mark that the entrypoint wrapper has run at least once
if [ -n "$DATA_PATH" ] && [ -d "$DATA_PATH" ]; then
    mkdir -p "$DATA_PATH/extmgr"
    touch "$DATA_PATH/extmgr/.entrypoint-configured"
fi

# --- Hand off to the real entrypoint ---

# The FreshRSS CMD is a compound shell expression, not a simple binary.
# We source the entrypoint (which does setup then exec "$@") and pass
# the original CMD through.
if [ -f "/var/www/FreshRSS/Docker/entrypoint.sh" ]; then
    # Official image (Debian): CMD from Dockerfile
    exec /var/www/FreshRSS/Docker/entrypoint.sh \
        sh -c '([ -z "$CRON_MIN" ] || cron) && . /etc/apache2/envvars && exec apache2 -D FOREGROUND $([ -n "$OIDC_ENABLED" ] && [ "$OIDC_ENABLED" -ne 0 ] && echo "-D OIDC_ENABLED")'
elif [ -x "/init" ]; then
    exec /init
else
    echo "[ExtMgr] Warning: could not find FreshRSS entrypoint"
    exit 1
fi
