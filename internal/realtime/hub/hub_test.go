package hub

import "testing"

func TestBroadcastFiltersTenantAndChannel(t *testing.T) {
	h := New()
	c1 := &Client{TenantID: "t1", SubtenantID: "main", outbound: make(chan []byte, 4)}
	c2 := &Client{TenantID: "t2", SubtenantID: "main", outbound: make(chan []byte, 4)}
	c1.Subscribe([]string{"data.invoice"})
	c2.Subscribe([]string{"data.product"})
	h.Register(c1)
	h.Register(c2)

	n := h.Broadcast("t1", "main", "data.invoice", map[string]any{"x": 1, "origin": "custom"})
	if n != 1 {
		t.Fatalf("delivered=%d want 1", n)
	}
	if len(c1.outbound) != 1 {
		t.Fatal("c1 should receive")
	}
	if len(c2.outbound) != 0 {
		t.Fatal("c2 should not receive")
	}
}
