package service

import (
	"os"
	"strings"
	"testing"
)

func TestParseAdminLoginRequiresPhone(t *testing.T) {
	_, err := parseAdminLogin("")
	if err == nil {
		t.Fatal("empty login must fail")
	}
	phone, err := parseAdminLogin("+79991234567")
	if err != nil {
		t.Fatal(err)
	}
	if phone != "+79991234567" {
		t.Fatalf("got %q", phone)
	}
	phone, err = parseAdminLogin("79991234567")
	if err != nil {
		t.Fatal(err)
	}
	if phone != "+79991234567" {
		t.Fatalf("digits-only must gain +: %q", phone)
	}
	if _, err := parseAdminLogin("admin"); err == nil {
		t.Fatal("username without phone must fail — вход в RBAC по телефону")
	}
}

func TestBootstrapInputRequiresPassword(t *testing.T) {
	if err := validateBootstrapInput("+79991234567", ""); err == nil {
		t.Fatal("empty password must fail")
	}
	if err := validateBootstrapInput("+79991234567", "short"); err == nil {
		t.Fatal("short password must fail")
	}
	if err := validateBootstrapInput("+79991234567", "long-enough-12"); err != nil {
		t.Fatal(err)
	}
}

func TestWritePublicManifestOmitsPassword(t *testing.T) {
	dir := t.TempDir()
	path := dir + "/bootstrap.json"
	access := DemoAccess{
		Created:     true,
		TenantID:    "t-0123456789abcdef",
		SubtenantID: "main",
		Phone:       "+79991234567",
		Login:       "u79991234567",
		Role:        "tenant_admin",
	}
	if err := access.WritePublicManifest(path); err != nil {
		t.Fatal(err)
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	body := string(raw)
	if !strings.Contains(body, `"tenant_id": "t-0123456789abcdef"`) {
		t.Fatalf("missing tenant_id: %s", body)
	}
	if !strings.Contains(body, `"phone": "+79991234567"`) {
		t.Fatalf("missing phone: %s", body)
	}
	if strings.Contains(strings.ToLower(body), "password") {
		t.Fatal("public bootstrap.json must not contain password")
	}
}
