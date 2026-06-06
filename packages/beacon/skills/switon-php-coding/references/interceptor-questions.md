# Interceptor reference — questions

**Generate:** switon-meta-skill::questions-author. **Verify:** switon-meta-skill::verify-reference.

**1. Basic**
1.1. You need logic around a single controller action only. Which scope is correct?
1.2. You need logic for every request regardless of action. Which scope is correct?
1.3. You need to add per‑action behavior to a CLI command action. Where is it attached?
1.4. You need the same action‑level behavior on three actions in one controller. What is the supported way to apply it?
1.5. You put the attribute on the controller class expecting it to affect all actions. Will it run? What level is
supported?
1.6. You see `#[ETag]` on a controller action. What does that imply about how the behavior is executed?
1.7. You need to add a header to every HTTP response for all routes. Which mechanism should you use instead of
action‑scoped logic?
1.8. You’re unsure whether to use event listeners or action‑level behavior for a concern. What’s the key scope
difference?
1.9. Add rate limiting to a single controller action only. Where do you attach the logic?
1.10. You want transactional behavior around a method. Which framework mechanism provides it?
1.11. You need to bypass action‑level behavior for one special call. What option controls this?
1.12. You need to alter an action’s return value during the around‑call flow. Which phase allows that?
1.13. Two action‑level attributes are applied to the same method. How is their order determined?
1.14. Two wrappers A then B are declared. The action throws. Which exception handler runs first?
1.15. You need Request/Response inside action‑level logic. What base class or injection path gives access?
1.16. You want the same behavior around a service method (not a controller action). What must be true about how it’s
invoked?
1.17. You need per‑request mutable state shared across pre/after. Where should it live?
1.18. You need behavior that should apply only to HTTP actions, not CLI. What choice signals that intent?
1.19. You’re migrating from endpoint “filters/guards”. What is the local equivalent and how is it attached?
1.20. You’re migrating from a global request stack handling both auth and per‑action checks. Which concerns stay global
vs
action‑scoped here?
1.21. You need to confirm action‑level attributes are method‑level only. What do you check?

**2. Intermediate**
2.1. A wrapper around a controller action only runs when invoked through a specific framework path. What call path is
required?
2.2. Where is the order of before/after/exception behavior defined?
2.3. Returning `false` from the pre step didn’t stop execution. How should you block execution instead?
2.4. Your action‑scoped attribute never fires. Which `Attribute::TARGET_*` must it declare?
2.5. The behavior runs for controller actions but not for a direct service call. What’s the likely cause?
2.6. Do action attributes get a fresh instance per invocation or can they be reused? Justify from the call flow.
2.7. You add constructor parameters to an action attribute, but runtime values always look default. Where are the
arguments supposed to be passed?
2.8. Are attribute arguments resolved from container services or used as raw values? Where is that decision made?
2.9. CLI actions are invoked through a different invoker than HTTP. Does that path still apply action attributes?
Justify from the call chain.
2.10. You want to know whether action‑level behavior runs before or after argument resolution. What call sequence should
you check?
2.11. You need to know if the pre phase can mutate action parameters. How would you determine this from the invocation
flow?
2.12. You suspect attribute discovery depends on interface match rather than exact class. What reflection filter
indicates this?
2.13. You need to confirm whether class‑level attributes are scanned or not. What reflection query is used?
2.14. You suspect action‑level behavior is disabled for one call. Where is the flag read, and what is the default?
2.15. You need to confirm action‑level behavior is skipped for non‑method callables. Where does the framework branch on
callable type?
2.16. You want to know how the return value becomes mutable in the after phase. What passing mechanism makes this
possible?
2.17. You want to identify where attribute instances are constructed. Which method in the call chain is responsible?
2.18. The exception path runs handlers in a different order than the success path. Where is the reversal applied?
2.19. You need to know if action attributes are applied for methods invoked by `Switon\Invoking\InvokerInterface`. What
delegation path should you follow?
2.20. You see `#[ETag]` on an action. How would you trace from that attribute to runtime behavior without search?
2.21. You want to know whether action‑level behavior is discoverable by tools/inspection. Where would you look?
2.22. You want the same behavior around a job handler method. What must the call path use?
2.23. Two attributes with different constructor args are applied to the same action. How does the framework ensure each
instance gets its own args?
2.24. You want to know whether action attributes can be stacked with other PHP attributes without interference. What in
the reflection logic tells you this?
2.25. You want to confirm the minimum declarations required for a new action attribute to be discovered and executed.
What are those declarations?

