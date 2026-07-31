<?php
$legalDocs =[
    'terms' => [
        'title' => 'Terms of Service',
        'updated' => 'March 6, 2026',
        'summary1' => 'These Terms of Service govern your access to and use of services operated by Frostbyt3 Gaming LLC, including game servers, websites, management panels, digital services, and related platforms.',
        'summary2' => 'By accessing or using any Frostbyt3 Gaming services, you agree to be bound by these Terms.',
        'file' => './legal/ToS.pdf',
        'subject' => 'Frostbyt3 Gaming Terms Question',
        'icon' => 'fas fa-file-contract'
    ],
    'privacy' => [
        'title' => 'Privacy Policy',
        'updated' => 'March 7, 2026',
        'summary1' => 'This Privacy Policy explains how Frostbyt3 Gaming LLC collects, uses, and protects information when you access or use our services, including game servers, websites, management panels, digital services, and related platforms.',
        'summary2' => 'By accessing or using Frostbyt3 Gaming services, you acknowledge and agree to the practices described in this Privacy Policy.',
        'file' => './legal/Privacy.pdf',
        'subject' => 'Frostbyt3 Gaming Privacy Policy Question',
        'icon' => 'fas fa-user-shield'
    ]
];

$activeDoc = $_GET['doc'] ?? 'terms';
if (!array_key_exists($activeDoc, $legalDocs)) {
    $activeDoc = 'terms';
}

$current = $legalDocs[$activeDoc];
?>

<div class="legal-wrapper">
    <div class="legal-card">

        <div class="legal-switch">
            <a href="./page.php?name=legal&doc=terms" class="legal-tab <?php echo $activeDoc === 'terms' ? 'active' : ''; ?>">
                <i class="fas fa-file-contract"></i> Terms of Service
            </a>

            <a href="./page.php?name=legal&doc=privacy" class="legal-tab <?php echo $activeDoc === 'privacy' ? 'active' : ''; ?>">
                <i class="fas fa-user-shield"></i> Privacy Policy
            </a>
        </div>

        <h1><?php echo htmlspecialchars($current['title']); ?></h1>
        <div class="legal-meta">Last Updated: <?php echo htmlspecialchars($current['updated']); ?></div>

        <p><?php echo htmlspecialchars($current['summary1']); ?></p>
        <p><?php echo htmlspecialchars($current['summary2']); ?></p>

        <div class="legal-actions">
            <a class="legal-btn primary" href="<?php echo htmlspecialchars($current['file']); ?>" target="_blank" rel="noopener noreferrer">
                <i class="fas fa-file-pdf"></i> Download PDF
            </a>

            <a class="legal-btn secondary" href="mailto:support@frostbyt3gaming.com?subject=<?php echo rawurlencode($current['subject']); ?>">
                <i class="fas fa-envelope"></i> Contact Support
            </a>
        </div>

        <div class="legal-divider"></div>

        <iframe class="pdf-frame" src="<?php echo htmlspecialchars($current['file']); ?>"></iframe>

        <p class="legal-note">
            Questions about this document can be sent to
            <a href="mailto:support@frostbyt3gaming.com">support@frostbyt3gaming.com</a>.
        </p>
    </div>
</div>