<?php
/*
 * TDC Supply Room – settings
 *
 * Copy this file to config.php (in the same folder) and fill it in.
 * config.php is never committed to GitHub, so your passwords stay on the server.
 */

// The one password the whole office uses to sign in.
// Changing it later signs every phone and computer out.
define('OFFICE_PASSWORD', 'change-me');

// MySQL database from Hostinger hPanel → Databases → Management.
define('DB_HOST', 'localhost');
define('DB_NAME', 'u000000000_supplies');
define('DB_USER', 'u000000000_supplies');
define('DB_PASS', 'database-password-here');

// The three keys below can be left empty here and pasted in the site instead:
// Setup › Data collection. Saving there checks the key and writes it into this file
// (into the database instead, if the server doesn't let the site change this file).
// Keys are never sent back to the page.

// AI, for reading product links, photos, price lists and invoices, and finding substitutes.
// Any one is enough; with several, the first (or AI_PROVIDER) answers and the others are backups.
// Leave them all empty and the site is "Not AI powered": pages are read from the store's own product data.
define('ANTHROPIC_API_KEY', '');   // Claude: console.anthropic.com → API keys
// define('ANTHROPIC_MODEL', 'claude-sonnet-5-5');        // product pages, photos
// define('ANTHROPIC_MODEL_QUICK', 'claude-haiku-5-5');   // quick suggestions
// define('OPENAI_API_KEY', '');   // OpenAI: platform.openai.com → API keys (newest model picked automatically)
// define('OPENAI_MODEL', '');     // optional: force a model
// define('GEMINI_API_KEY', '');   // Google Gemini: aistudio.google.com → Get API key (newest model picked automatically)
// define('GEMINI_MODEL', '');     // optional: force a model
// define('AI_LOCAL_URL', '');     // your own model's OpenAI-compatible address (Ollama, LM Studio…), reachable from this server
// define('AI_LOCAL_MODEL', '');   // its model name, e.g. qwen2.5vl
// define('AI_PROVIDER', '');      // claude, openai, gemini or local: which answers first (default: that order)

// Amazon links: Amazon blocks servers from reading its pages, so they go through
// Apify (apify.com → Settings → API & Integrations → Personal API token).
// Leave empty and Amazon links fill in only the SKU (ASIN).
define('APIFY_TOKEN', '');

// Connector for Claude (mcp.php). Make up a long random key (40+ letters and
// numbers), or press Turn on in Setup › Data collection to have one made for you.
// The connector address is https://<your site>/mcp.php?key=<this key>.
// Leave empty to turn the connector off.
define('MCP_KEY', '');

// Dates written by the server (ordered / received) use this time zone.
define('TIMEZONE', 'America/Los_Angeles');

// Other services for reading stores that turn servers away (optional; any one is enough).
// Paste them in Setup › Data collection, or fill them in here.
define('SCRAPINGBEE_KEY', '');   // app.scrapingbee.com › API key
define('BRIGHTDATA_KEY', '');    // Bright Data › Account settings › API keys
define('BRIGHTDATA_ZONE', '');   // your Web Unlocker zone name, e.g. web_unlocker1
define('PAGE_SERVICE', '');      // tried first: brightdata, scrapingbee or apify ('' = automatic)
