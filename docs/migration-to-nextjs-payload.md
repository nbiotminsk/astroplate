# Migration Plan: Astro → Next.js + PayloadCMS (Embedded)

Target: `teleofis24.by`, self-hosted on Plesk (hb.by), PostgreSQL, byte-for-byte URL parity incl. trailing slashes.

## 1. Inventory & Freeze Contract

Before touching any code, capture the **exact URL and content manifest** so we can later diff and guarantee parity.

- Build a script (`scripts/audit-urls.mjs`) that walks `src/pages/**` + Astro `getStaticPaths()` + `src/content/**` and emits:
  - `urls.json` — full list of public URLs (with trailing slashes), grouped by route type (blog, store, info, category, tag, page, static).
  - `redirects.json` — any existing redirects found in `public/_redirects`, `netlify.toml`, `wrangler.jsonc`, or `.vercel`.
  - `frontmatter.json` — every frontmatter field per collection entry (slug → title, meta_title, description, date, image, faq, features, price, schema fields, etc.).
  - `assets.json` — every file in `public/` and every image referenced in markdown.
- Snapshot the current production output: run `npm run build`, capture `dist/` listing and the generated `sitemap*.xml`. Decode Cyrillic the same way `astro.config.mjs` does (lines 226–233) and store canonical decoded URLs.
- This becomes the **acceptance contract**: every navigation in the audit MUST resolve identically after migration or the cutover is blocked.

## 2. Tech Stack Decisions (locked)

| Concern | Decision |
|---|---|
| Frontend | Next.js 15 (App Router) |
| CMS | Payload 3.x embedded (`payload` package + `@payloadcms/db-postgres`, `@payloadcms/next`, `@payloadcms/richtext-lexical`, `@payloadcms/plugin-seo`) |
| DB | PostgreSQL (managed or Plesk-hosted) |
| Hosting | Plesk on hb.by — Node.js app via Plesk Node extension, persistent disk |
| Build output | `output: 'standalone'` for self-hosting |
| Uploads | `@payloadcms/plugin-cloud-storage` not used — Payload local disk adapter writing to `/var/www/volumes/uploads` (persistent path on Plesk) |
| Trailing slash | `trailingSlash: true` in `next.config.mjs` (matches Astro) |
| URL characters | All slugs stored decoded (Cyrillic-transliterated as today); never percent-encoded in DB |
| Image optimization | Next.js `<Image>` + Payload's image transformation in app route `/api/media/file/[...path]` (no external CDN) |

## 3. Content Architecture in Payload

Define Payload collections that mirror `src/content.config.ts` exact schema, so the migration is lossless:

### Collections
1. **Blog** (`slug`, `title`, `meta_title`, `description`, `date`, `image`, `author`, `categories: relationship→Category[]`, `tags: relationship→Tag[]`, `draft`, `faq: array{question,answer}`, `body: RichText (Lexical)`).
2. **Store** — replicate every Sitepins-style field in current schema (`storeCollection` lines 117–173): `title`, `meta_title`, `meta_description`, `category`, `price`, `price_currency`, `img`, `image`, `brand`, `review`, `aggregateRating`, `availability`, `shippingDetails`, `hasMerchantReturnPolicy`, `short_description`, `description`, `features[]`, `faq[]`, `sku`, `mpn`, `gtin`, `condition`, `custom_label_0`, `body: RichText`.
3. **Pages** (mirrors `commonFields`, used by `/services`, `/solutions`, etc.).
4. **Info** (mirrors `infoCollection` — FAQ-enabled, for `/info/dogovor-oferty`, `/info/dostavka-oplata`).
5. **Homepage** — single global doc structured to match `homepageCollection` schema (banner + features[]).
6. **Contact** — single global doc.
7. **Category**, **Tag** — separate collections so blog/store taxonomies can be edited via UI.
8. **Redirects** — `{ from: text, to: text, status: number(308) }` for cut over safety.
9. **Users** — Payload default + admin role.

### Slugs = canonical URL
- **Custom slug field DISABLED for editing** on existing entries; slugs must remain byte-identical. Add a server-side guard: on collection create, slug is generated from title via transliteration; on update of an existing doc the `slug` field is read-only in the admin UI.
- Slugs stored **decoded** in DB (e.g., `distancionny-syem-pod-kluch`). The Cyrillic-decode that `astro.config.mjs` does at sitemap time becomes unnecessary.

### Rich-text migration
- MDX importer script parses each `.md`/`.mdx` with `gray-matter` + `remark-parse`, walks the tree, and emits a Lexical JSON tree. Shortcodes (`Button`, `Accordion`, `Notice`, `Video`, `Youtube`, `Tabs`, `Tab`) map to **custom Lexical blocks** registered via `@payloadcms/rich-text-lexical` features config. The `remarkResolvePrices` plugin logic (lines 54–97) is replaced by a stored Price-Block that resolves to current product prices at render time (server component reads from Payload).

