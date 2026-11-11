const m = document.getElementById("m");
const send = msg => new Promise(r => chrome.runtime.sendMessage(msg, r));
const esc = s => String(s).replace(/[&<>"]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[c]);
async function show() {
  const s = await send({ type: "status" });
  if (!s.paired) {
    m.innerHTML = `<p>Pair it with your Supply Room once:</p><ol class="hint"><li>Open your Supply Room › Setup › Data collection.</li><li>Under <b>Browser extension</b>, press <b>Turn on</b>, then <b>Copy pairing code</b>.</li><li>Paste it here.</li></ol>
      <textarea id="c" placeholder="Pairing code"></textarea><button id="p">Pair</button><p id="e" class="bad"></p>`;
    document.getElementById("p").onclick = async () => { const r = await send({ type: "pair", code: document.getElementById("c").value }); if (r.ok) show(); else document.getElementById("e").textContent = r.message; };
    return;
  }
  m.innerHTML = `<p class="ok">Paired with ${esc(new URL(s.site).hostname)}</p>
    <p class="hint">Open a product page on a supplier's website (signed in for your account prices). When it's one of your Supply Room products, its price is saved and a note shows in the corner.</p>
    <label><input type="checkbox" id="a" ${s.auto ? "checked" : ""}> Save prices automatically</label>
    <label><input type="checkbox" id="z" ${s.paused ? "checked" : ""}> Pause</label>
    <div><b>Recently saved</b>${s.log.length ? `<ul>${s.log.map(l => `<li>${esc(l.name)}<br><span class="hint">${esc(l.sup)} · $${Number(l.price).toFixed(2)}${l.change ? ` (${l.change > 0 ? "+" : "−"}$${Math.abs(l.change).toFixed(2)})` : ""} · ${new Date(l.at).toLocaleDateString()}</span></li>`).join("")}</ul>` : `<p class="hint">Nothing yet.</p>`}</div>
    <div><button class="g" id="r">Refresh product list</button> <button class="g" id="u">Unpair</button></div><p id="e" class="hint"></p>`;
  document.getElementById("a").onchange = e => send({ type: "settings", values: { auto: e.target.checked } });
  document.getElementById("z").onchange = e => send({ type: "settings", values: { paused: e.target.checked } });
  document.getElementById("r").onclick = async () => { const r = await send({ type: "refresh" }); document.getElementById("e").textContent = r.ok ? `${r.items} products loaded.` : r.message; };
  document.getElementById("u").onclick = async () => { await send({ type: "unpair" }); show(); };
}
show();
