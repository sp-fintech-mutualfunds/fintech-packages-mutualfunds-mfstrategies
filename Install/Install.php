<?php

namespace Apps\Fintech\Packages\Mf\Strategies\Install;

use Apps\Fintech\Packages\Mf\Strategies\Install\Schema\MfStrategies;
use Apps\Fintech\Packages\Mf\Strategies\MfStrategies as MfStrategiesPackage;
use Apps\Fintech\Packages\Mf\Strategies\Model\AppsFintechMfStrategies;
use System\Base\BasePackage;
use System\Base\Providers\ModulesServiceProvider\DbInstaller;

class Install extends BasePackage
{
    protected $databases;

    protected $dbInstaller;

    public function init()
    {
        $this->databases =
            [
                'apps_fintech_mf_strategies'  => [
                    'schema'        => new MfStrategies,
                    'model'         => new AppsFintechMfStrategies
                ]
            ];

        $this->dbInstaller = new DbInstaller;

        return $this;
    }

    public function install()
    {
        $this->preInstall();

        $this->installDb();

        $this->postInstall();

        return true;
    }

    protected function preInstall()
    {
        return true;
    }

    public function installDb()
    {
        $this->dbInstaller->installDb($this->databases);

        return true;
    }

    public function postInstall()
    {
        $strategiesArr = $this->basepackages->utils->scanDir('apps/Fintech/Packages/Mf/Strategies/Strategies/');

        if (count($strategiesArr['files']) > 0) {
            $strategiesPackage = new MfStrategiesPackage;

            foreach ($strategiesArr['files'] as $key => $strategies) {
                $strategies = ucfirst($strategies);
                $strategies = str_replace('/', '\\', $strategies);
                $strategies = str_replace('.php', '', $strategies);

                $strategiesClass = (new $strategies)->init();
                $strategiesReflection = new \ReflectionClass($strategies);

                $dbStrategy = $strategiesPackage->getMfStrategiesByName($strategiesReflection->getShortName());

                if (!$dbStrategy) {
                    $dbStrategy = [];
                }

                $dbStrategy['name'] = $strategiesReflection->getShortName();
                $dbStrategy['display_name'] = $strategiesReflection->getShortName();
                $dbStrategy['class'] = $strategiesReflection->getName();
                if ($strategiesReflection->hasProperty('strategyDisplayName')) {
                    $dbStrategy['display_name'] = $strategiesReflection->getProperty('strategyDisplayName')->getValue($strategiesClass);
                }
                $dbStrategy['description'] = '';
                if ($strategiesReflection->hasProperty('strategyDescription')) {
                    $dbStrategy['description'] = $strategiesReflection->getProperty('strategyDescription')->getValue($strategiesClass);
                }
                $dbStrategy['args'] = '';
                if ($strategiesReflection->hasProperty('strategyArgs')) {
                    $dbStrategy['args'] = $strategiesReflection->getProperty('strategyArgs')->getValue($strategiesClass);
                }

                if (isset($dbStrategy['id'])) {
                    $strategiesPackage->update($dbStrategy);
                } else {
                    $strategiesPackage->add($dbStrategy);
                }
            }
        }

        return true;
    }

    public function truncate()
    {
        $this->dbInstaller->truncate($this->databases);
    }

    public function uninstall($remove = false)
    {
        if ($remove) {
            //Check Relationship
            //Drop Table(s)
            $this->dbInstaller->uninstallDb($this->databases);
        }

        return true;
    }
}