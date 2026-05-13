#!/bin/bash
set -u

status=0
prettier_targets=(
  "src/**/*.{astro,ts,tsx,js,jsx,json,md,mdx,css}"
  "!src/styles/generated-theme.css"
)

echo "=== Code Health Check ==="
echo ""

echo "1/3 TypeScript & Astro checks..."
if npm run check 2>&1; then
  echo "✓ TypeScript & Astro: OK"
else
  echo "✗ TypeScript & Astro: ERRORS FOUND"
  status=1
fi

echo ""
echo "2/3 Prettier formatting..."
if npx prettier --check "${prettier_targets[@]}" 2>&1; then
  echo "✓ Formatting: OK"
else
  echo "✗ Formatting: ISSUES FOUND (format the editable source files; do not edit generated-theme.css)"
  status=1
fi

echo ""
echo "3/3 Build test..."
if npm run build 2>&1; then
  echo "✓ Build: OK"
else
  echo "✗ Build: FAILED"
  status=1
fi

echo ""
echo "=== Health check complete ==="

exit "$status"
