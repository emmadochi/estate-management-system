                                </div>
                            </div>
                            <!--end::Content-->

                        </div>
                    </div>
                    <!--end::Main-->

                </div>
                <!--end::Wrapper-->

            </div>
            <!--end::Page-->
        </div>
        <!--end::App-->

        <script src="../../assets/plugins/global/plugins.bundle.js"></script>
        <script src="../../assets/js/scripts.bundle.js"></script>
        <script src="../../assets/plugins/custom/fullcalendar/fullcalendar.bundle.js"></script>
        <script src="../../assets/plugins/custom/datatables/datatables.bundle.js"></script>
        <script>
        (function() {
            function getTheme() {
                var t = document.documentElement.getAttribute('data-bs-theme');
                return (t === 'dark' || t === 'light') ? t : 'light';
            }
            function setTheme(mode) {
                document.documentElement.setAttribute('data-bs-theme', mode);
                try { localStorage.setItem('data-bs-theme', mode); } catch (e) {}
                var moon = document.getElementById('kt_theme_icon_moon');
                var sun = document.getElementById('kt_theme_icon_sun');
                if (moon) moon.style.display = mode === 'light' ? '' : 'none';
                if (sun) sun.style.display = mode === 'dark' ? '' : 'none';
            }
            document.addEventListener('DOMContentLoaded', function() {
                setTheme(getTheme());
                var btn = document.getElementById('kt_theme_toggle');
                if (btn) btn.addEventListener('click', function() {
                    setTheme(getTheme() === 'dark' ? 'light' : 'dark');
                });
            });
        })();
        </script>
        <script src="../../assets/js/pwa.js"></script>
    </body>
</html>

