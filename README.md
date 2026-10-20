# Store-AI

Sales, inventory, debts and profit tracking for small retail stores, with AI photo-stocking and an AI report chat.

- `api/` — PHP 7.4+ (no Composer) + MySQL/MariaDB
- `web/` — Next.js 14, static export (`web/out`)

## Features
Store-type picker · products (manual or AI photo scan with editable names/quantities) · cost & sale prices ·
multi-item sales cart · cash/card/transfer/credit · part-payments · debts owed to you and by you with payment history ·
expenses · reports (revenue, gross/net profit, top products, payment modes) · AI analyst chat over your live numbers.

## Local development
1. Create DB: `mysql -u root -e "CREATE DATABASE store_ai CHARACTER SET utf8mb4" && mysql -u root store_ai < api/schema.sql`
2. API: `cd api && php -S 127.0.0.1:8000 index.php` (DB creds default to root/no password; override in `api/config.local.php`)
3. Web: `cd web && npm install && npm run dev` → http://localhost:3000 (API URL in `web/.env.local`)

## Deploy (cPanel / shared hosting)
1. **Database**: create a MySQL DB + user in cPanel, import `api/schema.sql` into it (select the database first; the file has no CREATE DATABASE line).
2. **API**: upload the `api/` folder to e.g. `public_html/api/`. Copy `config.local.sample.php` → `config.local.php` and set DB details,
   a long random `app_secret`, and `openai_key` (needed for photo scan + AI chat). Make `api/uploads/` writable (755/775).
3. **Frontend**: set `NEXT_PUBLIC_API_URL` (default `/api` = same domain), run `cd web && npm run build`, upload the contents of `web/out/` to `public_html/`.
   The pre-built `out/` assumes the dev API URL — rebuild before deploying.
4. Set `cors_origin` in config to your site URL if the API is on a different domain.

## Notes
- Sales snapshot cost/price per line, so changing prices later never rewrites past profit.
- Voiding a sale restores stock and removes its debt.
- Photos are resized in the browser (max 1600px) before upload; AI model is configurable via `ai_model`.

## Deploying to Bluehost from GitHub (cPanel Git)
`web-dist/` is the pre-built frontend (built for the `/website_c9b9f857` subfolder). After changing anything in `web/`, rebuild and commit it:
`cd web && NEXT_PUBLIC_BASE_PATH=/website_c9b9f857 NEXT_PUBLIC_API_URL=/website_c9b9f857/api npm run build && cd .. && rm -rf web-dist && cp -r web/out web-dist`
`.cpanel.yml` copies `web-dist/` → `public_html/` and `api/` → `public_html/api/` on every deploy.
