# EgMenu

Multi-tenant SaaS by **EgWeb** for restaurants and cafes: QR menu, table ordering, order board, POS, kitchen display,
call center, loyalty card. Arabic-first (RTL) with English.

## Hosts

| Host | What |
|---|---|
| `egmenu.app` | Landing + public menus at `/{slug}` (`/{slug}/t/{token}` = table QR) |
| `app.egmenu.app` | Restaurant dashboard; the restaurant is the logged-in user's own |
| `admin.egmenu.app` | EgWeb super admin (restaurants, subscriptions, plans & features, payment settings) |

Locally the domain is `localhost` (`CENTRAL_DOMAINS` in `.env`); browsers resolve `*.localhost` to 127.0.0.1.

## Run locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed     # plans, roles, demo cafe, super admin
npm run build
php artisan serve --port=8000
```

- Menu: http://localhost:8000/demo-cafe
- Dashboard: http://app.localhost:8000 — `owner@demo.test` / `password`
- Super admin: http://admin.localhost:8000 — `admin@egmenu.test` / `password`

## Code map

- `app/Models/Concerns/TenantModel.php` — base model; `BelongsToTenant` scopes every query to the current restaurant.
- `app/Http/Middleware/IdentifyTenantByPath.php` — menus; `InitializeTenancyFromUser.php` — dashboard.
  Both run before route-model binding (`bootstrap/app.php`), so `{id}`s never resolve across restaurants.
- `app/Services/Entitlements.php` — what a plan allows (`allows()`, `limit()`, `canAdd()`); `feature:` middleware.
- `app/Models/Plan.php` — catalogue of plan features/limits; values live in `plans` / `plan_features` (super admin edits them).
- `app/Support/Permissions.php` — staff roles: owner, manager, cashier, call center, kitchen, waiter.
- `app/Services/TenantProvisioner.php` — new restaurant: owner, 14-day Pro trial, main branch, 5 tables.
- `lang/ar.json` — Arabic strings; `tests/Unit/TranslationsTest.php` fails when one is missing.

## Tests

```bash
php artisan test
```

Tenant isolation, path routing, auth & roles, plans & entitlements, translations.
