# Soran Panel — Project Documentation

**Status:** design settled, foundation built and tested. The panel itself is not written yet.

> **How to use this file:** paste or open it at the start of every new chat about the panel. It holds every decision made, so nothing is lost between sessions. Update the Task Log (Section 12) at the end of each session.
>
> `PROJECT_DOC.md` in this same repository governs **Smart Soran Store System** — the product being sold. This file governs **Soran Panel** — the tool Soran runs to manage the shops he has sold it to. Where the two touch, PROJECT_DOC wins on anything about the shop system itself.

---

## 1. Instructions for Claude — read first, every session

- This is a **PHP/Laravel control panel** that Soran runs to manage the shops he sells Smart Soran Store System to. He hosts every customer himself.
- **Follow this doc.** Where it does not describe something, ask rather than invent. Soran's standing instruction across this project: *"use my doc, if not describe it dont touch just work on doc."*
- **Git:** develop and push only to the branch named at the start of the session. **Never open a pull request unless explicitly asked.**
- **Show results before pushing.** Screenshots of real screens, or command output — not a description of what the code would do.
- **Reproduce before fixing, verify in the real thing.** This project has twice shipped bugs that only a real engine or a real browser would have caught: a MariaDB reserved word that SQLite accepted, and a Carbon object in the cache that the array driver tolerated. If a change depends on MySQL, a browser, or Apache, test it there.
- **Four rules that govern everything here:**
  1. **The private key never touches the server** (Section 6). The panel verifies licences; it cannot sign them.
  2. **Anything irreversible is held and confirmed, and logged against a name** (Section 7).
  3. **A shop's own data is read, never rewritten** (Section 8). The panel manages installs, not the trade inside them.
  4. **One codebase, many shops** (Section 3). Never copy the system per customer.
- If a decision changes, **update this file** — don't just say it in chat.

---

## 2. What this is, and why

Soran sells Smart Soran Store System to electronics shops in Iraq on a monthly plan, and hosts each one on his own cPanel account at hosting.com, behind Cloudflare, on `soranstore.com`.

Without a panel, every customer is manual work: create the database, upload the files, write the `.env`, issue the licence, remember the `.htaccess`, chase the payment, notice when they stop using it. That does not scale past a handful, and the parts that get skipped are the ones that leak data.

**The panel is the one screen that answers: who is running my system, are they paid up, are they healthy, and is anything about to break.** It also does the work — creating a customer, delivering a licence, changing a storage limit, running a backup — because a panel that only reports is a panel that leaves the work undone.

**What it is not:** it does not manage a shop's products, sales or customers. That is the shop's own system, and the shop's staff own it. The panel reads a shop's data to report on it and never writes to it, with two exceptions it owns outright: the `.env` file, and running the shop's own maintenance commands.

---

## 3. The foundation — already built

This is done, tested and pushed. Do not rebuild it; build on it.

### One codebase, many shops

A shop is no longer a copy of the system. Two installs differ in exactly four things:

| | |
|---|---|
| `.env` | its database, its licence key, its storage limit |
| `storage/` | its logs, backups, uploaded logo |
| `bootstrap/cache/` | its compiled config and routes |
| the public folder | the six files its domain points at |

Everything else is identical, so it is one folder every shop reads.

A shop's `public/index.php` names itself before loading the shared bootstrap:

```php
define('SHOP_HOME',   '/home/soransto/shops/soran');   // .env, storage, caches
define('SHOP_PUBLIC', __DIR__);                        // where the domain points
require '/home/soransto/smart-store/vendor/autoload.php';
$app = require '/home/soransto/smart-store/bootstrap/app.php';
```

`bootstrap/app.php` moves those four paths and leaves everything else alone. With no `SHOP_HOME` — a developer's machine, a single-shop install, the test suite — nothing changes at all.

### The failure this exists to prevent ⚠️

Laravel compiles the **whole resolved config, database password included**, into `bootstrap/cache/config.php`. Built the obvious way — per-shop `.env` and storage, shared bootstrap cache — a second shop reads the first shop's credentials and serves the first shop's data on every page, with its own storage path and its own `.env` still looking perfectly correct.

