# Config reference — questions

**Generate:** switon-meta-skill::questions-author. **Verify:** switon-meta-skill::verify-reference.

**Flow:** Run in a **subagent**: feed it this file + the reference(s). It may use only references and **read** source
files; **no grep/search** of the codebase. **Verification:** Prefer a **verification subagent**; use actual code as
truth. May read code files; no grep or other code search. Verify every answer; list wrong answers or reference gaps.

**Mix:** Some answerable from the reference; others need inference or code (so verification is meaningful). Do not
mention specific reference files in the question text.

**1. Basic**
1.1. I need to add a new app config for a component. Where do I put the file and what should the top-level key be?
1.2. I'm adding a config option that reads from the environment. What do I use in the config array and what files must I
touch?
1.3. I want to know the valid config keys for an interface (e.g. the DB client). What do I run or use?
1.4. The component docs don't list all options. Where is the source of truth for which keys I can put under an
interface's config?
1.5. I added a new env variable for the app. Besides .env, which other file should I update so others know what to
configure?
1.6. For a new config file, how do I choose the filename from the interface name?

**2. Value shapes**
2.1. I'm setting a string option in config. Is it passed through as-is or does the framework transform it?
2.2. The implementation has an array property with a non-empty default. I want to override one key and remove another.
How do I do that in config?
2.3. The implementation has an array property with no default. I pass an array in config. Is it merged with anything or
used as-is?
2.4. I need to fully replace the default array (no merge). What value type in config gives me that?
2.5. The implementation has `#[Autowired(instances: true)]` on an array. What can each array value be (string vs array)?
2.6. I'm injecting a named instance (e.g. a specific cache) into a component's config. What syntax do I use in the
config value?
2.7. I want to inject an object that's not in the container — I'll define it inline in config. What shape does the
config value have (class key, params)?
2.8. For an object property, I omitted the 'class' key in the inline config. How does the framework resolve the
implementation?

**3. Class and implementation**
3.1. Config top-level key is an interface FQCN. Which concrete class is instantiated for that config — where is that
mapping defined (binding, ServiceProvider, or config)?
3.2. I want the "default" instance to use RedisCache and the "sessions" instance to use FileCache (different
implementation classes per Factory entry). How do I set the class for each entry?
3.3. I want to replace a package's default implementation for an interface with my own class. Where do I configure
that (binding vs config key)?
3.4. In inline config I passed an array with 'class' => MyCustomClass::class and params. Does the framework use that
class or the container's bound implementation for the interface?
3.5. The implementation class has constructor parameters. Can I pass them via config (e.g. inline object with params),
and does that affect how the implementation class is chosen?
3.6. We use Factory with two entries; both use the same implementation class but different property values. Do we need
to set 'class' in each entry or only once?
3.7. I added a new config file with my own interface as the top-level key. Who provides the implementation class for
that interface — do I have to bind it somewhere?
3.8. A component's config accepts an object property. I want to pass a subclass of the expected type. Do I use a
string (FQCN) or inline array with 'class'?

**4. Multi-instance (Factory)**
4.1. I need several instances of the same interface (e.g. default cache + user cache). Which class do I use and where do
I register them?
4.2. In the Factory array, what should the keys be — arbitrary names or something derived from the app?
4.3. How do I discover which named instances (keys) to add to a Factory for an interface?
4.4. I'm defining a named instance in a Factory and not changing the implementation class. Do I need to set the 'class'
key in that entry?
4.5. In another component's config I want to inject the "user" cache instance that's defined in a Factory. What do I set
the property to?
4.6. Is there a conventional key name for the "default" instance when using Factory?
4.7. What is the "default" entry in a Factory for — when is that instance used versus a named one?
4.8. A component injects the interface without a name (e.g. just CacheInterface, not #user). Which Factory entry is used
to build that instance?
4.9. We added a Factory with two entries, "default" and "sessions". We did not provide any config for "sessions" (empty
array or omitted). The implementation has a required property. What happens at runtime and why is that intentional?
4.10. We have a Factory and one named entry has no config (or empty config) but the implementation has non-nullable
properties. Will it fail at bootstrap or only when that instance is first requested?
4.11. Multiple components need different instances of the same interface (e.g. one cache for sessions, one for API). How
do we define those instances and wire each component to the right one?
4.12. Can we have only named instances in a Factory (e.g. "sessions" and "user") and no "default" entry? If so, what
happens when something injects the interface without #name?
4.13. We reference #name in config but that name is not defined in the Factory for that interface. What happens?
4.14. Why would the framework deliberately error when resolving a Factory entry that has no config and the
implementation has required properties (instead of using defaults)?
4.15. We need three named instances (e.g. default, sessions, rate-limit); one of them must use a different
implementation class. Where do we set 'class' for that entry only?
4.16. Component A should get the default cache, Component B should get the "sessions" cache. Where do we specify which
component gets which — in each component's config or elsewhere?
4.17. Can the same interface's Factory entries be split across multiple config files, or must all named entries live
under one interface key in one file?
4.18. We have two config files: one defines a Factory with "default" and "sessions", another references #sessions. What
constraint must hold (same interface, same file, or something else)?

**5. Verification and troubleshooting**
5.1. User says they added a config key but it has no effect. What do I check first (valid keys, file, key shape)?
5.2. User removed a key from config but the app still behaves as if it's set. Could merge semantics be involved? What
removes a key in a merged array?
5.3. I need to verify that a config file has no unknown keys and that each value matches the expected type. What
procedure do I follow (which tool, then what)?
5.4. A component expects an object; we passed a string that's not a service id. What should the config value look like
for an object?

**6. Flow / multi-class**
6.1. From "config file on disk" to "property set on the implementation instance", which class applies the config values
to the object?
6.2. Config keys are bound to implementation properties. Where does the implementation class come from when the config
key is an interface FQCN?
6.3. We have two config files: one that defines a Factory with named instances, and another that references one of those
names with #name. What constraint must hold for that to work?
6.4. I added a new #[Autowired] property to an implementation class. What do I need to do in app config for that option
to be configurable (and how do I know the key name)?

**7. From other PHP frameworks**
7.1. In Laravel we use config('database.connections.mysql'). How do we read config or pass it to services here — is
there a config() helper?
7.2. In Laravel we have config/database.php with 'connections' => [ 'mysql' => [...], 'redis' => [...] ]. We need
multiple instances of the same interface (e.g. two caches). How do we do that here?
7.3. In Symfony we have parameters in config and %env(APP_URL)%. Here how do we use env vars in config files and what
files do we touch for a new env key?
7.4. In Symfony we bind service arguments from config (e.g. arguments: [$param]). Here how does config get into a
service — constructor, property, or both?
7.5. In Laravel we inject ConfigRepository or use config() in classes. Here if a class needs config, how does it get
it — inject something or call a helper?
7.6. We came from a framework where we had a single config array and config('key'). Here config is keyed by interface.
How do we add a new "section" of config (e.g. for our custom feature)?
7.7. In Symfony we have services.yaml with service id and arguments. Here config is keyed by interface FQCN. How do we
know which config keys (under that key) are valid — docs or something else?
7.8. In Laravel we have config/cache.php with 'stores' => [ 'file' => [...], 'redis' => [...] ]. We need named instances
of an interface. What's the Switon equivalent (which class, which config shape)?
7.9. We used YAML or env() in another PHP framework. Here config is PHP arrays. How do we override one option for a
built-in component without forking the package?
7.10. In Symfony we use service bindings and parameters. Here is there a central "parameters" or config service we
inject, or do we get config per interface?
