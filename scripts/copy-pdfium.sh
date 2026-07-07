#!/usr/bin/env bash
#
# Copy the PDFium shared library into lib/, next to the compiled
# liteparse_php native library, so pdfium-sys's runtime loader finds it via
# "next to our own loaded module" resolution (see
# liteparse/crates/pdfium-sys/src/dynamic.rs::search_paths, self_dir()).
#
# Usage: ./scripts/copy-pdfium.sh [release|debug]
#
# Auto-detects the pdfium library location from, in order:
#   1. PDFIUM_LIB_PATH env var (set by CI or user)
#   2. rust/target/<profile>/deps/ (the build we just produced)
#   3. The pdfium-sys build cache (~/.cache/pdfium-rs or platform equivalent)

set -euo pipefail

PROFILE="${1:-release}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="${SCRIPT_DIR}/.."
OUTPUT_DIR="${REPO_ROOT}/lib"

mkdir -p "${OUTPUT_DIR}"

case "$(uname -s)" in
    Darwin*)              DYLIB="libpdfium.dylib" ;;
    Linux*)               DYLIB="libpdfium.so" ;;
    MINGW*|MSYS*|CYGWIN*) DYLIB="pdfium.dll" ;;
    *)                    echo "Unsupported OS: $(uname -s)" >&2; exit 1 ;;
esac

find_pdfium() {
    if [ -n "${PDFIUM_LIB_PATH:-}" ] && [ -f "${PDFIUM_LIB_PATH}/${DYLIB}" ]; then
        echo "${PDFIUM_LIB_PATH}/${DYLIB}"
        return
    fi

    local deps="${REPO_ROOT}/rust/target/${PROFILE}/deps/${DYLIB}"
    if [ -f "$deps" ]; then
        echo "$deps"
        return
    fi

    local cache_base
    case "$(uname -s)" in
        Darwin*) cache_base="$HOME/Library/Caches/pdfium-rs" ;;
        MINGW*|MSYS*|CYGWIN*) cache_base="${LOCALAPPDATA:-$HOME/AppData/Local}/pdfium-rs" ;;
        *)       cache_base="${XDG_CACHE_HOME:-$HOME/.cache}/pdfium-rs" ;;
    esac

    if [ -d "$cache_base" ]; then
        local found
        found=$(find "$cache_base" -name "$DYLIB" -type f 2>/dev/null | head -1)
        if [ -n "$found" ]; then
            echo "$found"
            return
        fi
    fi

    echo ""
}

PDFIUM_PATH=$(find_pdfium)

if [ -z "$PDFIUM_PATH" ]; then
    echo "Error: could not find ${DYLIB}. Set PDFIUM_LIB_PATH to the directory containing it." >&2
    exit 1
fi

cp "$PDFIUM_PATH" "${OUTPUT_DIR}/${DYLIB}"
echo "Copied ${PDFIUM_PATH} -> ${OUTPUT_DIR}/${DYLIB}"
