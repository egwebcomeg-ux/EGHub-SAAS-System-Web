# EgMenu deployment (cPanel shared hosting)

Stack: Laravel 13, PHP 8.3+, MySQL, LiteSpeed, behind Cloudflare. No Node, no queue daemon and no Redis on the server.

## 1. Server requirements

- PHP 8.3 or newer (cPanel > Select PHP Version / MultiPHP Manager).
- PHP extensions: `pdo_mysql`, `mbstring`, `gd` (menu image resizing), `zip` (backups + XLSX import/export), `openssl`, `curl`, `fileinfo`, `xml`/`dom`, `tokenizer`, `ctype`, `json`, `bcmath` (recommended). `intl` is not required.
- `exec`/`proc_open` must not be disabled and `mysqldump` must exist (used by `egmenu:backup`). Check with `which mysqldump`.
- Composer 2 (or run `composer install` locally and upload `vendor/`).
- Cron access, SSH access recommended (Terminal in cPanel works).

## 2. cPanel setup

1. Upload or `git clone` the project **outside** the web root, e.g. `/home/USER/egmenu` (not `public_html`).
2. Domains: the **document root of every host must be `/home/USER/egmenu/public`**.
   - `egmenu.app` (addon/primary domain) -> docroot `egmenu/public`
   - Subdomains `app.egmenu.app` and `admin.egmenu.app` -> same docroot `egmenu/public`
   - Wildcard subdomain: cPanel > Domains (or Subdomains) > create `*.egmenu.app`, docroot `egmenu/public`. If the host will not create a wildcard, ask support or add it in the vhost.
   - If `public_html` must stay the docroot, symlink instead: `rm -rf public_html && ln -s /home/USER/egmenu/public public_html`.
3. DNS (Cloudflare): `A egmenu.app`, `A app`, `A admin`, `A *` all -> server IP, **proxied (orange cloud)**.
4. MySQL: cPanel > MySQL Databases: create database `USER_egmenu`, user `USER_egmenu` with a long random password, add the user to the database with ALL PRIVILEGES. Charset utf8mb4 is used by the app.
5. Select PHP 8.3+ for all the domains above (including the wildcard).

## 3. First deploy

```bash
cd /home/USER/egmenu
composer install --no-dev --optimize-autoloader
cp .env.example .env
nano .env                         # see section 4
php artisan key:generate          # FIRST DEPLOY ONLY - never again (see warning below)
php artisan migrate --force
php artisan db:seed --class=PlanSeeder --force
php artisan db:seed --class=RoleSeeder --force
php artisan storage:link
php artisan egmenu:admin you@yourdomain.com --name="Your Name"   # prompts for the password
php artisan optimize
php artisan egmenu:doctor
```

Do **not** run plain `db:seed` in production (`DatabaseSeeder` only adds demo data on local, but stay explicit).

**APP_KEY warning:** the key encrypts restaurant AI API keys and sessions/cookies. Regenerating it later makes the stored AI keys unreadable and logs everybody out. Back it up somewhere safe (password manager) and never commit it.

### Assets

There is no Node on the server. Build locally and upload:

```bash
npm ci && npm run build
```

Upload the whole `public/build` folder to `/home/USER/egmenu/public/build` (the folder is gitignored). Re-upload it after every frontend change.

### Cron

cPanel > Cron Jobs, every minute (adjust the PHP path, e.g. `/usr/local/bin/ea-php83` or `/opt/cpanel/ea-php83/root/usr/bin/php`):

```
* * * * * cd /home/USER/egmenu && php artisan schedule:run >> /dev/null 2>&1
```

This one line runs everything: the queue worker every minute (`queue:work --stop-when-empty`), billing lifecycle 03:00, loyalty expiry 03:30, backup 02:30, and a heartbeat read by `egmenu:doctor`.

## 4. `.env` for production

Keys marked **REQUIRED** have no safe default.

