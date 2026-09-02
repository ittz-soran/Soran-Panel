# Putting the panel on the server

Build order step 9 — PANEL_DOC Section 11. This is the one step nobody could do
for you, because it happens on your cPanel account.

Everything here has been run somewhere. The parts that could be tested on
another machine were: the folder arrangement below was built and the panel
served through it, with its `.env` proved unreachable. The parts that only exist
on cPanel — UAPI, the cron entry, the DNS — are marked ⚠️ and are yours to
confirm the first time.

**After every step, run `php artisan panel:check`.** It asks each setting a real
question and names the wrong one. It is quicker than finding out halfway through
creating a customer.

---

## Step 0. Your account's real name

Every path below is written as `/home/soransto/…`. That is a guess at your cPanel
username, and if it is wrong every path is wrong. Open cPanel → **Terminal** and
run:

```bash
echo $HOME
```

Whatever it prints is the folder everything below lives in. If it is not
`/home/soransto`, substitute it everywhere — including in the `.env` values,
which are absolute paths and will not fall back to anything sensible.

If there is no **Terminal** in cPanel, stop here and tell me: without shell
access the whole approach changes, because `composer`, `artisan` and
`shop:provision` all need one.

---

## What ends up where

Section 4 measured that **a document root cannot leave `public_html`** — cPanel
was told otherwise and silently made its own folder inside it. So the code lives
outside and only the handful of files the web is allowed to see go inside:

```
/home/soransto/
├── smart-store/              the shop system — one codebase every shop reads
├── panel/                    the panel's code.  NOT reachable by any URL
├── shops/
│   ├── bazaar/               each shop's .env, storage, bootstrap/cache
│   └── …
└── public_html/
    ├── panel/                ← panel.soranstore.com points here
    │   ├── index.php         written by `panel:public`; absolute paths
    │   ├── .htaccess
    │   └── build/            the shop system's compiled assets
    ├── bazaar/               ← bazaar.soranstore.com points here
    └── …
```

`panel/.htaccess` denies everything, as a net for the day somebody moves the
code inside `public_html`. Section 4 found a real customer's install serving its
`.env` and `laravel.log` to anyone; the panel's `.env` is worse — it holds the
database with every customer, licence and payment, the admin password, and an
account that may create and drop databases.

---

## 1. The shop system

```bash
cd ~
git clone https://github.com/ittz-soran/systemmanagment.git smart-store
cd smart-store
composer install --no-dev --optimize-autoloader
npm install && npm run build          # builds public/build — needed by the panel too
```

⚠️ If npm is not available on the account, build `public/build` on your own
machine and upload it.

**Check:** `php artisan list | grep shop:provision` prints the command.

---

## 2. The panel

```bash
cd ~
git clone https://github.com/ittz-soran/Soran-Panel.git panel
cd panel
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Copy the shop system's compiled assets in — Section 10: the panel has no
stylesheet of its own and no npm build.

```bash
cp -r ~/smart-store/public/build ~/panel/public/build
```

---

## 3. Its database

Make one in cPanel → **MySQL Databases**: a database, a user, and the user
granted **ALL PRIVILEGES** on it. That account needs rights over this one
database only.

⚠️ **While you are on that page, write down the number in the heading** —
"MySQL Databases (3 / 25)". PANEL_DOC Section 13 has that as the only open
question left: it is the real ceiling on how many customers fit on this account,
and one shop uses one database.

Then in `~/panel/.env`:

```
DB_DATABASE=soransto_panel
DB_USERNAME=soransto_panel
DB_PASSWORD=…
```

```bash
php artisan migrate --force
```

---

## 4. A way in

```
PANEL_ADMIN_NAME=Soran
PANEL_ADMIN_EMAIL=you@example.com
PANEL_ADMIN_PASSWORD=…                # at least 12 characters
```

```bash
php artisan db:seed --force
```

There is **no sign-up page and no forgotten-password email** — `MAIL_MAILER` is
`log` on this hosting, so a reset link would never leave the building. Running
`db:seed` again does not reset the password.

**Set up the authenticator the first time you sign in.** It is the only way back
in, and `panel:check` will keep saying so until you do.

---

## 5. The rest of the settings

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://panel.soranstore.com

PANEL_SHOPS_HOME=/home/soransto/shops
PANEL_SHOPS_PUBLIC=/home/soransto/public_html
PANEL_SHARED_ARTISAN=/home/soransto/smart-store/artisan

PANEL_DATABASE_MAKER=cpanel
PANEL_UAPI=/usr/bin/uapi
PANEL_CPANEL_PREFIX=soransto          # cPanel prefixes every database and user
```

