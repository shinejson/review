<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Company Rating SaaS'; ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <?php if (!empty($extraCss)): ?>
        <?php foreach ((array) $extraCss as $extraCssFile): ?>
    <link rel="stylesheet" href="<?php echo $extraCssFile; ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body>
