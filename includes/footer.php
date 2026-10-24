    </main>

    <?php // v2.0 Phase 1.3: close the shell wrappers opened in header.php.
          // Only emitted when that page opted in via $use_v2_shell AND is
          // logged in; the v1.8.0 markup below is unchanged for every other
          // page, so unconverted pages keep rendering exactly as before.
          //
          // Mockup §7 (Footer) is intentionally not reproduced: it carries
          // decorative links (docs, changelog) that nginx returns 404 for
          // (issue-020 hardening), which is the same bug tester-note-002
          // bug 7 removed from the About popup. The v1.8.0 footer already
          // carries the required copyright line. ?>
    <?php if (!empty($use_v2_shell) && isset($show_nav) && $show_nav && isLoggedIn()): ?>
    </div><!-- /.app-main -->
    </div><!-- /.app-shell -->
    <?php endif; ?>

    <footer class="footer">
        <div class="footer-content">
            <p>&copy; <?= date('Y') ?> <?= APP_NAME ?>. All rights reserved.</p>
        </div>
    </footer>

    <script src="<?= e(asset_url('/assets/js/script.js')) ?>"></script>
</body>
</html>