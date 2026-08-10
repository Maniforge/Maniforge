// Package service — бизнес-логика Manifest Engine.
package service

import (
	"context"
	"database/sql"
	"fmt"
	"net/http"
	"strconv"
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/dataplane"
	"maniforge/internal/licensingclient"
	"maniforge/internal/manifestengine/model"
	mert "maniforge/internal/manifestengine/realtime"
	"maniforge/internal/manifestengine/presets"
	mrepo "maniforge/internal/manifestengine/repository"
	"maniforge/internal/realtime/publisher"
	"maniforge/internal/platform/code"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/versioning"
)

// Scope — алиас контура сессии для handler.
type Scope = model.Scope

type Service struct {
	cfg        config.Config
	shared     *sql.DB
	dataPlane  *dataplane.Resolver
	licensing  *licensingclient.Client
	roles      *repository.RoleRepository
	versioning *versioning.Recorder
	realtime   *mert.Notifier
}

func New(cfg config.Config, shared *sql.DB, dp *dataplane.Resolver) *Service {
	if dp == nil && shared != nil {
		dp = dataplane.NewResolver(cfg, shared)
	}
	return &Service{
		cfg:        cfg,
		shared:     shared,
		dataPlane:  dp,
		licensing:  licensingclient.New(cfg, shared),
		roles:      repository.NewRoleRepository(shared),
		versioning: versioning.NewRecorder(cfg, shared),
		realtime:   mert.NewNotifier(publisher.New(cfg)),
	}
}

func (s *Service) dataRepo(scope model.Scope) *mrepo.Repository {
	if s.dataPlane != nil {
		db, err := s.dataPlane.DB(context.Background(), scope.TenantID)
		if err == nil && db != nil {
			return mrepo.New(db)
		}
	}
	return mrepo.New(s.shared)
}

type CreateManifestInput struct {
	Code     string             `json:"code"`
	Name     string             `json:"name"`
	Type     *string            `json:"type,omitempty"`
	Section  *string            `json:"section,omitempty"`
	Fields   []model.FieldDef   `json:"fields"`
	Metadata map[string]any     `json:"metadata"`
}

