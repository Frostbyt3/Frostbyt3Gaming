document.addEventListener("DOMContentLoaded", function () {
    const siteDomain = "frostbyt3gaming.com";

    document.querySelectorAll("a[href]").forEach(link => {
        const rawHref = link.getAttribute("href");
        if (!rawHref) return;

        if (
            link.hasAttribute("data-bypass-leave") ||
            rawHref.startsWith("#") ||
            rawHref.startsWith("javascript:") ||
            rawHref.startsWith("mailto:") ||
            rawHref.startsWith("tel:")
        ) {
            return;
        }

        let url;
        try {
            url = new URL(rawHref, window.location.origin);
        } catch {
            return;
        }

        const hostname = url.hostname;
        const currentHostname = window.location.hostname;

        const isInternal =
            url.origin === window.location.origin ||
            hostname === currentHostname ||
            hostname === "127.0.0.1" ||
            hostname === "localhost" ||
            hostname === siteDomain ||
            hostname.endsWith("." + siteDomain);

        if (isInternal) return;

        link.addEventListener("click", function (e) {
            e.preventDefault();
            window.location.href = "/page.php?name=leave&url=" + encodeURIComponent(url.href);
        });
    });
});