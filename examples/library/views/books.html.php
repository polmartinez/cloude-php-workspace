<?php use Cloude\View;

?>

<h1>Catalogue</h1>

<?php if ($books === []): ?>
    <p class="empty">No books yet. <a href="/books/new">Register the first one →</a></p>
<?php else: ?>
    <ul class="book-list">
        <?php foreach ($books as $b): ?>
            <li class="book">
                <header class="book-header">
                    <h2><?= View::e($b->title()) ?></h2>
                    <p class="book-meta">
                        by <?= View::e($b->author()) ?>
                        · ISBN <code><?= View::e($b->isbn()->value()) ?></code>
                    </p>
                    <p class="copies <?= $b->isAvailable() ? '' : 'unavailable' ?>">
                        <strong><?= $b->copiesAvailable() ?></strong>
                        of <?= $b->copiesTotal() ?> available
                    </p>
                </header>

                <?php if ($b->isAvailable()): ?>
                    <form method="post"
                          action="/books/<?= View::e($b->isbn()->value()) ?>/borrow"
                          class="borrow-form">
                        <input type="text" name="member"
                               placeholder="Member name" required maxlength="80">
                        <button type="submit" class="btn">Borrow</button>
                    </form>
                <?php else: ?>
                    <p class="muted">All copies are out — try again later.</p>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