`PANEL_CPANEL_PREFIX` matters more than it looks. cPanel creates
`soransto_bazaar_shop` when asked for `bazaar_shop`, and the panel has to record
the name the shop will really connect to — get it wrong and the panel cannot
read that shop again.

```bash
mkdir -p ~/shops
php artisan panel:check
```

---

## 6. Where the domain points

```bash
php artisan panel:public /home/soransto/public_html/panel
```

Then in cPanel → **Subdomains**, create `panel.soranstore.com` with its document
root at `/home/soransto/public_html/panel`.

⚠️ cPanel will offer its own path. Let it create the subdomain, then check that
the document root really is that folder — Section 4 records it silently using
its own and ignoring what was typed.

DNS is Cloudflare, proxied. **SSL/TLS must be Full (strict)** — Section 4 again.

**Check:** `https://panel.soranstore.com/login` shows the sign-in screen, and
`https://panel.soranstore.com/.env` does **not** show a file. It should be a
404 or a 403; anything else means the code is inside the document root.

---

## 7. The hourly check

The health check only runs if cron calls the scheduler. cPanel → **Cron Jobs**,
every minute:

```
* * * * * cd /home/soransto/panel && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

⚠️ Confirm the PHP path — Section 4 measured `/usr/local/bin/php` on this
account, and cPanel sometimes wants `/usr/local/bin/ea-php83`.

**Check:** after an hour, the **Health** screen shows a "last run" time, and
`panel:check` stops warning about cron.

---

## 8. Your own shop, through the panel

Build order step 10, and the last one. **Customers → New customer**, filled in
for your own shop.

This is also what proves the **cPanel UAPI** half of database creation. It is
the one piece of the panel that has never run against a real cPanel account: it
is written from cPanel's documented API and Section 4's measurement, and its
tests drive a fake. If a call name is wrong you will see cPanel's own error text
on the screen, and **nothing will be left half-made** — the rollback is tested,
and it takes back the database, the database user and both folders.

Fill it in like this:

| Field | What to put |
|---|---|
| Shop name | Your shop, as it should read on its own screen |
| Short name | Lower-case letters and numbers. Becomes the folder, `<short>_shop` and `<short>_user`, and **cannot be changed later** |
| Domain | The subdomain — create it in cPanel **first**, pointing at `/home/soransto/public_html/<short>` |
| How they start | **On a free trial.** Nothing signed, nothing to paste, and it proves the whole path works before a licence is involved |

Then, once it is trading:

1. Sign in to the shop itself and check it works — it is a real install.
2. On your own machine, run `licence:issue --host=<your subdomain>`.
3. In the panel, **Renew**, and paste it. The panel verifies, writes, clears the
   shop's cache, and asks the shop what it now thinks. `valid` on screen means
   the whole licence path works end to end on the real host.

> **Create the subdomain first, then the customer.** cPanel creates the
> document root when you create the subdomain, and the panel writes into it.
>
> Writing this checklist is what found that the panel could not do that: it
> refused any public folder that already existed, so making the subdomain first
> deadlocked it, and making the customer first left cPanel to find the folder
> taken. Neither order worked. An empty document root — or one holding nothing
> but `cgi-bin` and `.well-known` — is now written into, and a folder with
> anything else in it is still refused, because that is somebody's site. If the
> panel does refuse, the message names the folder.

---

## 9. Speed, last

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Do this **after** everything else works, and run all three again after any
`.env` change. A cached config reads the old file for ever — which is the same
trap the panel clears for a shop after delivering a licence.

⚠️ If something breaks straight after this, `php artisan optimize:clear` puts it
back.

---

## If something goes wrong

| What you see | Where to look |
|---|---|
| Unstyled HTML | `public/build` is missing. Copy it from the shop system, then `panel:public` again. |
| A 500 with no detail | `storage/logs/laravel.log`. `APP_DEBUG` stays off. |
| `.env` visible in a browser | The code is inside the document root. Move it out and run `panel:public`. |
| A setting change does nothing | `php artisan optimize:clear`, then re-cache. |
| "Health" never updates | Cron is not calling `schedule:run`. Check the PHP path. |
| Creating a customer fails | The message names the step. Everything it made is taken back. |
| Anything else | `php artisan panel:check` |
