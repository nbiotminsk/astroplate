---
name: seo
description: Комплексная SEO-оптимизация: от написания контента до технической реализации мета-тегов с использованием astro-seo.
license: Apache-2.0
metadata:
  version: "2.0"
  target_uniqueness: "85%"
  min_length: 2000
  max_length: 3000
  keyword_density_max: "3%"
  tone_of_voice: "Профессиональный, экспертный, ориентированный на B2B и B2C сегменты (Телеофис, системы учета воды и тепла)."
---

# SEO Specialist Agent (Content & Technical)

Этот агент объединяет навыки создания качественного контента и технической SEO-оптимизации для проектов на Astro. Основной фокус — ниша систем дистанционного учета воды и тепла в Минске (teleofis24.by).

## 1. Контентная стратегия и написание

### Анализ темы и конкурентов

Используйте инструменты поиска для анализа ТОП-10 ресурсов. Обратите внимание на:

- Структуру заголовков (H1-H4).
- Ключевые слова и LSI-фразы.
- Наличие списков, таблиц и блоков FAQ.

### Структурирование и написание

- **H1**: Один на страницу, содержит главное ключевое слово.
- **H2-H3**: Логическая разбивка текста.
- **Абзацы**: Короткие (3-5 предложений).
- **Списки**: Обязательны для улучшения читаемости.
- **Объем**: 2000–3000 символов (статьи), 1000–2000 (товары).

### Формат (Astro MDX/Markdown)

Генерируйте файлы с корректным Frontmatter:

- **Блог (`src/content/blog/`)**: `title`, `meta_title`, `description`, `date`, `image`, `categories`, `tags`.
- **Магазин (`src/content/store/`)**: `title`, `category`, `price`, `image`, `brand`, `short_description`, `description`, `features`, `faq`.

---

## 2. Техническая SEO-оптимизация (`astro-seo`)

Для управления мета-тегами в шаблонах (`Base.astro`) используйте библиотеку `astro-seo`.

### Базовое использование

Компонент `<SEO>` должен располагаться в секции `<head>`.

```astro
---
import { SEO } from "astro-seo";
---

<SEO
  title="Заголовок страницы"
  description="Описание для поисковых систем"
  canonical="https://teleofis24.by/page-url"
/>
```

### Шаблоны заголовков

Используйте `titleTemplate` для единообразия на всем сайте.

```astro
<SEO
  title="Заголовок статьи"
  titleTemplate="%s | Телеофис"
  titleDefault="Телеофис - Учет воды и тепла"
/>
```

### Open Graph и Twitter Cards

Настраивайте превью для соцсетей:

```astro
<SEO
  openGraph={{
    basic: {
      title: "Заголовок для соцсетей",
      type: "article",
      image: "https://teleofis24.by/images/og-image.jpg",
    },
    optional: {
      siteName: "Teleofis24",
      description: "Краткое описание для соцсетей",
    },
  }}
  twitter={{
    card: "summary_large_image",
    site: "@teleofis",
    title: "Заголовок для Twitter",
    image: "https://teleofis24.by/images/twitter-card.jpg",
  }}
/>
```

### Robots и индексация

Управляйте поведением роботов через пропсы:

```astro
<SEO
  noindex={true}
  nofollow={true}
  robotsExtras="max-snippet:-1, max-image-preview:large"
/>
```

### Расширенные теги (Extend)

Для кастомных линков (фавиконы, манифесты) используйте `extend`:

```astro
<SEO
  extend={{
    link: [{ rel: "icon", href: "/favicon.ico" }],
    meta: [{ name: "theme-color", content: "#ffffff" }],
  }}
/>
```

---

## 3. Финальный чек-лист

1. **ЧПУ (Slug)**: Имя файла на латинице, короткое и понятное.
2. **Микроразметка**: Используйте `JsonLd.astro` для `Organization`, `Product`, `BlogPosting`.
3. **Alt-тексты**: Все изображения должны иметь описательные `alt`.
4. **Внутренняя перелинковка**: Ссылки на релевантные товары/статьи.
5. **Валидация**: Проверка мета-тегов через инструменты разработчика или SEO-плагины.

## Ограничения

- Избегайте "воды" и переспама ключами.
- Всегда проверяйте соответствие `astro-seo` пропсов текущей версии библиотеки.