## 4. Next.js Routing that Preserves URLs 1:1

App Router structure with **force-static + ISR** and `trailingSlash: true` on every page:

```
app/
  (frontend)/
    layout.tsx
    page.tsx                    # /
    blog/
      page.tsx                  # /blog/
      [slug]/page.tsx           # /blog/{slug}/  (generateStaticParams from Payload)
      page/[slug]/page.tsx      # /blog/page/{n}/  (matches Astro pagination)
    store/
      page.tsx                  # /store/
      [slug]/page.tsx           # /store/{slug}/
    categories/
      page.tsx                  # /categories/
      [category]/page.tsx       # /categories/{category}/
    tags/
      page.tsx                  # /tags/
      [tag]/page.tsx            # /tags/{tag}/
    info/
      [slug]/page.tsx           # /info/{slug}/
    services/page.tsx
    solutions/page.tsx
    contact/page.tsx
    cart/page.tsx
    layout.astro-metadata.tsx   # robots meta, canonical, OG, JSON-LD per type
  (payload)/
    admin/[[...segments]]/page.tsx  # Payload Admin UI on /admin
  api/
    payload/[...slug]/route.ts
    carts/route.ts              # preserve client-cart logic from src/lib/cart.ts
    media/file/[...path]/route.ts   # Payload local-file serving
  rss.xml/route.ts              # port src/pages/rss.xml.ts
  sitemap.xml/route.ts          # port astro.config.mjs sitemap logic incl. image-sitemap
  robots.txt/route.ts
```

### Dynamic render strategy
- Pages use `generateStaticParams()` (Id-mode `flag: 'header'`) + `revalidate` keyed on collection update via Payload access hook → `revalidatePath`/`revalidateTag`.
- `trailingSlash: true` in next.config produces canonical URL with trailing slash, matching Astro's `trailingSlash: "always"`.
- For blog posts and store products: add `headers()` metadata that emits `<link rel="canonical">` exactly equal to current canonical (already absolute, e.g. `https://teleofis24.by/store/{slug}/`).

## 5. SEO Preservation – Item by Item

Carry over every SEO artifact that exists today. The current site already has rich SEO; moving it verbatim is the project's central risk.

| Astro source | Next.js equivalent |
|---|---|
| `astro-seo` meta per page | `generateMetadata()` returning identical title/description/canonical/OG/Twitter |
| JSON-LD `Product` schema in `store/[slug].astro` (lines 166–269) | Identical `Product` JSON-LD, computed server-side, injected via `<script type="application/ld+json">` |
| JSON-LD `FAQPage` (default FAQs + per-product) | Same logic, same fields |
| Product meta (`product:price:amount`, `currency`, `availability`) | Identical meta in `generateMetadata` |
| `aggregateRating`, `review`, `shippingDetails`, `hasMerchantReturnPolicy`, GTIN, MPN, SKU, condition schema URLs | Replicated in a shared `productSchema(product)` util |
| `Breadcrumbs.astro` → breadcrumb JSON-LD | Replicate as `<Breadcrumbs>` component + `BreadcrumbList` JSON-LD |
| `astro.config.mjs` sitemap serialization logic for priorities (lines 174–212) + Cyrillic decode + image-sitemap injection (lines 218–257) | Custom `app/sitemap.xml/route.ts` that queries Payload and reproduces the same `<url><loc>` entries, priority/map by path rules, plus `<image:image>` for blog posts |
| `src/pages/rss.xml.ts` | `app/rss.xml/route.ts` with same channel/items |
| 404 page | Custom `not-found.tsx` with same content + noindex |
| `.json/` structured-data snippets (if any) | Port to static JSON in `public/` |
| `robots.txt` | Static file or route with same rules |

## 6. Migration Scripts (`scripts/migrate/`)

1. **`01-import-articles.ts`** — Reads `src/content/blog/*.md*`, emits Payload `blog` docs:
   - frontmatter → top-level fields
   - body (`remark` tree) → Lexical JSON, custom blocks for shortcodes
   - Price placeholders `{{price:slug}}` → resolved at render via `ProductPriceBlock` server component
