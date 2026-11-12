/* Supply Room Price Helper: lets the Supply Room web page (only the one this extension is paired with) start and
   watch "update all prices" runs. The page posts a request; this forwards a small fixed set of requests to the
   extension and posts the answer back. Nothing else on the page is read or changed. */
(() => {
  if (window.__tdcBridge) return; window.__tdcBridge = true;
  const OK = ["hello", "sweep", "sweepStop", "status", "read"];
  window.addEventListener("message", e => {
    if (e.source !== window || !e.data || e.data.tdcExt !== "req" || !OK.includes(e.data.msg?.type)) return;
    const { id, msg } = e.data;
    try {
      chrome.runtime.sendMessage({ type: msg.type, host: String(msg.host || ""), resume: !!msg.resume, url: String(msg.url || ""), ids: Array.isArray(msg.ids) ? msg.ids.slice(0, 500).map(String) : undefined, fromApp: true }, r =>
        window.postMessage({ tdcExt: "res", id, r: r || { ok: false, message: chrome.runtime.lastError?.message || "The extension didn't answer." } }, location.origin));
    } catch (err) { window.postMessage({ tdcExt: "res", id, r: { ok: false, message: "The extension was updated: reload this page." } }, location.origin); }
  });
  window.postMessage({ tdcExt: "ready", version: chrome.runtime.getManifest().version }, location.origin);
})();
