import mdx from "@astrojs/mdx";
import react from "@astrojs/react";
import sitemap from "@astrojs/sitemap";
import tailwindcss from "@tailwindcss/vite";
import AutoImport from "astro-auto-import";
import { defineConfig, fontProviders } from "astro/config";
import remarkCollapse from "remark-collapse";
import remarkToc from "remark-toc";
import sharp from "sharp";
import fs from "node:fs";
import path from "node:path";
import matter from "gray-matter";
import { fileURLToPath } from "node:url";
import config from "./src/config/config.json";
import theme from "./src/config/theme.json";

// Helper to parse font string format: "FontName:wght@400;500;600;700"
function parseFontString(fontStr) {
  const [name, weightPart] = fontStr.split(":");
  let weights = [400]; // default weight

  if (weightPart) {
    // Extract weights from wght@400;500;600 format
    const weightMatch = weightPart.match(/wght@?([\d;]+)/);
    if (weightMatch) {
      weights = weightMatch[1].split(";").map((w) => parseInt(w, 10));
    }
  }

  // remove + from font name and add space
  const cleanName = name.replace(/\+/g, " ");
  return { name: cleanName, weights };
}

// Build fonts configuration from theme.json
const fontsConfig = Object.entries(theme.fonts.font_family)
  .filter(([key]) => !key.includes("_type")) // Filter out type entries
  .map(([key, fontStr]) => {
    const { name, weights } = parseFontString(fontStr);
    const typeKey = `${key}_type`;
    const fallback = theme.fonts.font_family[typeKey] || "sans-serif";

    return {
      name,
      cssVariable: `--font-${key}`,
      provider: fontProviders.google(),
      weights,
      display: "swap",
      fallbacks: [fallback],
    };
  });

// Remark plugin to dynamically resolve store item prices in markdown files
function remarkResolvePrices() {
  const storeDir = path.join(path.dirname(fileURLToPath(import.meta.url)), "src/content/store");
  const priceCache = {};

  if (fs.existsSync(storeDir)) {
    const files = fs.readdirSync(storeDir);
    for (const file of files) {
      if (file.endsWith(".md") || file.endsWith(".mdx")) {
        const filePath = path.join(storeDir, file);
        try {
          const content = fs.readFileSync(filePath, "utf-8");
          const parsed = matter(content);
          const price = parsed.data.price;
          const slug = file.replace(/\.(md|mdx)$/, "");
          if (price !== undefined) {
            priceCache[slug] = String(price);
          }
        } catch (e) {
          console.error(`Error parsing price for ${file}:`, e);
        }
      }
    }
  }

  return (tree) => {
    function visit(node) {
      if (node.value && typeof node.value === "string") {
        node.value = node.value.replace(/\{\{price:([a-zA-Z0-9_-]+)\}\}/g, (match, slug) => {
          if (priceCache[slug] !== undefined) {
            return priceCache[slug];
          }
          console.warn(`[remarkResolvePrices] Warning: Price for slug "${slug}" not found in store collection.`);
          return match;
        });
      }

      if (node.children && Array.isArray(node.children)) {
        node.children.forEach(visit);
      }
    }

    visit(tree);
  };
}

// Build blog image cache for image-sitemap (slug -> frontmatter image)
const blogDir = path.join(
  path.dirname(fileURLToPath(import.meta.url)),
  "src/content/blog",
);
const blogImageCache = {};
if (fs.existsSync(blogDir)) {
  for (const file of fs.readdirSync(blogDir)) {
    if (!file.endsWith(".md") && !file.endsWith(".mdx")) continue;
    if (file.startsWith("-")) continue; // skip index page
    const slug = file.replace(/\.(md|mdx)$/, "");
    try {
      const parsed = matter(fs.readFileSync(path.join(blogDir, file), "utf-8"));
      if (parsed.data.image && !parsed.data.draft) {
        blogImageCache[slug] = {
          image: parsed.data.image,
          title: parsed.data.title || slug,
        };
      }
    } catch (e) {
      console.error(`Error parsing blog image for ${file}:`, e);
    }
  }
}

const siteBaseUrl = config.site.base_url.replace(/\/$/, "");

