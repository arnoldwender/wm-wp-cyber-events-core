#!/usr/bin/env bash
#
# tests/validate-invoice-xml.sh — validates the CII XML of invoices and credit notes against the
# EN 16931 schematron with Mustang-CLI (https://www.mustangproject.org), the open-source ZUGFeRD /
# Factur-X / XRechnung validator.
#
# The XML is produced by the plugin's own code (tests/generate-invoice-samples.php). Mustang-CLI is
# downloaded from Maven Central once and checked against a pinned SHA-256; a jar with another hash is
# never run. Requires Java 11 or later.
#
# Usage: bash tests/validate-invoice-xml.sh
#        MUSTANG_DIR=/path/to/cache bash tests/validate-invoice-xml.sh
# Exit:  0 when every file is reported valid, 1 otherwise, 2 on a setup problem.
#
# Baseline measured 2026-09-15 on the XML before the correction: 3 errors (ApplicableHeaderTradeDelivery
# missing, BR-CO-15, guideline ID FX-SCH-A-000556).

set -uo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
MUSTANG_VERSION="2.26.0"
# SHA-256 of Mustang-CLI-2.26.0.jar, computed 2026-09-15 after checking the SHA-1 Maven Central
# publishes for it (8755fc333032f802ad7dcf3930201edfe64014e2).
MUSTANG_SHA256="42d7868cb68264874a7b8cab4c3587b03b23ccc7cd72373da917f66758bb9736"
MUSTANG_DIR="${MUSTANG_DIR:-${TMPDIR:-/tmp}/wm-mustang-cli}"
JAR="$MUSTANG_DIR/Mustang-CLI-$MUSTANG_VERSION.jar"

command -v java >/dev/null 2>&1 || { echo "java not found"; exit 2; }

sha256_of() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }

mkdir -p "$MUSTANG_DIR"
if [ ! -f "$JAR" ] || [ "$(sha256_of "$JAR")" != "$MUSTANG_SHA256" ]; then
  rm -f "$JAR"
  curl -sfL -o "$JAR" "https://repo1.maven.org/maven2/org/mustangproject/Mustang-CLI/$MUSTANG_VERSION/Mustang-CLI-$MUSTANG_VERSION.jar" \
    || { echo "download of Mustang-CLI failed"; exit 2; }
fi
actual=$(sha256_of "$JAR")
if [ "$actual" != "$MUSTANG_SHA256" ]; then
  echo "Mustang-CLI hash mismatch (got $actual); not running it"
  rm -f "$JAR"
  exit 2
fi

WORK=$(mktemp -d "${TMPDIR:-/tmp}/wm-cii-samples.XXXXXX")
trap 'rm -rf "$WORK"' EXIT

php "$ROOT/tests/generate-invoice-samples.php" "$WORK" || { echo "sample generation failed"; exit 2; }

failed=0
count=0
for xml in "$WORK"/*.xml; do
  count=$((count + 1))
  name=$(basename "$xml")
  report=$(cd "$WORK" && java -jar "$JAR" --action validate --source "$xml" --no-notices --disable-file-logging 2>&1)
  code=$?
  # Valid means: exit 0, a summary status of "valid", and no error element anywhere in the report.
  if [ "$code" -eq 0 ] && printf '%s\n' "$report" | grep -q '<summary status="valid"' && ! printf '%s\n' "$report" | grep -q '<error'; then
    echo "VALID    $name"
  else
    echo "INVALID  $name (exit $code)"
    printf '%s\n' "$report" | grep -oE '<error[^>]*>[^<]*' | sed -E 's/ location="[^"]*"//; s/ criterion="[^"]*"//' | head -10
    failed=1
  fi
done

[ "$count" -gt 0 ] || { echo "no XML generated"; exit 2; }
echo "$count files, $([ "$failed" -eq 0 ] && echo 'all valid' || echo 'INVALID files above')"
exit "$failed"
