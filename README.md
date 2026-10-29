# EgHub

Multi-tenant SaaS by **EgWeb** for restaurants and cafes: QR menu, table ordering, order board, POS, kitchen display,
call center, loyalty card. Arabic-first (RTL) with English.

## Hosts

| Host | What |
|---|---|
| `eghub.app` | Landing pages, restaurant directory (`/restaurants`), `/sitemap.xml`, `/robots.txt` |
| `eghub.app/{slug}` | A restaurant's public menu — the **canonical** address used by QR codes, the dashboard, admin, emails and `<link rel="canonical">` (`/about` = SEO profile, `/t/{token}` = table QR, `/order`, `/o/{token}`, `/card`, `/board`, `/tv/{token}`, ...) |
| `{slug}.eghub.app` | Short alias: the same pages, links stay on the alias, canonical tag points at `eghub.app/{slug}` |
| `app.eghub.app` | Restaurant dashboard; the restaurant is the logged-in user's own |
| `admin.eghub.app` | EgWeb super admin (restaurants, subscriptions, plans & features, payment settings) |
| any other host | A restaurant's verified custom domain (same menu routes, canonical to itself); inactive/unpaid → redirect to `eghub.app/{slug}` |
| `egmenu.app` | The earlier name: redirects to eghub.app and hosts the restaurant directory (second entry of `CENTRAL_DOMAINS`) |

`www`, `app`, `admin` (and every name in `Tenant::RESERVED`) are never restaurants. `Tenant::url()` builds the menu
address (custom domain if active, else `eghub.app/{slug}`); `Tenant::subdomainUrl()` the alias. Cookies are per host (`SESSION_DOMAIN` stays empty — never
`.eghub.app`, or the dashboard session would be sent to every restaurant's subdomain).

Production needs a wildcard DNS record `*.eghub.app` and a wildcard subdomain (`*`) in cPanel pointing at `public/`,
with a wildcard SSL certificate. Locally the domain is `localhost` (`CENTRAL_DOMAINS` in `.env`); browsers resolve
`*.localhost` to 127.0.0.1, so `http://{slug}.localhost:8000` works.

## Run locally

Needs PHP 8.4.1+ (the locked packages refuse 8.3) and Node for the asset build.

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed     # plans, roles, demo cafe, super admin
npm run build
php artisan serve --port=8000
```

- Menu: http://demo-cafe.localhost:8000
- Dashboard: http://app.localhost:8000 — `owner@demo.test` / `password`
- Super admin: http://admin.localhost:8000 — `admin@egmenu.test` / `password`
- Order as a guest: open a table QR from Dashboard → Tables & QR → Print, or `http://demo-cafe.localhost:8000/t/{token}`.

## Code map

- `app/Models/Concerns/TenantModel.php` — base model; `BelongsToTenant` scopes every query to the current restaurant.
- `app/Http/Middleware/IdentifyTenantBySubdomain.php` — menus (`IdentifyTenantByDomain` for custom domains,
  `IdentifyTenantByPath` for `eghub.app/{slug}`, which keeps generated links on the main domain; every menu page
  gets `<link rel="canonical">` from `markCanonical()`); `InitializeTenancyFromUser.php` — dashboard.
  All run before route-model binding (`bootstrap/app.php`), so `{id}`s never resolve across restaurants.
- `app/Services/Entitlements.php` — what a plan allows (`allows()`, `limit()`, `canAdd()`); `feature:` middleware.
- `app/Models/Plan.php` — catalogue of plan features/limits; values live in `plans` / `plan_features` (super admin edits them).
- `app/Support/Permissions.php` — staff roles: owner, manager, cashier, call center, kitchen, waiter.
- `app/Services/TenantProvisioner.php` — new restaurant: owner, 14-day Pro trial, main branch, 5 tables.
- `app/Services/Orders/OrderService.php` — places orders (prices recomputed server-side, option rules, idempotent
  `client_uuid`, per-restaurant numbers) and moves them (`new → preparing → ready → served`, history + timestamps).
- `app/Services/Menu/MenuCatalog.php` — the menu as a branch sees it (branch price / availability overrides).
- `app/Services/ImageService.php` — uploads re-encoded to WebP under `storage/app/public/tenants/{tenant}/`.
- Order board polls `/orders/feed` every 5 s (`resources/js/board.js`); public menu cart in `resources/js/menu.js`.
- `lang/ar.json` — Arabic strings; `tests/Unit/TranslationsTest.php` fails when one is missing.

## Tests

```bash
php artisan test
```

Tenant isolation, path routing, auth & roles, plans & entitlements, translations.
