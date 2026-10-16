/* ═══════════════════════════════════════════════════════════════════════════
   Club Catalist — relais CRM (Cloudflare Worker)

   Le jeu est un fichier statique : toute clé qu'il contiendrait serait
   publique, et une clé Brevo ouvre le CRM entier. Avant ce relais, la clé
   restait donc dans le navigateur de l'administrateur… et aucun joueur
   n'atteignait jamais Brevo, puisque son propre navigateur n'avait pas de
   clé. Le relais garde la clé (secret BREVO_API_KEY) et n'expose qu'une
   opération : inscrire ou mettre à jour un joueur dans la liste Club
   Catalist, avec les seuls attributs du jeu, et transmettre ses
   événements d'usage.

   Trois règles, parce que la liste Club Catalist côtoie le reste du CRM :
   — un contact déjà connu garde son statut d'envoi. Le relais ne touche
     jamais à la liste noire d'un contact existant : le jeu le faisait, et
     un prospect qui jouait sans cocher la case était exclu de TOUTES les
     campagnes C'mon Sports ;
   — les champs partagés (COMPANY, JOB_TITLE, SPORT) ne sont remplis que
     s'ils sont vides, et OPT_IN ne peut que passer à vrai ;
   — la réponse est la même que l'adresse soit connue ou non : le relais
     ne doit pas servir d'annuaire du CRM.
   ═══════════════════════════════════════════════════════════════════════════ */

const DEFAULT_ORIGINS = "https://clubcatalist.cmon-sports.com";
const MAX_BODY = 16 * 1024;
const MAX_EVENTS = 20;
const EVENT_MAX_AGE_MS = 90 * 24 * 3600 * 1000;

/* Valeurs canoniques — miroir de index.html (CRM_SERVICES, CRM_CERT,
   QUALIFY et rôles du formulaire). worker.test.js vérifie qu'elles
   concordent : un écart ferait rejeter les joueurs en HTTP 400. */
export const SERVICES = ["Audit DMOS", "Fan Lab Insight", "Fan Lab Pro", "Data Factory", "Blue Penguin", "Griddy Picks"];
export const CERTS = ["Gold", "Silver", "Audité non certifié", "Non audité"];
export const ROLES = ["Direction générale", "Marketing & communication", "Sponsoring & commercial", "Data & billetterie", "Autre"];
export const QUALIFY = {
  CC_MATURITY: ["Pas de base de données fans", "Base peu exploitée", "CRM et segmentation actifs", "Hors club"],
  CC_PRIORITY: ["Collecte et qualification de données", "Valorisation fanbase / sponsors",
                "Structuration marketing digital", "Conformité RGPD / nLPD", "Aucune, découverte"],
  CC_TIMING: ["Budget prévu", "À l'étude", "Pas maintenant"],
};
export const HOT_TIMING = ["Budget prévu", "À l'étude"];
export const TUTO = ["Terminé", "Passé"];
// Catégorie Brevo SPORT : basket 1, foot 2, hockey 4, volley 6
const SPORTS = { 1: "Basketball", 2: "Football", 4: "Hockey", 6: "Volleyball" };
const LANGS = ["fr", "de", "en"];

/* Attributs propres au jeu, créés au besoin au premier passage. Préfixe
   CC_ pour ne jamais heurter les attributs métier du CRM ; PSEUDO plutôt
   que PRENOM, réservé au vrai prénom ; CC_LANG plutôt que LANGUE, une
   catégorie à valeurs figées où un texte libre vaudrait un HTTP 400 (même
   raison pour ne jamais écrire SOURCE_ACQUISITION). */
export const ATTRIBUTES = [
  ["CMON_SOLUTION_CLUBCATALIST", "boolean"], ["PSEUDO", "text"],
  ["CC_SEASONS", "float"], ["CC_SERVICES", "text"], ["CC_CERT", "text"],
  ["CC_LAST_PLAYED", "date"], ["CC_LANG", "text"],
  ["CC_MATURITY", "text"], ["CC_PRIORITY", "text"], ["CC_TIMING", "text"],
  ["CC_TUTO", "text"],
  // Date de la première alerte « appel chaud » : une seule alerte par contact
  ["CC_HOT_AT", "date"],
];

/* Événements Brevo acceptés, avec leurs propriétés. Ils datent chaque
   geste là où les attributs ne gardent que le dernier état : ce sont eux
   qui déclenchent les workflows (« a essayé Data Factory » → séquence). */
