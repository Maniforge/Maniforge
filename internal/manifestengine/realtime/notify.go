// Package realtime — уведомления Manifest Engine → WebSocket hub.
//
// Файл: notify.go
// Назначение: события platform + custom сущностей.
package realtime

import (
	"maniforge/internal/manifestengine/model"
	"maniforge/internal/realtime/channel"
	"maniforge/internal/realtime/publisher"
)

type Notifier struct {
	pub *publisher.Client
}

func NewNotifier(pub *publisher.Client) *Notifier {
	return &Notifier{pub: pub}
}

func (n *Notifier) Manifest(scope model.Scope, m *model.Manifest, event string) {
	if n == nil || n.pub == nil || m == nil {
		return
	}
	payload := map[string]any{
		"event": event, "entity": m.Code, "origin": m.Origin,
		"project_id": scope.ProjectID, "version": m.Version,
	}
	tenant, sub := scope.TenantID, scope.SubtenantID
	n.pub.Broadcast(tenant, sub, channel.EntityAll, payload)
	if m.Origin == model.OriginPlatform {
		n.pub.Broadcast(tenant, sub, channel.EntityPlatform, payload)
	} else {
		n.pub.Broadcast(tenant, sub, channel.EntityCustom, payload)
	}
	n.pub.Broadcast(tenant, sub, channel.Entity(m.Code), payload)
}

func (n *Notifier) Record(scope model.Scope, m *model.Manifest, event string, recordID int64) {
	if n == nil || n.pub == nil || m == nil {
		return
	}
	payload := map[string]any{
		"event": event, "entity": m.Code, "origin": m.Origin,
		"record_id": recordID, "project_id": scope.ProjectID,
	}
	n.pub.Broadcast(scope.TenantID, scope.SubtenantID, channel.Data(m.Code), payload)
}
