package modules

import (
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

func testCatalog(t *testing.T) Catalog {
	t.Helper()
	cat, err := LoadCatalog(CatalogPath(repoRoot(t)))
	if err != nil {
		t.Fatal(err)
	}
	return cat
}

func repoRoot(t *testing.T) string {
	t.Helper()
	_, file, _, ok := runtime.Caller(0)
	if !ok {
		t.Fatal("caller")
	}
	dir := filepath.Dir(file)
	for i := 0; i < 8; i++ {
		if _, err := os.Stat(filepath.Join(dir, "go.mod")); err == nil {
			if _, err := os.Stat(filepath.Join(dir, "deploy", "modules.yaml")); err == nil {
				return dir
			}
		}
		parent := filepath.Dir(dir)
		if parent == dir {
			break
		}
		dir = parent
	}
	t.Fatal("repo root not found")
	return ""
}

func TestCoreOmitsWMS(t *testing.T) {
	r, err := Resolve(testCatalog(t), "core")
	if err != nil {
		t.Fatal(err)
	}
	if HasSystemd(r, "maniforge-wms.service") {
		t.Fatal("core must not enable wms unit")
	}
	if HasCaddyHandle(r, "/wms") || HasCaddyHandle(r, "/wms/*") {
		t.Fatal("core must not proxy /wms")
	}
	if !HasSystemd(r, "maniforge-rbac.service") || !HasSystemd(r, "maniforge-caddy.service") {
		t.Fatal("core must enable rbac and caddy")
	}
	body := RenderCaddy(r, CaddyOpts{Mode: "host", Listen: ":18090"})
	if strings.Contains(body, "/wms") {
		t.Fatalf("caddy core leaked /wms:\n%s", body)
	}
	if !strings.Contains(body, "path /rbac /rbac/*") {
		t.Fatal("caddy core missing /rbac")
	}
}

func TestWMSrequiresSupply(t *testing.T) {
	_, err := Resolve(testCatalog(t), "core,wms")
	if err == nil {
		t.Fatal("expected error")
	}
	if !strings.Contains(err.Error(), "supply") {
		t.Fatalf("want supply in error, got %v", err)
	}
}

func TestFullHasEveryPrefix(t *testing.T) {
	r, err := Resolve(testCatalog(t), "full")
	if err != nil {
		t.Fatal(err)
	}
	needles := []string{"/rbac", "/tenant-licensing", "/versioning", "/warehouses", "/products", "/inventory", "/wms"}
	body := RenderCaddy(r, CaddyOpts{Mode: "compose", Listen: ":8080"})
	for _, n := range needles {
		if !strings.Contains(body, n) {
			t.Errorf("full caddy missing %s", n)
		}
	}
	if !HasSystemd(r, "maniforge-wms.service") {
		t.Error("full missing wms unit")
	}
	if len(r.ComposeProfiles) == 0 {
		t.Error("full should set compose profiles for optional packages")
	}
}

func TestSupplyWithoutWMS(t *testing.T) {
	r, err := Resolve(testCatalog(t), "core,supply")
	if err != nil {
		t.Fatal(err)
	}
	if HasCaddyHandle(r, "/wms") {
		t.Fatal("supply must not include /wms")
	}
	if !HasCaddyHandle(r, "/warehouses") {
		t.Fatal("supply missing warehouses")
	}
	disable := strings.Join(r.SystemdDisable, " ")
	if !strings.Contains(disable, "maniforge-wms.service") {
		t.Fatalf("wms should be disabled, got %s", disable)
	}
}

func TestUnknownPackage(t *testing.T) {
	_, err := Resolve(testCatalog(t), "core,nope")
	if err == nil || !strings.Contains(err.Error(), "unknown package") {
		t.Fatalf("got %v", err)
	}
}

func TestServerUpKeepsHTTP18090(t *testing.T) {
	raw, err := os.ReadFile(filepath.Join(repoRoot(t), "deploy", "scripts", "server-up.sh"))
	if err != nil {
		t.Fatal(err)
	}
	body := string(raw)
	if !strings.Contains(body, `CADDY_LISTEN=":18090"`) {
		t.Fatal("server-up must default Caddy to :18090")
	}
	if !strings.Contains(body, "Caddyfile.active") {
		t.Fatal("server-up must render Caddyfile.active")
	}
	if !strings.Contains(body, "env_get()") {
		t.Fatal("optional env keys must use env_get so missing grep does not trip set -e")
	}
	if strings.Contains(body, `[ "${gw_port}" = "443" ]`) {
		t.Fatal("server-up must not bind Caddy to MANIFORGE_GATEWAY_PORT=443")
	}
	if !strings.Contains(body, "postgres script perms") {
		t.Fatal("server-up must chmod replica-entrypoint before compose")
	}
	compose, err := os.ReadFile(filepath.Join(repoRoot(t), "deploy", "compose.platform.server.yml"))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(compose), `entrypoint: ["/bin/sh", "/replica-entrypoint.sh"]`) {
		t.Fatal("replica must start via /bin/sh so missing +x is not fatal")
	}
	if !strings.Contains(body, "maniforge_fill_admin") {
		t.Fatal("server-up must fill ADMIN_LOGIN/PASSWORD/ORG (demo defaults if unset)")
	}
}

