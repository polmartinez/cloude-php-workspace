<?php use Cloude\View;

?>

<h1>New contact</h1>

<?php if (!empty($errors)): ?>
    <div class="errors">
        <strong>Could not save:</strong>
        <ul>
            <?php foreach ($errors as $err): ?>
                <li><?= View::e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="/new" class="form" novalidate>
    <label>
        <span>Name <em>*</em></span>
        <input type="text" name="name" required minlength="2" maxlength="80"
               value="<?= View::e($old['name']) ?>" autofocus>
    </label>

    <label>
        <span>Email</span>
        <input type="email" name="email" maxlength="120"
               value="<?= View::e($old['email']) ?>"
               placeholder="optional, e.g. ada@example.com">
    </label>

    <label>
        <span>Phone</span>
        <input type="tel" name="phone" maxlength="30"
               value="<?= View::e($old['phone']) ?>"
               placeholder="optional">
    </label>

    <label>
        <span>Notes</span>
        <textarea name="notes" rows="4" maxlength="500"
                  placeholder="optional"><?= View::e($old['notes']) ?></textarea>
    </label>

    <div class="form-actions">
        <a href="/" class="btn">Cancel</a>
        <button type="submit" class="btn btn-primary">Save contact</button>
    </div>
</form>
