<?php
declare(strict_types=1);

/** @var list<array{problem: string, solution: string}> $rows */
?>
<div class="landing-why-table-wrap">
    <table class="landing-why-table">
        <thead>
            <tr>
                <th scope="col">Боль</th>
                <th scope="col">Решение</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $row['problem'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><strong><?= htmlspecialchars((string) $row['solution'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
