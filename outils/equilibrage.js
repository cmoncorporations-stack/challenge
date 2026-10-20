/* C'mon Sports Challenge — équilibrage : taux de licenciement par comité,
   club et stratégie (sans service, achat progressif, tout acheter tôt),
   avec un joueur automatique « attentif » (actions choisies selon la
   situation, réserve de trois semaines de charges).

   Usage : npm i playwright && node outils/equilibrage.js [parties]
   (200 parties par combinaison par défaut ; CHROMIUM_PATH si besoin).
   Complète outils/simulation.js (invariants). */
const http = require("http");
const fs = require("fs");
const path = require("path");
const { chromium } = require("playwright");

const ROOT = path.resolve(__dirname, "..");
const N = +(process.argv[2] || 200), SEASONS = 3;
const TYPES = { ".html": "text/html; charset=utf-8", ".js": "text/javascript", ".css": "text/css",
  ".svg": "image/svg+xml", ".webp": "image/webp", ".png": "image/png", ".jpg": "image/jpeg", ".woff2": "font/woff2" };

const server = http.createServer((req, res) => {
  let p = decodeURIComponent(new URL(req.url, "http://x").pathname);
  if (p.endsWith("/")) p += "index.html";
  const f = path.join(ROOT, p);
  if (!f.startsWith(ROOT) || !fs.existsSync(f)) { res.writeHead(404); return res.end(); }
  res.writeHead(200, { "Content-Type": TYPES[path.extname(f)] || "application/octet-stream" });
  fs.createReadStream(f).pipe(res);
});