// https://astro.build/config
export default defineConfig({
  site: config.site.base_url ? config.site.base_url : "http://examplesite.com",
  base: config.site.base_path ? config.site.base_path : "/",
  trailingSlash: config.site.trailing_slash ? "always" : "never",
  image: { service: sharp() },
  vite: {
    plugins: [tailwindcss()],
    build: {
      rollupOptions: {
        output: {
          entryFileNames: "_astro/[name].js",
          chunkFileNames: "_astro/[name].js",
          assetFileNames: (assetInfo) => {
            if (assetInfo.name && assetInfo.name.endsWith(".css")) {
              return "_astro/[name][extname]";
            }
            return "_astro/[name].[hash][extname]";
          },
        },
      },
    },
    environments: {
      client: {
        build: {
          rollupOptions: {
            output: {
              entryFileNames: "_astro/[name].js",
              chunkFileNames: "_astro/[name].js",
            },
          },
        },
      },
    },
  },
  fonts: fontsConfig,
  integrations: [
    react(),
    sitemap({
      filter: (page) => {
        const exclude = ["/404", "/cart", "/404.html"];
        return !exclude.some((p) => page.includes(p));
      },
      changefreq: "weekly",
      priority: 0.6,
      serialize(item) {
        // Декодирование кириллицы в URL
        if (/%[0-9A-F]{2}/i.test(item.url)) {
          item.url = decodeURIComponent(item.url);
        }

        const path = item.url.replace(siteBaseUrl, "");

        // Приоритеты по типам страниц
        if (path === "/" || path === "") {
          item.priority = 1.0;
          item.changefreq = "daily";
        } else if (path === "/store/" || path === "/store") {
          item.priority = 0.9;
          item.changefreq = "weekly";
        } else if (path.startsWith("/store/")) {
          item.priority = 0.8;
          item.changefreq = "weekly";
        } else if (path === "/blog/" || path === "/blog") {
          item.priority = 0.8;
          item.changefreq = "daily";
        } else if (path.startsWith("/blog/page/")) {
          item.priority = 0.3;
          item.changefreq = "weekly";
        } else if (path.startsWith("/blog/")) {
          item.priority = 0.7;
          item.changefreq = "monthly";
        } else if (
          path === "/services" ||
          path === "/contact" ||
          path === "/solutions" ||
          path.startsWith("/info/")
        ) {
          item.priority = 0.6;
          item.changefreq = "monthly";
        }

        return item;
      },
    }),
    {
      name: "postprocess-sitemap",
      hooks: {
        "astro:build:done": async ({ dir }) => {
          const distDir = fileURLToPath(dir);
          const files = fs.readdirSync(distDir);
          for (const file of files) {
            if (file.startsWith("sitemap") && file.endsWith(".xml")) {
              const filePath = path.join(distDir, file);
              let content = fs.readFileSync(filePath, "utf-8");
              let modified = false;

              // 1. Декодирование кириллицы в URL
              if (/%D[01]%[0-9A-F]{2}/i.test(content)) {
                content = content.replace(
                  /(%D0%[0-9A-F]{2}|%D1%[0-9A-F]{2})/gi,
                  (match) => decodeURIComponent(match),
                );
                modified = true;
              }

              // 2. Image-sitemap: инжекция <image:image> для blog-постов
              //    (serialize API @astrojs/sitemap не поддерживает images)
              content = content.replace(
                /(<url><loc>https:\/\/[^/]+\/blog\/([^/]+)\/<\/loc>.*?)(<\/url>)/g,
                (match, prefix, slug, closing) => {
                  const entry = blogImageCache[slug];
                  if (!entry) return match;
                  const escXml = (s) =>
                    s
                      .replace(/&/g, "&amp;")
                      .replace(/</g, "&lt;")
                      .replace(/>/g, "&gt;")
                      .replace(/"/g, "&quot;");
                  const imgUrl = escXml(`${siteBaseUrl}${entry.image}`);
                  const imgTitle = escXml(entry.title);
                  modified = true;
                  return `${prefix}<image:image><image:loc>${imgUrl}</image:loc><image:title>${imgTitle}</image:title></image:image>${closing}`;
                },
              );

              if (modified) fs.writeFileSync(filePath, content);
            }
          }
        },
      },
    },
    AutoImport({
      imports: [
        "@/shortcodes/Button",
        "@/shortcodes/Accordion",
        "@/shortcodes/Notice",
        "@/shortcodes/Video",
        "@/shortcodes/Youtube",
        "@/shortcodes/Tabs",
        "@/shortcodes/Tab",
      ],
    }),
    mdx(),
  ],
  markdown: {
    remarkPlugins: [remarkResolvePrices, remarkToc, [remarkCollapse, { test: "Table of contents" }]],
    shikiConfig: { theme: "one-dark-pro", wrap: true },
  },
});
