<?php use Cloude\View; ?>

<section class="search">
    <label for="q" class="search-label">Search</label>
    <input
        id="q"
        type="search"
        name="q"
        placeholder="Type to filter by name, email or notes…"
        autocomplete="off"
        data-search>
    <p class="search-hint" data-count><?= count($contacts) ?> contact<?= count($contacts) === 1 ? '' : 's' ?></p>
</section>

<ul class="contact-list" data-results>
    <?php foreach ($contacts as $c): ?>
        <li class="contact">
            <a href="/contact/<?= View::e($c['slug']) ?>" class="contact-name">
                <?= View::e($c['name']) ?>
            </a>
            <?php if ($c['email'] !== ''): ?>
                <span class="contact-meta"><?= View::e($c['email']) ?></span>
            <?php endif; ?>
            <?php if ($c['phone'] !== ''): ?>
                <span class="contact-meta"><?= View::e($c['phone']) ?></span>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>

<p class="empty" data-empty<?= $contacts === [] ? '' : ' hidden' ?>>
    No contacts yet. <a href="/new">Add the first one →</a>
</p>
