<?php
/**
 * Debug 503 page rendered by Cloude\Http\ErrorHandler.
 *
 * Variables in scope:
 *   $exceptionClass string
 *   $message        string
 *   $file           string
 *   $line           int
 *   $reqInfo        string
 *   $snippet        string (rendered HTML)
 *   $traceRows      string (rendered HTML)
 *   $prev           string (rendered HTML)
 */
$h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>503 — <?= $h($exceptionClass) ?></title>
<style>
body{font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:0;background:#1e1e1e;color:#e8e8e8}
.wrap{max-width:1100px;margin:0 auto;padding:24px}
h1{margin:0 0 4px;font-size:18px;color:#ff6b6b}
h1 .cls{color:#ffb86b}
h2{margin:24px 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#9aa}
.msg{font-size:16px;color:#fff;background:#2d1f1f;border-left:3px solid #ff6b6b;padding:12px 14px;border-radius:4px;margin:8px 0 4px;white-space:pre-wrap}
.loc{color:#7fc8ff;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;margin-top:4px}
.req{color:#aaa;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}
table{border-collapse:collapse;width:100%;background:#252525;border-radius:4px;overflow:hidden}
td{padding:2px 8px;vertical-align:top;border-top:1px solid #2f2f2f}
td.ln{color:#666;text-align:right;width:1%;white-space:nowrap;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;user-select:none;background:#1f1f1f}
pre{margin:0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;white-space:pre;color:#e8e8e8}
.src tr.hl{background:#5a2424}
.src tr.hl td.ln{background:#7a2828;color:#ffb86b}
.src tr.hl pre{color:#fff}
code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#7fc8ff}
.prev{margin-top:16px;padding:12px;background:#2a2a2a;border-radius:4px}
.prev h3{margin:0 0 4px;font-size:13px;color:#ffb86b}
</style>
</head>
<body>
<div class="wrap">
    <h1><span class="cls"><?= $h($exceptionClass) ?></span> — 503</h1>
    <div class="req"><?= $h($reqInfo) ?></div>
    <div class="msg"><?= $h($message) ?></div>
    <div class="loc">at <?= $h($file . ':' . $line) ?></div>
    <?php if ($snippet): ?><h2>Source</h2><?= $snippet ?><?php endif; ?>
    <?= $prev ?>
    <?php if ($traceRows): ?><h2>Stack trace</h2><table><tbody><?= $traceRows ?></tbody></table><?php endif; ?>
</div>
</body>
</html>
