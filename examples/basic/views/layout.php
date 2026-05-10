<?php use Cloude\View; ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title ?? 'Cloude') ?></title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; color: #222; }
        header, footer { border-bottom: 1px solid #eee; padding-bottom: .5rem; margin-bottom: 1.5rem; }
        footer { border: 0; border-top: 1px solid #eee; padding-top: .5rem; margin-top: 2rem; font-size: .9rem; color: #777; }
        a { color: #0a6cff; }
        code { background: #f4f4f4; padding: .1rem .3rem; border-radius: 3px; }
        pre  { background: #f4f4f4; padding: 1rem; border-radius: 6px; overflow-x: auto; }
    </style>
</head>
<body>
    <header>
        <strong>Cloude - example</strong> &middot;
        <a href="/">home</a> &middot;
        <a href="/about">about</a> &middot;
        <a href="/hello/world">/hello/world</a>
    </header>

    <main>
        <?php if (!empty($content)) {
            View::render($content, get_defined_vars());
        } ?>
    </main>

    <footer>
        Served by <a href="https://packagist.org/packages/cloude/framework">cloude/framework</a>.
    </footer>
</body>
</html>
