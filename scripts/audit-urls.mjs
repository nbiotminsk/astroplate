#!/usr/bin/env node
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import matter from "gray-matter";
import { slug } from "github-slugger";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, "..");
const CONTENT_DIR = path.join(ROOT, "src/content");
const PAGES_DIR = path.join(ROOT, "src/pages");
const PUBLIC_DIR = path.join(ROOT, "public");
const OUT_DIR = path.join(ROOT, "docs/audit");
fs.mkdirSync(OUT_DIR, { recursive: true });

const config = JSON.parse(
  fs.readFileSync(path.join(ROOT, "src/config/config.json"), "utf8"),
);
const BASE_URL = config.site.base_url.replace(/\/$/, "");
const PAGINATION = config.settings.pagination ?? 2;

function readContent(collection) {
  const dir = path.join(CONTENT_DIR, collection);
  if (!fs.existsSync(dir)) return [];
  const out = [];
  for (const file of fs.readdirSync(dir)) {
    if (!/\.(md|mdx)$/.test(file)) continue;
    const raw = fs.readFileSync(path.join(dir, file), "utf8");
    const { data, content } = matter(raw);
    const id = file.replace(/\.(md|mdx)$/, "");
    out.push({ id, file, data, content });
  }
  return out;
}

const slugify = (s) => slug(String(s ?? ""));

function listPublicFiles() {
  const files = [];
  function walk(dir, rel = "") {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      const r = rel ? `${rel}/${entry.name}` : entry.name;
      if (entry.isDirectory()) walk(full, r);
      else files.push("/" + r.replace(/^\/+/, ""));
    }
  }
  walk(PUBLIC_DIR);
  return files;
}

function extractBodyImages(content) {
  const set = new Set();
  const re = /!\[[^\]]*\]\(([^)\s]+)\)|\]\((\/[^)]+\.(?:png|jpe?g|webp|gif|svg|avif))\)/gi;
  let m;
  while ((m = re.exec(content))) {
    const ref = m[1] || m[2];
    if (ref) set.add(ref);
  }
  return [...set];
}

function extractFrontmatterImage(data) {
  const set = new Set();
  for (const k of ["image", "img", "alt_img", "favicon", "logo", "meta_image"]) {
    if (typeof data[k] === "string") set.add(data[k]);
  }
  return [...set];
}

const blog = readContent("blog");
const store = readContent("store");
const pages = readContent("pages");
const info = readContent("info");
const contact = readContent("contact");
const homepage = readContent("homepage");

const visibleBlog = blog.filter((p) => !p.data.draft && !p.id.startsWith("-"));
const visibleStore = store.filter((p) => !p.data.draft);
const visibleInfo = info.filter((p) => !p.data.draft);
const visiblePages = pages.filter((p) => !p.data.draft && !p.id.startsWith("-"));

const urls = {
  page: ["/", "/services/", "/solutions/", "/contact/", "/cart/", "/404/"],
  blog: ["/blog/", ...visibleBlog.map((p) => `/blog/${p.id}/`)],
  blogPagination: [],
  store: ["/store/", ...visibleStore.map((p) => `/store/${p.id}/`)],
  info: ["/info/", ...visibleInfo.map((p) => `/info/${p.id}/`)],
  pages: visiblePages.map((p) => `/${p.id}/`),
  categories: ["/categories/"],
  tags: ["/tags/"],
  static: ["/rss.xml", "/sitemap.xml", "/robots.txt"],
};

const totalPages = Math.ceil(visibleBlog.length / PAGINATION);
for (let n = 2; n <= totalPages; n++) {
  urls.blogPagination.push(`/blog/page/${n}/`);
}

const categories = new Set();
const tags = new Set();
for (const p of visibleBlog) {
  (p.data.categories || []).forEach((c) => categories.add(slugify(c)));
  (p.data.tags || []).forEach((t) => tags.add(slugify(t)));
}
[...categories].forEach((c) => urls.categories.push(`/categories/${c}/`));
[...tags].forEach((t) => urls.tags.push(`/tags/${t}/`));

const flatUrls = Object.values(urls).flat();

const frontmatter = {
  blog: Object.fromEntries(
    blog.map((p) => [p.id, { file: p.file, ...p.data }]),
  ),
  store: Object.fromEntries(
    store.map((p) => [p.id, { file: p.file, ...p.data }]),
  ),
  pages: Object.fromEntries(
    pages.map((p) => [p.id, { file: p.file, ...p.data }]),
  ),
  info: Object.fromEntries(
    info.map((p) => [p.id, { file: p.file, ...p.data }]),
  ),
  contact: Object.fromEntries(
    contact.map((p) => [p.id, { file: p.file, ...p.data }]),
  ),
  homepage: Object.fromEntries(
    homepage.map((p) => [p.id, { file: p.file, ...p.data }]),
  ),
};

const publicFiles = listPublicFiles();
const referencedAssets = new Set();
for (const list of [blog, store, pages, info, contact, homepage]) {
  for (const p of list) {
    extractFrontmatterImage(p.data).forEach((s) => {
      if (s) referencedAssets.add(s);
    });
    extractBodyImages(p.content).forEach((s) => referencedAssets.add(s));
  }
}

const assets = {
  publicFiles,
  referenced: [...referencedAssets].sort(),
  missing: [...referencedAssets]
    .filter((ref) => ref.startsWith("/") && !publicFiles.includes(ref))
    .sort(),
};

const redirects = { sources: {}, entries: [] };
const redirectSources = [
  "netlify.toml",
  "public/_redirects",
  "wrangler.jsonc",
];
for (const rel of redirectSources) {
  const full = path.join(ROOT, rel);
  if (fs.existsSync(full)) {
    const text = fs.readFileSync(full, "utf8");
    redirects.sources[rel] = text.slice(0, 20000);
  }
}
const vercelDir = path.join(ROOT, ".vercel");
if (fs.existsSync(vercelDir)) {
  redirects.sources[".vercel"] = fs
    .readdirSync(vercelDir)
    .map((f) => `[${f}]`)
    .join(", ");
}

function write(name, obj) {
  fs.writeFileSync(
    path.join(OUT_DIR, name),
    JSON.stringify(obj, null, 2),
    "utf8",
  );
  console.log(`  written ${name}`);
}

write("urls.json", {
  base_url: BASE_URL,
  trailing_slash: true,
  totals: Object.fromEntries(
    Object.entries(urls).map(([k, v]) => [k, v.length]),
  ),
  urls,
  flat: flatUrls,
});
write("frontmatter.json", frontmatter);
write("assets.json", assets);
write("redirects.json", redirects);

const manifest = {
  base_url: BASE_URL,
  generated_at: new Date().toISOString(),
  counts: {
    blog: blog.length,
    blogVisible: visibleBlog.length,
    store: store.length,
    storeVisible: visibleStore.length,
    info: info.length,
    infoVisible: visibleInfo.length,
    pages: pages.length,
    pagesVisible: visiblePages.length,
    categories: categories.size,
    tags: tags.size,
    publicFiles: publicFiles.length,
    totalUrls: flatUrls.length,
  },
};
write("manifest.json", manifest);

console.log(
  `\nAudit complete: ${flatUrls.length} URLs, ${publicFiles.length} public files, ${assets.missing.length} missing refs.`,
);
console.log(`Output: ${OUT_DIR}`);