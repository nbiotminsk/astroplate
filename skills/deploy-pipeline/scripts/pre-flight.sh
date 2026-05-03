#!/bin/bash
set -e

echo "=== Deploy Pipeline Pre-flight ==="
echo ""

FAILED=0

# Gate 1: TypeScript & Astro
echo "Gate 1/4: TypeScript & Astro checks..."
if npm run check 2>&1; then
  echo "  ✓ Check: PASSED"
else
  echo "  ✗ Check: FAILED"
  FAILED=1
fi

echo ""

# Gate 2: Formatting
echo "Gate 2/4: Prettier formatting..."
if npx prettier --check . 2>&1; then
  echo "  ✓ Formatting: PASSED"
else
  echo "  ✗ Formatting: FAILED (run 'npm run format' to fix)"
  FAILED=1
fi

echo ""

# Gate 3: Build
echo "Gate 3/4: Build..."
if npm run build 2>&1; then
  echo "  ✓ Build: PASSED"
else
  echo "  ✗ Build: FAILED"
  FAILED=1
fi

echo ""

# Gate 4: Git status
echo "Gate 4/4: Git status..."
if git diff --quiet HEAD 2>/dev/null; then
  echo "  ✓ Working tree: CLEAN (nothing to commit)"
else
  CHANGES=$(git diff --stat HEAD 2>/dev/null | tail -1)
  echo "  ⚠ Uncommitted changes: $CHANGES"
fi

echo ""
echo "=== Pre-flight complete ==="

if [ $FAILED -ne 0 ]; then
  echo "Result: ✗ BLOCKED — fix errors before pushing"
  exit 1
else
  echo "Result: ✓ READY TO PUSH"
fi
