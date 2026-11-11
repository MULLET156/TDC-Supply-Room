/* Supply Room Price Helper: talks to the office's Supply Room (ext.php) for the content script.
   Pairing (site address + key) and settings live in chrome.storage.local on this computer only. */
const INDEX_MAX_AGE = 30 * 60 * 1000;   // refresh the product list every 30 minutes
let index = null, indexAt = 0;

const get = keys => chrome.storage.local.get(keys);
const set = obj => chrome.storage.local.set(obj);

async function api(op, body) {
  const { site, key } = await get(["site", "key"]);
  if (!site || !key) throw new Error("Not paired yet. Open the extension and paste the code from Setup › Data collection › Browser extension.");
  const r = await fetch(new URL("ext.php?op=" + op, site).href, {
    method: body ? "POST" : "GET",
    headers: { "Content-Type": "application/json", "X-TDC-Ext-Key": key },
    body: body ? JSON.stringify(body) : undefined, credentials: "omit"
  });
  const j = await r.json().catch(() => ({ ok: false, message: "The Supply Room answered with an error (" + r.status + ")." }));
  if (!r.ok || !j.ok) throw new Error(j.message || "The Supply Room said no (" + r.status + ").");
  return j;
}
async function getIndex(force) {
  if (!force && index && Date.now() - indexAt < INDEX_MAX_AGE) return index;
  index = await api("index"); indexAt = Date.now();
  return index;
}
const norm = s => String(s || "").toUpperCase().replace(/[^A-Z0-9]/g, "").replace(/^0+/, "");
const host = u => { try { return new URL(u).hostname.replace(/^www\./, "").toLowerCase(); } catch { return ""; } };
const pathKey = u => { try { const x = new URL(u); return x.hostname.replace(/^www\./, "").toLowerCase() + x.pathname.replace(/\/$/, ""); } catch { return ""; } };

/* Which Supply Room product is this page? A saved link wins; otherwise an item number on the page that matches
   exactly one product's supplier item number or manufacturer number. */
function match(ix, page) {
  const pk = pathKey(page.url);
  let hit = ix.items.filter(it => it.urls.some(u => u.toLowerCase() === pk.toLowerCase()));
  let how = "link";
  if (!hit.length) {
    const codes = new Set((page.codes || []).map(norm).filter(c => c.length >= 4));
    hit = ix.items.filter(it => it.codes.some(c => codes.has(norm(c))));
    how = "item number";
  }
  if (hit.length !== 1) return { item: null, how, count: hit.length };
  return { item: hit[0], how };
}
function supplierFor(ix, url) {
  const h = host(url);
  const s = ix.sites.find(x => h === x.host || h.endsWith("." + x.host) || x.host.endsWith("." + h));
  if (s) return s.name;
  return { "henryschein.com": "Henry Schein", "pattersondental.com": "Patterson Dental", "benco.com": "Benco", "darbydental.com": "Darby Dental", "safcodental.com": "Safco", "net32.com": "Net32", "frontierdental.com": "Frontier Dental", "dentalcity.com": "Dental City" }[h.split(".").slice(-2).join(".")] || h;
}

chrome.runtime.onMessage.addListener((msg, sender, reply) => {
  (async () => {
    try {
      if (msg.type === "page") {
        const { auto = true, paused = false } = await get(["auto", "paused"]);
        if (paused) return reply({ ok: true, paused: true });
        const ix = await getIndex();
        const m = match(ix, msg.page);
        if (!m.item) return reply({ ok: true, item: null, count: m.count });
        const sup = supplierFor(ix, msg.page.url);
        const res = { ok: true, item: { id: m.item.id, name: m.item.name, price: m.item.price, units: m.item.units }, how: m.how, supplier: sup, auto };
        // save once per product, supplier and price (a changed price saves again)
        const seenKey = "seen:" + m.item.id + ":" + sup;
        const seen = (await get([seenKey]))[seenKey];
        if (msg.page.price && seen && Math.abs(seen.price - msg.page.price) < 0.005 && Date.now() - seen.at < 7 * 86400e3) res.already = seen;
        else if (msg.page.price && auto) res.saved = await save(m.item, sup, msg.page);
        return reply(res);
      }
      if (msg.type === "save") {
        const ix = await getIndex();
        const it = ix.items.find(x => x.id === msg.id); if (!it) throw new Error("That product isn't in the Supply Room any more.");
        return reply({ ok: true, saved: await save(it, supplierFor(ix, msg.page.url), msg.page) });
      }
      if (msg.type === "pair") {
        let site = "", key = "";
        try { const j = JSON.parse(atob(String(msg.code || "").trim())); site = j.u; key = j.k; } catch {}
        if (!/^https:\/\//.test(site || "") || !key) throw new Error("That isn't a pairing code. Copy it again from Setup › Data collection › Browser extension.");
        await set({ site, key }); index = null;
        const p = await api("ping");
        return reply({ ok: true, name: p.name, site });
      }
      if (msg.type === "status") {
        const s = await get(["site", "key", "auto", "paused", "log"]);
        return reply({ ok: true, paired: !!(s.site && s.key), site: s.site || "", auto: s.auto !== false, paused: !!s.paused, log: s.log || [] });
      }
      if (msg.type === "settings") { await set(msg.values || {}); return reply({ ok: true }); }
      if (msg.type === "unpair") { await chrome.storage.local.clear(); index = null; return reply({ ok: true }); }
      if (msg.type === "refresh") { await getIndex(true); return reply({ ok: true, items: index.items.length }); }
      reply({ ok: false, message: "Unknown request" });
    } catch (e) { reply({ ok: false, message: e.message || String(e) }); }
  })();
  return true;
});

async function save(item, sup, page) {
  const r = await api("price", { id: item.id, supplier: sup, price: page.price, pack: page.pack || "", url: page.url, sku: page.sku || "", product: page.title || "" });
  const seenKey = "seen:" + item.id + ":" + sup;
  await set({ [seenKey]: { price: page.price, at: Date.now() } });
  const { log = [] } = await get(["log"]);
  log.unshift({ at: Date.now(), name: item.name, sup, price: page.price, change: r.change ?? null });
  await set({ log: log.slice(0, 25) });
  return { price: page.price, change: r.change ?? null, previous: r.previous || null };
}
