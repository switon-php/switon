# Filter reference — questions

**Generate:** switon-meta-skill::questions-author. **Verify:** switon-meta-skill::verify-reference.

**Basic**

1. I need to add a header to every HTTP response regardless of route. Which mechanism do I use?
2. I need to log every request (method, path, status). Where does that logic go — which component type?
3. I need to run logic only before one controller action. Which scope or mechanism do I use?
4. I need to run logic when a domain event is dispatched. Which scope or mechanism do I use?
5. Where do I put a class that runs on every HTTP request (e.g. CORS, logging) — namespace and path?
6. I wrote a class that should run at request start. What attribute and method signature does it need?
7. I want to see what runs on every request (the list of those components). What command do I run?
8. I want to disable the access log that runs on every request without deleting its class. What do I set in config?
9. I need to add a custom component that runs on every request, after CORS but before the action. Where do I configure
   the order?
10. Which event should I subscribe to if I need to set a response header right before the response is sent?
11. Which event should I subscribe to if I need to do something at the very start of the request?
12. Which event should I subscribe to if I need to do something when the request has finished (e.g. write a log line)?
13. I need per-request state in a class that runs on every request. What contract and pattern is recommended?
14. The docs say "Business → listener". What does that mean for when to use request-wide components?
15. I have a class that only does monitoring (e.g. access log) and doesn't affect business logic. What optional
    interface can it implement?

**Discovery and config**

16. How does the framework discover components that run on every request when they live in a package (not the app)?
17. How does the framework discover those components when they live in the app?
18. I added a new class that should run on every request (in the app namespace for such components) but it doesn't
    appear when I run the list command. What do I check (naming, path)?
19. What is the config file name for enabling or disabling what runs on every request, and under which interface key
    does the list live?
20. In that config, I set one entry in the list to null. What effect does that have?
21. I want to add something that runs on every request. Do I register it in a service provider or in config?
22. Which class builds the registry that the list command uses for those components?

**Troubleshooting**

23. User says the new component they added that runs on every request never runs. What do I check first (config,
    discovery, event)?
24. User says those components run in the wrong order. Where is the order defined?
25. User disabled a default one by setting it to null in config, but it still runs. What could be wrong?
26. I added my class to the config file that controls what runs on every request, but it's not in the list. Could
    discovery have skipped it?
27. I need to see which HTTP lifecycle events exist so I can pick the right one for a class that runs at a specific
    phase. What command or filter do I use?

**Implementation details**

28. Can one class subscribe to more than one request lifecycle event (e.g. RequestBegin and RequestEnd)?
29. The docs say RequestInterface::setAttribute() is "allowed but not preferred". What is preferred for per-request
    state?
30. Where do the framework's request-lifecycle components live (package namespace pattern)?
31. What suffix should class names use for those components (for clarity and discovery)?
32. I'm writing a class that only inspects/monitors and does not affect business logic. What interface can I implement
    so the framework treats it as observability-only?

**Flow / multi-class**

33. From "request received" to "response sent", name three lifecycle events commonly used by components that run on
    every request and what phase they correspond to.
34. User says one component (CORS) runs but another (custom header) doesn't. Both subscribe to the same event. What
    could differ (config order, discovery)?
35. I need to trace how a request flows through the components that run on every request. The docs say there is no
    separate pipeline — what does that mean, and how do they actually run?
36. Which component reads the config file that enables/disables what runs on every request, and in what shape (key under
    which interface, array meaning)?
37. Packages register those components via composer.json. The app registers via scanning a directory. How are the two
    lists merged (order, override)?
38. I subscribe to RequestBegin and want to add a response header there. Can I, or do I need a different event?

**New to Switon / from other frameworks**

39. I'm used to Laravel's request stack (global vs route). Is there an equivalent here? How do I add something that
    runs on every request?
40. In Symfony I used kernel.request and kernel.response events. What's the Switon equivalent for "before request" and "
    after response"?
41. I've never used Switon. I need to add CORS handling. Where do I start — config, a class, or a command?
42. We're porting an Express app. We had app.use(cors()). Where do we put that kind of logic in Switon?
43. In our old framework we had "filters" that run per action. Here we want request-wide logic. Which concept do we use?
44. Someone said "what runs on every request runs as event listeners" — I thought it was a pipeline. What does that
    mean?
45. I don't know what RequestBegin or HeadersSending are. Where can I get a list of events I can hook into?
46. What's the difference between the thing that runs on every request and a "listener" in this codebase? When do I use
    which?
47. I need something that runs before the controller on every request. What do I use?
48. We used to have a single array of "things that run on every request" in our previous framework. Where is the
    equivalent list in this app?
49. I'm looking for where HTTP request lifecycle is defined (order of phases). Where do I look?
50. I come from a framework where you pass next() to continue. Here there's no next — how does the request "move on"
    after my code runs?
51. In Laravel we register a global request stack in Kernel or bootstrap. Where do we register "runs on every request"
    in
    Switon — a provider, a config file, or both?
52. In Symfony we have EventSubscriber with getSubscribedEvents(). How do we subscribe to request lifecycle in Switon —
    attribute on a method, config, or both?
53. In Express we have (req, res, next). In Switon there's no next(). When does the "next" handler run — after my method
    returns, or do I have to call something?
54. We used NestJS guards and interceptors. We want request-wide logic (e.g. auth check before controller). What's the
    Switon equivalent?
55. In Django we have MIDDLEWARE list in settings. Where is the equivalent ordered list in Switon?
56. In ASP.NET Core we have `UseMiddleware(...)` in the pipeline. How do we add the Switon equivalent — by config only,
    or do we also
    create a class and put it somewhere?
57. In Laravel we use $request->attributes or similar for request-scoped data. What's the Switon way to pass data along
    the request between components?
