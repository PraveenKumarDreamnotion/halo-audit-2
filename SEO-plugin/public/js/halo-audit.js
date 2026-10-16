(function () {
  'use strict';

  var cfg = window.HaloAudit || {};
  var nonce = null;
  var widgets = [];
  var STATUS = { pass: 'Pass', warn: 'Improve', fail: 'Fix', not_measured: 'Not measured' };

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
    this.say(competitorField ? 'Reading both pages. This takes up to a minute.' : 'Reading the page. This takes up to 15 seconds.');
    this.setState('loading');

    var body = { url: url, keyword: keyword };
    if (competitorField) { body.competitor_url = competitorUrl; }
    // The signed address of the page this form is on (FORM_NAME in Brevo). The server checks the signature.
    if (this.root.getAttribute('data-form-page')) { body.form_page = this.root.getAttribute('data-form-page'); }
    api('POST', 'analyze', body).then(function (data) {
      self.data = data;
      self.say('Audit complete. The score is below.');
      self.render();
      self.setState('preview');
      if (data.unlocked) { self.loadFull(); }
      // The server measures one page per call; a comparison needs a second call for the competitor.
      function speedDone() { return data.speed_done && (!data.competitor || data.competitor.speed_done); }
      function measure() {
        if (speedDone()) { return null; }
        return api('POST', 'speed', { report_id: data.report_id }).then(function (speed) {
          if (self.data !== data) { return null; }
          data.summary = speed.summary;
          data.speed_done = speed.speed_done;
          if (speed.competitor && data.competitor) {
            data.competitor.summary = speed.competitor.summary;
            data.competitor.speed_done = speed.competitor.speed_done;
          }
          self.renderSummary();
          if (speedDone()) {
            if (data.checks || data.unlocked) { self.loadFull(); }
            return null;
          }
          return measure();
        });
      }
      var measured = measure();
      return measured && measured.catch(function () {
        if (self.data !== data) { return; }
        data.speed_done = true;
        if (data.competitor) { data.competitor.speed_done = true; }
        self.renderSummary();
        // The findings' area lines also said "Testing speed...": show "Not measured" there too.
        if (data.checks) { self.renderFindings(); }
      });
    }).catch(function (error) {
      self.say(error.message, true);
      self.setState('error');
    }).then(function () {
      self.button.disabled = false;
    });
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

    this.summaryBox.appendChild(el('p', 'halo-audit__url', data.url));
    var score = el('div', 'halo-audit__score');
    var number = el('span', 'halo-audit__score-number', summary.overall === null ? 'Not measured' : summary.overall);
    score.appendChild(number);
    if (summary.overall !== null) { score.appendChild(el('span', 'halo-audit__score-of', 'out of 100')); }
    if (summary.grade) { score.appendChild(el('span', 'halo-audit__grade halo-audit__grade--' + summary.grade, 'Grade ' + summary.grade)); }
    this.summaryBox.appendChild(score);

    // role="list" on plain elements: a theme's bullet and indent rules for <ul> cannot reach it.
    var list = el('div', 'halo-audit__groups');
    list.setAttribute('role', 'list');
    Object.keys(summary.groups).forEach(function (key) {
      var group = summary.groups[key];
      var item = el('div', 'halo-audit__group');
      item.setAttribute('role', 'listitem');
      item.appendChild(el('span', 'halo-audit__group-name', data.labels[key] || key));
      var value;
      if (key === 'speed' && !data.speed_done) {
        value = 'Testing speed...';
      } else if (group.score === null) {
        value = 'Not measured';
      } else {
        value = group.score + ' / 100';
      }
      item.appendChild(el('span', 'halo-audit__group-score', value));
      item.appendChild(el('span', 'halo-audit__group-counts', group.pass + ' pass, ' + group.warn + ' to improve, ' + group.fail + ' to fix'));
      list.appendChild(item);
    });
    this.summaryBox.appendChild(list);
  };

  function groupScore(group, speedDone, key) {
    if (key === 'speed' && !speedDone) { return 'Testing speed...'; }
    return !group || group.score === null ? 'Not measured' : group.score + ' / 100';
  }

  /* Comparison: both pages scored by the same checks against the same keyword. */
  Widget.prototype.renderComparison = function () {
    var data = this.data;
    var them = data.competitor;
    var box = this.summaryBox;

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
      var name = el('th', null, data.labels[key] || key); name.scope = 'row'; row.appendChild(name);
      row.appendChild(el('td', null, groupScore(mine, data.speed_done, key)));
      row.appendChild(el('td', null, groupScore(theirs, them.speed_done, key)));
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

    var mineSpeed = data.summary.groups.speed;
    var theirSpeed = them.summary.groups.speed;
    if (data.speed_done && them.speed_done && mineSpeed && theirSpeed && (mineSpeed.score === null) !== (theirSpeed.score === null)) {
      box.appendChild(el('p', 'halo-audit__note', 'Speed was measured for only one of the two pages, so the overall scores are not directly comparable.'));
    }
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
    data.speed_done = mineDone || !!full.speed_done;
    if (full.competitor) {
      data.competitor = full.competitor;
      data.competitor.speed_done = theirsDone || !!full.competitor.speed_done;
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
    if (withFix && side && side.message) { box.appendChild(el('p', 'halo-audit__fix', side.message)); }
    return box;
  }

  Widget.prototype.renderComparisonFindings = function () {
    var self = this;
    var data = this.data;
    var box = this.findings;
    var groups = {};
    var order = [];
    data.pairs.forEach(function (pair) {
      if (!groups[pair.group]) { groups[pair.group] = []; order.push(pair.group); }
      groups[pair.group].push(pair);
    });
    order.forEach(function (key) {
      var section = el('section', 'halo-audit__section');
      section.appendChild(self.heading(1, 'halo-audit__section-title', data.labels[key] || key));
      var mine = data.summary.groups[key];
      var theirs = data.competitor.summary.groups[key];
      if (mine || theirs) {
        section.appendChild(el('p', 'halo-audit__section-scores',
          'Your page: ' + groupScore(mine, data.speed_done, key) + ' \u00b7 Competitor page: ' + groupScore(theirs, data.competitor.speed_done, key)));
      }
      groups[key].forEach(function (pair) {
        var item = el('div', 'halo-audit__check halo-audit__check--compare');
        item.setAttribute('data-check', pair.id);
        item.appendChild(el('strong', 'halo-audit__check-title', pair.title));
        var both = el('div', 'halo-audit__results-pair');
        both.appendChild(result(pair.mine, 'you', 'Your page', true));
        both.appendChild(result(pair.theirs, 'them', 'Competitor page', false));
        item.appendChild(both);
        item.appendChild(el('p', 'halo-audit__why', pair.why));
        section.appendChild(item);
      });
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
      var groups = {};
      data.checks.forEach(function (row) { (groups[row.group] = groups[row.group] || []).push(row); });
      Object.keys(groups).forEach(function (key) {
        var section = el('section', 'halo-audit__section');
        section.appendChild(self.heading(1, 'halo-audit__section-title', data.labels[key] || key));
        groups[key].forEach(function (row) {
          var item = el('div', 'halo-audit__check halo-audit__check--' + row.status);
          item.appendChild(el('span', 'halo-audit__badge', STATUS[row.status] || row.status));
          var body = el('div', 'halo-audit__check-body');
          body.appendChild(el('strong', 'halo-audit__check-title', row.title));
          if (row.evidence) { body.appendChild(el('p', 'halo-audit__evidence', row.evidence)); }
          if (row.message) { body.appendChild(el('p', 'halo-audit__fix', row.message)); }
          body.appendChild(el('p', 'halo-audit__why', row.why));
          item.appendChild(body);
          section.appendChild(item);
        });
        box.appendChild(section);
      });
    }

    var scan = el('section', 'halo-audit__section halo-audit__scan');
    scan.appendChild(self.heading(1, 'halo-audit__section-title', 'Live AI visibility'));
    scan.appendChild(el('p', null, 'Coming soon. This will show whether ChatGPT, Gemini and Google AI Overviews name your organisation.'));
    box.appendChild(scan);
  };

  Widget.prototype.downloadPdf = function (button) {
    var self = this;
    if (button.disabled) { return; }
    button.disabled = true;
    send('GET', 'report/' + this.data.report_id + '/pdf', null, false).then(function (response) {
      if (!response.ok) { throw new Error('The PDF could not be made. Try again.'); }
      return response.blob();
    }).then(function (blob) {
      var link = el('a');
      link.href = URL.createObjectURL(blob);
      link.download = 'halo-audit.pdf';
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
    }).catch(function (error) { self.say(error.message, true); }).then(function () { button.disabled = false; });
  };

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
