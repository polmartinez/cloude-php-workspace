<?php use Cloude\View; ?>

<h1>Register a book</h1>

<?php if (!empty($errors)): ?>
    <div class="errors">
        <strong>Could not register:</strong>
        <ul>
            <?php foreach ($errors as $err): ?>
                <li><?= View::e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="/books" class="form" novalidate>
    <label>
        <span>ISBN <em>*</em></span>
        <input type="text" name="isbn" required
               pattern="[0-9-\s]+"
               value="<?= View::e($old['isbn']) ?>"
               placeholder="10 or 13 digits, dashes allowed"
               autofocus>
    </label>

    <label>
        <span>Title <em>*</em></span>
        <input type="text" name="title" required maxlength="200"
               value="<?= View::e($old['title']) ?>">
    </label>

    <label>
        <span>Author <em>*</em></span>
        <input type="text" name="author" required maxlength="120"
               value="<?= View::e($old['author']) ?>">
    </label>

    <label>
        <span>Copies <em>*</em></span>
        <input type="number" name="copies" required min="1" max="999"
               value="<?= View::e((string) $old['copies']) ?>">
    </label>

    <div class="form-actions">
        <a href="/" class="btn">Cancel</a>
        <button type="submit" class="btn btn-primary">Register</button>
    </div>
</form>
