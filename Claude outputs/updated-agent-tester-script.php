<?php if( $is_agent_tester ) : ?>
<script defer src="https://cdn.customgpt.ai/js/chat.js"></script>
<script>
	window.__cgptConfig = {
		p_id: '98865',
		p_key: 'f12d51cc482847f28a6333cf7f6a5c9d'
		<?php
		// Same "Identify Logged-In Users" setting as the main CustomGPT
		// Chat Widget plugin's own settings page (Settings -> CustomGPT
		// Chat Widget), read through the plugin's exported
		// customgpt_widget_get_external_id() helper so this stays in
		// sync with that setting automatically rather than duplicating
		// its logic here. Only sent for logged-in visitors when that
		// setting isn't "Don't identify users"; '' otherwise.
		$cgpt_agent_tester_external_id = function_exists( 'customgpt_widget_get_external_id' )
			? customgpt_widget_get_external_id()
			: '';
		?>
		<?php if ( '' !== $cgpt_agent_tester_external_id ) : ?>
		, external_id: '<?php echo esc_js( $cgpt_agent_tester_external_id ); ?>'
		<?php endif; ?>
	};
</script>
<!-- <script defer src="https://cdn.customgpt.ai/js/chat.js"></script> <script defer> (function(){ function init(){ CustomGPT.init({ p_id:'98043', p_key:'8c7e9ac540d9dd825d6cf4eab0ade038' }) } document.readyState === 'complete' ? init() : window.addEventListener('load', init); })(); </script>  -->
<!-- <script defer src="https://cdn.customgpt.ai/js/chat.js"></script> <script defer> (function(){ function init(){ CustomGPT.init({ p_id:'97474', p_key:'b53f0fe49da7c1843edb69e542282c3d' }) } document.readyState === 'complete' ? init() : window.addEventListener('load', init); })(); </script>  -->
<script>
(function() {
    var cgptInitStarted = false;

    // CustomGPT.init() fires several admin-ajax proxy calls (project info,
    // settings, conversation create) that each take multiple seconds. It used
    // to run unconditionally on window load for every page view; now it only
    // runs the first time the user actually opens the chat toggle.
    function ensureCustomGptInit(onReady) {
        if (typeof CustomGPT === 'undefined' || !window.__cgptConfig) {
            onReady();
            return;
        }

        if (cgptInitStarted) {
            onReady();
            return;
        }

        cgptInitStarted = true;
        CustomGPT.init(window.__cgptConfig);

        var attempts = 0;
        var maxAttempts = 50; // ~15s at 300ms
        var poll = setInterval(function() {
            attempts++;
            var ready = document.querySelector('#cgptcb-chat-circle') || document.querySelector('#customgpt-chat-1');
            if (ready || attempts >= maxAttempts) {
                clearInterval(poll);
                onReady();
            }
        }, 300);
    }

    <?php if( !is_front_page() ) : ?>
    // Eager init off the front page: the front page has its own always-
    // eager embedded AI panel (.cgpt-hero-card, rendered by chat.js itself
    // reading window.__cgptConfig - see the demoteHeroTitle/labelCgptInputs
    // handling below), so triggering our own CustomGPT.init() there too
    // would be a redundant second init on top of that panel's own auto-
    // render. Shares cgptInitStarted with ensureCustomGptInit above, so
    // whichever fires first - this eager call, or an early click on the
    // header toggle - wins and the other is a no-op, never a double init.
    function noop() {}
    if (document.readyState === 'complete') {
        ensureCustomGptInit(noop);
    } else {
        window.addEventListener('load', function () { ensureCustomGptInit(noop); });
    }
    <?php endif; ?>

    function openCustomGptWidget() {
        if (document.body.classList.contains('portal-home-2')) {
            const target = document.querySelector('#customgpt-chat-1');
            if (!target) return;

            const header = document.querySelector('header');
            const offset = header ? header.offsetHeight + 20 : 100;
            const y = target.getBoundingClientRect().top + window.pageYOffset - offset;

            window.scrollTo({ top: y, behavior: 'smooth' });
        } else {
            const target = document.querySelector('#cgptcb-chat-circle');
            if (target) target.click();
        }
    }

    document.addEventListener('click', function (e) {
        const toggle = e.target.closest('.customgpt-toggle');
        if (!toggle) return;

        e.preventDefault();

        ensureCustomGptInit(openCustomGptWidget);
    });

    // The embedded #customgpt-chat-1 panel (portal-home-2 only) renders its
    // own "ADAPT Intelligence" heading as an <h1> once the widget script
    // loads - that's a second h1 on a page that already has its own (see
    // template-portal-flexible.php's sr-only h1), which is confusing for
    // anyone navigating by heading. Can't edit the widget's own markup, so
    // once its heading shows up, override its accessible level to 2 without
    // touching the tag/classes/visual styling - role="heading" + aria-level
    // is exactly what ARIA provides for this. MutationObserver instead of a
    // poll since there's no fixed "ready" signal for when the widget renders
    // this specific element (unlike ensureCustomGptInit's chat-circle check).
    if (document.body.classList.contains('portal-home-2')) {
        var demoteHeroTitle = function() {
            var heroTitle = document.querySelector('h1.cgpt-hero-title');
            if (heroTitle) {
                heroTitle.setAttribute('role', 'heading');
                heroTitle.setAttribute('aria-level', '2');
                return true;
            }
            return false;
        };
        if (!demoteHeroTitle()) {
            var heroObserver = new MutationObserver(function() {
                if (demoteHeroTitle()) heroObserver.disconnect();
            });
            heroObserver.observe(document.body, { childList: true, subtree: true });
            // Give up after ~15s so this observer doesn't run forever if the
            // widget never renders this element (e.g. blocked, or the
            // agent_tester-only gate in the PHP above means it never loads).
            // 45s, not 15: on the front page this hero card is rendered by
            // the [customgpt_chat mode="embedded"] shortcode's own widget
            // bundle (customgpt-widget.b16.min.js + vendors.b16.min.js),
            // which chains several admin-ajax.php calls - measured live via
            // the Network dependency tree, one alone took 6530ms, three
            // chained. 15s wasn't enough to catch the front page's card
            // before this observer gave up, which is why labelCgptInputs
            // below (same class of fix, same real cause) was never actually
            // applying there despite being wired up correctly.
            setTimeout(function() { heroObserver.disconnect(); }, 45000);
        }
    }

    // Accessibility: the widget's chat card (.cgpt-hero-card, confirmed via
    // DOM inspection - a React island with no id/name/data-* hooks on either
    // field) renders a <textarea> (inside .cgpt-input-row) and a separate
    // file-upload <input> (a sibling section of the same card, not inside
    // the input row) with no accessible name, both flagged by Lighthouse's
    // "form elements do not have associated labels" check. Can't edit the
    // widget's own markup, so patch the fields once they exist, same
    // MutationObserver approach as demoteHeroTitle above. Not gated to a
    // body class - the card can render wherever the widget's chat UI opens,
    // not just portal-home-2.
    var labelCgptInputs = function() {
        var card = document.querySelector('.cgpt-hero-card');
        if (!card) return false;
        var textarea = card.querySelector('textarea');
        var fileInput = card.querySelector('input[type="file"]');
        var done = true;
        if (textarea) {
            if (!textarea.getAttribute('aria-label')) {
                textarea.setAttribute('aria-label', textarea.placeholder || 'Ask a question');
            }
        } else {
            done = false;
        }
        if (fileInput) {
            if (!fileInput.getAttribute('aria-label')) {
                fileInput.setAttribute('aria-label', 'Attach a file');
            }
        } else {
            done = false;
        }
        return done;
    };
    if (!labelCgptInputs()) {
        var inputObserver = new MutationObserver(function() {
            if (labelCgptInputs()) inputObserver.disconnect();
        });
        inputObserver.observe(document.body, { childList: true, subtree: true });
        // See the matching comment on heroObserver's timeout above - same
        // widget, same slow-render cause, same 45s fix.
        setTimeout(function() { inputObserver.disconnect(); }, 45000);
    }
})();
(function($) {
    var added = false;

    var tooltipHtml =
        '<div class="cgptcb-tooltip">' +
            '<span>AI assistant to source the insights you need</span>' +
            '<span class="cgptcb-tooltip-close">' +
                '<svg width="10" height="10" viewBox="0 0 10 10" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                    '<path d="M9.33333 0.94L8.39333 0L4.66667 3.72667L0.94 0L0 0.94L3.72667 4.66667L0 8.39333L0.94 9.33333L4.66667 5.60667L8.39333 9.33333L9.33333 8.39333L5.60667 4.66667L9.33333 0.94Z" fill="white"/>' +
                '</svg>' +
            '</span>' +
            '<svg width="14" height="9" viewBox="0 0 14 9" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                '<path d="M6.92819 9L13.8564 0H-1.09673e-05L6.92819 9Z" fill="#222222"/>' +
            '</svg>' +
        '</div>';

    var tooltipInterval = setInterval(function() {
        var $body = $('#cgptcb-body');

        if ($body.length && !added) {
            added = true;
            $body.append(tooltipHtml);
            clearInterval(tooltipInterval);
        }
    }, 300);

    $(document).on('click', '.cgptcb-tooltip-close', function(e) {
        e.preventDefault();
        $('.cgptcb-tooltip').remove();
    });
})(jQuery);
<?php endif; ?>
</script>
