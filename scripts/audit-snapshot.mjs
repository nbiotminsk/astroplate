#!/usr/bin/env node
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, "..");
const DIST = path.join(ROOT, "dist");
const OUT = path.join(ROOT, "docs/audit");
fs.mkdirSync(OUT, { recursive: true });

function walk(dir, rel = "") {
  const out = [];
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const r = rel ? `${rel}/${e.name}` : e.name;
    const full = path.join(dir, e.name);
    if (e.isDirectory()) out.push(...walk(full, r));
    else out.push("/" + r);
  }
  return out;
}

const distTree = fs.existsSync(DIST) ? walk(DIST) : [];

const sitemaps = {};
const decodedUrls = [];
for (const f of ["sitemap-0.xml", "sitemap-index.xml"]) {
  const p = path.join(DIST, f);
  if (!fs.existsSync(p)) continue;
  const text = fs.readFileSync(p, "utf8");
  sitemaps[f] = text;
  let m;
  const re = /<loc>([^<]+)<\/loc>/g;
  while ((m = re.exec(text))) {
    let url = m[1];
    if (/%[0-9A-F]{2}/i.test(url)) {
      try {
        url = decodeURIComponent(url);
      } catch {}
    }
    decodedUrls.push(url);
  }
}

// parsed url + (priority if present) — naive: also extract surrounding <priority>
const parsedSitemap = [];
{
  const text = sitemaps["sitemap-0.xml"] || "";
  const urlRe =
    /<url>([\s\S]*?)<\/url>/g;
  let m;
  while ((m = urlRe.exec(text))) {
    const block = m[1];
    const loc = block.match(/<loc>([^<]+)<\/loc>/);
    const pri = block.match(/<priority>([^<]+)<\/priority>/);
    const cf = block.match(/<changefreq>([^<]+)<\/changefreq>/);
    const images = [...block.matchAll(/<image:image>[\s\S]*?<image:loc>([^<]+)<\/image:loc>[\s\S]*?<\/image:image>/g)].map(
      (x) => x[1],
    );
    if (!loc) continue;
    let locU = loc[1];
    if (/%[0-9A-F]{2}/i.test(locU)) {
      try {
        locU = decodeURIComponent(locU);
      } catch {}
    }
    parsedSitemap.push({
      loc: locU,
      priority: pri ? pri[1] : null,
      changefreq: cf ? cf[1] : null,
      images,
    });
  }
}

fs.writeFileSync(
  path.join(OUT, "dist-tree.json"),
  JSON.stringify(distTree, null, 2),
);
fs.writeFileSync(
  path.join(OUT, "production-sitemap.json"),
  JSON.stringify(
    { rawIndex: sitemaps["sitemap-index.xml"] || null, parsedSitemap },
    null,
    2,
  ),
);
fs.writeFileSync(
  path.join(OUT, "production-sitemap-locs.json"),
  JSON.stringify(parsedSitemap.map((e) => e.loc), null, 2),
);
console.log(
  `dist: ${distTree.length} files | sitemap entries: ${parsedSitemap.length} | images-with-image: ${
    parsedSitemap.filter((e) => e.images.length).length
  }`,
);