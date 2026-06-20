# Spec Queue - shell functions for Ralph Loop
# Ported from SpecQueue.ps1

count_root_specs() {
    local specs_dir="${1:-specs}"
    if [ ! -d "$specs_dir" ]; then
        echo 0
        return
    fi
    find "$specs_dir" -maxdepth 1 -name '*.md' -type f | wc -l
}

count_incomplete_root_specs() {
    local specs_dir="${1:-specs}"
    if [ ! -d "$specs_dir" ]; then
        echo 0
        return
    fi
    local count=0
    while IFS= read -r -d '' spec; do
        if ! grep -qE '^(#{1,3} )?(\*\*)?Status(\*\*)?:\s+COMPLETE' "$spec" 2>/dev/null; then
            count=$((count + 1))
        fi
    done < <(find "$specs_dir" -maxdepth 1 -name '*.md' -type f -print0 | sort -z)
    echo "$count"
}

get_first_incomplete_root_spec() {
    local specs_dir="${1:-specs}"
    if [ ! -d "$specs_dir" ]; then
        echo ""
        return
    fi
    while IFS= read -r -d '' spec; do
        if ! grep -qE '^(#{1,3} )?(\*\*)?Status(\*\*)?:\s+COMPLETE' "$spec" 2>/dev/null; then
            echo "$spec"
            return
        fi
    done < <(find "$specs_dir" -maxdepth 1 -name '*.md' -type f -print0 | sort -z)
    echo ""
}
