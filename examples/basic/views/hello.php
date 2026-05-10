<?php use Cloude\View; ?>
<h1>Hello, <?= View::e($name) ?></h1>
<p>This page is served by the <code>/hello/{name}</code> route.</p>
<p><a href="/">Back to home</a></p>
