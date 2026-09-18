package gatewaycheck

import (
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestCaddyfilesProxySupplyChainPrefixes(t *testing.T) {
	root := repoRoot(t)
	files := []string{"Caddyfile", "Caddyfile.server", "Caddyfile.production"}
	needles := []string{
		"path /warehouses /warehouses/*",
		"path /products /products/*",
		"path /inventory /inventory/*",
		"path /wms /wms/*",
	}
	for _, name := range files {
		p := filepath.Join(root, "deploy", name)
		raw, err := os.ReadFile(p)
		if err != nil {
			t.Fatalf("%s: %v", p, err)
		}
		body := string(raw)
		for _, n := range needles {
			if !strings.Contains(body, n) {
				t.Errorf("%s: нет %q", name, n)
			}
		}
	}
}

func TestLiveGatewaySupplyChainHealth(t *testing.T) {
	client := &http.Client{Timeout: 800 * time.Millisecond}
	bases := []string{"http://127.0.0.1:18090", "http://127.0.0.1:8080"}
	var base string
	for _, b := range bases {
		resp, err := client.Get(b + "/warehouses/health")
		if err != nil {
			continue
		}
		_, _ = io.Copy(io.Discard, resp.Body)
		_ = resp.Body.Close()
		if resp.StatusCode >= 200 && resp.StatusCode < 300 {
			base = b
			break
		}
	}
	if base == "" {
		t.Skip("Caddy gateway не слушает :18090/:8080")
	}
	for _, prefix := range []string{"/warehouses", "/products", "/inventory", "/wms"} {
		url := base + prefix + "/health"
		resp, err := client.Get(url)
		if err != nil {
			t.Fatalf("%s: %v", url, err)
		}
		_, _ = io.Copy(io.Discard, resp.Body)
		_ = resp.Body.Close()
		if resp.StatusCode < 200 || resp.StatusCode >= 300 {
			t.Errorf("%s: status %d", url, resp.StatusCode)
		}
	}
}

func repoRoot(t *testing.T) string {
	t.Helper()
	dir, err := os.Getwd()
	if err != nil {
		t.Fatal(err)
	}
	for i := 0; i < 12; i++ {
		if _, err := os.Stat(filepath.Join(dir, "go.mod")); err == nil {
			if _, err := os.Stat(filepath.Join(dir, "deploy", "Caddyfile")); err == nil {
				return dir
			}
		}
		parent := filepath.Dir(dir)
		if parent == dir {
			break
		}
		dir = parent
	}
	t.Fatal("не найден корень репозитория (go.mod + deploy/Caddyfile)")
	return ""
}
