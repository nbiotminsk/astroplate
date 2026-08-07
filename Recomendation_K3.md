# Astroplate Load-Speed Audit — teleofis24.by

**Build facts measured from `dist/`:** 191 pages · homepage HTML = **214 KB raw / 39.4 KB gzip** (of which **152 KB is inlined CSS**) · `client.js` (React runtime) = **186 KB raw / 58 KB gzip** loaded on every page · 3 React islands on all 191 pages · product-page LCP image is lazy-loaded.

---

## 1. JavaScript Audit

### 1.1 React runtime shipped to every page for 3 trivial islands
- **File:** `src/layouts/Base.astro:344-345, 354`
- **Severity:** Critical
- **Metric:** TBT, INP, FCP (on mobile), Speed Index
- **Problem:** `CartModal`, `CheckoutModal`, `PromoBadge` all hydrate with `client:idle` on **every one of the 191 pages** (verified in `dist/index.html`, `dist/blog/.../index.html`, `dist/contact/index.html` — all contain 3 `<astro-island>` elements). This forces download + parse + execution of `client.js` (React+ReactDOM, **186 KB raw / 58 KB gzip**) plus `index.js` (7.6 KB) plus 3 component chunks on pages where the user never opens a modal (contact, blog, info…). `CartModal.tsx` (92 lines) and `PromoBadge.tsx` (129 lines) are simple show/hide modals — they don't justify React.
- **Recommendation:** Replace these three with a single ~3 KB vanilla script (the modals are event-driven: `cart:item-added`, `checkout:open`, button click). This removes React from the critical path of the whole site.

```astro
<!-- Before (Base.astro:344-354) -->
<CartModal client:idle />
<CheckoutModal client:idle />
<PromoBadge client:idle />

<!-- After: one vanilla component, no renderer -->
<Modals />  <!-- plain .astro with a bundled <script>, ~0 hydration cost -->
```

If you must keep React: load on demand instead of idle —

```astro
<PromoBadge client:visible />        <!-- only when scrolled into view -->
<!-- and for modals, dynamic-import on first event: -->
<script>
  document.addEventListener('cart:item-added', async () => {
    (await import('@/lib/modal-lite')).openCart();
  }, { once: true });
</script>
```

### 1.2 Dead `SearchModal` + 423 KB search.json — latent bundle bomb
- **File:** `src/layouts/helpers/SearchModal.tsx:1` (`import searchData from ".json/search.json"`), `.json/search.json` (**423,735 bytes**), `src/config/config.json` (`settings.search: false`)
- **Severity:** High (latent Critical)
- **Metric:** TBT, FID/INP
- **Problem:** SearchModal is imported nowhere — the search button in `Header.astro:148-155` (`data-search-trigger`) has no modal attached (broken UX), and the 423 KB `search.json` (full blog content) is generated on every build for nothing. If anyone ever mounts `SearchModal`, the **entire 423 KB JSON gets bundled into client JS** and regex-searched on the main thread (`SearchModal.tsx:43-46` even runs `performance.now()` per keystroke render).
- **Recommendation:** Delete `SearchModal.tsx`, `SearchResult.tsx`, `search.css`, and the jsonGenerator search step — or re-enable properly with fetch-on-open:

```ts
// fetch index lazily, only when user opens search
const searchData = await (await fetch('/search.json')).json();
```

### 1.3 `marked` top-level import in shared util — latent client-side shipping
- **File:** `src/lib/utils/textConverter.ts:2`, `src/layouts/helpers/SearchResult.tsx:1`, `src/layouts/shortcodes/Tabs.tsx:1`
- **Severity:** Medium (latent High)
- **Metric:** TBT
- **Problem:** `marked` (~40 KB min) is imported at module top level in `textConverter.ts`, which is imported by the client component `SearchResult.tsx`. Currently safe only because SearchResult is dead code. One future `import { plainify } from "@/lib/utils/textConverter"` in any hydrated component ships marked to the browser.
- **Recommendation:** Split markdown functions into `textConverter.server.ts` (uses marked) and keep `plainify`/`titleify`/`slugify` in a dependency-free `textConverter.ts`.

