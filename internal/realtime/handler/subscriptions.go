// Файл: subscriptions.go
// Назначение: CRUD пользовательских WS-подписок (каналы через API).
package handler

import (
	"strconv"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/rbac/repository"
	rtService "maniforge/internal/realtime/service"
)

type SubscriptionsHandler struct {
	svc *rtService.SubscriptionService
}

func NewSubscriptions(svc *rtService.SubscriptionService) *SubscriptionsHandler {
	return &SubscriptionsHandler{svc: svc}
}

func (h *SubscriptionsHandler) scope(c *fiber.Ctx) (rtService.Scope, error) {
	session, _ := c.Locals("maniforge_session").(*repository.SessionRecord)
	return rtService.ScopeFromSession(session)
}

func (h *SubscriptionsHandler) Create(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	var in rtService.SubscriptionInput
	if err := c.BodyParser(&in); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.svc.Create(scope, in)
	return httpx.JSON(c, status, payload)
}

func (h *SubscriptionsHandler) List(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	payload, status := h.svc.List(scope)
	return httpx.JSON(c, status, payload)
}

func (h *SubscriptionsHandler) Get(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	id, err := strconv.ParseInt(c.Params("id"), 10, 64)
	if err != nil || id <= 0 {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid id")
	}
	payload, status := h.svc.Get(scope, id)
	return httpx.JSON(c, status, payload)
}

func (h *SubscriptionsHandler) Update(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	id, err := strconv.ParseInt(c.Params("id"), 10, 64)
	if err != nil || id <= 0 {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid id")
	}
	var in rtService.SubscriptionInput
	if err := c.BodyParser(&in); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.svc.Update(scope, id, in)
	return httpx.JSON(c, status, payload)
}

func (h *SubscriptionsHandler) Delete(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	id, err := strconv.ParseInt(c.Params("id"), 10, 64)
	if err != nil || id <= 0 {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid id")
	}
	payload, status := h.svc.Delete(scope, id)
	return httpx.JSON(c, status, payload)
}

func (h *SubscriptionsHandler) SuggestChannels(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	payload, status := h.svc.SuggestChannels(scope)
	return httpx.JSON(c, status, payload)
}
