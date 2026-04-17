<?php
$flash = get_flash_message();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h(APP_NAME); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="topbar">
    <h1><?php echo h(APP_NAME); ?></h1>
</header>
<div class="container">
<?php if ($flash): ?>
    <div class="flash flash-<?php echo h($flash['type']); ?>">
        <?php echo h($flash['message']); ?>
    </div>
<?php endif; ?>