### 1.4 Inline `is:inline` scripts skip minification and bundling
- **File:** `src/pages/index.astro:459-564` (106-line photo-upload handler), `src/layouts/helpers/Announcement.astro:29-48`
- **Severity:** Medium
- **Metric:** FCP (parse), HTML size
- **Problem:** `is:inline` scripts are emitted verbatim (unminified, untyped-checked by Vite, no dead-code elimination) and inflate HTML on every request. The homepage handler is 3.5 KB raw.
- **Recommendation:** Drop `is:inline` so Astro bundles it as an external hashed module (cached 1 year, minified):

```astro
<!-- Before: src/pages/index.astro:459 -->
<script is:inline> (() => { /* 106 lines */ })(); </script>
<!-- After -->
<script src="../scripts/meter-form.ts"></script>
<!-- or just: --><script> import "../scripts/meter-form"; </script>
```

### 1.5 `DynamicIcon` imports the entire fa6 pack
- **File:** `src/layouts/helpers/DynamicIcon.tsx:3` (`import * as FaIcons from "react-icons/fa6"`), used by `src/layouts/components/Social.astro`
- **Severity:** Medium (currently build-time only; latent Critical if hydrated)
- **Metric:** TBT (if hydrated), build time
- **Problem:** Full fa6 = ~1,500 icons. SSR-only today (and `Social.astro` appears unused), but any future `client:*` on it bundles the whole pack.
- **Recommendation:** Extend the existing `IconSprite.astro` pattern (already good) and delete `DynamicIcon`/`Social`, or switch to per-icon imports: `import { FaX } from "react-icons/fa6/FaX"`.

### 1.6 `date-fns` + `ru` locale
- **File:** `src/lib/utils/dateFormat.ts:1-2`
- **Severity:** Low
- **Metric:** none at runtime (verified: only used in `.astro` frontmatter → build-time only)
- **Recommendation:** Optional — replace with `new Intl.DateTimeFormat("ru-RU", {...})` to drop a dependency; zero runtime impact either way.

### 1.7 Dead/duplicated utilities
- **File:** `src/lib/utils/bgImageMod.ts` (no usages found), `src/layouts/helpers/Announcement.astro` (disabled: `announcement.enable: false`), `src/layouts/shortcodes/Youtube.tsx` + `Tabs.tsx` (no usages in `src/content/`), `astro-swiper` dependency (no matches in `src/`)
- **Severity:** Low
- **Metric:** build time, maintenance
- **Recommendation:** Remove unused deps: `yarn remove astro-swiper prop-types @justinribeiro/lite-youtube` (if shorts stay unused) and dead files.

---

## 2. Astro Component Audit

### 2.1 Product-page LCP image is lazy-loaded — **worst finding**
- **File:** `src/pages/store/[slug].astro:311-318`
- **Severity:** Critical
- **Metric:** LCP
- **Problem:** The main product image — the LCP element on every store page — renders with `loading="lazy"` (verified in `dist/store/arenda-modulya-nbiot/index.html`) because `ImageMod` receives no `loading`/`fetchpriority`. Lazy LCP images are delayed by the browser until layout, typically adding 0.5–1.5 s to LCP on mobile.
- **Recommendation:**

```astro
<!-- Before (store/[slug].astro:311) -->
<ImageMod src={image} alt={title} width={620} height={420} format="webp"
  class="w-full max-h-[420px] object-contain rounded-xl ..." />

<!-- After -->
<ImageMod src={image} alt={title} width={620} height={420} format="webp"
  loading="eager" fetchpriority="high" decoding="async"
  class="w-full max-h-[420px] object-contain rounded-xl ..." />
```

### 2.2 Header logo lazy-loaded, in two copies
- **File:** `src/layouts/components/Logo.astro:27-44` (and 49-63, 73-79)
- **Severity:** High
- **Metric:** LCP (mobile — hero image is `hidden md:block`, so the logo/H1 is the mobile LCP), FCP
- **Problem:** Both light and dark logo variants render with default `loading="lazy"` (verified in every dist page). The logo is at the top of every page.
- **Recommendation:**

```astro
<ImageMod src={src ? src : logo} ... loading="eager" fetchpriority="high" decoding="async" />
```

Better: only render the dark-mode `<img>` when needed — currently both raster variants are in the DOM of every page. Consider a single SVG logo or CSS `content` swap.

