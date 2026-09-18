package legacyref

import (
	"path/filepath"
	"regexp"
	"strings"
	"testing"
)

func TestReactDeskHeaderIsSharedAcrossApps(t *testing.T) {
	root := repoRoot(t)
	header := readFile(t, filepath.Join(root, "frontend", "packages", "desk-ui", "src", "DeskHeader.tsx"))
	for _, n := range []string{
		`className="top"`,
		`Mani<i>forge</i>`,
		`/about/`,
		`/about-us/`,
		`/app/`,
		`/api/`,
		`/desk/login/`,
		`/desk/users/`,
		`/scanner/`,
		`maniforge_access_token`,
	} {
		if !strings.Contains(header, n) {
			t.Errorf("DeskHeader missing %s", n)
		}
	}
	for _, rel := range []string{
		"frontend/apps/admin/src/components/AppShell.tsx",
		"frontend/apps/scanner/src/components/ScannerShell.tsx",
		"frontend/apps/desk/src/App.tsx",
	} {
		body := readFile(t, filepath.Join(root, filepath.FromSlash(rel)))
		if !strings.Contains(body, "DeskHeader") {
			t.Errorf("%s must use shared DeskHeader", rel)
		}
	}
}

func TestReactDeskServesApiDocsRoute(t *testing.T) {
	root := repoRoot(t)
	router := readFile(t, filepath.Join(root, "frontend", "apps", "desk", "src", "App.tsx"))
	for _, n := range []string{`path="/api"`, `ApiDocsPage`, `path="/desk/login"`} {
		if !strings.Contains(router, n) {
			t.Errorf("desk App missing %s", n)
		}
	}
}

func TestAdminRouterChecksAuthBeforeDashboardRedirect(t *testing.T) {
	raw := readFile(t, filepath.Join(repoRoot(t), "frontend", "apps", "admin", "src", "app", "router.tsx"))
	if !strings.Contains(raw, "<ProtectedRoute>") {
		t.Fatal("admin router missing ProtectedRoute")
	}
	unguarded := regexp.MustCompile(`</Route>\s*<Route path="/" element=\{<Navigate to="/dashboard"`)
	if unguarded.MatchString(raw) {
		t.Fatal("admin sends /app/ to /dashboard before auth; default redirect must be nested in ProtectedRoute")
	}
	if !strings.Contains(raw, `Navigate to="/dashboard"`) {
		t.Fatal("authenticated /app/ still needs / → /dashboard")
	}
}

func TestScannerRouterChecksAuthBeforeHubRedirect(t *testing.T) {
	raw := readFile(t, filepath.Join(repoRoot(t), "frontend", "apps", "scanner", "src", "app", "router.tsx"))
	if !strings.Contains(raw, "<ProtectedRoute>") {
		t.Fatal("scanner router missing ProtectedRoute")
	}
	unguarded := regexp.MustCompile(`</Route>\s*<Route path="\*" element=\{<Navigate to="/"`)
	if unguarded.MatchString(raw) {
		t.Fatal("scanner catch-all redirects to hub before auth; it must be nested in ProtectedRoute")
	}
	if !strings.Contains(raw, `path="*"`) || !strings.Contains(raw, `Navigate to="/"`) {
		t.Fatal("scanner catch-all missing")
	}
}

func TestDeskLoadsManifestsFromEnginePrefix(t *testing.T) {
	raw := readFile(t, filepath.Join(repoRoot(t), "frontend", "apps", "desk", "src", "pages", "DeskHomePage.tsx"))
	if strings.Contains(raw, `fetch('/manifest/api/v1/manifests'`) {
		t.Fatal("Desk must not call /manifest/api — Caddy does not proxy that prefix (empty 404)")
	}
	if !strings.Contains(raw, `fetch('/manifest-engine/api/v1/manifests'`) {
		t.Fatal("Desk must list manifests via /manifest-engine/api/v1/manifests")
	}
}

func TestDeskGuardsPrivateRoutesBeforeRender(t *testing.T) {
	raw := readFile(t, filepath.Join(repoRoot(t), "frontend", "apps", "desk", "src", "App.tsx"))
	if !strings.Contains(raw, "RequireDeskSession") {
		t.Fatal("desk must wrap /desk and /desk/users so session is checked before the page renders")
	}
	if !strings.Contains(raw, `path="/desk"`) || !strings.Contains(raw, `path="/desk/users"`) {
		t.Fatal("desk private routes missing")
	}
}
