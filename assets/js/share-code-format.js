// Auto-uppercase + auto-insert dashes while typing in the public
// share-code input. Extracted from an inline <script> block in
// index.php in v1.7.3.1 because the public landing is served with
// the client-portal CSP (issue-020 invariant: script-src must NOT
// contain 'unsafe-inline'), and the browser blocks any inline
// <script> under that CSP. Loading the same logic from an external
// file via <script src="…"> is allowed by 'self' in script-src.
//
// The logic is unchanged from the original inline block — same
// DOM event, same uppercase-and-dash formatting, same max length
// (15 chars, 3 groups of 5).
(function () {
    var input = document.getElementById('code');
    if (!input) return;
    input.addEventListener('input', function () {
        var v = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (v.length > 15) v = v.substring(0, 15);
        var formatted = '';
        for (var i = 0; i < v.length; i++) {
            if (i === 5 || i === 10) formatted += '-';
            formatted += v[i];
        }
        if (input.value !== formatted) {
            input.value = formatted;
        }
    });
})();
