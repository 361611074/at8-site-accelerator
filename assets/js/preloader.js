/**
 * AT8 Site Accelerator —— 前端链接预取器。
 *
 * 职责边界（很重要）：本脚本做的是**浏览器侧链接预取**，让访客点下去之后更快。
 * 它不会让服务端产生整页缓存——那是"缓存预热"（Pro 功能）干的事。
 *
 * 相比 2.x 版本的修复与改进：
 * 1. 【修复】原实现用一个全局 hoverTimer，快速划过多个链接时会预取错的那一个。
 *    现在每个链接各自计时。
 * 2. 【新增】尊重 Save-Data 与慢速网络（2G/slow-2g），省流优先。
 * 3. 【新增】页面隐藏 / 卸载时停止观察，避免在后台标签页继续消耗带宽。
 * 4. 【收紧】不再默认使用 `prerender`（它会完整渲染目标页，开销与流量都很大）。
 */
(function () {
	'use strict';

	var config = window.AT8SA_Preloader;

	if (!config) {
		return;
	}

	var security = config.security || {};
	var speed = config.speed || {};

	var protocols = security.dangerousProtocols || ['javascript:', 'data:', 'vbscript:', 'file:'];
	var sensitivePaths = security.sensitivePaths || ['/logout', '/wp-login', '/cart', '/checkout'];
	var dangerousExtensions = security.dangerousExtensions || ['.php', '.exe', '.sh'];
	var maxPreloads = Number(security.maxPreloads) || 20;
	var maxPerDomain = Number(security.maxPerDomain) || 10;

	var preloaded = Object.create(null);
	var domainCount = Object.create(null);
	var totalPreloads = 0;
	var timers = Object.create(null);

	function log(message, type) {
		if (config.debug && window.console) {
			(window.console[type] || window.console.log)('[AT8 预加载]', message);
		}
	}

	/**
	 * 当前网络是否适合预取。
	 */
	function networkAllows() {
		if (navigator.connection) {
			if (navigator.connection.saveData) {
				return false;
			}

			var type = navigator.connection.effectiveType || '';

			if (type === 'slow-2g' || type === '2g') {
				return false;
			}
		}

		return true;
	}

	/**
	 * URL 安全校验：仅本站、非危险协议、非敏感路径、非危险扩展名。
	 *
	 * @param {string} url 目标 URL。
	 * @returns {boolean}
	 */
	function isSafeUrl(url) {
		try {
			var parsed = new URL(url, window.location.href);
			var lower = parsed.href.toLowerCase();
			var path = parsed.pathname.toLowerCase();
			var i;

			for (i = 0; i < protocols.length; i++) {
				if (lower.indexOf(protocols[i]) === 0) {
					return false;
				}
			}

			for (i = 0; i < sensitivePaths.length; i++) {
				if (lower.indexOf(String(sensitivePaths[i]).toLowerCase()) !== -1) {
					return false;
				}
			}

			for (i = 0; i < dangerousExtensions.length; i++) {
				var ext = String(dangerousExtensions[i]).toLowerCase();

				if (path.length >= ext.length && path.indexOf(ext) === path.length - ext.length) {
					return false;
				}
			}

			// 只预取同源资源。
			if (parsed.hostname !== window.location.hostname) {
				return false;
			}

			// 不预取 IP 直连。
			if (/^\d+\.\d+\.\d+\.\d+$/.test(parsed.hostname)) {
				return false;
			}

			// 不重复预取当前页。
			if (parsed.href.split('#')[0] === window.location.href.split('#')[0]) {
				return false;
			}

			return true;
		} catch (e) {
			return false;
		}
	}

	/**
	 * 配额与冷却校验。
	 */
	function canPreload(url) {
		try {
			var parsed = new URL(url, window.location.href);
			var domain = parsed.hostname;

			if (totalPreloads >= maxPreloads) {
				return false;
			}

			if ((domainCount[domain] || 0) >= maxPerDomain) {
				return false;
			}

			var last = preloaded[url];

			if (last && (Date.now() - last) / 1000 < (Number(speed.cacheTime) || 300)) {
				return false;
			}

			return true;
		} catch (e) {
			return false;
		}
	}

	/**
	 * 执行预取。
	 */
	function preload(url) {
		if (!networkAllows() || !isSafeUrl(url) || !canPreload(url)) {
			return;
		}

		try {
			var parsed = new URL(url, window.location.href);
			var strategies = speed.preloadStrategy || ['prefetch'];

			preloaded[url] = Date.now();
			domainCount[parsed.hostname] = (domainCount[parsed.hostname] || 0) + 1;
			totalPreloads++;

			for (var i = 0; i < strategies.length; i++) {
				var link = document.createElement('link');

				switch (strategies[i]) {
					case 'prefetch':
						link.rel = 'prefetch';
						link.href = url;
						break;

					case 'preconnect':
						link.rel = 'preconnect';
						link.href = parsed.origin;
						break;

					case 'dns-prefetch':
						link.rel = 'dns-prefetch';
						link.href = parsed.origin;
						break;

					case 'prerender':
						link.rel = 'prerender';
						link.href = url;
						break;

					default:
						continue;
				}

				document.head.appendChild(link);
			}

			log('已预取 ' + url);
		} catch (e) {
			log('预取失败：' + e.message, 'error');
		}
	}

	function idlePreload(url) {
		if (speed.useIdleCallback && 'requestIdleCallback' in window) {
			window.requestIdleCallback(function () {
				preload(url);
			}, { timeout: 2000 });

			return;
		}

		window.setTimeout(function () {
			preload(url);
		}, 200);
	}

	/**
	 * 取链接元素。
	 */
	function closestLink(node) {
		if (!node) {
			return null;
		}

		if (node.closest) {
			return node.closest('a[href]');
		}

		// 极老浏览器回退。
		var current = node;

		while (current && current !== document) {
			if (current.tagName === 'A' && current.getAttribute('href')) {
				return current;
			}

			current = current.parentNode;
		}

		return null;
	}

	function scheduleHover(link) {
		var href = link.href;

		if (!href) {
			return;
		}

		// 每个链接独立计时，修复 2.x 的"划过多个链接预取错目标"问题。
		window.clearTimeout(timers[href]);
		timers[href] = window.setTimeout(function () {
			preload(href);
			delete timers[href];
		}, Number(speed.hoverDelay) || 50);
	}

	function cancelHover(link) {
		if (link && timers[link.href]) {
			window.clearTimeout(timers[link.href]);
			delete timers[link.href];
		}
	}

	/* --------------------------------------------------------------
	 * 事件绑定
	 * ------------------------------------------------------------ */

	if (!networkAllows()) {
		log('网络条件不适合预取，已跳过初始化');
		return;
	}

	document.addEventListener('mouseover', function (event) {
		var link = closestLink(event.target);

		if (link) {
			scheduleHover(link);
		}
	}, { passive: true });

	document.addEventListener('mouseout', function (event) {
		cancelHover(closestLink(event.target));
	}, { passive: true });

	document.addEventListener('touchstart', function (event) {
		var link = closestLink(event.target);

		if (!link) {
			return;
		}

		window.setTimeout(function () {
			preload(link.href);
		}, Number(speed.touchDelay) || 100);
	}, { passive: true });

	var observer = null;

	if (speed.preloadInViewport && 'IntersectionObserver' in window) {
		observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					var link = entry.target;

					if (link.href) {
						idlePreload(link.href);
					}

					observer.unobserve(link);
				}
			});
		}, { rootMargin: '120px' });

		Array.prototype.slice.call(document.querySelectorAll('a[href]')).forEach(function (link) {
			observer.observe(link);
		});
	}

	// 页面隐藏时停止观察，避免后台标签页继续消耗带宽。
	document.addEventListener('visibilitychange', function () {
		if (!observer) {
			return;
		}

		if (document.hidden) {
			observer.disconnect();
			log('页面隐藏，已停止视口观察');
		} else {
			Array.prototype.slice.call(document.querySelectorAll('a[href]')).forEach(function (link) {
				observer.observe(link);
			});
		}
	});

	log('预取器已启动');
})();
