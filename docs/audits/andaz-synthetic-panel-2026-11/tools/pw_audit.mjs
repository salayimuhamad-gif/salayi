// Andaz audit — Playwright evidence harness (read-only crawl).
//
// Visits public pages of the target site at several viewports, records
// measurable facts (status, title, lang/dir, headings, nav labels, button
// labels, console errors, failed requests, timing, overflow, small text,
// small tap targets, images without alt, inputs without labels, approximate
// contrast failures), stores screenshots, and writes an auto evidence log
// (JSONL). It never submits forms, never logs in, never sends OTPs.
//
// Usage:
//   AUDIT_BASE=https://andaz.app AUDIT_OUT=../evidence node pw_audit.mjs
// Optional: AUDIT_MAX_PAGES=40  AUDIT_SLOW=1 (slow-network run)  AUDIT_SCALE=1 (text-scale run)

import { chromium, devices } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const BASE = (process.env.AUDIT_BASE || 'https://andaz.app').replace(/\/$/, '');
const OUT = path.resolve(process.env.AUDIT_OUT || path.join(path.dirname(new URL(import.meta.url).pathname), '..', 'evidence'));
const MAX_PAGES = Number(process.env.AUDIT_MAX_PAGES || 40);
const RUN_SLOW = process.env.AUDIT_SLOW === '1';
const RUN_SCALE = process.env.AUDIT_SCALE === '1';
const DATE = new Date().toISOString();

fs.mkdirSync(path.join(OUT, 'shots'), { recursive: true });
fs.mkdirSync(path.join(OUT, 'aria'), { recursive: true });
const logPath = path.join(OUT, 'evidence_auto.jsonl');
const log = (obj) => fs.appendFileSync(logPath, JSON.stringify({ recorded_at: DATE, ...obj }) + '\n');

const SEEDS = [
  '/', '/ar', '/en', '/projects', '/offers', '/map', '/invest', '/market', '/areas', '/companies', '/places',
  '/advisor', '/lifestyle', '/portfolio', '/login', '/register', '/about', '/contact', '/privacy', '/terms',
  '/this-page-should-not-exist-404-probe',
];

const VIEWPORTS = {
  mobile_360: { viewport: { width: 360, height: 780 }, deviceScaleFactor: 2.75, isMobile: true, hasTouch: true,
    userAgent: devices['Pixel 7'].userAgent, locale: 'ckb' },
  mobile_iphone: { ...devices['iPhone 13'], locale: 'ckb' },
  tablet_768: { ...devices['iPad (gen 7)'], locale: 'ckb' },
  desktop_1440: { viewport: { width: 1440, height: 900 }, locale: 'ckb' },
};

