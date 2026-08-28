package config

import "testing"

const (
	testPublicHost   = "platform.example.com"
	testGatewayPort  = "8080"
	testListenHost   = "127.0.0.1"
	testListenPort   = "9090"
	testListenAddr   = testListenHost + ":" + testListenPort
	testTLListenPort = "9091"
)

func TestJoinPublicOrigin(t *testing.T) {
	t.Parallel()
	cases := []struct {
		appURL string
		port   string
		want   string
	}{
		{"http://" + testPublicHost, testGatewayPort, "http://" + testPublicHost + ":" + testGatewayPort},
		{"http://" + testPublicHost + ":" + testGatewayPort, testGatewayPort, "http://" + testPublicHost + ":" + testGatewayPort},
		{"http://127.0.0.1:3000", testGatewayPort, "http://127.0.0.1:3000"},
		{"https://" + testPublicHost, "", "https://" + testPublicHost},
		{"https://" + testPublicHost, "443", "https://" + testPublicHost},
		{"http://" + testPublicHost + "/", testGatewayPort, "http://" + testPublicHost + ":" + testGatewayPort},
	}
	for _, tc := range cases {
		got := joinPublicOrigin(tc.appURL, tc.port)
		if got != tc.want {
			t.Fatalf("joinPublicOrigin(%q, %q)=%q want %q", tc.appURL, tc.port, got, tc.want)
		}
	}
}

func TestHTTPOriginFromListenAddr(t *testing.T) {
	t.Parallel()
	cases := []struct {
		in, want string
	}{
		{testListenAddr, "http://" + testListenAddr},
		{":" + testListenPort, "http://" + testListenHost + ":" + testListenPort},
		{"0.0.0.0:" + testListenPort, "http://" + testListenHost + ":" + testListenPort},
		{"[::]:" + testTLListenPort, "http://" + testListenHost + ":" + testTLListenPort},
		{"", ""},
		{"not-a-port", ""},
	}
	for _, tc := range cases {
		got := HTTPOriginFromListenAddr(tc.in)
		if got != tc.want {
			t.Fatalf("HTTPOriginFromListenAddr(%q)=%q want %q", tc.in, got, tc.want)
		}
	}
}

func TestJoinInternalHTTP(t *testing.T) {
	t.Parallel()
	got := JoinInternalHTTP(testListenAddr, "/rbac")
	want := "http://" + testListenAddr + "/rbac"
	if got != want {
		t.Fatalf("JoinInternalHTTP rbac = %q want %q", got, want)
	}
	tlAddr := testListenHost + ":" + testTLListenPort
	got = JoinInternalHTTP(tlAddr, "tenant-licensing")
	want = "http://" + tlAddr + "/tenant-licensing"
	if got != want {
		t.Fatalf("JoinInternalHTTP tl = %q want %q", got, want)
	}
}

func TestRBACInternalHTTPURL(t *testing.T) {
	t.Parallel()
	explicit := Config{RBACInternalURL: "http://rbac.internal:" + testListenPort + "/rbac", RBACAddr: testListenAddr}
	if got := explicit.RBACInternalHTTPURL(); got != "http://rbac.internal:"+testListenPort+"/rbac" {
		t.Fatalf("explicit = %q", got)
	}
	derived := Config{RBACAddr: testListenAddr}
	if got := derived.RBACInternalHTTPURL(); got != "http://"+testListenAddr+"/rbac" {
		t.Fatalf("derived = %q", got)
	}
}
