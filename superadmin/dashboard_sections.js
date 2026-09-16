/* ============================================================
   Dashboard Card Collapse System
   Allows individual dashboard cards to be expanded/collapsed
   ============================================================ */

document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    // --- Per-card collapse/expand ---
    function saToggleCard(card, collapsed) {
        if (typeof collapsed === 'undefined') {
            collapsed = !card.classList.contains('sa-card-collapsed');
        }
        if (collapsed) {
            card.classList.add('sa-card-collapsed');
        } else {
            card.classList.remove('sa-card-collapsed');
        }

        // Save preference to localStorage
        if (typeof localStorage !== 'undefined') {
            try {
                var key = 'sa_card_' + card.getAttribute('data-card-id');
                localStorage.setItem(key, collapsed ? 'collapsed' : 'expanded');
            } catch (e) {}
        }
    }

    // Load saved card states and attach collapse handlers
    function initCardCollapse() {
        var cards = document.querySelectorAll('.sa-card[data-card-id]');
        cards.forEach(function(card) {
            var key = 'sa_card_' + card.getAttribute('data-card-id');
            var saved = null;
            if (typeof localStorage !== 'undefined') {
                try {
                    saved = localStorage.getItem(key);
                } catch (e) {}
            }
            if (saved === 'collapsed') {
                card.classList.add('sa-card-collapsed');
            }

            var toggleBtn = card.querySelector('.sa-card-toggle');
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    saToggleCard(card);
                });
            }
        });
    }

    // Bulk actions: expand/collapse all
    window.toggleAllCards = function(action) {
        var cards = document.querySelectorAll('.sa-card[data-card-id]');
        var doCollapse = (action === 'collapse');
        cards.forEach(function(card) {
            saToggleCard(card, doCollapse);
        });
    };

    // Initialize
    initCardCollapse();
});

/* Keyboard shortcut: Press 'S' to toggle section control visibility */
document.addEventListener('keydown', function(e) {
    if (e.key === 's' && !e.ctrlKey && !e.metaKey && !e.altKey) {
        var controls = document.querySelector('.sa-section-controls');
        if (controls) {
            controls.classList.toggle('hidden');
        }
    }
});
