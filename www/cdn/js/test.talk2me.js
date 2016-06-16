var fs = require("fs");

function read(f) {
    return fs.readFileSync(f).toString();
}

function include(f) {
    eval.apply(global, [read(f)]);
}

include("jquery/1.10.2/jquery-1.10.2.min.js");
include("fake-query-0.2.js");

$$.reset();

include("moment.min.js");
include("Autolinker.min.js");
include("talk2me.js");

$$.stopRecording();
