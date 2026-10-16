/* Tests du relais — `node --test` dans relay/, sans dépendance.
   Brevo est simulé : chaque appel est consigné et l'état des contacts tenu
   en mémoire, ce qui permet de vérifier ce que le relais écrit vraiment. */
import { test, beforeEach } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import worker, { SERVICES, CERTS, ROLES, QUALIFY, TUTO, ATTRIBUTES, _resetForTests } from "./worker.js";

const ORIGIN = "https://clubcatalist.cmon-sports.com";
const ENV = { BREVO_API_KEY: "test-key", BREVO_API_BASE: "https://brevo.test/v3", NOTIFY_TO: "sales@example.com" };

function fakeBrevo({ contacts = {}, attributes = ATTRIBUTES.map(([n]) => n), failSmtp = false, failEvents = false, getStatus } = {}) {
  const calls = [];
  globalThis.fetch = async (url, init) => {
    const path = new URL(url).pathname.replace(/^\/v3/, "");
    const method = init.method;
    const body = init.body ? JSON.parse(init.body) : undefined;
    calls.push({ method, path, body, key: init.headers["api-key"] });
    const res = (status, b) => new Response(b === undefined ? null : JSON.stringify(b), { status });
    if (path === "/contacts/attributes" && method === "GET") return res(200, { attributes: attributes.map(name => ({ name })) });
    if (path.startsWith("/contacts/attributes/normal/")) { attributes.push(path.split("/").pop()); return res(201, {}); }
    if (path.startsWith("/contacts/lists/")) return res(200, { id: 63, name: "CMON_SOLUTION_CLUBCATALIST" });
    const m = path.match(/^\/contacts\/(.+)$/);
    if (m && method === "GET") {
      if (getStatus) return res(getStatus, {});
      const c = contacts[decodeURIComponent(m[1])];
      return c ? res(200, c) : res(404, { code: "document_not_found" });
    }
    if (path === "/contacts" && method === "POST") {
      if (contacts[body.email]) return res(400, { code: "duplicate_parameter" });
      contacts[body.email] = { email: body.email, emailBlacklisted: body.emailBlacklisted,
                               attributes: { ...body.attributes }, listIds: body.listIds };
      return res(201, { id: 1 });
    }
    if (m && method === "PUT") {
      const c = contacts[decodeURIComponent(m[1])];
      Object.assign(c.attributes, body.attributes);
      c.listIds = [...new Set([...(c.listIds || []), ...body.listIds])];
      return res(204);
    }
    if (path === "/events") return failEvents ? res(400, {}) : res(204);
    if (path === "/smtp/email") return failSmtp ? res(400, {}) : res(201, { messageId: "m" });
    return res(500, {});
  };
  return { calls, contacts };
}

const post = (body, { origin = ORIGIN, env = ENV, raw } = {}) => worker.fetch(new Request("https://relay.test/sync", {
  method: "POST", headers: { Origin: origin, "content-type": "application/json" }, body: raw ?? JSON.stringify(body),
}), env);
const writes = calls => calls.filter(c => c.method !== "GET");

beforeEach(() => _resetForTests());

test("CORS : seule l'origine du jeu est servie", async () => {
  fakeBrevo();
  const pre = await worker.fetch(new Request("https://relay.test/sync", { method: "OPTIONS", headers: { Origin: ORIGIN } }), ENV);
  assert.equal(pre.status, 204);
  assert.equal(pre.headers.get("access-control-allow-origin"), ORIGIN);
  const preKo = await worker.fetch(new Request("https://relay.test/sync", { method: "OPTIONS", headers: { Origin: "https://evil.test" } }), ENV);
  assert.equal(preKo.status, 403);
  const r = await post({ email: "a@b.ch" }, { origin: "https://evil.test" });
  assert.equal(r.status, 403);
});

test("nouveau joueur sans opt-in : créé dans la liste 63, exclu des campagnes", async () => {
  const b = fakeBrevo();
  const r = await post({ email: "Joueur@Club.CH", pseudo: "joueur", optIn: false, org: "HC Test",
                         role: "Marketing & communication", sport: 4, lang: "de" });
  assert.equal(r.status, 200);
  assert.deepEqual(await r.json(), { ok: true });
  const c = b.contacts["joueur@club.ch"];
  assert.equal(c.emailBlacklisted, true);
  assert.deepEqual(c.listIds, [63]);
  assert.equal(c.attributes.OPT_IN, false);
  assert.equal(c.attributes.CMON_SOLUTION_CLUBCATALIST, true);
  assert.equal(c.attributes.COMPANY, "HC Test");
  assert.equal(c.attributes.JOB_TITLE, "Marketing & communication");
  assert.equal(c.attributes.SPORT, 4);
  assert.equal(c.attributes.CC_LANG, "de");
  assert.ok(b.calls.every(x => x.key === "test-key"));
});

test("nouveau joueur avec opt-in : pas sur liste noire", async () => {
  const b = fakeBrevo();
  await post({ email: "fan@club.ch", optIn: true });
  assert.equal(b.contacts["fan@club.ch"].emailBlacklisted, false);
  assert.equal(b.contacts["fan@club.ch"].attributes.OPT_IN, true);
});

