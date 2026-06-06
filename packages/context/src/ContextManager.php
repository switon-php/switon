<?php

declare(strict_types=1);

namespace Switon\Context;

use ArrayObject;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Switon\Context\Event\ChildContextCreated;
use Switon\Context\Event\ChildContextInherited;
use Switon\Context\Event\ChildContextPropagated;
use Switon\Context\Exception\ContextException;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ContextAware;
use Switon\Core\ContextIsolated;
use Switon\Core\Lazy;
use Switon\Core\Runtime;
use Swoole\Coroutine;

use function spl_object_id;

/**
 * Manages context-scoped mutable state for <code>ContextAware</code> components.
 *
 * Use when <code>ContextAware</code> components need mutable state that must not leak across runtime scopes.
 *
 * Road-signs:
 * - <code>findContext()</code> reads the declared <code>getContext()</code> return type
 * - <code>createContext()</code> validates loadable zero-argument context classes
 * - <code>getContext()</code> chooses non-coroutine cache / coroutine inheritance / root fallback
 * - <code>ContextIsolated</code> creates a fresh child-coroutine context
 * - child-sharing events: inherited / created / propagated
 *
 * Runtime behavior:
 * - Non-coroutine runtime: contexts are cached in <code>$contexts</code> by object ID.
 * - Coroutine runtime: contexts are stored in coroutine context, with parent inheritance by default.
 * - Coroutine enabled without current coroutine context: contexts fall back to <code>$roots</code>.
 * - <code>ContextIsolated</code>: child coroutine always gets a fresh context instance.
 *
 * Guidance: Keep context classes constructor-free and loadable; context state belongs in the returned context object, not in the owner service.
 *
 * @see \Switon\Context\ServiceProvider
 * @see \Switon\Core\ContextManagerInterface
 * @see \Switon\Context\ContextManagerInterface Helper operations
 * @see \Switon\Core\ContextAware Context owner boundary
 * @see \Switon\Core\ContextIsolated Isolation marker
 * @see \Switon\Context\Event\ChildContextInherited Parent-sharing path
 * @see \Switon\Context\Event\ChildContextCreated Isolation path
 * @see \Switon\Context\Event\ChildContextPropagated Propagation path
 * @see \Switon\Context\Exception\ContextException
 */
class ContextManager implements ContextManagerInterface
{
    #[Autowired] protected EventDispatcherInterface|Lazy $eventDispatcher;

    /** @var array<class-string, class-string> Context class cache (component class => context class). */
    protected array $classes = [];

    /** @var array<int, object> Non-coroutine context cache by owner object ID. */
    protected array $contexts = [];

    /** @var array<int, object> Root fallback cache when coroutine mode is enabled without current coroutine context. */
    protected array $roots = [];

    /**
     * Resolves context class from <code>getContext()</code> return type.
     *
     * The declared return type must be one loadable named type. Concrete
     * instantiability is validated later in <code>createContext()</code>.
     *
     * @return class-string
     *
     * @throws ContextException When the return type is builtin or not one loadable named type.
     */
    public function findContext(ContextAware $object): string
    {
        $class = $object::class;
        if (($context = $this->classes[$class] ?? null) === null) {
            $method = new ReflectionMethod($object, 'getContext');
            $returnType = $method->getReturnType();

            if (!$returnType instanceof ReflectionNamedType) {
                ContextException::raise(
                    '{class}::getContext() must declare a single context class return type, got "{type}"',
                    ['class' => $class, 'type' => (string)$returnType]
                );
            }

            if ($returnType->isBuiltin()) {
                ContextException::raise(
                    '{class}::getContext() must return a context class, got builtin type "{type}"',
                    ['class' => $class, 'type' => $returnType->getName()]
                );
            }

            $context = $returnType->getName();
            if (!class_exists($context) && !interface_exists($context)) {
                ContextException::raise(
                    '{class}::getContext() must return a loadable context class, got "{type}"',
                    ['class' => $class, 'type' => $context]
                );
            }

            $context = $this->classes[$class] = $context;
        }

        return $context;
    }

