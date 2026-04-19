<h1>Hello from Cloude</h1>

<p>This is a minimal example project built on top of <code>cloude/framework</code>.</p>

<p>Available routes:</p>
<ul>
    <li><a href="/">/</a> - this page</li>
    <li><a href="/hello/world">/hello/{name}</a> - route with a dynamic parameter</li>
    <li><a href="/about">/about</a> - static page</li>
    <li><code>POST /api/echo</code> - returns a JSON dump of the request</li>
</ul>

<h2>Try the JSON endpoint</h2>
<pre>curl -X POST http://localhost:8000/api/echo \
  -H 'Content-Type: application/json' \
  -d '{"hello": "world"}'</pre>
