<?php

namespace updg\roadrunner\laravel;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use updg\roadrunner\easy\PSR7IntegrationInterface;

class LaravelIntegration implements PSR7IntegrationInterface
{
    /**
     * Stores the application
     *
     * @var \Illuminate\Foundation\Application|null
     */
    private $_app;

    /**
     * Stores the kernel
     *
     * @var \Illuminate\Contracts\Http\Kernel|\Laravel\Lumen\Application
     */
    private $_kernel;

    /**
     * Laravel Application->register() parameter count
     *
     * @var int
     */
    private $_appRegisterParameters;

    /**
     * @var \Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory
     */
    private $_httpFoundationFactory;

    /**
     * @var \Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory
     */
    private $_psr7factory;

    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->prepareKernel();

        $this->_httpFoundationFactory = new HttpFoundationFactory();
        $this->_psr7factory = new DiactorosFactory();
    }

    /**
     * @inheritdoc
     */
    public function beforeRequest()
    {
    }

    /**
     * @inheritdoc
     */
    public function afterRequest()
    {
        if (method_exists($this->_app, 'getProvider')) {
            //reset debugbar if available
            $this->resetProvider('\Illuminate\Redis\RedisServiceProvider');
            $this->resetProvider('\Illuminate\Cookie\CookieServiceProvider');
            $this->resetProvider('\Illuminate\Session\SessionServiceProvider');
        }
    }

    /**
     * @inheritdoc
     */
    public function shutdown()
    {
    }

    /**
     * Process request received from RoadRunner server
     *
     * @param RequestInterface $request
     * @return ResponseInterface
     */
    public function processRequest(RequestInterface $request): ResponseInterface
    {
        $symfonyRequest = $this->_httpFoundationFactory->createRequest($request);
        $request = \Illuminate\Http\Request::createFromBase($symfonyRequest);

        $response = $this->_kernel->handle($request);

        return $this->_psr7factory->createResponse($response);
    }


    private function prepareKernel()
    {
        // Laravel 5 / Lumen
        $isLaravel = true;
        if (file_exists('bootstrap/app.php')) {
            $this->_app = require_once 'bootstrap/app.php';
            if (substr($this->_app->version(), 0, 5) === 'Lumen') {
                $isLaravel = false;
            }
        }
        // Laravel 4
        if (file_exists('bootstrap/start.php')) {
            $this->_app = require_once 'bootstrap/start.php';
            $this->_app->boot();
            return;
        }
        if (!$this->_app) {
            throw new \RuntimeException('Laravel bootstrap file not found');
        }
        $kernel = $this->_app->make($isLaravel ? 'Illuminate\Contracts\Http\Kernel' : 'Laravel\Lumen\Application');
        $this->_app->afterResolving('auth', function ($auth) {
            $auth->extend('session', function ($app, $name, $config) {
                $provider = $app['auth']->createUserProvider($config['provider']);
                $guard = new SessionGuard($name, $provider, $app['session.store'], null, $app);
                if (method_exists($guard, 'setCookieJar')) {
                    $guard->setCookieJar($this->_app['cookie']);
                }
                if (method_exists($guard, 'setDispatcher')) {
                    $guard->setDispatcher($this->_app['events']);
                }
                if (method_exists($guard, 'setRequest')) {
                    $guard->setRequest($this->_app->refresh('request', $guard, 'setRequest'));
                }
                return $guard;
            });
        });
        $app = $this->_app;
        $this->_app->extend('session.store', function () use ($app) {
            $manager = $app['session'];
            return $manager->driver();
        });


        $this->_kernel = $kernel;
    }

    protected function resetProvider($providerName)
    {
        if (!$this->_app->getProvider($providerName)) {
            return;
        }
        $this->appRegister($providerName, true);
    }

    /**
     * Register application provider
     * Workaround for BC break in https://github.com/laravel/framework/pull/25028
     *
     * @param string $providerName
     * @param bool   $force
     * @throws \ReflectionException
     */
    protected function appRegister($providerName, $force = false)
    {
        if (!$this->_appRegisterParameters) {
            $method = new \ReflectionMethod(get_class($this->_app), 'register');
            $this->_appRegisterParameters = count($method->getParameters());
        }
        if ($this->_appRegisterParameters == 3) {
            $this->_app->register($providerName, [], $force);
        } else {
            $this->_app->register($providerName, $force);
        }
    }
}
