// Файл: main.go (cmd/token-gen)
// Назначение: генерация сильных service tokens для ротации (enterprise ops).
// См. также: docs/MANIFORGE_CREDENTIAL_ARCHITECTURE.md
package main

import (
	"crypto/rand"
	"encoding/base64"
	"fmt"
	"os"
)

func main() {
	names := []string{
		"TENANT_LICENSING_ADMIN_TOKEN",
		"TENANT_LICENSING_INTERNAL_TOKEN",
		"RBAC_INTERNAL_TOKEN",
	}
	if len(os.Args) > 1 {
		names = os.Args[1:]
	}
	fmt.Println("# Paste into .env (ротация — обновить все сервисы одновременно)")
	for _, name := range names {
		fmt.Printf("%s=%s\n", name, token())
	}
}

func token() string {
	b := make([]byte, 32)
	if _, err := rand.Read(b); err != nil {
		panic(err)
	}
	return base64.RawURLEncoding.EncodeToString(b)
}