    /**
     * Creates a new context instance for the given component.
     *
     * The resolved context must be an instantiable concrete class, not an
     * interface or abstract class, and it must not require constructor arguments.
     *
     * @throws ContextException When the resolved context cannot be instantiated directly.
     */
    public function createContext(ContextAware $object): object
    {
        $context = $this->findContext($object);
        $reflection = new ReflectionClass($context);

        if (!$reflection->isInstantiable()) {
            ContextException::raise(
                '{class}::getContext() must return an instantiable context class, got "{type}"',
                ['class' => $object::class, 'type' => $context]
            );
        }

        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            ContextException::raise(
                '{class}::getContext() must return a context class that can be created without constructor arguments, got "{type}"',
                ['class' => $object::class, 'type' => $context]
            );
        }

        return $reflection->newInstance();
    }

    /**
     * Returns request/coroutine-scoped context for one component instance.
     *
     * In coroutine mode, child coroutines inherit parent context unless the
     * context class implements <code>ContextIsolated</code>.
     *
     * @param ContextAware $object
     * @param int $cid Internal coroutine ID (0 = current coroutine)
     *
     * @throws ContextException
     */
    public function getContext(ContextAware $object, int $cid = 0): mixed
    {
        $object_id = spl_object_id($object);

        if (Runtime::isCoroutineEnabled()) {
            if ($ao = Coroutine::getContext($cid)) {
                if (($context = $ao[$object_id] ?? null) === null) {
                    $parent_cid = Coroutine::getPcid();
                    if (!is_int($parent_cid) || $parent_cid === -1) {
                        return $ao[$object_id] = $this->createContext($object);
                    }

                    $context_class = $this->findContext($object);
                    if (is_subclass_of($context_class, ContextIsolated::class)) {
                        $context = $ao[$object_id] = $this->createContext($object);
                        $this->eventDispatcher->dispatch(new ChildContextCreated($object::class, get_class($context), Coroutine::getCid(), $parent_cid));
                        return $context;
                    }

                    $parent_ao = Coroutine::getContext($parent_cid) ?? new ArrayObject();
                    $current_cid = Coroutine::getCid();

                    if (($context = $parent_ao[$object_id] ?? null) !== null) {
                        $ao[$object_id] = $context;
                        $this->eventDispatcher->dispatch(new ChildContextInherited($object::class, get_class($context), $current_cid, $parent_cid));
                        return $context;
                    }

                    $context = $ao[$object_id] = $this->createContext($object);
                    $parent_ao[$object_id] = $context;
                    $this->eventDispatcher->dispatch(new ChildContextPropagated($object::class, get_class($context), $current_cid, $parent_cid));
                    // Propagate up to root so all ancestors and their other children share
                    $ancestor_cid = $parent_cid;
                    while (true) {
                        $pid = Coroutine::getPcid($ancestor_cid);
                        if (!is_int($pid) || $pid === -1) {
                            break;
                        }
                        $ancestor_ao = Coroutine::getContext($pid);
                        if ($ancestor_ao === null || isset($ancestor_ao[$object_id])) {
                            break;
                        }
                        $ancestor_ao[$object_id] = $context;
                        $ancestor_cid = $pid;
                    }
                }
                return $context;
            } elseif (($context = $this->roots[$object_id] ?? null) === null) {
                return $this->roots[$object_id] = $this->createContext($object);
            } else {
                return $context;
            }
        } else {
            if (($context = $this->contexts[$object_id] ?? null) === null) {
                $context = $this->contexts[$object_id] = $this->createContext($object);
            }
            return $context;
        }
    }

    /**
     * Clears the per-object cache used outside coroutine storage.
     *
     * Rarely needed in normal application code. Coroutine-bound contexts are released by coroutine lifecycle. Root fallback contexts in <code>$roots</code> are retained.
     */
    public function resetContexts(): void
    {
        $this->contexts = [];
    }
}
