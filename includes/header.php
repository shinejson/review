<?php
// Base URL for assets: resolves correctly whether the app is served from the
// domain root (http://localhost/) or a subfolder (http://localhost/rate/).
$__projectRoot = str_replace('\\', '/', dirname(__DIR__));
$__docRoot = (!empty($_SERVER['DOCUMENT_ROOT']))
    ? rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])), '/')
    : '';
$assetBase = ($__docRoot !== '' && strpos($__projectRoot, $__docRoot) === 0)
    ? substr($__projectRoot, strlen($__docRoot))
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Company Rating SaaS'; ?></title>
    <link rel="stylesheet" href="<?php echo $assetBase; ?>/assets/css/style.css">
    <?php if (!empty($extraCss)): ?>
        <?php foreach ((array) $extraCss as $extraCssFile): ?>
    <link rel="stylesheet" href="<?php echo $assetBase . $extraCssFile; ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body>
