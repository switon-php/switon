# Router reference — questions

**Generate:** switon-meta-skill::questions-author. **Verify:** switon-meta-skill::verify-reference.

**1. Basic**
1.1. I added an endpoint but it returns 404 — how do I debug?
1.2. Who handles this URL (e.g. POST /api/orders)? I need to change the logic?
1.3. User says GET /users/123 errors — I need to find the code that handles it.
1.4. I want to add an endpoint under /api prefix — where do I configure that?
1.5. We have no prefix configured — when I look up a path, do I include a prefix or not?
1.6. User said "show me the code for this endpoint" and pasted a URL — how do I jump to it?
1.7. User gave a full URL — can I use just the path part to look up the handler?
1.8. I need to open the exact file and line where this endpoint is implemented.
1.9. How do I find which controller and action handle a given path?
1.10. Who handles POST /admin/account/captcha?
1.11. User says PUT /api/products/1 fails — I need to find the handler to fix it。
1.12. User says DELETE /api/cart/items returns 404 — who should handle it?
1.13. I need to find what handles GET /api/users.
1.14. I have a path but I'm not sure which HTTP method — how do I find all handlers for that path?
1.15. User asks "where is the code for POST /login" — what do I run?
1.16. User asks "which controller handles /dashboard" — what do I run?
1.17. User says "find the handler for this URL" — what's the one thing I should run?

**2. Listing and filters**
2.1. How do I list all routes in the project?
2.2. I need to see all GET routes only.
2.3. I need to see only routes under /api.
2.4. I need to see only routes under /admin.
2.5. I need to see only routes under /v2.
2.6. I need to list only POST routes.
2.7. I need to list only PUT routes.
2.8. I need a full list of routes with their short ids (e.g. for a dashboard).
2.9. I want to see every route with no filter — does list do that?
2.10. I need only /api routes in a format I can parse (e.g. JSON).
2.11. I need only GET routes in a format I can parse。
2.12. --path=/api in list — does it match pattern starting with /api or pattern that contains /api anywhere?
2.13. I run router:list twice — is the order of routes the same each time?

**3. Prefix and dynamic segments**
3.1. I need to check what prefix the app is using for routes.
3.2. I need to change where the API prefix is set (e.g. /api vs /v1).
3.3. Where do I set the router prefix for this app?
3.4. A script needs to read the current router prefix — how?
3.5. I need the router prefix in plain text for a script (no JSON).
3.6. We use static prefix /api — how do I confirm what the router thinks the prefix is?
3.7. We don't want any prefix (all routes at root) — is that supported?
3.8. Request came in as /api/v2/orders and our prefix is /api — what path do I pass to the router?
3.9. I'm building the request path to pass to the router — do I include the app's prefix or not?
3.10. I'm calling the router from a filter with the request path — do I pass the full path or strip prefix first?
3.11. Prefix is dynamic ?/v1; I run router:prefix (no URI) — what exactly is output?
3.12. Dynamic prefix ?/time; getPrefix("/en/time/current") — what string is returned?
3.13. Our config has ?/time prefix; a cron job runs without a request — will getPrefix() break?
3.14. We configured ?/v1 so /en/v1/users and /fr/v1/users both work — is that right?
3.15. We have ?/v1 — will /fr/v1/users match?
3.16. I want /xxx/time/current to work for any xxx (e.g. en, api-v2) — how to configure?
3.17. We have ?/time — will /foo/time/current match?
3.18. We want the first path segment to support locales (/en/..., /zh/...) — how to configure?
3.19. Our first segment is a locale code like en or zh — can it contain digits (e.g. en-US)?
3.20. Our first segment is an API version like api-v2 — can it have a hyphen?
3.21. We want the first path segment to accept any tenant/code (letters, numbers, hyphen) — which config?
3.22. First segment allows a-zA-Z0-9_-; does it allow underscore only (e.g. "_" or "a_b")?

**4. Handler id and controllers**
4.1. Logs show request hit id admin.account::captcha — which class/method is that?
4.2. I see handler id admin.account::captcha in the list — how is that derived from the class name?
4.3. Logs say handler id order::show — which class is that?
4.4. Logs say handler id shop.cart::add — which class is that?
4.5. Logs say handler id rbac.role::index — which class is that?
4.6. Handler id shows "captcha" not "captchaAction" — is that expected?
4.7. I see handler ids like user::index — is the controller part always kebab-case?
4.8. I see action part like create or index — is it always kebab and no "Action"?
4.9. Handler id shows admin.account — we have Areas\Admin\Controller\AccountController — is that the rule?
4.10. We have Areas\A\Controller\BController — what would the id controller part be?
4.11. I need to generate or parse handler ids (e.g. for logs) — where is the rule?
4.12. Handler string is missing `::method` or has an empty controller/action segment — is that supported?

