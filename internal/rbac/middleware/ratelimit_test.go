// Файл: ratelimit_test.go
// Назначение: unit-тесты bucket identity для rate limit (WB per-user / per-phone).
package middleware

import "testing"

func TestNormalizePhoneHint(t *testing.T) {
	if got := normalizePhoneHint(" +7 (930) 123-45-67 "); got != "+79301234567" {
		t.Fatalf("phone normalize: got %q", got)
	}
	if normalizePhoneHint("") != "" {
		t.Fatal("empty phone")
	}
}

func TestHashBucketDistinctUsers(t *testing.T) {
	a := hashBucket("user", "t1", "main", "1", "api")
	b := hashBucket("user", "t1", "main", "2", "api")
	if a == b {
		t.Fatal("different users must have different buckets")
	}
}

func TestHashBucketDistinctPhones(t *testing.T) {
	a := hashBucket("auth", "login", "+79001111111")
	b := hashBucket("auth", "login", "+79002222222")
	if a == b {
		t.Fatal("different phones must have different login buckets")
	}
}
