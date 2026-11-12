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
        const sw = sender.tab && sweepWaiters.get(sender.tab.id);
        if (!m.item && sw && msg.page.price) sw.done({ nomatch: true });
        if (!m.item) return reply({ ok: true, item: null, count: m.count });
        const res = { ok: true, item: { id: m.item.id, name: m.item.name, price: m.item.price, units: m.item.units }, how: m.how, supplier: sup, auto: auto || !!sw };
        // save once per product, supplier and price (a changed price saves again)
        const seenKey = "seen:" + m.item.id + ":" + sup;
        const seen = (await get([seenKey]))[seenKey];
        if (msg.page.price && seen && Math.abs(seen.price - msg.page.price) < 0.005 && Date.now() - seen.at < 7 * 86400e3) res.already = seen;
        else if (msg.page.price && msg.page.signedIn === false) res.signedOut = true;    // a signed-out page shows the list price: never saved
        else if (msg.page.price && (auto || sw) && (msg.page.signedIn === true || msg.page.accountPrice)) res.saved = await save(m.item, sup, msg.page);
        else if (msg.page.price) res.unsure = msg.page.signedIn == null;
        if (sw) { if (res.saved || res.already || res.signedOut || res.unsure) sw.done(res); else if (msg.page.signedIn === false) sw.signedOutSeen = true; }
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
        const s = await get(["site", "key", "auto", "paused", "log", "sweep"]);
        let targets = 0; if (s.site && s.key) { try { targets = sweepTargets(await getIndex(), "henryschein.com").length; } catch {} }
        const sweep = s.sweep || null; if (sweep && sweep.running && !sweeping) { sweep.running = false; sweep.why = sweep.why || "Chrome paused the extension partway. Press Resume to carry on."; sweep.resumable = true; await set({ sweep }); }
        return reply({ ok: true, paired: !!(s.site && s.key), site: s.site || "", auto: s.auto !== false, paused: !!s.paused, log: s.log || [], sweep, targets });
      }
      if (msg.type === "sweep") { if (!sweeping) runSweep("henryschein.com", msg.resume ? ((await get(["sweep"])).sweep?.done || 0) : 0); return reply({ ok: true }); }
      if (msg.type === "sweepStop") { sweepStop = true; return reply({ ok: true }); }
      if (msg.type === "settings") { await set(msg.values || {}); return reply({ ok: true }); }
      if (msg.type === "unpair") { await chrome.storage.local.clear(); index = null; return reply({ ok: true }); }
      if (msg.type === "refresh") { await getIndex(true); return reply({ ok: true, items: index.items.length }); }
      reply({ ok: false, message: "Unknown request" });
    } catch (e) { reply({ ok: false, message: e.message || String(e) }); }
  })();
  return true;
});

async function save(item, sup, page) {
  const r = await api("price", { id: item.id, supplier: sup, price: page.price, pack: page.pack || "", url: page.url, sku: page.sku || "", product: page.title || "",
    signedIn: page.signedIn === true || !!page.accountPrice, listPrice: page.listPrice || null });
  const seenKey = "seen:" + item.id + ":" + sup;
  if (r.kept) { await set({ [seenKey]: { price: page.price, at: Date.now() } }); return { kept: true, special: r.special, listPrice: r.listPrice }; }
  await set({ [seenKey]: { price: page.price, at: Date.now() } });
  const { log = [] } = await get(["log"]);
  log.unshift({ at: Date.now(), name: item.name, sup, price: page.price, change: r.change ?? null });
  await set({ log: log.slice(0, 25) });
  return { price: page.price, change: r.change ?? null, previous: r.previous || null };
}

/* "Check all Henry Schein prices": opens each Supply Room product's Henry Schein page, one at a time, in one
   background tab, waits for the account price to show, saves it the same way as when a person opens the page,
   then moves on after a short pause. It reads only; it never clicks anything on the site. Signed out, it stops. */
let sweeping = false, sweepStop = false;
const sweepWaiters = new Map();
const sleep = ms => new Promise(r => setTimeout(r, ms));
function sweepTargets(ix, h) {
  const out = [], seen = new Set();
  for (const it of ix.items) {
    let u = it.urls.find(x => x.toLowerCase().startsWith(h) || x.toLowerCase().startsWith("www." + h));
    if (u) u = "https://" + (u.startsWith("www.") ? u : "www." + u);
    else if (/schein/i.test(it.supplier || "")) { const c = it.codes.find(x => /^\d{6,8}$/.test(String(x).trim())); if (c) u = "https://www.henryschein.com/en-us/p/x/" + String(c).trim(); }
    if (u && !seen.has(it.id)) { seen.add(it.id); out.push({ id: it.id, name: it.name, url: u }); }
  }
  return out;
}
async function runSweep(h, from = 0) {
  sweeping = true; sweepStop = false;
  let tab = null; const keep = setInterval(() => chrome.runtime.getPlatformInfo(() => {}), 20000);   // keeps Chrome from pausing the extension mid-run
  try {
    const list = sweepTargets(await getIndex(true), h);
    const prev = from ? (await get(["sweep"])).sweep || {} : {};
    const st = { running: true, host: h, total: list.length, done: from, saved: from ? prev.saved || 0 : 0, same: from ? prev.same || 0 : 0, kept: from ? prev.kept || 0 : 0,
                 missed: from ? prev.missed || [] : [], startedAt: from ? prev.startedAt : Date.now(), why: "" };
    await set({ sweep: st });
    tab = await chrome.tabs.create({ url: "about:blank", active: false });
    for (let i = from; i < list.length; i++) {
      if (sweepStop) { st.why = "Stopped."; st.resumable = true; break; }
      const t = list[i];
      const res = await new Promise(resolve => {
        const w = { signedOutSeen: false, done: r => { sweepWaiters.delete(tab.id); clearTimeout(w.t1); clearTimeout(w.t2); resolve(r); } };
        sweepWaiters.set(tab.id, w);
        w.t1 = setTimeout(() => { if (w.signedOutSeen) w.done({ signedOut: true }); }, 15000);
        w.t2 = setTimeout(() => w.done({ timeout: true }), 30000);
        chrome.tabs.update(tab.id, { url: t.url }).catch(() => w.done({ gone: true }));
      });
      if (res.gone) { st.why = "The background tab was closed."; st.resumable = true; break; }
      if (res.signedOut) { st.why = "You're not signed in to Henry Schein. Sign in, then press Resume."; st.resumable = true; break; }
      st.done = i + 1;
      if (res.saved && res.saved.kept) st.kept++; else if (res.saved) st.saved++; else if (res.already) st.same++;
      else st.missed.push({ name: t.name, url: t.url, why: res.nomatch ? "page didn't match the product" : res.unsure ? "couldn't tell if signed in" : "no price shown" });
      st.missed = st.missed.slice(-60);
      await set({ sweep: st });
      await sleep(3000 + Math.random() * 2000);   // a person's pace, not a crawler's
    }
    st.running = false; if (!st.why) { st.why = ""; st.resumable = false; st.finishedAt = Date.now(); }
    await set({ sweep: st });
  } catch (e) {
    const { sweep } = await get(["sweep"]); await set({ sweep: { ...(sweep || {}), running: false, why: e.message || String(e), resumable: true } });
  } finally {
    sweeping = false; clearInterval(keep); if (tab) chrome.tabs.remove(tab.id).catch(() => {});
  }
}