const slug = (u) => (u.replace(BASE, '') || 'home').replace(/[^a-zA-Z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 80) || 'home';

async function collect(page) {
  return page.evaluate(() => {
    const vis = (el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
    const txt = (el) => (el.innerText || el.getAttribute('aria-label') || el.getAttribute('title') || '').trim().replace(/\s+/g, ' ').slice(0, 120);
    const q = (sel) => Array.from(document.querySelectorAll(sel));
    const buttons = q('button, [role="button"], input[type="submit"], a.btn, a[class*="button"]').filter(vis);
    const links = q('a[href]').filter(vis);
    const nav = q('nav, [role="navigation"]').map(n => txt(n).slice(0, 400));
    const headings = q('h1, h2, h3').filter(vis).map(h => ({ tag: h.tagName, text: txt(h) })).slice(0, 60);
    const imgs = q('img');
    const imgsNoAlt = imgs.filter(i => !i.hasAttribute('alt') || (i.getAttribute('alt') || '').trim() === '' && !i.closest('a,button')).length;
    const inputs = q('input:not([type=hidden]), select, textarea').filter(vis);
    const inputsNoLabel = inputs.filter(i => {
      const id = i.id; const byFor = id && document.querySelector(`label[for="${CSS.escape(id)}"]`);
      return !(byFor || i.closest('label') || i.getAttribute('aria-label') || i.getAttribute('aria-labelledby') || i.getAttribute('placeholder'));
    }).length;
    const smallText = q('p, span, a, li, button, label, td, th, div').filter(el => el.children.length === 0 && vis(el) && txt(el).length > 0)
      .filter(el => parseFloat(getComputedStyle(el).fontSize) < 12).length;
    const tiny = [...buttons, ...links].filter(el => { const r = el.getBoundingClientRect(); return (r.width < 40 || r.height < 40); })
      .map(el => ({ text: txt(el).slice(0, 40), w: Math.round(el.getBoundingClientRect().width), h: Math.round(el.getBoundingClientRect().height) })).slice(0, 40);
    const docEl = document.documentElement;
    const overflowX = docEl.scrollWidth > window.innerWidth + 1;
    // approximate contrast
    const parse = (c) => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const [r, g, b, a = '1'] = m[1].split(',').map(s => parseFloat(s)); return { r, g, b, a }; };
    const lum = ({ r, g, b }) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
    const bgOf = (el) => { let e = el; while (e) { const c = parse(getComputedStyle(e).backgroundColor); if (c && c.a > 0.9) return c; e = e.parentElement; } return { r: 255, g: 255, b: 255, a: 1 }; };
    const textEls = q('p, span, a, li, button, label, td, th, h1, h2, h3, h4, div').filter(el => el.children.length === 0 && vis(el) && txt(el).length > 1).slice(0, 400);
    let contrastFails = 0; const contrastSamples = [];
    for (const el of textEls) {
      const fg = parse(getComputedStyle(el).color); if (!fg) continue; const bg = bgOf(el);
      const L1 = lum(fg), L2 = lum(bg); const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
      const fs = parseFloat(getComputedStyle(el).fontSize); const bold = parseInt(getComputedStyle(el).fontWeight, 10) >= 700;
      const large = fs >= 24 || (fs >= 18.66 && bold); const need = large ? 3 : 4.5;
      if (ratio < need) { contrastFails++; if (contrastSamples.length < 12) contrastSamples.push({ text: txt(el).slice(0, 50), ratio: +ratio.toFixed(2), fontSize: fs }); }
    }
    const nav_perf = performance.getEntriesByType('navigation')[0];
    const res = performance.getEntriesByType('resource');
    const bytes = res.reduce((a, r) => a + (r.transferSize || 0), 0) + (nav_perf ? nav_perf.transferSize || 0 : 0);
    const alt = q('link[rel="alternate"][hreflang]').map(l => ({ hreflang: l.getAttribute('hreflang'), href: l.getAttribute('href') }));
    const langSwitch = q('a, button, select, [role="menuitem"]').filter(vis).filter(el => /^(ckb|ar|en|کوردی|عربي|العربية|english|kurdî|کوردیی?|سۆرانی|زمان|اللغة|language)$/i.test(txt(el)) || /lang|locale/i.test(el.getAttribute('href') || '') || /lang|locale/i.test(el.className || '')).map(el => ({ tag: el.tagName, text: txt(el), href: el.getAttribute('href') })).slice(0, 12);
    const main = document.querySelector('main, [role="main"]');
    const bodyText = (document.body.innerText || '').replace(/\s+/g, ' ').trim();
    return {
      title: document.title, lang: docEl.getAttribute('lang'), dir: docEl.getAttribute('dir') || getComputedStyle(docEl).direction,
      metaViewport: document.querySelector('meta[name="viewport"]')?.getAttribute('content') || null,
      metaDescription: document.querySelector('meta[name="description"]')?.getAttribute('content') || null,
      canonical: document.querySelector('link[rel="canonical"]')?.getAttribute('href') || null,
      alternates: alt, hasMainLandmark: !!main, hasSkipLink: !!q('a[href^="#"]').find(a => /skip|بازدان|تخطي/i.test(txt(a))),
      h1: headings.filter(h => h.tag === 'H1').map(h => h.text), headings, nav, buttons: buttons.map(txt).filter(Boolean).slice(0, 80),
      buttonsUnnamed: buttons.filter(b => !txt(b)).length, linksCount: links.length, imgCount: imgs.length, imgsNoAlt, inputsCount: inputs.length, inputsNoLabel,
      smallTextCount: smallText, tinyTargets: tiny, tinyTargetCount: tiny.length, overflowX, scrollWidth: docEl.scrollWidth, innerWidth: window.innerWidth,
      contrastFails, contrastSamples, langSwitch, bodyTextLength: bodyText.length, bodyTextStart: bodyText.slice(0, 1200),
      timing: nav_perf ? { domContentLoaded: Math.round(nav_perf.domContentLoadedEventEnd), load: Math.round(nav_perf.loadEventEnd), ttfb: Math.round(nav_perf.responseStart) } : null,
      transferBytesApprox: bytes, resourceCount: res.length,
      internalLinks: Array.from(new Set(links.map(a => a.href).filter(h => h.startsWith(location.origin)))).slice(0, 300),
    };
  });
}

async function visit(context, url, vpName, opts = {}) {
  const page = await context.newPage();
  const consoleErrors = []; const failed = []; const responses = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 300)); });
  page.on('requestfailed', (r) => failed.push({ url: r.url().slice(0, 200), err: r.failure()?.errorText }));
  page.on('response', (r) => { const s = r.status(); if (s >= 400) responses.push({ url: r.url().slice(0, 200), status: s }); });
  if (opts.slow) {
    const cdp = await context.newCDPSession(page);
    await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 400, downloadThroughput: (400 * 1024) / 8, uploadThroughput: (200 * 1024) / 8 });
  }
  if (opts.textScale) {
    await page.addInitScript((pct) => { document.addEventListener('DOMContentLoaded', () => { document.documentElement.style.fontSize = pct; }); }, opts.textScale);
  }
  const t0 = Date.now(); let status = null; let finalUrl = url; let error = null;
  try {
    const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    status = resp?.status() ?? null;
    await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
    await page.waitForTimeout(1200);
    finalUrl = page.url();
  } catch (e) { error = String(e).slice(0, 300); }
  const elapsedMs = Date.now() - t0;
  let data = null;
  try { data = await collect(page); } catch (e) { error = (error ? error + ' | ' : '') + 'collect: ' + String(e).slice(0, 200); }
  const base = `${slug(url)}__${vpName}${opts.slow ? '__slow' : ''}${opts.textScale ? '__scale' : ''}`;
  try { await page.screenshot({ path: path.join(OUT, 'shots', base + '.png') }); } catch {}
  try { await page.screenshot({ path: path.join(OUT, 'shots', base + '__full.png'), fullPage: true }); } catch {}
  try { const aria = await page.locator('body').ariaSnapshot(); fs.writeFileSync(path.join(OUT, 'aria', base + '.txt'), aria); } catch {}
  log({ kind: 'page_visit', url, finalUrl, viewport: vpName, slow: !!opts.slow, textScale: opts.textScale || null, status, error, elapsedMs,
    consoleErrors: consoleErrors.slice(0, 20), failedRequests: failed.slice(0, 20), httpErrors: responses.slice(0, 20), screenshot: `shots/${base}.png`, ...data });
  const links = data?.internalLinks || [];
  await page.close();
  return { links, status, finalUrl };
}

