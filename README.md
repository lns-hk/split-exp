# split-exp

A small, self-hosted trip expense splitter. No accounts: whoever holds the link can see or edit the trip. It is
built for real trips with mixed currencies, where the bill comes out the same on every phone.

Live at **[lns.hk/split-exp](https://lns.hk/split-exp/)**. The UI is in Traditional Chinese (繁體中文).

> 旅行分帳：不用註冊，憑連結查看或編輯；多貨幣、每筆支出鎖定匯率、整數金額運算，結算結果在每部手機都一樣。

## What it does

- **Trips by link.** Creating a trip gives you an edit link and a read-only link. Tokens are 22 random base62
  characters. The server stores only their SHA-256, so a lost link cannot be recovered. It can only be reissued from admin.
- **Mixed currencies, frozen rates.** Each expense keeps the exchange rate it was entered with (`rate_e8`, the rate × 10⁸).
  The "today's rate" button fetches a public quote on the server side. The visitor's browser never contacts a third party.
- **No floating point anywhere.** Amounts are stored as integers in each currency's minor unit (HKD 1 = 100, JPY 1 = 1).
  Conversion uses bcmath with round-half-up. Splitting uses the largest-remainder method, so the shares always add up to the total.
- **Settle-up** matches debtors to creditors into a short list of transfers, and records repayments as settlements.
- **Concurrent edits are safe.** Editing or deleting an expense sends its version in `If-Match`, so a stale client gets 409 instead of overwriting someone else's change.
- **Abuse limits.** Per-IP rate limits (SHA-256 of a salted IP, no raw IPs stored), with Cloudflare Turnstile on trip creation only.
- **Privacy.** No cookies, no analytics, no third-party scripts except Turnstile on the create page, plus a strict CSP.
  Trips inactive for 12 months can be purged with a CLI script.

## Stack

| Layer | Choice |
|---|---|
| Server | PHP 8.2+ with `pdo_sqlite`, `bcmath`, `openssl`, `mbstring`. No framework, no Composer. |
| Storage | SQLite in WAL mode with a persistent PDO handle. One file, backed up with `VACUUM INTO`. |
| Front end | Vanilla JS modules bundled by esbuild into a single `app.js`. The built assets are committed, so a PHP host needs no Node. |
| Web server | Apache-style `.htaccess` (mod_rewrite, mod_headers). Nginx users: see below. |

```
public/          the only thing inside the document root: index.html, privacy/terms, assets/, api/index.php, admin/index.php
server/lib/      API, money maths, storage, FX, admin   (outside the docroot)
server/*.php     CLI: backup.php, cleanup.php
web/src/         front-end sources (esbuild → public/assets)
tests/           calc_test.php (money & settlement), fx_test.php, http_test.php (end-to-end against a staging URL)
data/            yours, git-ignored: config.php, app.sqlite, backups/
```

## Install

```bash
npm install                                   # esbuild + qrcode-generator, build-time only
bash deploy.sh /var/www/html /split-exp/ /srv/split-exp
$EDITOR /srv/split-exp/data/config.php       # base_url, ip_salt, Turnstile keys, trusted_proxies
```

`deploy.sh` builds the assets and puts only static files plus two tiny entry scripts in the document root. The
PHP library, the CLI and the data directory go to the app directory, outside the document root. The web server user
needs write access to `<app-dir>/data` only.

**Nginx** (no `.htaccess`): route `<base>/api/*` to `api/index.php`, route `<base>/t/<22 chars>` to `index.html`, and deny
everything except `index.html`, `privacy.html`, `terms.html`, `assets/`, `api/index.php` and `admin/index.php`. Copy
the security headers from `public/.htaccess`.

**Maintenance** (cron):

```bash
php /srv/split-exp/server/backup.php --keep=30           # daily
php /srv/split-exp/server/cleanup.php                    # list trips idle > 12 months; add --execute to delete (backs up first)
```

## Admin (optional)

`admin/` lists trips and can reissue links. It has no built-in login. Set `auth_lib` in `config.php` to a PHP
file that defines:

```php
auth_require(string $app): array          // returns the signed-in user ['id' => …] or ends the request
auth_csrf_token(): string
auth_csrf_check(?string $token): bool
auth_log(int $userId, string $app, string $action, string $detail): void
auth_logout_url(): string
```

[php-passkey](https://github.com/lns-hk/php-passkey) provides all five with passkey-only (WebAuthn) sign-in. Leave
`auth_lib` empty and `admin/` answers 404. Restricting `admin/` to your LAN at the reverse proxy is a good idea either way.

## Tests

```bash
php tests/calc_test.php                                   # money, rounding, splitting, settle-up
php tests/fx_test.php                                     # FX parsing
php tests/http_test.php https://staging.example.com/split-exp   # end-to-end: tokens, versions, limits, file exposure
```

`http_test.php` creates test trips and trips the create rate limit for your IP for an hour. Point it at a staging copy.

## Notes

- "Today's rate" parses a public Google Finance page, which is not an official API. If the format changes the button
  fails gracefully, and you can always type a rate by hand.
- Edit `public/privacy.html` and `public/terms.html` with your own contact details before going live.

## License

MIT © 2026 Lierre Wong
