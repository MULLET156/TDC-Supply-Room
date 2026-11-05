# TDC Supply Room

Supply inventory for Toledo Dental Care: counts, reorder points (desired reserve and 911 levels), the Order list / On order / Expiring lists, suppliers, reps, product photos and an activity log. Staff sign in with one office password; everything is stored in a MySQL database on Hostinger.

New products can be added from a supplier's product link: the server reads the page and Claude (through an Anthropic API key) fills in the name, SKU, price, package size, description and photo. A connector lets Claude (claude.ai or the Claude apps) look up and update the inventory directly.

## Files

| File | What it is |
|---|---|
| `index.html` | The Supply Room itself (the page staff open). |
| `api.php` | Saves and loads data for the page, handles the office password, stores photos, reads product links and asks Claude. |
| `blob.php` | Shows saved product photos (signed-in devices only). |
| `mcp.php` | The connector for Claude (Model Context Protocol). |
| `lib.php` | Code shared by the files above. Creates its database tables on first use. |
| `config.sample.php` | Template for `config.php`, which holds the passwords and keys. `config.php` is never committed. |
| `.htaccess` | Forces HTTPS and keeps `config.php`, `lib.php` and the `.git` folder private. |

Requirements: Hostinger web hosting with PHP 8.1 or newer, the cURL extension and MySQL (Premium or Business plans include all three).

## Set up on Hostinger

### 1. Choose where it lives and turn on SSL
In hPanel, pick the website or subdomain for the Supply Room (for example `supplies.toledodentalcare.com`; a subdomain is created under **Domains → Subdomains**). Under **Security → SSL**, make sure SSL is active. The Supply Room always redirects to `https://`.

### 2. Create the database
**Databases → Management → Create a new MySQL database.** Note the database name, username and password. Hostinger adds a prefix such as `u123456789_` to the name and username; you'll need the full versions.

### 3. Connect this GitHub repository
**Websites → Manage → Advanced → GIT.**

1. Because this repository is private, copy the **SSH key** Hostinger shows on that page. On GitHub, open this repository → **Settings → Deploy keys → Add deploy key**, paste it, and leave "Allow write access" unchecked.
2. Back in hPanel, under **Create a New Repository**:
   - Repository: `git@github.com:MULLET156/TDC-Supply-Room.git`
   - Branch: `main`
   - Directory: leave empty to install at the root of the site, or enter a folder name (for example `supplies`). **The folder must be empty.**
3. Click **Create**, then **Deploy**.
4. For automatic updates: click **Auto Deployment**, copy the webhook URL, and add it on GitHub under **Settings → Webhooks → Add webhook** (Payload URL = that link, event = "Just the push event"). After that, every push to `main` updates the live site.

### 4. Create `config.php`
**Files → File Manager**, open the folder from step 3, copy `config.sample.php` to `config.php`, and edit it:

```php
define('OFFICE_PASSWORD', 'the password your team will use');
define('DB_HOST', 'localhost');
define('DB_NAME', 'u123456789_supplies');   // from step 2
define('DB_USER', 'u123456789_supplies');   // from step 2
define('DB_PASS', 'the database password');
define('ANTHROPIC_API_KEY', 'sk-ant-…');    // step 6
define('APIFY_TOKEN', 'apify_api_…');       // step 6b (Amazon links)
define('MCP_KEY', 'a long random key');     // step 7
```

Pick a strong office password (at least 12 characters). Changing it later signs every device out.

### 5. Sign in and bring over your data
Open the site and sign in with the office password. Go to **Setup → Backup & import → Restore…** and pick the `.backup.json` file made from the claude.ai version. The preview shows what's in it; tap **Restore**. Restoring twice is safe: records with the same id are replaced, nothing is deleted.

Each person then picks their initials under **Logging as** at the top, the same as before.

