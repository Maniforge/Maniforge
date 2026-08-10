// Package testutil — HTTP-клиент для интеграционных тестов Manifest Engine.
package testutil

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
)

// Client вызывает Manifest Engine и RBAC.
type Client struct {
	RBACBase string
	MEBase   string
	Token    string
	HTTP     *http.Client
}

func (c *Client) DoJSON(method, url string, body any, token string) (map[string]any, int, error) {
	var raw []byte
	if body != nil {
		raw, _ = json.Marshal(body)
	}
	req, err := http.NewRequest(method, url, bytes.NewReader(raw))
	if err != nil {
		return nil, 0, err
	}
	req.Header.Set("Accept", "application/json")
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	hc := c.HTTP
	if hc == nil {
		hc = http.DefaultClient
	}
	res, err := hc.Do(req)
	if err != nil {
		return nil, 0, err
	}
	defer res.Body.Close()
	var out map[string]any
	if err := json.NewDecoder(res.Body).Decode(&out); err != nil {
		return nil, res.StatusCode, err
	}
	return out, res.StatusCode, nil
}

func (c *Client) Get(path string) (map[string]any, int, error) {
	return c.DoJSON(http.MethodGet, c.MEBase+path, nil, c.Token)
}

func (c *Client) Post(path string, body any) (map[string]any, int, error) {
	return c.DoJSON(http.MethodPost, c.MEBase+path, body, c.Token)
}

func (c *Client) Patch(path string, body any) (map[string]any, int, error) {
	return c.DoJSON(http.MethodPatch, c.MEBase+path, body, c.Token)
}

func (c *Client) Put(path string, body any) (map[string]any, int, error) {
	return c.DoJSON(http.MethodPut, c.MEBase+path, body, c.Token)
}

func (c *Client) Delete(path string) (map[string]any, int, error) {
	return c.DoJSON(http.MethodDelete, c.MEBase+path, nil, c.Token)
}

func (c *Client) RBACPost(path string, body any) (map[string]any, int, error) {
	return c.DoJSON(http.MethodPost, c.RBACBase+path, body, "")
}

func (c *Client) GetRaw(path string) ([]byte, int, error) {
	req, err := http.NewRequest(http.MethodGet, c.MEBase+path, nil)
	if err != nil {
		return nil, 0, err
	}
	req.Header.Set("Authorization", "Bearer "+c.Token)
	hc := c.HTTP
	if hc == nil {
		hc = http.DefaultClient
	}
	res, err := hc.Do(req)
	if err != nil {
		return nil, 0, err
	}
	defer res.Body.Close()
	body, err := io.ReadAll(res.Body)
	return body, res.StatusCode, err
}

func ExpectOK(resp map[string]any, status int, err error) error {
	if err != nil {
		return err
	}
	if status < 200 || status >= 300 {
		return fmt.Errorf("status %d: %v", status, resp["error"])
	}
	if resp == nil || resp["ok"] != true {
		return fmt.Errorf("ok=false: %v", resp)
	}
	return nil
}

func ExpectFail(resp map[string]any, status int, err error, wantStatus int) error {
	if err != nil {
		return err
	}
	if status != wantStatus {
		return fmt.Errorf("ожидался status %d, получен %d: %v", wantStatus, status, resp)
	}
	return nil
}
