<?php use Cloude\View; ?>

<h1>Active loans</h1>

<?php if ($loans === []): ?>
    <p class="empty">No active loans. <a href="/">Browse the catalogue →</a></p>
<?php else: ?>
    <table class="loan-table">
        <thead>
            <tr>
                <th>Book</th>
                <th>Member</th>
                <th>Due</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($loans as $l): ?>
                <tr<?= $l['overdue'] ? ' class="overdue"' : '' ?>>
                    <td>
                        <strong><?= View::e($l['title']) ?></strong>
                        <br><code class="muted"><?= View::e($l['isbn']) ?></code>
                    </td>
                    <td><?= View::e($l['member']) ?></td>
                    <td>
                        <?= View::e($l['due_at']) ?>
                        <?php if ($l['overdue']): ?>
                            <span class="badge">overdue</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post"
                              action="/loans/<?= View::e($l['id']) ?>/return"
                              style="margin:0">
                            <button type="submit" class="btn">Mark returned</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
