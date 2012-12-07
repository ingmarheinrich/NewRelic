<?php
namespace NewRelic;

use Zend\Mvc\MvcEvent;
use Zend\ModuleManager\Feature\AutoloaderProviderInterface;
use Zend\ModuleManager\Feature\ConfigProviderInterface;
use Zend\ModuleManager\Feature\ServiceProviderInterface;
use Zend\ServiceManager\ServiceManager;
use Zend\Stdlib\ResponseInterface as Response;
use NewRelic\Service\LoggerFactory;
use NewRelic\Service\LogWriterFactory;
use NewRelic\Service\ClientFactory;

class Module implements
    ConfigProviderInterface,
    ServiceProviderInterface,
    AutoloaderProviderInterface
{
    /**
     * @var ServiceManager
     */
    protected $ServiceManager;

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getServiceConfig()
    {
        return array(
            'factories' => array(
                'NewRelicClient'    => new ClientFactory,
                'NewRelicLogWriter' => new LogWriterFactory,
                'logger'            => new LoggerFactory,
            ),
        );
    }

    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }

    public function onBootstrap(MvcEvent $e)
    {
        $application = $e->getApplication();
        $this->serviceManager = $application->getServiceManager();

        $client = $this->getClient();

        if (!$client->extensionLoaded()) {
            return;
        }

        /* @var $eventManager \Zend\EventManager\EventManager */
        $eventManager = $application->getEventManager();

        $eventManager->attach('route', function(MvcEvent $e) use ($client) {
            $matches = $e->getRouteMatch();
            $route   = $matches->getMatchedRouteName();

            $client->nameTransaction($route);
            \Zend\Debug\Debug::dump($route);
        });

        $eventManager->attach('finish', function(MvcEvent $e) use ($client) {
            $configuration = $client->getConfiguration();

            $client->setAppName(
                $configuration->getApplicationName(),
                $configuration->getLicense()
            );

        }, 100);
        $eventManager->attach('finish', array($this, 'initBrowserTiming'), 100);
    }

    public function initBrowserTiming(MvcEvent $e)
    {
        $client = $this->getClient();
        $configuration = $client->getConfiguration();

        if ($configuration->getBrowserTimingEnabled()) {
            ini_set(
                'newrelic.browser_monitoring.auto_instrument',
                $configuration->getBrowserTimingAutoInstrument()
            );

            if (!$configuration->getBrowserTimingAutoInstrument()) {
                $response = $e->getResponse();
                $content = $response->getBody();

                $client = $this->getClient();

                $browserTimingHeader = $client->getBrowserTimingHeader();
                $browserTimingFooter = $client->getBrowserTimingFooter();

                $content = str_replace('<head>', '<head>' . $browserTimingHeader, $content);
                $content = str_replace('</body>', $browserTimingFooter . '</body>', $content);

                $response->setContent($content);
            }
        }
    }

    /**
     * @return \NewRelic\Client
     */
    public function getClient()
    {
        return $this->serviceManager->get('NewRelicClient');
    }
}