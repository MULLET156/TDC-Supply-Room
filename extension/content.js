/* Supply Room Price Helper: reads the product page the person has open (their own signed-in view) for its item
   numbers and price, asks the Supply Room whether it's one of the office's products, and saves the price.
   It never clicks, submits or changes anything on the supplier's site. */
(() => {
  if (window.__tdcPriceHelper) return; window.__tdcPriceHelper = true;
  const money = s => { const m = String(s || "").replace(/,/g, "").match(/\$\s*(\d+(?:\.\d{1,2})?)/) || String(s || "").match(/^\s*(\d+(?:\.\d{1,2}))\s*$/); return m ? parseFloat(m[1]) : null; };
  const text = el => (el?.innerText || el?.textContent || "").replace(/\s+/g, " ").trim();
  const visible = el => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== "hidden"; };

  function readPage() {
    const page = { url: location.href.split("#")[0], title: "", price: null, pack: "", sku: "", codes: [] };
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
    const labelled = [...document.querySelectorAll('[class*="price" i],[id*="price" i],[data-testid*="price" i]')].filter(visible)
      .map(el => ({ el, t: text(el) })).filter(x => x.t && x.t.length < 160 && money(x.t) != null);
    const pick = labelled.find(x => /your price|sale|net price|special|contract|our price/i.test(x.t + " " + (x.el.className || "")))
      || labelled.find(x => !/list|msrp|was|retail|reg(ular)?/i.test(x.t + " " + (x.el.className || "")));
    if (pick) { const v = money(pick.t); if (v && (!page.price || /your price|sale|net|special|contract/i.test(pick.t))) page.price = v; }
    const pk = body.match(/\b(\d+\s*(?:\/|per)\s*(?:box|bx|pk|pack|case|cs|bag|bg|ea|each|bottle|btl|tube|kit|roll|syringe|jar|vial))\b/i) || body.match(/\b((?:box|pack|case|bag|package) of \d+)\b/i);
    if (pk) page.pack = pk[1];
    page.codes = [...new Set(page.codes.map(c => String(c).trim()).filter(Boolean))].slice(0, 40);
    return page;
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
      if (r.saved) {
        const ch = r.saved.change; const chg = ch == null ? "" : ch === 0 ? " · unchanged" : ` · ${ch > 0 ? "up" : "down"} ${fmt(Math.abs(ch))}`;
        note(`<span class="d"></span><div>${what}<small>Saved ${fmt(r.saved.price)}${esc(page.pack ? " / " + page.pack : "")}${esc(chg)}</small></div>`);
      } else if (r.already) {
        note(`<span class="d"></span><div>${what}<small>${fmt(page.price)} already saved</small></div>`);
        setTimeout(() => host && host.remove(), 4000);
      } else if (page.price) {
        note(`<span class="d w"></span><div>${what}<small>Price on this page: ${fmt(page.price)}</small><button class="s">Save to Supply Room</button></div>`,
          [[".s", () => chrome.runtime.sendMessage({ type: "save", id: r.item.id, page }, x => note(x && x.ok ? `<span class="d"></span><div>${what}<small>Saved ${fmt(page.price)}</small></div>` : `<span class="d w"></span><div><b>Not saved</b><small>${esc(x?.message || "")}</small></div>`))]]);
      } else note(`<span class="d w"></span><div>${what}<small>No price shown on this page (signed in?)</small></div>`);
    });
  }
  // product pages often fill in the price after loading, and some sites change page without reloading
  let t = null; const soon = () => { clearTimeout(t); t = setTimeout(check, 900); };
  new MutationObserver(soon).observe(document.documentElement, { childList: true, subtree: true, characterData: true });
  addEventListener("popstate", soon); soon();
})();
