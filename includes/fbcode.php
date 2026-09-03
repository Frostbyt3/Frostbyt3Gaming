<?php
declare(strict_types=1);

require_once __DIR__ . '/fbcode-vendor.php';

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRCodeOutputException;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

final class FBGCodeException extends RuntimeException
{
}

final class FBGCodePngWithLogo extends QRGdImagePNG
{
    public function dump(string|null $file = null, string|null $logo = null): string
    {
        $logo = (string)$logo;
        $this->options->returnResource = true;

        if ($logo === '' || !is_file($logo) || !is_readable($logo)) {
            throw new QRCodeOutputException('invalid logo');
        }

        parent::dump($file);

        $logoImage = imagecreatefrompng($logo);
        if ($logoImage === false) {
            throw new QRCodeOutputException('imagecreatefrompng() error');
        }

        imagealphablending($this->image, true);
        imagesavealpha($this->image, true);
        imagealphablending($logoImage, true);
        imagesavealpha($logoImage, true);

        $logoWidth = imagesx($logoImage);
        $logoHeight = imagesy($logoImage);
        $spaceWidth = max(1, (int)$this->options->logoSpaceWidth);
        $spaceHeight = max(1, (int)$this->options->logoSpaceHeight);
        $targetWidth = max(1, ($spaceWidth - 2) * $this->options->scale);
        $targetHeight = max(1, ($spaceHeight - 2) * $this->options->scale);
        $qrSize = $this->matrix->getSize() * $this->options->scale;

        imagecopyresampled(
            $this->image,
            $logoImage,
            (int)(($qrSize - $targetWidth) / 2),
            (int)(($qrSize - $targetHeight) / 2),
            0,
            0,
            $targetWidth,
            $targetHeight,
            $logoWidth,
            $logoHeight
        );

        imagedestroy($logoImage);

        $imageData = $this->dumpImage();
        $this->saveToFile($imageData, $file);

        return $imageData;
    }
}

function fbgCodeDefaultOptions(): array
{
    return [
        'content' => 'https://frostbyt3gaming.com',
        'format' => 'svg',
        'size' => 720,
        'pattern_color' => '#14b8ff',
        'background_color' => '#0d1117',
        'module_style' => 'rounded',
        'logo_enabled' => true,
        'logo_scale' => 0.2,
        'ecc_level' => 'H',
        'quiet_zone' => 4,
        'draw_light_modules' => true,
        'connect_paths' => true,
        'finder_style' => 'square',
    ];
}

function fbgCodeNormalizeOptions(array $input): array
{
    $defaults = fbgCodeDefaultOptions();
    $content = trim((string)($input['content'] ?? $defaults['content']));

    if ($content === '') {
        throw new FBGCodeException('Enter the text or destination the FBCode should open.');
    }

    if (mb_strlen($content) > 2500) {
        throw new FBGCodeException('FBCode content is too long. Keep it under 2,500 characters.');
    }

    $format = strtolower(trim((string)($input['format'] ?? $defaults['format'])));
    if (!in_array($format, ['svg', 'png'], true)) {
        throw new FBGCodeException('Choose SVG or PNG output.');
    }

    $size = max(192, min(1600, (int)($input['size'] ?? $defaults['size'])));
    $patternColor = fbgCodeNormalizeHexColor((string)($input['pattern_color'] ?? $defaults['pattern_color']), 'pattern color');
    $backgroundColor = fbgCodeNormalizeHexColor((string)($input['background_color'] ?? $defaults['background_color']), 'background color');
    $moduleStyle = strtolower(trim((string)($input['module_style'] ?? $defaults['module_style'])));

    if (!in_array($moduleStyle, ['square', 'rounded'], true)) {
        $moduleStyle = $defaults['module_style'];
    }

    $logoEnabled = fbgCodeNormalizeBool($input['logo_enabled'] ?? $defaults['logo_enabled']);
    $logoScale = max(0.12, min(0.3, (float)($input['logo_scale'] ?? $defaults['logo_scale'])));
    $eccLevel = strtoupper(trim((string)($input['ecc_level'] ?? $defaults['ecc_level'])));

    if (!in_array($eccLevel, ['L', 'M', 'Q', 'H'], true)) {
        $eccLevel = $defaults['ecc_level'];
    }

    if ($logoEnabled && $eccLevel !== 'H') {
        $eccLevel = 'H';
    }

    $quietZone = max(0, min(12, (int)($input['quiet_zone'] ?? $defaults['quiet_zone'])));
    $drawLightModules = fbgCodeNormalizeBool($input['draw_light_modules'] ?? $defaults['draw_light_modules']);
    $connectPaths = fbgCodeNormalizeBool($input['connect_paths'] ?? $defaults['connect_paths']);
    $finderStyle = strtolower(trim((string)($input['finder_style'] ?? $defaults['finder_style'])));

    if (!in_array($finderStyle, ['square', 'match'], true)) {
        $finderStyle = $defaults['finder_style'];
    }

    return [
        'content' => $content,
        'format' => $format,
        'size' => $size,
        'pattern_color' => $patternColor,
        'background_color' => $backgroundColor,
        'module_style' => $moduleStyle,
        'logo_enabled' => $logoEnabled,
        'logo_scale' => $logoScale,
        'ecc_level' => $eccLevel,
        'quiet_zone' => $quietZone,
        'draw_light_modules' => $drawLightModules,
        'connect_paths' => $connectPaths,
        'finder_style' => $finderStyle,
    ];
}

