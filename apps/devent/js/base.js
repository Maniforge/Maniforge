(function (w) {
  function deventBase() {
    const p = w.location.pathname;
    const app = p.indexOf("/app/");
    if (app >= 0) return p.slice(0, app);
    if (/\/login\.html$/.test(p)) return p.replace(/\/login\.html$/, "");
    if (/\/login\/?$/.test(p)) return p.replace(/\/login\/?$/, "");
    if (p.endsWith("/health")) return p.replace(/\/health$/, "");
    return "";
  }

  const BASE = deventBase();
  w.DEVENT_BASE = BASE;
  w.deventUrl = function (path) {
    if (!path) return BASE || "/";
    if (/^https?:/i.test(path)) return path;
    if (path.charAt(0) !== "/") path = "/" + path;
    return BASE + path;
  };

  function rewrite() {
    document.querySelectorAll("a[href^='/']").forEach((a) => {
      const href = a.getAttribute("href");
      if (!href || href.startsWith("//")) return;
      if (
        href.startsWith("/app/") ||
        href === "/login" ||
        href.startsWith("/login?") ||
        href.startsWith("/api/")
      ) {
        a.setAttribute("href", w.deventUrl(href));
      }
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", rewrite);
  } else {
    rewrite();
  }
})(window);
