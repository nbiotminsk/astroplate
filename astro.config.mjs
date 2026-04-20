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

// https://astro.build/config
export default defineConfig({
  site: config.site.base_url ? config.site.base_url : "http://examplesite.com",
  base: config.site.base_path ? config.site.base_path : "/",
  trailingSlash: config.site.trailing_slash ? "always" : "never",
  image: { service: sharp() },
  vite: { plugins: [tailwindcss()] },
  fonts: fontsConfig,
  integrations: [
    react(),
    sitemap({
      serialize(item) {
        if (/%[0-9A-F]{2}/i.test(item.url)) {
          item.url = decodeURIComponent(item.url);
        }
        return item;
      },
    }),
    {
      name: "decode-sitemap",
      hooks: {
        "astro:build:done": async ({ dir }) => {
          const distDir = fileURLToPath(dir);
          const files = fs.readdirSync(distDir);
          for (const file of files) {
            if (file.startsWith("sitemap") && file.endsWith(".xml")) {
              const filePath = path.join(distDir, file);
              let content = fs.readFileSync(filePath, "utf-8");
              if (/%D[01]%[0-9A-F]{2}/i.test(content)) {
                content = content.replace(
                  /(%D0%[0-9A-F]{2}|%D1%[0-9A-F]{2})/gi,
                  (match) => decodeURIComponent(match),
                );
                fs.writeFileSync(filePath, content);
              }
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
    remarkPlugins: [remarkToc, [remarkCollapse, { test: "Table of contents" }]],
    shikiConfig: { theme: "one-dark-pro", wrap: true },
  },
});
