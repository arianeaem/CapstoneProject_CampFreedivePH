/**
 * Camp FreedivePH - page switcher for the staff portal.
 * Loads the next page with fetch and swaps only the main content,
 * so the sidebar and header stay and the page doesn't fully reload.
 */

class SPARouter {
    constructor() {
        this.progressBar = null;
        this.progressTimer = null;
        this.isLoading = false;
        this.currentUrl = window.location.href;

        this.init();
    }

    init() {
        if (typeof window === 'undefined') return;

        this.createProgressBar();
        this.bindEvents();
        this.updateSidebarActiveLinks(window.location.href);
    }

    createProgressBar() {
        if (document.getElementById('spa-progress-bar')) {
            this.progressBar = document.getElementById('spa-progress-bar');
            return;
        }

        const bar = document.createElement('div');
        bar.id = 'spa-progress-bar';
        bar.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 0%;
            height: 3px;
            background: #780000;
            z-index: 99999;
            pointer-events: none;
            transition: width 0.25s ease-out, opacity 0.3s ease-in-out;
            box-shadow: none;
            opacity: 0;
        `;
        document.body.appendChild(bar);
        this.progressBar = bar;
    }

    startProgress() {
        if (!this.progressBar) this.createProgressBar();
        clearTimeout(this.progressTimer);
        
        this.progressBar.style.opacity = '1';
        this.progressBar.style.width = '20%';

        this.progressTimer = setTimeout(() => {
            if (this.isLoading) {
                this.progressBar.style.width = '65%';
            }
        }, 150);
    }

    finishProgress() {
        clearTimeout(this.progressTimer);
        if (!this.progressBar) return;

        this.progressBar.style.width = '100%';
        setTimeout(() => {
            this.progressBar.style.opacity = '0';
            setTimeout(() => {
                if (!this.isLoading) {
                    this.progressBar.style.width = '0%';
                }
            }, 300);
        }, 200);
    }

    bindEvents() {
        // Catch link clicks
        document.addEventListener('click', (e) => {
            if (e.defaultPrevented) return;
            const link = e.target.closest('a');
            if (!link) return;

            if (this.shouldInterceptLink(link, e)) {
                e.preventDefault();
                this.navigate(link.href);
            }
        });

        // Catch GET and POST forms
        document.addEventListener('submit', (e) => {
            if (e.defaultPrevented) return;
            const form = e.target;
            if (!form || !this.shouldInterceptForm(form)) return;

            e.preventDefault();
            this.handleFormSubmit(form);
        });

        // Browser back / forward
        window.addEventListener('popstate', (e) => {
            this.navigate(window.location.href, false);
        });
    }

    hasSpaContainer(doc = document) {
        return !!doc.getElementById('spa-page-content');
    }

    isAuthUrl(url) {
        if (!url) return false;
        try {
            const urlObj = new URL(url, window.location.origin);
            const path = urlObj.pathname.toLowerCase();
            const authPrefixes = [
                '/login',
                '/admin/login',
                '/staff/login',
                '/staff',
                '/logout',
                '/forgot-password',
                '/reset-password',
                '/force-password-change'
            ];
            return authPrefixes.some(prefix => path === prefix || path.startsWith(prefix + '/') || path.startsWith(prefix + '?'));
        } catch {
            return false;
        }
    }

    shouldInterceptLink(link, event) {
        // Skip ctrl/shift clicks and middle clicks
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) {
            return false;
        }

        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) {
            return false;
        }

        // Only if the page uses the SPA layout
        if (!this.hasSpaContainer(document)) {
            return false;
        }

        // Skip download links and new tab links
        if (link.hasAttribute('download') || link.getAttribute('target') === '_blank') {
            return false;
        }

        // Skip links marked as no-spa
        if (link.hasAttribute('data-native') || link.hasAttribute('data-no-spa')) {
            return false;
        }

        // Skip login and logout links
        if (this.isAuthUrl(link.href) || href.includes('/logout')) {
            return false;
        }

        try {
            const targetUrl = new URL(link.href, window.location.origin);
            
            // Only same-site links
            if (targetUrl.origin !== window.location.origin) {
                return false;
            }

            // Same page with only a #hash, let the browser scroll
            if (targetUrl.pathname === window.location.pathname && targetUrl.search === window.location.search && targetUrl.hash) {
                return false;
            }

            return true;
        } catch {
            return false;
        }
    }

    shouldInterceptForm(form) {
        // Only if the page uses the SPA layout
        if (!this.hasSpaContainer(document)) {
            return false;
        }

        if (form.hasAttribute('data-native') || form.hasAttribute('data-no-spa')) {
            return false;
        }

        const action = form.getAttribute('action') || window.location.href;
        if (this.isAuthUrl(action) || action.includes('/logout')) {
            return false;
        }

        try {
            const targetUrl = new URL(action, window.location.origin);
            return targetUrl.origin === window.location.origin;
        } catch {
            return false;
        }
    }

    async handleFormSubmit(form) {
        const method = (form.getAttribute('method') || 'GET').toUpperCase();
        const action = form.getAttribute('action') || window.location.href;

        if (method === 'GET') {
            const formData = new FormData(form);
            const params = new URLSearchParams();
            for (const [key, value] of formData.entries()) {
                if (value !== '') {
                    params.append(key, value);
                }
            }
            const queryString = params.toString();
            const targetUrl = action.split('?')[0] + (queryString ? '?' + queryString : '');
            return this.navigate(targetUrl);
        }

        // POST / PUT / PATCH / DELETE forms
        this.isLoading = true;
        this.startProgress();

        try {
            const formData = new FormData(form);
            const response = await fetch(action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-SPA-Request': '1',
                    'Accept': 'text/html, application/xhtml+xml, application/xml'
                }
            });

            if (!response.ok && response.status === 401) {
                window.location.href = response.url || '/login';
                return;
            }

            const finalUrl = response.url || action;
            const htmlText = await response.text();

            if (!response.ok) {
                // Validation error (422) or server error (500), show the returned page
                this.renderContent(htmlText, finalUrl, false);
                this.currentUrl = finalUrl;
                return;
            }

            this.renderContent(htmlText, finalUrl, true);
            this.currentUrl = finalUrl;
        } catch (err) {
            console.error('[SPARouter] Form submission error, falling back to native:', err);
            form.submit();
        } finally {
            this.isLoading = false;
            this.finishProgress();
        }
    }

    async navigate(url, pushState = true) {
        if (this.isLoading && this.currentUrl === url) return;

        this.isLoading = true;
        this.startProgress();

        try {
            const response = await fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-SPA-Request': '1',
                    'Accept': 'text/html, application/xhtml+xml, application/xml'
                }
            });

            if (!response.ok && response.status === 401) {
                // Session expired, go to login
                window.location.href = response.url || '/login';
                return;
            }

            const finalUrl = response.url || url;
            const htmlText = await response.text();

            this.renderContent(htmlText, finalUrl, pushState);
            this.currentUrl = finalUrl;
        } catch (err) {
            console.error('[SPARouter] Navigation error, falling back to native reload:', err);
            window.location.href = url;
        } finally {
            this.isLoading = false;
            this.finishProgress();
        }
    }

    renderContent(htmlText, finalUrl, pushState = true) {
        // Login/logout/reset pages need a full reload
        if (this.isAuthUrl(finalUrl)) {
            window.location.href = finalUrl;
            return;
        }

        const parser = new DOMParser();
        const newDoc = parser.parseFromString(htmlText, 'text/html');

        // Check if both pages use the SPA container (#spa-page-content)
        const isCurrentAdmin = !!document.getElementById('spa-page-content');
        const isNewAdmin = !!newDoc.getElementById('spa-page-content');

        if (!isCurrentAdmin || !isNewAdmin) {
            // Not an SPA page, do a normal page load
            window.location.href = finalUrl;
            return;
        }

        // 1. Page title
        if (newDoc.title) {
            document.title = newDoc.title;
        }

        // 2. Main content
        const targetContainer = document.getElementById('spa-page-content');
        const sourceContainer = newDoc.getElementById('spa-page-content');

        if (!targetContainer || !sourceContainer) {
            window.location.href = finalUrl;
            return;
        }

        // Remove the old Alpine components first
        if (window.Alpine && typeof window.Alpine.destroyTree === 'function') {
            window.Alpine.destroyTree(targetContainer);
        }

        // Put in the new HTML
        targetContainer.innerHTML = sourceContainer.innerHTML;

        // Run the page's @stack('scripts') first so the components they define
        // (e.g. x-data="coachAvailabilityCalendar(...)") exist before Alpine starts
        const targetScripts = document.getElementById('spa-page-scripts');
        const sourceScripts = newDoc.getElementById('spa-page-scripts');
        if (targetScripts) {
            targetScripts.innerHTML = sourceScripts ? sourceScripts.innerHTML : '';
            this.executeScripts(targetScripts);
        }

        // Run scripts inside the content
        this.executeScripts(targetContainer);

        // Start Alpine on the new content
        if (window.Alpine && typeof window.Alpine.initTree === 'function') {
            window.Alpine.initTree(targetContainer);
        }

        // 3. Breadcrumbs
        const targetBreadcrumb = document.getElementById('header-breadcrumbs');
        const sourceBreadcrumb = newDoc.getElementById('header-breadcrumbs');
        if (targetBreadcrumb && sourceBreadcrumb) {
            targetBreadcrumb.innerHTML = sourceBreadcrumb.innerHTML;
        }

        // 4. Flash messages
        const targetFlash = document.getElementById('flash-messages-container');
        const sourceFlash = newDoc.getElementById('flash-messages-container');
        if (targetFlash && sourceFlash) {
            targetFlash.innerHTML = sourceFlash.innerHTML;
        }

        // 5. Active link in the sidebar and mobile menu
        this.updateSidebarActiveLinks(finalUrl);

        // 6. Close the mobile menu
        const mobileDrawer = document.querySelector('[x-data]');
        if (mobileDrawer && window.Alpine) {
            try {
                // Close it if mobileMenuOpen exists
                window.dispatchEvent(new CustomEvent('close-mobile-menu'));
            } catch {}
        }

        // 7. Browser history
        if (pushState && window.location.href !== finalUrl) {
            window.history.pushState({ spa: true, url: finalUrl }, '', finalUrl);
        }

        // 8. Scroll to top
        window.scrollTo({ top: 0, behavior: 'instant' });

        // 9. Tell other scripts the page changed
        window.dispatchEvent(new CustomEvent('spa:navigated', { detail: { url: finalUrl } }));
    }

    executeScripts(container) {
        const scripts = container.querySelectorAll('script');
        scripts.forEach(oldScript => {
            const newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(attr => {
                newScript.setAttribute(attr.name, attr.value);
            });
            newScript.textContent = oldScript.textContent;
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }

    updateSidebarActiveLinks(url) {
        try {
            const currentUrlObj = new URL(url, window.location.origin);
            const currentPath = currentUrlObj.pathname;

            const allNavLinks = document.querySelectorAll('#desktop-sidebar nav a, #mobile-sidebar nav a');
            
            allNavLinks.forEach(link => {
                const linkHref = link.getAttribute('href');
                if (!linkHref) return;

                const linkUrlObj = new URL(linkHref, window.location.origin);
                const linkPath = linkUrlObj.pathname;

                // Same URL or parent URL (e.g. /owner/pricing/create matches /owner/pricing)
                const isExact = currentPath === linkPath;
                const isSubPath = linkPath !== '/' && linkPath !== '/admin' && linkPath !== '/owner' && linkPath !== '/coach' && currentPath.startsWith(linkPath);
                const isActive = isExact || isSubPath;

                const activeClasses = ['bg-[#780000]/10', 'text-[#780000]', 'font-bold', 'shadow-2xs'];
                const inactiveClasses = ['text-[#3A3A3C]'];

                if (isActive) {
                    link.classList.add(...activeClasses);
                    link.classList.remove(...inactiveClasses);

                    // Also color the SVG icon
                    const svg = link.querySelector('svg');
                    if (svg) {
                        svg.classList.add('text-[#780000]');
                        svg.classList.remove('text-[#3A3A3C]');
                    }
                } else {
                    link.classList.remove(...activeClasses);
                    link.classList.add(...inactiveClasses);

                    const svg = link.querySelector('svg');
                    if (svg) {
                        svg.classList.remove('text-[#780000]');
                        svg.classList.add('text-[#3A3A3C]');
                    }
                }
            });
        } catch (e) {
            // Ignore bad URLs
        }
    }
}

// Start when the page is ready
if (typeof window !== 'undefined') {
    window.SPARouter = new SPARouter();
}

export default SPARouter;
