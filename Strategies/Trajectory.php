<?php

namespace Apps\Fintech\Packages\Mf\Strategies\Strategies;

use Apps\Fintech\Packages\Mf\Strategies\MfStrategies;

class Trajectory extends MfStrategies
{
    public $strategyDisplayName = 'Trajectory';

    public $strategyDescription = 'Perform trajectory strategy on a portfolio';

    public $strategyArgs = [];

    public function init()
    {
        $this->strategyArgs = $this->getStategyArgs();

        parent::init();

        return $this;
    }

    public function run($portfolio)
    {
        trace([$portfolio]);
    }

    protected function getStategyArgs()
    {
        return [];
    }
}