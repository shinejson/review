/* ============================================================
   Dashboard Card Collapse System
   Allows individual dashboard cards to be expanded/collapsed
   ============================================================ */

(function() {
    'use strict';

    // Toggle single card
    function saToggleCard(card, forceState) {
        if (!card) return;
        var willCollapse = (typeof forceState === 'boolean') 
            ? forceState 
            : !card.classList.contains('sa-card-collapsed');

        if (willCollapse) {
            card.classList.add('sa-card-collapsed');
        } else {
            card.classList.remove('sa-card-collapsed');
        }

        var toggleBtn = card.querySelector('.sa-card-toggle:not([data-action="refresh"]):not([onclick])');
        if (toggleBtn) {
            toggleBtn.setAttribute('aria-expanded', willCollapse ? 'false' : 'true');
            toggleBtn.setAttribute('title', willCollapse ? 'Expand card' : 'Collapse card');
        }

        // Save preference to localStorage
        var cardId = card.getAttribute('data-card-id');
        if (cardId && typeof localStorage !== 'undefined') {
            try {
                localStorage.setItem('sa_card_' + cardId, willCollapse ? 'collapsed' : 'expanded');
            } catch (e) {}
        }
    }

    // Load saved card states
    function initCardStates() {
        var cards = document.querySelectorAll('.sa-card[data-card-id]');
        cards.forEach(function(card) {
            var cardId = card.getAttribute('data-card-id');
            var saved = null;
            if (cardId && typeof localStorage !== 'undefined') {
                try {
                    saved = localStorage.getItem('sa_card_' + cardId);
                } catch (e) {}
            }
            if (saved === 'collapsed') {
                saToggleCard(card, true);
            } else {
                var toggleBtn = card.querySelector('.sa-card-toggle:not([data-action="refresh"]):not([onclick])');
                if (toggleBtn) {
                    toggleBtn.setAttribute('aria-expanded', 'true');
                    toggleBtn.setAttribute('title', 'Collapse card');
                }
            }
        });
    }

    // Global event delegation for all card toggle clicks
    document.addEventListener('click', function(e) {
        var toggleBtn = e.target.closest ? e.target.closest('.sa-card-toggle') : null;
        if (!toggleBtn) return;

        // Skip if button has an explicit onclick handler (like refresh)
        if (toggleBtn.getAttribute('onclick') || toggleBtn.getAttribute('data-action') === 'refresh') {
            return;
        }

        var card = toggleBtn.closest('.sa-card');
        if (card) {
            e.preventDefault();
            e.stopPropagation();
            saToggleCard(card);
        }
    });

    // Bulk actions: expand/collapse all
    window.toggleAllCards = function(action) {
        var cards = document.querySelectorAll('.sa-card[data-card-id]');
        var doCollapse = (action === 'collapse');
        cards.forEach(function(card) {
            saToggleCard(card, doCollapse);
        });
    };

    window.saToggleCard = saToggleCard;

    // Safe execution: execute now or when DOM is ready
    if (document.readyState !== 'loading') {
        initCardStates();
    } else {
        document.addEventListener('DOMContentLoaded', initCardStates);
    }
})();

/* Keyboard shortcut: Press 'S' to toggle section control visibility */
document.addEventListener('keydown', function(e) {
    if (e.key === 's' && !e.ctrlKey && !e.metaKey && !e.altKey && e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
        var controls = document.querySelector('.sa-section-controls');
        if (controls) {
            controls.classList.toggle('hidden');
        }
    }
});