### 2.3 Hero image: no responsive srcset, `decoding="sync"`, no preload
- **File:** `src/pages/index.astro:143-153`, `src/layouts/components/ImageMod.astro:54-70`
- **Severity:** High
- **Metric:** LCP, mobile bandwidth
- **Problem:** (a) All visitors get the **1200 px / 54.7 KB** webp regardless of viewport — a 390 px phone needs ~600 px. (b) `decoding="sync"` blocks the main thread on decode; even for LCP, `async` (or omitting) is safer. (c) The `<img>` sits after **~170 KB of inlined `<head>` CSS** in the HTML stream; a `<link rel="preload">` would let the browser start the fetch before the parser reaches it.
- **Recommendation:**

```astro
<!-- index.astro -->
<ImageMod src="/images/water_meter_blueprint.png" width={1200} height={1200}
  widths={[480, 768, 1200]} sizes="(min-width: 768px) 50vw, 100vw"
  loading="eager" fetchpriority="high" decoding="async" ... />

<!-- Base.astro head slot (index.astro:46) -->
<link rel="preload" as="image" href="/_astro/water_meter_blueprint....webp"
      fetchpriority="high" imagesrcset="..." imagesizes="..." />
```

### 2.4 No `client:visible` / `client:media` anywhere
- **File:** repo-wide (grep confirms only `client:idle` ×3 and `client:only` ×1)
- **Severity:** Medium
- **Metric:** TBT, INP
- **Problem/Recommendation:** `PromoBadge` (Base.astro:354) → `client:visible` (hydrates only when the fixed button is actually rendered into view). `Disqus` (PostSingle.astro:159) → if ever re-enabled, wrap in an IntersectionObserver facade instead of `client:only` (loads Disqus's ~100 KB+ of third-party JS immediately on blog pages).

### 2.5 Product JSON-LD inlines up to 5 000 chars of description
- **File:** `src/pages/store/[slug].astro:34-38` (`description.slice(0, 5000)`) → schema at `:166-169`
- **Severity:** Low
- **Metric:** HTML size / FCP (parse)
- **Recommendation:** Cap schema description at ~300 chars — rich results don't need more:

```ts
const description = (product.data.description || product.data.short_description || "").slice(0, 300);
```

### 2.6 Font loading — mostly good, one nit
- **File:** `src/layouts/Base.astro:245-250`
- **Severity:** Low (informational)
- **Metric:** FCP/CLS
- **Verified good:** single 38 KB woff2, `display:swap`, preload present, self-hosted via Font API — no Google Fonts preconnect needed. Two `@font-face` blocks (`Golos Text-949…`, `Golos Text-f5b…`) reference the same file, and the one preload covers both. No change needed; keep as is.

---

## 3. Page Audit — `src/pages/index.astro`

- **DOM:** 556 elements, max depth 23 — **acceptable**, no action. (Lighthouse flags >1 400.)
- **3.1 Inline form handler (lines 459-564):** see §1.4 — extract to a bundled module. **Severity: Medium, Metric: FCP/TBT.**
- **3.2 Below-fold sections:** All homepage sections (Products, Solutions, WhyUs, HowToBuy, Integrations, Business, Cases, CTA, FAQ) are plain HTML — correctly zero-JS. No change needed; do **not** add islands here.
- **3.3 Product card images (line 202-209):** default lazy — correct (below fold). But like the hero, they get no srcset: `width={840}` served to a 350 px-wide card on mobile. Add `widths={[320, 640, 840]}` `sizes="(min-width:1024px) 25vw, (min-width:640px) 50vw, 100vw"`. **Severity: Medium, Metric: mobile bandwidth / LCP on slow networks.**
- **3.4 JSON-LD FAQ + LocalBusiness (lines 47-100):** ~3 KB total inline — fine; keep inline (structured data must be in initial HTML).

---

## 4. Build Configuration Audit — `astro.config.mjs`

### 4.1 Immutable 1-year cache on JS files that have NO content hash — cache-poisoning bug
- **File:** `astro.config.mjs:140-141` and `:156-157`; cache headers in `netlify.toml:18-21`, `public/_headers:9-10`, `public/.htaccess` (`_astro/.*\.(js|css|woff2|wasm)` → `max-age=31536000, immutable`)
- **Severity:** Critical (correctness/staleness → users run old JS for up to a year; also masks perf regressions)
- **Metric:** all (indirect)
- **Problem:** `entryFileNames/chunkFileNames: "_astro/[name].js"` emit stable names (`client.js`, `CartModal.js`, `CheckoutModal.js`, `PromoBadge.js`, `Header.astro_...js` — verified in `dist/_astro/`). With `immutable` + 1-year cache, a returning visitor's browser will **never revalidate** `client.js` after a deploy — new HTML runs against stale JS. The `vite:preloadError` reload hack (`Base.astro:309-333`) only catches *missing* files, not stale same-name files that load fine.
- **Recommendation:** restore hashes; headers stay as-is:

```js
// astro.config.mjs — both rollupOptions blocks
entryFileNames: "_astro/[name].[hash].js",
chunkFileNames: "_astro/[name].[hash].js",
```

(Images/fonts already carry hashes — that's why `_astro/*` immutable is right for them.)

### 4.2 `inlineStylesheets: "always"` → 152 KB CSS duplicated into 191 HTML files
- **File:** `astro.config.mjs:132-134`
- **Severity:** High
- **Metric:** FCP (first visit), TTFB→parse on every navigation, hosting bandwidth
- **Problem:** Every page inlines the full 150 KB (minified; ~20 KB gzip) Tailwind bundle → 214 KB HTML pages. First visit benefits (no render-blocking request), but **every subsequent page view re-downloads and re-parses the entire CSS** inside the HTML, whereas an external `styles.[hash].css` would be cached for a year. For a 191-page store where users browse multiple products, external + `<link rel="preload">` wins after page 1.
- **Recommendation:**

```js
build: { inlineStylesheets: "never" },   // or "auto"
```

plus emit a per-page critical-CSS later if desired. If you keep `"always"`, at least cut the CSS itself (§6).

### 4.3 No vendor chunk splitting — monolithic `client.js`
- **File:** `astro.config.mjs:135-163`
- **Severity:** Medium (moot if §1.1 is adopted — React leaves the critical path entirely)
- **Metric:** TBT
- **Recommendation:** if React stays, split vendor so component chunks don't drag ReactDOM re-parse: `output.manualChunks: { react: ["react", "react-dom"] }`.

### 4.4 Verified OK / minor
- `compressHTML` — Astro 6 default `true`; verified output is minified (whitespace 4.1 %, CSS single-line). ✅
- `experimental.clientPrerender` — not enabled; only relevant with View Transitions. Skip.
- `experimental.directRenderScript` — scripts already bundle as external modules correctly (e.g. `Header.astro_...js` = 297 B). Not needed.
- `@astrojs/partytown` — **not needed while GTM is disabled** (`config.json` `google_tag_manager.enable: false`, verified: zero `googletagmanager` occurrences in dist). When marketing re-enables GTM, load it via Partytown or `client:idle`-style deferral, not the current sync `<head>` snippet that `@digi4care/astro-google-tagmanager` injects at `Base.astro:122-126`.
- `astro-compress` — marginal gain; HTML/CSS/JS already minified. Optional for `public/` assets.

---

## 5. Image Strategy Audit

### 5.1 `ImageMod.astro` — no responsive images anywhere on the site
- **File:** `src/layouts/components/ImageMod.astro:6-19, 54-70`
- **Severity:** High
- **Metric:** LCP, mobile data, Speed Index
- **Problem:** The wrapper exposes no `widths`/`sizes`, so Astro's `<Image>` emits a single `src` — no `srcset`. Every image on the site (hero, product cards, blog cards, product detail) serves one fixed width to all viewports. Confirmed in dist: all `<img>` tags lack `srcset`.
- **Recommendation:**

```astro
---
// ImageMod.astro — add to Props
widths?: number[];
sizes?: string;
---
<Image
  ...
  widths={widths ?? [Math.round(width / 2), width]}
  sizes={sizes ?? `(max-width: ${width}px) 100vw, ${width}px`}
/>
```

Astro's `<Image>` auto-generates `srcset` when `widths`+`sizes` are passed. Optionally upgrade to `<Picture>` with `formats={["avif","webp"]}` — avif saves a further ~20-30 % over the current webp-only (`format="webp"` everywhere).

### 5.2 Hardcoded `<img>` bypassing ImageMod
- **File:** none found — all raster images go through `ImageMod` (grep-verified). ✅ Good.

### 5.3 `bgImageMod.ts`
- **File:** `src/lib/utils/bgImageMod.ts:33-36`
- **Severity:** Low (currently unused — zero usages found)
- **Problem:** If used, `getImage({ src, format })` without `width` emits the **original resolution** — a 2400 px PNG background would ship as a multi-hundred-KB webp.
- **Recommendation:** add `width` + `quality` params, or delete the file.

### 5.4 `IconSprite.astro` — good pattern ✅
- ~7 KB of `<symbol>`s inlined once per page, referenced by `<use>` — far better than hydrating react-icons. Loaded on every page including ones using few icons, but the tradeoff is acceptable. Keep. (`Base.astro:352`)

---

## 6. CSS Audit

### 6.1 Dead search CSS + typography plugin weight shipped site-wide
- **File:** `src/styles/main.css:11` (`@import "./search.css"`), `src/styles/search.css` (97 lines), `@plugin "@tailwindcss/typography"` (`main.css:3`)
- **Severity:** Medium
- **Metric:** FCP (CSS parse), HTML size (since CSS is inlined)
- **Problem:** Verified in dist: `.search-modal` rules are present in the inlined CSS of the homepage — yet search is disabled (`settings.search: false`) and SearchModal is dead code. Similarly `.prose`/`.content` typography rules ship on all 191 pages though only blog/product bodies use them (unavoidable with the plugin, but the search CSS is pure waste).
- **Recommendation:** delete `search.css` + its import; keep typography (used via `.content` in PostSingle/store pages).

### 6.2 Total CSS budget
- **File:** built CSS = **150,223 bytes minified** (~20 KB gzip), 1 515 rules, 549 `.dark` occurrences
- **Severity:** Medium
- **Metric:** FCP
- **Recommendation:** Tailwind v4 JIT is working (no obvious unused-class leak beyond the above). Biggest lever is architectural (§4.2: external cached CSS) and, optionally, dropping the dark-mode variant set (`@custom-variant dark`) if the theme switcher (`theme_switcher: true`) isn't business-critical — that alone would cut roughly a third of the rules.

---

## 7. Third-Party Dependencies Audit

| Package | Verdict |
|---|---|
| `react`, `react-dom`, `@astrojs/react` | **Critical-path cost today** — see §1.1. Remove islands → remove 58 KB gzip/page. |
| `react-icons` | Runtime-safe (SSR-rendered to inline SVG in `Header.astro:5-7`, `PostSingle.astro:9`, `store/index.astro:12`). Keep, but fix `DynamicIcon.tsx:3` full-pack import (§1.5). |
| `date-fns` | Build-time only (§1.6). Optional swap to `Intl`. |
| `marked`, `remark-*` | Build-time only, **but** `marked` is one import away from shipping client-side (§1.3). |
| `disqus-react` | Disabled (`disqus.enable: false`); the 21.6 KB `Disqus.js` chunk is built but referenced by no page — harmless dead weight in `dist`. If re-enabled, replace `client:only` with a click-to-load facade. |
| `astro-swiper` | **Unused entirely** — remove from `package.json:32`. |
| `@digi4care/astro-google-tagmanager` | Disabled in config; zero bytes shipped. Re-enable only via Partytown/deferred strategy. |
| `@justinribeiro/lite-youtube` | Good facade pattern (`Youtube.tsx:13` dynamic import) but shortcode unused in content — dead dep. |
| `prop-types` | Legacy; React 19 doesn't need it at runtime. Remove. |
| `sharp` | Build-time image service — correct. ✅ |

---

## Prioritized Action Plan

| # | Fix | Expected impact |
|---|---|---|
| 1 | Product LCP image: `loading="eager" fetchpriority="high"` (`store/[slug].astro:311`) | LCP −0.5-1.5 s on all store pages |
| 2 | Restore `[hash]` in JS filenames (`astro.config.mjs:140-141, 156-157`) | Fixes year-long stale-JS poisoning; enables safe immutable caching |
| 3 | Replace 3 React islands with vanilla JS (`Base.astro:344-354`) | −58 KB gzip JS & −~100 ms TBT on **every page** |
| 4 | Logo `loading="eager"` (`Logo.astro:27-44`) | Mobile LCP/FCP improvement |
| 5 | Add `widths`/`sizes` to `ImageMod` + hero preload (`ImageMod.astro`, `index.astro:143`) | −30-50 % hero/card image bytes on mobile; earlier LCP discovery |
| 6 | `inlineStylesheets: "never"` (+ preload) or trim CSS (kill `search.css`) | −20 KB gzip per repeat page-view |
| 7 | Extract `is:inline` homepage form script (`index.astro:459`) | smaller HTML, minified cached JS |
| 8 | Delete dead code: SearchModal/search.json, DynamicIcon/Social, bgImageMod, astro-swiper, prop-types | build hygiene, removes latent bundle bombs |