test("contact existant : jamais mis sur liste noire, champs partagés préservés", async () => {
  const b = fakeBrevo({ contacts: { "client@club.ch": {
    email: "client@club.ch", emailBlacklisted: false, listIds: [24, 34],
    attributes: { OPT_IN: true, COMPANY: "Vraie Société", SPORT: "2", PRENOM: "Anne" } } } });
  const r = await post({ email: "client@club.ch", optIn: false, org: "Autre chose", role: "Autre", sport: 4 });
  assert.equal(r.status, 200);
  const put = writes(b.calls).find(c => c.method === "PUT");
  assert.ok(put, "mise à jour attendue");
  assert.equal("emailBlacklisted" in put.body, false);
  assert.equal("OPT_IN" in put.body.attributes, false);
  assert.equal("COMPANY" in put.body.attributes, false);
  assert.equal("SPORT" in put.body.attributes, false);
  assert.equal(put.body.attributes.JOB_TITLE, "Autre");          // vide auparavant : complété
  const c = b.contacts["client@club.ch"];
  assert.equal(c.emailBlacklisted, false);
  assert.equal(c.attributes.OPT_IN, true);
  assert.equal(c.attributes.COMPANY, "Vraie Société");
  assert.deepEqual(c.listIds, [24, 34, 63]);
  assert.equal(writes(b.calls).some(x => x.path === "/contacts" && x.method === "POST"), false);
});

test("contact existant qui consent : OPT_IN passe à vrai, liste noire inchangée", async () => {
  const b = fakeBrevo({ contacts: { "x@y.ch": { email: "x@y.ch", emailBlacklisted: true, attributes: { OPT_IN: false } } } });
  await post({ email: "x@y.ch", optIn: true });
  const put = writes(b.calls).find(c => c.method === "PUT");
  assert.equal(put.body.attributes.OPT_IN, true);
  assert.equal("emailBlacklisted" in put.body, false);
});

test("la réponse ne révèle pas si l'adresse existait", async () => {
  fakeBrevo({ contacts: { "connu@x.ch": { email: "connu@x.ch", attributes: {} } } });
  const a = await (await post({ email: "connu@x.ch" })).text();
  const b = await (await post({ email: "inconnu@x.ch" })).text();
  assert.equal(a, b);
});

test("signaux d'usage et questionnaire transmis", async () => {
  const b = fakeBrevo();
  await post({ email: "p@x.ch", tuto: "Terminé",
    q: { CC_MATURITY: "Base peu exploitée", CC_PRIORITY: "Structuration marketing digital", CC_TIMING: "Pas maintenant" },
    cc: { seasons: 2, services: "Audit DMOS, Data Factory", cert: "Gold", lastPlayed: "2026-10-15" } });
  const a = b.contacts["p@x.ch"].attributes;
  assert.equal(a.CC_SEASONS, 2);
  assert.equal(a.CC_SERVICES, "Audit DMOS, Data Factory");
  assert.equal(a.CC_CERT, "Gold");
  assert.equal(a.CC_LAST_PLAYED, "2026-10-15");
  assert.equal(a.CC_TUTO, "Terminé");
  assert.equal(a.CC_MATURITY, "Base peu exploitée");
  assert.equal(a.CC_TIMING, "Pas maintenant");
  assert.equal(a.CC_HOT_AT, undefined);
  assert.equal(b.calls.some(c => c.path === "/smtp/email"), false);
});

test("événements : les valides partent vers Brevo, les autres sont ignorés", async () => {
  const b = fakeBrevo();
  const at = new Date(Date.now() - 3600e3).toISOString();
  const r = await post({ email: "e@x.ch", events: [
    { name: "club_catalist_service_tried", props: { service: "Data Factory" }, at },
    { name: "club_catalist_service_tried", props: { service: "Inventé" } },
    { name: "club_catalist_season_completed", props: { seasons: 1, cert: "Silver" }, at: "2001-01-01T00:00:00Z" },
    { name: "club_catalist_questionnaire", props: { timing: "Budget prévu", priority: "Conformité RGPD / nLPD" } },
    { name: "autre_chose", props: {} },
  ] });
  assert.equal(r.status, 200);
  const ev = b.calls.filter(c => c.path === "/events");
  assert.equal(ev.length, 3);
  assert.deepEqual(ev[0].body, { event_name: "club_catalist_service_tried", identifiers: { email_id: "e@x.ch" },
                                 event_properties: { service: "Data Factory" }, event_date: at });
  assert.equal("event_date" in ev[1].body, false, "date trop ancienne : omise");
  assert.deepEqual(ev[2].body.event_properties, { priority: "Conformité RGPD / nLPD", timing: "Budget prévu", hot: true });
});

test("un événement refusé par Brevo ne fait pas échouer la synchronisation", async () => {
  fakeBrevo({ failEvents: true });
  const r = await post({ email: "e@x.ch", events: [{ name: "club_catalist_service_tried", props: { service: "Fan Lab Pro" } }] });
  assert.equal(r.status, 200);
});