const EVENTS = {
  club_catalist_service_tried: p =>
    SERVICES.includes(p.service) ? { service: p.service } : null,
  club_catalist_season_completed: p =>
    Number.isInteger(p.seasons) && p.seasons > 0 && p.seasons < 1000
      ? Object.assign({ seasons: p.seasons }, CERTS.includes(p.cert) ? { cert: p.cert } : {})
      : null,
  club_catalist_questionnaire: p => {
    const out = {};
    for (const [k, attr] of [["maturity", "CC_MATURITY"], ["priority", "CC_PRIORITY"], ["timing", "CC_TIMING"]]) {
      if (QUALIFY[attr].includes(p[k])) out[k] = p[k];
    }
    if (out.timing) out.hot = HOT_TIMING.includes(out.timing);
    return Object.keys(out).length ? out : null;
  },
};

class Invalid extends Error {}
class BrevoError extends Error {
  constructor(status) { super("brevo " + status); this.status = status; }
}

/* ================= Validation de ce qu'envoie le jeu ================= */

// Texte libre : coupé, et sans caractères de contrôle (il finit dans un objet d'e-mail).
function str(v, max) {
  return typeof v === "string" ? v.replace(/[\u0000-\u001f\u007f]/g, " ").trim().slice(0, max) : "";
}
function oneOf(v, list, field) {
  if (v == null || v === "") return undefined;
  if (!list.includes(v)) throw new Invalid(field);
  return v;
}

function parseEvent(e, now) {
  if (!e || typeof e !== "object" || !Object.hasOwn(EVENTS, e.name)) return null;
  const props = EVENTS[e.name](e.props && typeof e.props === "object" ? e.props : {});
  if (!props) return null;
  const ev = { name: e.name, props };
  const t = typeof e.at === "string" ? Date.parse(e.at) : NaN;
  if (t <= now + 5 * 60 * 1000 && t >= now - EVENT_MAX_AGE_MS) ev.at = new Date(t).toISOString();
  return ev;
}

export function parsePlayer(b, now = Date.now()) {
  if (!b || typeof b !== "object" || Array.isArray(b)) throw new Invalid("body");
  const email = str(b.email, 254).toLowerCase();
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) throw new Invalid("email");
  let sport;
  if (b.sport != null && b.sport !== "") {
    if (!Object.hasOwn(SPORTS, b.sport)) throw new Invalid("sport");
    sport = Number(b.sport);
  }
  const p = {
    email,
    optIn: b.optIn === true,
    pseudo: str(b.pseudo, 40) || email.split("@")[0],
    org: str(b.org, 60),
    role: oneOf(b.role, ROLES, "role"),
    sport,
    lang: oneOf(b.lang, LANGS, "lang") || "fr",
    tuto: oneOf(b.tuto, TUTO, "tuto"),
    q: {},
    cc: null,
    events: [],
  };
  if (b.q != null) {
    if (typeof b.q !== "object") throw new Invalid("q");
    for (const k of Object.keys(QUALIFY)) {
      const v = oneOf(b.q[k], QUALIFY[k], k);
      if (v) p.q[k] = v;
    }
  }
  if (b.cc != null) {
    const c = b.cc;
    if (typeof c !== "object") throw new Invalid("cc");
    const seasons = Number(c.seasons);
    if (!Number.isInteger(seasons) || seasons < 0 || seasons > 999) throw new Invalid("cc.seasons");
    const services = str(c.services, 200);
    if (services && services.split(", ").some(s => !SERVICES.includes(s))) throw new Invalid("cc.services");
    p.cc = {
      seasons, services,
      cert: oneOf(c.cert, CERTS, "cc.cert"),
      lastPlayed: /^\d{4}-\d{2}-\d{2}$/.test(c.lastPlayed) ? c.lastPlayed : undefined,
    };
  }
  if (b.events != null) {
    if (!Array.isArray(b.events)) throw new Invalid("events");
    // Un événement inconnu ou mal formé est ignoré plutôt que de bloquer
    // le contact : une ancienne version du jeu ne doit pas rester en échec.
    for (const e of b.events.slice(0, MAX_EVENTS)) {
      const ev = parseEvent(e, now);
      if (ev) p.events.push(ev);
    }
  }
  return p;
}

/* ================= Brevo ================= */

