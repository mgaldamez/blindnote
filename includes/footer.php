<?php
/** Pie comun: cierra el layout y carga el JS del menu. */
$jsVersion = @filemtime(__DIR__ . '/../assets/js/app.js') ?: 1;
?>
    </main>
</div>

<footer class="footer">
    <div class="contenedor">
        <p><?= e(APP_NOMBRE) ?></p>
    </div>
</footer>

<script src="assets/js/app.js?v=<?= (int) $jsVersion ?>" defer></script>
</body>
</html>
