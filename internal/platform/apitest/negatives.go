package apitest

import (
	"net/http"
	"testing"

	"github.com/gofiber/fiber/v2"
)

// MustStatus — статус HTTP как у Client.MustStatus (client.go).
func MustStatus(c *Client, method, path string, body any, want int) map[string]any {
	c.T.Helper()
	return c.MustStatus(method, path, body, want)
}

// RegisterUserWithoutWrite — второй пользователь того же tenant с системной ролью
// user (005_rbac_roles.sql). Нет warehouses.write / products.write / inventory.write / wms.write.
func RegisterUserWithoutWrite(t *testing.T, rbacApp *fiber.App, admin *Client) *Client {
	t.Helper()
	return RegisterSameTenantUser(t, rbacApp, admin)
}

// DomainClient — Bearer-клиент к другому Fiber-приложению с той же сессией.
func DomainClient(t *testing.T, app *fiber.App, sess Session) *Client {
	t.Helper()
	return &Client{T: t, App: app, Auth: true, Session: sess}
}

// WithoutCSRF копирует клиента без X-CSRF-Token (Session.CSRF пустой).
func WithoutCSRF(c *Client) *Client {
	clone := *c
	clone.Session.CSRF = ""
	return &clone
}

// MustForbidden — 403.
func MustForbidden(c *Client, method, path string, body any) map[string]any {
	c.T.Helper()
	return MustStatus(c, method, path, body, http.StatusForbidden)
}

// MustUnprocessable — 422.
func MustUnprocessable(c *Client, method, path string, body any) map[string]any {
	c.T.Helper()
	return MustStatus(c, method, path, body, http.StatusUnprocessableEntity)
}

// MustUnauthorized — 401.
func MustUnauthorized(c *Client, method, path string, body any) map[string]any {
	c.T.Helper()
	return MustStatus(c, method, path, body, http.StatusUnauthorized)
}

// MustBadRequest — 400.
func MustBadRequest(c *Client, method, path string, body any) map[string]any {
	c.T.Helper()
	return MustStatus(c, method, path, body, http.StatusBadRequest)
}
