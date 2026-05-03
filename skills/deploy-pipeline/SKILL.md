---
name: deploy-pipeline
description: >
  Полный цикл проверки и деплоя проекта: code-cleaner → сборка → git push → мониторинг
  GitHub Actions → проверка deploy-ветки. Используй для подготовки и отправки стабильной
  версии в продакшен. НЕ используй, если нужно только проверить ошибки без деплоя
  (для этого есть code-cleaner).
license: Apache-2.0
metadata:
  version: "1.0"
  author: "Antigravity"
  depends_on: "code-cleaner"
---

# Deploy Pipeline Agent

Агент для полного цикла: от проверки качества кода до деплоя. Последовательно выполняет проверку, сборку, коммит, пуш и мониторинг CI/CD.

**Принцип:** Не пушить сломанный код. Каждый этап — gate. Не проходит gate → остановка и доклад.

## Поведенческие принципы

### 1. Никаких пушей без прохождения всех gate-ов

Каждый этап ниже — это gate. Если gate не пройден, СТОП. Не пытайся «обойти» проблему. Сообщи пользователю, что именно не прошло и почему.

### 2. Явный статус на каждом этапе

После каждого шага выводи краткий статус:

- ✓ — gate пройден
- ✗ — gate не пройден (+ причина)
- ⏳ — ожидание (для CI/CD)

### 3. Не коммить чужие изменения

Перед коммитом проверь `git status`. Если есть изменения, которые НЕ относятся к текущей задаче — спроси пользователя, включать ли их в коммит.

## Процесс работы

```
1. Code Cleaner      → gate: npm run check без ошибок
2. Форматирование    → gate: prettier --check без замечаний
3. Сборка            → gate: npm run build без ошибок
4. Git commit & push → gate: push в main успешен
5. CI/CD мониторинг  → gate: GitHub Actions workflow завершён успешно
6. Deploy-ветка      → gate: deploy-ветка обновлена с новым коммитом
```

---

### Этап 1. Code Cleaner (проверка ошибок)

Запусти проверки из скилла `code-cleaner`:

```bash
npm run check
```

**Gate:** вывод без ошибок. Warnings допустимы, ошибки — нет.

Если есть ошибки:

- Попробуй исправить, следуя принципам из `skills/code-cleaner/SKILL.md`.
- После исправления перезапусти `npm run check`.
- Если ошибка неясна — СТОП, доложи пользователю.

### Этап 2. Форматирование

```bash
npm run format
npx prettier --check .
```

**Gate:** prettier не находит проблем.

### Этап 3. Сборка

```bash
npm run build
```

Этот скрипт выполняет:

1. `node scripts/themeGenerator.js` — генерация CSS-темы из `theme.json`
2. `node scripts/jsonGenerator.js` — генерация JSON-индексов для поиска
3. `astro build` — сборка статического сайта в `dist/`

**Gate:** сборка завершена без ошибок. Директория `dist/` создана.

Если сборка падает:

- Прочитай ошибку. Чаще всего это: битый frontmatter, несуществующий импорт, ошибка в Astro-компоненте.
- Исправь и вернись на Этап 1 (перепроверка).

### Этап 4. Git commit & push

Перед коммитом:

```bash
git status
git diff --stat
```

Убедись, что изменения относятся к задаче. Затем:

```bash
git add -A
git commit -m "<описательное сообщение>"
git push origin main
```

**Правила коммит-сообщений:**

- Краткое описание на русском или английском.
- Формат: `fix: ...`, `feat: ...`, `chore: ...`, `style: ...`.
- Примеры: `fix: исправлена типизация в ProductCard`, `chore: форматирование и удаление мёртвых импортов`.

**Gate:** `git push` выполнен без ошибок (no conflicts, no rejected).

Если push отклонён:

- Скорее всего, удалённая ветка опережает. Выполни `git pull --rebase origin main`, разреши конфликты если есть, затем push повторно.

### Этап 5. Мониторинг GitHub Actions

После push в `main`, GitHub Actions автоматически запускает workflow `CI/CD Pipeline` (`.github/workflows/deploy.yml`).

Workflow делает:

1. Checkout репозитория
2. Setup Node.js 22 + yarn cache
3. `yarn install --frozen-lockfile`
4. `npm run check`
5. `npm run build`
6. Deploy `dist/` в ветку `deploy` через `peaceiris/actions-gh-pages@v4`

Для мониторинга:

- Открой `https://github.com/nbiotminsk/astroplate/actions` в браузере.
- Или используй GitHub CLI: `gh run list --limit 1 && gh run view <run-id>`.

**Gate:** workflow завершён со статусом `success`.

Если workflow упал:

- Прочитай логи через `gh run view <run-id> --log-failed` или в браузере.
- Чаще всего причины: `yarn.lock` не синхронизирован с `package.json`, ошибка в check/build.
- Исправь локально, вернись на Этап 1.

### Этап 6. Проверка deploy-ветки

После успешного CI/CD проверь, что deploy-ветка обновилась:

```bash
git fetch origin deploy
git log origin/deploy -1 --oneline
```

Коммит-сообщение должно содержать `Deploy: <sha> [skip ci]` с актуальным SHA.

**Gate:** последний коммит в `deploy` соответствует текущему push-у.

## Специфика проекта

- **Менеджер пакетов:** В CI используется `yarn` (`yarn install --frozen-lockfile`). Локально тоже используй `yarn` для установки зависимостей, чтобы `yarn.lock` не расходился.
- **Ветка deploy:** Это orphan-ветка, содержащая только собранный `dist/`. Не мёрджь её в `main`.
- **Файл `.nojekyll`:** Создаётся автоматически в CI. Нужен для корректного хостинга на GitHub Pages.
- **`htaccess.txt → .htaccess`:** Копируется в CI для серверов Apache. Не удаляй `dist/htaccess.txt`.

## Быстрый запуск (чеклист)

Для быстрой ориентации — минимальная последовательность команд:

```bash
# 1. Проверка
npm run check

# 2. Форматирование
npm run format

# 3. Сборка
npm run build

# 4. Коммит и пуш
git add -A
git commit -m "chore: описание изменений"
git push origin main

# 5. Мониторинг CI
# Открой https://github.com/nbiotminsk/astroplate/actions
# или: gh run list --limit 1

# 6. Проверка deploy
git fetch origin deploy
git log origin/deploy -1 --oneline
```

## Признаки успешной работы

- Все 6 gate-ов пройдены с ✓.
- В `main` запушен чистый коммит без лишних изменений.
- GitHub Actions workflow завершился со статусом `success`.
- Ветка `deploy` содержит свежий билд.
- Пользователь получил полный отчёт по каждому этапу.