**3. Advanced**
3.1. You suspect attribute discovery uses interface matching rather than exact class names. Explain the matching rule
based on the reflection flags.
3.2. You want to trace how attribute arguments are extracted and passed into instances. Which step performs that
conversion?
3.3. You want to locate the exact point where attributes are converted into runtime objects. What object is responsible?
3.4. You need to verify that exception handlers run in reverse order. What control flow construct shows this?
3.5. You want to know whether action‑level behavior can be disabled per call. Where in the call chain is that flag
passed?
3.6. You need to prove that action‑level behavior does not run for direct method calls. What evidence in the call flow
shows this?
3.7. You need to confirm action‑level behavior applies in both HTTP and CLI paths. Compare the call chains to justify.
3.8. You need to confirm action‑level behavior applies only to method callables. Which branch in the invocation flow
enforces this?
3.9. You want to confirm instances are created per invocation rather than reused. What in the flow indicates a new
instance each time?
3.10. You are mapping a decorator‑based system from another language. What is the local equivalent here, and what
limitation should you note?
3.11. You are mapping a global pipeline model to action‑level behavior. When should you avoid action‑level logic?
3.12. You need to decide whether request‑level state should live on the Request object or a request‑scoped service. What
lifecycle clues decide?
3.13. You want to find the exact API call that collects attributes from a method. Which reflection API is used?
3.14. You need to confirm the attribute target is METHOD by inspecting existing attributes. Where is that declared?
3.15. You want to trace where DI/autowiring happens for attribute instances. Which container step handles it?
3.16. You want to know if action‑level behavior still applies when invoked through a wrapper invoker. Trace the
delegation chain.
3.17. You need to determine whether attribute instances are reused across multiple actions in the same request. What
code indicates they are not?
3.18. You want to know which component chooses the invocation path for controller actions. What call flow is
responsible?
3.19. You want to apply action‑level behavior to a service method and keep it active when called from a controller. What
must the call path do?
3.20. You want to know whether action‑level behavior is applied to closures. Where is the callable type check?

**4. From other PHP frameworks**
4.1. In Laravel, you previously used route stack hooks for per-route cross-cutting concerns. Which Switon construct is
the
closest equivalent for per-action logic, and how is it attached?
4.2. In Symfony, you used kernel event subscribers to wrap requests globally. When porting that code, which parts should
stay as global filter-like mechanisms, and which should move to action-level behavior?
4.3. You are used to Laravel’s controller hook stack that can be applied to specific actions via method lists. How do
you express the same per-action selection in Switon?
4.4. In your previous framework, you used annotations on controller classes to apply behavior to all actions. How does
Switon’s action-scoped model differ, and what should you look for in reflection code to confirm?
4.5. You are migrating from a system where decorators wrap service methods directly. Under what condition will Switon’s
action-level behavior correctly wrap those methods when called from controllers?
4.6. A teammate expects adding an attribute to a base controller class will behave like a global filter in their old
framework. How would you design a question to test whether they understand the actual supported scope here?
4.7. In your previous framework, you used one HTTP request stack for both logging and small per-action tweaks. After
migration,
which concerns should move to events and which to action-level behavior, and why?
4.8. You are mapping Symfony’s `@IsGranted` security annotations on controller actions. What is the closest Switon
construct for this, and how is it expressed and attached?
4.9. A Laravel team used both route hooks and a global request stack for auth. When porting, how would you split global
auth checks, per-action guards, and post-action auditing across filters, action-level behavior, and events?
