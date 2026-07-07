#!/usr/bin/env bash
# Build the native liteparse_php library and stage it (with its PDFium
# dependency) into lib/, ready for LiteParseFfi.php to load.
#
# Usage: ./scripts/build.sh [release|debug]

set -euo pipefail

PROFILE="${1:-release}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="${SCRIPT_DIR}/.."

cd "${REPO_ROOT}/rust"
if [ "$PROFILE" = "release" ]; then
    cargo build --release
else
    cargo build
fi
cd "${REPO_ROOT}"

mkdir -p lib

case "$(uname -s)" in
    Darwin*)              LIBNAME="libliteparse_php.dylib" ;;
    Linux*)               LIBNAME="libliteparse_php.so" ;;
    MINGW*|MSYS*|CYGWIN*) LIBNAME="liteparse_php.dll" ;;
    *)                    echo "Unsupported OS: $(uname -s)" >&2; exit 1 ;;
esac

cp "rust/target/${PROFILE}/${LIBNAME}" "lib/${LIBNAME}"
echo "Copied rust/target/${PROFILE}/${LIBNAME} -> lib/${LIBNAME}"

"${SCRIPT_DIR}/copy-pdfium.sh" "${PROFILE}"
