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

## Step 0b. Getting the code onto the box

Both repositories are private, and **GitHub stopped accepting passwords for git
in 2021** — `git clone https://…` asks for a username and password and then
refuses whatever you type:

```
remote: Invalid username or token. Password authentication is not supported.
```

Two ways round it. Neither could be tested from here, so the checks below are
worth actually running.

### A. A fine-grained token — start here

It goes over HTTPS on port 443, which is never blocked. Shared hosting often
blocks outbound port 22, which is what option B needs.

Make it at **github.com/settings/personal-access-tokens/new**:

| Field | What to set |
|---|---|
| Repository access | **Only select repositories** → `systemmanagment` and `Soran-Panel` |
| Permissions → Contents | **Read-only** |
| Expiration | A year is fine; note the date |

⚠️ **Not a classic token.** A classic one with `repo` scope can write to every
repository you own, from a shared machine you do not watch. A fine-grained
read-only token on two repositories is as narrow as a deploy key.

On the server, store it once:

```bash
git config --global credential.helper store
```

Then clone as normal. Git asks once per host: username `ittz-soran`, password
**the token** (not your GitHub password).

⚠️ That writes the token to `~/.git-credentials` in plain text. Lock it down —
it is outside `public_html`, so no URL reaches it, but the file mode is worth
setting anyway:

```bash
chmod 600 ~/.git-credentials
```

⚠️ **Do not put the token in the remote URL** (`https://TOKEN@github.com/…`). It
lands in `.git/config`, gets copied by every backup, and shows up in `git remote
-v` output you might paste somewhere.

### B. Read-only deploy keys — tidier, if port 22 is open

A deploy key reads exactly one repository and nothing else on your account, and
never expires. It needs SSH out to GitHub.

```bash
mkdir -p ~/.ssh && chmod 700 ~/.ssh
ssh-keygen -t ed25519 -f ~/.ssh/id_shop  -N '' -C 'soransto shop system'
ssh-keygen -t ed25519 -f ~/.ssh/id_panel -N '' -C 'soransto panel'
chmod 600 ~/.ssh/id_shop ~/.ssh/id_panel
```

A deploy key belongs to one repository, so give each its own alias:

```bash
cat >> ~/.ssh/config <<'EOF'

Host github-shop
  HostName github.com
  User git
  IdentityFile ~/.ssh/id_shop
  IdentitiesOnly yes

Host github-panel
  HostName github.com
  User git
  IdentityFile ~/.ssh/id_panel
  IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config
```

Print each public key and add it under **Settings → Deploy keys → Add deploy
key** on its own repository:

```bash
cat ~/.ssh/id_shop.pub     # → github.com/ittz-soran/systemmanagment/settings/keys
cat ~/.ssh/id_panel.pub    # → github.com/ittz-soran/Soran-Panel/settings/keys
```

⚠️ **Leave "Allow write access" unticked.** The server only ever reads. A deploy
key that can write is one that can rewrite your shop system from a machine
nobody is watching.

**Check** — each should greet you by repository name and then refuse a shell,
which is success:

```bash
ssh -T git@github-shop
ssh -T git@github-panel
```

⚠️ If those hang or say "connection refused", the host blocks port 22. GitHub
also answers SSH on 443 — add `Port 443` and change `HostName` to
`ssh.github.com` in both blocks — or use option A instead.

With deploy keys, clone using the aliases:

```bash
git clone git@github-shop:ittz-soran/systemmanagment.git smart-store
git clone git@github-panel:ittz-soran/Soran-Panel.git panel
```

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

The URL below is the token form (option A in step 0b). On deploy keys it is
`git@github-shop:ittz-soran/systemmanagment.git`.

```bash
cd ~
git clone https://github.com/ittz-soran/systemmanagment.git smart-store
cd smart-store
composer install --no-dev --optimize-autoloader
```

**No `.env` here, and that is deliberate.** This folder is not an install — it
is the library every shop reads and the commands the panel runs, and it has no
shop and no database of its own. Each shop's `.env` lives in its own folder
under `~/shops`. Giving this one a `.env` would mean a database created purely
to make `shop:provision` runnable, and Section 13 records the database count as
the real ceiling on how many customers fit here.

