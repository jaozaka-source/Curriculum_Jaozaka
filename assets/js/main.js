(function () {
  'use strict';

  // Année du pied de page
  document.querySelectorAll('[data-year]').forEach(function (el) { el.textContent = new Date().getFullYear(); });

  // Menu mobile
  var toggle = document.querySelector('.nav-toggle');
  var nav = document.getElementById('nav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.setAttribute('aria-label', open ? 'Fermer le menu' : 'Ouvrir le menu');
    });
  }

  // Accueil : lignes du « pont » métiers ⟷ technique
  var bridge = document.querySelector('[data-bridge]');
  if (bridge) {
    var svg = bridge.querySelector('.bridge-lines');
    var core = bridge.querySelector('[data-core] img');
    var NS = 'http://www.w3.org/2000/svg';
    var draw = function () {
      while (svg.firstChild) svg.removeChild(svg.firstChild);
      if (getComputedStyle(svg).display === 'none') return;
      var box = bridge.getBoundingClientRect();
      var c = core.getBoundingClientRect();
      var cx = c.left + c.width / 2 - box.left, cy = c.top + c.height / 2 - box.top, r = c.width / 2 + 6;
      bridge.querySelectorAll('[data-node]').forEach(function (pill) {
        var p = pill.getBoundingClientRect();
        var isTech = pill.getAttribute('data-node') === 't';
        var px = isTech ? p.left - box.left - 4 : p.right - box.left + 4;
        var py = p.top + p.height / 2 - box.top;
        var ang = Math.atan2(py - cy, px - cx);
        var sx = cx + Math.cos(ang) * r, sy = cy + Math.sin(ang) * r;
        var mx = (sx + px) / 2;
        var path = document.createElementNS(NS, 'path');
        path.setAttribute('d', 'M' + sx + ',' + sy + ' C' + mx + ',' + sy + ' ' + mx + ',' + py + ' ' + px + ',' + py);
        if (isTech) path.setAttribute('class', 't');
        path.style.setProperty('--i', pill.style.getPropertyValue('--i'));
        svg.appendChild(path);
        path.style.setProperty('--len', Math.ceil(path.getTotalLength()));
      });
    };
    var start = function () { draw(); requestAnimationFrame(function () { bridge.classList.add('go'); }); };
    if (core.complete) start(); else core.addEventListener('load', start);
    var t; window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(draw, 120); });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(draw);
  }

  // Expériences : filtres par tag + tout déplier
  var filters = document.querySelectorAll('.filter');
  if (filters.length) {
    var items = document.querySelectorAll('.xp');
    filters.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var f = btn.getAttribute('data-f');
        filters.forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
        items.forEach(function (li) {
          li.hidden = !(f === 'all' || li.getAttribute('data-tags').split(' ').indexOf(f) > -1);
        });
      });
    });
    var all = document.querySelector('[data-toggle-all]');
    all.addEventListener('click', function () {
      var cards = document.querySelectorAll('.xp:not([hidden]) .xp-card');
      var anyClosed = Array.prototype.some.call(cards, function (d) { return !d.open; });
      cards.forEach(function (d) { d.open = anyClosed; });
      all.textContent = anyClosed ? 'Tout replier' : 'Tout déplier';
    });
    // Ouvrir l'expérience ciblée par l'URL (#monoprix…)
    if (location.hash) {
      var target = document.querySelector(location.hash + '.xp-card');
      if (target) target.open = true;
    }
  }

  // Contact : envoi AJAX vers contact.php
  var form = document.getElementById('contact-form');
  if (form) {
    var status = form.querySelector('.form-status');
    var show = function (cls, msg) { status.className = 'form-status ' + cls; status.textContent = msg; };
    var params = new URLSearchParams(location.search);
    if (params.get('envoye') === '1') show('ok', 'Merci, votre message a bien été envoyé. Je vous réponds sous 48 h.');
    if (params.get('erreur')) show('err', "L'envoi a échoué. Écrivez-moi directement à jaozaka@gmail.com.");

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var bad = null;
      form.querySelectorAll('[required]').forEach(function (el) {
        var ok = el.type === 'checkbox' ? el.checked : el.checkValidity() && el.value.trim() !== '';
        el.setAttribute('aria-invalid', ok ? 'false' : 'true');
        if (!ok && !bad) bad = el;
      });
      if (bad) { show('err', 'Vérifiez les champs obligatoires : nom, e-mail valide, message et consentement.'); bad.focus(); return; }

      var btn = form.querySelector('button[type=submit]');
      btn.disabled = true;
      fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (res) {
          if (res.ok) { form.reset(); show('ok', 'Merci, votre message a bien été envoyé. Je vous réponds sous 48 h.'); }
          else show('err', res.error || "L'envoi a échoué. Écrivez-moi directement à jaozaka@gmail.com.");
        })
        .catch(function () { show('err', "L'envoi a échoué. Écrivez-moi directement à jaozaka@gmail.com."); })
        .then(function () { btn.disabled = false; });
    });
  }
})();