test("appel chaud : une seule alerte par contact", async () => {
  const b = fakeBrevo();
  const body = { email: "chaud@club.ch", org: "FC <Test>", q: { CC_TIMING: "Budget prévu" } };
  await post(body);
  const mails = b.calls.filter(c => c.path === "/smtp/email");
  assert.equal(mails.length, 1);
  assert.deepEqual(mails[0].body.to, [{ email: "sales@example.com" }]);
  assert.deepEqual(mails[0].body.replyTo, { email: "chaud@club.ch" });
  assert.match(mails[0].body.htmlContent, /FC &lt;Test&gt;/);
  assert.match(b.contacts["chaud@club.ch"].attributes.CC_HOT_AT, /^\d{4}-\d{2}-\d{2}$/);
  await post(body);
  assert.equal(b.calls.filter(c => c.path === "/smtp/email").length, 1);
});

test("appel chaud : si l'alerte échoue, elle sera retentée", async () => {
  const b = fakeBrevo({ failSmtp: true });
  const r = await post({ email: "chaud@club.ch", q: { CC_TIMING: "À l'étude" } });
  assert.equal(r.status, 200);
  assert.equal(b.contacts["chaud@club.ch"].attributes.CC_HOT_AT, undefined);
});

test("validation : adresse, service et taille", async () => {
  fakeBrevo();
  assert.equal((await post({ email: "pas-une-adresse" })).status, 400);
  assert.equal((await post({ email: "a@b.ch", cc: { seasons: 1, services: "Audit DMOS, Piratage" } })).status, 400);
  assert.equal((await post({ email: "a@b.ch", role: "Pirate" })).status, 400);
  assert.equal((await post({ email: "a@b.ch", sport: 99 })).status, 400);
  assert.equal((await post(null, { raw: "{pas du json" })).status, 400);
  assert.equal((await post(null, { raw: JSON.stringify({ email: "a@b.ch", pseudo: "x".repeat(20000) }) })).status, 413);
});

test("erreur Brevo : 502, sans écriture", async () => {
  const b = fakeBrevo({ getStatus: 401 });
  const r = await post({ email: "a@b.ch" });
  assert.equal(r.status, 502);
  assert.deepEqual(await r.json(), { ok: false, error: "brevo", status: 401 });
  assert.equal(writes(b.calls).length, 0);
});

test("attributs manquants créés une seule fois", async () => {
  const b = fakeBrevo({ attributes: ["PSEUDO"] });
  await post({ email: "a@b.ch" });
  await post({ email: "c@d.ch" });
  const created = b.calls.filter(c => c.path.startsWith("/contacts/attributes/normal/")).map(c => c.path.split("/").pop());
  assert.deepEqual(created.sort(), ATTRIBUTES.map(([n]) => n).filter(n => n !== "PSEUDO").sort());
});

test("santé : liste et attributs", async () => {
  fakeBrevo();
  const r = await worker.fetch(new Request("https://relay.test/health", { headers: { Origin: ORIGIN } }), ENV);
  assert.deepEqual(await r.json(), { ok: true, brevo: "ok", listId: 63, list: true,
                                     expected: ATTRIBUTES.length, missing: [], notify: true });
});

test("limite de débit respectée", async () => {
  fakeBrevo();
  const env = { ...ENV, RATE_LIMITER: { limit: async () => ({ success: false }) } };
  assert.equal((await post({ email: "a@b.ch" }, { env })).status, 429);
});

/* Les valeurs canoniques doivent rester celles du jeu : un libellé changé
   d'un seul côté ferait rejeter tous les joueurs concernés. */
test("valeurs canoniques identiques à index.html", () => {
  const html = readFileSync(new URL("../index.html", import.meta.url), "utf8");
  const block = name => html.match(new RegExp(`const ${name} = ([\\[{][\\s\\S]*?\\n?[\\]}]);`))[1];
  const services = [...block("CRM_SERVICES").matchAll(/:\s*"([^"]+)"/g)].map(m => m[1]);
  assert.deepEqual(services.sort(), [...SERVICES].sort());
  const certs = [...block("CRM_CERT").matchAll(/:\s*"([^"]+)"/g)].map(m => m[1]);
  assert.ok(html.includes(`: "Non audité"`));
  assert.deepEqual([...certs, "Non audité"].sort(), [...CERTS].sort());
  const qualify = html.slice(html.indexOf("const QUALIFY = ["), html.indexOf("];", html.indexOf("const QUALIFY = [")));
  for (const part of qualify.split(/\{ attr: /).slice(1)) {
    const attr = part.match(/^"([A-Z_]+)"/)[1];
    const opts = [...part.matchAll(/\["([^"]+)", `/g)].map(m => m[1]);
    assert.deepEqual(opts, QUALIFY[attr], attr);
  }
  const roles = [...html.matchAll(/<option value="([^"]+)" data-i18n="ui\.login\.role\./g)].map(m => m[1].replace(/&amp;/g, "&"));
  assert.deepEqual(roles, ROLES);
  const tutos = [...new Set([...html.matchAll(/tutoFinish\("([^"]+)"\)/g)].map(m => m[1]))];
  assert.deepEqual(tutos.sort(), [...TUTO].sort());
});