### The compiled assets

`public/build` is not in the repository — it is built, not written — and **every
shop and the panel read their entire appearance from it**. Without it every
screen loads as unstyled HTML.

Try to build it on the server:

```bash
npm install && npm run build
```

⚠️ **`npm: command not found` is the normal answer on shared hosting**, and it
is not a problem to solve on the server. Two ways past it:

**Upload a built copy.** Ask for `build.tar.gz`, put it in `~/smart-store/public`
with cPanel's File Manager, and:

```bash
cd ~/smart-store/public && tar -xzf build.tar.gz && rm build.tar.gz
```

**Or use cPanel's own Node.** If the account has **Setup Node.js App**
(CloudLinux's selector), create an application pointed at `~/smart-store`, then
use the "Run NPM Install" button and its shell — `npm run build` works from
there. Worth doing if you would rather not upload a folder every time the
appearance changes.

**Check** — the manifest and at least one stylesheet:

```bash
ls ~/smart-store/public/build/manifest.json
ls ~/smart-store/public/build/assets/*.css
```

⚠️ **Rebuild whenever the shop system's front end changes.** `git pull` brings
the source, not the compiled output — a pull that changes a stylesheet and no
new `build/` leaves every shop looking at the old one.

**Check:** `php artisan list | grep shop:provision` prints the command.

---

## 2. The panel

On deploy keys the URL is `git@github-panel:ittz-soran/Soran-Panel.git`.

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

## 3. Its database, and everything else it must be told

Make one in cPanel → **MySQL Databases**: a database, a user, and the user
granted **ALL PRIVILEGES** on it. That account needs rights over this one
database only.

*(This account has since been read: `Databases 4 / ∞` — no limit. One shop is
still one database, but there is no allowance to run out of. PANEL_DOC
Section 13 records it; nothing here depends on the number any more.)*

Then let the panel ask for the rest, rather than editing the file:

```bash
cd ~/panel
php artisan panel:setup
```

It asks for the database, its user and its password — and **tests them before
going on**, so a wrong one is caught while you are still looking at the cPanel
page it came from. Then the cPanel prefix (offered from the database name you
just gave), your name, email and a password for signing in, and the address the
panel will answer on. Nothing is written until every answer is in.

⚠️ **Do not type these into `.env` by hand.** The template ships a database name
that is a guess and wrong on every account, so `Access denied` for a name you
never chose reads as a broken panel rather than an unedited setting. And a
generated password often contains a backslash — in a double-quoted `.env` value
that is an escape sequence, and an invalid one makes dotenv reject the *whole
file*, losing every setting at once rather than just that one.

```bash
php artisan migrate --force      # the panel’s tables
php artisan db:seed --force      # you, so you can sign in
```

There is **no sign-up page and no forgotten-password email** — `MAIL_MAILER` is
`log` on this hosting, so a reset link would never leave the building. Running
`db:seed` again does not reset the password.

**Set up the authenticator the first time you sign in.** It is the only way back
in, and `panel:check` will keep saying so until you do.

---

## 5. The rest of the settings

`panel:setup` and the template between them have set most of this. These are
the paths, and they are only wrong if your account is not at
`/home/soransto` — step 0 told you:

```
PANEL_SHOPS_HOME=/home/soransto/shops
PANEL_SHOPS_PUBLIC=/home/soransto/public_html
PANEL_SHARED_ARTISAN=/home/soransto/smart-store/artisan
```

`PANEL_CPANEL_PREFIX`, which `panel:setup` asked for, matters more than it
looks. cPanel creates `soransto_bazaar_shop` when asked for `bazaar_shop`, and
the panel has to record the name the shop will really connect to — get it wrong
and the panel cannot read that shop again.

```bash
mkdir -p ~/shops
php artisan panel:check
```

⚠️ **`panel:check` is meant to be red here**, and this is the point people
undo working settings trying to fix it. Until the shop system is installed it
will say the shared codebase is missing, and until the compiled assets are
copied in it will say the stylesheet is. Both are steps 1 and 2, and both are
below. What must already be green: the panel's own database, somebody who can
sign in, the seller's public key, and its `.env` not being on the web.

---

## 6. Where the domain points

This is two separate jobs in two different places, and doing only the first is
the trap: cPanel sets up the **web server**, Cloudflare publishes the **name**.
Neither one does the other's half.

### 6a. cPanel — the web server

```bash
php artisan panel:public /home/soransto/public_html/panel
```

Then in cPanel → **Domains** → *Create A New Domain*, make
`panel.soranstore.com` with its document root at
`/home/soransto/public_html/panel`.

⚠️ Newer cPanel has no separate **Subdomains** page — a subdomain of a domain
you already own is created here, and it is the same thing. Do not go looking
for Subdomains and conclude something is missing.

⚠️ cPanel will offer its own path. Let it create the domain, then check the
Document Root column really says that folder — Section 4 records it silently
using its own and ignoring what was typed.

### 6b. Cloudflare — the name

**The nameservers for `soranstore.com` are Cloudflare's, so cPanel's DNS zone
is not authoritative and nothing it writes is ever published.** Adding the
domain in cPanel gives you a working web server that no one can reach:

```
This site can’t be reached
panel.soranstore.com’s server IP address could not be found.
ERR_NAME_NOT_RESOLVED
```

In the Cloudflare dashboard → **DNS** → *Add record*:

| Field | Value |
|---|---|
| Type | `CNAME` |
| Name | `panel` |
| Target | `soranstore.com` |
| Proxy status | **DNS only** (grey cloud) — for now |

A CNAME to the main domain rather than an A record to an IP address, so it
follows the main domain wherever it moves and there is no address to copy
wrongly.

⚠️ **Grey cloud first, not orange.** cPanel's AutoSSL proves it owns the name
by answering a request on it over plain HTTP, and it can only do that if the
request reaches the server. Turn the proxy on before the certificate exists and
the next step has nothing to validate against.

**Check** — from any machine, the name now answers:

```bash
getent hosts panel.soranstore.com     # or: nslookup panel.soranstore.com
```

### 6c. The certificate, then the proxy

In cPanel → **SSL/TLS Status**, tick `panel.soranstore.com` and *Run AutoSSL*.
Wait for it to report a certificate.

Only then, in Cloudflare: switch that record to **Proxied** (orange cloud), and
under **SSL/TLS → Overview** set the mode to **Full (strict)** — Section 4.

⚠️ Order matters. Full (strict) with no certificate on the origin is a
Cloudflare **526** error, which looks like the panel is broken when the only
thing missing is the certificate.

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
2. On your own machine, run the command the panel shows you. Open the shop in
   the panel and press **Renew**: step 1 of that screen prints the exact line,
   with the shop's name and host already filled in and a Copy button beside it.
   It is `licence:issue "<name>" --host=<host> --months=1 --key=<your key>` —
   the name is a positional argument and comes first, before the options.
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

⚠️ **Re-run all three after any `.env` change**, or the panel keeps reading the
old one. `panel:setup` clears the config cache for you when it writes, so its
answers always take effect — but it does not re-cache, so run these again
afterwards.

Do this **after** everything else works, and run all three again after any
`.env` change. A cached config reads the old file for ever — which is the same
trap the panel clears for a shop after delivering a licence.

⚠️ If something breaks straight after this, `php artisan optimize:clear` puts it
back.

---

## If something goes wrong

| What you see | Where to look |
|---|---|
| `git clone` asks for a password and then refuses it | GitHub has not accepted passwords for git since 2021. Step 0b — a token or a deploy key. |
| Unstyled HTML | `public/build` is missing. Copy it from the shop system, then `panel:public` again. |
| A 500 with no detail | `storage/logs/laravel.log`. `APP_DEBUG` stays off. |
| `.env` visible in a browser | The code is inside the document root. Move it out and run `panel:public`. |
| A setting change does nothing | `php artisan optimize:clear`, then re-cache. |
| "Health" never updates | Cron is not calling `schedule:run`. Check the PHP path. |
| Creating a customer fails | The message names the step. Everything it made is taken back. |
| Anything else | `php artisan panel:check` |