58. In Symfony we use kernel.request listener priority to order execution. How is order defined in Switon for request
    lifecycle?
59. We came from Rails. We had Rack request wrappers. Where do we put that kind of "wrap the request" logic in Switon?
60. In Laravel we have route hook stacks vs global hooks. Does Switon have "only for some routes" or is it always "every
    request"?
61. In Express we do app.use((err, req, res, next) => {}) for error handling. Is there a request-lifecycle event for
    errors in Switon?
62. We used Spring's HandlerInterceptor (preHandle, postHandle, afterCompletion). Which Switon concept maps to "
    before/after request"?
63. In Laravel we put request-stack classes under an `app/Http/` convention. Does Switon have a conventional directory
    and namespace for "
    runs on every request"?
64. We're from a framework where a request-stack class has `handle($request, $next)`. In Switon we see events and
    listeners. Are those the same concept or a different split?
65. In Slim (PHP) we add a request wrapper with `$app->add()`. In Switon how do we add one — same idea or different?
66. We used FastAPI request wrappers. We want to run code before and after the request. Do we need one class with two
    event
    subscriptions or two classes?

**User reports / real scenarios**

67. User says "the new thing we added that runs on every request never runs". They added a class and put it in config.
    What's the first thing to verify?
68. User says "the order is wrong — our auth runs after the action". Where do they fix the order of what runs on every
    request?
69. User disabled one of those components with null in config but it still runs in production. What could be wrong?
70. User added a class under a legacy `App\Http\...` request-stack namespace that should run on every request, but the
    list command doesn't show it.
    What naming or path rule might they have broken?
71. User wants to add X-Request-ID to every response. Which event and where do they put the class?
72. User says "we need to log every request with duration". Where does that logic go — which component type, and how do
    they decide?
73. User reports "CORS works in dev but not in production". Config is the same. What could differ (order of components,
    discovery)?
74. User added two components that both set response headers. One overwrites the other. What determines the order they
    run?
75. User wants to run logic only when a specific route is hit. Which mechanism do they use?
76. User says "our custom component that runs on every request also runs in CLI when we run a command" — they expected
    HTTP only. Is that expected?
77. User needs to stop the request early (e.g. return 401) from something that runs on every request. Can that be done,
    and how?
78. User pasted config that lists what runs on every request, including 'cors' => SomeClass::class. They want to disable
    cors. What do they change?
79. User is not sure if their component is in the list of what runs on every request. What single command do they run?
80. User moved the class that runs on every request to another namespace. Now it doesn't run. What else do they need to
    update?
81. User wants "A then B then C" for the things that run on every request. Where do they define that order?
82. User says "we have both a legacy `App\Http\...` request-stack namespace and `App\Filter\...` in the codebase". Which
    path does discovery scan?
83. User added a class that implements ObservabilityProbe and runs on every request. What difference does that make at
    runtime?
84. User needs to share data from one of those components with another in the same request. What's the recommended way?
85. User says "our component that runs on every request throws and the whole request fails". Can they catch and handle
    so the request still completes?
86. User wants to add timing (X-Response-Time header). Which lifecycle event should they use?

**Terminology / where to look**

87. What does "request-wide" mean, and how is it different from "per-action"?
88. I see RequestHandlerInterface in config. What is it and why is the config for "what runs on every request" under it?
89. What is "discovery" for the components that run on every request — who discovers what?
90. I see `extra.switon.filters` in a package's `composer.json`. What is that for?
91. What does "no separate pipeline" mean — does the framework still run those components in order?
92. Where do I find the FQCN of a built-in component that runs on every request so I can reference it in config?
93. What's the difference between "enable/disable" in config and "discovery" (e.g. not listing the class)?
94. I see ObservabilityProbe mentioned. What problem does it solve (e.g. for metrics or logging)?
95. The docs say "Business → listener". What counts as "business" so I don't put it in request-wide components?
96. What is @app and where does the app scan for classes that run on every request (concrete path or alias)?

**Edge cases / behavior**

97. Can a method that handles request lifecycle events receive multiple event types (e.g. union type) like a listener
    can?
98. If I set the response body in a RequestBegin listener and throw StopFlow, does the request end there?
99. Two components subscribe to RequestEnd. In what order do they run — config order or registration order?
100. I want my code to run only for requests to /api/*. Is that supported for components that run on every request, or
     do I need something else?
101. Can I add a component that runs on every request and lives only in the app (not in a package) without touching
     composer.json?
102. Does the framework create one instance of my class per request or reuse the same instance?
103. My class that runs on every request needs the current user. Is that available in the event or do I inject a
     service?
104. I need to run code after the response has been sent to the client. Is there an event for that?
105. Can I remove a default component from the pipeline without forking the package (e.g. set to null)?
106. The app discovers classes by a certain file pattern and suffix. If I use that suffix (e.g. *X), will my class be
     discovered?
107. Config has name => FQCN. Can I use the same name as a package's default to override it?
108. I added an entry in that config but forgot to create the class. When will the app fail — boot, first request, or
     when?
109. Do those components run for OPTIONS preflight requests the same as for GET/POST?
110. I want to add something that only runs when the request has a certain header. Do I do that inside the handler
     method or is there a way to "attach" to a subset of requests?
111. What happens if that component throws an exception — does the framework still send a response?

**Quick wins / one-step answers**

112. I need to add a custom response header on every response. One event name to subscribe to?
113. I need to disable the framework's access log. One config change?
114. I need to see all HTTP lifecycle event class names. One command (with filter)?
115. I need to see what runs on every request and in what order. One command?
116. I'm new here. In one sentence: when do I use request-wide lifecycle components vs listener vs interceptor?
