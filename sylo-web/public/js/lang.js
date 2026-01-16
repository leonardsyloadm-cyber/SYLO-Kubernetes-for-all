/**
 * Sylo Language Manager
 * Handles asynchronous loading of language files and DOM updates.
 */
class LanguageManager {
    constructor() {
        this.currentLang = localStorage.getItem('sylo_lang') || 'es';
        this.translations = {};
        this.availableLangs = ['es', 'en', 'fr', 'de'];

        // Auto-init
        document.addEventListener('DOMContentLoaded', () => {
            this.init();
        });
    }

    async init() {
        // Inject CSS for flag/lang selector if not exists
        this.injectStyles();

        // Load stored language
        await this.loadLanguage(this.currentLang);

        // Bind UI triggers
        this.bindTriggers();

        // Observe DOM for changes
        this.observeDOM();
    }

    observeDOM() {
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.type === 'childList') {
                    mutation.addedNodes.forEach((node) => {
                        if (node.nodeType === 1) { // ELEMENT_NODE
                            if (node.hasAttribute('data-i18n')) {
                                this.translateElement(node);
                            }
                            // Check children
                            node.querySelectorAll('[data-i18n]').forEach(child => this.translateElement(child));
                        }
                    });
                }
            });
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    translateElement(el) {
        const key = el.getAttribute('data-i18n');
        const val = this.getNested(this.translations, key);
        if (val) {
            if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
                if (el.placeholder) el.placeholder = val;
            } else {
                el.innerHTML = val;
            }
        }

    }

    injectStyles() {
        if (document.getElementById('sylo-lang-css')) return;
        const style = document.createElement('style');
        style.id = 'sylo-lang-css';
        style.innerHTML = `
            .lang-selector { position: relative; display: inline-block; margin-left:10px; }
            .lang-btn { background: transparent; border: 1px solid rgba(100,116,139,0.3); color: inherit; padding: 4px 10px; border-radius: 20px; cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 0.85rem; transition: all 0.2s; }
            .lang-btn:hover { border-color: var(--sylo-accent, #3b82f6); background: rgba(59, 130, 246, 0.1); }
            .lang-dropdown { display: none; position: absolute; top: 110%; right: 0; background: var(--sylo-card, #fff); border: 1px solid rgba(100,116,139,0.2); border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); overflow: hidden; z-index: 10000; min-width: 140px; }
            [data-theme="dark"] .lang-dropdown { background: #1e293b; border-color: #334155; }
            .lang-dropdown.show { display: block; animation: fadeIn 0.1s ease-out; }
            .lang-opt { display: flex; align-items: center; padding: 8px 16px; width: 100%; text-align: left; background: none; border: none; color: inherit; cursor: pointer; transition: background 0.2s; font-size: 0.9rem; }
            .lang-opt:hover { background: rgba(59, 130, 246, 0.1); color: var(--sylo-accent, #3b82f6); }
            .lang-opt img { width: 20px; margin-right: 10px; border-radius: 2px; }
            .lang-flag-icon { width:18px; height:13px; object-fit:cover; border-radius:2px; display:inline-block; }
        `;
        document.head.appendChild(style);
    }

    bindTriggers() {
        // Find language toggles
        document.querySelectorAll('.lang-toggle-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                // Find associated dropdown (sibling or specifically ID'd)
                const dd = btn.nextElementSibling;
                if (dd && dd.classList.contains('lang-dropdown')) {
                    dd.classList.toggle('show');
                }
            });
        });

        // Close dropdowns on click outside
        document.addEventListener('click', () => {
            document.querySelectorAll('.lang-dropdown').forEach(d => d.classList.remove('show'));
        });
    }

    async loadLanguage(lang) {
        if (!this.availableLangs.includes(lang)) lang = 'es';

        try {
            // Determine path relative to current page location
            // If we are in /panel/ , we need ../public/lang/
            // If we are in /public/ , we need ./lang/
            const isPanel = window.location.pathname.includes('/panel/');
            const basePath = isPanel ? '../public/lang/' : 'lang/';

            // Cache Busting: Force load fresh JSON
            const req = await fetch(`${basePath}${lang}.json?v=${new Date().getTime()}`);
            if (!req.ok) throw new Error("Failed to load lang");

            this.translations = await req.json();
            this.currentLang = lang;
            localStorage.setItem('sylo_lang', lang);

            this.applyTranslations();
            this.updateSelectorUI();

        } catch (e) {
            console.error("Language load failed:", e);
        }
    }

    applyTranslations() {
        document.querySelectorAll('[data-i18n]').forEach(el => {
            const key = el.getAttribute('data-i18n');
            const val = this.getNested(this.translations, key);
            if (val) {
                if (el.placeholder) el.placeholder = val;
                else el.innerHTML = val;
            }
        });
    }

    getNested(obj, path) {
        return path.split('.').reduce((prev, curr) => prev ? prev[curr] : null, obj);
    }

    get(key) {
        return this.getNested(this.translations, key) || key;
    }

    async setLanguage(lang) {
        await this.loadLanguage(lang);
    }

    updateSelectorUI() {
        const flagMap = { 'es': '🇪🇸', 'en': '🇬🇧', 'fr': '🇫🇷', 'de': '🇩🇪' };
        const labelMap = { 'es': 'ES', 'en': 'EN', 'fr': 'FR', 'de': 'DE' };

        document.querySelectorAll('.current-lang-flag').forEach(el => el.innerText = flagMap[this.currentLang]);
        document.querySelectorAll('.current-lang-label').forEach(el => el.innerText = labelMap[this.currentLang]);
    }
}

// Global exposure
window.SyloLang = new LanguageManager();
