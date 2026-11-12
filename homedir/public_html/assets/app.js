/* Whisper (F005): progressiv forbedring. Alt virker uden denne fil; den indlæses med defer fra <head> (CSP: ingen
   inline script). Bundet til data-attributter: .ts[data-start], [data-copy], [data-rate], [data-js-only]. */
(function () {
  'use strict';
  var d = document;
  d.documentElement.classList.add('js');
  var live = d.getElementById('live');

  function say(text) {
    if (!live) { return; }
    live.textContent = '';
    setTimeout(function () { live.textContent = text; }, 50);
  }

  d.querySelectorAll('[data-js-only], [data-copy]').forEach(function (el) { el.hidden = false; });

  /* 1. Tidsstempler: klik spoler og afspiller. Uden afspillelig lyd forbliver de tekst (disabled). */
  var audio = d.getElementById('afspiller');
  var stamps = Array.prototype.slice.call(d.querySelectorAll('.ts[data-start]'));
  function setStamps(on) { stamps.forEach(function (b) { b.disabled = !on; }); }
  /* networkState 3 = NETWORK_NO_SOURCE: lyden kan være fejlet, før dette defer-script kørte, og så er audio.error
     ikke altid sat, og error-hændelsen er allerede gået forbi. */
  if (audio && audio.networkState !== 3 && !audio.error) {
    setStamps(true);
    audio.addEventListener('error', function () { setStamps(false); });
    d.addEventListener('click', function (ev) {
      var b = ev.target.closest('.ts[data-start]');
      if (!b || b.disabled) { return; }
      audio.currentTime = parseFloat(b.getAttribute('data-start')) || 0;
      var p = audio.play();
      if (p && p.catch) {
        p.catch(function (err) {
          if (err && err.name === 'AbortError') { return; }   /* afbrudt af et nyt klik, ikke en fejl */
          say('Lyden kunne ikke afspilles.');
          setStamps(false);
        });
      }
    });

    /* 3. Markér den aktuelle linje (ingen automatisk rulning). */
    var current = null;
    audio.addEventListener('timeupdate', function () {
      var t = audio.currentTime, found = null;
      for (var i = 0; i < stamps.length; i++) {
        if (parseFloat(stamps[i].getAttribute('data-start')) <= t + 0.05) { found = stamps[i].closest('li'); } else { break; }
      }
      if (found !== current) {
        if (current) { current.classList.remove('is-current'); }
        if (found) { found.classList.add('is-current'); }
        current = found;
      }
    });

    /* 4. Afspilningshastighed. */
    var rates = d.querySelectorAll('[data-rate]');
    rates.forEach(function (b) {
      b.addEventListener('click', function () {
        audio.playbackRate = parseFloat(b.getAttribute('data-rate')) || 1;
        rates.forEach(function (o) { o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
      });
    });
  } else {
    d.querySelectorAll('.speed').forEach(function (el) { el.hidden = true; });
  }

  /* 2. Kopiér: et felt, en kodeblok eller et transcript (med tidsstempler, én linje pr. segment). */
  function textOf(el) {
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') { return el.value; }
    var items = el.querySelectorAll('li');
    if (items.length) {
      return Array.prototype.map.call(items, function (li) {
        var ts = li.querySelector('.ts'), p = li.querySelector('p');
        return (ts ? '[' + ts.textContent.trim() + '] ' : '') + (p ? p.textContent.trim() : '');
      }).join('\n') + '\n';
    }
    var paras = el.querySelectorAll('p');
    if (paras.length) {
      return Array.prototype.map.call(paras, function (p) { return p.textContent.trim(); }).join('\n\n') + '\n';
    }
    return el.textContent;
  }

  function selectEl(el) {
    if (el.select) { el.focus(); el.select(); return; }
    var r = d.createRange();
    r.selectNodeContents(el);
    var s = window.getSelection();
    s.removeAllRanges();
    s.addRange(r);
  }

  function copied(btn) {
    var label = btn.querySelector('.label') || btn;
    var old = label.getAttribute('data-label') || label.textContent;
    label.setAttribute('data-label', old);
    label.textContent = 'Kopieret ✓';
    btn.classList.add('is-copied');
    say('Kopieret til udklipsholderen.');
    setTimeout(function () { label.textContent = old; btn.classList.remove('is-copied'); }, 2000);
  }

  d.querySelectorAll('button[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var el = d.querySelector(btn.getAttribute('data-copy'));
      if (!el) { return; }
      var text = textOf(el);
      var fallback = function () { selectEl(el); say('Tryk Ctrl+C for at kopiere'); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { copied(btn); }, fallback);
      } else {
        fallback();
      }
    });
  });

  /* 4. F004: "Transcribér dette opkald" — én afsendelse; serveren er idempotent, så uden JS sker intet værre */
  d.querySelectorAll('form.select-call').forEach(function (form) {
    form.addEventListener('submit', function () {
      var btn = form.querySelector('button[data-busy]');
      if (!btn) { return; }
      btn.textContent = btn.getAttribute('data-busy');
      btn.setAttribute('aria-disabled', 'true');
      setTimeout(function () { btn.disabled = true; }, 0);
      say('Sætter opkaldet i kø.');
    });
  });
  /* 5. F006: Godkend/Afvis sendes kun én gang (QA F3). Knapperne låses først efter afsendelsen (setTimeout 0), så den
     valgte knaps name/value kommer med. Uden JS fanger serveren et dobbeltklik (engangs-rid). */
  d.querySelectorAll('form[data-once]').forEach(function (form) {
    form.addEventListener('submit', function () {
      setTimeout(function () {
        form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
      }, 0);
    });
  });
  /* Tilbage-knappen kan genskabe siden fra bfcache med en låst knap: gendan den (F004 UX Finding 3) */
  window.addEventListener('pageshow', function (ev) {
    if (!ev.persisted) { return; }
    d.querySelectorAll('form[data-once] button[type="submit"]').forEach(function (b) { b.disabled = false; });
    d.querySelectorAll('form.select-call button[data-busy]').forEach(function (btn) {
      btn.disabled = false;
      btn.removeAttribute('aria-disabled');
      btn.textContent = 'Transcribér dette opkald';
    });
  });
})();
