import rss from "@astrojs/rss";
import { getCollection } from "astro:content";
import type { APIContext } from "astro";
import config from "@/config/config.json";

export async function GET(context: APIContext) {
  const posts = (await getCollection("blog"))
    .filter((post) => !post.data.draft && !post.id.startsWith("-"))
    .sort(
      (a, b) => new Date(b.data.date).valueOf() - new Date(a.data.date).valueOf(),
    );

  return rss({
    title: "teleofis24.by — Блог",
    description:
      "Статьи о дистанционном учёте воды, NB-IoT и оборудовании Teleofis в Беларуси",
    site: context.site ?? config.site.base_url,
    items: posts.map((post) => ({
      title: post.data.title,
      description: post.data.description,
      pubDate: new Date(post.data.date),
      link: `/blog/${post.id}/`,
      categories: post.data.categories,
    })),
    customData: `<language>ru-ru</language>`,
  });
}
