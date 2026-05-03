#!/bin/bash
set -e

echo "=== Code Health Check ==="
echo ""

echo "1/3 TypeScript & Astro checks..."
if npm run check 2>&1; then
  echo "✓ TypeScript & Astro: OK"
else
  echo "✗ TypeScript & Astro: ERRORS FOUND"
  exit 1
fi

echo ""
echo "2/3 Prettier formatting..."
if npx prettier --check . 2>&1; then
  echo "✓ Formatting: OK"
else
  echo "✗ Formatting: ISSUES FOUND (run 'npm run format' to fix)"
fi

echo ""
echo "3/3 Build test..."
if npm run build 2>&1; then
  echo "✓ Build: OK"
else
  echo "✗ Build: FAILED"
  exit 1
fi

echo ""
echo "=== Health check complete ==="