2. **`02-import-store.ts`** — Same for `src/content/store/`.
3. **`03-import-info-pages.ts`** — Read `src/content/info/` + `src/content/pages/` + `src/content/homepage/` + `src/content/contact/`.
4. **`04-import-taxonomy.ts`** — Derive `categories`/`tags` union from existing `categories`/`tags` arrays in blog frontmatter (today they're arbitrary strings) and create Category/Tag docs; patch references back into blog docs.
5. **`05-assets-sync.ts`** — Walk `public/images/**` (and any inline image refs in markdown), copy to Payload local upload path, link via upload field where applicable. Preserve `/images/...` URL paths — they keep serving from Next.js `public/` folder OR Payload uploads collection with path-prefix policy that keeps `/images/` prefix intact.
6. **`06-redirect-map.ts`** — Emit `redirects.json` for Next.js `redirects()` plus Payload `redirects` collection (loaded at runtime).

Run scripts against a local Postgres (Docker) first; verify against the audit contract (`scripts/audit-urls.mjs` output).

## 7. Migration Verification Checklist

Before DNS switch:
- Run `scripts/audit-urls.mjs` against the new site (after build). Diff must be empty — same count of URLs, every URL byte-identical.
- HTTP smoke-test: HEAD each URL on staging (`plesk-staging.hb.by`), record `status`, `final URL`, `canonical`, `og:url`, `X-Robots-Tag`. Compare with production capture.
- Run `curl` on every `<url>` from the old sitemap against the new site; every one must 200 with `<link rel="canonical">` matching the production canonical.
- Run Schema.org validator on 10 sample product pages + 10 sample blog posts; rich results parity.
- Generate new `sitemap.xml` on staging and diff against production sitemap (after Cyrillic-normalizing both). Every `<loc>` must appear identically.
- Diff `<title>`, `<meta name="description">`, `<meta property="og:title">`, `<meta property="og:description">`, `<meta property="og:image">`, `<link rel="canonical">` for each URL.
- Lighthouse SEO category on 20 representative URLs ≥ current scores.
- Confirm `rss.xml` byte-equivalent (channel metadata + item count + item order).

## 8. Cut-over Sequence

1. Freeze articles/products in current Astro admin (no new posts/edits) — declare content cut-off datetime.
2. Run migration scripts against staging Postgres.
3. Run audit diff against staging. Fix until zero diffs.
4. Submit staging sitemap to GSC as a temporary test property? **Don't** — instead:
5. On cut-over day:
   - Re-run migration in production with `--write` flag.
   - Switch Plesk Node app from Astro `dist` server to Next standalone `.next/standalone` process.
   - Repoint domain A record only if DNS changes (likely not — same Plesk host). Mostly Node app swap.
   - Submit new sitemap to Google Search Console.
6. Monitor GSC for 14 days: Coverage report, Index, Core Web Vitals, mobile usability. Watch for non-301 "Excluded by noindex" spike, "Duplicate without canonical", "Page with redirect", "Soft 404".
7. Check Analytics for traffic drop / bounce on top pages.
8. Keep old redirect map live (Payload `redirects` collection) indefinitely for any deep-link drift (RSS readers, cached Google search).

## 9. Backups & Rollback Plan

- Daily Postgres dumps on Plesk (Plesk Backup Manager per-domain, plus a `pg_dump` cron to volumes).
- Nightly Next standalone + `public/` snapshot.
- Rollback switch path: keep the old Astro `dist/` server entry on a hot-standby Node app on a different port for 14 days; flip Plesk proxy back if metrics degrade > 10%.

## 10. Milestones & Effort

| # | Milestone | Est. |
|---|---|---|
| 1 | Inventory/URL audit + freeze content | 0.5d |
| 2 | Scaffold Next repo (embedded Payload, Postgres, Plesk-compatible next.config) | 1d |
| 3 | Payload collections + admin UI matching current schema; SEO plugin | 1.5d |
| 4 | Lexical rich-text with custom shortcode blocks + MDX importer | 2d |
| 5 | Next.js routes + generateMetadata parity + JSON-LD parity | 2d |
| 6 | Migration scripts for content + assets | 1.5d |
| 7 | Sitemap + RSS + robots parity (including image-sitemap + Cyrillic handling) | 0.5d |
| 8 | Cart + checkout parity (port `src/lib/cart.ts`) | 0.5d |
| 9 | Audit diff tool + verification checklist | 0.5d |
| 10 | Staging deploy on Plesk; dry-run cut-over; metrics dry-run | 0.5d |
| 11 | Production cut-over + 14-day monitoring | 1d + 14d watch |

## 11. Open Risks / Things to Confirm Before Coding

1. **Plesk hb.by Node capabilities**: confirm Plesk Node extension supports Next 15 standalone (`node server.js`) with persistent user-writable volume for `/uploads`. If only ephemeral disk is available, we **must** switch uploads to S3/R2 — local disk won't survive restarts.
2. **Postgres on Plesk hb.by**: confirm PostgreSQL is offered (it usually is, often on a separate port). Otherwise we fall back to managed external Postgres (Neon, Supabase) or SQLite.
3. **Cart persistence**: the current cart lives in the browser (`src/lib/cart`). Confirm we keep client-side storage and don't need server-side cart.
4. **Search & Disqus**: both currently disabled — confirm we leave them off.
5. **GTM**: form action and `google_tag_manager` are currently `false` / `#`. Confirm whether to keep disabled or wire up real GTM.

Once those five items are answered, the next step is to lay out the repo directory tree and the first code commit (Payload schema + next config).