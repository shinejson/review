/**
 * Optibiz Widget.js — JS embed for review generation widgets
 * Usage:
 *   <div id="optibiz-widget"></div>
 *   <script src="https://yourdomain.com/widget.js?key=abcd1234&theme=light" async></script>
 * Or with data attributes:
 *   <script src="widget.js" data-key="abcd1234" data-theme="dark" data-layout="grid" async></script>
 * The script injects a responsive iframe and tracks clicks/views.
 * Supports entitlement errors (402) and free-tier limits.
 */
(function(){
  'use strict';
  var scripts = document.getElementsByTagName('script');
  var thisScript = scripts[scripts.length - 1];
  function getParam(name){
    var src = thisScript.getAttribute('src') || '';
    try {
      var url = new URL(src, window.location.href);
      var v = url.searchParams.get(name);
      if (v) return v;
    } catch(e) {}
    var dataVal = thisScript.getAttribute('data-'+name);
    if (dataVal) return dataVal;
    // also try data-widget-key alias
    if (name==='key') {
      var alt = thisScript.getAttribute('data-widget-key') || thisScript.getAttribute('data-widget_key');
      if (alt) return alt;
    }
    return '';
  }
  var key = getParam('key') || getParam('widget_key') || getParam('k');
  var theme = getParam('theme') || '';
  var layout = getParam('layout') || '';
  var targetId = getParam('target') || thisScript.getAttribute('data-target') || '';
  var container = null;
  if (targetId) container = document.getElementById(targetId);
  if (!container) {
    // create container after script
    container = document.createElement('div');
    container.className = 'optibiz-widget-container';
    container.style.cssText = 'max-width:100%;';
    if (thisScript.parentNode) thisScript.parentNode.insertBefore(container, thisScript.nextSibling);
    else document.body.appendChild(container);
  }
  if (!key) {
    container.innerHTML = '<div style="padding:12px;border:1px solid #fde68a;background:#fffbeb;color:#92400e;border-radius:10px;font:13px sans-serif;">Optibiz Widget: missing <code>key</code> — add <code>?key=YOUR_WIDGET_KEY</code> to the script src. Find it in Admin → Grow & Monetize → Review Widgets.</div>';
    return;
  }
  // Build iframe src
  var base = thisScript.getAttribute('src') || '';
  var origin = '';
  try {
    var u = new URL(base, window.location.href);
    origin = u.origin + u.pathname.replace(/widget\.js$/,'widget.php');
  } catch(e) {
    origin = 'widget.php';
  }
  var qs = '?key=' + encodeURIComponent(key);
  if (theme) qs += '&theme=' + encodeURIComponent(theme);
  if (layout) qs += '&layout=' + encodeURIComponent(layout);
  // Height heuristic per layout
  var heights = { badge: 60, card: 360, grid: 420, carousel: 380 };
  var h = heights[layout] || heights.card;
  if (layout==='badge') h = 60;

  var iframe = document.createElement('iframe');
  iframe.src = origin + qs;
  iframe.setAttribute('title','Customer reviews — Optibiz verified');
  iframe.setAttribute('loading','lazy');
  iframe.setAttribute('frameborder','0');
  iframe.setAttribute('scrolling','no');
  iframe.style.cssText = 'width:100%;border:none;overflow:hidden;border-radius:14px;display:block;max-width:100%;background:transparent;';
  iframe.style.height = h + 'px';
  // Allow responsive resize via postMessage
  window.addEventListener('message', function(ev){
    try {
      if (!ev.data || ev.data.source !== 'optibiz-widget') return;
      if (ev.data.key && ev.data.key !== key) return;
      if (ev.data.height) iframe.style.height = Math.max(60, Math.min(900, parseInt(ev.data.height,10))) + 'px';
    } catch(e){}
  });
  // Fallback click beacon: iframe will beacon itself, but also track container visibility
  var viewed = false;
  function markView(){
    if (viewed) return; viewed = true;
    try {
      if (navigator.sendBeacon) {
        var fd = new FormData(); fd.append('widget_key', key); fd.append('event','view');
        // relative beacon — works when widget.js served from same origin; cross-origin will still hit origin
        var beaconUrl = origin.replace('widget.php','api/widget_view.php');
        navigator.sendBeacon(beaconUrl, fd);
      }
    } catch(e){}
  }
  // IntersectionObserver for view impression (counts even before iframe loads)
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function(entries){
      entries.forEach(function(en){ if(en.isIntersecting){ markView(); io.disconnect(); }});
    }, {threshold:0.4});
    io.observe(container);
  } else {
    setTimeout(markView, 1200);
  }
  iframe.onload = function(){
    // try to auto-resize after load (iframe can postMessage its height)
    setTimeout(function(){
      try {
        iframe.contentWindow.postMessage({source:'optibiz-widget-resize', action:'getHeight'}, '*');
      } catch(e){}
    }, 300);
  };
  iframe.onerror = function(){
    container.innerHTML = '<div style="padding:12px;border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;border-radius:10px;font:12px sans-serif;">Widget failed to load — check your widget key and domain allowlist in Optibiz.</div>';
  };
  container.appendChild(iframe);
  // Expose global for manual refresh
  window.OptibizWidget = window.OptibizWidget || {};
  window.OptibizWidget.reload = function(newKey){
    if (newKey) iframe.src = origin + '?key=' + encodeURIComponent(newKey) + (theme?'&theme='+theme:'') + (layout?'&layout='+layout:'');
    else iframe.src = iframe.src;
  };
})();
