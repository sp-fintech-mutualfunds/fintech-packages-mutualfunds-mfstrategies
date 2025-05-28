<?php

namespace Apps\Fintech\Packages\Mf\Strategies;

use Apps\Fintech\Packages\Mf\Portfolios\MfPortfolios;
use Apps\Fintech\Packages\Mf\Strategies\Model\AppsFintechMfStrategies;
use System\Base\BasePackage;

class MfStrategies extends BasePackage
{
    protected $modelToUse = AppsFintechMfStrategies::class;

    protected $packageName = 'mfstrategies';

    public $mfstrategies;

    protected $strategyClass;

    protected $portfolioPackage;

    protected $progressMethods = [];

    protected $newPortfolioId;

    public function getMfStrategiesByName($name)
    {
        if ($this->config->databasetype === 'db') {
            $conditions =
                [
                    'conditions'    => 'name = :name:',
                    'bind'          =>
                        [
                            'name'  => $name
                        ]
                ];

            $strategy = $this->getByParams($conditions);
        } else {
            $this->ffStore = $this->ff->store($this->ffStoreToUse);

            $strategy = $this->ffStore->findBy(['name', '=', $name]);
        }

        if ($strategy && count($strategy) > 0) {
            return $strategy[0];
        }

        return false;
    }

    protected function checkDataAndInstantiateStrategyClass(&$data)
    {
        if (!isset($data['strategy_id'])) {
            $this->addResponse('Strategy ID is not provided', 1);

            return false;
        }

        $strategy = $this->getById($data['strategy_id']);

        if (!isset($strategy)) {
            $this->addResponse('Strategy ID provided is incorrect.', 1);

            return false;
        }

        try {
            $this->strategyClass = new $strategy['class']();

            return $this->strategyClass;
        } catch (\throwable $e) {
            trace([$e]);
            $this->addResponse('Error instantiating Strategy class. Contact developer.', 1);

            return false;
        }
    }

    public function getStrategiesTransactions($data)
    {
        $this->checkDataAndInstantiateStrategyClass($data);

        if (!$this->strategyClass) {
            return false;
        }

        $this->strategyClass->getStrategiesTransactions($data);

        $this->addResponse(
            $this->strategyClass->packagesData->responseMessage,
            $this->strategyClass->packagesData->responseCode,
            $this->strategyClass->packagesData->responseData ?? []
        );
    }

    public function applyStrategy($data)
    {
        $this->checkDataAndInstantiateStrategyClass($data);

        if (!$this->strategyClass) {
            return false;
        }

        $this->strategyClass->getStrategiesTransactions($data);

        //Create Progress here
        $this->registerProgressMethods($data);

        $progressFile = $this->basepackages->progress->checkProgressFile('mfportfoliostrageties');

        //Increase Exectimeout to 10 mins as this process takes time to extract and merge data.
        if ((int) ini_get('max_execution_time') < 600) {
            set_time_limit(600);
        }

        //Increase memory_limit to 1G as the process takes a bit of memory to process the array.
        if ((int) ini_get('memory_limit') < 1024) {
            ini_set('memory_limit', '1024M');
        }

        $this->portfolioPackage = $this->usePackage(MfPortfolios::class);

        foreach ($progressFile['allProcesses'] as $process) {
            if ($this->withProgress($process['method'], $process['args']) === false) {
                if ($process['method'] === 'clonePortfolio') {
                    $this->addResponse(
                        $this->portfolioPackage->packagesData->responseMessage,
                        $this->portfolioPackage->packagesData->responseCode,
                        $this->portfolioPackage->packagesData->responseData ?? []
                    );

                    return false;
                }

                $this->addResponse(
                    $this->strategyClass->packagesData->responseMessage,
                    $this->strategyClass->packagesData->responseCode,
                    $this->strategyClass->packagesData->responseData ?? []
                );

                return false;
            }
        }

        $this->addResponse(
            'Applied Strategy to portfolio',
            0,
            ['strategyData' => $this->strategyClass->packagesData->responseData ?? [], 'portfolio_id' => $this->newPortfolioId ?? $data['portfolio_id']]
        );

        return true;
    }

    protected function registerProgressMethods($data)
    {
        if ($this->basepackages->progress->checkProgressFile('mfportfoliostrageties')) {
            $this->basepackages->progress->deleteProgressFile('mfportfoliostrageties');
        }

        if (isset($data['clone_portfolio']) && $data['clone_portfolio'] == 'true') {
            array_push($this->progressMethods,
                [
                    'method'    => 'clonePortfolio',
                    'text'      => 'Cloning portfolio...',
                    'args'      => [$data]
                ]
            );
        }

        $transactionDates = array_keys($this->strategyClass->transactions);

        if (count($transactionDates) > 0) {
            foreach ($transactionDates as $date) {
                array_push($this->progressMethods,
                    [
                        'method'    => 'processStrategy',
                        'text'      => 'Processing strategy for ' . $date . '...',
                        'args'      => [$data, $date]
                    ]
                );
            }
        }

        array_push($this->progressMethods,
            [
                'method'    => 'recalculateStrategyPortfolio',
                'text'      => 'Recalculate portfolio...',
                'args'      => [$data]
            ]
        );

        $this->basepackages->progress->registerMethods($this->progressMethods);
    }

    protected function clonePortfolio($args)
    {
        $data = $args[0];

        $this->newPortfolioId = $this->portfolioPackage->clonePortfolio(['id' => $data['portfolio_id']]);

        if (!$this->newPortfolioId) {
            return false;
        }

        return true;
    }

    protected function processStrategy($args)
    {
        $data = $args[0];
        $date = $args[1];

        if ($this->newPortfolioId) {
            $data['portfolio_id'] = $this->newPortfolioId;
        }

        if (!$this->strategyClass->processStrategyTransactionsByDate($data, $date)) {
            $this->addResponse(
                $this->strategyClass->packagesData->responseMessage,
                $this->strategyClass->packagesData->responseCode,
                $this->strategyClass->packagesData->responseData ?? []
            );

            return false;
        }

        return true;
    }

    protected function recalculateStrategyPortfolio($args)
    {
        $data = $args[0];

        if ($this->newPortfolioId) {
            $data['portfolio_id'] = $this->newPortfolioId;
        }

        if (!$this->portfolioPackage->recalculatePortfolio(['portfolio_id' => $data['portfolio_id']], true)) {
            $this->addResponse(
                $this->portfolioPackage->packagesData->responseMessage,
                $this->portfolioPackage->packagesData->responseCode,
                $this->portfolioPackage->packagesData->responseData ?? []
            );

            return false;
        }

        return true;
    }

    protected function withProgress($method, $arguments)
    {
        if (method_exists($this, $method)) {
            $arguments['progressMethod'] = $method;

            $arguments = [$arguments];

            $this->basepackages->progress->updateProgress($method, null, false);

            $call = call_user_func_array([$this, $method], $arguments);

            $this->basepackages->progress->updateProgress($method, $call, false);

            return $call;
        }

        return false;
    }

    public function getMfStrategiesById($id)
    {
        //
    }

    public function addMfStrategies($data)
    {
        //
    }

    public function updateMfStrategies($data)
    {
        //
    }

    public function removeMfStrategies($data)
    {
        //
    }
}