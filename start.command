#!/bin/sh
# Encounter Manager: double-click on a Mac (or run ./start.command on Mac/Linux).
# Starts a private web server on this computer and opens the app in your browser.
cd "$(dirname "$0")" || exit 1
PORT="${PORT:-8765}"
URL="http://localhost:$PORT/"

if ! command -v php >/dev/null 2>&1; then
    echo "PHP isn't installed."
    echo "  Mac:   install Homebrew from https://brew.sh, then run:  brew install php"
    echo "  Linux: sudo apt install php-cli php-sqlite3 php-curl   (or your distro's equivalent)"
    echo "Then double-click start.command again."
    printf "Press Return to close. "; read -r _
    exit 1
fi

php -r 'exit(PHP_VERSION_ID >= 80100 && extension_loaded("pdo_sqlite") && extension_loaded("curl") ? 0 : 1);' || {
    echo "Your PHP needs to be 8.1 or newer with the pdo_sqlite and curl extensions."
    php -v | head -1
    printf "Press Return to close. "; read -r _
    exit 1
}

echo "Encounter Manager is running at $URL"
echo "Leave this window open while you play. Close it (or press Ctrl+C) to stop."
( sleep 1; (open "$URL" || xdg-open "$URL") >/dev/null 2>&1 ) &
exec php -S "127.0.0.1:$PORT" router.php
