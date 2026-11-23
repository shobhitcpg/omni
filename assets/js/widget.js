/**
 * OmniThread AI chat widget – dependency-free, rendered inside a Shadow DOM so no theme CSS can interfere.
 */
(function () {
	'use strict';

	var cfg = window.OTAI_CONFIG;
	if (!cfg || window.__otaiLoaded) {
		return;
	}
	window.__otaiLoaded = true;
	var t = cfg.i18n || {};

	function uuid() {
		if (window.crypto && window.crypto.randomUUID) {
			return window.crypto.randomUUID();
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
			var r = (Math.random() * 16) | 0;
			return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
		});
	}

	var store = {
		load: function () {
			try {
				var data = JSON.parse(window.localStorage.getItem(cfg.storageKey) || 'null');
				if (data && data.session && Array.isArray(data.messages)) {
					return data;
				}
			} catch (e) {}
			return { session: uuid(), messages: [], open: false };
		},
		save: function (state) {
			try {
				window.localStorage.setItem(cfg.storageKey, JSON.stringify({
					session: state.session,
					messages: state.messages.slice(-40),
					open: state.open
				}));
			} catch (e) {}
		}
	};

	var state = store.load();

	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function inline(s) {
		return s
			.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
			.replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, '$1<a href="$2" target="_blank" rel="noopener noreferrer">$2</a>')
			.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
			.replace(/`([^`]+)`/g, '<code>$1</code>');
	}

	// Minimal, XSS-safe Markdown: everything is escaped first, then a small whitelist is re-introduced.
	function md(text) {
		var lines = esc(text).split(/\n/);
		var html = '';
		var list = null;
		lines.forEach(function (line) {
			var ul = line.match(/^\s*[-*•]\s+(.*)$/);
			var ol = line.match(/^\s*\d+[.)]\s+(.*)$/);
			var type = ul ? 'ul' : ol ? 'ol' : null;
			if (type) {
				if (list !== type) {
					if (list) { html += '</' + list + '>'; }
					html += '<' + type + '>';
					list = type;
				}
				html += '<li>' + inline((ul || ol)[1]) + '</li>';
				return;
			}
			if (list) {
				html += '</' + list + '>';
				list = null;
			}
			var h = line.match(/^#{1,4}\s+(.*)$/);
			if (h) {
				html += '<p><strong>' + inline(h[1]) + '</strong></p>';
			} else if (line.trim() !== '') {
				html += '<p>' + inline(line) + '</p>';
			}
		});
		if (list) { html += '</' + list + '>'; }
		return html;
	}

	function el(tag, cls, text) {
		var node = document.createElement(tag);
		if (cls) { node.className = cls; }
		if (text) { node.textContent = text; }
		return node;
	}

	var ICON_CHAT = '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path fill="currentColor" d="M12 3C6.5 3 2 6.6 2 11c0 2.2 1.1 4.2 2.9 5.7L4 21l4.6-2.3c1.1.3 2.2.4 3.4.4 5.5 0 10-3.6 10-8s-4.5-8-10-8z"/></svg>';
	var ICON_SEND = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M3.4 20.4 21 12 3.4 3.6 3.4 10l12.6 2-12.6 2z"/></svg>';
	var ICON_CLOSE = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7 2.9 18.3 9.2 12 2.9 5.7 4.3 4.3l6.3 6.3 6.3-6.3z"/></svg>';
	var ICON_RESET = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M12 5V1L7 6l5 5V7a5 5 0 1 1-5 5H5a7 7 0 1 0 7-7z"/></svg>';

	function Chat(mount, floating) {
		this.floating = floating;
		this.busy = false;
		var host = document.createElement('div');
		host.className = 'otai-host';
		if (!floating) { host.style.height = '100%'; }
		mount.appendChild(host);
		var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
		this.root = root;

		var link = document.createElement('link');
		link.rel = 'stylesheet';
		link.href = cfg.cssUrl;
		root.appendChild(link);

		var wrap = el('div', 'otai ' + (floating ? 'otai--floating otai--' + (cfg.position === 'left' ? 'left' : 'right') : 'otai--inline'));
		wrap.style.setProperty('--otai-primary', cfg.color || '#2563eb');
		wrap.style.visibility = 'hidden';
		link.onload = link.onerror = function () { wrap.style.visibility = ''; };
		this.wrap = wrap;

		if (floating) {
			var launcher = el('button', 'otai-launcher');
			launcher.type = 'button';
			launcher.setAttribute('aria-label', t.open || 'Open chat');
			launcher.setAttribute('aria-expanded', 'false');
			launcher.innerHTML = ICON_CHAT + (cfg.launcherLabel ? '<span>' + esc(cfg.launcherLabel) + '</span>' : '');
			launcher.addEventListener('click', this.toggle.bind(this, true));
			wrap.appendChild(launcher);
			this.launcher = launcher;
		}

		var panel = el('section', 'otai-panel');
		panel.setAttribute('role', floating ? 'dialog' : 'region');
		panel.setAttribute('aria-label', cfg.botName);
		if (floating) { panel.hidden = true; }

		var header = el('header', 'otai-header');
		var title = el('div', 'otai-title');
		title.appendChild(el('span', 'otai-dot'));
		title.appendChild(el('span', '', cfg.botName));
		var actions = el('div', 'otai-actions');
		var reset = el('button', 'otai-icon');
		reset.type = 'button';
		reset.title = t.reset || 'Reset';
		reset.setAttribute('aria-label', t.reset || 'Reset');
		reset.innerHTML = ICON_RESET;
		reset.addEventListener('click', this.reset.bind(this));
		actions.appendChild(reset);
		if (floating) {
			var close = el('button', 'otai-icon');
			close.type = 'button';
			close.setAttribute('aria-label', t.close || 'Close');
			close.innerHTML = ICON_CLOSE;
			close.addEventListener('click', this.toggle.bind(this, false));
			actions.appendChild(close);
		}
		header.appendChild(title);
		header.appendChild(actions);

		this.log = el('div', 'otai-log');
		this.log.setAttribute('role', 'log');
		this.log.setAttribute('aria-live', 'polite');

		this.starters = el('div', 'otai-starters');

		var form = el('form', 'otai-composer');
		var input = el('textarea', 'otai-input');
		input.rows = 1;
		input.maxLength = cfg.maxLength || 1000;
		input.placeholder = t.placeholder || 'Type your message…';
		input.setAttribute('aria-label', t.placeholder || 'Message');
		var send = el('button', 'otai-send');
		send.type = 'submit';
		send.setAttribute('aria-label', t.send || 'Send');
		send.innerHTML = ICON_SEND;
		form.appendChild(input);
		form.appendChild(send);
		this.input = input;
		this.sendBtn = send;

		var self = this;
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			self.send(input.value);
		});
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				self.send(input.value);
			}
		});
		input.addEventListener('input', function () {
			input.style.height = 'auto';
			input.style.height = Math.min(input.scrollHeight, 120) + 'px';
		});
		panel.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && self.floating) { self.toggle(false); }
		});

		var foot = el('div', 'otai-foot');
		var notes = [cfg.disclaimer, cfg.consent, t.poweredBy].filter(Boolean);
		foot.textContent = notes.join(' ');

		panel.appendChild(header);
		panel.appendChild(this.log);
		panel.appendChild(this.starters);
		panel.appendChild(form);
		panel.appendChild(foot);
		wrap.appendChild(panel);
		root.appendChild(wrap);
		this.panel = panel;

		this.render();
		if (floating && state.open) { this.toggle(true); }
	}

	Chat.prototype.toggle = function (open) {
		if (!this.floating) { return; }
		this.panel.hidden = !open;
		this.wrap.classList.toggle('otai--open', open);
		this.launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
		state.open = open;
		store.save(state);
		if (open) {
			var input = this.input;
			setTimeout(function () { input.focus(); }, 50);
			this.scroll();
		} else {
			this.launcher.focus();
		}
	};

	Chat.prototype.reset = function () {
		state = { session: uuid(), messages: [], open: state.open };
		store.save(state);
		this.render();
	};

	Chat.prototype.scroll = function () {
		this.log.scrollTop = this.log.scrollHeight;
	};

	Chat.prototype.render = function () {
		var self = this;
		this.log.innerHTML = '';
		if (cfg.welcome) {
			this.bubble({ role: 'assistant', content: cfg.welcome, welcome: true });
		}
		state.messages.forEach(function (m) { self.bubble(m); });
		this.renderStarters();
		this.scroll();
	};

	Chat.prototype.renderStarters = function () {
		var self = this;
		this.starters.innerHTML = '';
		if (state.messages.length || !cfg.starters || !cfg.starters.length) {
			this.starters.hidden = true;
			return;
		}
		this.starters.hidden = false;
		cfg.starters.forEach(function (s) {
			var b = el('button', 'otai-chip', s);
			b.type = 'button';
			b.addEventListener('click', function () { self.send(s); });
			self.starters.appendChild(b);
		});
	};

	Chat.prototype.bubble = function (m) {
		var row = el('div', 'otai-msg otai-msg--' + (m.role === 'user' ? 'user' : 'bot') + (m.error ? ' otai-msg--error' : ''));
		var body = el('div', 'otai-bubble');
		if (m.role === 'user') {
			body.textContent = m.content;
		} else {
			body.innerHTML = md(m.content);
		}
		row.appendChild(body);

		if (m.sources && m.sources.length) {
			var src = el('div', 'otai-sources');
			src.appendChild(el('span', 'otai-sources-label', (t.sources || 'Sources') + ':'));
			m.sources.forEach(function (s) {
				if (!/^https?:\/\//i.test(s.url)) { return; }
				var a = el('a', '', s.title || s.url);
				a.href = s.url;
				a.target = '_blank';
				a.rel = 'noopener noreferrer';
				src.appendChild(a);
			});
			row.appendChild(src);
		}

		if (m.role === 'assistant' && m.id && !m.welcome) {
			var fb = el('div', 'otai-feedback');
			if (m.rated) {
				fb.textContent = t.thanks || 'Thanks!';
			} else {
				[[1, '👍', t.helpful], [-1, '👎', t.notHelpful]].forEach(function (opt) {
					var b = el('button', 'otai-fb', opt[1]);
					b.type = 'button';
					b.setAttribute('aria-label', opt[2] || '');
					b.addEventListener('click', function () { rate(m, opt[0], fb); });
					fb.appendChild(b);
				});
			}
			row.appendChild(fb);
		}
		this.log.appendChild(row);
		return row;
	};

	function api(path, body) {
		var headers = { 'Content-Type': 'application/json' };
		if (cfg.nonce) { headers['X-WP-Nonce'] = cfg.nonce; }
		return fetch(cfg.restUrl + path, {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin',
			body: JSON.stringify(body)
		}).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (data) {
				if (!res.ok) {
					var err = new Error((data && data.message) || t.error);
					err.status = res.status;
					throw err;
				}
				return data;
			});
		});
	}

	function rate(m, rating, container) {
		m.rated = rating;
		store.save(state);
		container.textContent = t.thanks || 'Thanks!';
		api('feedback', { session: state.session, message_id: m.id, rating: rating }).catch(function () {});
	}

	Chat.prototype.send = function (text) {
		text = (text || '').trim();
		if (!text || this.busy) { return; }
		this.busy = true;
		this.input.value = '';
		this.input.style.height = 'auto';
		this.sendBtn.disabled = true;

		var history = state.messages
			.filter(function (m) { return !m.error; })
			.slice(-2 * (cfg.historyTurns || 6))
			.map(function (m) { return { role: m.role, content: m.content }; });

		var userMsg = { role: 'user', content: text };
		state.messages.push(userMsg);
		store.save(state);
		this.bubble(userMsg);
		this.renderStarters();

		var typing = el('div', 'otai-msg otai-msg--bot');
		typing.innerHTML = '<div class="otai-bubble otai-typing"><span></span><span></span><span></span></div>';
		this.log.appendChild(typing);
		this.scroll();

		var self = this;
		api('chat', { message: text, session: state.session, history: history, page: window.location.href })
			.then(function (data) {
				var msg = { role: 'assistant', content: data.reply || t.error, sources: data.sources || [], id: data.message_id || 0 };
				state.messages.push(msg);
				store.save(state);
				if (data.debug && window.console) { window.console.info('[OmniThread AI]', data.source, data.provider, data.debug); }
				typing.remove();
				self.bubble(msg);
			})
			.catch(function (err) {
				typing.remove();
				var msg = { role: 'assistant', content: err.message || t.error, error: true };
				state.messages.push(msg);
				store.save(state);
				self.bubble(msg);
			})
			.then(function () {
				self.busy = false;
				self.sendBtn.disabled = false;
				self.scroll();
				self.input.focus();
			});
	};

	function boot() {
		var inlines = document.querySelectorAll('[data-otai-inline]');
		if (inlines.length) {
			Array.prototype.forEach.call(inlines, function (node) { new Chat(node, false); });
			return;
		}
		if (cfg.floating) {
			new Chat(document.body, true);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
