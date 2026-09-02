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
customer_id, licence_id (K7QP-3MZX), host, licence_key (the signed string, text)
issued_on, expires_on   null = sold outright
delivered_at            when it was actually written into their .env — confirmed, never assumed
issued_by, revoked_at, revoked_reason, timestamps
```

**A renewal is a new row, never an edit.** That is what makes the licence history on a customer's page possible, and the only way to answer "when did this shop last actually pay" months later.

**The column is `licence_key`, not `key`.** `KEY` is a reserved word in MariaDB. It survives the query builder, which quotes its own identifiers, and fails the moment anyone types SQL by hand — `SELECT key FROM licences` is a syntax error, and so is `SET key = …` in a repair. Checked on the real engine rather than assumed. Section 1 records this project having already shipped one MariaDB reserved word that SQLite accepted; this is the same trap, caught before it was built.

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

**The panel verifies with the same public key every shop ships** — the committed default from the shop system's `config/licence.php`, copied into the panel's own. If those two ever differ, the panel accepts a string the customer's shop then rejects, and the customer is locked out by the very act of renewing. Nothing in the panel can sign: a test walks `app/`, `config/` and `routes/` asserting no file can call `openssl_sign`.

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

**Suspending uses the licence as the lever.** Taking `LICENCE_KEY` out of a shop's `.env` puts it in the state the shop system already has a considered answer for: read-only, with reading, printing, deleting and signing in untouched. PROJECT_DOC is explicit that a shop locked out of its own records is a shop that will never pay another invoice — and being paid is the point of suspending somebody. Resuming puts the same string back; nothing new is signed.

**⚠️ Build order step 7 said "seed from `install:sql`", and that cannot work here.** `install:sql` builds a scratch database, fills it, dumps it and drops it — its own help says "This needs an account that may CREATE DATABASE — your local MySQL root, usually" — and Section 4 measured that this cPanel account denies exactly that. It exists for shops with no terminal, and the panel has one. The panel runs `migrate` and `db:seed` on the shop's own `artisan`, which is what `install:sql`'s own docblock calls the seller's-machine equivalent.

**A shop's `.env` is rewritten atomically**, through a temporary file renamed over the top, with the old one kept as `.env.bak` at the same permissions. A rename within one directory is atomic, so a shop being served in that instant reads the whole old file or the whole new one and never half of either — and a half `.env` is not a shop with a wrong setting, it is a shop that does not start, with its `APP_KEY` gone and every encrypted column unreadable for ever.

---

## 8. Reading a shop

Every shop is on the same server, so the panel reads directly — no HTTP endpoint, no remote-control door opened in every customer's install. Soran's decision, and it is the safer one for as long as he hosts everyone himself.

- **Its settings and data:** a second database connection, configured at runtime from the customer row. Read-only queries.
- **Its storage:** the filesystem, under `shop_home`.
- **Its own opinion of itself:** run its artisan commands through the shared codebase with `SHOP_HOME` set — `licence:show`, `migrate:status`, the data check. This is exactly how a shop runs, so the answer is the shop's own.
- **Its data check:** the seventeen Section 10b assertions from PROJECT_DOC, against live data. Read-only, deliberately: a contradiction is evidence, and repairing it before it has been read destroys the record of what went wrong.

**If a customer is ever hosted elsewhere,** this is the part that changes, and it changes alone. Keep the reading behind one service so a second implementation can slot in. That service is `App\Contracts\ShopReader`, with one method; `LocalShopReader` is the implementation for shops on this server, and it is bound in one line of `AppServiceProvider`.

⚠️ **The shop's process must not inherit the panel's environment.** Laravel exports every key of its `.env` into the process environment, a child process inherits it, and an environment variable beats the `.env` file beside it. So the first version of this had a shop's own `artisan` boot with the *panel's* `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` — the shop reported 3 of 32 migrations run and 0 of 17 assertions passing, and both numbers were true readings of the panel's own database, where exactly three of the shop system's migration names also exist. Read-only commands made that a wrong number. Section 7 has the panel run a shop's `migrate` and `backup:run` as well, and the same leak there points them at the panel's database — the customer list, the licence history and the payment record. `LocalShopReader` strips those variables from every subprocess, and `ShopReaderTest` fails if it stops.

**The panel keeps no shop's database password.** Section 5 records the database *name* and *user* on the customer row and stops there; the password is read out of the shop's own `.env` at the moment of connecting, and never stored. A dump of the panel's database hands over the customer list, and not the keys to every shop.

**Read-only is enforced, not intended.** The panel connects with the shop's own credentials, because that is the only account there is, and those credentials can do anything — nothing at the database end says no. `App\Support\ReadOnlyConnection` refuses `insert`, `update`, `delete`, `statement`, `affectingStatement`, `unprepared` and `beginTransaction` by name, before they reach the server. It is not a boundary against a hostile panel; it is a guard against the ordinary mistake of a write on a model that happens to be bound to a shop's connection.

---

## 9. Pages

Mockups were built and approved against the shop system's own stylesheet, so the panel looks like the same product.

| Page | What it is for |
|---|---|
| **Overview** | Only what needs Soran this week: licences running out, storage near its limit, shops nobody has used. Everything else is a number. |
| **Customers** | The working screen. Shop, licence state, expiry, storage, last used, schema, monthly fee. Filter by "needs chasing". |
| **One customer** | Licence with its full history, storage broken into database/backups/uploads, whether they are actually using it, its schema state, and the danger zone. |
| **Renew** | The paste-and-verify flow from Section 6, with what-will-happen shown beside it. |
| **New customer** | One form replacing six manual steps. |
| **Subscriptions** | Who has paid, who has not, what the month is worth. |
| **Health** | Each shop's own report on itself, read hourly. |
| **What I changed** | The `actions` log. |

**"Code version" was replaced by "schema".** These pages were designed on 30 August; Section 3 settled the shared codebase on the 31st, and it says two installs differ in exactly four things — code is not one of them. Every shop reads one folder, so a per-shop code version is the same string on every row by construction and can tell Soran nothing. What still differs, and answers the question that column was there to ask, is whether a shop has RUN the shared code's migrations: "Up to date" or "4 migrations behind", from the hourly check.

**The three attention thresholds live in `config/panel.php`**, not spelled through the code: 30 days for a licence, 80% for storage, 14 days unused. They are judgement rather than fact, and the right line moves as Soran learns which warnings he acts on. The screens say what the current numbers are.

**Every figure on a screen comes from the last check that could READ the shop, and the state from the last check.** They are not always the same check. Section 5 keeps snapshots in a table so a failed check does not wipe the last good reading, and a screen that shows "unknown" everywhere the moment a shop goes down throws that away — which is when somebody most wants to know what it looked like an hour ago. An unreachable shop shows its real figures, dated, beside a red badge saying it cannot be reached now.

**Subscriptions reads no licence, on purpose.** A licence is what a shop may run on; a payment is money that arrived. They come apart exactly when it matters — a licence delivered before the money, or money taken for months not yet issued — and a screen that conflated them would show a customer as settled because they can still trade. Owing is asked as "nobody has paid for a period that reaches today", which covers the customer who has never paid without a second clause. A shop that has stopped trading owes nothing: whatever it owed when it left is a conversation, not a figure to add a month to for ever.

**A reading older than the licence cannot disagree with it.** The hourly check runs on its own schedule, so straight after a renewal the newest reading is usually older than the licence — and comparing them then reports "the shop says unlicensed" about a shop that was asked, answered `valid`, and has been fine since. One customer only flags a disagreement when the reading was taken after `delivered_at`.

**`php artisan panel:check`** asks every setting the panel depends on a real question — its own database, somebody who can sign in, the shared codebase's `artisan`, both shops folders, whether a database can actually be created, the public key, and the borrowed stylesheet — and names the ones that are wrong. It exists because the panel is developed on a local machine and runs on cPanel, and the two need different answers to nearly all of it; without it a wrong setting surfaces halfway through creating a customer. A warning does not fail it: a check that goes red for things that are merely worth knowing teaches you to ignore it.

**The panel is deployed the same way a shop is** — `DEPLOY.md` is the checklist. Section 4 measured that a document root cannot leave `public_html`, so the panel's code lives outside it and `php artisan panel:public <folder>` writes the five files the web is allowed to see. The one file that has to differ is `index.php`: Laravel's reaches its application through `__DIR__.'/../vendor/autoload.php'`, and once the folder has moved, `..` is `public_html`.

⚠️ **The panel had no deny-all `.htaccess`, and the shop system did.** Section 4 records finding a real customer's install serving its `.env` and `laravel.log` to anyone; the panel's `.env` is worse — the database holding every customer, licence and payment, the admin password, and an account that may create and drop databases. It has one now, with `public/` granting itself back, and `panel:check` fails without it.

**Design:** Bootstrap 5.3, the shop system's compiled stylesheet, same shell. English only — the panel has one reader. (The shop system's four languages and RTL do not apply here, and `translations:check` does not cover it.) The stylesheet **subsets its icons** — 74 of Bootstrap Icons' two thousand — so an icon that is perfectly real draws as nothing; `BorrowedStylesheetTest` fails the suite and names the file.

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
5. **Customers, one customer, Overview** — the screens that only read. **Done 2026-09-01.** "Code version" became "schema" — see Section 9.
6. **Renew** — paste, verify, deliver, confirm. *(Task #42)* **Done 2026-09-01.**
7. **New customer** — UAPI database creation, provision, seed, issue. **Done 2026-09-01**, along with operators, storage limits and suspend/resume. Not `install:sql` — see Section 7. ⚠️ The cPanel UAPI half has not been run against a real cPanel account; the first customer created on the server is what proves it.
8. **Subscriptions and payments.** **Done 2026-09-01**, with Health and What I changed — the two Section 9 pages this list never gave a step of their own. Also `panel:check`, which says whether a machine is set up to run the panel at all.
9. **Deploy** — `smart-store` and `panel` on the server, `panel.soranstore.com`. **Prepared 2026-09-02:** `DEPLOY.md` is the checklist, `panel:public` writes the panel's public folder, and `panel:check` says whether the machine is ready. The steps that need the real cPanel account are marked ⚠️ and are Soran's to run.
10. **Soran's own shop first**, then Halabja-phone rebuilt through the panel.

---

## 12. Task Log

| Date | Done | Next |
|---|---|---|
| 2026-09-02 | **Deploy** (build order step 9) — everything for it that could be done from here, and it is more than a document. The panel is deployed the way a shop is: `php artisan panel:public <folder>` writes the five files the web may see into a folder inside `public_html`, with an `index.php` that names the panel's base absolutely, because Section 4 measured that a document root cannot leave `public_html` and `..` from there is not the panel. **Verified by building that arrangement and serving the panel through it**: signed in, all six pages 200, fully styled, and `.env`, `artisan`, `config/panel.php` and the log all unreachable — the code is not under the document root at all. **The panel had no deny-all `.htaccess` and the shop system did**, which is the gap that made Section 4's Halabja finding possible, and the panel's `.env` is worth more than any one shop's: it now has one, `public/` grants itself back, and a test holds both. `panel:check` grew the production half — debug off, key set, https, folders writable, cron actually running, and its own `.env` not on the web — and judges those only where they apply, because `APP_DEBUG=on` is the right answer on a laptop and a check that goes red for being a laptop is one nobody reads. `DEPLOY.md` is the ordered cPanel checklist, marking ⚠️ the three things only the real account can confirm: UAPI, the cron PHP path, and the subdomain's document root. Suite: 327 tests, 327 passing, on both engines. | Soran's own shop, then Halabja-phone rebuilt through the panel (step 10) |
| 2026-09-01 | **Subscriptions and payments** (build order step 8), and the last two Section 9 pages — **Health** and **What I changed** — which had no step of their own and would otherwise never have been built. Every page Section 9 names now exists; a test opens each one from the sidebar and fails if any is still a dead entry. Subscriptions reads no licence, deliberately: money and permission-to-trade come apart exactly when it matters, and owing is asked as "nobody has paid for a period that reaches today", which covers the customer who never paid at all without a second clause. Payments can be recorded, corrected and removed — removed ones stop counting and stay on record, because a payment that can vanish is one somebody can deny receiving. Two things found by looking at the screens rather than by a test. **An ended shop was shown as "24 months" behind** while the Owing filter correctly left it out — the model disagreed with its own scope, and a shop that has stopped trading owes nothing. And **a health check older than a licence was being reported as the shop disagreeing with the panel**: straight after a renewal the newest reading predates the licence, so the customer page cried wolf about a shop that had just answered `valid` — it now only compares readings taken since `delivered_at`. Also **`php artisan panel:check`**, because Soran is testing on a local machine and the panel runs on cPanel: it asks every setting a real question and names the wrong one, instead of letting it surface halfway through creating a customer. With a documented `.env.example` carrying both sets of answers. Suite: 312 tests, 312 passing, on both engines; driven in Chromium against four shops with real money data, and a payment recorded through the real form with the two-second hold. | Deploy (step 9), then Soran's own shop (step 10) |
| 2026-09-01 | **Renew (step 6), and everything else needed to actually run the panel** — operators, New customer (step 7), storage limits, suspend and resume. Renew is Section 6's eight steps in that order: the command is shown for Soran's own machine and the panel signs nothing, the paste is verified **before** anything is written, the licence is saved as not-delivered, the shop's `.env` is rewritten, the blank `LICENCE_PUBLIC_KEY` a trial leaves is removed, the cache is cleared, and only then is the shop asked what it thinks — `delivered_at` comes from that answer or not at all. Findings worth keeping. **Section 6 says remove "the blank `LICENCE_PUBLIC_KEY` line" and the first version removed it whether or not it was blank**, which would silently move a shop with a deliberate key onto the committed default. **`.env.bak` was written world-readable** — `file_put_contents` creates at 0644, so backing up a 0600 `.env` copied out the database password and `APP_KEY`, which is Section 4's Halabja finding recreated by the backup step meant to make renewing safe; caught by looking at permissions on a real shop. **The rollback left every public folder standing**, because its safety guard only allowed the shops root and Section 4 forces the two roots apart — a folder that looks provisioned is one somebody later points a domain at. And **a Blade comment is not inert**: Blade pulls raw `@php` blocks out before it strips comments, so a comment that merely mentions `@php` or an opening PHP tag opens a block that swallows every directive after it. That was made twice, in comments written to explain the first one, and it is a 500 on a live screen; `ViewsCompileTest` now lints every compiled template and names the comment. Step 7 does **not** use `install:sql` — it needs an account that may `CREATE DATABASE`, which Section 4 measured as denied here — and Section 7 above records why. Verified end to end against real MariaDB and the real shop system: a licence issued by `licence:issue` and delivered through the panel took a shop from `missing` and read-only to `valid`; suspending made it `missing` again and resuming brought it back; and **a whole new shop was created from nothing** — database, folder, 32 migrations, 17 of 17 assertions, reading only its own data, running as a trial that can actually trade. In Chromium: a quick click does not submit, a two-second hold does, a bad paste is refused with nothing written, and both typed confirmations gate their buttons. Suite: 256 tests, 256 passing, on both engines. | Subscriptions and payments (step 8), then deploy (step 9) |
| 2026-09-01 | **Overview, Customers and One customer** (build order step 5) — the screens that only read. The Overview shows Section 9's three lists and nothing else as a list; Customers is the working table with the one filter Section 9 names; One customer has the licence with its whole history, storage split three ways, whether anybody is using it, and the danger zone with every one of Section 7's destructive actions named and disabled with the reason on the button. **"Code version" is replaced by "schema"**, and Section 9 above now says why: those pages were designed the day before Section 3 settled the shared codebase, and a per-shop code version is now the same string on every row by construction — what differs is whether a shop has run the shared code's migrations. Three findings, and only one came from a test. **The Overview said three shops needed Soran while the Customers filter showed two** — a shop on two lists at once was counted twice, which is the exact disagreement the filter exists to avoid; the scopes agreed with each other perfectly, so nothing failed until the page was opened. **The screens threw away the last good reading**: they read the newest check for everything, so a shop that went down showed "unknown" in every column, making Section 5's whole reason for keeping snapshots pointless — state now comes from the last check and every figure from the last check that could read the shop, dated when they differ. And **`config/panel.php` was overwritten**, deleting the first-operator config the seeder reads. `FirstOperatorTest` existed and stayed green: every test in it injects the config with `config()->set`, which proves the seeder's logic and not that there is anything for it to read — so the only way into a freshly migrated panel was gone and the suite said nothing. It now also asserts the keys are really in the file, and fails when they are not. Also caught by the step 2 icon guard: eight icons that are perfectly real and absent from the shop system's 74-icon subset. Verified in Chromium light and dark against four seeded shops and one really provisioned one, no console errors. Suite: 147 tests, 147 passing, on both engines. | Renew — paste, verify, deliver, confirm (step 6, Task #42) |
| 2026-09-01 | **Reading a shop** (build order step 4), and the hourly health check. `ShopReader` is one interface with one method, as Section 8 asks, so a shop hosted elsewhere changes one line; `LocalShopReader` reads a shop four ways — its data over a second connection, its disk under `shop_home`, its own opinion from its own `artisan`, and `data:check`. `shops:check` writes one snapshot per shop per run and never stops early: one shop with a stopped database is a row saying so, and the other five are looked at anyway. **The bug worth remembering is the environment leak.** A child process inherits its parent's environment and Laravel exports its whole `.env` into it, so the shop's `artisan` booted with the panel's database credentials and answered about the panel's own database — 3 of 32 migrations, 0 of 17 assertions, both true and both about the wrong database. Read-only commands made it a wrong reading; Section 7 has the panel run a shop's `migrate` too, and that would have been the panel's own tables. Section 8 above now records it, and a test fails if the guard goes. Also: read-only is enforced in `ReadOnlyConnection` rather than intended, verified against the real shop — the write is refused and the administrator's name is unchanged; and the panel keeps no shop's database password, reading it from the shop's `.env` at the moment it connects. In the shop system, on `claude/shop-provision`: `licence:show --json` and a new `data:check` command, because Section 8 needs answers a machine can read and the data check existed only as a screen you had to sign in to a customer's shop to see. Verified against a really provisioned shop: 32 of 32 migrations, 17 of 17 assertions, `unlicensed`, 2 MB. Suite: 110 tests, 110 passing, on both engines. | Customers, one customer, Overview (step 5) |
| 2026-09-01 | **The schema and the models** (build order step 3): the five remaining tables of Section 5 — `customers`, `licences`, `payments`, `health_checks`, `actions` — with their models, factories and twenty-one tests, run on SQLite and on real MariaDB. **One column is renamed:** Section 5 spelled the signed string `key`, and `KEY` is reserved in MariaDB — quoted it works, hand-typed it is a syntax error, checked on the engine rather than assumed. It is `licence_key`, and Section 5 above now says so. Two things the tests caught that reading would not have. `currentLicence` filtered *outside* the `latestOfMany` subquery, so `MAX(issued_on)` picked the newest licence and the filters then threw it away if it happened to be revoked or undelivered — a shop whose last licence was revoked would have shown as having none at all, while running perfectly well on the one before it; the filters are inside the subquery now, with `id` as a tiebreaker because two licences on one day is the ordinary case when a delivery fails and is redone. And every count on `health_checks` is nullable rather than zero, so a check that could not look stays distinguishable from a shop that genuinely has no sales — zero products is a broken install, "we do not know" is a broken check. `actions` is append-only, with no `updated_at` to edit it by, and it outlives the operator who made it. Suite: 76 tests, 76 passing, on both engines. | Reading a shop (step 4), with the hourly health check |
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