That was reproduced deliberately against two real MariaDB databases before the guard was written: shop Beta reported Alpha's database, Alpha's shop name and Alpha's administrator. **Nothing on the filesystem hints at it.** `ShopIsolationTest` holds it, spawning real processes because `SHOP_HOME` is a constant and a constant-driven mechanism can only honestly be tested the way it runs.

Two things learned in the building, worth not rediscovering:

- **`Container::when()` is contextual binding, not `Conditionable`.** Chaining the path calls off `->create()` returns a `ContextualBindingBuilder` where `bootstrap/app.php` must return an `Application`.
- **`config:cache` and `route:cache` re-require `bootstrapPath('app.php')`** to rebuild a fresh application. Moving the bootstrap path therefore means each shop needs a file there — one line deferring to the shared bootstrap. A loud missing-file failure, rather than a silent shared cache.

The **providers list deliberately stays with the code.** `Application::configure()` resolves it while building, before the shop paths apply, and `RegisterProviders::merge()` keeps that resolved path. What a shop differs in is its data, never its service providers.

### What a customer costs

| | |
|---|---|
| One install, copied per customer | 27,187 files (26,783 of them the framework) |
| One shop, this way | about 40 files, plus what the shop stores |
| Six customers, copied | ~163,000 files |
| Six customers, this way | ~28,000 — roughly one install, for ever |

**The file count is no longer the reason.** cPanel reports File Usage as
`48,130 / ∞` — the account has no inode limit, so the ceiling this was first
argued from does not exist. That argument was over-weighted and is corrected
here rather than quietly dropped.

**The decision stands on the reason that actually matters: an update is one
upload instead of one per customer.** Copying the system per shop means every
bug fix is repeated by hand as many times as there are customers, and the
first one that gets skipped is a shop running old code against a migrated
database — silent, and discovered by the shopkeeper rather than by Soran. One
shared folder makes that class of failure impossible rather than unlikely.
Disk is a distant second, and inodes are now no reason at all.

---

## 4. The hosting, as measured

Checked on the real account rather than assumed. Do not re-litigate these.

| | |
|---|---|
| Home | `/home/soransto`, writable by PHP |
| PHP | 8.3.33 · `pdo_mysql` `mbstring` `zip` `gd` `intl` `openssl` `fileinfo` `curl` all present |
| Limits | memory 512M · execution 60s · uploads 512M |
| Database | MariaDB 11.4.13 |
| `proc_open` | **allowed** — `/bin/mysqldump`, `/bin/mysql`, `/usr/local/bin/php` |
| cPanel UAPI | **answers** at `/usr/bin/uapi` — the panel can create databases and users itself |
| Plain SQL `CREATE DATABASE` | denied, which is normal on cPanel. Use UAPI. |
| Domain | Cloudflare DNS, proxied. SSL/TLS must be **Full (strict)**. |
| File Usage | `48,130 / ∞` — no inode limit on this account |

**Document roots cannot leave `public_html`.** Tested: cPanel silently created its own folder inside `public_html` and ignored the path typed in. So the arrangement is:

```
/home/soransto/
├── smart-store/        the system, one copy — outside the web
├── shops/<name>/       .env · storage/ · bootstrap/ — outside the web
├── panel/              the panel's own files — outside the web
└── public_html/
    ├── (soranstore.com — Soran's separate website, untouched)
    ├── <shop>/         index.php · .htaccess · build/   ← the subdomain points here
    └── panel/          index.php · .htaccess · build/
```

Nothing private is under a document root. **This is what ends the security problem** — not an `.htaccess` that has to stay correct, but an absence of any address that reaches those files.

**A `.htaccess` pair still ships** in the shop system (root denies, `public/` grants back) for installs that do sit inside `public_html`. Both are needed; the deny cascades and a root file without the matching grant returns 403 to the whole shop. Verified against Apache 2.4 in all three states.

---

## 5. Database schema

The panel has **its own database**. It never adds tables to a shop's database.

### `customers` — one row per shop sold
```
id, name, contact_name, phone, email
host                  unique. bazaar.soranstore.com — what the licence binds to
shop_home             /home/soransto/shops/bazaar
public_path           /home/soransto/public_html/bazaar
database_name         bazaar_shop
database_user
status                trial | active | suspended | ended
monthly_fee           integer IQD, never decimal (PROJECT_DOC Section 2)
storage_limit_mb      mirrors what is written to their .env
language              the language their install starts in
started_on, notes, timestamps, soft deletes
```

