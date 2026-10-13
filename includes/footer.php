    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= url('assets/js/kalasag.js') ?>"></script>
<script>
// small live clock on the topbar
(function () {
    const el = document.getElementById('clock');
    if (!el) return;
    const tick = () => el.textContent = new Date().toLocaleString('en-PH', {
        timeZone: 'Asia/Manila', weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
    });
    tick(); setInterval(tick, 30000);
})();
</script>
<?= $extra_scripts ?? '' ?>
</body>
</html>
