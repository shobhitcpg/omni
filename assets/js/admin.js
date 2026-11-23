/* global jQuery, OTAI_ADMIN */
(function ($) {
	'use strict';
	var A = window.OTAI_ADMIN || {};
	var t = A.i18n || {};

	function post(action, data) {
		return $.post(A.ajaxUrl, $.extend({ action: action, _ajax_nonce: A.nonce }, data || {}));
	}

	function esc(s) {
		return $('<div>').text(s == null ? '' : String(s)).html();
	}

	$(function () {
		// Range <-> number sync.
		$(document).on('input', '.otai-slider input[type=range]', function () {
			$('#' + $(this).data('sync')).val(this.value);
		});
		$(document).on('input', '.otai-slider input[type=number]', function () {
			$(this).siblings('input[type=range]').val(this.value);
		});

		// Card selection styling.
		$(document).on('change', '.otai-vertical input, .otai-mode input', function () {
			var group = $(this).closest('.otai-verticals, .otai-modes');
			group.find('label').removeClass('is-selected');
			$(this).closest('label').addClass('is-selected');
		});

		// Colour picker.
		if ($.fn.wpColorPicker) {
			$('.otai-color').wpColorPicker();
		}

		// Display mode → show only the relevant targeting fields.
		$('#otai-display').on('change', function () {
			$('.otai-when-selected').prop('hidden', this.value !== 'selected');
			$('.otai-when-bubble').prop('hidden', this.value === 'shortcode_only');
		});

		// Provider order.
		var $order = $('#otai-order');
		if ($order.length && $.fn.sortable) {
			$order.sortable({
				axis: 'y',
				update: function () {
					var ids = $order.children('li').map(function () { return $(this).data('id'); }).get();
					$('#otai-provider-order').val(ids.join(','));
				}
			});
		}

		// Provider toggle styling.
		$(document).on('change', '.otai-provider .otai-toggle input[type=checkbox]', function () {
			$(this).closest('.otai-provider').toggleClass('is-enabled', this.checked);
		});

		// Provider test.
		$(document).on('click', '.otai-test', function () {
			var $btn = $(this);
			var $out = $btn.siblings('.otai-test-result').removeClass('is-error').text(t.testing || 'Testing…');
			$btn.prop('disabled', true);
			post('otai_test_provider', { provider: $btn.data('provider') })
				.done(function (res) {
					if (res && res.success) {
						$out.text(res.data.message);
					} else {
						$out.addClass('is-error').text('❌ ' + ((res && res.data && res.data.message) || 'Error'));
					}
				})
				.fail(function (xhr) {
					$out.addClass('is-error').text('❌ HTTP ' + xhr.status);
				})
				.always(function () { $btn.prop('disabled', false); });
		});

		// Search engine: show only the credentials for the chosen auth method.
		var $auth = $('#otai-es_auth');
		function toggleAuth() {
			var v = $auth.val();
			$('#otai-es_username, #otai-es_password').closest('tr').prop('hidden', v !== 'basic');
			$('#otai-es_api_key').closest('tr').prop('hidden', v !== 'api_key');
		}
		if ($auth.length) {
			$auth.on('change', toggleAuth);
			toggleAuth();
		}

		$('#otai-test-search').on('click', function () {
			var $btn = $(this).prop('disabled', true);
			var $out = $('#otai-test-search-result').removeClass('is-error').text(t.testing || 'Testing…');
			post('otai_test_search_engine', {})
				.done(function (res) {
					var ok = res && res.success;
					$out.toggleClass('is-error', !ok).text((res && res.data && res.data.message) || 'Error');
				})
				.fail(function (xhr) { $out.addClass('is-error').text('❌ HTTP ' + xhr.status); })
				.always(function () { $btn.prop('disabled', false); });
		});

		$('.otai-rag-action').on('click', function () {
			var $btn = $(this).prop('disabled', true);
			var $out = $('#otai-rag-result').text(t.testing || 'Testing...');
			post('otai_rag_action', { operation: $btn.data('operation') })
				.done(function (res) {
					$out.text((res && res.data && res.data.message) || 'Error');
				})
				.fail(function (xhr) { $out.text('HTTP ' + xhr.status); })
				.always(function () { $btn.prop('disabled', false); });
		});

		// Reindex with progress.
		$('#otai-reindex').on('click', function () {
			var $btn = $(this).prop('disabled', true);
			var $bar = $('.otai-progress').prop('hidden', false).find('.otai-progress-bar').css('width', '0');
			var $status = $('#otai-reindex-status').text(t.indexing || 'Indexing…');
			(function step(offset) {
				post('otai_reindex', { offset: offset })
					.done(function (res) {
						if (!res || !res.success) {
							$status.text((res && res.data && res.data.message) || 'Error');
							$btn.prop('disabled', false);
							return;
						}
						var d = res.data;
						var pct = d.total ? Math.round((100 * d.processed) / d.total) : 100;
						$bar.css('width', pct + '%');
						$status.text(d.processed + ' / ' + d.total);
						if (d.done) {
							$status.text((t.indexed || 'Done') + ' (' + d.total + ')');
							setTimeout(function () { window.location.reload(); }, 800);
						} else {
							step(d.processed);
						}
					})
					.fail(function (xhr) {
						$status.text((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || ('HTTP ' + xhr.status));
						$btn.prop('disabled', false);
					});
			})(0);
		});

		// Retrieval test.
		$('#otai-search-test').on('submit', function (e) {
			e.preventDefault();
			var q = $(this).find('input').val();
			var $out = $('#otai-search-results').text('…');
			post('otai_search_test', { query: q }).done(function (res) {
				if (!res || !res.success) { $out.text((res && res.data && res.data.message) || 'Error'); return; }
				var d = res.data;
				var html = d.engine ? '<p class="description">Search engine: <strong>' + esc(d.engine) + '</strong></p>' : '';
				if (d.faq) {
					var ok = d.faq.score >= d.faq_threshold;
					html += '<div class="otai-result ' + (ok ? 'is-hit' : '') + '"><strong>FAQ</strong> · score ' + esc(d.faq.score) + (ok ? ' ✅' : ' (threshold ' + esc(d.faq_threshold) + ')') +
						'<br><em>' + esc(d.faq.question) + '</em><br>' + esc(d.faq.answer) + '</div>';
				}
				if (!d.chunks.length && !d.faq) {
					html += '<p>' + esc(t.noResults || 'No results') + '</p>';
				}
				d.chunks.forEach(function (c) {
					var hit = c.score >= d.local_threshold && c.type !== 'faq';
					html += '<div class="otai-result ' + (hit ? 'is-hit' : '') + '"><strong>' + esc(c.title) + '</strong> <code>' + esc(c.type) + '</code> · score ' + esc(c.score) + (hit ? ' ✅' : '') +
						(c.url ? ' · <a target="_blank" rel="noopener" href="' + esc(c.url) + '">link</a>' : '') + '<br><small>' + esc(c.excerpt) + '</small></div>';
				});
				$out.html(html);
			});
		});

		// Playground (uses the real REST endpoint as the logged-in admin).
		var session = 'admin-' + Math.random().toString(36).slice(2, 14);
		var history = [];
		$('#otai-playground').on('submit', function (e) {
			e.preventDefault();
			var $input = $(this).find('input');
			var q = $.trim($input.val());
			if (!q) { return; }
			$input.val('');
			var $log = $('#otai-playground-log');
			$log.append('<div class="otai-t otai-t--user"><div class="otai-t-body">' + esc(q) + '</div></div>');
			var $pending = $('<div class="otai-t otai-t--bot"><div class="otai-t-body">' + esc(t.thinking || '…') + '</div></div>').appendTo($log);
			$log.scrollTop($log[0].scrollHeight);
			$.ajax({
				url: A.restUrl + 'chat',
				method: 'POST',
				contentType: 'application/json',
				headers: { 'X-WP-Nonce': A.restNonce },
				data: JSON.stringify({ message: q, session: session, history: history.slice(-12) })
			}).done(function (d) {
				history.push({ role: 'user', content: q }, { role: 'assistant', content: d.reply });
				var meta = 'source: ' + d.source + (d.provider ? ' · provider: ' + d.provider : '') + (d.debug && d.debug.model ? ' · model: ' + d.debug.model : '');
				if (d.debug && d.debug.attempts && d.debug.attempts.length) {
					meta += ' · failed: ' + d.debug.attempts.map(function (a) { return a.provider + ' (' + a.error + ')'; }).join('; ');
				}
				var sources = (d.sources || []).map(function (s) { return '<a target="_blank" rel="noopener" href="' + esc(s.url) + '">' + esc(s.title) + '</a>'; }).join(' · ');
				$pending.html('<div class="otai-t-body">' + esc(d.reply).replace(/\n/g, '<br>') + '</div><div class="otai-t-meta">' + esc(meta) + (sources ? '<br>' + sources : '') + '</div>');
			}).fail(function (xhr) {
				var msg = (xhr.responseJSON && xhr.responseJSON.message) || ('HTTP ' + xhr.status);
				$pending.html('<div class="otai-t-body is-error">' + esc(msg) + '</div>');
			}).always(function () {
				$log.scrollTop($log[0].scrollHeight);
			});
		});

		$('#otai-purge-form').on('submit', function () {
			return window.confirm(t.confirm || 'Are you sure?');
		});
	});
})(jQuery);