function fbgCodeGenerate(array $input): array
{
    $options = fbgCodeNormalizeOptions($input);

    if ($options['format'] === 'png' && !extension_loaded('gd')) {
        throw new FBGCodeException('PNG output needs the GD PHP extension.');
    }

    if ($options['logo_enabled'] && !is_readable(fbgCodeLogoPath())) {
        throw new FBGCodeException('The Frostbyt3 logo asset could not be loaded.');
    }

    $qrOptions = fbgCodeBuildQrOptions($options);
    $qrCode = new QRCode($qrOptions);

    if ($options['format'] === 'png' && $options['logo_enabled']) {
        $renderer = new FBGCodePngWithLogo($qrOptions, $qrCode->addByteSegment($options['content'])->getQRMatrix());
        $body = $renderer->dump(null, fbgCodeLogoPath());
    } else {
        $body = $qrCode->render($options['content']);
    }

    if ($options['format'] === 'svg' && $options['logo_enabled']) {
        $body = fbgCodeInjectSvgLogo($body, $options['logo_scale']);
    }

    return [
        'body' => $body,
        'mime' => $options['format'] === 'png' ? 'image/png' : 'image/svg+xml',
        'extension' => $options['format'],
        'options' => $options,
        'warnings' => fbgCodeWarnings($options),
    ];
}

function fbgCodeBuildQrOptions(array $options): QROptions
{
    $eccMap = [
        'L' => EccLevel::L,
        'M' => EccLevel::M,
        'Q' => EccLevel::Q,
        'H' => EccLevel::H,
    ];
    $rgbDark = fbgCodeHexToRgb($options['pattern_color']);
    $rgbLight = fbgCodeHexToRgb($options['background_color']);
    $isRounded = $options['module_style'] === 'rounded';
    $logoModules = $options['logo_enabled'] ? max(7, min(15, (int)round($options['logo_scale'] * 53))) : null;
    $keepAsSquare = [];

    if ($isRounded && $options['finder_style'] === 'square') {
        $keepAsSquare = [
            QRMatrix::M_FINDER,
            QRMatrix::M_FINDER_DARK,
            QRMatrix::M_FINDER_DOT,
            QRMatrix::M_FINDER_DOT_LIGHT,
            QRMatrix::M_ALIGNMENT,
            QRMatrix::M_ALIGNMENT_DARK,
        ];
    }

    $libraryOptions = [
        'version' => -1,
        'versionMin' => $options['logo_enabled'] ? 5 : 1,
        'eccLevel' => $eccMap[$options['ecc_level']],
        'scale' => max(4, min(40, (int)round($options['size'] / 41))),
        'outputBase64' => false,
        'outputInterface' => $options['format'] === 'png' ? QRGdImagePNG::class : QRMarkupSVG::class,
        'addQuietzone' => $options['quiet_zone'] > 0,
        'quietzoneSize' => $options['quiet_zone'],
        'drawLightModules' => $options['draw_light_modules'],
        'drawCircularModules' => $isRounded,
        'circleRadius' => $isRounded ? 0.45 : 0.5,
        'keepAsSquare' => $keepAsSquare,
        'connectPaths' => $options['format'] === 'svg' && $options['connect_paths'],
    ];

    if ($options['format'] === 'png') {
        $libraryOptions['bgColor'] = $rgbLight;
        $libraryOptions['moduleValues'] = fbgCodeBuildPngModuleValues($rgbDark, $rgbLight);
    } else {
        $libraryOptions['svgAddXmlHeader'] = false;
        $libraryOptions['svgDefs'] = sprintf(
            '<style><![CDATA[.dark{fill:%1$s}.light{fill:%2$s}.fbg-code-logo-bg{fill:%2$s;opacity:.92}]]></style>',
            $options['pattern_color'],
            $options['background_color']
        );
        $libraryOptions['svgUseFillAttributes'] = false;
    }

    if ($logoModules !== null) {
        $libraryOptions['addLogoSpace'] = true;
        $libraryOptions['logoSpaceWidth'] = $logoModules;
        $libraryOptions['logoSpaceHeight'] = $logoModules;
    }

    return new QROptions($libraryOptions);
}

