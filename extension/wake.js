/* Supply Room Price Helper: runs only in the background tabs the extension opens itself (marked #tdc-bg).
   Chrome pauses drawing in tabs that aren't in front, and some stores (Henry Schein) only draw the price when the
   page is drawn, so these tabs are told they're visible and get a stand-in for the paused animation frames. */
(() => {
  if (!location.hash.includes("tdc-bg")) return;
  try {
    Object.defineProperty(Document.prototype, "hidden", { configurable: true, get: () => false });
    Object.defineProperty(Document.prototype, "visibilityState", { configurable: true, get: () => "visible" });
    Document.prototype.hasFocus = () => true;
    const raf = window.requestAnimationFrame.bind(window), caf = window.cancelAnimationFrame.bind(window), map = new Map(); let n = 0;
    window.requestAnimationFrame = cb => { const id = ++n; let done = false;
      const run = t => { if (done) return; done = true; map.delete(id); cb(t); };
      const a = raf(run), b = setTimeout(() => run(performance.now()), 50); map.set(id, [a, b]); return id; };
    window.cancelAnimationFrame = id => { const v = map.get(id); if (v) { caf(v[0]); clearTimeout(v[1]); map.delete(id); } };
  } catch (e) {}
})();
