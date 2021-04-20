<?php

declare(strict_types=1);

namespace GetResponse\GetResponseIntegration\Controller\Api;

use GetResponse\GetResponseIntegration\Domain\Magento\FacebookAdsPixel;
use GetResponse\GetResponseIntegration\Domain\Magento\FacebookBusinessExtension;
use GetResponse\GetResponseIntegration\Domain\Magento\FacebookPixel;
use GetResponse\GetResponseIntegration\Domain\Magento\LiveSynchronization;
use GetResponse\GetResponseIntegration\Domain\Magento\PluginMode;
use GetResponse\GetResponseIntegration\Domain\Magento\Repository;
use GetResponse\GetResponseIntegration\Domain\Magento\RequestValidationException;
use GetResponse\GetResponseIntegration\Domain\Magento\WebEventTracking;
use GetResponse\GetResponseIntegration\Domain\Magento\WebForm;
use GetResponse\GetResponseIntegration\Domain\SharedKernel\Scope;
use GetResponse\GetResponseIntegration\Helper\MagentoStore;
use GetResponse\GetResponseIntegration\Presenter\Api\Section\General;
use GetResponse\GetResponseIntegration\Presenter\Api\ConfigurationPresenter;
use GetResponse\GetResponseIntegration\Presenter\Api\Section\Store;
use Magento\Framework\App\Cache\Manager;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request;

/**
 * @api
 */
class ConfigurationController extends ApiController
{
    private const MODULE_NAME = 'GetResponse_GetResponseIntegration';

    private $moduleList;
    private $request;
    private $cacheManager;

    public function __construct(
        Repository $repository,
        MagentoStore $magentoStore,
        ModuleListInterface $moduleList,
        Request $request,
        Manager $cacheManager
    ) {
        parent::__construct($repository, $magentoStore);
        $this->moduleList = $moduleList;
        $this->request = $request;
        $this->cacheManager = $cacheManager;
    }

    /**
     * @return ConfigurationPresenter
     */
    public function list(): ConfigurationPresenter
    {
        $versionInfo = $this->moduleList->getOne(self::MODULE_NAME);
        $pluginVersion = $versionInfo['setup_version'] ?? '';

        $pluginMode = PluginMode::createFromRepository($this->repository->getPluginMode());
        $stores = [];

        foreach ($this->magentoStore->getMagentoStores() as $storeId => $storeName) {
            $scope = new Scope($storeId);
            $stores[] = $pluginMode->isNewVersion() ? $this->createStore($scope) : $this->createEmptyStoreConfiguration($scope);
        }

        return new ConfigurationPresenter(
            new General($pluginVersion, $pluginMode),
            $stores
        );
    }

    /**
     * @return void
     */
    public function delete(): void
    {
        $pluginMode = PluginMode::createFromRepository($this->repository->getPluginMode());

        if (false === $pluginMode->isNewVersion()) {
            return;
        }

        foreach ($this->magentoStore->getMagentoStores() as $storeId => $storeName) {
            $this->repository->clearConfiguration($storeId);
        }

        $this->cacheManager->clean(['config']);
    }

    /**
     * @throws WebapiException
     * @return void
     * @param string $scope
     */
    public function update(string $scope): void
    {
        try {
            $this->verifyPluginMode();
            $this->verifyScope($scope);

            $facebookPixel = FacebookPixel::createFromRequest($this->request->getBodyParams());
            $facebookAdsPixel = FacebookAdsPixel::createFromRequest($this->request->getBodyParams());
            $facebookBusinessExtension = FacebookBusinessExtension::createFromRequest($this->request->getBodyParams());
            $webForm = WebForm::createFromRequest($this->request->getBodyParams());
            $webEventTracking = WebEventTracking::createFromRequest($this->request->getBodyParams());
            $liveSynchronization = LiveSynchronization::createFromRequest($this->request->getBodyParams());

            $this->repository->saveFacebookPixelSnippet($facebookPixel, $scope);
            $this->repository->saveFacebookAdsPixelSnippet($facebookAdsPixel, $scope);
            $this->repository->saveFacebookBusinessExtensionSnippet($facebookBusinessExtension, $scope);
            $this->repository->saveWebformSettings($webForm, $scope);
            $this->repository->saveWebEventTracking($webEventTracking, $scope);
            $this->repository->saveLiveSynchronization($liveSynchronization, $scope);

            $this->cacheManager->clean(['config']);

        } catch (RequestValidationException $e) {
            throw new WebapiException(new Phrase($e->getMessage()));
        }
    }

    private function createStore(Scope $scope): Store
    {
        return new Store(
            $scope,
            FacebookPixel::createFromRepository(
                $this->repository->getFacebookPixelSnippet($scope->getScopeId())
            ),
            FacebookAdsPixel::createFromRepository(
                $this->repository->getFacebookAdsPixelSnippet($scope->getScopeId())
            ),
            FacebookBusinessExtension::createFromRepository(
                $this->repository->getFacebookBusinessExtensionSnippet($scope->getScopeId())
            ),
            WebForm::createFromRepository(
                $this->repository->getWebformSettings($scope->getScopeId())
            ),
            WebEventTracking::createFromRepository(
                $this->repository->getWebEventTracking($scope->getScopeId())
            ),
            LiveSynchronization::createFromRepository(
                $this->repository->getLiveSynchronization($scope->getScopeId())
            )
        );
    }

    private function createEmptyStoreConfiguration(Scope $scope): Store
    {
        return new Store(
            $scope,
            new FacebookPixel(false, ''),
            new FacebookAdsPixel(false, ''),
            new FacebookBusinessExtension(false, ''),
            new WebForm(false, '', '', ''),
            new WebEventTracking(false, false, ''),
            new LiveSynchronization(false, '', '')
        );
    }
}
