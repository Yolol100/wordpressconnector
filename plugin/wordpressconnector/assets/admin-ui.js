/* global wp, WPConnectorAdminUI */
(function (win, doc) {
    'use strict';

    const mount = doc.getElementById('wpconnector-admin-tabs');
    const config = win.WPConnectorAdminUI;
    const element = win.wp && win.wp.element;
    const Button = win.wp && win.wp.components && win.wp.components.Button;

    // Leave the server-rendered navigation and all settings visible if React
    // is unavailable. No credentials or settings are managed in client state.
    if (!mount || !config || !Array.isArray(config.tabs) ||
        !element || !element.useState || !Button) {
        return;
    }

    const names = config.tabs.map(function (tab) { return tab.name; });
    const tabId = function (name) { return 'wpconnector-tab-' + name; };
    const panelId = function (name) { return 'wpconnector-' + name; };

    function validHash() {
        const name = win.location.hash.replace(/^#wpconnector-/, '');
        return names.indexOf(name) !== -1 ? name : 'overview';
    }

    function updatePanels(selected) {
        names.forEach(function (name) {
            const panel = doc.getElementById(panelId(name));
            if (!panel) {
                return;
            }
            panel.hidden = selected !== name;
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('aria-labelledby', tabId(name));
            panel.setAttribute('tabindex', '0');
        });
    }

    function Navigation() {
        const state = element.useState(validHash);
        const selected = state[0];
        const setSelected = state[1];
        const buttons = element.useRef({});

        element.useEffect(function () {
            updatePanels(selected);
        }, [selected]);

        element.useEffect(function () {
            function handleHashChange() {
                setSelected(validHash());
            }
            win.addEventListener('hashchange', handleHashChange);
            return function () {
                win.removeEventListener('hashchange', handleHashChange);
            };
        }, []);

        function select(name, focus) {
            if (names.indexOf(name) === -1) {
                return;
            }
            // Keep native hash links working for the fallback and deep links.
            if (win.location.hash !== '#' + panelId(name)) {
                if (win.history && win.history.replaceState) {
                    win.history.replaceState(null, '', '#' + panelId(name));
                } else {
                    win.location.hash = panelId(name);
                }
            }
            setSelected(name);
            if (focus && buttons.current[name]) {
                buttons.current[name].focus();
            }
        }

        function onKeyDown(event, name) {
            const index = names.indexOf(name);
            let next = index;
            if (event.key === 'ArrowRight') {
                next = (index + 1) % names.length;
            } else if (event.key === 'ArrowLeft') {
                next = (index + names.length - 1) % names.length;
            } else if (event.key === 'Home') {
                next = 0;
            } else if (event.key === 'End') {
                next = names.length - 1;
            } else {
                return;
            }
            event.preventDefault();
            select(names[next], true);
        }

        return element.createElement(
            'div',
            { role: 'tablist', className: 'wpconnector-tabs', 'aria-label': config.navLabel },
            config.tabs.map(function (tab) {
                const isActive = selected === tab.name;
                return element.createElement(
                    Button,
                    {
                        key: tab.name,
                        ref: function (node) { buttons.current[tab.name] = node; },
                        id: tabId(tab.name),
                        role: 'tab',
                        type: 'button',
                        variant: 'tertiary',
                        tabIndex: isActive ? 0 : -1,
                        'aria-selected': isActive ? 'true' : 'false',
                        'aria-controls': panelId(tab.name),
                        className: 'wpconnector-tab' + (isActive ? ' is-active' : ''),
                        onClick: function () { select(tab.name, false); },
                        onKeyDown: function (event) { onKeyDown(event, tab.name); }
                    },
                    tab.title
                );
            })
        );
    }

    if (element.createRoot) {
        element.createRoot(mount).render(element.createElement(Navigation));
    } else if (element.render) {
        element.render(element.createElement(Navigation), mount);
    }
}(window, document));
