// Файл: subscription.go
// Назначение: CRUD подписок WS + валидация каналов по manifests проекта.
package service

import (
	"database/sql"
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/manifestengine/model"
	"maniforge/internal/realtime/channel"
	"maniforge/internal/realtime/repository"
)

type SubscriptionService struct {
	repo *repository.SubscriptionRepository
}

func NewSubscriptionService(db *sql.DB) *SubscriptionService {
	return &SubscriptionService{repo: repository.NewSubscriptionRepository(db)}
}

type SubscriptionInput struct {
	Name     string   `json:"name"`
	Channels []string `json:"channels"`
}

func (s *SubscriptionService) Create(scope Scope, in SubscriptionInput) (map[string]any, int) {
	if err := scope.requireProject(); err != nil {
		return fail(err.Error(), fiber.StatusBadRequest)
	}
	name := strings.TrimSpace(in.Name)
	if name == "" {
		return fail("name обязателен", fiber.StatusUnprocessableEntity)
	}
	channels, err := s.normalizeChannels(scope, in.Channels)
	if err != nil {
		return fail(err.Error(), fiber.StatusUnprocessableEntity)
	}
	sub, err := s.repo.Create(scope.TenantID, scope.SubtenantID, scope.ProjectID, scope.UserID, name, channels)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	return ok(map[string]any{
		"subscription": sub.ToMap(),
		"ws_subscribe": map[string]any{"type": "subscribe", "subscription_id": sub.ID},
	}, fiber.StatusCreated)
}

func (s *SubscriptionService) List(scope Scope) (map[string]any, int) {
	if err := scope.requireProject(); err != nil {
		return fail(err.Error(), fiber.StatusBadRequest)
	}
	items, err := s.repo.List(scope.TenantID, scope.SubtenantID, scope.ProjectID, scope.UserID)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	out := make([]map[string]any, 0, len(items))
	for i := range items {
		out = append(out, items[i].ToMap())
	}
	return ok(map[string]any{"subscriptions": out}, fiber.StatusOK)
}

func (s *SubscriptionService) Get(scope Scope, id int64) (map[string]any, int) {
	if err := scope.requireProject(); err != nil {
		return fail(err.Error(), fiber.StatusBadRequest)
	}
	sub, err := s.repo.GetByID(scope.TenantID, scope.SubtenantID, scope.ProjectID, scope.UserID, id)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	if sub == nil {
		return fail("подписка не найдена", fiber.StatusNotFound)
	}
	return ok(map[string]any{"subscription": sub.ToMap()}, fiber.StatusOK)
}

func (s *SubscriptionService) Update(scope Scope, id int64, in SubscriptionInput) (map[string]any, int) {
	if err := scope.requireProject(); err != nil {
		return fail(err.Error(), fiber.StatusBadRequest)
	}
	name := strings.TrimSpace(in.Name)
	if name == "" {
		return fail("name обязателен", fiber.StatusUnprocessableEntity)
	}
	channels, err := s.normalizeChannels(scope, in.Channels)
	if err != nil {
		return fail(err.Error(), fiber.StatusUnprocessableEntity)
	}
	sub, err := s.repo.Update(id, scope.TenantID, scope.SubtenantID, scope.ProjectID, scope.UserID, name, channels)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	if sub == nil {
		return fail("подписка не найдена", fiber.StatusNotFound)
	}
	return ok(map[string]any{"subscription": sub.ToMap()}, fiber.StatusOK)
}

func (s *SubscriptionService) Delete(scope Scope, id int64) (map[string]any, int) {
	if err := scope.requireProject(); err != nil {
		return fail(err.Error(), fiber.StatusBadRequest)
	}
	okArch, err := s.repo.Archive(id, scope.TenantID, scope.SubtenantID, scope.ProjectID, scope.UserID)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	if !okArch {
		return fail("подписка не найдена", fiber.StatusNotFound)
	}
	return ok(map[string]any{"archived": true, "id": id}, fiber.StatusOK)
}

func (s *SubscriptionService) ChannelsForSubscription(scope Scope, id int64) ([]string, error) {
	sub, err := s.repo.GetByID(scope.TenantID, scope.SubtenantID, scope.ProjectID, scope.UserID, id)
	if err != nil || sub == nil {
		return nil, err
	}
	return sub.Channels, nil
}

func (s *SubscriptionService) SuggestChannels(scope Scope) (map[string]any, int) {
	if err := scope.requireProject(); err != nil {
		return fail(err.Error(), fiber.StatusBadRequest)
	}
	manifests, err := s.repo.ListManifestCodes(scope.TenantID, scope.ProjectID)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	var custom, platform []string
	for code, origin := range manifests {
		if origin == model.OriginPlatform {
			platform = append(platform, code)
		} else {
			custom = append(custom, code)
		}
	}
	return ok(map[string]any{
		"manifests": manifests,
		"meta_channels": []string{
			channel.EntityAll, channel.EntityCustom, channel.EntityPlatform,
			"notifications", "tenant",
		},
		"suggested": channel.SuggestAll(manifests),
		"custom_entities": custom, "platform_entities": platform,
	}, fiber.StatusOK)
}

func (s *SubscriptionService) normalizeChannels(scope Scope, channels []string) ([]string, error) {
	if len(channels) == 0 {
		return nil, errString("channels обязателен")
	}
	manifests, err := s.repo.ListManifestCodes(scope.TenantID, scope.ProjectID)
	if err != nil {
		return nil, err
	}
	seen := map[string]struct{}{}
	var out []string
	for _, ch := range channels {
		ch = strings.TrimSpace(ch)
		if ch == "" {
			continue
		}
		if !channel.ValidateClientChannel(ch) {
			return nil, errString(channel.InvalidChannelError(ch))
		}
		if err := channel.ValidateAgainstManifests(ch, manifests); err != nil {
			return nil, err
		}
		if _, ok := seen[ch]; ok {
			continue
		}
		seen[ch] = struct{}{}
		out = append(out, ch)
	}
	if len(out) == 0 {
		return nil, errString("channels обязателен")
	}
	return out, nil
}

type errString string

func (e errString) Error() string { return string(e) }
