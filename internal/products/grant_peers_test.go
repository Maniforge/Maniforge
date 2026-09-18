package products

import (
	"testing"

	"maniforge/internal/platform/apitest"
	"maniforge/internal/rbac"
	"maniforge/internal/tenantlicensing"
)

func TestProductsGrantPeersFromTL(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	apitest.AssertGrantPeersFromTL(t, NewApp(cfg, sqlDB), rbac.NewApp(cfg, sqlDB), tenantlicensing.NewApp(cfg, sqlDB), "+7912")
}
