/**
 * AT8 Site Accelerator —— 后台交互脚本。
 *
 * 无依赖（不依赖 jQuery）：WordPress 后台的 jQuery 在部分精简环境里会被移除，
 * 而本插件的卖点之一就是"精简"，不该反过来依赖被精简掉的东西。
 *
 * 约定：
 * - 所有写操作一律 POST + nonce，不使用可被预取的 GET 链接；
 * - 每个动作在 UI 上都有明确的进行中/成功/失败三态；
 * - 不弹 alert，不阻塞主线程。
 */
(function () {
	'use strict';

	var cfg = window.AT8SA_Admin || {};

	if (!cfg.ajaxUrl) {
		return;
	}

	var i18n = cfg.i18n || {};

	function $(selector, scope) {
		return (scope || document).querySelector(selector);
	}

	function $$(selector, scope) {
		return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
	}

	/* ------------------------------------------------------------------
	 * 提示条
	 * ---------------------------------------------------------------- */

	function notify(message, isError) {
		var box = document.createElement('div');
		box.className = 'notice ' + (isError ? 'notice-error' : 'notice-success') + ' is-dismissible';
		box.setAttribute('style', 'position:fixed;top:46px;right:20px;z-index:100000;max-width:360px;');
		box.innerHTML = '<p></p>';
		box.querySelector('p').textContent = message;

		document.body.appendChild(box);

		window.setTimeout(function () {
			if (box.parentNode) {
				box.parentNode.removeChild(box);
			}
		}, 4000);
	}

	/* ------------------------------------------------------------------
	 * AJAX
	 * ---------------------------------------------------------------- */

	function post(action, payload) {
		var body = new URLSearchParams();

		body.append('action', action);
		body.append('nonce', cfg.nonce);

		Object.keys(payload || {}).forEach(function (key) {
			body.append(key, payload[key]);
		});

		return window.fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (response) {
			return response.json();
		});
	}

	/**
	 * 绑定一个"按钮 → AJAX 动作"。
	 *
	 * @param {string} selector 按钮选择器。
	 * @param {string} action   AJAX action。
	 * @param {Object} options  { confirm, payload, onSuccess }
	 */
	function bindAction(selector, action, options) {
		var opts = options || {};

		$$(selector).forEach(function (button) {
			button.addEventListener('click', function (event) {
				event.preventDefault();

				if (opts.confirm && !window.confirm(opts.confirm)) {
					return;
				}

				var target = opts.messageTarget ? $(opts.messageTarget) : null;
				var original = button.textContent;

				button.disabled = true;
				button.textContent = i18n.working || '…';

				if (target) {
					target.textContent = i18n.working || '…';
					target.className = 'at8sa-inline-msg';
				}

				var payload = typeof opts.payload === 'function' ? opts.payload() : (opts.payload || {});

				post(action, payload)
					.then(function (json) {
						var ok = json && json.success;
						var data = (json && json.data) || {};
						var message = data.message || (ok ? '' : (i18n.failed || 'Failed'));

						if (ok) {
							if (target) {
								target.textContent = message;
								target.className = 'at8sa-inline-msg is-ok';
							} else {
								notify(message, false);
							}

							if (typeof opts.onSuccess === 'function') {
								opts.onSuccess(data, button);
							}
						} else {
							if (target) {
								target.textContent = message;
								target.className = 'at8sa-inline-msg is-error';
							} else {
								notify(message, true);
							}
						}
					})
					.catch(function () {
						var message = i18n.failed || 'Failed';

						if (target) {
							target.textContent = message;
							target.className = 'at8sa-inline-msg is-error';
						} else {
							notify(message, true);
						}
					})
					.then(function () {
						button.disabled = false;
						button.textContent = original;
					});
			});
		});
	}

	/* ------------------------------------------------------------------
	 * 标签切换
	 * ---------------------------------------------------------------- */

	function initTabs() {
		var tabs = $$('.at8sa-tab');

		if (!tabs.length) {
			return;
		}

		function activate(name, pushState) {
			tabs.forEach(function (tab) {
				var selected = tab.getAttribute('data-tab') === name;
				tab.setAttribute('aria-selected', selected ? 'true' : 'false');
				tab.setAttribute('tabindex', selected ? '0' : '-1');
			});

			$$('.at8sa-panel').forEach(function (panel) {
				panel.hidden = panel.getAttribute('data-panel') !== name;
			});

			if (pushState !== false) {
				try {
					window.history.replaceState(null, '', '#' + name);
				} catch (e) {
					// 某些环境禁用 history API，忽略即可。
				}
			}
		}

		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				activate(tab.getAttribute('data-tab'));
			});

			tab.addEventListener('keydown', function (event) {
				var index = tabs.indexOf(tab);

				if (event.key === 'ArrowRight') {
					event.preventDefault();
					tabs[(index + 1) % tabs.length].focus();
				} else if (event.key === 'ArrowLeft') {
					event.preventDefault();
					tabs[(index - 1 + tabs.length) % tabs.length].focus();
				}
			});
		});

		var hash = (window.location.hash || '').replace('#', '');

		if (hash && $('.at8sa-tab[data-tab="' + hash + '"]')) {
			activate(hash, false);
		} else {
			activate(tabs[0].getAttribute('data-tab'), false);
		}
	}

	/* ------------------------------------------------------------------
	 * 复制到剪贴板
	 * ---------------------------------------------------------------- */

	function initCopy() {
		$$('[data-at8sa-copy]').forEach(function (button) {
			button.addEventListener('click', function () {
				var source = $(button.getAttribute('data-at8sa-copy'));

				if (!source) {
					return;
				}

				var text = source.textContent || '';

				function done() {
					var original = button.textContent;
					button.textContent = i18n.copied || 'Copied';

					window.setTimeout(function () {
						button.textContent = original;
					}, 1600);
				}

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(done, function () {
						notify(i18n.failed || 'Failed', true);
					});

					return;
				}

				// 回退：旧浏览器的 execCommand。
				var area = document.createElement('textarea');
				area.value = text;
				area.setAttribute('readonly', 'readonly');
				area.setAttribute('style', 'position:absolute;left:-9999px;');
				document.body.appendChild(area);
				area.select();

				try {
					document.execCommand('copy');
					done();
				} catch (e) {
					notify(i18n.failed || 'Failed', true);
				}

				document.body.removeChild(area);
			});
		});
	}

	/* ------------------------------------------------------------------
	 * 数据库预览渲染
	 * ---------------------------------------------------------------- */

	function renderDbPreview(items, container) {
		if (!container) {
			return;
		}

		container.innerHTML = '';

		var list = document.createElement('ul');
		list.className = 'at8sa-detail-list';

		Object.keys(items).forEach(function (key) {
			var item = items[key];
			var line = document.createElement('li');
			line.textContent = item.label + '：' + Number(item.count).toLocaleString() + ' 条';
			list.appendChild(line);
		});

		container.appendChild(list);
	}

	/* ------------------------------------------------------------------
	 * 初始化
	 * ---------------------------------------------------------------- */

	document.addEventListener('DOMContentLoaded', function () {
		initTabs();
		initCopy();

		bindAction('#at8sa-purge-all', 'at8sa_purge_all', {
			confirm: i18n.confirmAll,
			messageTarget: '#at8sa-purge-all-msg',
			onSuccess: function () {
				window.setTimeout(function () {
					window.location.reload();
				}, 900);
			}
		});

		bindAction('#at8sa-purge-url', 'at8sa_purge_url', {
			messageTarget: '#at8sa-purge-url-msg',
			payload: function () {
				var input = $('#at8sa-purge-url-input');
				return { url: input ? input.value : '' };
			}
		});

		bindAction('#at8sa-db-preview', 'at8sa_db_preview', {
			messageTarget: '#at8sa-db-msg',
			onSuccess: function (data) {
				renderDbPreview(data.items || {}, $('#at8sa-db-preview-result'));
			}
		});

		bindAction('#at8sa-db-run', 'at8sa_db_run', {
			confirm: i18n.confirmDb,
			messageTarget: '#at8sa-db-msg',
			/* 设置页的勾选框属于底部「保存设置」表单，但用户习惯是勾选后直接执行。
			 * 把数据库面板当前的勾选状态随请求带走，服务端先持久化再清理——
			 * 否则服务端读到的还是上次保存的值，会误报"没有任何清理项被勾选"。 */
			payload: function () {
				var panel = document.querySelector('.at8sa-panel[data-panel="database"]');
				var items = {};

				if (!panel) {
					return { items: '' };
				}

				panel.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
					var m = (box.name || '').match(/\[([^\]]+)\]/);

					if (m) {
						items[m[1]] = box.checked ? 1 : 0;
					}
				});

				panel.querySelectorAll('select').forEach(function (sel) {
					var m = (sel.name || '').match(/\[([^\]]+)\]/);

					if (m) {
						items[m[1]] = sel.value;
					}
				});

				return { items: JSON.stringify(items) };
			},
			onSuccess: function (data) {
				var container = $('#at8sa-db-preview-result');

				if (!container || !data.detail) {
					return;
				}

				container.innerHTML = '';

				var list = document.createElement('ul');
				list.className = 'at8sa-detail-list';

				data.detail.forEach(function (line) {
					var item = document.createElement('li');
					item.textContent = line;
					list.appendChild(item);
				});

				container.appendChild(list);
			}
		});

		bindAction('#at8sa-install-dropin', 'at8sa_install_dropin', {
			messageTarget: '#at8sa-dropin-msg',
			onSuccess: function () {
				window.setTimeout(function () {
					window.location.reload();
				}, 900);
			}
		});

		bindAction('#at8sa-remove-dropin', 'at8sa_remove_dropin', {
			messageTarget: '#at8sa-dropin-msg',
			onSuccess: function () {
				window.setTimeout(function () {
					window.location.reload();
				}, 900);
			}
		});

		bindAction('#at8sa-enable-wp-cache', 'at8sa_enable_wp_cache', {
			messageTarget: '#at8sa-dropin-msg'
		});

		bindAction('#at8sa-disable-wp-cache', 'at8sa_disable_wp_cache', {
			messageTarget: '#at8sa-dropin-msg'
		});

		bindAction('#at8sa-write-htaccess', 'at8sa_write_htaccess', {
			messageTarget: '#at8sa-htaccess-msg'
		});

		bindAction('#at8sa-remove-htaccess', 'at8sa_remove_htaccess', {
			messageTarget: '#at8sa-htaccess-msg'
		});

		bindAction('#at8sa-clear-log', 'at8sa_clear_log', {
			messageTarget: '#at8sa-log-msg',
			onSuccess: function () {
				var box = $('#at8sa-log-output');

				if (box) {
					box.textContent = '';
				}
			}
		});

		bindAction('#at8sa-rescan-conflicts', 'at8sa_rescan_conflicts', {
			messageTarget: '#at8sa-conflict-msg',
			onSuccess: function () {
				window.setTimeout(function () {
					window.location.reload();
				}, 900);
			}
		});

		bindAction('#at8sa-export', 'at8sa_export_settings', {
			messageTarget: '#at8sa-io-msg',
			onSuccess: function (data) {
				var output = $('#at8sa-export-output');

				if (!output) {
					return;
				}

				var text = JSON.stringify(data, null, 2);
				output.textContent = text;

				var blob = new Blob([text], { type: 'application/json' });
				var link = document.createElement('a');

				link.href = URL.createObjectURL(blob);
				link.download = 'at8-site-accelerator-settings.json';
				document.body.appendChild(link);
				link.click();
				document.body.removeChild(link);
				URL.revokeObjectURL(link.href);
			}
		});

		bindAction('#at8sa-import', 'at8sa_import_settings', {
			messageTarget: '#at8sa-io-msg',
			payload: function () {
				var input = $('#at8sa-import-input');
				return { payload: input ? input.value : '' };
			},
			onSuccess: function () {
				window.setTimeout(function () {
					window.location.reload();
				}, 900);
			}
		});
	});
})();
