#!/usr/bin/env sh
# Run all Translation YAML Tools console examples with pauses so you can watch files change.
# Usage (from demo/symfony8): DEMO_ROOT=$PWD PAUSE=3 sh ../scripts/translation-yaml-walkthrough.sh
set -e
: "${DEMO_ROOT:?Set DEMO_ROOT to the demo directory (e.g. symfony8)}"
cd "$DEMO_ROOT" || exit 1

PAUSE="${PAUSE:-3}"
export PAUSE

echo "translation-yaml-walkthrough: pause between steps = ${PAUSE}s (override with PAUSE=5)"
echo ""

docker-compose exec -T -e PAUSE php sh -s <<'EOS'
set -e
cd /app
B="/tmp/tyt-walkthrough-$$"
mkdir -p "$B"
for f in messages.en.yaml messages.es.yaml validators.en.yaml validators.es.yaml; do
  [ -f "translations/$f" ] && cp "translations/$f" "$B/"
done

restore() {
  echo ""
  echo "=== Restoring translations/ from backup ==="
  for f in messages.en.yaml messages.es.yaml validators.en.yaml validators.es.yaml; do
    [ -f "$B/$f" ] && cp "$B/$f" "translations/$f"
  done
  rm -rf "$B"
}

trap restore EXIT

pause() { sleep "$PAUSE"; }

echo "=== [messages] tree --dry-run ==="
php bin/console nowo:translation-yaml:tree --domain=messages --locale=en --dry-run
pause

echo "=== [messages] tree (write nested) ==="
php bin/console nowo:translation-yaml:tree --domain=messages --locale=en
echo ""
echo "----- translations/messages.en.yaml -----"
cat translations/messages.en.yaml
echo ""
pause

echo "=== [messages] sort (block YAML) ==="
php bin/console nowo:translation-yaml:sort --domain=messages --locale=en
echo ""
echo "----- translations/messages.en.yaml -----"
cat translations/messages.en.yaml
echo ""
pause

echo "=== [messages] sort --inline (flow YAML) ==="
php bin/console nowo:translation-yaml:sort --domain=messages --locale=en --inline
echo ""
echo "----- translations/messages.en.yaml -----"
cat translations/messages.en.yaml
echo ""
pause

echo "=== [messages] tree --inline (nested + flow dump) ==="
php bin/console nowo:translation-yaml:tree --domain=messages --locale=en --inline
echo ""
echo "----- translations/messages.en.yaml -----"
cat translations/messages.en.yaml
echo ""
pause

echo "=== [messages] flatten --dry-run (dot keys at root) ==="
php bin/console nowo:translation-yaml:flatten --domain=messages --locale=en --dry-run
pause

echo "=== [messages] flatten (write flat dot keys) ==="
php bin/console nowo:translation-yaml:flatten --domain=messages --locale=en
echo ""
echo "----- translations/messages.en.yaml -----"
cat translations/messages.en.yaml
echo ""
pause

echo "=== [validators] tree --dry-run ==="
php bin/console nowo:translation-yaml:tree --domain=validators --locale=en --dry-run
pause

echo "=== [validators] sort --dry-run ==="
php bin/console nowo:translation-yaml:sort --domain=validators --locale=en --dry-run
pause

echo "=== [validators] sort (write) ==="
php bin/console nowo:translation-yaml:sort --domain=validators --locale=en
echo ""
echo "----- translations/validators.en.yaml -----"
cat translations/validators.en.yaml
echo ""
pause

echo "=== [validators] tree --inline ==="
php bin/console nowo:translation-yaml:tree --domain=validators --locale=en --inline
echo ""
echo "----- translations/validators.en.yaml -----"
cat translations/validators.en.yaml
echo ""
pause

echo "=== [validators] flatten (dot keys at root) ==="
php bin/console nowo:translation-yaml:flatten --domain=validators --locale=en
echo ""
echo "----- translations/validators.en.yaml -----"
cat translations/validators.en.yaml
echo ""
pause

echo "=== fill-missing messages -> es --dry-run ==="
php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=es --dry-run
pause

echo "=== fill-missing messages -> es --dry-run --tree ==="
php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=es --dry-run --tree
pause

echo "=== fill-missing messages -> es --dry-run --tree --inline (no API; dry-run only) ==="
php bin/console nowo:translation-yaml:fill-missing --domain=messages --target-locale=es --dry-run --tree --inline
pause

echo "=== Done. Restoring original YAML files ==="
trap - EXIT
restore
EOS

echo ""
echo "Walkthrough finished; translations/ restored."
