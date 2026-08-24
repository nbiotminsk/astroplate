# Astroplate Project Context

Это проект на базе **Astro 6**, представляющий собой современный сайт-каталог и блог для компании «Телеофис» (teleofis24.by), специализирующейся на системах дистанционного учёта воды и тепла в Минске.

## 🚀 Технологический стек

- **Framework:** [Astro 6](https://astro.build/) (Static Site Generation)
- **UI Library:** React 19 (используется для интерактивных компонентов: корзина, модальные окна, поиск)
- **Styling:** Tailwind CSS 4 (с использованием `@tailwindcss/vite`)
- **Language:** TypeScript
- **Content:** Markdown и MDX (управляется через Astro Content Collections)
- **CMS:** Sitepins (Git-based Headless CMS)
- **Deployment:** Netlify / Cloudflare Workers / Docker

## 📂 Структура проекта

- `src/content/`: Основное хранилище контента.
  - `blog/`: Статьи блога.
  - `store/`: Каталог товаров и услуг (счетчики, модули NBIoT, услуги поверки/замены).
  - `homepage/`: Конфигурация главной страницы (баннеры, фичи, FAQ).
  - `pages/`, `contact/`, `info/`: Служебные и информационные страницы.
- `src/layouts/`: Шаблоны страниц.
  - `Base.astro`: Основной макет с `<head>`, Header и Footer.
  - `PostSingle.astro`: Шаблон для отдельных статей блога.
- `src/pages/`: Роутинг проекта.
  - `store/[slug].astro`: Динамические страницы товаров.
  - `blog/[single].astro`: Динамические страницы блога.
- `src/config/`: Конфигурационные JSON-файлы (меню, тема, метаданные сайта).
- `scripts/`: Вспомогательные скрипты для генерации тем и обработки JSON.

## 🛠 Ключевые команды

- `npm run dev`: Запуск сервера разработки с автоматической генерацией темы и JSON-индексов.
- `npm run build`: Сборка статического сайта.
- `npm run check`: Проверка типов и корректности Astro-файлов.
- `npm run format`: Форматирование кода через Prettier.
- `npm run generate-json`: Ручная генерация вспомогательных JSON-файлов для поиска и контента.

## 📝 Стандарты и конвенции

- **Микроразметка (SEO):** Все значимые страницы используют JSON-LD. Для этого предназначен компонент `src/layouts/components/JsonLd.astro`. Основные сущности: `Organization`, `LocalBusiness`, `Product`, `BlogPosting`, `BreadcrumbList`, `FAQPage`.
- **Изображения:** Используется компонент `ImageMod.astro` для оптимизации (WebP, lazy loading). Изображения хранятся в `public/images/`.
- **Контент:** Поля Frontmatter в Markdown-файлах строго типизированы в `src/content.config.ts`.
- **Компоненты:** Приоритет отдается Astro-компонентам. React используется только там, где необходима сложная интерактивность на стороне клиента (острова интерактивности).

## 💡 Важные особенности

- **Синхронизация с Sitepins:** Конфигурация проекта в `.sitepins/` позволяет визуально редактировать контент и настройки темы.
- **Интеграция с эквайрингом и API:** В проекте присутствуют PHP-скрипты в `public/api/` для работы с ЕРИП (Epos) и Telegram-ботом.
- **Правило деплоя API:** При изменении файлов в `public/api/` пушить в репозиторий (срабатывает Fast Deploy в ветку `deploy` без пересборки сайта) и обязательно вызывать вебхук хостинга Plesk: `POST https://by4010.hb.by:8443/modules/git/public/web-hook.php?uuid=545844a5-6f56-2a7d-37aa-0a92eb58d127`
- **База данных MySQL / MariaDB (teleofis24.by):**
  - СУБД: MariaDB 11.4.12
  - Имя БД: `teleofis_24`
  - Сервер БД: `localhost:3306` (локально для PHP на сервере) / `by4010.hb.by:3306` (удалённо)
  - Пользователь: `teleofis`
  - Пароль: `Y@W8vsydVwv@bn44`
  - Доступ: разрешены удалённые подключения с любого IP.
- **Генерация темы:** Файл `src/styles/generated-theme.css` создается автоматически скриптом на основе `src/config/theme.json`. Не редактируйте его вручную.