### `licences` — every licence ever issued
```
customer_id, licence_id (K7QP-3MZX), host, key (the signed string, text)
issued_on, expires_on   null = sold outright
delivered_at            when it was actually written into their .env — confirmed, never assumed
issued_by, revoked_at, revoked_reason, timestamps
```

**A renewal is a new row, never an edit.** That is what makes the licence history on a customer's page possible, and the only way to answer "when did this shop last actually pay" months later.

### `payments` — money received
```
customer_id, amount (integer IQD), paid_on
covers_from, covers_to   which period this payment buys
method, reference, note, recorded_by, timestamps, soft deletes
```

**Two date pairs, not one.** A payment records *which month it buys*, so a customer who pays three months at once is not chased next week, and a late payment still starts from the day the last licence ended rather than losing them days.

### `health_checks` — an hourly snapshot per shop
```
customer_id, checked_at, reachable
database_bytes, backups_bytes, uploads_bytes, storage_limit_mb
migrations_run, migrations_total
last_activity_at, users_count, products_count, sales_count
licence_state            what THEIR system thinks — a cross-check against ours
data_check_passed, data_check_total
error
```

**Snapshots in a table, not columns on `customers`** — for the same reason `stock_movements` exists in the shop system. You want to see storage growing over weeks, and a failed check must not wipe the last good reading.

### `actions` — what the panel did, and who told it to
```
customer_id, user_id, action, detail (json: from → to), ip_address, created_at
```

Mirrors `activity_logs` in the shop system, for the same reason. Anything that reaches into a customer's install leaves a record with a name on it.

### `users` — panel operators
Reuse the shop system's auth and authenticator (`app/Support/Totp.php`, PROJECT_DOC Section 8e). Admin only for now; the shape allows staff later.

---

## 6. Licences — how delivery works

The mechanism itself is PROJECT_DOC Section 8f and does not change. What the panel adds is delivery.

**The private key never reaches the server.** Soran's decision, and the panel is built around it. A break-in on `soranstore.com` must never be able to forge a licence for anybody.

So a renewal is:

1. The panel shows the exact command to run **on Soran's own machine**:
   `php artisan licence:issue "Hawler Computer" --host=hawler.soranstore.com --months=1 --key=C:\soran-keys\private.pem`
2. He pastes the signed string back into the panel.
3. **The panel verifies it against the public key before writing anything.** A licence that does not verify, or names a different host, or is already expired, is refused there — not on the customer's server.
4. It is saved as a new `licences` row.
5. `LICENCE_KEY` in the shop's `.env` is replaced. The old file is kept as `.env.bak`.
6. The shop's config cache is cleared so it takes effect at once.
7. **The shop is asked what it now thinks, and the answer is shown back.** `delivered_at` is set from a confirmation, never from an assumption.
8. The payment is recorded if asked, and the action logged.

**Trial licences** are the one thing the panel may issue without a paste: a trial is a `status`, not a signed licence. A shop on trial runs `unlicensed` — full function, no banner, PROJECT_DOC Section 8f. The trial's end date lives in `customers`, and the panel chases it.

⚠️ **"No key at all" is not enough, and this was wrong here until 2026-09-01.** The shop system carries the seller's public key as a *committed default* in `config/licence.php`, so licensing is switched on in every install of that codebase. A shop with an empty `LICENCE_KEY` is therefore `missing`, not `unlicensed` — and `missing` is **read-only from its first minute**. A trial customer could not record a single sale.

What makes a trial actually run unlicensed is blanking `LICENCE_PUBLIC_KEY` in **the shop's own `.env`**, which overrides that default. `shop:provision --trial` writes it. Checked on a real provisioned shop rather than reasoned about: keyless reports state `missing` and `allowsWriting()` false; with the public key blanked it reports `unlicensed` and `allowsWriting()` true.

**So ending a trial is two edits, not one.** Writing a `LICENCE_KEY` while the public key is still blank leaves the licence unchecked, which is the same as no licence at all. The panel's Renew flow (Section 6, steps 5–7) must remove the blank `LICENCE_PUBLIC_KEY` line as well as writing the key — and step 7, asking the shop what it now thinks, is what catches it if it does not.

