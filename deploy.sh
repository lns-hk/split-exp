#!/bin/bash
# Build the front end and copy split-exp to a web server.
#
#   bash deploy.sh <docroot> <base-path> <app-dir>
#
#   docroot    web server document root, e.g. /var/www/html
#   base-path  URL path the app is served under, with slashes, e.g. /split-exp/
#   app-dir    a directory OUTSIDE the document root for the PHP code and data, e.g. /srv/split-exp
#
# Result: <docroot><base-path> holds only static files + the two entry scripts;
#         <app-dir>/server holds the PHP library and CLI; <app-dir>/data is yours (config, SQLite, backups).
set -euo pipefail
DOCROOT="${1:?docroot}"; BASE="${2:?base path, e.g. /split-exp/}"; APP="${3:?app dir outside the docroot}"
case "$BASE" in /*/) ;; *) echo "base path must start and end with /"; exit 1;; esac
HERE="$(cd "$(dirname "$0")" && pwd)"; DST="$DOCROOT${BASE%/}"
SED_I=(-i ''); sed --version >/dev/null 2>&1 && SED_I=(-i)   # GNU vs BSD sed

cd "$HERE" && npm run build >/dev/null
V=$(cat public/assets/app.js public/assets/app.css | shasum -a 256 | cut -c1-10)
mkdir -p "$DST/assets" "$DST/api" "$DST/admin" "$APP/server/lib" "$APP/data"
for f in index.html privacy.html terms.html; do
  sed -e "s#<base href=\"/split-exp/\">#<base href=\"$BASE\">#" \
      -e "s#assets/app.js\"#assets/app.js?v=$V\"#" -e "s#assets/app.css\"#assets/app.css?v=$V\"#" "public/$f" > "$DST/$f"
done
cp public/assets/*.js public/assets/*.css "$DST/assets/"
for d in api admin; do
  sed "s#dirname(__DIR__, 2) . '/server/lib'#'$APP/server/lib'#" "public/$d/index.php" > "$DST/$d/index.php"
  cp "public/$d/.htaccess" "$DST/$d/.htaccess"
done
sed "s#__BASE__#$BASE#" public/.htaccess > "$DST/.htaccess"
cp server/lib/*.php server/lib/schema.sql "$APP/server/lib/"
cp server/*.php "$APP/server/"
[ -f "$APP/data/config.php" ] || { cp server/config.example.php "$APP/data/config.php"; echo "created $APP/data/config.php — edit it"; }
echo "deployed $BASE (assets v=$V)"
