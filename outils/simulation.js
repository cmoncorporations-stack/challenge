/* C'mon Sports Challenge — simulation automatique.
   Joue plusieurs saisons avec les quatre clubs, comité « exigeant », selon
   trois stratégies (sans accompagnement, avec Fan Lab Pro, avec Fan Lab Pro
   et Fan Data Factory), puis vérifie quelques invariants :
   aucune erreur console, paliers du fandom = contacts chaque semaine,
   valeur de départ ≥ 10 × le prix de Fan Lab Pro.

   Usage : npm i playwright && node outils/simulation.js [saisons]
   (CHROMIUM_PATH=/chemin/vers/chromium si le navigateur n'est pas installé
   par Playwright). Sortie : un tableau par stratégie, code 1 si un
   invariant échoue. */
const http = require("http");
const fs = require("fs");
const path = require("path");
const { chromium } = require("playwright");

const ROOT = path.resolve(__dirname, "..");
const SEASONS = +(process.argv[2] || 3);
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
  const page = await browser.newPage();
  const errors = [];
  page.on("pageerror", e => errors.push(e.message));
  page.on("console", m => { if (m.type() === "error") errors.push(m.text()); });
  await page.addInitScript(() => { try { localStorage.setItem("mktclubgm_lang", "fr"); } catch (e) {} });
  await page.goto(url);

  const result = await page.evaluate(seasons => {
    const problems = [];
    const proPrice = SERVICES.find(x => x.id === "fanlabPro").setup;
    const start = CLUBS.map(c => {
      newGame(c, 1);
      const fev = fanEquityValue(state);
      if (fev < 10 * proPrice) problems.push(`${c.name} : valeur de départ < 10 × Fan Lab Pro`);
      return { club: c.name, fev: Math.round(fev), engagement: Math.round(state.engagement) };
    });
    const ACTS = ["collect", "content", "campaign", "activation"];
    const run = (pro, factory) => CLUBS.map(c => {
      newGame(c, 1);
      let guard = 0;
      while (!state.gameOver && state.season <= seasons && guard++ < 60 * seasons) {
        if (pro && state.season === 1 && state.week === 2) buyService("fanlabPro");
        if (factory && state.season === 1 && state.week === 3) buyService("datafactory");
        selected = {};
        selected[ACTS[state.week % 4]] = 1;
        selected[ACTS[(state.week + 1) % 4]] = 1;
        if (state.week % 5 === 0) selected.prospect = 1;
        advanceWeek(); closeModal();
        syncTiers(state);
        const gap = Math.abs(tierCounts(state).reduce((a, b) => a + b, 0) - state.contacts);
        if (gap > 1e-6) problems.push(`${c.name} : paliers ≠ contacts (écart ${gap})`);
      }
      return { club: c.name, saisons: state.history.length, licencie: state.gameOver,
        objectifs: state.history.map(h => h.okCount).reverse().join("/"),
        score: challengeScore(state), index: fanEquityIndex(state), engagement: Math.round(state.engagement) };
    });
    return { start, problems,
      sans: run(false, false), pro: run(true, false), proFactory: run(true, true) };
  }, SEASONS);

  console.log("Départ"); console.table(result.start);
  console.log("Sans accompagnement"); console.table(result.sans);
  console.log("Fan Lab Pro"); console.table(result.pro);
  console.log("Fan Lab Pro + Fan Data Factory"); console.table(result.proFactory);
  const problems = result.problems.concat(errors.map(e => "console : " + e));
  console.log(problems.length ? "ÉCHECS :\n- " + problems.join("\n- ") : "Tous les invariants sont respectés.");
  await browser.close(); server.close();
  process.exit(problems.length ? 1 : 0);
})();
