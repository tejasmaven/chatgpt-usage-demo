(function () {
    var isDashboard = window.location.search.indexOf('page=dashboard') !== -1 || window.location.search === '';

    if (isDashboard) {
        setTimeout(function () {
            window.location.reload();
        }, 60000);
    }
})();
