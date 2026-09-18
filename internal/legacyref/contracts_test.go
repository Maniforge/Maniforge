package legacyref

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestPHPRoutersExposeSupplyChainAPI(t *testing.T) {
	root := repoRoot(t)
	cases := []struct {
		rel     string
		needles []string
	}{
		{
			"app/Maniforge/Warehouses/Http/Router.php",
			[]string{"/api/v1/stock-types", "/api/v1/stocks", "/api/v1/stocks/tree", "/api/v1/stocks/([0-9]+)/audit"},
		},
		{
			"app/Maniforge/Products/Http/Router.php",
			[]string{"/api/v1/products", "/api/v1/products/by-barcode/"},
		},
		{
			"app/Maniforge/Inventory/Http/Router.php",
			[]string{"/api/v1/balances", "/api/v1/movements", "/api/v1/orders", "/api/v1/reserves"},
		},
		{
			"app/Maniforge/Wms/Http/Router.php",
			[]string{"/api/v1/scan", "/api/v1/movements/scan", "/api/v1/packs", "/api/v1/markings"},
		},
	}
	for _, tc := range cases {
		body := readFile(t, filepath.Join(root, filepath.FromSlash(tc.rel)))
		for _, n := range tc.needles {
			if !strings.Contains(body, n) {
				t.Errorf("%s missing route %s", tc.rel, n)
			}
		}
	}
}

func TestFrontendAppsCallThoseRoutes(t *testing.T) {
	root := repoRoot(t)
	wh := readFile(t, filepath.Join(root, "frontend/apps/admin/src/shared/api/warehouses.ts"))
	for _, n := range []string{"/api/v1/stocks/tree", "/api/v1/stock-types", "/api/v1/stocks"} {
		if !strings.Contains(wh, n) {
			t.Errorf("admin warehouses.ts missing %s", n)
		}
	}
	wms := readFile(t, filepath.Join(root, "frontend/apps/scanner/src/shared/api/wms.ts"))
	for _, n := range []string{"/api/v1/scan", "/api/v1/movements/scan", "/api/v1/packs"} {
		if !strings.Contains(wms, n) {
			t.Errorf("scanner wms.ts missing %s", n)
		}
	}
}

func TestModuleCatalogPrefixes(t *testing.T) {
	root := repoRoot(t)
	body := readFile(t, filepath.Join(root, "templates/data/maniforge-modules.php"))
	for _, prefix := range []string{
		"'prefix' => '/rbac'",
		"'prefix' => '/warehouses'",
		"'prefix' => '/products'",
		"'prefix' => '/inventory'",
		"'prefix' => '/wms'",
	} {
		if !strings.Contains(body, prefix) {
			t.Errorf("maniforge-modules.php missing %s", prefix)
		}
	}
}

func TestPresetsPointAtPHPModules(t *testing.T) {
	root := repoRoot(t)
	body := readFile(t, filepath.Join(root, "internal/manifestengine/presets/presets.go"))
	for _, n := range []string{`php_module": "/products"`, `php_module": "/warehouses"`} {
		if !strings.Contains(body, n) {
			t.Errorf("presets.go missing %s", n)
		}
	}
}

func readFile(t *testing.T, path string) string {
	t.Helper()
	b, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("read %s: %v", path, err)
	}
	return string(b)
}