(async () => {
  await new Promise(ok => server.listen(0, ok));
  const url = `http://localhost:${server.address().port}/jouer/`;
  const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
  const p = await browser.newPage();
  const errors = [];
  p.on("pageerror", e => errors.push(e.message));
  p.on("console", m => { if (m.type() === "error") errors.push(m.text()); });
  await p.addInitScript(() => { try { localStorage.setItem("mktclubgm_lang", "fr"); } catch (e) {} });
  await p.goto(url);
  const res = await p.evaluate(({ N, SEASONS }) => {
    // la simulation ne dessine rien et n'écrit rien
    renderAll = () => {}; save = () => {}; ccTrack = () => {};
    showGameOver = () => {}; showSeasonModal = () => {};
    showDecision = d => { const c = d.choices[Math.floor(Math.random() * d.choices.length)]; c.run(state);
      if (state.boardConfidence <= diff(state).fireAt) state.gameOver = true; };
    const cost = id => actionCost(state, ACTIONS.find(a => a.id === id));
    function pickActions(s) {
      const sel = {}; let ap = 3; const club = clubOf(s);
      const add = id => { const a = ACTIONS.find(x => x.id === id); if (ap < a.ap) return false;
        let spent = Object.keys(sel).reduce((t, k) => t + cost(k) * sel[k], 0);
        const reserve = Math.max(3000, club.budget * 0.08, 3 * (weekCost() + weeklyServicesCost(s)));
        if (s.budget - spent - cost(id) < reserve) return false;
        sel[id] = (sel[id] || 0) + 1; ap -= a.ap; return true; };
      const sat = s.sponsors.length ? s.sponsors.reduce((t, x) => t + x.satisfaction, 0) / s.sponsors.length : 100;
      const dmosObj = s.objectives.find(o => o.id === "dmos");
      if (sat < 64) add("activation");
      if (dmosObj && dmosTotal(s) < dmosObj.target && s.week % 2 === 0) add(s.dmos.conformite < 5 && s.week % 4 === 0 ? "rgpd" : "training");
      if (s.engagement < 40) add("content");
      if (s.contacts > 300 && s.emailFatigue < 2) add("campaign");
      if (penetration(s) < 0.5 && s.week % 2 === 1) add("collect");
      if (s.sponsors.length < 4 && s.week % 4 === 0 && s.budget > club.budget * 0.4) add("prospect");
      for (const id of ["content", "campaign", "collect", "activation"]) { if (ap <= 0) break; add(id); }
      return sel;
    }
    const STRATS = {
      sans: s => {},
      progressive: s => {
        const club = clubOf(s), buffer = club.budget * 0.35;
        if (!serviceActive(s, "audit") && s.budget > 5000 + buffer) buyService("audit");
        else if (s.services.audited && !insightActive(s) && s.budget > 6500 + buffer) buyService("fanlabInsight");
        else if (insightActive(s) && !s.services.fanlabPro && s.budget > 18500 + buffer) buyService("fanlabPro");
        else if (s.services.fanlabPro && !s.services.datafactory && s.budget > club.budget * 0.9) buyService("datafactory");
      },
      toutDeSuite: s => {
        if (s.week >= 2) buyService("fanlabPro");
        if (s.week >= 3) buyService("datafactory");
        if (s.week >= 4) buyService("bluepenguin");
      },
    };
    const out = [];
    for (const d of DIFFICULTIES) for (const c of CLUBS) for (const [sk, strat] of Object.entries(STRATS)) {
      const agg = { diff: d.id, club: c.id, strat: sk, n: N, firedS1: 0, fired: 0, seasons: 0, ok: 0, okN: 0, fevOk: 0, fevN: 0, score: 0, idx: [], neg: 0 };
      for (let r = 0; r < N; r++) {
        newGame(c, d.id); state.tuto.done = true;
        let guard = 0;
        while (!state.gameOver && state.season <= SEASONS && guard++ < 30 * SEASONS) {
          strat(state);
          selected = pickActions(state);
          advanceWeek(); closeModal();
        }
        const h = state.history;
        if (state.gameOver) { agg.fired++; if (h.length === 0 || (h.length === 1 && state.season === 1)) agg.firedS1++; }
        agg.seasons += h.length;
        h.forEach(x => { agg.ok += x.okCount; agg.okN++; x.results.forEach((rr,ri) => { agg.per=agg.per||{}; const k=rr.label.slice(0,14); agg.per[k]=agg.per[k]||[0,0]; agg.per[k][1]++; if(rr.ok) agg.per[k][0]++; if (/Fan Equity Value/.test(rr.label)) { agg.fevN++; if (rr.ok) agg.fevOk++; } }); });
        agg.score += challengeScore(state);
        const i = fanEquityIndex(state); if (i != null) agg.idx.push(i);
        if (state.budget < 0) agg.neg++;
      }
      agg.idx.sort((a, b) => a - b);
      out.push({ diff: d.id, club: c.id, strat: sk,
        licencie: Math.round(agg.fired / N * 100), licencieS1: Math.round(agg.firedS1 / N * 100),
        saisons: +(agg.seasons / N).toFixed(1), objectifs: agg.okN ? +(agg.ok / agg.okN).toFixed(1) : null,
        fevReussi: agg.fevN ? Math.round(agg.fevOk / agg.fevN * 100) : null,
        score: Math.round(agg.score / N), indexMedian: agg.idx.length ? agg.idx[Math.floor(agg.idx.length / 2)] : null,
        budgetNegatif: Math.round(agg.neg / N * 100), per: Object.fromEntries(Object.entries(agg.per||{}).map(([k,v])=>[k,Math.round(v[0]/v[1]*100)])) });
    }
    return out;
  }, { N, SEASONS });
  const NOMS = ["patient", "exigeant", "impitoyable"];
  for (const d of [0, 1, 2]) {
    console.log(`Comité ${NOMS[d]} — % licenciés en ${SEASONS} saisons`);
    console.table(res.filter(r => r.diff === d).map(r => ({ club: r.club, strategie: r.strat, licencies: r.licencie,
      saisonsJouees: r.saisons, objectifs: r.objectifs, objectifFEV: r.fevReussi, score: r.score, indexMedian: r.indexMedian })));
  }
  if (errors.length) console.log("Erreurs console :\n- " + errors.join("\n- "));
  await browser.close(); server.close();
  process.exit(errors.length ? 1 : 0);
})();
