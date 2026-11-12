const m = document.getElementById("m");
const send = msg => new Promise(r => chrome.runtime.sendMessage(msg, r));
const esc = s => String(s).replace(/[&<>"]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[c]);
async function show() {
  const s = await send({ type: "status" });
  if (!s.paired) {
    m.innerHTML = `<p>Pair it with your Supply Room once:</p><ol class="hint"><li>Open your Supply Room › Setup › Data collection.</li><li>Under <b>Browser extension</b>, press <b>Turn on</b>, then <b>Copy pairing code</b>.</li><li>Paste it here.</li></ol>
      <textarea id="c" placeholder="Pairing code"></textarea><button id="p">Pair</button><p id="e" class="bad"></p>`;
    document.getElementById("p").onclick = async () => {
      // also let the Supply Room page start price updates (Chrome asks once)
      try { const j = JSON.parse(atob(document.getElementById("c").value.trim())); await chrome.permissions.request({ origins: [new URL(j.u).origin + "/*"] }); } catch {}
      const r = await send({ type: "pair", code: document.getElementById("c").value }); if (r.ok) show(); else document.getElementById("e").textContent = r.message; };
    return;
  }
  m.innerHTML = `<p class="ok">Paired with ${esc(new URL(s.site).hostname)}</p>
    ${s.linked ? "" : `<div class="sw"><b>Connect to the Supply Room page</b><p class="hint">Lets Setup › Suppliers start "Update all prices with browser extension". Chrome asks once.</p><button id="lk">Connect</button></div>`}
    <p class="hint">Open a product page on a supplier's website (signed in for your account prices). When it's one of your Supply Room products, its price is saved and a note shows in the corner.</p>
    <label><input type="checkbox" id="a" ${s.auto ? "checked" : ""}> Save prices automatically</label>
    <label><input type="checkbox" id="z" ${s.paused ? "checked" : ""}> Pause</label>
    <div class="sw" id="sw"></div>
    <div><b>Recently saved</b>${s.log.length ? `<ul>${s.log.map(l => `<li>${esc(l.name)}<br><span class="hint">${esc(l.sup)} · $${Number(l.price).toFixed(2)}${l.change ? ` (${l.change > 0 ? "+" : "−"}$${Math.abs(l.change).toFixed(2)})` : ""} · ${new Date(l.at).toLocaleDateString()}</span></li>`).join("")}</ul>` : `<p class="hint">Nothing yet.</p>`}</div>
    <div><button class="g" id="r">Refresh product list</button> <button class="g" id="u">Unpair</button></div><p id="e" class="hint"></p>`;
  drawSweep(s);
  const lk = document.getElementById("lk"); if (lk) lk.onclick = async () => { const ok = await chrome.permissions.request({ origins: [new URL(s.site).origin + "/*"] }); if (ok) show(); };
  document.getElementById("a").onchange = e => send({ type: "settings", values: { auto: e.target.checked } });
  document.getElementById("z").onchange = e => send({ type: "settings", values: { paused: e.target.checked } });
  document.getElementById("r").onclick = async () => { const r = await send({ type: "refresh" }); document.getElementById("e").textContent = r.ok ? `${r.items} products loaded.` : r.message; };
  document.getElementById("u").onclick = async () => { await send({ type: "unpair" }); show(); };
}
show();

/* Check all Henry Schein prices: the button, then progress while it runs */
function drawSweep(s) {
  const el = document.getElementById("sw"); if (!el) return;
  const w = s.sweep, n = s.targets || 0;
  const tally = w ? `${w.saved} saved · ${w.same} unchanged${w.kept ? ` · ${w.kept} special prices kept` : ""}${w.missed?.length ? ` · ${w.missed.length} without a price` : ""}` : "";
  if (w && w.running) {
    el.innerHTML = `<b>Checking Henry Schein prices</b><div class="bar"><i style="width:${w.total ? Math.round(w.done / w.total * 100) : 0}%"></i></div><p class="hint">${w.done} of ${w.total} · ${tally}. You can keep working; a background tab opens each page.</p><button class="g" id="swStop">Stop</button>`;
    document.getElementById("swStop").onclick = async () => { await send({ type: "sweepStop" }); };
    clearTimeout(drawSweep.t); drawSweep.t = setTimeout(async () => drawSweep(await send({ type: "status" })), 1500);
    return;
  }
  el.innerHTML = `<b>Henry Schein</b><p class="hint">Sign in to henryschein.com in this browser, then check every Henry Schein product's price in one go (${n} products, about ${Math.max(1, Math.round(n * 9 / 60))} minutes).</p>
    ${w ? `<p class="hint">${w.finishedAt ? `Last run ${new Date(w.finishedAt).toLocaleDateString()}: ` : w.why ? `${esc(w.why)} ` : ""}${w.done} of ${w.total} · ${tally}.</p>` : ""}
    ${w && w.missed?.length ? `<details><summary class="hint">Without a price (${w.missed.length})</summary><ul>${w.missed.map(x => `<li><a href="${esc(x.url)}" target="_blank">${esc(x.name)}</a><br><span class="hint">${esc(x.why)}</span></li>`).join("")}</ul></details>` : ""}
    <button id="swGo" ${n ? "" : "disabled"}>Check all ${n} Henry Schein prices</button>${w && w.resumable && w.done < w.total ? ` <button class="g" id="swRes">Resume</button>` : ""}`;
  document.getElementById("swGo").onclick = async () => { await send({ type: "sweep" }); setTimeout(async () => drawSweep(await send({ type: "status" })), 600); };
  const r = document.getElementById("swRes"); if (r) r.onclick = async () => { await send({ type: "sweep", resume: true }); setTimeout(async () => drawSweep(await send({ type: "status" })), 600); };
}
