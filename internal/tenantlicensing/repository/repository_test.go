// Файл: repository_test.go
// Назначение: unit-тесты логики активности лицензии (isLicenseActive).
// См. также: repository.go (Entitlements, activeLicense)
package repository

import (
	"testing"
	"time"
)

func TestLicenseActive(t *testing.T) {
	future := time.Now().UTC().Add(time.Hour)
	past := time.Now().UTC().Add(-time.Hour)

	cases := []struct {
		name   string
		lic    *LicenseInfo
		active bool
	}{
		{"nil", nil, false},
		{"active no expiry", &LicenseInfo{Status: "active"}, true},
		{"active future", &LicenseInfo{Status: "active", ExpiresAt: &future}, true},
		{"active expired", &LicenseInfo{Status: "active", ExpiresAt: &past}, false},
		{"revoked", &LicenseInfo{Status: "revoked"}, false},
	}

	for _, tc := range cases {
		got := isLicenseActive(tc.lic)
		if got != tc.active {
			t.Fatalf("%s: got %v want %v", tc.name, got, tc.active)
		}
	}
}

func isLicenseActive(license *LicenseInfo) bool {
	if license == nil {
		return false
	}
	return license.Status == "active" &&
		(license.ExpiresAt == nil || license.ExpiresAt.After(time.Now().UTC()))
}