(async () => {
  const browser = await chromium.launch();
  log({ kind: 'run_start', base: BASE, maxPages: MAX_PAGES, slow: RUN_SLOW, textScale: RUN_SCALE });
  // Discovery pass on mobile_360 (primary viewport)
  const seen = new Set(); const queue = SEEDS.map(p => BASE + p); const discovered = [];
  const ctx = await browser.newContext(VIEWPORTS.mobile_360);
  while (queue.length && seen.size < MAX_PAGES) {
    const url = queue.shift(); if (seen.has(url)) continue; seen.add(url);
    const { links } = await visit(ctx, url, 'mobile_360');
    for (const l of links) {
      const clean = l.split('#')[0];
      if (!seen.has(clean) && !queue.includes(clean) && !/\.(png|jpg|jpeg|svg|pdf|webp|css|js)$/i.test(clean) && !/logout|unsubscribe|install/i.test(clean)) {
        // prioritise detail pages
        if (/\/(projects|offers|areas|companies|places)\/[^/]+/.test(clean)) queue.unshift(clean); else queue.push(clean);
      }
    }
    discovered.push(url);
  }
  await ctx.close();
  log({ kind: 'discovery_done', visited: discovered.length, remaining_in_queue: queue.length, remaining_sample: queue.slice(0, 50) });
  // Secondary viewports for a subset of the most important pages
  const important = discovered.filter(u => { const p = u.replace(BASE, ''); return p === '' || p === '/' || /^\/(ar|en)$/.test(p) || /^\/(projects|offers|map|invest|market|areas|companies|advisor|login|register)(\/[^/]+)?$/.test(p); }).slice(0, 18);
  for (const vp of ['mobile_iphone', 'tablet_768', 'desktop_1440']) {
    const c = await browser.newContext(VIEWPORTS[vp]);
    for (const u of important) await visit(c, u, vp);
    await c.close();
  }
  if (RUN_SLOW) {
    const c = await browser.newContext(VIEWPORTS.mobile_360);
    for (const u of important.slice(0, 6)) await visit(c, u, 'mobile_360', { slow: true });
    await c.close();
  }
  if (RUN_SCALE) {
    const c = await browser.newContext(VIEWPORTS.mobile_360);
    for (const u of important.slice(0, 8)) await visit(c, u, 'mobile_360', { textScale: '150%' });
    await c.close();
  }
  log({ kind: 'run_end' });
  await browser.close();
  console.log('done; evidence at', logPath);
})();
