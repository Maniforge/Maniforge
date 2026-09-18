// Package tenantid — opaque tenant codes for demo/bootstrap installs.
//
// tenantId = "t-" + first 16 hex chars of SHA-256(random UUID).
// UUID itself is discarded; the hash is the public identifier.
package tenantid

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"strings"
)

const prefix = "t-"

// FromUUID hashes a UUID (or any seed) into a tenant code.
func FromUUID(uuid string) string {
	sum := sha256.Sum256([]byte(strings.TrimSpace(uuid)))
	return prefix + hex.EncodeToString(sum[:8])
}

// New generates a random UUID v4 and returns its hashed tenant id.
func New() (string, error) {
	uuid, err := randomUUID()
	if err != nil {
		return "", err
	}
	return FromUUID(uuid), nil
}

func randomUUID() (string, error) {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		return "", err
	}
	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80
	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:]), nil
}
