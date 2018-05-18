<?php
/**
 * This file is part of the Ray.Compiler package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace Ray\Compiler;

use Ray\Compiler\Exception\Unbound;
use Ray\Di\AbstractModule;
use Ray\Di\Dependency;
use Ray\Di\InjectorInterface;
use Ray\Di\Name;
use Ray\Di\NullModule;

final class ScriptInjector implements InjectorInterface
{
    const MODULE = '/module.txt';
    const AOP = '/aop.txt';
    const INSTANCE = '%s/%s.php';
    const QUALIFIER = '%s/qualifer/%s-%s-%s';

    /**
     * @var string
     */
    private $scriptDir;

    /**
     * Injection Point
     *
     * [$class, $method, $parameter]
     *
     * @var array
     */
    private $ip;

    /**
     * Singleton instance container
     *
     * @var array
     */
    private $singletons = [];

    /**
     * @var array [[$class,],]
     */
    private $functions;

    /**
     * @var callable
     */
    private $lazyModule;

    /**
     * @var AbstractModule
     */
    private $module;

    /**
     * Saved modules
     *
     * @var array
     */
    private static $saved = [];

    /**
     * @var bool
     */
    private $wakeup = false;

    /**
     * @param string   $scriptDir  generated instance script folder path
     * @param callable $lazyModule callable variable which return AbstractModule instance
     */
    public function __construct($scriptDir, callable $lazyModule = null)
    {
        $this->scriptDir = $scriptDir;
        $this->lazyModule = $lazyModule ?: function () {
            return new NullModule;
        };
        $this->registerLoader();
        $prototype = function ($dependencyIndex, array $injectionPoint = []) {
            $this->ip = $injectionPoint;
            list($prototype, $singleton, $injection_point, $injector) = $this->functions;

            return require $this->getInstanceFile($dependencyIndex);
        };
        $singleton = function ($dependencyIndex, array $injectionPoint = []) {
            if (isset($this->singletons[$dependencyIndex])) {
                return $this->singletons[$dependencyIndex];
            }
            $this->ip = $injectionPoint;
            list($prototype, $singleton, $injection_point, $injector) = $this->functions;

            $instance = require $this->getInstanceFile($dependencyIndex);
            $this->singletons[$dependencyIndex] = $instance;

            return $instance;
        };
        $injection_point = function () use ($scriptDir) {
            return new InjectionPoint(
                new \ReflectionParameter([$this->ip[0], $this->ip[1]], $this->ip[2]),
                $scriptDir
            );
        };
        $injector = function () {
            return $this;
        };
        $this->functions = [$prototype, $singleton, $injection_point, $injector];
        self::$saved = [];
    }

    public function __sleep()
    {
        $this->saveModule();

        return ['scriptDir', 'singletons'];
    }

    public function __wakeup()
    {
        $this->__construct(
            $this->scriptDir,
            function () {
                return $this->getModule();
            }
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getInstance($interface, $name = Name::ANY)
    {
        $dependencyIndex = $interface . '-' . $name;
        if (isset($this->singletons[$dependencyIndex])) {
            return $this->singletons[$dependencyIndex];
        }
        list($prototype, $singleton, $injection_point, $injector) = $this->functions;
        $instance = require $this->getInstanceFile($dependencyIndex);
        /* @var bool $is_singleton */
        $isSingleton = (isset($is_singleton) && $is_singleton) ? true : false;
        if ($isSingleton) {
            $this->singletons[$dependencyIndex] = $instance;
        }

        return $instance;
    }

    public function clear()
    {
        $unlink = function ($path) use (&$unlink) {
            foreach (\glob(\rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*') as $file) {
                \is_dir($file) ? $unlink($file) : \unlink($file);
                @\rmdir($file);
            }
        };
        $unlink($this->scriptDir);
    }

    public function isSingleton($dependencyIndex) : bool
    {
        $module = $this->getModule();
        /** @var AbstractModule $module */
        $container = $module->getContainer()->getContainer();
        if (! isset($container[$dependencyIndex])) {
            throw new Unbound($dependencyIndex);
        }
        $dependency = $container[$dependencyIndex];
        $isSingleton = $dependency instanceof Dependency ? (new PrivateProperty)($dependency, 'isSingleton') : false;

        return $isSingleton;
    }

    private function getModule() : AbstractModule
    {
        return \file_exists($this->scriptDir . self::MODULE) ? \unserialize(\file_get_contents($this->scriptDir . self::MODULE)) : new NullModule;
    }


    /**
     * Return compiled script file name
     */
    private function getInstanceFile(string $dependencyIndex) : string
    {
        $file = \sprintf(self::INSTANCE, $this->scriptDir, \str_replace('\\', '_', $dependencyIndex));
        if (\file_exists($file)) {
            return $file;
        }
        if (! $this->module instanceof AbstractModule) {
            $this->module = ($this->lazyModule)();
        }
        $isFirstCompile = ! \file_exists($this->scriptDir . self::AOP);
        if ($isFirstCompile) {
            (new DiCompiler(($this->lazyModule)(), $this->scriptDir))->savePointcuts($this->module->getContainer());
            $this->saveModule();
        }
        (new OnDemandCompiler($this, $this->scriptDir, $this->module))($dependencyIndex);

        return $file;
    }

    private function saveModule()
    {
        $isNotUnserializedAndWriteOnce = ! \in_array($this->scriptDir, self::$saved, true) && ! $this->wakeup;
        if ($isNotUnserializedAndWriteOnce) {
            self::$saved[] = $this->scriptDir;
            $module = $this->module instanceof AbstractModule ? $this->module : ($this->lazyModule)();
            \file_put_contents($this->scriptDir . self::MODULE, \serialize($module));
        }
    }

    /**
     * Register autoload for AOP file
     */
    private function registerLoader()
    {
        \spl_autoload_register(function ($class) {
            $file = \sprintf('%s/%s.php', $this->scriptDir, $class);
            if (\file_exists($file)) {
                // @codeCoverageIgnoreStart
                require $file;
                // codeCoverageIgnoreEnd
            }
        });
    }
}
