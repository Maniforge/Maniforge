</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php foreach ($extraScripts ?? [] as $scriptSrc): ?>
    <script src="<?= htmlspecialchars((string) $scriptSrc, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"></script>
<?php endforeach; ?>
</body>
</html>