function fbgCodeBuildPngModuleValues(array $rgbDark, array $rgbLight): array
{
    $moduleValues = [];

    foreach ((new ReflectionClass(QRMatrix::class))->getConstants() as $name => $value) {
        if (!is_int($value) || !str_starts_with((string)$name, 'M_')) {
            continue;
        }

        $moduleValues[$value] = ($value & QRMatrix::IS_DARK) === QRMatrix::IS_DARK
            ? $rgbDark
            : $rgbLight;
    }

    return $moduleValues;
}

function fbgCodeInjectSvgLogo(string $svg, float $logoScale): string
{
    if (!preg_match('/viewBox="0 0 ([0-9.]+) ([0-9.]+)"/', $svg, $matches)) {
        return $svg;
    }

    $viewBoxWidth = (float)$matches[1];
    $viewBoxHeight = (float)$matches[2];
    $logoSize = max(5.0, min($viewBoxWidth * 0.32, $viewBoxWidth * $logoScale));
    $logoX = ($viewBoxWidth - $logoSize) / 2;
    $logoY = ($viewBoxHeight - $logoSize) / 2;
    $padding = $logoSize * 0.18;
    $logoHref = 'data:image/png;base64,' . base64_encode((string)file_get_contents(fbgCodeLogoPath()));
    $logoSvg = sprintf(
        '<rect class="fbg-code-logo-bg" x="%1$.3F" y="%2$.3F" width="%3$.3F" height="%3$.3F" rx="%4$.3F"/><image href="%5$s" x="%6$.3F" y="%7$.3F" width="%8$.3F" height="%8$.3F" preserveAspectRatio="xMidYMid meet"/>',
        $logoX - $padding,
        $logoY - $padding,
        $logoSize + ($padding * 2),
        $padding,
        htmlspecialchars($logoHref, ENT_QUOTES, 'UTF-8'),
        $logoX,
        $logoY,
        $logoSize
    );

    return str_replace('</svg>', $logoSvg . '</svg>', $svg);
}

function fbgCodeNormalizeHexColor(string $value, string $label): string
{
    $value = trim($value);

    if (preg_match('/^#[0-9a-f]{6}$/i', $value) !== 1) {
        throw new FBGCodeException('Choose a valid ' . $label . '.');
    }

    return strtoupper($value);
}

function fbgCodeHexToRgb(string $hex): array
{
    return [
        hexdec(substr($hex, 1, 2)),
        hexdec(substr($hex, 3, 2)),
        hexdec(substr($hex, 5, 2)),
    ];
}

function fbgCodeNormalizeBool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    return in_array((string)$value, ['1', 'true', 'on', 'yes'], true);
}

function fbgCodeLogoPath(): string
{
    return dirname(__DIR__) . '/backend/img/Snowflake.png';
}

function fbgCodeDownloadFilename(array $options): string
{
    $content = strtolower((string)$options['content']);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $content) ?: 'fbcode';
    $slug = trim(substr($slug, 0, 42), '-') ?: 'fbcode';

    return $slug . '-' . date('Ymd-His') . '.' . $options['format'];
}

function fbgCodeWarnings(array $options): array
{
    $contrast = fbgCodeContrastRatio($options['pattern_color'], $options['background_color']);

    if ($contrast < 3.0) {
        return ['The pattern and background colors are low contrast. Some scanners may struggle with this FBCode.'];
    }

    return [];
}

function fbgCodeContrastRatio(string $foreground, string $background): float
{
    $fg = fbgCodeRelativeLuminance(fbgCodeHexToRgb($foreground));
    $bg = fbgCodeRelativeLuminance(fbgCodeHexToRgb($background));
    $light = max($fg, $bg);
    $dark = min($fg, $bg);

    return ($light + 0.05) / ($dark + 0.05);
}

function fbgCodeRelativeLuminance(array $rgb): float
{
    $channels = array_map(static function (int $channel): float {
        $value = $channel / 255;
        return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }, $rgb);

    return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
}
