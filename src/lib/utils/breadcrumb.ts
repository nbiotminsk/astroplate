import { humanize } from "./textConverter";
import config from "../../config/config.json";

export const getBreadcrumbs = (pathname: string, title?: string) => {
  const labelMap: Record<string, string> = {
    blog: "Блог",
    tags: "Теги",
    categories: "Категории",
    solutions: "Решения",
    services: "Услуги",
    contact: "Контакты",
    store: "Магазин",
    cart: "Корзина",
    info: "Информация",
    "delivery-payment": "Доставка и оплата",
    "404": "404",
  };

  const paths = pathname.split("/").filter((x) => x);
  let parts = [
    {
      label: "Главная",
      href: "/",
    },
  ];

  paths.forEach((label, i) => {
    const href = `/${paths.slice(0, i + 1).join("/")}`;
    const normalizedLabel = label.replace(".html", "").replace(/[-_]/g, " ");
    const translatedLabel =
      i === paths.length - 1 && title
        ? title
        : labelMap[label] || humanize(normalizedLabel) || "";

    // if path is root and not in labelMap, it's likely a service page, so add "Services" before it
    if (paths.length === 1 && !labelMap[label]) {
      parts.push({
        label: labelMap["services"],
        href: "/services",
      });
    }

    parts.push({
      label: translatedLabel,
      href,
    });
  });

  return parts;
};

export const getBreadcrumbSchema = (
  parts: { label: string; href: string }[],
) => {
  const siteUrl = config.site.base_url.replace(/\/$/, "");
  return {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: parts.map((part, index) => ({
      "@type": "ListItem",
      position: index + 1,
      name: part.label,
      item: part.href.startsWith("http") ? part.href : `${siteUrl}${part.href}`,
    })),
  };
};