---

## 7. What the panel may do, and the guard rails

Soran chose: **everything, with a hold-to-confirm on anything destructive.** The hosting supports it — `proc_open` allowed, all three binaries present, UAPI answering.

| It may | How it is guarded |
|---|---|
| Create a customer end to end | Hold to confirm. Rolls back what it made on failure. |
| Deliver a licence | Verified against the public key first. |
| Change a storage limit | Logged, from → to. |
| Suspend and resume a shop | Hold to confirm, typed shop name. |
| Run a shop's backup, and download it | Logged. |
| Run a shop's migrations | Hold to confirm. Backup taken first. |
| Read a shop's health and data check | Read-only by construction. |

**The same guard rails as the shop system** (PROJECT_DOC Section 9b): a two-second hold and a typed confirmation for anything that cannot be undone, the reason shown on a disabled button rather than discovered after pressing it, and a backup before anything irreversible.

**What it may never do:** write to a shop's business tables, delete a shop's database, or hold the private key.

---

## 8. Reading a shop

Every shop is on the same server, so the panel reads directly — no HTTP endpoint, no remote-control door opened in every customer's install. Soran's decision, and it is the safer one for as long as he hosts everyone himself.

- **Its settings and data:** a second database connection, configured at runtime from the customer row. Read-only queries.
- **Its storage:** the filesystem, under `shop_home`.
- **Its own opinion of itself:** run its artisan commands through the shared codebase with `SHOP_HOME` set — `licence:show`, `migrate:status`, the data check. This is exactly how a shop runs, so the answer is the shop's own.
- **Its data check:** the seventeen Section 10b assertions from PROJECT_DOC, against live data. Read-only, deliberately: a contradiction is evidence, and repairing it before it has been read destroys the record of what went wrong.

**If a customer is ever hosted elsewhere,** this is the part that changes, and it changes alone. Keep the reading behind one service so a second implementation can slot in.

---

## 9. Pages

Mockups were built and approved against the shop system's own stylesheet, so the panel looks like the same product.

| Page | What it is for |
|---|---|
| **Overview** | Only what needs Soran this week: licences running out, storage near its limit, shops nobody has used. Everything else is a number. |
| **Customers** | The working screen. Shop, licence state, expiry, storage, last used, code version, monthly fee. Filter by "needs chasing". |
| **One customer** | Licence with its full history, storage broken into database/backups/uploads, whether they are actually using it, their code version, and the danger zone. |
| **Renew** | The paste-and-verify flow from Section 6, with what-will-happen shown beside it. |
| **New customer** | One form replacing six manual steps. |
| **Subscriptions** | Who has paid, who has not, what the month is worth. |
| **Health** | Each shop's own report on itself, read hourly. |
| **What I changed** | The `actions` log. |

**Design:** Bootstrap 5.3, the shop system's compiled stylesheet, same shell. English only — the panel has one reader. (The shop system's four languages and RTL do not apply here, and `translations:check` does not cover it.)

---

## 10. Where the code lives

**Its own GitHub repository** — `ittz-soran/Soran-Panel`, created by Soran, and
the repository this file now lives in. Settled: the panel is built here, and
never inside the shop system's repository (`ittz-soran/SystemManagment`).

That keeps the two apart in the way that matters: `smart-store/` is uploaded to
customers' hosting, and the panel's source never travels with it. It also means
the panel has its own branch names, its own history and its own test suite,
none of which the shop system has to carry.

What crosses between them is deliberately small and one-directional:

- The panel **reads** `PANEL_DOC.md` and `PROJECT_DOC.md` for the rules.
- The panel **runs** the shop system's artisan commands with `SHOP_HOME` set —
  `install:sql`, `licence:show`, `migrate`, `backup:run`, the data check. It
  does this as a subprocess against the shared codebase on the server, not by
  importing any of its classes.
- The panel **reuses the look** — Bootstrap 5.3 and the shop system's compiled
  stylesheet — by copying `build/` at deploy time, not by depending on it.

