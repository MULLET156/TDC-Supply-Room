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
// Setup › Data collection (they're stored in your database, never sent back to the page).
// A key filled in here wins over one pasted in Setup, and can only be changed here.

// Claude, for reading product links and photos (console.anthropic.com → API keys).
// Leave empty to turn those features off; everything else still works.
define('ANTHROPIC_API_KEY', '');
// define('ANTHROPIC_MODEL', 'claude-sonnet-5-5');        // product pages, photos
// define('ANTHROPIC_MODEL_QUICK', 'claude-haiku-5-5');   // quick suggestions

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
