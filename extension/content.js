/* Supply Room Price Helper: reads the product page the person has open (their own signed-in view) for its item
   numbers and price, asks the Supply Room whether it's one of the office's products, and saves the price.
   It never clicks, submits or changes anything on the supplier's site. */
(() => {
  if (window.__tdcPriceHelper) return; window.__tdcPriceHelper = true;
  const money = s => { const m = String(s || "").replace(/,/g, "").match(/\$\s*(\d+(?:\.\d{1,2})?)/) || String(s || "").match(/^\s*(\d+(?:\.\d{1,2}))\s*$/); return m ? parseFloat(m[1]) : null; };
  const text = el => (el?.innerText || el?.textContent || "").replace(/\s+/g, " ").trim();
  const visible = el => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== "hidden"; };

  /* querySelectorAll that also looks inside open shadow roots (Henry Schein draws its prices inside web components) */
  function deepAll(sel, root = document, out = [], depth = 0) {
    out.push(...root.querySelectorAll(sel));
    if (depth < 6) for (const e of root.querySelectorAll("*")) if (e.shadowRoot) deepAll(sel, e.shadowRoot, out, depth + 1);
    return out;
  }
  function readPage() {
    const page = { url: location.href.split("#")[0], title: "", price: null, pack: "", sku: "", codes: [], listPrice: null, accountPrice: false, signedIn: null };
    // 1. structured product data (schema.org JSON-LD and microdata)
    for (const s of document.querySelectorAll('script[type="application/ld+json"]')) {
      let j; try { j = JSON.parse(s.textContent); } catch { continue; }
      const list = [].concat(j["@graph"] || j).flat();
      for (const p of list) {
        if (!p || !/Product/i.test([].concat(p["@type"]).join(" "))) continue;
        page.title ||= p.name || "";
        for (const c of [p.sku, p.mpn, p.productID, p.gtin12, p.gtin13]) if (c) page.codes.push(String(c));
        const o = [].concat(p.offers || []).flat()[0];
        const pr = o && (o.price ?? o.lowPrice ?? o.priceSpecification?.price);
        if (pr != null && !page.price) page.price = parseFloat(pr) || null;
      }
    }
    document.querySelectorAll('[itemprop="sku"],[itemprop="mpn"],[itemprop="productID"]').forEach(e => page.codes.push(e.getAttribute("content") || text(e)));
    const mp = document.querySelector('[itemprop="price"]'); if (mp && !page.price) page.price = parseFloat(mp.getAttribute("content")) || money(text(mp));
    const meta = document.querySelector('meta[property="product:price:amount"],meta[property="og:price:amount"]'); if (meta && !page.price) page.price = parseFloat(meta.content) || null;
    page.title ||= text(document.querySelector("h1")) || document.title;

    // 2. item numbers shown on the page ("Item #", "Mfr #", "Catalog No.") and in the address (Henry Schein: /p/x/1234567)
    const body = text(document.body).slice(0, 60000);
    for (const m of body.matchAll(/\b(?:item|sku|catalog|cat\.?|mfr\.?|manufacturer|part|product|model|ref)\s*(?:#|no\.?|number|code)?\s*[:#]?\s*([A-Z0-9][A-Z0-9\-\.\/]{2,24})/gi)) page.codes.push(m[1]);
    const u = location.pathname.match(/\/(\d{6,8})(?:[\/?]|$)/); if (u) page.codes.push(u[1]);
    page.sku = page.codes[0] || "";

    // 3. the signed-in price: a price labelled "Your price"/"Sale"/"Net" wins over a list price
    // Henry Schein: the account price and the struck-through list price sit in .product-unit-price inside a web component
    const hs = deepAll(".product-unit-price").find(visible);
    if (hs) {
      const own = [...hs.querySelectorAll(".display-price")].filter(e => !e.closest(".original-price") && visible(e))[0];
      const lst = [...hs.querySelectorAll(".original-price .display-price")].find(visible);
      const v = own && money("$" + text(own)), l = lst && money("$" + text(lst));
      if (v) { page.price = v; page.accountPrice = true; if (l && l > v + 0.004) page.listPrice = l; }
    }
    // Henry Schein's product data carries its catalog (list) price; the account price appears only once the page has drawn it
    if (/(^|\.)henryschein\.com$/.test(location.hostname) && !(hs && page.accountPrice)) page.price = null;
    const labelled = deepAll('[class*="price" i],[id*="price" i],[data-testid*="price" i]').filter(visible)
      .map(el => ({ el, t: text(el) })).filter(x => x.t && x.t.length < 160 && money(x.t) != null);
    const pick = labelled.find(x => /your price|sale|net price|special|contract|our price/i.test(x.t + " " + (x.el.className || "")))
      || labelled.find(x => !/list|msrp|was|retail|reg(ular)?/i.test(x.t + " " + (x.el.className || "")));
    if (pick && !hs && !/(^|\.)henryschein\.com$/.test(location.hostname)) { const v = money(pick.t); if (v && (!page.price || /your price|sale|net|special|contract/i.test(pick.t))) page.price = v; }
    if (pick && !hs && /your price|net price|special|contract|our price/i.test(pick.t + " " + (pick.el.className || ""))) page.accountPrice = true;
    const lst = labelled.find(x => x !== pick && /list|msrp|was|retail|reg(ular)?/i.test(x.t + " " + (x.el.className || "")));
    if (lst && !page.listPrice) { const v = money(lst.t); if (v && page.price && v > page.price + 0.004) page.listPrice = v; }
    page.signedIn = signedIn(body);
    if (hs && page.price) page.signedIn = true;   // Henry Schein shows a price only to a signed-in account
    const pk = body.match(/\b(\d+\s*(?:\/|per)\s*(?:box|bx|pk|pack|case|cs|bag|bg|ea|each|bottle|btl|tube|kit|roll|syringe|jar|vial))\b/i) || body.match(/\b((?:box|pack|case|bag|package) of \d+)\b/i);
    if (pk) page.pack = pk[1];
    page.codes = [...new Set(page.codes.map(c => String(c).trim()).filter(Boolean))].slice(0, 40);
    return page;
  }

  /* Is the person signed in to this store? Signed-out pages show the list price, not the account's special price.
     true: a sign-out link or a "Hi, name" greeting is showing; false: a sign-in link (or "sign in to see your price") with no sign-out; null: can't tell. */
  function signedIn(body) {
    const links = [...document.querySelectorAll('a,button,[role="button"],[role="menuitem"]')].filter(visible).map(e => text(e).toLowerCase()).filter(t => t && t.length < 40);
    const out = links.some(t => /^(sign|log)\s?(out|off)\b|^(hi|hello|welcome),?\s+\w/.test(t));
    const inn = links.some(t => /^(sign|log)\s?in\b|^login\b|\b(account|member|customer) (log|sign) ?in\b|^sign in\s*(\/|or)\s*(register|create)/.test(t)) || /\b(sign|log)\s?in (to|for) (see|view|get)? ?(your )?(price|pricing)\b|login for pricing/i.test(body);
    if (out && !/\b(sign|log)\s?in (to|for) (see|view)? ?(your )?(price|pricing)\b/i.test(body)) return true;
    if (inn) return false;
    return null;
  }
  /* a small note in the corner: which Supply Room product this is, and the price saved */
  let host = null;
  function note(html, buttons) {
    if (!host) { host = document.createElement("div"); host.style.cssText = "position:fixed;right:16px;bottom:16px;z-index:2147483647"; document.documentElement.appendChild(host); host.attachShadow({ mode: "open" }); }
    host.shadowRoot.innerHTML = `<style>
      .b{font:13px/1.35 system-ui,-apple-system,"Segoe UI",sans-serif;background:#1f2d33;color:#fff;border-radius:12px;padding:10px 12px;box-shadow:0 6px 24px rgba(0,0,0,.25);max-width:320px;display:flex;gap:10px;align-items:flex-start}
      .d{width:10px;height:10px;border-radius:50%;background:#4caf50;flex:none;margin-top:4px}.d.w{background:#f0a020}
      b{font-weight:600}small{opacity:.75;display:block}button{font:inherit;border:0;border-radius:8px;padding:4px 10px;margin:6px 6px 0 0;cursor:pointer;background:#e5e9d8;color:#1f2d33}
      button.x{background:none;color:#fff;opacity:.7;padding:0 2px;margin:0 0 0 auto;font-size:16px;line-height:1}</style>
      <div class="b">${html}<button class="x" title="Close">×</button></div>`;
    host.shadowRoot.querySelector(".x").onclick = () => host.remove();
    (buttons || []).forEach(([sel, fn]) => { const b = host.shadowRoot.querySelector(sel); if (b) b.onclick = fn; });
  }
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[c]);
  const fmt = v => "$" + Number(v).toFixed(2);

  let last = "";
  function check() {
    const page = readPage();
    const sig = page.url + "|" + page.price + "|" + page.codes.join(",");
    if (sig === last) return; last = sig;
    if (!page.price && !page.codes.length) return;
    chrome.runtime.sendMessage({ type: "page", page }, r => {
      if (chrome.runtime.lastError || !r) return;
      if (!r.ok) { if (/paired/i.test(r.message || "")) return; return note(`<span class="d w"></span><div><b>Supply Room</b><small>${esc(r.message)}</small></div>`); }
      if (!r.item) return;
      const what = `<b>${esc(r.item.name)}</b><small>${esc(r.supplier)} · matched by ${esc(r.how)}</small>`;
      if (r.signedOut) {
        note(`<span class="d w"></span><div>${what}<small>You're not signed in to ${esc(r.supplier)}, so this page shows the list price (${fmt(page.price)}). Sign in to save your account price; nothing was saved.</small></div>`);
      } else if (r.saved && r.saved.kept) {
        note(`<span class="d"></span><div>${what}<small>Special price ${fmt(r.saved.special)} kept. This page shows the list price ${fmt(page.price)}: sign in to see your account price.</small></div>`);
      } else if (r.saved) {
        const ch = r.saved.change; const chg = ch == null ? "" : ch === 0 ? " · unchanged" : ` · ${ch > 0 ? "up" : "down"} ${fmt(Math.abs(ch))}`;
        note(`<span class="d"></span><div>${what}<small>Saved ${fmt(r.saved.price)}${r.saved.web ? " as the website price" : ""}${esc(page.pack ? " / " + page.pack : "")}${page.listPrice ? ` <b>SP</b> · list ${fmt(page.listPrice)}` : ""}${esc(chg)}</small></div>`);
      } else if (r.already) {
        note(`<span class="d"></span><div>${what}<small>${fmt(page.price)} already saved</small></div>`);
        setTimeout(() => host && host.remove(), 4000);
      } else if (page.price) {
        note(`<span class="d w"></span><div>${what}<small>Price on this page: ${fmt(page.price)}${r.unsure ? ". Couldn't tell whether you're signed in: save only if this is your account price." : ""}</small><button class="s">Save to Supply Room</button></div>`,
          [[".s", () => chrome.runtime.sendMessage({ type: "save", id: r.item.id, page: Object.assign({}, page, { signedIn: page.signedIn === false ? false : true }) }, x => note(x && x.ok && x.saved?.kept ? `<span class="d"></span><div>${what}<small>Special price ${fmt(x.saved.special)} kept; ${fmt(page.price)} is the list price.</small></div>` : x && x.ok ? `<span class="d"></span><div>${what}<small>Saved ${fmt(page.price)}</small></div>` : `<span class="d w"></span><div><b>Not saved</b><small>${esc(x?.message || "")}</small></div>`))]]);
      } else note(`<span class="d w"></span><div>${what}<small>No price shown on this page (signed in?)</small></div>`);
    });
  }
  // product pages often fill in the price after loading, and some sites change page without reloading
  // (pages that never stop changing, such as Henry Schein's, still get checked at least every 3 seconds)
  let t = null, since = 0; const soon = () => { clearTimeout(t); if (!since) since = Date.now(); t = setTimeout(() => { since = 0; check(); }, Date.now() - since > 3000 ? 0 : 900); };
  new MutationObserver(soon).observe(document.documentElement, { childList: true, subtree: true, characterData: true });
  addEventListener("popstate", soon); soon();
})();
