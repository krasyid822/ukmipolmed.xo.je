    <script src="lib/adhan.umd.min.js"></script>
    <script>
    (function () {
      var LAT = 3.563484742344483, LNG = 98.65425015257077; // Masjid Baitul Ilmi POLMED (sekretariat UKMI)
      var ORDER = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'];
      var root = document.getElementById('footer-sholat');
      if (!root) return;
      var times = null, dayKey = '', timer = null;

      function pad(n) { return (n < 10 ? '0' : '') + n; }
      function fmt(d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); }

      function buildTimes(now) {
        if (!window.adhan) return false;
        var coords = new window.adhan.Coordinates(LAT, LNG);
        var p = window.adhan.CalculationMethod.Singapore(); // Kemenag/NU
        // ihtiyati +2 menit (default al-waqt)
        p.adjustments.fajr = p.adjustments.dhuhr = p.adjustments.asr = p.adjustments.maghrib = p.adjustments.isha = 2;
        times = new window.adhan.PrayerTimes(coords, now, p);
        dayKey = now.getFullYear() + '-' + (now.getMonth() + 1) + '-' + now.getDate();
        return true;
      }

      function renderTimes() {
        ORDER.forEach(function (k) {
          var el = root.querySelector('[data-time="' + k + '"]');
          if (el && times && times[k] instanceof Date) el.textContent = fmt(times[k]);
        });
      }

      function tick() {
        var now = new Date();
        var k = now.getFullYear() + '-' + (now.getMonth() + 1) + '-' + now.getDate();
        if (!times || k !== dayKey) { if (!buildTimes(now)) return; renderTimes(); }

        // cari sholat berikutnya hari ini
        var nextKey = null, nextTime = null;
        for (var i = 0; i < ORDER.length; i++) {
          var t = times[ORDER[i]];
          if (t && t > now) { nextKey = ORDER[i]; nextTime = t; break; }
        }
        // setelah Isya -> Subuh esok hari
        if (!nextTime && window.adhan) {
          var tomorrow = new Date(now.getTime() + 86400000);
          var coords = new window.adhan.Coordinates(LAT, LNG);
          var p2 = window.adhan.CalculationMethod.Singapore();
          p2.adjustments.fajr = 2;
          nextKey = 'fajr';
          nextTime = new window.adhan.PrayerTimes(coords, tomorrow, p2).fajr;
        }

        // highlight sholat berikutnya
        root.querySelectorAll('.fs-item').forEach(function (el) {
          el.classList.toggle('is-active', el.getAttribute('data-prayer') === nextKey);
        });

        // countdown ke sholat berikutnya
        var nextEl = document.getElementById('fs-next');
        if (nextEl && nextTime) {
          var diff = nextTime - now;
          if (diff < 0) diff = 0;
          var h = Math.floor(diff / 3600000);
          var m = Math.floor((diff % 3600000) / 60000);
          var s = Math.floor((diff % 60000) / 1000);
          var label = root.querySelector('[data-prayer="' + nextKey + '"] .fs-name');
          nextEl.hidden = false;
          nextEl.textContent = '→ ' + (label ? label.textContent : '') + ' ' + pad(h) + ':' + pad(m) + ':' + pad(s);
        }
      }

      var adhanTries = 0;
      function start() {
        if (!window.adhan) {
          if (++adhanTries > 20) return; // menyerah setelah ~6 detik jika library gagal dimuat
          setTimeout(start, 300);
          return;
        }
        tick();
        if (!timer) timer = setInterval(tick, 1000);
      }

      start();
    })();
    </script>
