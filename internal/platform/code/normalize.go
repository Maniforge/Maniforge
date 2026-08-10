// Package code — нормализация строковых идентификаторов платформы.
//
// Файл: normalize.go
// Назначение: trim + lowercase для tenant/subtenant/project кодов.
// См. также: licensingclient, tenantlicensing/repository
package code

import "strings"

// Normalize приводит код к lower-case trimmed (tenant, subtenant, project).
func Normalize(s string) string {
	return strings.ToLower(strings.TrimSpace(s))
}
