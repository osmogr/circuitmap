window.CircuitMapBasePath = (function () {
    var meta = document.querySelector('meta[name="base-path"]');
    return (meta && meta.getAttribute('content')) || '';
})();
