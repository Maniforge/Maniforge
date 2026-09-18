package legacyref

import (
	"os"
	"path/filepath"
	"runtime"
	"testing"
)

// Files restored from platform-core.bak (2026-08-28) + Desk www-desk.
// They are the visual/API/apps surface that platform-core dropped.
func TestReferenceTreePresent(t *testing.T) {
	root := repoRoot(t)
	for _, rel := range requiredFiles() {
		path := filepath.Join(root, filepath.FromSlash(rel))
		st, err := os.Stat(path)
		if err != nil {
			t.Errorf("missing %s: %v", rel, err)
			continue
		}
		if st.IsDir() {
			t.Errorf("%s is a directory, want a file", rel)
		}
		if st.Size() == 0 {
			t.Errorf("%s is empty", rel)
		}
	}
}

func TestPHPModulePublicEntrypoints(t *testing.T) {
	root := repoRoot(t)
	modules := []string{
		"rbac", "tenant-licensing", "versioning",
		"warehouses", "products", "inventory", "wms",
	}
	for _, m := range modules {
		rel := filepath.Join("maniforge", m, "public", "index.php")
		if _, err := os.Stat(filepath.Join(root, rel)); err != nil {
			t.Errorf("PHP public entry missing: %s", filepath.ToSlash(rel))
		}
	}
}

func TestSupplyChainJourneyChecks(t *testing.T) {
	root := repoRoot(t)
	for _, name := range []string{
		"warehouses_journey_check.php",
		"products_journey_check.php",
		"inventory_journey_check.php",
		"wms_journey_check.php",
		"check_all.php",
	} {
		rel := filepath.Join("maniforge", "rbac", "tools", name)
		if _, err := os.Stat(filepath.Join(root, rel)); err != nil {
			t.Errorf("journey missing: %s", filepath.ToSlash(rel))
		}
	}
}

func requiredFiles() []string {
	return []string{
		"frontend/apps/admin/package.json",
		"frontend/apps/admin/src/App.tsx",
		"frontend/apps/admin/src/pages/DashboardPage.tsx",
		"frontend/apps/admin/src/pages/LoginPage.tsx",
		"frontend/apps/admin/src/pages/ManifestPage.tsx",
		"frontend/apps/admin/src/pages/WarehousesPage.tsx",
		"frontend/apps/admin/src/shared/api/warehouses.ts",
		"frontend/apps/admin/src/shared/api/manifest.ts",
		"frontend/apps/scanner/package.json",
		"frontend/apps/scanner/src/pages/ScanPage.tsx",
		"frontend/apps/scanner/src/pages/MovementPage.tsx",
		"frontend/apps/scanner/src/pages/HubPage.tsx",
		"frontend/apps/scanner/src/shared/api/wms.ts",
		"templates/modules.php",
		"templates/api.php",
		"templates/data/maniforge-modules.php",
		"maniforge/warehouses/public/index.php",
		"maniforge/products/public/index.php",
		"maniforge/inventory/public/index.php",
		"maniforge/wms/public/index.php",
		"maniforge/rbac/public/index.php",
		"maniforge/rbac/public/pages/api-docs.php",
		"public/app/index.html",
		"public/scanner/index.html",
		"apps/devent/store.html",
		"apps/devent/wms.html",
		"apps/devent/index.html",
		"deploy/www-desk/assets/desk.js",
		"deploy/www-desk/assets/desk.css",
		"deploy/www-desk/desk/login/index.html",
		"deploy/www-desk/desk/users/index.html",
		"deploy/www-desk/desk/index.html",
		"deploy/www-desk/api/index.html",
		"docs/MANIFORGE_WAREHOUSES.md",
		"docs/MANIFORGE_PRODUCTS.md",
		"docs/MANIFORGE_INVENTORY.md",
		"docs/MANIFORGE_WMS.md",
	}
}

func repoRoot(t *testing.T) string {
	t.Helper()
	_, file, _, ok := runtime.Caller(0)
	if !ok {
		t.Fatal("runtime.Caller failed")
	}
	dir := filepath.Dir(file)
	for i := 0; i < 8; i++ {
		if _, err := os.Stat(filepath.Join(dir, "go.mod")); err == nil {
			return dir
		}
		parent := filepath.Dir(dir)
		if parent == dir {
			break
		}
		dir = parent
	}
	t.Fatal("go.mod not found from test file")
	return ""
}