**5. Attributes, discovery and registration**
5.1. I added a Controller with GetMapping — how do I confirm the route is registered?
5.2. I added a new endpoint via attributes — what do I run to confirm it's there?
5.3. I added PostMapping on createAction — how do I confirm the route exists?
5.4. I changed a Mapping attribute — how do I verify it took effect?
5.5. Do I have to register routes in code or does the framework discover them?
5.6. I'm debugging why a route isn't registered — which component scans the controllers?
5.7. I'm debugging route registration — what actually scans the controllers?
5.8. We use controller attributes for routes — do we still need to call add* somewhere?
5.9. We want one route added in code (not attribute) — is that supported?
5.10. I want to add one route in code without an attribute — can I?
5.11. We have a resource controller (CRUD) — is there a shortcut to add all routes?
5.12. Reference says "manual add* usually unnecessary" — in what case would I need add*?
5.13. I need the rules for RequestMapping, GetMapping (path, verb) — where do I look?
5.14. The router reference says "see controller.md" for attributes — where is that file?
5.15. One controller has #[RequestMapping(['/a', '/b'])] — how many route patterns does list show for one action?

**6. Resolving and debugging**
6.1. After I run a command I get file, lineStart, lineEnd — what do I do with them?
6.2. I need handler name, file path, and line range for /orders/42.
6.3. I need to script "resolve path to handler" and parse the output — what do I run?
6.4. I didn't specify verb when resolving — can I get back more than one handler?
6.5. I ran resolve and no route matched — what will the output look like?
6.6. Resolve returned results with params — what are those (e.g. path parameters)?
6.7. I see id in both list and resolve output — is it the same format?
6.8. I got file, lineStart, lineEnd from resolve — how do I open that in the editor?
6.9. User gave https://example.com/api/users — what path do I pass to resolve?
6.10. Path has a number like /users/123 — is that valid to pass to resolve?
6.11. No route matches the path — does the command fail or return something I可以parse?
6.12. I'm matching a path in code — if no route matches, does it throw or return null?
6.13. I resolved GET /users with verb specified — one result?
6.14. I resolved /users without verb — one result or several?
6.15. Request URI has query string, e.g. /api/users?page=1 — do I pass the full string to match() or strip the query?
6.16. match() with no matching route — does the CLI router:resolve catch it and return JSON, or does it throw up to the
shell?
6.17. router:resolve with path that matches but handler is not Class::method — what appears in results[].file?
6.18. Handler is a closure or invokable — does resolve still return file/lineStart/lineEnd?
6.19. Path has two segments like /users/42/posts — is that valid for resolve?
6.20. Route pattern is /users/{id}; request /users/42 — what key does results[].params use for 42?

**7. Scripting and programmatic access**
7.1. I need to script "list all routes" and parse the output — what format do I ask for?
7.2. I need to script "resolve path to handler" and parse the output — what do I run?
7.3. I need only /api routes in a format I can parse (e.g. JSON).
7.4. I need only GET routes in a format I can parse.
7.5. I need to list routes so another tool can consume the output — how?
7.6. I need to resolve a path so a script can get handler and location — how?
7.7. A script needs to read the current router prefix — how?
7.8. I need the router prefix in plain text for a script (no JSON).
7.9. In router:list JSON output, is each route's "pattern" the full path (including prefix) or relative to prefix?
7.10. getAllRoutes() — what is the structure of each element (same as list JSON routes[])?
7.11. I need to get all registered routes programmatically — which method?

**8. Types, package and environment**
8.1. I'm writing a test that mocks the router — what type do I use?
8.2. I need to know which package provides the router (for composer / docs).
8.3. When does the app set the router prefix (bootstrap or config load)?
8.4. We register CRUD with multiple addGet/addPost/… — should we use #[GetMapping] instead?
8.5. A route works locally but returns 404 in production — what should I check (config, prefix, env)?
8.6. User pasted a path without leading slash (e.g. users/42) — will router:resolve still work or should I tell them to
add the slash?

**9. Edge cases and behavior**
9.1. We have flexible first segment; user hit a path that doesn't match — what happens?
9.2. Two routes could match the same URI and verb — which one wins (registration order)?
