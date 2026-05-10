<?php use Cloude\View;

?>

<article class="card">
    <h1><?= View::e($contact['name']) ?></h1>

    <dl class="kv">
        <?php if ($contact['email'] !== ''): ?>
            <dt>Email</dt>
            <dd><a href="mailto:<?= View::e($contact['email']) ?>"><?= View::e($contact['email']) ?></a></dd>
        <?php endif; ?>

        <?php if ($contact['phone'] !== ''): ?>
            <dt>Phone</dt>
            <dd><?= View::e($contact['phone']) ?></dd>
        <?php endif; ?>

        <?php if ($contact['notes'] !== ''): ?>
            <dt>Notes</dt>
            <dd><?= nl2br(View::e($contact['notes'])) ?></dd>
        <?php endif; ?>

        <?php if (!empty($contact['created_at'])): ?>
            <dt>Created</dt>
            <dd><time datetime="<?= View::e($contact['created_at']) ?>"><?= View::e($contact['created_at']) ?></time></dd>
        <?php endif; ?>

        <dt>Slug</dt>
        <dd><code><?= View::e($contact['slug']) ?></code></dd>
    </dl>

    <div class="form-actions">
        <a href="/" class="btn">← Back</a>
        <form method="post" action="/contact/<?= View::e($contact['slug']) ?>/delete"
              onsubmit="return confirm('Delete <?= View::e($contact['name']) ?>?');"
              style="display:inline">
            <button type="submit" class="btn btn-danger">Delete</button>
        </form>
    </div>
</article>
