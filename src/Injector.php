<?php

declare(strict_types=1);

namespace Ray\Compiler;

use function crc32;
use Doctrine\Common\Cache\Cache;
use function is_callable;
use Ray\Compiler\Annotation\Compile;
use Ray\Di\AbstractModule;
use Ray\Di\AssistedModule;
use Ray\Di\Exception\Unbound;
use Ray\Di\Injector as RayInjector;
use Ray\Di\InjectorInterface;

final class Injector
{
    /**
     * @var array<InjectorInterface>
     */
    private static $instances;

    private function __construct()
    {
    }

    /**
     * @param class-string|callable():AbstractModule $initialModule
     * @param array<class-string<AbstractModule>>    $contextModules
     * @param array<class-string>                    $savedSingletons
     */
    public static function getInstance($initialModule, array $contextModules, string $scriptDir, Cache $cache, array $savedSingletons = []) : InjectorInterface
    {
        $injectorId = crc32($scriptDir);
        if (isset(self::$instances[$injectorId])) {
            return self::$instances[$injectorId];
        }
        /** @var ?InjectorInterface $cachedInjector */
        $cachedInjector = $cache->fetch(InjectorInterface::class);
        $injector = $cachedInjector instanceof InjectorInterface ? $cachedInjector : self::factory($initialModule, $contextModules, $cache, $scriptDir, $savedSingletons);
        self::$instances[$injectorId] = $injector;

        return $injector;
    }

    /**
     * @param class-string|callable():AbstractModule $initialModule
     * @param array<class-string<AbstractModule>>    $contextModules
     * @param array<class-string>                    $savedSingletons
     */
    private static function factory($initialModule, array $contextModules, Cache $cache, string $scriptDir, array $savedSingletons) : InjectorInterface
    {
        ! is_dir($scriptDir) && ! @mkdir($scriptDir) && ! is_dir($scriptDir);
        $module = is_callable($initialModule) ? $initialModule() : new $initialModule;
        $module->install(new AssistedModule);
        foreach ($contextModules as $contextModule) {
            /** @var $module AbstractModule */
            $module->install(new $contextModule);
        }
        $rayInjector = new RayInjector($module, $scriptDir);
        /** @var bool $isProd */
        try {
            $isProd = $rayInjector->getInstance('', Compile::class);
        } catch (Unbound $e) {
            $isProd = false;
        }
        $injector = $isProd ? self::getScriptInjector($scriptDir, $module, $rayInjector, $savedSingletons, $cache) : $rayInjector;
        self::saveSingletons($injector, $savedSingletons);

        return $injector;
    }

    /**
     * @param array<class-string> $savedSingletons
     */
    private static function getScriptInjector(string $scriptDir, AbstractModule $module, RayInjector $rayInjector, array $savedSingletons, Cache $cache) : ScriptInjector
    {
        $scriptInjector = new ScriptInjector($scriptDir, function () use ($scriptDir, $module) {
            return new ScriptinjectorModule($scriptDir, $module);
        });
        self::saveSingletons($rayInjector, $savedSingletons);
        $cache->save(InjectorInterface::class, $scriptInjector);

        return $scriptInjector;
    }

    /**
     * @param array<class-string> $savedSingletons
     */
    private static function saveSingletons(InjectorInterface $injector, array $savedSingletons) : void
    {
        foreach ($savedSingletons as $singleton) {
            $injector->getInstance($singleton);
        }
    }
}
