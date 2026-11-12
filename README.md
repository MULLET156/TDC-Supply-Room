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

### 6. Turn on AI for product links and photos
Any one AI key makes the site **AI powered** (shown in Setup → Data collection):
- **Claude:** at [console.anthropic.com](https://console.anthropic.com) create an API key (**API keys → Create key**) and add a payment method.
- **OpenAI:** at [platform.openai.com](https://platform.openai.com/api-keys) create an API key.
- **Google Gemini:** at [aistudio.google.com](https://aistudio.google.com/app/apikey) get an API key (has a free tier).
- **Your own model:** an OpenAI-compatible server (Ollama, LM Studio) that this website's server can reach over the internet, plus its model name. It can't read PDF invoices.

Paste it under **Setup → Data collection → AI**. The site checks the key, then writes it into `config.php` for you. With more than one, the first answers (Claude, then OpenAI, then Gemini, then your own, unless **Use first** says otherwise) and the rest take over automatically if it's down, out of credit or can't answer. Cost is per use, typically a few cents per product page read.

With a key set, these work: filling in a new item from its link, reading a SKU or product photo, name and category suggestions, and **Update from supplier pages**. Without it the page still works, and adding from a link fills in only what the page itself lists (name, SKU, photo, sometimes price).

The server reads product pages without signing in to your supplier accounts, so prices that only show after signing in (common at Henry Schein, Patterson and Benco) come back empty or as list price. Some supplier sites also block servers entirely. For those, add the product from Claude instead (step 7), which uses your signed-in browser.

**Importing invoices and screenshots.** Under **Add item → Add from an invoice or screenshot**, or **On order → Receive from invoice**, pick photos, screenshots or PDFs of an invoice, packing slip, order confirmation, quote or supplier page (up to 8 files). Claude reads every product line and matches it to your items. You check the matches, then choose whether to mark them received, mark them ordered, or only update prices; new products can be added in the same step. A screenshot of a supplier page you're signed in to brings in your account price. Each import costs a few cents (more for long PDFs).

### 6b. Amazon links (optional)
Amazon blocks servers from reading its product pages, so Amazon links go through [Apify](https://apify.com) and its Amazon Product Scraper (`junglee/amazon-crawler`). Create a free Apify account, copy the token from **Settings → API & Integrations → Personal API token**, and paste it in the site under **Setup → Data collection → Apify API key**; Save writes it into `config.php`. Or add it to `config.php` yourself:

```php
define('APIFY_TOKEN', 'apify_api_…');
```

Apify's free plan includes $5 of usage a month; one Amazon product costs well under a cent. Reading an Amazon link takes 20–60 seconds. Without the token, Amazon links only fill in the SKU (ASIN).

The token also lets the Supply Room look products up when there's no link: **Add using a SKU photo** and **Update from supplier pages** for items without a reorder link search the web for the SKU and name (on the supplier's own site when its web address is known), Claude picks the matching product page, and it's read like a pasted link. Results found by search are marked so you can double-check them.

The same token is the fallback for any other supplier site that blocks the server or shows a robot check (Net32, for example): the page is opened in a real browser by Apify's RAG Web Browser (`apify/rag-web-browser`) and read the same way. Sites that let the server in (Henry Schein, Safco, Blue Sky Bio…) are read directly, free and in a few seconds. Neither way is signed in to your supplier accounts, so account-only prices still don't come through.

**Other page services.** Instead of (or as well as) Apify, a [ScrapingBee](https://www.scrapingbee.com) key or a [Bright Data](https://brightdata.com) Web Unlocker key and zone name can read stores that turn the server away. Paste them under **Setup → Data collection**; **Blocked pages use** picks which one is tried first, and the others are tried if it fails. **Test page services** reads one product link through each service so you can compare them. Apify is still needed for Amazon links and web searches.

**Items without a link (added from an invoice or packing slip).** **Update from supplier pages** first looks for the item's SKU on the supplier's own store: the server walks the store's pages, notes every item number and which page lists it, and keeps that index for a week per site (the first item from a supplier can take about a minute; the rest are quick). This finds products on stores that list a whole size range in one table, like Blue Sky Bio, which web search can't. It uses the website saved for the supplier under Setup, or the built-in address for well-known suppliers (Blue Sky Bio, Henry Schein, Net32, Benco, Patterson, Darby, Safco). Only if the SKU isn't on the store does it fall back to a web search (needs APIFY_TOKEN). A result whose page doesn't show the item's SKU is flagged as possibly a different product. Approving a match saves its link, so later updates go straight to the page.

**Supplier lights.** Under **Setup → Suppliers**, each supplier with a website gets a light: green means its products are found and read on the server for free, orange means it works but uses Apify (paid), red means prices need a sign-in or the site blocks servers, so the browser extension or Claude reads them in your browser, and grey means it hasn't been checked yet. Open a supplier to see how its website is read and press **Check site** to work it out again.

### Browser extension for signed-in prices (optional)

The **Supply Room Price Helper** Chrome extension saves the prices staff see on supplier websites while signed in (Henry Schein, Patterson and others), with no AI and nothing to type.

1. **Setup → Data collection → Browser extension → Download**, and unzip it.
2. In Chrome open `chrome://extensions`, turn on **Developer mode**, press **Load unpacked** and pick the unzipped folder.
3. Back in Setup press **Turn on**, then **Copy pairing code**. Click the extension's toolbar button and paste the code.

From then on, opening a product page that matches a Supply Room product (by its saved link or an item number on the page) saves the price shown, prefers "Your price" over list price, and shows a small note in the corner. Prices go into the product's price history like any other price check. The pairing code can only list products and save prices; **New code** or **Turn off** in Setup disconnects every paired browser. The source is in `extension/`; rebuild the download with `cd extension && zip -r ../supply-room-extension.zip .`.

### 7. Connect Claude (optional)
1. In the site, open **Setup → Data collection → Claude connector** and press **Turn on**, then **Show link** and **Copy**. (Or put a long random key in `config.php` as `MCP_KEY` and build the link yourself: `https://<your site>/mcp.php?key=<your MCP_KEY>`.)
2. In Claude: **Settings → Connectors → Add custom connector**. Name: `TDC Supply Room`. URL: the link you copied.
3. In a chat, turn the connector on and ask things like "What's on our Order list?", "We got the glove order, mark it received" or "Add this to the supply room: <product link>".

Anyone with the full connector URL can read and change the inventory, so keep it private. To disconnect everything, press **New link** or **Turn off** in Setup → Data collection (or change or empty `MCP_KEY` in `config.php`).

What Claude can do through the connector: search items, see one item, list suppliers/categories/locations, add an item (with a photo from a link), change an item, record a count, put something on the order list, mark items ordered or received, read a product page, find an item's pages at your suppliers, record a price found at a supplier, and show an item's price history. Every recorded price is kept (in the `prices` collection, one entry per check), so Claude can tell you how a supplier's price has changed over time and which supplier is cheapest now. Every change shows in the Activity log with the initials given (or `CL` for Claude).

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
