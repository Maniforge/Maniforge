// Файл: channels.go
// Назначение: подсказка каналов WS (platform + custom manifests проекта).
package handler

import (
	rtService "maniforge/internal/realtime/service"
)

// ChannelsHandler — алиас для обратной совместимости маршрута /api/v1/ws/channels.
type ChannelsHandler = SubscriptionsHandler

func NewChannels(svc *rtService.SubscriptionService) *ChannelsHandler {
	return NewSubscriptions(svc)
}
