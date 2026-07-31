<?php
    function asset($path) {
	$full = $_SERVER['DOCUMENT_ROOT'] . $path;
	return $path . '?v=' . (file_exists($full) ? filemtime($full) : time());
    }

    /**
    * Handles redirect URL logic
    *
    * @param string $param      Query parameter name (default "link")
    * @param string $defaultUrl Fallback if not provided or invalid
    * @return string            Safe redirect URL
    */
    function getRedirectUrl($param = "link", $defaultUrl = "https://panel.frostbyt3gaming.com/") {
        $redirectUrl = isset($_GET[$param]) && !empty($_GET[$param])
            ? $_GET[$param]
            : $defaultUrl;

        // Sanitize (avoid things like javascript:)
        if (!filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
            $redirectUrl = $defaultUrl;
        }

        return $redirectUrl;
    }
?>