### 6. Turn on Claude for product links and photos
At [console.anthropic.com](https://console.anthropic.com) create an API key (**API keys → Create key**), add a payment method, and put the key in `config.php` as `ANTHROPIC_API_KEY`. Cost is per use, typically a few cents per product page read.

With the key set, these work again: filling in a new item from its link, reading a SKU or product photo, name and category suggestions, and **Update from supplier pages**. Without it the page still works, and adding from a link fills in only what the page itself lists (name, SKU, photo, sometimes price).

The server reads product pages without signing in to your supplier accounts, so prices that only show after signing in (common at Henry Schein, Patterson and Benco) come back empty or as list price. Some supplier sites also block servers entirely. For those, add the product from Claude instead (step 7), which uses your signed-in browser.

**Importing invoices and screenshots.** Under **Add item → Add from an invoice or screenshot**, or **On order → Receive from invoice**, pick photos, screenshots or PDFs of an invoice, packing slip, order confirmation, quote or supplier page (up to 8 files). Claude reads every product line and matches it to your items. You check the matches, then choose whether to mark them received, mark them ordered, or only update prices; new products can be added in the same step. A screenshot of a supplier page you're signed in to brings in your account price. Each import costs a few cents (more for long PDFs).

### 6b. Amazon links (optional)
Amazon blocks servers from reading its product pages, so Amazon links go through [Apify](https://apify.com) and its Amazon Product Scraper (`junglee/amazon-crawler`). Create a free Apify account, copy the token from **Settings → API & Integrations → Personal API token**, and add it to `config.php`:

```php
define('APIFY_TOKEN', 'apify_api_…');
```

Apify's free plan includes $5 of usage a month; one Amazon product costs well under a cent. Reading an Amazon link takes 20–60 seconds. Without the token, Amazon links only fill in the SKU (ASIN).

The token also lets the Supply Room look products up when there's no link: **Add using a SKU photo** and **Update from supplier pages** for items without a reorder link search the web for the SKU and name (on the supplier's own site when its web address is known), Claude picks the matching product page, and it's read like a pasted link. Results found by search are marked so you can double-check them.

The same token is the fallback for any other supplier site that blocks the server or shows a robot check (Net32, for example): the page is opened in a real browser by Apify's RAG Web Browser (`apify/rag-web-browser`) and read the same way. Sites that let the server in (Henry Schein, Safco, Blue Sky Bio…) are read directly, free and in a few seconds. Neither way is signed in to your supplier accounts, so account-only prices still don't come through.

### 7. Connect Claude (optional)
1. Put a long random key in `config.php` as `MCP_KEY` (40 or more letters and numbers; a password manager's generator works).
2. In Claude: **Settings → Connectors → Add custom connector**. Name: `TDC Supply Room`. URL: `https://<your site>/mcp.php?key=<your MCP_KEY>`.
3. In a chat, turn the connector on and ask things like "What's on our Order list?", "We got the glove order, mark it received" or "Add this to the supply room: <product link>".

Anyone with the full connector URL can read and change the inventory, so keep it private. To disconnect everything, change or empty `MCP_KEY`.

What Claude can do through the connector: search items, see one item, list suppliers/categories/locations, add an item (with a photo from a link), change an item, record a count, put something on the order list, mark items ordered or received, and read a product page. Every change shows in the Activity log with the initials given (or `CL` for Claude).

## Updating

Edit the files and push to `main`. With auto deployment on, Hostinger picks the change up within a minute or two; otherwise click **Deploy** in hPanel → GIT. `config.php` and the database (including photos) are never touched by a deploy.

## Backups

Use **Setup → Backup & import → Full backup (.json)** regularly (for example monthly) and keep the file somewhere safe: it includes supplier logins. Hostinger's own backups (**Files → Backups**) also cover the database.

## Security notes

- Every request to `api.php` and `blob.php` needs the sign-in cookie, except signing in itself. Ten wrong passwords from one network blocks sign-in for 15 minutes.
- Sign-ins last 90 days per device. **Setup → Backup & import → Sign out** ends it on that device; changing `OFFICE_PASSWORD` ends it everywhere.
- The server only fetches public web addresses (never the local network), and only for signed-in users or the connector.
- Keep patient names and other patient information out of item notes.

## Local testing

`config.php` can point at SQLite instead of MySQL:

```php
define('DB_DRIVER', 'sqlite');
define('DB_SQLITE_PATH', __DIR__ . '/dev/test.sqlite');
```

Then run `php -S 127.0.0.1:8765` in this folder and open http://127.0.0.1:8765/.