func (s *Service) CreateManifest(scope model.Scope, in CreateManifestInput) (map[string]any, int) {
	if deny := s.guardLicensed(scope); deny != nil {
		return deny, int(deny["status"].(int))
	}
	roles, err := s.userRoles(scope)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	if !model.IsManifestAdmin(roles) {
		return fail("требуется роль tenant_admin или subtenant_admin", fiber.StatusForbidden)
	}

	codeNorm := code.Normalize(in.Code)
	if codeNorm == "" || strings.TrimSpace(in.Name) == "" {
		return fail("code и name обязательны", fiber.StatusUnprocessableEntity)
	}
	if err := model.AssertClientMayDefineManifest(codeNorm, in.Metadata, presets.IsReservedCode); err != nil {
		return fail(err.Error(), fiber.StatusUnprocessableEntity)
	}
	fields, err := model.ParseFieldDefs(in.Fields)
	if err != nil {
		return fail(err.Error(), fiber.StatusUnprocessableEntity)
	}
	meta := model.ApplyDocCatalogMetadata(in.Metadata, in.Type, in.Section)
	repo := s.dataRepo(scope)
	m, err := repo.CreateManifest(scope, codeNorm, strings.TrimSpace(in.Name), model.OriginCustom, fields, meta, scope.UserID)
	if err != nil {
		if strings.Contains(err.Error(), "duplicate") || strings.Contains(err.Error(), "unique") {
			return fail("manifest уже существует", fiber.StatusConflict)
		}
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	_ = repo.WriteAudit("manifest.created", scope.TenantID, scope.ProjectID, m.Code, nil, scope.UserID, map[string]any{"name": m.Name})
	s.realtime.Manifest(scope, m, "manifest.created")
	return ok(map[string]any{"manifest": mrepo.PublicManifest(m)}, fiber.StatusCreated)
}

func (s *Service) ListManifests(scope model.Scope, originRaw string) (map[string]any, int) {
	if deny := s.guardLicensed(scope); deny != nil {
		return deny, int(deny["status"].(int))
	}
	origin, valid := model.NormalizeOrigin(originRaw)
	if !valid {
		return fail("origin: ожидается platform, custom или пусто", fiber.StatusUnprocessableEntity)
	}
	items, err := s.dataRepo(scope).ListManifests(scope, origin, 100)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	out := make([]map[string]any, 0, len(items))
	for i := range items {
		out = append(out, mrepo.PublicManifest(&items[i]))
	}
	return ok(map[string]any{"manifests": out, "filter": map[string]any{"origin": origin}}, fiber.StatusOK)
}

func (s *Service) GetManifest(scope model.Scope, entityCode string) (map[string]any, int) {
	if deny := s.guardLicensed(scope); deny != nil {
		return deny, int(deny["status"].(int))
	}
	m, err := s.loadManifest(scope, entityCode)
	if err != nil {
		return fail(err.Error(), fiber.StatusNotFound)
	}
	return ok(map[string]any{"manifest": mrepo.PublicManifest(m)}, fiber.StatusOK)
}

func (s *Service) UpdateManifest(scope model.Scope, entityCode string, in CreateManifestInput) (map[string]any, int) {
	if deny := s.guardLicensed(scope); deny != nil {
		return deny, int(deny["status"].(int))
	}
	roles, err := s.userRoles(scope)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	if !model.IsManifestAdmin(roles) {
		return fail("требуется роль tenant_admin или subtenant_admin", fiber.StatusForbidden)
	}

	fields, err := model.ParseFieldDefs(in.Fields)
	if err != nil {
		return fail(err.Error(), fiber.StatusUnprocessableEntity)
	}
	name := strings.TrimSpace(in.Name)
	if name == "" {
		return fail("name обязателен", fiber.StatusUnprocessableEntity)
	}
	entity := code.Normalize(entityCode)
	repo := s.dataRepo(scope)
	existing, err := repo.GetManifestByCode(scope, entity)
	if err != nil || existing == nil {
		return fail("manifest не найден", fiber.StatusNotFound)
	}
	if err := model.AssertClientMayMutateManifest(existing); err != nil {
		return fail(err.Error(), fiber.StatusForbidden)
	}
	meta := existing.Metadata
	if in.Metadata != nil {
		meta = in.Metadata
	}
	meta = model.ApplyDocCatalogMetadata(meta, in.Type, in.Section)
	m, err := repo.UpdateManifest(scope, entity, name, fields, meta)
	if err != nil || m == nil {
		return fail("manifest не найден", fiber.StatusNotFound)
	}
	_ = repo.WriteAudit("manifest.updated", scope.TenantID, scope.ProjectID, entity, nil, scope.UserID, map[string]any{"version": m.Version})
	s.realtime.Manifest(scope, m, "manifest.updated")
	return ok(map[string]any{"manifest": mrepo.PublicManifest(m)}, fiber.StatusOK)
}

func (s *Service) DeleteManifest(scope model.Scope, entityCode string) (map[string]any, int) {
	if deny := s.guardLicensed(scope); deny != nil {
		return deny, int(deny["status"].(int))
	}
	roles, err := s.userRoles(scope)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	if !model.IsManifestAdmin(roles) {
		return fail("требуется роль tenant_admin или subtenant_admin", fiber.StatusForbidden)
	}
	entity := code.Normalize(entityCode)
	repo := s.dataRepo(scope)
	existing, err := repo.GetManifestByCode(scope, entity)
	if err != nil || existing == nil {
		return fail("manifest не найден", fiber.StatusNotFound)
	}
	if err := model.AssertClientMayMutateManifest(existing); err != nil {
		return fail(err.Error(), fiber.StatusForbidden)
	}
	okArch, err := repo.ArchiveManifest(scope, entity)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	if !okArch {
		return fail("manifest не найден", fiber.StatusNotFound)
	}
	_ = repo.WriteAudit("manifest.archived", scope.TenantID, scope.ProjectID, entity, nil, scope.UserID, nil)
	if existing != nil {
		existing.Status = "archived"
		s.realtime.Manifest(scope, existing, "manifest.archived")
	}
	return ok(map[string]any{"archived": true, "code": entity}, fiber.StatusOK)
}

func (s *Service) OpenAPI(scope model.Scope, entityCode, baseURL string) (map[string]any, int) {
	if deny := s.guardLicensed(scope); deny != nil {
		return deny, int(deny["status"].(int))
	}
	m, err := s.loadManifest(scope, entityCode)
	if err != nil {
		return fail(err.Error(), fiber.StatusNotFound)
	}
	return ok(map[string]any{"openapi": model.OpenAPISpec(m, baseURL)}, fiber.StatusOK)
}

func (s *Service) OpenAPIYAML(scope model.Scope, entityCode, baseURL string) ([]byte, int, string) {
	if deny := s.guardLicensed(scope); deny != nil {
		return nil, int(deny["status"].(int)), deny["error"].(string)
	}
	m, err := s.loadManifest(scope, entityCode)
	if err != nil {
		return nil, fiber.StatusNotFound, err.Error()
	}
	raw, err := model.OpenAPIYAML(m, baseURL)
	if err != nil {
		return nil, fiber.StatusInternalServerError, err.Error()
	}
	return raw, fiber.StatusOK, ""
}

func (s *Service) ListRecords(scope model.Scope, entityCode string, limit, offset int, filterRaw string) (map[string]any, int) {
	if deny := s.guardLicensed(scope); deny != nil {
		return deny, int(deny["status"].(int))
	}
	m, err := s.loadManifest(scope, entityCode)
	if err != nil {
		return fail(err.Error(), fiber.StatusNotFound)
	}
	filter, err := model.ParseRecordFilter(filterRaw)
	if err != nil {
		return fail(err.Error(), fiber.StatusUnprocessableEntity)
	}
	if err := model.ValidateFilterKeys(m.Fields, filter); err != nil {
		return fail(err.Error(), fiber.StatusUnprocessableEntity)
	}
	roles, _ := s.userRoles(scope)
	repo := s.dataRepo(scope)
	result, err := repo.ListRecordsFiltered(m.ID, scope, m.Fields, filter, limit, offset)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	out := make([]map[string]any, 0, len(result.Records))
	for i := range result.Records {
		result.Records[i].Data = model.FilterReadableData(roles, m.Fields, result.Records[i].Data)
		out = append(out, mrepo.PublicRecord(&result.Records[i]))
	}
	return ok(map[string]any{
		"records": out,
		"entity":  m.Code,
		"meta": map[string]any{
			"total":  result.Total,
			"limit":  clampLimit(limit),
			"offset": maxOffset(offset),
			"count":  len(out),
		},
	}, fiber.StatusOK)
}

func clampLimit(limit int) int {
	if limit <= 0 || limit > 200 {
		return 50
	}
	return limit
}

func maxOffset(offset int) int {
	if offset < 0 {
		return 0
	}
	return offset
}

func (s *Service) CreateRecord(scope model.Scope, entityCode string, data map[string]any) (map[string]any, int) {
	if deny := s.guardLicensed(scope); deny != nil {
		return deny, int(deny["status"].(int))
	}
	m, err := s.loadManifest(scope, entityCode)
	if err != nil {
		return fail(err.Error(), fiber.StatusNotFound)
	}
	roles, err := s.userRoles(scope)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	keys := mapKeys(data)
	if err := model.ValidateWritableKeys(roles, m.Fields, keys); err != nil {
		if model.IsForbidden(err) {
			return fail(err.Error(), fiber.StatusForbidden)
		}
		return fail(err.Error(), fiber.StatusUnprocessableEntity)
	}
	if err := model.ValidateData(m.Fields, data, false); err != nil {
		return fail(err.Error(), fiber.StatusUnprocessableEntity)
	}
	repo := s.dataRepo(scope)
	rec, err := repo.CreateRecord(m.ID, scope, data, scope.UserID)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	rid := rec.ID
	_ = repo.WriteAudit("record.created", scope.TenantID, scope.ProjectID, m.Code, &rid, scope.UserID, nil)
	s.recordVersion(scope, m.Code, "insert", nil, rec)
	s.realtime.Record(scope, m, "record.created", rec.ID)
	rec.Data = model.FilterReadableData(roles, m.Fields, rec.Data)
	return ok(map[string]any{"record": mrepo.PublicRecord(rec)}, fiber.StatusCreated)
}

func (s *Service) GetRecord(scope model.Scope, entityCode string, id int64) (map[string]any, int) {
	if deny := s.guardLicensed(scope); deny != nil {
		return deny, int(deny["status"].(int))
	}
	m, err := s.loadManifest(scope, entityCode)
	if err != nil {
		return fail(err.Error(), fiber.StatusNotFound)
	}
	repo := s.dataRepo(scope)
	rec, err := repo.GetRecord(scope, id)
	if err != nil || rec == nil {
		return fail("запись не найдена", fiber.StatusNotFound)
	}
	roles, _ := s.userRoles(scope)
	rec.Data = model.FilterReadableData(roles, m.Fields, rec.Data)
	return ok(map[string]any{"record": mrepo.PublicRecord(rec)}, fiber.StatusOK)
}

func (s *Service) PatchRecord(scope model.Scope, entityCode string, id int64, patch map[string]any) (map[string]any, int) {
	if deny := s.guardLicensed(scope); deny != nil {
		return deny, int(deny["status"].(int))
	}
	m, err := s.loadManifest(scope, entityCode)
	if err != nil {
		return fail(err.Error(), fiber.StatusNotFound)
	}
	roles, err := s.userRoles(scope)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	if err := model.ValidateWritableKeys(roles, m.Fields, mapKeys(patch)); err != nil {
		if model.IsForbidden(err) {
			return fail(err.Error(), fiber.StatusForbidden)
		}
		return fail(err.Error(), fiber.StatusUnprocessableEntity)
	}
	if err := model.ValidateData(m.Fields, patch, true); err != nil {
		return fail(err.Error(), fiber.StatusUnprocessableEntity)
	}
	repo := s.dataRepo(scope)
	rec, err := repo.GetRecord(scope, id)
	if err != nil || rec == nil {
		return fail("запись не найдена", fiber.StatusNotFound)
	}
	merged := cloneMap(rec.Data)
	for k, v := range patch {
		merged[k] = v
	}
	if err := model.ValidateData(m.Fields, merged, false); err != nil {
		return fail(err.Error(), fiber.StatusUnprocessableEntity)
	}
	before := cloneRecord(rec)
	updated, err := repo.UpdateRecord(scope, id, merged, scope.UserID)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	_ = repo.WriteAudit("record.updated", scope.TenantID, scope.ProjectID, m.Code, &id, scope.UserID, map[string]any{"keys": mapKeys(patch)})
	s.recordVersion(scope, m.Code, "update", before, updated)
	s.realtime.Record(scope, m, "record.updated", id)
	updated.Data = model.FilterReadableData(roles, m.Fields, updated.Data)
	return ok(map[string]any{"record": mrepo.PublicRecord(updated)}, fiber.StatusOK)
}

func (s *Service) PutField(scope model.Scope, entityCode string, id int64, fieldPath string, value any) (map[string]any, int) {
	if deny := s.guardLicensed(scope); deny != nil {
		return deny, int(deny["status"].(int))
	}
	m, err := s.loadManifest(scope, entityCode)
	if err != nil {
		return fail(err.Error(), fiber.StatusNotFound)
	}
	roles, err := s.userRoles(scope)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	topField := topLevelField(fieldPath)
	if topField != "" {
		if err := model.ValidateWritableKeys(roles, m.Fields, []string{topField}); err != nil {
			if model.IsForbidden(err) {
				return fail(err.Error(), fiber.StatusForbidden)
			}
		}
	}
	repo := s.dataRepo(scope)
	rec, err := repo.GetRecord(scope, id)
	if err != nil || rec == nil {
		return fail("запись не найдена", fiber.StatusNotFound)
	}
	data := cloneMap(rec.Data)
	if err := model.SetFieldPath(data, fieldPath, value); err != nil {
		return fail(err.Error(), fiber.StatusUnprocessableEntity)
	}
	if err := model.ValidateData(m.Fields, data, false); err != nil {
		return fail(err.Error(), fiber.StatusUnprocessableEntity)
	}
	before := cloneRecord(rec)
	updated, err := repo.UpdateRecord(scope, id, data, scope.UserID)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	_ = repo.WriteAudit("record.field_updated", scope.TenantID, scope.ProjectID, m.Code, &id, scope.UserID, map[string]any{"field": fieldPath})
	s.recordVersion(scope, m.Code, "update", before, updated)
	s.realtime.Record(scope, m, "record.updated", id)
	updated.Data = model.FilterReadableData(roles, m.Fields, updated.Data)
	return ok(map[string]any{
		"record": mrepo.PublicRecord(updated),
		"field":  fieldPath,
		"value":  value,
	}, fiber.StatusOK)
}

func (s *Service) DeleteField(scope model.Scope, entityCode string, id int64, fieldPath string) (map[string]any, int) {
	if deny := s.guardLicensed(scope); deny != nil {
		return deny, int(deny["status"].(int))
	}
	m, err := s.loadManifest(scope, entityCode)
	if err != nil {
		return fail(err.Error(), fiber.StatusNotFound)
	}
	roles, err := s.userRoles(scope)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	topField := topLevelField(fieldPath)
	if topField != "" {
		if err := model.ValidateWritableKeys(roles, m.Fields, []string{topField}); err != nil {
			if model.IsForbidden(err) {
				return fail(err.Error(), fiber.StatusForbidden)
			}
		}
	}
	repo := s.dataRepo(scope)
	rec, err := repo.GetRecord(scope, id)
	if err != nil || rec == nil {
		return fail("запись не найдена", fiber.StatusNotFound)
	}
	data := cloneMap(rec.Data)
	if err := model.ClearFieldPath(data, fieldPath); err != nil {
		return fail(err.Error(), fiber.StatusUnprocessableEntity)
	}
	if err := model.ValidateData(m.Fields, data, false); err != nil {
		return fail(err.Error(), fiber.StatusUnprocessableEntity)
	}
	before := cloneRecord(rec)
	updated, err := repo.UpdateRecord(scope, id, data, scope.UserID)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	_ = repo.WriteAudit("record.field_cleared", scope.TenantID, scope.ProjectID, m.Code, &id, scope.UserID, map[string]any{"field": fieldPath})
	s.recordVersion(scope, m.Code, "update", before, updated)
	s.realtime.Record(scope, m, "record.updated", id)
	updated.Data = model.FilterReadableData(roles, m.Fields, updated.Data)
	return ok(map[string]any{
		"record": mrepo.PublicRecord(updated),
		"field":  fieldPath,
		"value":  nil,
	}, fiber.StatusOK)
}

func (s *Service) DeleteRecord(scope model.Scope, entityCode string, id int64) (map[string]any, int) {
	if deny := s.guardLicensed(scope); deny != nil {
		return deny, int(deny["status"].(int))
	}
	m, err := s.loadManifest(scope, entityCode)
	if err != nil {
		return fail(err.Error(), fiber.StatusNotFound)
	}
	roles, err := s.userRoles(scope)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	if !model.IsManifestAdmin(roles) {
		// обычный user может удалять только если все поля writable — упрощённо: admin only для delete
		return fail("удаление записи требует admin-роли", fiber.StatusForbidden)
	}
	repo := s.dataRepo(scope)
	rec, err := repo.GetRecord(scope, id)
	if err != nil || rec == nil {
		return fail("запись не найдена", fiber.StatusNotFound)
	}
	before := cloneRecord(rec)
	okDel, err := repo.SoftDeleteRecord(scope, id, scope.UserID)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	if !okDel {
		return fail("запись не найдена", fiber.StatusNotFound)
	}
	_ = repo.WriteAudit("record.deleted", scope.TenantID, scope.ProjectID, m.Code, &id, scope.UserID, nil)
	s.recordVersion(scope, m.Code, "delete", before, nil)
	s.realtime.Record(scope, m, "record.deleted", id)
	return ok(map[string]any{"deleted": true, "id": id}, fiber.StatusOK)
}

func (s *Service) guardLicensed(scope model.Scope) map[string]any {
	projectCode := repository.ProjectCodeForSession(s.shared, scope.TenantID, sql.NullInt64{Int64: scope.ProjectID, Valid: true})
	decision := s.licensing.AssertAccess(scope.TenantID, projectCode, scope.SubtenantID)
	if decision.OK {
		return nil
	}
	status := decision.Status
	if status == 0 {
		status = http.StatusForbidden
	}
	return map[string]any{"ok": false, "error": decision.Error, "status": status}
}

func (s *Service) userRoles(scope model.Scope) ([]string, error) {
	return s.roles.ListRoleCodesForUser(scope.UserID, scope.TenantID, scope.SubtenantID)
}

func (s *Service) loadManifest(scope model.Scope, entityCode string) (*model.Manifest, error) {
	m, err := s.dataRepo(scope).GetManifestByCode(scope, code.Normalize(entityCode))
	if err != nil {
		return nil, err
	}
	if m == nil {
		return nil, fmt.Errorf("manifest не найден")
	}
	return m, nil
}

func ScopeFromSession(session *repository.SessionRecord) (model.Scope, error) {
	if session == nil {
		return model.Scope{}, fmt.Errorf("нет сессии")
	}
	if !session.ProjectID.Valid {
		return model.Scope{}, fmt.Errorf("project_id обязателен в сессии")
	}
	return model.Scope{
		TenantID:    session.TenantID,
		SubtenantID: session.SubtenantID,
		ProjectID:   session.ProjectID.Int64,
		UserID:      session.UserID,
	}, nil
}

func mapKeys(m map[string]any) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	return keys
}

func topLevelField(path string) string {
	path = strings.Trim(path, "/")
	if path == "" {
		return ""
	}
	return strings.Split(path, "/")[0]
}

func cloneMap(in map[string]any) map[string]any {
	out := make(map[string]any, len(in))
	for k, v := range in {
		out[k] = v
	}
	return out
}

func cloneRecord(rec *model.Record) *model.Record {
	if rec == nil {
		return nil
	}
	cp := *rec
	cp.Data = cloneMap(rec.Data)
	return &cp
}

func fail(msg string, status int) (map[string]any, int) {
	return map[string]any{"ok": false, "error": msg, "status": status}, status
}

func ok(payload map[string]any, status int) (map[string]any, int) {
	payload["ok"] = true
	payload["status"] = status
	return payload, status
}

func ParseID(raw string) (int64, error) {
	return strconv.ParseInt(strings.TrimSpace(raw), 10, 64)
}
