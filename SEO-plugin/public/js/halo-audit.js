(function () {
  'use strict';

  var cfg = window.HaloAudit || {};
  var nonce = null;
  var widgets = [];
  var STATUS = { pass: 'Pass', warn: 'Improve', fail: 'Fix', not_measured: 'Not measured' };
  /* The order a check takes in its area: what to fix first, then what to improve, then what could not be measured. */
  var ATTENTION = { fail: 0, warn: 1, not_measured: 2, pass: 3 };
  var TOP_FIXES = 5;
  var DONE = 'Audit complete.';
  var SPEED_PENDING = 'Page read. The speed test is still running. It takes up to 90 seconds.';
  var SPEED_FAILED = 'Page audit complete. The speed test did not return a result, so Speed is not measured.';
  var RETRY = 'Try the speed test again';

  function route(path) {
    var parts = path.split('?');
    var joiner = cfg.root.indexOf('?') === -1 ? '?' : '&';
    return cfg.root + parts[0] + (parts[1] ? joiner + parts[1] : '');
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined && text !== null) { node.textContent = String(text); }
    return node;
  }

  function clear(node) {
    while (node.firstChild) { node.removeChild(node.firstChild); }
  }

  function fetchNonce() {
    return fetch(route('nonce?t=' + Date.now()), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (data) { nonce = data.nonce; });
  }

  function send(method, path, body, retried) {
    var ready = nonce ? Promise.resolve() : fetchNonce();
    return ready.then(function () {
      return fetch(route(path), {
        method: method,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'X-Halo-Nonce': nonce },
        body: body ? JSON.stringify(body) : undefined
      });
    }).then(function (response) {
      if (response.status === 403 && !retried && path.indexOf('report/') !== 0 && path.indexOf('scan') !== 0) {
        nonce = null;
        return send(method, path, body, true);
      }
      return response;
    });
  }

  function api(method, path, body) {
    return send(method, path, body, false).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok) {
          var error = new Error(data.message || 'Something went wrong. Try again.');
          error.status = response.status;
          throw error;
        }
        return data;
      });
    });
  }

  /* Marks a field as wrong and points it at the message that explains why. */
  function flag(input, messageId, wrong) {
    if (wrong) {
      input.setAttribute('aria-invalid', 'true');
      input.setAttribute('aria-describedby', messageId);
    } else {
      input.removeAttribute('aria-invalid');
      input.removeAttribute('aria-describedby');
    }
  }

  function Widget(root) {
    this.root = root;
    this.id = root.id || ('halo-audit-' + (widgets.length + 1));
    this.form = root.querySelector('.halo-audit__form');
    this.status = root.querySelector('.halo-audit__status');
    this.results = root.querySelector('.halo-audit__results');
    this.button = this.form.querySelector('.halo-audit__button');
    // Comparison mode: the shortcode printed a competitor page field.
    this.competitorField = this.form.elements.competitor_url || null;
    this.level = Math.min(5, Math.max(2, parseInt(root.getAttribute('data-heading-level'), 10) || 2));
    this.data = null;
    if (!this.status.id) { this.status.id = this.id + '-status'; }
    this.form.addEventListener('submit', this.onSubmit.bind(this));
  }

  /*
   * The widget's state lives on its own root element:
   *   data-state = idle | invalid | loading | error | preview | gate | code | unlocked
   * A "halo-audit:state" event bubbles from the root on every change. Its detail holds the state name only.
   */
  Widget.prototype.setState = function (state) {
    if (this.root.getAttribute('data-state') === state) { return; }
    this.root.setAttribute('data-state', state);
    if (state === 'loading') { this.root.setAttribute('aria-busy', 'true'); } else { this.root.removeAttribute('aria-busy'); }
    var event;
    try {
      event = new CustomEvent('halo-audit:state', { bubbles: true, detail: { state: state } });
    } catch (e) {
      return;
    }
    this.root.dispatchEvent(event);
  };

  Widget.prototype.say = function (text, isError) {
    this.status.textContent = text || '';
    this.status.classList.toggle('halo-audit__status--error', !!isError);
  };

  Widget.prototype.heading = function (offset, className, text) {
    return el('h' + Math.min(6, this.level + offset), className, text);
  };

  Widget.prototype.onSubmit = function (event) {
    event.preventDefault();
    if (this.button.disabled) { return; }
    var urlField = this.form.elements.url;
    var keywordField = this.form.elements.keyword;
    var competitorField = this.competitorField;
    var url = urlField.value.trim();
    var keyword = keywordField.value.trim();
    var competitorUrl = competitorField ? competitorField.value.trim() : '';
    flag(urlField, this.status.id, !url);
    flag(keywordField, this.status.id, !keyword);
    if (competitorField) { flag(competitorField, this.status.id, !competitorUrl); }
    if (!url || !keyword || (competitorField && !competitorUrl)) {
      this.say(competitorField ? 'Enter your page address, a competitor page address and a keyword.' : 'Enter a page address and a keyword.', true);
      // A report that is already on the page stays, and so does its state.
      if (this.results.hidden) { this.setState('invalid'); }
      (!url ? urlField : (competitorField && !competitorUrl) ? competitorField : keywordField).focus();
      return;
    }
    var self = this;
    this.button.disabled = true;
    this.results.hidden = true;
    clear(this.results);
    this.data = null;
    this.speedRun = null;
    this.say(competitorField ? 'Reading both pages. This takes up to a minute.' : 'Reading the page. This takes up to 15 seconds.');
    this.setState('loading');

    var body = { url: url, keyword: keyword };
    if (competitorField) { body.competitor_url = competitorUrl; }
    // The signed address of the page this form is on (FORM_NAME in Brevo). The server checks the signature.
    if (this.root.getAttribute('data-form-page')) { body.form_page = this.root.getAttribute('data-form-page'); }
    api('POST', 'analyze', body).then(function (data) {
      self.data = data;
      self.say(data.speed_done && (!data.competitor || data.competitor.speed_done) ? DONE : SPEED_PENDING);
      self.render();
      self.setState('preview');
      if (data.unlocked) { self.loadFull(); }
      self.speedRun = self.measureSpeed(data);
      return self.speedRun;
    }).catch(function (error) {
      self.say(error.message, true);
      self.setState('error');
    }).then(function () {
      self.button.disabled = false;
    });
  };

  /*
   * The server measures one page per call: your page, then in a comparison the competitor page. Each page has
   * its own call, so a failed or timed-out call marks only that page "Not measured" and the other page is still
   * tested. The calls run one after the other so that one audit never holds two of the site's PHP workers.
   * Resolves (never rejects) when every page has a speed result or has failed.
   */
  Widget.prototype.measureSpeed = function (data) {
    var self = this;
    function side(name) { return name === 'competitor' ? data.competitor : data; }
    function step(name) {
      if (self.data !== data || side(name).speed_done) { return Promise.resolve(); }
      return api('POST', 'speed', { report_id: data.report_id, side: name }).then(function (speed) {
        if (self.data !== data) { return; }
        // Take only the page this call measured, and only forward: the answer's copy of the other page can be older.
        var fresh = name === 'competitor' ? speed.competitor : speed;
        if (fresh && fresh.speed_done && !side(name).speed_done) {
          side(name).summary = fresh.summary;
          side(name).speed_done = true;
          side(name).speed_failed = !!fresh.speed_failed;
        }
      }).catch(function () {
        if (self.data !== data) { return; }
        // No answer for this page (a timeout, an error): Speed is not measured and the page offers a retry. The
        // server may still save a result; a retry then answers with it instead of testing again.
        side(name).speed_done = true;
        side(name).speed_failed = true;
      }).then(function () {
        if (self.data !== data) { return; }
        self.renderSummary();
        // The findings' area lines also said "Testing…".
        if (data.checks) { self.renderFindings(); }
      });
    }
    return step('mine').then(function () {
      return data.competitor ? step('competitor') : null;
    }).then(function () { self.speedFinished(data); });
  };

  /* After a speed run or retry: the status line says honestly how it ended, and a full report is refreshed. */
  Widget.prototype.speedFinished = function (data) {
    if (this.data !== data) { return; }
    var failed = data.speed_failed || (data.competitor && data.competitor.speed_failed);
    if (this.status.textContent === SPEED_PENDING || this.status.textContent === DONE || this.status.textContent === SPEED_FAILED) {
      this.say(failed ? SPEED_FAILED : DONE);
    }
    if (data.checks || data.unlocked) { this.loadFull(); }
  };

  /*
   * "Try the speed test again" for one page whose test gave no result. It asks the server to test that page once
   * more (retry: true) and nothing else: the page audit is not run again. While it runs the button is gone and
   * Speed says "Testing…", so it cannot be started twice; the server also refuses a second test for the page.
   */
  Widget.prototype.retrySpeed = function (name) {
    var self = this;
    var data = this.data;
    // Looked up each time: a full report loaded meanwhile replaces the competitor's object.
    function side() { return name === 'competitor' ? data.competitor : data; }
    if (!side() || side().retrying || !side().speed_failed) { return; }
    side().retrying = true;
    this.say('Testing the speed again. It takes up to 90 seconds.');
    this.renderSummary();
    this.speedRun = api('POST', 'speed', { report_id: data.report_id, side: name, retry: true }).then(function (speed) {
      if (self.data !== data) { return; }
      var fresh = name === 'competitor' ? speed.competitor : speed;
      if (fresh && fresh.speed_done) {
        side().summary = fresh.summary;
        side().speed_failed = !!fresh.speed_failed;
      }
      var failed = data.speed_failed || (data.competitor && data.competitor.speed_failed);
      self.say(failed ? SPEED_FAILED : DONE);
    }).catch(function (error) {
      if (self.data !== data) { return; }
      self.say(error.status === 409 ? error.message : 'The speed test did not return a result. ' + error.message, true);
    }).then(function () {
      if (self.data !== data) { return; }
      side().retrying = false;
      self.renderSummary();
      if (data.checks || data.unlocked) { self.loadFull(); }
    });
    return this.speedRun;
  };

  /* A button that retries the speed test of one page ('mine' or 'competitor'). */
  Widget.prototype.retryButton = function (name, label) {
    var self = this;
    var button = el('button', 'halo-audit__button halo-audit__button--ghost halo-audit__retry', label || RETRY);
    button.type = 'button';
    button.addEventListener('click', function () { self.retrySpeed(name); });
    return button;
  };

  Widget.prototype.render = function () {
    clear(this.results);
    this.results.hidden = false;
    this.summaryBox = el('div', 'halo-audit__summary');
    this.actions = el('div', 'halo-audit__actions');
    this.gateBox = el('div', 'halo-audit__gate');
    this.findings = el('div', 'halo-audit__findings');
    this.findings.tabIndex = -1;
    this.results.appendChild(this.summaryBox);
    this.results.appendChild(this.actions);
    this.results.appendChild(this.gateBox);
    this.results.appendChild(this.findings);
    this.renderSummary();
    this.renderActions();
  };

  Widget.prototype.renderSummary = function () {
    var data = this.data;
    var summary = data.summary;
    clear(this.summaryBox);
    if (data.competitor) { this.renderComparison(); return; }

    var meta = el('p', 'halo-audit__url');
    meta.appendChild(el('strong', null, data.url));
    if (data.keyword) { meta.appendChild(document.createTextNode(' \u00b7 keyword \u201c' + data.keyword + '\u201d')); }
    this.summaryBox.appendChild(meta);
    var score = el('div', 'halo-audit__score');
    var number = el('span', 'halo-audit__score-number', summary.overall === null ? 'Not measured' : summary.overall);
    score.appendChild(number);
    if (summary.overall !== null) { score.appendChild(el('span', 'halo-audit__score-of', 'out of 100')); }
    if (summary.grade) { score.appendChild(el('span', 'halo-audit__grade halo-audit__grade--' + summary.grade, 'Grade ' + summary.grade)); }
    this.summaryBox.appendChild(score);

    // role="list" on plain elements: a theme's bullet and indent rules for <ul> cannot reach it.
    var self = this;
    var list = el('div', 'halo-audit__groups');
    list.setAttribute('role', 'list');
    Object.keys(summary.groups).forEach(function (key) {
      var group = summary.groups[key];
      var item = el('div', 'halo-audit__group');
      item.setAttribute('role', 'listitem');
      item.appendChild(el('span', 'halo-audit__group-name', groupName(data, key)));
      var pending = key === 'speed' && (!data.speed_done || !!data.retrying);
      var failed = key === 'speed' && !pending && !!data.speed_failed;
      var value = el('span', 'halo-audit__group-score');
      if (pending) {
        value.textContent = 'Testing\u2026';
        value.classList.add('halo-audit__group-score--muted');
      } else if (failed) {
        value.textContent = 'No result';
        value.classList.add('halo-audit__group-score--muted');
      } else if (group.score === null) {
        value.textContent = 'Not measured';
        value.classList.add('halo-audit__group-score--muted');
      } else {
        value.textContent = group.score;
        value.appendChild(el('small', null, '/ 100'));
      }
      item.appendChild(value);
      if (!pending && !failed && group.score !== null) { item.appendChild(bar(group.score)); }
      item.appendChild(el('span', 'halo-audit__group-counts',
        pending ? 'Takes up to 90 seconds.' : (failed ? 'The speed test did not return a result.' : counts(group))));
      if (failed) { item.appendChild(self.retryButton('mine')); }
      list.appendChild(item);
    });
    this.summaryBox.appendChild(list);
    if (speedMeasured([data])) { this.summaryBox.appendChild(el('p', 'halo-audit__note', SPEED_NOTE)); }
  };

  // Speed is Halo Audit's own score from three checks. Said wherever it sits near Google's own number.
  var SPEED_NOTE = 'Speed is Halo Audit’s own score, not Google’s Performance score. It comes from three checks on ' +
    'Google’s mobile lab test (Lighthouse): the Performance score, LCP and CLS, each Pass 100, Improve 50 or Fix 0, weighted 3, 2 and 2.';

  function groupName(data, key) {
    var name = data.labels[key] || key;
    return key === 'speed' ? name + ' (mobile)' : name;
  }

  /*
   * "5 to fix \u00b7 1 to improve \u00b7 6 pass", always in that order, leaving out the counts that are zero.
   * "Every check passed" when every check in the area passed; "No result" when nothing was measured.
   */
  function counts(group) {
    var parts = [];
    if (group.fail) { parts.push(group.fail + ' to fix'); }
    if (group.warn) { parts.push(group.warn + ' to improve'); }
    if (group.pass && !parts.length && !group.not_measured) { return 'Every check passed'; }
    if (group.pass) { parts.push(group.pass + ' pass'); }
    return parts.length ? parts.join(' \u00b7 ') : 'No result';
  }

  /* A score bar. Decorative: the number beside it says the same. */
  function bar(score) {
    var track = el('span', 'halo-audit__bar');
    track.setAttribute('aria-hidden', 'true');
    var fill = el('span', 'halo-audit__bar-fill');
    fill.style.width = Math.max(0, Math.min(100, score)) + '%';
    track.appendChild(fill);
    return track;
  }

  function speedMeasured(sides) {
    return sides.some(function (s) { return s && s.speed_done && s.summary.groups.speed && s.summary.groups.speed.score !== null; });
  }

  /* An area's score as text. side is the page's data: its speed state decides what Speed says. */
  function groupScore(group, side, key) {
    if (key === 'speed' && (!side.speed_done || side.retrying)) { return 'Testing\u2026'; }
    if (key === 'speed' && side.speed_failed) { return 'No result'; }
    return !group || group.score === null ? 'Not measured' : group.score + ' / 100';
  }

  /* Comparison: both pages scored by the same checks against the same keyword. */
  Widget.prototype.renderComparison = function () {
    var data = this.data;
    var them = data.competitor;
    var box = this.summaryBox;

    if (data.keyword) { box.appendChild(el('p', 'halo-audit__url', 'Keyword “' + data.keyword + '”')); }
    var versus = el('div', 'halo-audit__versus');
    [['Your page', data, 'halo-audit__side--you'], ['Competitor page', them, 'halo-audit__side--them']].forEach(function (side) {
      var card = el('div', 'halo-audit__side ' + side[2]);
      card.appendChild(el('p', 'halo-audit__side-name', side[0]));
      card.appendChild(el('p', 'halo-audit__url', side[1].url));
      var score = el('div', 'halo-audit__score');
      var overall = side[1].summary.overall;
      score.appendChild(el('span', 'halo-audit__score-number', overall === null ? 'Not measured' : overall));
      if (overall !== null) { score.appendChild(el('span', 'halo-audit__score-of', 'out of 100')); }
      if (side[1].summary.grade) { score.appendChild(el('span', 'halo-audit__grade halo-audit__grade--' + side[1].summary.grade, 'Grade ' + side[1].summary.grade)); }
      card.appendChild(score);
      versus.appendChild(card);
    });
    box.appendChild(versus);

    var table = el('table', 'halo-audit__compare');
    var head = el('tr');
    ['Area', 'Your page', 'Competitor page', 'Difference'].forEach(function (text) {
      var th = el('th', null, text); th.scope = 'col'; head.appendChild(th);
    });
    var thead = el('thead'); thead.appendChild(head); table.appendChild(thead);
    var tbody = el('tbody');
    Object.keys(data.summary.groups).forEach(function (key) {
      var mine = data.summary.groups[key];
      var theirs = them.summary.groups[key];
      var row = el('tr');
      var name = el('th', null, groupName(data, key)); name.scope = 'row'; row.appendChild(name);
      row.appendChild(el('td', null, groupScore(mine, data, key)));
      row.appendChild(el('td', null, groupScore(theirs, them, key)));
      var diff = '';
      var cls = 'halo-audit__diff';
      if (mine && theirs && mine.score !== null && theirs.score !== null) {
        var d = mine.score - theirs.score;
        diff = d === 0 ? 'Level' : (d > 0 ? '+' + d + ' ahead' : d + ' behind');
        cls += d > 0 ? ' halo-audit__diff--ahead' : (d < 0 ? ' halo-audit__diff--behind' : '');
      } else if ((mine && mine.score !== null) !== (theirs && theirs.score !== null) &&
          !(key === 'speed' && (!data.speed_done || !them.speed_done))) {
        // Measured on one page only. Not measured on both leaves the cell empty.
        diff = 'Not comparable';
      }
      row.appendChild(el('td', cls, diff));
      tbody.appendChild(row);
    });
    table.appendChild(tbody);
    var wrap = el('div', 'halo-audit__compare-wrap');
    wrap.appendChild(table);
    box.appendChild(wrap);

    // A page whose speed test gave no result: say so and offer a retry for that page only.
    var self = this;
    [['mine', data, 'your page', 'Try again for your page'], ['competitor', them, 'the competitor page', 'Try again for the competitor page']].forEach(function (side) {
      if (!side[1].speed_failed || side[1].retrying) { return; }
      var note = el('p', 'halo-audit__note halo-audit__speed-failed', 'The speed test for ' + side[2] + ' did not return a result, so its Speed is not measured. ');
      note.appendChild(self.retryButton(side[0], side[3]));
      box.appendChild(note);
    });

    var mineSpeed = data.summary.groups.speed;
    var theirSpeed = them.summary.groups.speed;
    if (data.speed_done && them.speed_done && mineSpeed && theirSpeed && (mineSpeed.score === null) !== (theirSpeed.score === null)) {
      box.appendChild(el('p', 'halo-audit__note', 'Speed was measured for only one of the two pages, so the overall scores are not directly comparable.'));
    }
    if (speedMeasured([data, them])) { box.appendChild(el('p', 'halo-audit__note', SPEED_NOTE)); }
  };

  Widget.prototype.renderActions = function () {
    var self = this;
    clear(this.actions);
    var see = el('button', 'halo-audit__button', 'See all findings');
    see.type = 'button';
    see.addEventListener('click', function () { self.need(function () { self.loadFull(); }); });
    var pdf = el('button', 'halo-audit__button halo-audit__button--ghost', 'Download PDF');
    pdf.type = 'button';
    pdf.addEventListener('click', function () { self.need(function () { self.downloadPdf(pdf); }); });
    this.actions.appendChild(see);
    this.actions.appendChild(pdf);
  };

  Widget.prototype.need = function (then) {
    if (this.data.unlocked) { then(); return; }
    this.renderGate(then);
  };

  Widget.prototype.field = function (labelText, input, name) {
    var label = el('label', 'halo-audit__field');
    label.appendChild(el('span', 'halo-audit__label', labelText));
    input.name = name;
    label.appendChild(input);
    return label;
  };

  Widget.prototype.renderGate = function (then) {
    var self = this;
    var box = this.gateBox;
    var data = this.data;
    clear(box);
    box.appendChild(this.heading(1, 'halo-audit__gate-title', 'See every finding'));
    box.appendChild(el('p', 'halo-audit__gate-intro', 'Enter your name and email. We send a 6-digit code to confirm the address.'));

    var name = el('input'); name.type = 'text'; name.autocomplete = 'name';
    var email = el('input'); email.type = 'email'; email.autocomplete = 'email'; email.inputMode = 'email';
    var sendButton = el('button', 'halo-audit__button', 'Send code'); sendButton.type = 'button';
    var code = el('input'); code.type = 'text'; code.inputMode = 'numeric'; code.maxLength = 6; code.autocomplete = 'one-time-code'; code.pattern = '[0-9]{6}';
    var codeField = this.field('6-digit code', code, 'halo_code'); codeField.hidden = true;
    var verifyButton = el('button', 'halo-audit__button', 'Confirm'); verifyButton.type = 'button'; verifyButton.hidden = true;
    // The live region is in the page, empty, before any message is written into it.
    var note = el('p', 'halo-audit__status');
    note.id = this.id + '-gate-status';
    note.setAttribute('role', 'status');
    note.setAttribute('aria-live', 'polite');
    [this.field('Your name', name, 'halo_name'), this.field('Work email', email, 'halo_email'), sendButton, codeField, verifyButton, note]
      .forEach(function (node) { box.appendChild(node); });

    function say(text, isError) {
      note.textContent = text;
      note.classList.toggle('halo-audit__status--error', !!isError);
    }

    sendButton.addEventListener('click', function () {
      var noName = !name.value.trim();
      var noEmail = !email.value.trim();
      flag(name, note.id, noName);
      flag(email, note.id, noEmail);
      if (noName || noEmail) {
        say('Enter your name and email.', true);
        (noName ? name : email).focus();
        return;
      }
      sendButton.disabled = true;
      api('POST', 'gate/send', { email: email.value.trim(), report_id: data.report_id }).then(function () {
        codeField.hidden = false;
        verifyButton.hidden = false;
        sendButton.textContent = 'Send a new code';
        say('We sent a code to ' + email.value.trim() + '. It is valid for 15 minutes.');
        self.setState('code');
        code.focus();
      }).catch(function (error) {
        flag(email, note.id, true);
        say(error.message, true);
      }).then(function () { sendButton.disabled = false; });
    });

    verifyButton.addEventListener('click', function () {
      verifyButton.disabled = true;
      api('POST', 'gate/verify', {
        email: email.value.trim(), name: name.value.trim(), code: code.value.trim(), report_id: data.report_id
      }).then(function (full) {
        if (self.data !== data) { return; }
        data.unlocked = true;
        clear(box);
        if (self.isBehind(full)) {
          self.loadFull();
        } else {
          self.applyFull(full);
          self.renderSummary();
          self.renderFindings();
        }
        self.setState('unlocked');
        self.findings.focus();
        // The unlock is a cookie for the whole site, so every other widget on the page is unlocked too.
        widgets.forEach(function (other) { if (other !== self) { other.unlockFromElsewhere(); } });
        then();
      }).catch(function (error) {
        flag(code, note.id, true);
        say(error.message, true);
        code.focus();
      }).then(function () { verifyButton.disabled = false; });
    });

    code.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') { event.preventDefault(); verifyButton.click(); }
    });

    this.setState('gate');
    name.focus();
  };

  Widget.prototype.unlockFromElsewhere = function () {
    if (!this.data || this.data.unlocked) { return; }
    this.data.unlocked = true;
    if (this.gateBox && this.gateBox.firstChild) {
      clear(this.gateBox);
      this.loadFull();
    }
  };

  /*
   * Responses can arrive out of order: a full report fetched before a speed test finished can land after it.
   * isBehind() spots a full report that knows less about speed than the page already shows.
   */
  Widget.prototype.isBehind = function (full) {
    var data = this.data;
    return !!((data.speed_done && !full.speed_done) ||
      (data.competitor && data.competitor.speed_done && full.competitor && !full.competitor.speed_done));
  };

  /* Takes the findings and scores of a full report. A page's speed state only moves forward. */
  Widget.prototype.applyFull = function (full) {
    var data = this.data;
    var mineDone = !!data.speed_done;
    var theirsDone = !!(data.competitor && data.competitor.speed_done);
    data.checks = full.checks;
    data.pairs = full.pairs || null;
    data.summary = full.summary;
    var mineFailed = !!data.speed_failed;
    var theirsFailed = !!(data.competitor && data.competitor.speed_failed);
    var theirsRetrying = !!(data.competitor && data.competitor.retrying);
    data.speed_done = mineDone || !!full.speed_done;
    // A stored result wins over a failure seen in the browser (a call that timed out after the server saved it).
    data.speed_failed = full.speed_done ? !!full.speed_failed : mineFailed;
    if (full.competitor) {
      data.competitor = full.competitor;
      data.competitor.speed_done = theirsDone || !!full.competitor.speed_done;
      data.competitor.speed_failed = full.competitor.speed_done ? !!full.competitor.speed_failed : theirsFailed;
      data.competitor.retrying = theirsRetrying;
    }
  };

  Widget.prototype.loadFull = function (retried) {
    var self = this;
    var data = this.data;
    api('GET', 'report/' + data.report_id).then(function (full) {
      if (self.data !== data) { return; }
      // The stored report is newer than this answer by now (the speed result is saved before it is sent).
      if (self.isBehind(full) && !retried) { self.loadFull(true); return; }
      self.applyFull(full);
      self.renderSummary();
      self.renderFindings();
      self.setState('unlocked');
    }).catch(function (error) {
      if (self.data !== data) { return; }
      if (error.status === 403) { data.unlocked = false; self.renderGate(function () { self.loadFull(); }); return; }
      self.say(error.message, true);
    });
  };

  /* One page's result for one check in a comparison. side is null when the check did not run on that page. */
  function result(side, who, label, withFix) {
    var box = el('div', 'halo-audit__result halo-audit__result--' + who + ' halo-audit__result--' + (side ? side.status : 'not_checked'));
    box.appendChild(el('span', 'halo-audit__result-page', label));
    box.appendChild(el('span', 'halo-audit__badge', side ? (STATUS[side.status] || side.status) : 'Not checked'));
    if (side && side.evidence) { box.appendChild(el('p', 'halo-audit__evidence', side.evidence)); }
    // The fix belongs to your page only (the server sends no fix for the competitor's side).
    if (withFix && side && side.message) { box.appendChild(fixRow(side.message)); }
    return box;
  }

  /* The "What to do" row: the label, then the check's own advice text. One builder for both views. */
  function fixRow(message) {
    var fix = el('p', 'halo-audit__fix');
    fix.appendChild(el('strong', null, 'What to do: '));
    fix.appendChild(document.createTextNode(message));
    return fix;
  }

  /* Passed checks folded under a native disclosure, with how many there are; nothing when there are none. */
  function foldPassed(summaryText, items) {
    if (!items.length) { return null; }
    var fold = el('details', 'halo-audit__passed');
    fold.appendChild(el('summary', null, summaryText));
    items.forEach(function (item) { fold.appendChild(item); });
    return fold;
  }

  /* One check of a comparison: its name, both pages' results side by side, and why it matters. */
  function compareCheck(pair) {
    var item = el('div', 'halo-audit__check halo-audit__check--compare');
    item.setAttribute('data-check', pair.id);
    item.appendChild(el('strong', 'halo-audit__check-title', pair.title));
    var both = el('div', 'halo-audit__results-pair');
    both.appendChild(result(pair.mine, 'you', 'Your page', true));
    both.appendChild(result(pair.theirs, 'them', 'Competitor page', false));
    item.appendChild(both);
    if (pair.why) { item.appendChild(el('p', 'halo-audit__why', pair.why)); }
    return item;
  }

  /*
   * Comparison findings, laid out like the single-page view: "Fix these first" for your page, then each area with
   * the checks that need a look first (by your page's status: Fix, Improve, not measured or not checked) and the
   * checks that passed on both pages folded away. A check that passed on your page but not on the competitor's
   * stays open: the difference is the point of a comparison.
   */
  Widget.prototype.renderComparisonFindings = function () {
    var self = this;
    var data = this.data;
    var box = this.findings;
    var groups = {};
    var order = [];
    var theirs = {};
    (data.pairs || []).forEach(function (pair) {
      if (!pair || !pair.group) { return; }
      if (!groups[pair.group]) { groups[pair.group] = []; order.push(pair.group); }
      groups[pair.group].push(pair);
      theirs[pair.id] = pair.theirs || null;
    });
    this.renderTopFixes(theirs);
    var bothPassed = function (pair) { return !!(pair.mine && pair.theirs && pair.mine.status === 'pass' && pair.theirs.status === 'pass'); };
    var attention = function (pair) {
      var rank = pair.mine ? ATTENTION[pair.mine.status] : undefined;
      return rank === undefined ? 2 : rank;
    };
    order.forEach(function (key) {
      var section = el('section', 'halo-audit__section');
      section.appendChild(self.heading(1, 'halo-audit__section-title', data.labels[key] || key));
      var mine = data.summary.groups[key];
      var theirScore = data.competitor.summary.groups[key];
      if (mine || theirScore) {
        section.appendChild(el('p', 'halo-audit__section-scores',
          'Your page: ' + groupScore(mine, data, key) + ' \u00b7 Competitor page: ' + groupScore(theirScore, data.competitor, key)));
      }
      if (key === 'speed' && speedMeasured([data, data.competitor])) { section.appendChild(el('p', 'halo-audit__note', SPEED_NOTE)); }
      var pairs = groups[key];
      pairs.filter(function (pair) { return !bothPassed(pair); })
        .map(function (pair, i) { return { pair: pair, i: i }; })
        .sort(function (a, b) { return (attention(a.pair) - attention(b.pair)) || (a.i - b.i); })
        .forEach(function (item) { section.appendChild(compareCheck(item.pair)); });
      var passed = pairs.filter(bothPassed);
      var fold = foldPassed(passed.length + (passed.length === 1 ? ' check passed on both pages' : ' checks passed on both pages'), passed.map(compareCheck));
      if (fold) { section.appendChild(fold); }
      box.appendChild(section);
    });
  };

  Widget.prototype.renderFindings = function () {
    var self = this;
    var data = this.data;
    var box = this.findings;
    clear(box);
    if (!data.checks) { return; }

    box.classList.toggle('halo-audit__findings--compare', !!(data.competitor && data.pairs));
    if (data.competitor && data.pairs) {
      this.renderComparisonFindings();
    } else {
      this.renderTopFixes();
      var groups = {};
      data.checks.forEach(function (row) { (groups[row.group] = groups[row.group] || []).push(row); });
      Object.keys(groups).forEach(function (key) {
        var section = el('section', 'halo-audit__section');
        var title = self.heading(1, 'halo-audit__section-title', groupName(data, key));
        var group = data.summary.groups[key];
        var meta = (group ? groupScore(group, data, key) : 'Not scored') + ' \u00b7 ' + groups[key].length + (groups[key].length === 1 ? ' check' : ' checks');
        title.appendChild(el('span', 'halo-audit__section-meta', meta));
        section.appendChild(title);
        if (key === 'speed' && speedMeasured([data])) { section.appendChild(el('p', 'halo-audit__note', SPEED_NOTE)); }
        var rows = groups[key].slice().sort(function (a, b) {
          return (ATTENTION[a.status] === undefined ? 2 : ATTENTION[a.status]) - (ATTENTION[b.status] === undefined ? 2 : ATTENTION[b.status]);
        });
        var passed = rows.filter(function (row) { return row.status === 'pass'; });
        rows.filter(function (row) { return row.status !== 'pass'; }).forEach(function (row) { section.appendChild(check(row, true)); });
        // The passed checks are folded away: the open list is what needs work.
        var fold = foldPassed(passed.length + (passed.length === 1 ? ' check passed' : ' checks passed'),
          passed.map(function (row) { return check(row, false); }));
        if (fold) { section.appendChild(fold); }
        box.appendChild(section);
      });
    }

    var scan = el('section', 'halo-audit__section halo-audit__scan');
    scan.appendChild(self.heading(1, 'halo-audit__section-title', 'Live AI visibility'));
    scan.appendChild(el('p', null, 'Coming soon. This will show whether ChatGPT, Gemini and Google AI Overviews name your organisation.'));
    box.appendChild(scan);
    this.renderCta();
  };

  /* One finding. With detail, the "What to do" row and the reason; a passed check shows its result only. */
  function check(row, detail) {
    var item = el('div', 'halo-audit__check halo-audit__check--' + row.status);
    item.appendChild(el('span', 'halo-audit__badge', STATUS[row.status] || row.status));
    var body = el('div', 'halo-audit__check-body');
    body.appendChild(el('strong', 'halo-audit__check-title', row.title));
    if (row.evidence) { body.appendChild(el('p', 'halo-audit__evidence', row.evidence)); }
    if (detail && row.message) { body.appendChild(fixRow(row.message)); }
    if (detail && row.why) { body.appendChild(el('p', 'halo-audit__why', row.why)); }
    item.appendChild(body);
    return item;
  }

  /*
   * The fixes that matter most, in the PDF's order (Pdf::rankFixes): Fix before Improve, then the checks that count
   * most in their area (severity), then the areas that count most in the overall score. A tie keeps the order of the
   * report. A check listed twice is listed once.
   */
  function rankFixes(checks) {
    var weight = { onpage: 30, technical: 20, ai: 30, speed: 20 };
    var seen = {};
    return (Array.isArray(checks) ? checks : []).map(function (row, i) { return { row: row, i: i }; }).filter(function (item) {
      var row = item.row;
      if (!row || (row.status !== 'fail' && row.status !== 'warn')) { return false; }
      if (row.id === undefined || row.id === null) { return true; }
      if (seen[row.id]) { return false; }
      seen[row.id] = true;
      return true;
    }).sort(function (a, b) {
      return (ATTENTION[a.row.status] - ATTENTION[b.row.status]) ||
        ((b.row.severity || 0) - (a.row.severity || 0)) ||
        ((weight[b.row.group] || 0) - (weight[a.row.group] || 0)) ||
        (a.i - b.i);
    }).slice(0, TOP_FIXES).map(function (item) { return item.row; });
  }

  /*
   * "Fix these first". Always your page's checks (data.checks): in a comparison a check that fails only on the
   * competitor page is not a fix for you. With theirs (check ID to the competitor's result, from the pairs), each
   * item also says how the competitor page did on that check.
   */
  Widget.prototype.renderTopFixes = function (theirs) {
    var data = this.data;
    var rows = rankFixes(data.checks);
    if (!rows.length) { return; }
    var section = el('section', 'halo-audit__section halo-audit__top');
    section.appendChild(this.heading(1, 'halo-audit__section-title', 'Fix these first'));
    var list = el('ol', 'halo-audit__top-list');
    rows.forEach(function (row) {
      var item = el('li', 'halo-audit__check--' + row.status);
      var text = el('span', 'halo-audit__top-text');
      text.appendChild(el('strong', null, row.title));
      var area = groupName(data, row.group);
      if (theirs) {
        var other = theirs[row.id];
        area += ' · Competitor: ' + (other ? (STATUS[other.status] || other.status) : 'Not checked');
      }
      text.appendChild(el('span', 'halo-audit__top-area', area));
      item.appendChild(text);
      item.appendChild(el('span', 'halo-audit__badge', STATUS[row.status]));
      list.appendChild(item);
    });
    section.appendChild(list);
    this.findings.appendChild(section);
  };

  /* The site's closing block, from the shortcode's cta_* attributes. None without a link. */
  Widget.prototype.renderCta = function () {
    var url = this.root.getAttribute('data-cta-url');
    if (!url || !/^https?:\/\//i.test(url)) { return; }
    var box = el('div', 'halo-audit__cta');
    var title = this.root.getAttribute('data-cta-title');
    var text = this.root.getAttribute('data-cta-text');
    if (title) { box.appendChild(this.heading(1, 'halo-audit__cta-title', title)); }
    if (text) { box.appendChild(el('p', 'halo-audit__cta-text', text)); }
    var link = el('a', 'halo-audit__button', this.root.getAttribute('data-cta-button') || 'Talk to us');
    link.href = url;
    box.appendChild(link);
    this.findings.appendChild(box);
  };

  Widget.prototype.downloadPdf = function (button) {
    var self = this;
    if (button.disabled) { return; }
    button.disabled = true;
    var data = this.data;
    var speedDone = data.speed_done && !data.retrying && (!data.competitor || (data.competitor.speed_done && !data.competitor.retrying));
    // The PDF is made from the stored report: wait for a running speed test so the PDF has its result.
    var waits = !speedDone && !!this.speedRun;
    if (waits) { this.say('Making the PDF once the speed test has finished…'); }
    var name = 'halo-audit.pdf';
    (waits ? this.speedRun : Promise.resolve()).then(function () {
      return send('GET', 'report/' + data.report_id + '/pdf', null, false);
    }).then(function (response) {
      if (!response.ok) { throw new Error('The PDF could not be made. Try again.'); }
      name = fileName(response.headers.get('Content-Disposition'));
      return response.blob();
    }).then(function (blob) {
      var link = el('a');
      link.href = URL.createObjectURL(blob);
      link.download = name; // The name the server sends in Content-Disposition.
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
      if (waits) { self.say(data.speed_failed || (data.competitor && data.competitor.speed_failed) ? SPEED_FAILED : DONE); }
    }).catch(function (error) { self.say(error.message, true); }).then(function () { button.disabled = false; });
  };

  /*
   * The server's file name ("halo-audit-<site>-<date>.pdf"), quoted or not, only in the shape the server builds;
   * else the plain name. A name with a path, a quote or any other character is never used.
   */
  function fileName(disposition) {
    var match = /(?:^|;)\s*filename\s*=\s*(?:"(halo-audit[a-z0-9.-]*\.pdf)"|(halo-audit[a-z0-9.-]*\.pdf))\s*(?:;|$)/i.exec(String(disposition || ''));
    var name = match ? (match[1] || match[2]) : '';
    return name && name.length <= 160 && name.indexOf('..') === -1 ? name : 'halo-audit.pdf';
  }

  function boot() {
    if (!cfg.root) { return; }
    var nodes = document.querySelectorAll('.halo-audit');
    for (var i = 0; i < nodes.length; i++) {
      // The script may be printed twice by a page builder or an optimiser. Each widget starts once.
      if (nodes[i].getAttribute('data-halo-ready') === '1' || !nodes[i].querySelector('.halo-audit__form')) { continue; }
      nodes[i].setAttribute('data-halo-ready', '1');
      widgets.push(new Widget(nodes[i]));
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