| Key | Value |
| --- | --- |
| `APP_NAME` | `EgMenu` |
| `APP_ENV` | `production` **REQUIRED** |
| `APP_DEBUG` | `false` **REQUIRED** |
| `APP_KEY` | from `key:generate` **REQUIRED** |
| `APP_URL` | `https://egmenu.app` **REQUIRED** |
| `APP_TIMEZONE` | `Africa/Cairo` |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | `ar` / `en` |
| `CENTRAL_DOMAINS` | `egmenu.app` **REQUIRED** (first entry is the main domain; `app.` and `admin.` hosts derive from it). Default `localhost` is wrong in production |
| `DB_CONNECTION` | `mysql` **REQUIRED** (default is sqlite) |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | from step 2.4 **REQUIRED** |
| `SESSION_DRIVER` | `database` |
| `SESSION_DOMAIN` | `null` (do not share cookies across restaurant subdomains) |
| `SESSION_SECURE_COOKIE` | `true` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `database` |
| `LOG_CHANNEL` / `LOG_STACK` / `LOG_LEVEL` | `stack` / `daily` / `warning` |
| `MAIL_MAILER` | `smtp` **REQUIRED** (`log` silently drops all email, e.g. password resets) |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` / `MAIL_SCHEME` | your SMTP provider **REQUIRED** |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | e.g. `no-reply@egmenu.app` / `EgMenu` **REQUIRED** (default is `hello@example.com`) |
| `BACKUP_KEEP_DAYS` | `7` |
| `BACKUP_DISK` | `s3` to also copy backups off-server (optional but strongly recommended) |
| `BACKUP_REMOTE_DIR` / `BACKUP_MYSQLDUMP` | defaults `egmenu-backups` / `mysqldump` |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_DEFAULT_REGION` / `AWS_BUCKET` / `AWS_ENDPOINT` / `AWS_USE_PATH_STYLE_ENDPOINT` | only if `BACKUP_DISK=s3`. Backblaze B2 example: `AWS_ENDPOINT=https://s3.us-west-004.backblazeb2.com`, `AWS_USE_PATH_STYLE_ENDPOINT=true` |
| `AWS_URL` | optional |
| `APP_MAINTENANCE_DRIVER` | `file` |
| `BCRYPT_ROUNDS` | `12` |

Other framework keys (`REDIS_*`, `MEMCACHED_*`, `SQS_*`, `POSTMARK_API_KEY`, `RESEND_API_KEY`, `SLACK_*`, `DYNAMODB_*`, `AUTH_*`, `SESSION_*` extras) are unused or have safe defaults. AI menu import uses a per-restaurant key stored in the database, not `.env`.

After editing `.env` always run `php artisan config:clear && php artisan optimize`.

## 5. Cloudflare

- DNS records proxied (orange cloud) for `@`, `app`, `admin` and `*`. Note the free plan covers wildcard DNS proxying, but the free Universal certificate covers only `egmenu.app` and `*.egmenu.app` (one level), which is exactly what is needed.
- SSL/TLS mode: **Full (strict)**.
- Create an **Origin Certificate** (SSL/TLS > Origin Server) for `egmenu.app` and `*.egmenu.app`, install it in cPanel > SSL/TLS (or ask the host to install it). Without a valid origin certificate Full (strict) returns 526.
- Turn on Always Use HTTPS and Automatic HTTPS Rewrites.
- Do not cache HTML. If you add a "Cache Everything" rule, bypass it for `app.` and `admin.` hosts and for any path with a session cookie.
- Real visitor IPs arrive in `CF-Connecting-IP`; if rate limiting or logs show Cloudflare IPs, configure trusted proxies in `bootstrap/app.php`.
- Custom restaurant domains (CNAME to your host) need "Cloudflare for SaaS"/manual handling; not covered here.

### WhatsApp inbound webhook (phone confirmation at signup)

Owners confirm their mobile by sending one prepared message (`EGMENU <code>`) to the platform's WhatsApp number; the webhook matches sender + code and stamps `users.phone_verified_at`. Nothing blocks the dashboard if this is not set up.

1. `.env`: `WHATSAPP_NUMBER=2010xxxxxxxx` (the number owners write to; empty falls back to the admin setting "support WhatsApp"), `WHATSAPP_VERIFY_TOKEN=<any long random string>`, `WHATSAPP_APP_SECRET=<Meta App → Settings → Basic → App secret>`. Then `php artisan config:clear && php artisan optimize`.
2. Meta for Developers → your app → WhatsApp → Configuration → Webhook: callback URL `https://egmenu.app/webhooks/whatsapp`, verify token = `WHATSAPP_VERIFY_TOKEN`, click Verify (Meta sends `GET` with `hub.challenge`; the app answers it when the token matches). Subscribe to the **messages** field. The admin Settings page shows the URL and token ready to copy.
3. Every `POST` must carry a valid `X-Hub-Signature-256` (HMAC of the raw body with the app secret). Without `WHATSAPP_APP_SECRET` every payload is refused with 403 (logged once a day). Message status updates (sent/delivered/read/failed) in the same payloads update `whatsapp_messages.status` by `provider_id`.
4. Another provider instead of Meta: have it `POST {"from": "2010xxxxxxxx", "text": "..."}` to the same URL with header `X-EgMenu-Secret: <WHATSAPP_WEBHOOK_SECRET>`.
5. The route is on the main domain only and excluded from CSRF; Cloudflare must not cache or challenge `/webhooks/*` (add a WAF skip rule if bot protection is on).

## 6. Updating

```bash
cd /home/USER/egmenu
php artisan down --render="errors.503" --retry=60
git pull origin main
composer install --no-dev --optimize-autoloader
# upload public/build from your machine if the frontend changed
php artisan migrate --force
php artisan optimize:clear
php artisan optimize              # also purges the LiteSpeed page cache (section 6a)
php artisan up
php artisan egmenu:doctor
```

