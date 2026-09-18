# Apply shared-edge Caddy: TLS site {domain} reverse_proxies to platform :18090 (Desk + APIs).
# Expected: DOMAIN, ROOT. Optional: MANIFORGE_EDGE_CADDYFILE, MANIFORGE_EDGE_UPSTREAM,
# MANIFORGE_EDGE_CADDY_CONTAINER. Never prints secrets.

apply_edge_desk() {
  local domain="${DOMAIN:-}"
  local edge="${MANIFORGE_EDGE_CADDYFILE:-/opt/maniforge/nilzyagram-business/deploy/caddy/Caddyfile}"
  local container="${MANIFORGE_EDGE_CADDY_CONTAINER:-nilzyagram-caddy-1}"
  local tmpl="${ROOT}/deploy/caddy/edge-platform.caddy"
  local upstream="${MANIFORGE_EDGE_UPSTREAM:-}"

  if [ -z "$domain" ]; then
    echo "==> edge desk: skip (no --domain)"
    return 0
  fi
  if [ ! -f "$edge" ]; then
    echo "==> edge desk: no $edge (generated snippet only)"
    return 0
  fi
  if [ ! -f "$tmpl" ]; then
    echo "missing $tmpl" >&2
    return 1
  fi
  if [ -z "$upstream" ]; then
    upstream="$(ip -4 route get 8.8.8.8 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="src") {print $(i+1); exit}}')"
    upstream="${upstream:-127.0.0.1}:18090"
  fi

  python3 - "$edge" "$tmpl" "$domain" "$upstream" <<'PY'
import re, shutil, sys, time
from pathlib import Path

edge_path, tmpl_path, domain, upstream = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
text = Path(edge_path).read_text(encoding="utf-8").replace("\ufeff", "").replace("\r\n", "\n").replace("\r", "\n")
snippet = (
    Path(tmpl_path)
    .read_text(encoding="utf-8")
    .replace("\ufeff", "")
    .replace("\r\n", "\n")
    .replace("\r", "\n")
    .replace("{domain}", domain)
    .replace("{upstream}", upstream)
    .strip()
    + "\n"
)
stamp = time.strftime("%Y%m%d-%H%M%S")
shutil.copy2(edge_path, f"{edge_path}.bak-{stamp}")


def end_of_block(src: str, brace_at: int) -> int:
    depth = 0
    for i, ch in enumerate(src[brace_at:], brace_at):
        if ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                return i
    raise SystemExit("unbalanced Caddyfile braces")


def replace_site(src: str, name: str, body: str) -> str:
    pattern = re.compile(rf"(?m)^{re.escape(name)} \{{")
    m = pattern.search(src)
    if not m:
        return src.rstrip() + "\n\n" + body + "\n"
    brace = src.find("{", m.start())
    end = end_of_block(src, brace)
    j = end + 1
    if j < len(src) and src[j] == "\n":
        j += 1
    return src[: m.start()] + body + src[j:]


text = replace_site(text, domain, snippet)
Path(edge_path).write_text(text, encoding="utf-8")
print(f"edge site {domain} -> {upstream}")
PY

  if docker ps --format '{{.Names}}' | grep -qx "$container"; then
    docker exec "$container" caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
    docker exec "$container" caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
    echo "==> edge caddy reloaded ($container)"
  else
    echo "==> edge container $container not running (Caddyfile updated)"
  fi
}
