<?php use Cloude\View; ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title ?? 'Contacts') ?> · Cloude demo</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
    <header class="topbar">
        <a href="/" class="brand">📇 Contacts</a>
        <nav>
            <a href="/">All</a>
            <a href="/new" class="btn btn-primary">+ New contact</a>
        </nav>
    </header>

    <main class="container">
        <?php if (!empty($flash)): ?>
            <div class="flash"><?= View::e($flash) ?></div>
        <?php endif; ?>

        <?php if (!empty($content)) {
            require __DIR__ . '/' . $content;
        } ?>
    </main>

    <footer>
        <small>
            Built with <a href="https://packagist.org/packages/cloude/framework"><code>cloude/framework</code></a>.
            Data lives at <code>example/contacts/data/contacts/*.json</code>.
        </small>
    </footer>

    <script src="/assets/app.js" defer></script>
</body>
</html>