function brevo(env) {
  const base = (env.BREVO_API_BASE || "https://api.brevo.com/v3").replace(/\/$/, "");
  return async (method, path, body) => {
    const r = await fetch(base + path, {
      method,
      headers: { "api-key": env.BREVO_API_KEY, "content-type": "application/json", accept: "application/json" },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    const txt = await r.text();
    let data = null;
    if (txt) { try { data = JSON.parse(txt); } catch (e) { /* corps non JSON : ignoré */ } }
    return { ok: r.ok, status: r.status, data };
  };
}

/* Un segment Brevo ne peut porter que sur un attribut existant : on crée
   ceux du jeu au premier passage, une fois par instance du Worker. */
let attributesReady = false;
export function _resetForTests() { attributesReady = false; }

async function ensureAttributes(call) {
  if (attributesReady) return;
  const r = await call("GET", "/contacts/attributes");
  if (!r.ok) return;   // non bloquant : l'écriture du contact dira ce qui manque
  const known = new Set(((r.data && r.data.attributes) || []).map(a => a.name));
  const missing = ATTRIBUTES.filter(([name]) => !known.has(name));
  const res = await Promise.all(missing.map(([name, type]) =>
    call("POST", `/contacts/attributes/normal/${name}`, { type })));
  // 400 = déjà créé entre-temps par une autre instance : ça convient aussi
  attributesReady = res.every(x => x.ok || x.status === 400);
}

const esc = v => String(v).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

/* Alerte interne « appel chaud » : le joueur annonce un budget prévu ou
   un projet à l'étude. Envoyée une seule fois par contact (CC_HOT_AT). */
async function notifyHot(call, env, p) {
  if (!env.NOTIFY_TO) return false;
  const rows = [
    ["E-mail", p.email], ["Pseudo", p.pseudo], ["Organisation", p.org], ["Rôle", p.role],
    ["Sport", p.sport && SPORTS[p.sport]], ["Maturité", p.q.CC_MATURITY],
    ["Priorité", p.q.CC_PRIORITY], ["Projet", p.q.CC_TIMING],
    ["Services essayés", p.cc && p.cc.services], ["Saisons terminées", p.cc && p.cc.seasons],
    ["Certification", p.cc && p.cc.cert], ["Actualités par e-mail", p.optIn ? "oui" : "non"],
  ].filter(([, v]) => v !== undefined && v !== null && v !== "");
  const html = `<h2>Club Catalist — appel chaud</h2>
<p>Un joueur vient d'annoncer un projet dans le questionnaire de positionnement.
Répondre à cet e-mail lui écrit directement.</p>
<table cellpadding="4">${rows.map(([k, v]) =>
    `<tr><th align="left">${esc(k)}</th><td>${esc(v)}</td></tr>`).join("")}</table>`;
  const r = await call("POST", "/smtp/email", {
    sender: { name: "Club Catalist", email: env.NOTIFY_SENDER || "noreply@cmon-sports.com" },
    to: env.NOTIFY_TO.split(",").map(s => ({ email: s.trim() })).filter(x => x.email),
    replyTo: { email: p.email },
    subject: `Club Catalist — appel chaud : ${p.org || p.email}`,
    htmlContent: html,
    tags: ["club-catalist", "hot-lead"],
  });
  if (!r.ok) console.error("alerte appel chaud refusée par Brevo", r.status);
  return r.ok;
}

export async function syncPlayer(p, env) {
  const call = brevo(env);
  const listId = Number(env.BREVO_LIST_ID || 63);
  await ensureAttributes(call);

  const found = await call("GET", `/contacts/${encodeURIComponent(p.email)}`);
  if (!found.ok && found.status !== 404) throw new BrevoError(found.status);
  const existing = found.ok ? found.data : null;
  const prev = (existing && existing.attributes) || {};

  // Attributs du jeu : toujours à jour
  const attrs = { CMON_SOLUTION_CLUBCATALIST: true, PSEUDO: p.pseudo, CC_LANG: p.lang };
  if (p.tuto) attrs.CC_TUTO = p.tuto;
  Object.assign(attrs, p.q);
  if (p.cc) {
    attrs.CC_SEASONS = p.cc.seasons;
    attrs.CC_SERVICES = p.cc.services;
    if (p.cc.cert) attrs.CC_CERT = p.cc.cert;
    if (p.cc.lastPlayed) attrs.CC_LAST_PLAYED = p.cc.lastPlayed;
  }
  // Champs partagés avec le reste du CRM : complétés, jamais écrasés
  const empty = k => prev[k] === undefined || prev[k] === null || prev[k] === "";
  if (p.org && empty("COMPANY")) attrs.COMPANY = p.org;
  if (p.role && empty("JOB_TITLE")) attrs.JOB_TITLE = p.role;
  if (p.sport && empty("SPORT")) attrs.SPORT = p.sport;
  if (!existing) attrs.OPT_IN = p.optIn;
  else if (p.optIn && prev.OPT_IN !== true) attrs.OPT_IN = true;

  // L'alerte part avant l'écriture : si elle échoue, CC_HOT_AT reste vide
  // et la prochaine synchronisation la retente.
  if (HOT_TIMING.includes(p.q.CC_TIMING) && empty("CC_HOT_AT")) {
    if (await notifyHot(call, env, p)) attrs.CC_HOT_AT = new Date().toISOString().slice(0, 10);
  }

  const update = async () => {
    const u = await call("PUT", `/contacts/${encodeURIComponent(p.email)}`, { attributes: attrs, listIds: [listId] });
    if (!u.ok) throw new BrevoError(u.status);
  };
  if (existing) {
    await update();
  } else {
    // Nouveau contact : sans consentement, il entre au CRM exclu des campagnes.
    const c = await call("POST", "/contacts", {
      email: p.email, listIds: [listId], emailBlacklisted: !p.optIn, attributes: attrs,
    });
    if (!c.ok) {
      // Créé entre la lecture et l'écriture (deux onglets) : simple mise à jour
      if (c.status === 400 && c.data && c.data.code === "duplicate_parameter") await update();
      else throw new BrevoError(c.status);
    }
  }

  // Événements : au mieux. Les attributs portent déjà l'état durable ; un
  // événement refusé est journalisé, pas renvoyé au jeu en boucle.
  const sent = await Promise.all(p.events.map(e => call("POST", "/events", Object.assign(
    { event_name: e.name, identifiers: { email_id: p.email }, event_properties: e.props },
    e.at ? { event_date: e.at } : {}))));
  const refused = sent.filter(r => !r.ok);
  if (refused.length) console.error("événements refusés par Brevo", refused.map(r => r.status));
}

async function health(env) {
  const call = brevo(env);
  const listId = Number(env.BREVO_LIST_ID || 63);
  const [list, attrs] = await Promise.all([call("GET", `/contacts/lists/${listId}`), call("GET", "/contacts/attributes")]);
  const known = new Set(attrs.ok ? ((attrs.data && attrs.data.attributes) || []).map(a => a.name) : []);
  return {
    ok: list.ok && attrs.ok,
    brevo: attrs.ok ? "ok" : "http " + attrs.status,
    listId,
    list: list.ok,
    expected: ATTRIBUTES.length,
    missing: attrs.ok ? ATTRIBUTES.map(([n]) => n).filter(n => !known.has(n)) : null,
    notify: !!env.NOTIFY_TO,
  };
}

/* ================= Point d'entrée HTTP ================= */

function json(body, status, headers) {
  return new Response(JSON.stringify(body), {
    status, headers: Object.assign({ "content-type": "application/json; charset=utf-8" }, headers),
  });
}

export default {
  async fetch(request, env) {
    const origin = request.headers.get("Origin") || "";
    const allowed = (env.ALLOWED_ORIGINS || DEFAULT_ORIGINS).split(",").map(s => s.trim()).filter(Boolean);
    const cors = allowed.includes(origin) ? { "Access-Control-Allow-Origin": origin, Vary: "Origin" } : null;

    if (request.method === "OPTIONS") {
      if (!cors) return new Response(null, { status: 403 });
      return new Response(null, { status: 204, headers: Object.assign({
        "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
        "Access-Control-Allow-Headers": "content-type",
        "Access-Control-Max-Age": "86400",
      }, cors) });
    }
    // Garde-fou de navigateur, pas une authentification : un script peut
    // forger l'en-tête Origin. La limite de débit et la validation stricte
    // bornent ce qu'il pourrait faire.
    if (!cors) return json({ ok: false, error: "origin" }, 403);

    if (env.RATE_LIMITER) {
      const { success } = await env.RATE_LIMITER.limit({ key: request.headers.get("CF-Connecting-IP") || "?" });
      if (!success) return json({ ok: false, error: "rate" }, 429, cors);
    }
    if (!env.BREVO_API_KEY) return json({ ok: false, error: "config" }, 500, cors);

    const path = new URL(request.url).pathname;
    try {
      if (path === "/health" && request.method === "GET") return json(await health(env), 200, cors);
      if (path === "/sync" && request.method === "POST") {
        const text = await request.text();
        if (text.length > MAX_BODY) return json({ ok: false, error: "too_large" }, 413, cors);
        let body;
        try { body = JSON.parse(text); } catch (e) { throw new Invalid("json"); }
        await syncPlayer(parsePlayer(body), env);
        return json({ ok: true }, 200, cors);
      }
      return json({ ok: false, error: "not_found" }, 404, cors);
    } catch (e) {
      if (e instanceof Invalid) return json({ ok: false, error: "invalid", field: e.message }, 400, cors);
      if (e instanceof BrevoError) return json({ ok: false, error: "brevo", status: e.status }, 502, cors);
      console.error("relais : erreur inattendue", e && e.message);
      return json({ ok: false, error: "internal" }, 500, cors);
    }
  },
};