Never run `key:generate` or `migrate:fresh` here. Take a backup first for risky releases: `php artisan egmenu:backup`.

## 6a. LiteSpeed page cache

The marketing pages (home, features, pricing, blog, apps, QR generator, supported screens, legal pages, the directory)
and the restaurants' public menu pages (menu, item, about, links) are stored by LiteSpeed for guests
(`App\Services\PageCache`). PHP decides with response headers only; `public/.htaccess` turns the lookup on
(`CacheLookup on`).

Turn it on in `.env`, then `php artisan config:clear && php artisan optimize`:

```
PAGE_CACHE=true
PAGE_CACHE_ORIGIN_IP=54.36.13.152   # purge calls go straight to the server, not through Cloudflare
```

- Ages: marketing pages 1 hour (never past the next scheduled blog article), menus 5 minutes (never past the next
  greeting change, the end of busy mode or an item back from a timed sold-out).
- Never stored: anything for a signed-in user, the dashboard and admin hosts, sign-up, services form, booking,
  account, cards, orders, screens, any POST, any status but 200, addresses with other query parameters, and a menu
  for a guest with a scanned table, a remembered account, branch or language.
- Purges: every change of what a page shows purges that restaurant (or the marketing pages) from the request that
  made it, and as signed calls to `/_lscache/purge` on each host after the response, after each queue job and at
  the end of each command.
- Every deploy: `php artisan optimize` purges everything by itself (the stored pages point at the old build files).
  After uploading a new `public/build` without running `optimize`, purge by hand:

```bash
/opt/cpanel/ea-php84/root/usr/bin/php artisan egmenu:page-cache-purge                 # everything
/opt/cpanel/ea-php84/root/usr/bin/php artisan egmenu:page-cache-purge --tenant=rosto   # one restaurant
/opt/cpanel/ea-php84/root/usr/bin/php artisan egmenu:page-cache-purge --platform       # marketing pages
```

Check: `curl -sI https://eghub.app/pricing | grep -i x-litespeed` twice; the second answer says
`x-litespeed-cache: hit`. Turn it off: `PAGE_CACHE=false`, `config:clear`, `optimize` (the purge runs while still on,
so run `egmenu:page-cache-purge` before switching it off).

## 7. Rolling back

1. `php artisan down --render="errors.503"`
2. Code: `git log --oneline -5` then `git checkout <previous-good-commit>` (or `git reset --hard <sha>` on a deploy-only clone), then `composer install --no-dev --optimize-autoloader`.
3. Database: only if the bad release ran migrations. Prefer `php artisan migrate:rollback --step=N --force` for the exact N migrations of that release; if destructive, restore the pre-release backup (section 8).
4. Re-upload the previous `public/build`.
5. `php artisan optimize:clear && php artisan optimize && php artisan up`

## 8. Backups and restore

`egmenu:backup` runs daily at 02:30. It writes `storage/app/backups/egmenu-YYYY-mm-dd-HHMM.zip` containing `database.sql` and `public/` (uploads), keeps `BACKUP_KEEP_DAYS` days locally and copies to `BACKUP_DISK` if set. Run manually: `php artisan egmenu:backup`.

Restore:

```bash
cd /home/USER/egmenu
php artisan down --render="errors.503"
mkdir /tmp/restore && unzip storage/app/backups/egmenu-2026-01-01-0230.zip -d /tmp/restore   # download from the bucket first if needed
mysql -u DB_USER -p DB_NAME < /tmp/restore/database.sql      # asks for the password
cp -a /tmp/restore/public/. storage/app/public/
php artisan optimize:clear && php artisan up
rm -rf /tmp/restore
```

Test a restore on a scratch database at least once. Keep `APP_KEY` backed up separately: it is not in the archive.

## 9. Post-deploy smoke checklist

- [ ] `php artisan egmenu:doctor` prints no FAIL (WARN for "Scheduler" is normal in the first minute; "Backup" until the first backup).
- [ ] `https://egmenu.app/up` returns 200.
- [ ] Landing page loads with styles (proves `public/build` is uploaded) over HTTPS with no certificate warning.
- [ ] `https://admin.egmenu.app` login works with the admin created by `egmenu:admin`.
- [ ] `https://app.egmenu.app` signup/login works; a password-reset email arrives.
- [ ] Create a test restaurant: its menu opens at `https://egmenu.app/{slug}` (and its subdomain, once enabled).
- [ ] Upload a menu image: it displays (checks `storage:link` and `gd`).
- [ ] Import an XLSX/export a template (checks `zip`).
- [ ] Wait two minutes, re-run `egmenu:doctor`: scheduler check is OK.
- [ ] Run `php artisan egmenu:backup` once and confirm the zip (and the copy in the bucket if configured).
- [ ] `APP_DEBUG=false`: a random 404 URL shows the EgMenu 404 page, not a stack trace.