func TestPromptAdminHelper(t *testing.T) {
	raw, err := os.ReadFile(filepath.Join(repoRoot(t), "deploy", "scripts", "lib", "prompt-admin.sh"))
	if err != nil {
		t.Fatal(err)
	}
	body := string(raw)
	for _, needle := range []string{"ADMIN_LOGIN", "ADMIN_PASSWORD", "ADMIN_ORG", "+79991234567", "DemoAdmin!12345"} {
		if !strings.Contains(body, needle) {
			t.Errorf("prompt-admin.sh missing %s", needle)
		}
	}
	if strings.Contains(body, "skip demo bootstrap") {
		t.Fatal("zero-config install must not skip demo admin")
	}
}

func TestHostCaddyServesDesk(t *testing.T) {
	r, err := Resolve(testCatalog(t), "full")
	if err != nil {
		t.Fatal(err)
	}
	body := RenderCaddy(r, CaddyOpts{
		Mode:    "host",
		Listen:  ":18090",
		WebRoot: "/opt/maniforge/platform-core/deploy/www-desk",
	})
	for _, needle := range []string{"file_server", "try_files {path} {path}/ {path}/index.html", "root * /opt/maniforge/platform-core/deploy/www-desk"} {
		if !strings.Contains(body, needle) {
			t.Fatalf("host caddy missing %s:\n%s", needle, body)
		}
	}
	if strings.Contains(body, `respond "Maniforge platform`) {
		t.Fatal("host caddy should serve Desk, not a text stub")
	}
}

func TestInstallFromGitIsOneCommand(t *testing.T) {
	raw, err := os.ReadFile(filepath.Join(repoRoot(t), "deploy", "scripts", "install-from-git.sh"))
	if err != nil {
		t.Fatal(err)
	}
	body := string(raw)
	for _, needle := range []string{"git clone", "install-maniforge.sh", "compose.platform.server.yml"} {
		if !strings.Contains(body, needle) {
			t.Errorf("install-from-git.sh missing %s", needle)
		}
	}
	edge, err := os.ReadFile(filepath.Join(repoRoot(t), "deploy", "scripts", "lib", "apply-edge-desk.sh"))
	if err != nil {
		t.Fatal(err)
	}
	bodyEdge := string(edge)
	if !strings.Contains(bodyEdge, "apply_edge_desk") {
		t.Fatal("apply-edge-desk.sh missing apply_edge_desk")
	}
	if !strings.Contains(bodyEdge, `(?m)^`) {
		t.Fatal("apply-edge-desk.sh must match site names at line start, not inside www.domain")
	}
}

func TestDocsMentionMakeUpAndModules(t *testing.T) {
	root := repoRoot(t)
	files := []string{
		filepath.Join(root, "README.md"),
		filepath.Join(root, "deploy", "README.md"),
		filepath.Join(root, "docs", "PRODUCTION_BOX.md"),
	}
	for _, f := range files {
		raw, err := os.ReadFile(f)
		if err != nil {
			t.Fatal(err)
		}
		body := string(raw)
		if !strings.Contains(body, "make up") {
			t.Errorf("%s: missing make up", f)
		}
		if !strings.Contains(body, "MANIFORGE_MODULES=") {
			t.Errorf("%s: missing MANIFORGE_MODULES=", f)
		}
	}
}

func TestShellExport(t *testing.T) {
	full, err := Resolve(testCatalog(t), "full")
	if err != nil {
		t.Fatal(err)
	}
	sh := ShellExport(full)
	if !strings.Contains(sh, "MANIFORGE_COMPOSE_SERVICES='") {
		t.Fatalf("unquoted compose services would break eval:\n%s", sh)
	}
	if strings.Contains(sh, "MANIFORGE_COMPOSE_SERVICES=postgres migrate") {
		t.Fatal("bare unquoted assignment")
	}

	core, err := Resolve(testCatalog(t), "core")
	if err != nil {
		t.Fatal(err)
	}
	coreSh := ShellExport(core)
	if strings.Contains(coreSh, "/wms/health") {
		t.Fatal("core shell leaked wms health")
	}
}
