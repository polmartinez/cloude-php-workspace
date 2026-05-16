<?php use Cloude\View;

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title ?? 'Library') ?> · Cloude DDD demo</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
    <header class="topbar">
        <a href="/" class="brand">📚 Library</a>
        <nav>
            <a href="/">Catalogue</a>
            <a href="/loans">Active loans</a>
            <a href="/books/new" class="btn btn-primary">+ Register book</a>
        </nav>
    </header>

    <main class="container">
        <?php if (!empty($flash)): ?>
            <div class="flash <?= str_starts_with($flash, 'Error') ? 'flash-err' : '' ?>">
                <?= View::e($flash) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($content)) {
            require __DIR__ . '/' . $content;
        } ?>
    </main>

    <footer>
        <small>
            Cloude DDD demo — Domain / Application / Infrastructure / Presentation layering.
            Data lives at <code>examples/library/data/</code>.
        </small>
    </footer>
</body>
</html>
