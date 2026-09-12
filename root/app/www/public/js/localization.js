function translate(key, args) {
    var map = window.APP_TRANSLATIONS || {};
    var text = map[key];
    if (text === undefined || text === null) {
        text = key;
    }
    text = String(text);
    if (args && args.length) {
        for (var i = 0; i < args.length; i++) {
            text = text.split('{' + i + '}').join(args[i] != null ? String(args[i]) : '');
        }
    }
    return text;
}
window.translate = translate;
// -------------------------------------------------------------------------------------------
