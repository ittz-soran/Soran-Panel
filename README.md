# Soran Panel

The tool Soran runs to manage the shops he has sold Smart Soran Store System
to. It answers who is running the system, whether they are paid up, whether
they are healthy, and whether anything is about to break — and it does the work
too: creating a customer, delivering a licence, running a backup.

**[`PANEL_DOC.md`](PANEL_DOC.md) is the specification.** Every decision lives
there. Read it before changing anything here; where it does not describe
something, ask rather than invent.

The shop system itself is a separate repository, `ittz-soran/SystemManagment`.
Nothing of its code is imported here — see PANEL_DOC Section 10 for the three
things that do cross between them.

## What is built

Build order (PANEL_DOC Section 11) step 2: the scaffold. Laravel 13, sign in,
the authenticator, and the shell.

The other seven pages in Section 9 are named in the sidebar and shown as not
built yet, so the shape of the thing is on the screen without any dead links.

## Running it

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Point `DB_*` at the panel's **own** database — it never shares one with a shop
(PANEL_DOC Section 5) — then:

```bash
php artisan migratez
```

There is no `/register` route and there never will be: an account here reaches
into every customer's install. The first operator comes from the environment:

```dotenv
PANEL_ADMIN_NAME=Soran
PANEL_ADMIN_EMAIL=you@example.com
PANEL_ADMIN_PASSWORD=something-long
```

```bash
php artisan db:seed
```

Seeding again never resets an existing operator's password.

**Set up the authenticator immediately** at *Authenticator* in the account
menu. There is no forgotten-password email — `MAIL_MAILER` is `log` on this
hosting, so a link would never leave the building — and the authenticator is
therefore the only way back in.

## The stylesheet is borrowed, not built

There is no `npm install` here and no stylesheet of its own. PANEL_DOC Section
10: the panel reuses the shop system's compiled assets by **copying `build/` at
deploy time**.

```bash
# in the shop system's checkout
npm install && npm run build

# then, into this one
cp -a /path/to/smart-store/public/build/. public/build/
```

`public/build/` is git-ignored on purpose — it is deployed, not committed. If
it is missing, every page throws by name on the `@vite` call, which is the
right failure: a panel serving unstyled HTML looks broken rather than
undeployed.

Two things follow from borrowing it, both easy to trip over:

- **The icons are subsetted.** The shop system ships only the icons it draws
  (its `tools/subset-icons.py`) — 74 of Bootstrap Icons' two thousand. An icon
  outside that set draws as *nothing*: no error, no empty box. `BorrowedStylesheetTest`
  fails the suite instead, and names the file.
- **The entry names must match** (`resources/scss/app.scss`,
  `resources/js/app.js`), because the copied `manifest.json` is what `@vite`
  reads.

## Tests

```bash
php artisan test
```

The suite runs on SQLite by default and is also run against real MariaDB before
anything is pushed — PANEL_DOC Section 1 records two bugs that only a real
engine or a real browser would have caught.

```bash
DB_CONNECTION=mysql DB_DATABASE=soran_panel_test DB_USERNAME=... DB_PASSWORD=... \
  php vendor/bin/phpunit
```

The suite refuses to run against a database that is not SQLite and is not named
`*_test`, because `RefreshDatabase` drops every table it touches. That guard is
in `tests/TestCase.php` and it is there because the development database was
lost to exactly that mistake.
