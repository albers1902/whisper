/* Whisper (F007): progressiv forbedring af det nye udseende. Indlæses kun med flaget F007_kilder (ellers app.js).
   Alt virker uden filen. CSP: ingen inline script/style; bundet til klasser og data-attributter. Transcript-tekst
   sættes aldrig som HTML: "Find i samtalen" deler tekstnoder og indsætter <mark>-elementer. */
(function () {
  'use strict';
  var d = document;
  var root = d.documentElement;
  root.classList.add('js');
  var live = d.getElementById('live');

  function say(text) {
    if (!live) { return; }
    live.textContent = '';
    setTimeout(function () { live.textContent = text; }, 50);
  }
  function fmt(sec) {
    sec = Math.max(0, Math.floor(sec || 0));
    var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    return (h > 0 ? h + ':' + (m < 10 ? '0' : '') + m : m) + ':' + (s < 10 ? '0' : '') + s;
  }

  d.querySelectorAll('[data-js-only], [data-copy]').forEach(function (el) { el.hidden = false; });

  /* 1. Menu-knappen (under 900 px): viser/skjuler sidebaren under topbaren; Esc lukker og giver fokus tilbage. */
  var menuBtn = d.querySelector('.menu-btn');
  function setMenu(open) {
    if (!menuBtn) { return; }
    root.classList.toggle('menu-open', open);
    menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  if (menuBtn) {
    menuBtn.addEventListener('click', function () { setMenu(!root.classList.contains('menu-open')); });
  }

  /* 2. Piller med <details>: Esc og klik udenfor lukker. */
  d.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') { return; }
    var open = d.querySelector('details.pill-menu[open]');
    if (open) {
      open.open = false;
      open.querySelector('summary').focus();
      return;
    }
    if (root.classList.contains('menu-open')) {
      setMenu(false);
      menuBtn.focus();
    }
  });
  d.addEventListener('click', function (ev) {
    d.querySelectorAll('details.pill-menu[open]').forEach(function (det) {
      if (!det.contains(ev.target)) { det.open = false; }
    });
  });

  /* 3. Afspilleren: egne kontroller over det native <audio>, klik på boble/tidsstempel spoler. */
  var audio = d.getElementById('afspiller');
  var card = d.querySelector('.player-v2');
  var stamps = Array.prototype.slice.call(d.querySelectorAll('.ts[data-start]'));
  var items = Array.prototype.slice.call(d.querySelectorAll('#transcript li[data-start]'));
  if (audio && card && audio.networkState !== 3 && !audio.error) {
    var play = card.querySelector('.pl-play');
    var seek = card.querySelector('.pl-seek');
    var cur = card.querySelector('.pl-cur');
    var total = card.querySelector('.pl-total');
    var errEl = card.querySelector('.pl-error');
    var rates = card.querySelectorAll('[data-rate]');
    var playable = true;
    var lastText = -1;
    card.classList.add('has-js');
    audio.controls = false;
    stamps.forEach(function (b) { b.disabled = false; });

    var fail = function () {
      if (!playable) { return; }
      playable = false;
      errEl.hidden = false;
      [play, seek].concat(Array.prototype.slice.call(rates)).forEach(function (el) { el.disabled = true; });
      stamps.forEach(function (b) { b.disabled = true; });
      d.querySelectorAll('#transcript.playable').forEach(function (t) { t.classList.remove('playable'); });
      say('Lyden kunne ikke afspilles.');
    };
    var start = function () {
      var p = audio.play();
      if (p && p.catch) {
        p.catch(function (err) { if (!err || err.name !== 'AbortError') { fail(); } });
      }
    };
    var duration = function () { return isFinite(audio.duration) && audio.duration > 0 ? audio.duration : parseFloat(seek.max) || 0; };
    var sync = function () {
      var t = audio.currentTime;
      seek.value = String(Math.floor(t));
      cur.textContent = fmt(t);
      if (Math.floor(t) !== lastText) {   /* højst én gang i sekundet */
        lastText = Math.floor(t);
        seek.setAttribute('aria-valuetext', fmt(t) + ' af ' + fmt(duration()));
      }
    };
    audio.addEventListener('error', fail);
    audio.addEventListener('loadedmetadata', function () {
      if (isFinite(audio.duration) && audio.duration > 0) {
        seek.max = String(Math.ceil(audio.duration));
        total.textContent = fmt(audio.duration);
      }
    });
    audio.addEventListener('play', function () { play.classList.add('is-playing'); play.setAttribute('aria-label', 'Pause'); });
    audio.addEventListener('pause', function () { play.classList.remove('is-playing'); play.setAttribute('aria-label', 'Afspil'); });
    audio.addEventListener('waiting', function () { cur.textContent = 'Indlæser …'; });
    audio.addEventListener('timeupdate', function () { sync(); mark(); });
    play.addEventListener('click', function () { if (audio.paused) { start(); } else { audio.pause(); } });
    seek.addEventListener('input', function () { audio.currentTime = parseFloat(seek.value) || 0; sync(); });
    seek.addEventListener('keydown', function (ev) {
      var jump = { ArrowRight: 5, ArrowUp: 5, ArrowLeft: -5, ArrowDown: -5, PageUp: 30, PageDown: -30 }[ev.key];
      if (jump === undefined) { return; }
      ev.preventDefault();
      audio.currentTime = Math.min(duration(), Math.max(0, audio.currentTime + jump));
      sync();
    });
    rates.forEach(function (b) {
      b.addEventListener('click', function () {
        audio.playbackRate = parseFloat(b.getAttribute('data-rate')) || 1;
        rates.forEach(function (o) { o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
      });
    });
    var jumpTo = function (sec) { audio.currentTime = sec; start(); };
    d.addEventListener('click', function (ev) {
      if (!playable) { return; }
      var b = ev.target.closest('.ts[data-start]');
      if (b && !b.disabled) { jumpTo(parseFloat(b.getAttribute('data-start')) || 0); return; }
      var li = ev.target.closest('#transcript.chat li[data-start]');
      var sel = window.getSelection ? String(window.getSelection()) : '';
      if (li && sel === '') { jumpTo(parseFloat(li.getAttribute('data-start')) || 0); }
    });

    /* Den aktuelle boble/linje markeres; siden ruller ikke af sig selv. */
    var current = null;
    var mark = function () {
      var t = audio.currentTime, found = null;
      for (var i = 0; i < items.length; i++) {
        if (parseFloat(items[i].getAttribute('data-start')) <= t + 0.05) { found = items[i]; } else { break; }
      }
      if (found !== current) {
        if (current) { current.classList.remove('is-current'); }
        if (found) { found.classList.add('is-current'); }
        current = found;
      }
    };
  } else if (card) {
    d.querySelectorAll('.pl-controls').forEach(function (el) { el.hidden = true; });
  }

  /* 4. Kopiér: et felt eller transcriptet i .txt-format ("[00:12] Agent: tekst"). */
  function textOf(el) {
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') { return el.value; }
    var lis = el.querySelectorAll('li');
    if (lis.length) {
      return Array.prototype.map.call(lis, function (li) {
        var ts = li.querySelector('.ts'), p = li.querySelector('.say'), who = li.getAttribute('data-who');
        return (ts ? '[' + ts.textContent.trim() + '] ' : '') + (who ? who + ': ' : '') + (p ? p.textContent.trim() : '');
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

  /* 5. "Find i samtalen" (AK11): markerer alle træf i teksten, "n af m", Enter/pil ned = næste, Skift+Enter/pil op = forrige. */
  var findInput = d.getElementById('find-q');
  var findStatus = d.getElementById('find-status');
  var conv = d.getElementById('transcript');
  if (findInput && conv) {
    var hits = [];
    var active = -1;
    var clear = function () {
      conv.querySelectorAll('mark.find-hit').forEach(function (m) {
        var parent = m.parentNode;
        parent.replaceChild(d.createTextNode(m.textContent), m);
        parent.normalize();
      });
      hits = [];
      active = -1;
    };
    var show = function (i) {
      if (!hits.length) { return; }
      if (active >= 0) { hits[active].classList.remove('is-active'); }
      active = (i + hits.length) % hits.length;
      hits[active].classList.add('is-active');
      hits[active].scrollIntoView({ block: 'center' });
      findStatus.textContent = (active + 1) + ' af ' + hits.length;
    };
    var run = function () {
      clear();
      var q = findInput.value.trim().toLowerCase();
      if (q === '') { findStatus.textContent = ''; return; }
      var nodes = [];
      var walker = d.createTreeWalker(conv, NodeFilter.SHOW_TEXT, {
        acceptNode: function (n) { return n.parentNode.closest('.say') ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT; }
      });
      while (walker.nextNode()) { nodes.push(walker.currentNode); }
      nodes.forEach(function (node) {
        var text = node.nodeValue, lower = text.toLowerCase(), at = lower.indexOf(q);
        while (at !== -1) {
          var match = node.splitText(at);
          node = match.splitText(q.length);
          var m = d.createElement('mark');
          m.className = 'find-hit';
          m.textContent = match.nodeValue;
          match.parentNode.replaceChild(m, match);
          hits.push(m);
          lower = node.nodeValue.toLowerCase();
          at = lower.indexOf(q);
        }
      });
      if (hits.length) { show(0); } else { findStatus.textContent = 'Ingen træf'; }
    };
    var timer = null;
    findInput.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 150); });
    findInput.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' || ev.key === 'ArrowDown') {
        ev.preventDefault();
        if (timer) { clearTimeout(timer); timer = null; if (!hits.length) { run(); return; } }
        show(ev.shiftKey ? active - 1 : active + 1);
      } else if (ev.key === 'ArrowUp') {
        ev.preventDefault();
        show(active - 1);
      } else if (ev.key === 'Escape') {
        findInput.value = '';
        clear();
        findStatus.textContent = '';
      }
    });
  }

  /* 6. "Transcribér dette opkald" og F006's Godkend/Afvis sendes kun én gang; bfcache gendanner knapperne. */
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
  d.querySelectorAll('form[data-once]').forEach(function (form) {
    form.addEventListener('submit', function () {
      setTimeout(function () {
        form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
      }, 0);
    });
  });
  window.addEventListener('pageshow', function (ev) {
    if (!ev.persisted) { return; }
    setMenu(false);
    d.querySelectorAll('form[data-once] button[type="submit"]').forEach(function (b) { b.disabled = false; });
    d.querySelectorAll('form.select-call button[data-busy]').forEach(function (btn) {
      btn.disabled = false;
      btn.removeAttribute('aria-disabled');
      btn.textContent = 'Transcribér dette opkald';
    });
  });
})();
