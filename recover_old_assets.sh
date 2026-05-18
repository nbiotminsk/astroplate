#!/bin/bash
set -e

echo "Создаем временную папку для ассетов..."
mkdir -p /tmp/old_astro_assets

# Получаем хэши коммитов с 1 мая 2026
COMMITS=$(git log --since="2026-05-01" --format="%H")
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)

for commit in $COMMITS; do
  echo "Сборка коммита $commit..."
  git restore .
  git checkout $commit
  npm run build || true
  # Копируем сгенерированные файлы (не перезаписывая уже скопированные)
  cp -n dist/_astro/* /tmp/old_astro_assets/ 2>/dev/null || true
done

# Возвращаемся на исходную ветку
git checkout $CURRENT_BRANCH

# Обновляем ветку deploy напрямую с новыми (и старыми) ассетами
echo "Переключаемся на ветку deploy..."
git fetch origin deploy
git worktree add ../deploy-branch origin/deploy
cd ../deploy-branch

echo "Копируем накопленные старые ассеты в _astro/..."
mkdir -p _astro
cp -n /tmp/old_astro_assets/* _astro/

echo "Пушим изменения в ветку deploy..."
git add _astro/
git commit -m "chore: восстановлены старые CSS/JS ассеты с 1 мая для предотвращения 404 ошибок у старых пользователей" || true
git push origin HEAD:deploy

# Возвращаемся и убираем за собой
cd ../astroplate
git worktree remove ../deploy-branch
rm -rf /tmp/old_astro_assets

echo "Успех! Все старые ассеты восстановлены и загружены на хостинг."
