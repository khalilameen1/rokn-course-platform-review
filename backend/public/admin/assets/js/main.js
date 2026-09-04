jQuery(document).ready(function($) {

	"use strict";

	if (typeof window.SelectFx === 'function') {
		[].slice.call(document.querySelectorAll('select.cs-select')).forEach(function(el) {
			new window.SelectFx(el);
		});
	}

	if ($.fn.selectpicker) {
		$('.selectpicker').selectpicker();
	}


	const body = document.body;
	const darkModeToggle = document.getElementById('darkModeToggle');
	function setDarkMode(enabled) {
		body.classList.toggle('dark-mode', enabled);
		darkModeToggle?.setAttribute('aria-pressed', String(enabled));
	}
	// A denied storage preference must not disable navigation or editing.
	try {
		setDarkMode(localStorage.getItem('darkMode') === 'enabled');
	} catch (_) {
		setDarkMode(body.classList.contains('dark-mode'));
	}
	darkModeToggle?.addEventListener('click', function() {
		const enabled = !body.classList.contains('dark-mode');
		setDarkMode(enabled);
		try { localStorage.setItem('darkMode', enabled ? 'enabled' : 'disabled'); } catch (_) {}
	});

	const headerMenus = [
		['notificationToggle', 'notificationMenu', '.modern-notification'],
		['userMenuToggle', 'userMenu', '.modern-user-menu'],
	].map(function([buttonId, panelId, selector]) {
		return {
			button: document.getElementById(buttonId),
			panel: document.getElementById(panelId),
			container: document.querySelector(selector),
		};
	}).filter(function(menu) { return menu.button && menu.panel && menu.container; });

	function setHeaderMenu(menu, open) {
		menu.container.classList.toggle('show', open);
		menu.button.setAttribute('aria-expanded', String(open));
		menu.panel.setAttribute('aria-hidden', String(!open));
	}
	headerMenus.forEach(function(menu) {
		menu.button.addEventListener('click', function() {
			const opening = !menu.container.classList.contains('show');
			headerMenus.forEach(function(other) { setHeaderMenu(other, other === menu && opening); });
		});
	});
	document.addEventListener('click', function(event) {
		headerMenus.forEach(function(menu) {
			if (!menu.container.contains(event.target)) setHeaderMenu(menu, false);
		});
		const closeButton = event.target.closest('[data-close-alert]');
		closeButton?.closest('.enhanced-alert')?.remove();
	});
	document.addEventListener('keydown', function(event) {
		if (event.key !== 'Escape') return;
		headerMenus.forEach(function(menu) {
			if (!menu.container.classList.contains('show')) return;
			setHeaderMenu(menu, false);
			menu.button.focus();
		});
	});
	document.querySelectorAll('.enhanced-alert-success').forEach(function(alert) {
		window.setTimeout(function() { alert.remove(); }, 4500);
	});

	const sidebar = document.getElementById('left-panel');
	const menuToggle = document.getElementById('menuToggle');
	const sidebarClose = document.getElementById('adminSidebarClose');
	const sidebarOverlay = document.getElementById('adminSidebarOverlay');
	const rightPanel = document.getElementById('right-panel');
	const mobileSidebar = window.matchMedia('(max-width: 768px)');
	let desktopSidebarCollapsed = body.classList.contains('open');
	let sidebarReturnFocus = null;

	function sidebarFocusableElements() {
		if (!sidebar) {
			return [];
		}

		return Array.from(sidebar.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'))
			.filter(function(element) {
				return element.offsetParent !== null;
			});
	}

	function setMobileSidebar(open, restoreFocus) {
		const shouldOpen = Boolean(open && mobileSidebar.matches);
		const wasOpen = body.classList.contains('sidebar-open');

		if (shouldOpen && !wasOpen) {
			sidebarReturnFocus = document.activeElement;
		}

		body.classList.toggle('sidebar-open', shouldOpen);
		body.classList.remove('open');
		sidebar?.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
		sidebar?.toggleAttribute('inert', !shouldOpen);
		if (shouldOpen) {
			sidebar?.setAttribute('role', 'dialog');
			sidebar?.setAttribute('aria-modal', 'true');
		} else {
			sidebar?.removeAttribute('role');
			sidebar?.removeAttribute('aria-modal');
		}
		rightPanel?.toggleAttribute('inert', shouldOpen);
		if (shouldOpen) {
			rightPanel?.setAttribute('aria-hidden', 'true');
		} else {
			rightPanel?.removeAttribute('aria-hidden');
		}
		menuToggle?.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
		menuToggle?.setAttribute('aria-label', shouldOpen ? 'إغلاق القائمة الرئيسية' : 'فتح القائمة الرئيسية');
		sidebarOverlay?.setAttribute('aria-hidden', 'true');

		if (shouldOpen) {
			window.requestAnimationFrame(function() {
				(sidebarClose || sidebarFocusableElements()[0])?.focus();
			});
		} else if (restoreFocus) {
			const focusTarget = sidebarReturnFocus?.isConnected ? sidebarReturnFocus : menuToggle;
			focusTarget?.focus();
			sidebarReturnFocus = null;
		}
	}

	function syncSidebarForViewport() {
		if (mobileSidebar.matches) {
			desktopSidebarCollapsed = body.classList.contains('open') || desktopSidebarCollapsed;
			setMobileSidebar(false, false);
			return;
		}

		body.classList.remove('sidebar-open');
		body.classList.toggle('open', desktopSidebarCollapsed);
		sidebar?.removeAttribute('aria-hidden');
		sidebar?.removeAttribute('inert');
		sidebar?.removeAttribute('role');
		sidebar?.removeAttribute('aria-modal');
		rightPanel?.removeAttribute('aria-hidden');
		rightPanel?.removeAttribute('inert');
		sidebarOverlay?.setAttribute('aria-hidden', 'true');
		menuToggle?.setAttribute('aria-expanded', desktopSidebarCollapsed ? 'false' : 'true');
		menuToggle?.setAttribute('aria-label', desktopSidebarCollapsed ? 'توسيع القائمة الرئيسية' : 'طي القائمة الرئيسية');
	}

	menuToggle?.addEventListener('click', function(event) {
		event.preventDefault();

		if (mobileSidebar.matches) {
			setMobileSidebar(!body.classList.contains('sidebar-open'), true);
			return;
		}

		desktopSidebarCollapsed = !desktopSidebarCollapsed;
		syncSidebarForViewport();
	});

	sidebarClose?.addEventListener('click', function() {
		setMobileSidebar(false, true);
	});

	sidebarOverlay?.addEventListener('click', function() {
		setMobileSidebar(false, true);
	});

	sidebar?.addEventListener('click', function(event) {
		if (mobileSidebar.matches && event.target.closest('a[href]')) {
			setMobileSidebar(false, false);
		}
	});

	document.addEventListener('keydown', function(event) {
		if (!mobileSidebar.matches || !body.classList.contains('sidebar-open')) {
			return;
		}

		if (event.key === 'Escape') {
			event.preventDefault();
			setMobileSidebar(false, true);
			return;
		}

		if (event.key !== 'Tab') {
			return;
		}

		const focusable = sidebarFocusableElements();
		if (focusable.length === 0) {
			event.preventDefault();
			return;
		}

		const first = focusable[0];
		const last = focusable[focusable.length - 1];
		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		} else if (!sidebar.contains(document.activeElement)) {
			event.preventDefault();
			first.focus();
		}
	});

	if (typeof mobileSidebar.addEventListener === 'function') {
		mobileSidebar.addEventListener('change', syncSidebarForViewport);
	} else {
		mobileSidebar.addListener(syncSidebarForViewport);
	}

	sidebar?.querySelector('.nav-item.active > .nav-link')?.setAttribute('aria-current', 'page');
	syncSidebarForViewport();

	$('.search-trigger').on('click', function(event) {
		event.preventDefault();
		event.stopPropagation();
		$('.search-trigger').parent('.header-left').addClass('open');
	});
	if ($.fn.select2) {
		$('.services').select2({ dir: 'rtl' });
	}
	$('.search-close').on('click', function(event) {
		event.preventDefault();
		event.stopPropagation();
		$('.search-trigger').parent('.header-left').removeClass('open');
	});

	/*
	 * Native mutation forms otherwise accept two taps before the first
	 * navigation starts. Keep the submitter enabled so its formaction/value is
	 * still serialized, but block a second submit event and expose one honest
	 * busy state. AJAX forms which preventDefault keep owning their lifecycle.
	 */
	function resetMutationForm(form) {
		delete form.dataset.roknSubmitting;
		form.removeAttribute('aria-busy');
		form.querySelectorAll('[data-rokn-submit-pending]').forEach(function(button) {
			button.removeAttribute('data-rokn-submit-pending');
			button.removeAttribute('aria-disabled');
			button.style.opacity = button.dataset.roknPreviousOpacity || '';
			button.style.cursor = button.dataset.roknPreviousCursor || '';
			button.style.pointerEvents = button.dataset.roknPreviousPointerEvents || '';
			delete button.dataset.roknPreviousOpacity;
			delete button.dataset.roknPreviousCursor;
			delete button.dataset.roknPreviousPointerEvents;
		});
	}
	const enqueueMutationMicrotask = window.queueMicrotask || function(callback) {
		Promise.resolve().then(callback);
	};

	document.addEventListener('submit', function(event) {
		const form = event.target;
		if (!(form instanceof HTMLFormElement)) return;
		if (form.dataset.allowRepeatSubmit === 'true') return;
		if (form.dataset.roknSubmitting === 'true') {
			event.preventDefault();
			event.stopImmediatePropagation();
			return;
		}

		enqueueMutationMicrotask(function() {
			if (event.defaultPrevented || !form.isConnected) return;
			const method = String(form.method || 'get').toLowerCase();
			const target = String(form.target || '').toLowerCase();
			if (method === 'get' || (target && target !== '_self')) return;
			form.dataset.roknSubmitting = 'true';
			form.setAttribute('aria-busy', 'true');
			form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function(button) {
				button.dataset.roknSubmitPending = 'true';
				button.dataset.roknPreviousOpacity = button.style.opacity || '';
				button.dataset.roknPreviousCursor = button.style.cursor || '';
				button.dataset.roknPreviousPointerEvents = button.style.pointerEvents || '';
				button.setAttribute('aria-disabled', 'true');
				button.style.opacity = '0.68';
				button.style.cursor = 'progress';
				button.style.pointerEvents = 'none';
			});
		});
	});

	// Safari/Chrome may restore a submitted page from the back-forward cache.
	// A visual lock from the previous navigation must never strand its forms.
	window.addEventListener('pageshow', function() {
		document.querySelectorAll('form[data-rokn-submitting="true"]').forEach(resetMutationForm);
	});

    $('#has_discount').on('change',function(){
        if($(this). prop("checked") == true){
         $('.discount_detail_div').css('display','block');   
     }else{
        $('.discount_detail_div').css('display','none');
     }    
    });
	// $('.user-area> a').on('click', function(event) {
	// 	event.preventDefault();
	// 	event.stopPropagation();
	// 	$('.user-menu').parent().removeClass('open');
	// 	$('.user-menu').parent().toggleClass('open');
	// });


});