**The licence public key** is the one constant both sides need. The shop system
holds it in `config/licence.php`; the panel needs the same value to verify a
pasted licence before delivering it (Section 6). Copy it into the panel's own
config. It is public by design — the private half is the secret, and that never
leaves Soran's machine.

**Never upload the panel inside `smart-store/`.** Separate repositories make
that unlikely rather than impossible.

## 11. Build order

1. **`shop:provision` command** — creates a shop's folder from nothing: `.env` with a fresh `APP_KEY`, storage skeleton, `bootstrap/app.php` and `cache/`, its own `artisan`, and the public folder with `index.php`, `.htaccess` and a copy of `build/`. This is what New Customer calls, and it is testable on its own. **Done 2026-09-01**, on branch `claude/shop-provision` in the shop system's repository — that is where it belongs, beside `install:sql` and the shared bootstrap it defers to. The per-shop `artisan` was not in this list and is needed by Section 8: the shared `artisan` serves every shop and so can name none of them, so each shop gets its own entry point exactly as it gets its own `public/index.php`. *(Task #40)*
2. **Panel scaffold** — Laravel, auth, the authenticator, the shell. *(Task #41)*
3. **Schema and models** — the six tables from Section 5.
4. **Reading a shop** — the service from Section 8, with the hourly health check.
5. **Customers, one customer, Overview** — the screens that only read.
6. **Renew** — paste, verify, deliver, confirm. *(Task #42)*
7. **New customer** — UAPI database creation, provision, seed from `install:sql`, issue.
8. **Subscriptions and payments.**
9. **Deploy** — `smart-store` and `panel` on the server, `panel.soranstore.com`.
10. **Soran's own shop first**, then Halabja-phone rebuilt through the panel.

---

## 12. Task Log

| Date | Done | Next |
|---|---|---|
| 2026-09-01 | **`shop:provision`** (build order step 1, Task #40), written in the shop system's repository on `claude/shop-provision`, because that is where it belongs — beside `install:sql` and the shared bootstrap it defers to. It makes a shop's `.env` with a fresh `APP_KEY`, its storage skeleton, its `bootstrap/app.php` and `cache/`, its own `artisan`, and the public folder with `index.php`, `.htaccess` and a copy of `build/`. **The trial does not work the way Section 6 said**, and Section 6 is corrected rather than left: the shop system ships the seller's public key as a committed default, so a shop with no `LICENCE_KEY` is `missing` and read-only from its first minute, not `unlicensed`. `--trial` blanks `LICENCE_PUBLIC_KEY` in the shop's own `.env`, which is the only thing that makes Section 6's sentence true — and ending a trial is therefore two edits, not one. Also: the per-shop `artisan`, which Section 11 did not list and Section 8 needs, because the shared `artisan` serves every shop and can name none of them. One bug of my own, found by the test that asks for it: rollback left `shops/<name>` standing after a failure, because `mkdir(recursive)` makes parents silently and nothing recorded that this run had made them — a folder holding nothing, looking like a provisioned shop. Verified beyond the suite: two shops provisioned and migrated against real MariaDB with config cached on both, each reading only its own database, name and `APP_KEY`, the shared `bootstrap/cache` holding no `config.php` at all; then one served over HTTP, signed into, and its dashboard rendered in Chromium with no console errors. Suite: 636 tests, 633 passing, 1 skipped — the 2 failures are `BackupTest` asserting `mysql` is not on PATH, which it is on this machine, and they fail the same way on the untouched branch. | The schema in Section 5 (step 3), then reading a shop (step 4) |
| 2026-09-01 | **The panel's repository, and the scaffold** (build order step 2, Task #41). `ittz-soran/Soran-Panel` — Section 10's open item is filled in, and its "not in this one" reworded, because that sentence was written from inside the shop system's repository and inverts its own meaning now the file lives here. Laravel 13, sign in, the authenticator, the shell. No `/register` and no forgotten-password email: `MAIL_MAILER` is `log` on this hosting, so a link would never leave the building, and a route that appears to send a way back in and does not is worse than no route — the authenticator is the only way in, and the account menu says so while it is off. Three things found by driving it rather than trusting it. **The borrowed stylesheet subsets its icons** — the shop system ships only the 74 it draws, so `bi-heart-pulse` beside Health and the panel's own `bi-sliders2` mark simply were not there: no error, no empty box, nothing but a gap. `BorrowedStylesheetTest` now fails the suite and names the file. **A success message appeared twice at once**, as a toast and as an in-page alert; the shell toasts what worked and the guest screens, which have no toast container, show it inline. And **running the suite lost the development database**: `--env=testing` with no `.env.testing` falls back to `.env`, so `RefreshDatabase` dropped every table in the real one — `tests/TestCase.php` now refuses any database that is not SQLite and not named `*_test`, which on the server would have been the customer list, the licence history and the payment record. Verified in Chromium end to end: signed in, enrolled a phone with a real TOTP code, spent a recovery code to set a new password, signed back in with it — no console errors, no failed requests. Suite: 36 tests, 36 passing, on SQLite and on real MariaDB. | `shop:provision` (Task #40), then the schema in Section 5 |
| 2026-08-31 | **The last open questions closed**, all four from Soran. The panel gets its own GitHub repository, so its source never travels to a customer's hosting with the shop system. The `sys` folder beside `public_html` was a mistake and is deleted. Halabja-phone is backed up, and its database stays in cPanel because that is what a rebuilt install restores from. And File Usage reads `48,130 / ∞` — **there is no inode limit on this account**, so the ceiling the shared codebase was first argued from does not exist. Section 3 corrected rather than quietly left: the decision stands, but on updating once instead of once per customer, which was always the larger half of it. The only ceiling left is how many databases the plan allows, still unknown. | Confirm the repository name, then `shop:provision` |
| 2026-08-31 | **One codebase, many shops** (Section 3). `SHOP_HOME` and `SHOP_PUBLIC` move a shop's `.env`, storage, compiled caches and public folder; everything else is shared. Built the naive way first on purpose, against two real MariaDB databases with config cached on both, and watched the second shop report the first shop's database, shop name and administrator with its own paths still looking correct — then fixed it and verified each shop reads only its own. `ShopIsolationTest` spawns real processes because `SHOP_HOME` is a constant. Two findings: `Container::when()` is contextual binding and would have returned the wrong object from `bootstrap/app.php`; and `config:cache` re-requires `bootstrapPath('app.php')`, so a shop needs a one-line file there. Also corrected a hardcoded `SHOP_HOME.'/public'` that assumed a layout this hosting does not allow. Suite: 614 tests, 613 passing, 1 skipped. | `shop:provision` |
| 2026-08-31 | **Hosting measured** (Section 4), by three checkers run on the real account. `proc_open` allowed with all three binaries, cPanel UAPI answering, PHP 8.3.33, MariaDB 11.4.13. Document roots cannot leave `public_html` — tested, cPanel ignored the path and made its own folder. Found Halabja-phone's install serving `.env` and `laravel.log` to anyone; the folder has since been deleted, and its database must be kept. | — |
| 2026-08-30 | **Design settled.** Schema, pages and the four decisions: shared codebase; the panel may do everything with hold-to-confirm; it reads shops directly on the same server; the private key never leaves Soran's own machine. Page mockups built against the shop system's stylesheet and approved. | — |

---

## 13. Open questions

**Still open**

- **How many databases the hosting plan allows.** One per shop, and it is now
  the only real ceiling on how many customers fit on this account — the inode
  limit turned out not to exist. Not readable from PHP. Soran to check
  cPanel → **MySQL Databases**, where the heading reads something like
  "MySQL Databases (3 / 25)", or the plan's own feature list. Worth knowing
  before selling the next shop, not urgent before building.

**Settled**

- **Where the code lives** — its own GitHub repository. See Section 10.
- **The `sys` folder at `/home/soransto/sys`** — it was a mistake and has been
  deleted. Nothing depended on it.
- **The inode allowance** — `48,130 / ∞`. No limit. Section 3 is corrected.
- **Backups** — Soran has a backup of Halabja-phone, so their trading history
  survives the folder being deleted. Its **database must stay in cPanel**: that
  is what a rebuilt install restores from.
- **Backups of the panel's own database** — it holds the customer list, the
  licence history and the payment record, and losing it is worse than losing
  any one shop. It reuses the shop system's `BackupService`, nightly, with the
  off-machine copy, and a restore drill before go-live.
